<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       fieldservice/class/fieldserviceshipmentservice.class.php
 * \ingroup    fieldservice
 * \brief      Synchronize Field Service material usage to draft Dolibarr Shipments.
 */

require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
require_once DOL_DOCUMENT_ROOT.'/expedition/class/expeditionligne.class.php';
require_once DOL_DOCUMENT_ROOT.'/expedition/class/expeditionlinebatch.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/productbatch.class.php';
require_once __DIR__.'/fieldservicematerial.class.php';
require_once __DIR__.'/fieldservicematerialallocation.class.php';

/**
 * Shipment integration service.
 *
 * Field Service owns the work/material workflow, while Dolibarr Expedition
 * remains the authoritative business document for physical stock output.
 */
class FieldServiceShipmentService
{
	/** @var DoliDB */
	private $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Return exactly one customer order linked to the Intervention, otherwise 0.
	 *
	 * No guess is made when several orders are linked.
	 *
	 * @param Fichinter $workOrder Intervention
	 * @return int<-1,max> Order id, 0 if none/ambiguous, negative on error
	 */
	public function resolveSingleLinkedOrder(Fichinter $workOrder)
	{
		$workOrder->clearObjectLinkedCache();
		$result = $workOrder->fetchObjectLinked();
		if ($result < 0) {
			$this->error = $workOrder->error;
			$this->errors = $workOrder->errors;
			return -1;
		}

		$orderIds = array();
		if (!empty($workOrder->linkedObjectsIds['commande'])) {
			foreach ($workOrder->linkedObjectsIds['commande'] as $orderId) {
				$orderIds[(int) $orderId] = (int) $orderId;
			}
		}

		return count($orderIds) === 1 ? (int) reset($orderIds) : 0;
	}

	/**
	 * Resolve the order used as Shipment origin for a material row.
	 *
	 * Explicit commandedet origin wins. Otherwise a single order linked to the
	 * work order is used as Shipment header origin, but the material line stays
	 * a free Shipment line until it is explicitly/uniquely mapped to commandedet.
	 *
	 * @param FieldServiceMaterial $material Material row
	 * @param Fichinter $workOrder Intervention
	 * @return int<-1,max>
	 */
	public function resolveOrderForMaterial(FieldServiceMaterial $material, Fichinter $workOrder)
	{
		if ($material->origin_type === 'commande' && !empty($material->fk_origin_line)) {
			$orderLine = new OrderLine($this->db);
			$result = $orderLine->fetch((int) $material->fk_origin_line);
			if ($result <= 0) {
				$this->error = $orderLine->error ?: 'OrderLineNotFound';
				$this->errors = $orderLine->errors;
				return -1;
			}
			return (int) $orderLine->fk_commande;
		}

		return $this->resolveSingleLinkedOrder($workOrder);
	}

	/**
	 * Fetch a mapped draft Shipment for work order + optional order origin.
	 *
	 * @param int $fichinterId Intervention id
	 * @param int $orderId Optional customer order id
	 * @return Expedition|null|false Draft shipment, null if none, false on error
	 */
	public function findDraftShipment($fichinterId, $orderId = 0)
	{
		$sql = 'SELECT e.rowid';
		$sql .= ' FROM '.$this->db->prefix().'fieldservice_workorder_shipment as fws';
		$sql .= ' INNER JOIN '.$this->db->prefix().'expedition as e ON e.rowid = fws.fk_expedition';
		$sql .= ' WHERE fws.fk_fichinter = '.((int) $fichinterId);
		$sql .= ' AND e.fk_statut = '.Expedition::STATUS_DRAFT;
		if ($orderId > 0) {
			$sql .= ' AND fws.fk_commande = '.((int) $orderId);
		} else {
			$sql .= ' AND fws.fk_commande IS NULL';
		}
		$sql .= ' ORDER BY e.rowid DESC';
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return null;
		}

		$shipment = new Expedition($this->db);
		$result = $shipment->fetch((int) $obj->rowid);
		if ($result <= 0) {
			$this->error = $shipment->error;
			$this->errors = $shipment->errors;
			return false;
		}

		return $shipment;
	}

	/**
	 * Create a draft Shipment and Field Service mapping.
	 *
	 * @param Fichinter $workOrder Intervention
	 * @param User $user Acting user
	 * @param int $orderId Optional customer order id
	 * @return Expedition|false
	 */
	public function createDraftShipment(Fichinter $workOrder, User $user, $orderId = 0)
	{
		$shipment = new Expedition($this->db);
		$shipment->socid = (int) $workOrder->socid;
		$shipment->fk_project = (int) $workOrder->fk_project;

		if ($orderId > 0) {
			$order = new Commande($this->db);
			$result = $order->fetch($orderId);
			if ($result <= 0 || (int) $order->socid !== (int) $workOrder->socid) {
				$this->error = $result <= 0 ? ($order->error ?: 'OrderNotFound') : 'OrderThirdPartyMismatch';
				$this->errors = $order->errors;
				return false;
			}

			$shipment->origin = 'commande';
			$shipment->origin_type = 'commande';
			$shipment->origin_id = $orderId;
		}

		$result = $shipment->create($user);
		if ($result <= 0) {
			$this->error = $shipment->error;
			$this->errors = $shipment->errors;
			return false;
		}

		$sql = 'INSERT INTO '.$this->db->prefix().'fieldservice_workorder_shipment';
		$sql .= ' (fk_fichinter, fk_expedition, fk_commande, date_creation)';
		$sql .= ' VALUES ('.((int) $workOrder->id).', '.((int) $shipment->id).', ';
		$sql .= $orderId > 0 ? ((int) $orderId) : 'NULL';
		$sql .= ", '".$this->db->idate(dol_now())."')";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return false;
		}

		return $shipment;
	}

	/**
	 * Return or create the appropriate draft Shipment.
	 *
	 * @param FieldServiceMaterial $material Material row
	 * @param Fichinter $workOrder Intervention
	 * @param User $user Acting user
	 * @return Expedition|false
	 */
	public function getOrCreateDraftShipment(FieldServiceMaterial $material, Fichinter $workOrder, User $user)
	{
		$orderId = $this->resolveOrderForMaterial($material, $workOrder);
		if ($orderId < 0) {
			return false;
		}

		$shipment = $this->findDraftShipment($workOrder->id, $orderId);
		if ($shipment === false) {
			return false;
		}
		if ($shipment instanceof Expedition) {
			return $shipment;
		}

		return $this->createDraftShipment($workOrder, $user, $orderId);
	}

	/**
	 * Fetch mapping for a material row.
	 *
	 * @param int $materialId Material row id
	 * @return object|null|false
	 */
	private function fetchMaterialShipmentMapping($materialId)
	{
		$sql = 'SELECT rowid, fk_expedition, fk_expeditiondet';
		$sql .= ' FROM '.$this->db->prefix().'fieldservice_material_shipment';
		$sql .= ' WHERE fk_material = '.((int) $materialId);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $obj ?: null;
	}

	/**
	 * Remove the existing draft Shipment representation for a material row.
	 *
	 * @param int $materialId Material row id
	 * @param User $user Acting user
	 * @return int<-1,1>
	 */
	public function removeMaterialFromDraftShipment($materialId, User $user)
	{
		$mapping = $this->fetchMaterialShipmentMapping($materialId);
		if ($mapping === false) {
			return -1;
		}
		if ($mapping === null) {
			return 1;
		}

		$shipment = new Expedition($this->db);
		if ($shipment->fetch((int) $mapping->fk_expedition) <= 0) {
			// The Shipment was deleted outside Field Service. Drop the stale
			// mapping so the material can be synchronized to a new draft Shipment.
			$sql = 'DELETE FROM '.$this->db->prefix().'fieldservice_material_shipment';
			$sql .= ' WHERE rowid = '.((int) $mapping->rowid);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}

			$sql = 'DELETE FROM '.$this->db->prefix().'fieldservice_workorder_shipment';
			$sql .= ' WHERE fk_expedition = '.((int) $mapping->fk_expedition);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}

			return 1;
		}
		if ((int) $shipment->status !== Expedition::STATUS_DRAFT) {
			$this->error = 'FieldServiceShipmentNotDraft';
			return -1;
		}

		$line = new ExpeditionLigne($this->db);
		if ($line->fetch((int) $mapping->fk_expeditiondet) > 0) {
			$result = $line->delete($user);
			if ($result < 0) {
				$this->error = $line->error;
				$this->errors = $line->errors;
				return -1;
			}
		}

		$sql = 'DELETE FROM '.$this->db->prefix().'fieldservice_material_shipment';
		$sql .= ' WHERE rowid = '.((int) $mapping->rowid);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Synchronize one material row to its draft Shipment.
	 *
	 * @param FieldServiceMaterial $material Material row
	 * @param Fichinter $workOrder Intervention
	 * @param User $user Acting user
	 * @return int<-1,1>
	 */
	public function syncMaterial(FieldServiceMaterial $material, Fichinter $workOrder, User $user)
	{
		if (empty($material->id)) {
			$this->error = 'MaterialMustBeCreatedBeforeShipmentSync';
			return -1;
		}
		if ((int) $material->status !== FieldServiceMaterial::STATUS_DRAFT) {
			$this->error = 'FieldServiceOnlyDraftEditable';
			return -1;
		}

		$product = new Product($this->db);
		if ($product->fetch((int) $material->fk_product, '', '', '', 1, 1, 1) <= 0) {
			$this->error = $product->error;
			$this->errors = $product->errors;
			return -1;
		}

		$shipment = $this->getOrCreateDraftShipment($material, $workOrder, $user);
		if (!$shipment instanceof Expedition) {
			return -1;
		}

		$result = $this->removeMaterialFromDraftShipment($material->id, $user);
		if ($result < 0) {
			return -1;
		}

		$line = new ExpeditionLigne($this->db);
		$line->fk_expedition = $shipment->id;
		$line->entrepot_id = (int) $material->fk_entrepot;
		$line->fk_product = (int) $material->fk_product;
		$line->qty = (float) $material->qty;
		$line->fk_unit = !empty($material->fk_unit) ? (int) $material->fk_unit : 0;
		$line->description = (string) $material->description;
		$line->rang = -1;
		$line->element_type = 'fichinter';

		if ($material->origin_type === 'commande' && !empty($material->fk_origin_line)) {
			$line->element_type = 'commande';
			$line->fk_elementdet = (int) $material->fk_origin_line;
			$line->origin_line_id = (int) $material->fk_origin_line;
		}

		$lineId = $line->insert($user);
		if ($lineId <= 0) {
			$this->error = $line->error;
			$this->errors = $line->errors;
			return -1;
		}

		if (isModEnabled('productbatch') && $product->hasbatch()) {
			$allocation = new FieldServiceMaterialAllocation($this->db);
			$allocations = $allocation->fetchAllByMaterial($material->id);
			if (!is_array($allocations)) {
				$this->error = $allocation->error;
				$this->errors = $allocation->errors;
				return -1;
			}

			$allocatedQty = 0.0;
			foreach ($allocations as $allocationRow) {
				$stockBatch = new Productbatch($this->db);
				$result = $stockBatch->find(0, '', '', (string) $allocationRow->batch, (int) $material->fk_entrepot, (int) $material->fk_product);
				if ($result <= 0 || empty($stockBatch->id)) {
					$this->error = 'FieldServiceLotSerialNotAvailable';
					$this->errors[] = (string) $allocationRow->batch;
					return -1;
				}
				if ((float) $stockBatch->qty + 0.00000001 < (float) $allocationRow->qty) {
					$this->error = 'FieldServiceAllocationExceedsStock';
					$this->errors[] = (string) $allocationRow->batch;
					return -1;
				}

				$batchLine = new ExpeditionLineBatch($this->db);
				$batchLine->batch = (string) $allocationRow->batch;
				$batchLine->qty = (float) $allocationRow->qty;
				$batchLine->eatby = $allocationRow->eatby;
				$batchLine->sellby = $allocationRow->sellby;
				$batchLine->fk_origin_stock = (int) $stockBatch->id;
				$batchLine->fk_warehouse = (int) $material->fk_entrepot;

				$result = $batchLine->create($lineId, $user);
				if ($result <= 0) {
					$this->error = $batchLine->error;
					$this->errors = $batchLine->errors;
					return -1;
				}
				$allocatedQty += (float) $allocationRow->qty;
			}

			if (abs($allocatedQty - (float) $material->qty) > 0.00000001) {
				$this->error = 'FieldServiceAllocationMustMatchQty';
				return -1;
			}
		}

		$sql = 'INSERT INTO '.$this->db->prefix().'fieldservice_material_shipment';
		$sql .= ' (fk_material, fk_expedition, fk_expeditiondet, date_creation)';
		$sql .= ' VALUES ('.((int) $material->id).', '.((int) $shipment->id).', '.((int) $lineId).", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Check whether a Shipment belongs to the Field Service workflow.
	 *
	 * @param int $shipmentId Shipment id
	 * @return int<-1,1> 1 mapped, 0 not mapped, negative on error
	 */
	public function isMappedShipment($shipmentId)
	{
		$sql = 'SELECT rowid';
		$sql .= ' FROM '.$this->db->prefix().'fieldservice_workorder_shipment';
		$sql .= ' WHERE fk_expedition = '.((int) $shipmentId);
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$mapped = $this->db->num_rows($resql) > 0 ? 1 : 0;
		$this->db->free($resql);
		return $mapped;
	}

	/**
	 * Return validity of Shipment mappings for each material of an Intervention.
	 *
	 * @param int $fichinterId Intervention id
	 * @return array<int,bool>|false Material id => valid Shipment + Shipment line
	 */
	public function getMaterialShipmentHealth($fichinterId)
	{
		$result = array();

		$sql = 'SELECT fm.rowid as material_id,';
		$sql .= ' MAX(CASE WHEN e.rowid IS NOT NULL AND ed.rowid IS NOT NULL THEN 1 ELSE 0 END) as mapping_valid';
		$sql .= ' FROM '.$this->db->prefix().'fieldservice_material as fm';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'fieldservice_material_shipment as fms ON fms.fk_material = fm.rowid';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'expedition as e ON e.rowid = fms.fk_expedition';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'expeditiondet as ed';
		$sql .= ' ON ed.rowid = fms.fk_expeditiondet AND ed.fk_expedition = fms.fk_expedition';
		$sql .= ' WHERE fm.fk_fichinter = '.((int) $fichinterId);
		$sql .= ' GROUP BY fm.rowid';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$result[(int) $obj->material_id] = !empty($obj->mapping_valid);
		}
		$this->db->free($resql);

		return $result;
	}

	/**
	 * Check whether a posted material has lost its Shipment or Shipment line.
	 *
	 * @param int $fichinterId Intervention id
	 * @return int<-1,1> 1 if inconsistent posted material exists
	 */
	public function hasBrokenPostedMaterial($fichinterId)
	{
		$sql = 'SELECT fm.rowid';
		$sql .= ' FROM '.$this->db->prefix().'fieldservice_material as fm';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'fieldservice_material_shipment as fms ON fms.fk_material = fm.rowid';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'expedition as e ON e.rowid = fms.fk_expedition';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'expeditiondet as ed';
		$sql .= ' ON ed.rowid = fms.fk_expeditiondet AND ed.fk_expedition = fms.fk_expedition';
		$sql .= ' WHERE fm.fk_fichinter = '.((int) $fichinterId);
		$sql .= ' AND fm.status = '.FieldServiceMaterial::STATUS_POSTED;
		$sql .= ' AND (e.rowid IS NULL OR ed.rowid IS NULL)';
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$broken = $this->db->num_rows($resql) > 0 ? 1 : 0;
		$this->db->free($resql);
		return $broken;
	}

	/**
	 * Remove Field Service mappings for a Shipment being deleted.
	 *
	 * The SHIPPING_DELETE trigger calls this inside the Shipment transaction,
	 * so a failed core deletion also rolls back this cleanup.
	 *
	 * @param int $shipmentId Shipment id
	 * @param User $user Acting user
	 * @return int<-1,1>
	 */
	public function cleanupShipmentMappings($shipmentId, User $user)
	{
		$materialIds = array();

		$sql = 'SELECT DISTINCT fms.fk_material';
		$sql .= ' FROM '.$this->db->prefix().'fieldservice_material_shipment as fms';
		$sql .= ' WHERE fms.fk_expedition = '.((int) $shipmentId);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$materialIds[] = (int) $obj->fk_material;
		}
		$this->db->free($resql);

		if (!empty($materialIds)) {
			$idList = implode(',', array_map('intval', $materialIds));

			$sql = 'UPDATE '.$this->db->prefix().'fieldservice_material';
			$sql .= ' SET status = '.FieldServiceMaterial::STATUS_DRAFT;
			$sql .= ', fk_user_modif = '.((int) $user->id);
			$sql .= ' WHERE rowid IN ('.$idList.')';
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}

			$sql = 'UPDATE '.$this->db->prefix().'fieldservice_material_alloc';
			$sql .= ' SET date_posted = NULL, fk_user_posted = NULL';
			$sql .= ' WHERE fk_material IN ('.$idList.')';
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}

		$sql = 'DELETE FROM '.$this->db->prefix().'fieldservice_material_shipment';
		$sql .= ' WHERE fk_expedition = '.((int) $shipmentId);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$sql = 'DELETE FROM '.$this->db->prefix().'fieldservice_workorder_shipment';
		$sql .= ' WHERE fk_expedition = '.((int) $shipmentId);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Repair mappings whose Shipment or Shipment line no longer exists.
	 *
	 * This handles historical inconsistent states created before the
	 * SHIPPING_DELETE cleanup trigger was added.
	 *
	 * @param int $fichinterId Intervention id
	 * @param User $user Acting user
	 * @return int<-1,max> Number of repaired material mappings, or negative on error
	 */
	public function repairMissingShipmentMappings($fichinterId, User $user)
	{
		$mappingIds = array();
		$materialIds = array();
		$missingShipmentIds = array();

		$sql = 'SELECT fms.rowid as mapping_id, fms.fk_material, fms.fk_expedition, e.rowid as expedition_exists';
		$sql .= ' FROM '.$this->db->prefix().'fieldservice_material_shipment as fms';
		$sql .= ' INNER JOIN '.$this->db->prefix().'fieldservice_material as fm ON fm.rowid = fms.fk_material';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'expedition as e ON e.rowid = fms.fk_expedition';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'expeditiondet as ed';
		$sql .= ' ON ed.rowid = fms.fk_expeditiondet AND ed.fk_expedition = fms.fk_expedition';
		$sql .= ' WHERE fm.fk_fichinter = '.((int) $fichinterId);
		$sql .= ' AND (e.rowid IS NULL OR ed.rowid IS NULL)';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$mappingIds[] = (int) $obj->mapping_id;
			$materialIds[] = (int) $obj->fk_material;
			if (empty($obj->expedition_exists)) {
				$missingShipmentIds[] = (int) $obj->fk_expedition;
			}
		}
		$this->db->free($resql);

		$mappingIds = array_values(array_unique($mappingIds));
		$materialIds = array_values(array_unique($materialIds));
		$missingShipmentIds = array_values(array_unique($missingShipmentIds));

		if (empty($mappingIds)) {
			return 0;
		}

		$mappingList = implode(',', array_map('intval', $mappingIds));
		$materialList = implode(',', array_map('intval', $materialIds));

		$sql = 'DELETE FROM '.$this->db->prefix().'fieldservice_material_shipment';
		$sql .= ' WHERE rowid IN ('.$mappingList.')';
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$sql = 'UPDATE '.$this->db->prefix().'fieldservice_material';
		$sql .= ' SET status = '.FieldServiceMaterial::STATUS_DRAFT;
		$sql .= ', fk_user_modif = '.((int) $user->id);
		$sql .= ' WHERE rowid IN ('.$materialList.')';
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$sql = 'UPDATE '.$this->db->prefix().'fieldservice_material_alloc';
		$sql .= ' SET date_posted = NULL, fk_user_posted = NULL';
		$sql .= ' WHERE fk_material IN ('.$materialList.')';
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		if (!empty($missingShipmentIds)) {
			$shipmentList = implode(',', array_map('intval', $missingShipmentIds));
			$sql = 'DELETE FROM '.$this->db->prefix().'fieldservice_workorder_shipment';
			$sql .= ' WHERE fk_expedition IN ('.$shipmentList.')';
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}

		return count($mappingIds);
	}

	/**
	 * Return all Shipments mapped to an Intervention.
	 *
	 * @param int $fichinterId Intervention id
	 * @return array<int,array{shipment:Expedition,order_id:int}>|false
	 */
	public function getShipmentsForIntervention($fichinterId)
	{
		$result = array();

		$sql = 'SELECT fk_expedition, fk_commande';
		$sql .= ' FROM '.$this->db->prefix().'fieldservice_workorder_shipment';
		$sql .= ' WHERE fk_fichinter = '.((int) $fichinterId);
		$sql .= ' ORDER BY rowid ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$shipment = new Expedition($this->db);
			$fetchResult = $shipment->fetch((int) $obj->fk_expedition);
			if ($fetchResult <= 0) {
				// Keep the read path usable even if somebody deleted a mapped
				// Shipment directly from Dolibarr. isMaterialSyncComplete() will
				// flag the broken mapping and offer synchronization/repair.
				continue;
			}
			$result[] = array(
				'shipment' => $shipment,
				'order_id' => !empty($obj->fk_commande) ? (int) $obj->fk_commande : 0,
			);
		}

		$this->db->free($resql);
		return $result;
	}

	/**
	 * Check whether every material row has a Shipment line mapping.
	 *
	 * @param int $fichinterId Intervention id
	 * @return int<-1,1>
	 */
	public function isMaterialSyncComplete($fichinterId)
	{
		$sql = 'SELECT COUNT(DISTINCT fm.rowid) as material_count,';
		$sql .= ' COUNT(DISTINCT CASE WHEN e.rowid IS NOT NULL AND ed.rowid IS NOT NULL THEN fm.rowid END) as mapped_count';
		$sql .= ' FROM '.$this->db->prefix().'fieldservice_material as fm';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'fieldservice_material_shipment as fms ON fms.fk_material = fm.rowid';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'expedition as e ON e.rowid = fms.fk_expedition';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'expeditiondet as ed';
		$sql .= ' ON ed.rowid = fms.fk_expeditiondet AND ed.fk_expedition = fms.fk_expedition';
		$sql .= ' WHERE fm.fk_fichinter = '.((int) $fichinterId);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return 1;
		}

		return ((int) $obj->material_count === (int) $obj->mapped_count) ? 1 : 0;
	}

	/**
	 * Finalize all Shipments for a completed work order.
	 *
	 * Draft Shipments are validated first and then closed. Dolibarr's stock
	 * configuration decides whether the physical movement happens on validation
	 * or on close; Field Service does not bypass that lifecycle.
	 *
	 * @param Fichinter $workOrder Intervention being completed
	 * @param User $user Acting user
	 * @return int<-1,1>
	 */
	public function finalizeWorkOrderShipments(Fichinter $workOrder, User $user)
	{
		$syncComplete = $this->isMaterialSyncComplete($workOrder->id);
		if ($syncComplete < 0) {
			return -1;
		}
		if ($syncComplete === 0) {
			$this->error = 'FieldServiceMaterialsNotSyncedToShipment';
			return -1;
		}

		$shipmentRows = $this->getShipmentsForIntervention($workOrder->id);
		if ($shipmentRows === false) {
			return -1;
		}

		foreach ($shipmentRows as $shipmentRow) {
			/** @var Expedition $shipment */
			$shipment = $shipmentRow['shipment'];

			if ((int) $shipment->status === Expedition::STATUS_CANCELED) {
				$this->error = 'FieldServiceShipmentCanceled';
				$this->errors[] = $shipment->ref;
				return -1;
			}

			if ((int) $shipment->status === Expedition::STATUS_DRAFT) {
				$result = $shipment->valid($user);
				if ($result < 0) {
					$this->error = $shipment->error;
					$this->errors = $shipment->errors;
					return -1;
				}
			}

			if ((int) $shipment->status !== Expedition::STATUS_CLOSED) {
				$result = $shipment->setClosed();
				if ($result < 0) {
					$this->error = $shipment->error;
					$this->errors = $shipment->errors;
					return -1;
				}
			}

			$sql = 'UPDATE '.$this->db->prefix().'fieldservice_material as fm';
			$sql .= ' INNER JOIN '.$this->db->prefix().'fieldservice_material_shipment as fms ON fms.fk_material = fm.rowid';
			$sql .= ' SET fm.status = '.FieldServiceMaterial::STATUS_POSTED;
			$sql .= ', fm.fk_user_modif = '.((int) $user->id);
			$sql .= ' WHERE fms.fk_expedition = '.((int) $shipment->id);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}

			$sql = 'UPDATE '.$this->db->prefix().'fieldservice_material_alloc as fma';
			$sql .= ' INNER JOIN '.$this->db->prefix().'fieldservice_material_shipment as fms ON fms.fk_material = fma.fk_material';
			$sql .= " SET fma.date_posted = '".$this->db->idate(dol_now())."'";
			$sql .= ', fma.fk_user_posted = '.((int) $user->id);
			$sql .= ' WHERE fms.fk_expedition = '.((int) $shipment->id);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}

		return 1;
	}


	/**
	 * Synchronize every editable material row of an Intervention to draft Shipments.
	 *
	 * Intended for upgrading existing Field Service work orders that were
	 * created before Shipment integration was introduced.
	 *
	 * @param Fichinter $workOrder Intervention
	 * @param User $user Acting user
	 * @return int<-1,1>
	 */
	public function syncAllMaterials(Fichinter $workOrder, User $user)
	{
		$brokenPosted = $this->hasBrokenPostedMaterial($workOrder->id);
		if ($brokenPosted < 0) {
			return -1;
		}
		if ($brokenPosted > 0) {
			$this->error = 'FieldServicePostedShipmentMissing';
			return -1;
		}

		$material = new FieldServiceMaterial($this->db);
		$materials = $material->fetchAllByIntervention($workOrder->id);
		if (!is_array($materials)) {
			$this->error = $material->error;
			$this->errors = $material->errors;
			return -1;
		}

		foreach ($materials as $materialRow) {
			if ((int) $materialRow->status !== FieldServiceMaterial::STATUS_DRAFT) {
				continue;
			}
			$result = $this->syncMaterial($materialRow, $workOrder, $user);
			if ($result < 0) {
				return -1;
			}
		}

		return 1;
	}

}
