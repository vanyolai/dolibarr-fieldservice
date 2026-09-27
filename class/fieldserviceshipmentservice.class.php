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
	 * Return customer order ids explicitly linked to an Intervention.
	 *
	 * @param Fichinter $workOrder Intervention
	 * @return int[]|false Order ids or false on error
	 */
	public function getLinkedOrderIds(Fichinter $workOrder)
	{
		$workOrder->clearObjectLinkedCache();
		$result = $workOrder->fetchObjectLinked();
		if ($result < 0) {
			$this->error = $workOrder->error;
			$this->errors = $workOrder->errors;
			return false;
		}

		$orderIds = array();
		if (!empty($workOrder->linkedObjectsIds['commande'])) {
			foreach ($workOrder->linkedObjectsIds['commande'] as $orderId) {
				$orderId = (int) $orderId;
				if ($orderId > 0) {
					$orderIds[$orderId] = $orderId;
				}
			}
		}

		return array_values($orderIds);
	}

	/**
	 * Return order-line shipment availability for orders linked to a work order.
	 *
	 * Draft and finalized Shipment lines both count as already allocated/shipped,
	 * matching Dolibarr's order-backed Shipment card. When editing one Field
	 * Service material row, its current Shipment line can be excluded.
	 *
	 * @param Fichinter $workOrder Intervention
	 * @param int $productId Optional product filter
	 * @param int $excludeMaterialId Optional Field Service material id to exclude
	 * @return array<int,array<string,mixed>>|false Indexed by commandedet rowid
	 */
	public function getOrderLineAvailability(Fichinter $workOrder, $productId = 0, $excludeMaterialId = 0)
	{
		$orderIds = $this->getLinkedOrderIds($workOrder);
		if ($orderIds === false) {
			return false;
		}
		if (empty($orderIds)) {
			return array();
		}

		$idList = implode(',', array_map('intval', $orderIds));
		$sql = 'SELECT c.rowid as order_id, c.ref as order_ref, c.fk_soc,';
		$sql .= ' cd.rowid as line_id, cd.fk_product, cd.qty as ordered_qty, cd.fk_unit, cd.rang,';
		$sql .= ' p.ref as product_ref, p.label as product_label,';
		if ($excludeMaterialId > 0) {
			$sql .= ' COALESCE(SUM(CASE WHEN fms_self.rowid IS NOT NULL THEN 0 ELSE ed.qty END), 0) as shipped_qty';
		} else {
			$sql .= ' COALESCE(SUM(ed.qty), 0) as shipped_qty';
		}
		$sql .= ' FROM '.$this->db->prefix().'commande as c';
		$sql .= ' INNER JOIN '.$this->db->prefix().'commandedet as cd ON cd.fk_commande = c.rowid';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'product as p ON p.rowid = cd.fk_product';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'expeditiondet as ed ON ed.fk_elementdet = cd.rowid';
		if ($excludeMaterialId > 0) {
			$sql .= ' LEFT JOIN '.$this->db->prefix().'fieldservice_material_shipment as fms_self';
			$sql .= ' ON fms_self.fk_expeditiondet = ed.rowid AND fms_self.fk_material = '.((int) $excludeMaterialId);
		}
		$sql .= ' WHERE c.rowid IN ('.$idList.')';
		$sql .= ' AND cd.fk_product IS NOT NULL';
		if ($productId > 0) {
			$sql .= ' AND cd.fk_product = '.((int) $productId);
		}
		$sql .= ' GROUP BY c.rowid, c.ref, c.fk_soc, cd.rowid, cd.fk_product, cd.qty, cd.fk_unit, cd.rang, p.ref, p.label';
		$sql .= ' ORDER BY c.ref ASC, cd.rang ASC, cd.rowid ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}

		$result = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ordered = (float) $obj->ordered_qty;
			$shipped = (float) $obj->shipped_qty;
			$result[(int) $obj->line_id] = array(
				'order_id' => (int) $obj->order_id,
				'order_ref' => (string) $obj->order_ref,
				'fk_soc' => (int) $obj->fk_soc,
				'line_id' => (int) $obj->line_id,
				'fk_product' => (int) $obj->fk_product,
				'product_ref' => (string) $obj->product_ref,
				'product_label' => (string) $obj->product_label,
				'fk_unit' => !empty($obj->fk_unit) ? (int) $obj->fk_unit : 0,
				'ordered_qty' => $ordered,
				'shipped_qty' => $shipped,
				'remaining_qty' => $ordered - $shipped,
			);
		}
		$this->db->free($resql);

		return $result;
	}

	/**
	 * Apply and validate an explicit order-source choice on a material row.
	 *
	 * Selection values are "extra" or "line:<commandedet id>". If linked orders
	 * exist, an explicit choice is mandatory so Field Service never guesses.
	 *
	 * @param FieldServiceMaterial $material Material row
	 * @param Fichinter $workOrder Intervention
	 * @param string $selection Source selection
	 * @param int $excludeMaterialId Material id being edited
	 * @return int<-1,1>
	 */
	public function applyOrderSourceSelection(FieldServiceMaterial $material, Fichinter $workOrder, $selection, $excludeMaterialId = 0)
	{
		$orderIds = $this->getLinkedOrderIds($workOrder);
		if ($orderIds === false) {
			return -1;
		}

		$selection = trim((string) $selection);
		if (empty($orderIds)) {
			$material->origin_type = null;
			$material->fk_origin_line = null;
			return 1;
		}

		if ($selection === '') {
			$this->error = 'FieldServiceOrderSourceRequired';
			return -1;
		}
		if ($selection === 'extra') {
			$material->origin_type = null;
			$material->fk_origin_line = null;
			return 1;
		}
		if (!preg_match('/^line:(\\d+)$/', $selection, $matches)) {
			$this->error = 'FieldServiceInvalidOrderSource';
			return -1;
		}

		$lineId = (int) $matches[1];
		$availability = $this->getOrderLineAvailability($workOrder, (int) $material->fk_product, (int) $excludeMaterialId);
		if ($availability === false) {
			return -1;
		}
		if (empty($availability[$lineId])) {
			$this->error = 'FieldServiceOrderLineProductMismatch';
			return -1;
		}

		$source = $availability[$lineId];
		if ((int) $source['fk_soc'] !== (int) $workOrder->socid) {
			$this->error = 'FieldServiceOrderThirdPartyMismatch';
			return -1;
		}
		if ((float) $material->qty - (float) $source['remaining_qty'] > 0.00000001) {
			$this->error = 'FieldServiceOrderLineQtyExceeded';
			$this->errors = array(
				(string) $source['order_ref'],
				(string) $source['product_ref'],
				(string) ((float) $source['remaining_qty'] + 0),
			);
			return -1;
		}

		$material->origin_type = 'commande';
		$material->fk_origin_line = $lineId;
		if (empty($material->fk_unit) && !empty($source['fk_unit'])) {
			$material->fk_unit = (int) $source['fk_unit'];
		}

		return 1;
	}

	/**
	 * Validate an already stored material/order provenance before Shipment sync.
	 *
	 * @param FieldServiceMaterial $material Material row
	 * @param Fichinter $workOrder Intervention
	 * @return int<-1,1>
	 */
	public function validateMaterialOrderOrigin(FieldServiceMaterial $material, Fichinter $workOrder)
	{
		if ($material->origin_type !== 'commande' || empty($material->fk_origin_line)) {
			return 1;
		}

		return $this->applyOrderSourceSelection(
			$material,
			$workOrder,
			'line:'.((int) $material->fk_origin_line),
			(int) $material->id
		);
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
		$orderIds = $this->getLinkedOrderIds($workOrder);
		if ($orderIds === false) {
			return -1;
		}

		if (count($orderIds) > 1) {
			$this->error = 'FieldServiceMultipleOrdersRequireSelection';
			$this->errors = array(implode(', ', array_map('strval', $orderIds)));
			return -1;
		}

		return count($orderIds) === 1 ? (int) reset($orderIds) : 0;
	}

	/**
	 * Resolve the order used as Shipment origin for a material row.
	 *
	 * Only explicit commandedet provenance creates an order-backed Shipment.
	 * Material marked as extra has no order provenance and is synchronized to a
	 * standalone Shipment, even when customer orders are linked to the work order.
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

		return 0;
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
		if ($orderId <= 0 && !getDolGlobalString('SHIPMENT_STANDALONE')) {
			$this->error = 'FieldServiceStandaloneShipmentDisabled';
			return false;
		}

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
			$result = $line->delete($user, 1);
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

		$result = $this->validateMaterialOrderOrigin($material, $workOrder);
		if ($result < 0) {
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
			$line->element_type = 'order';
			$line->fk_elementdet = (int) $material->fk_origin_line;
			$line->origin_line_id = (int) $material->fk_origin_line;
		}

		$lineId = $line->insert($user, 1);
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
				$this->cleanupCreatedShipmentLine($lineId, $user);
				return -1;
			}

			$allocatedQty = 0.0;
			foreach ($allocations as $allocationRow) {
				$stockBatch = new Productbatch($this->db);
				$result = $stockBatch->find(0, '', '', (string) $allocationRow->batch, (int) $material->fk_entrepot, (int) $material->fk_product);
				if ($result <= 0 || empty($stockBatch->id)) {
					$this->error = 'FieldServiceLotSerialNotAvailable';
					$this->errors[] = (string) $allocationRow->batch;
					$this->cleanupCreatedShipmentLine($lineId, $user);
					return -1;
				}
				if ((float) $stockBatch->qty + 0.00000001 < (float) $allocationRow->qty) {
					$this->error = 'FieldServiceAllocationExceedsStock';
					$this->errors[] = (string) $allocationRow->batch;
					$this->cleanupCreatedShipmentLine($lineId, $user);
					return -1;
				}

				$batchLine = new ExpeditionLineBatch($this->db);
				$batchLine->batch = (string) $allocationRow->batch;
				$batchLine->qty = (float) $allocationRow->qty;
				$batchLine->eatby = $allocationRow->eatby;
				$batchLine->sellby = $allocationRow->sellby;
				$batchLine->fk_origin_stock = (int) $stockBatch->id;
				$batchLine->fk_warehouse = (int) $material->fk_entrepot;

				$result = $batchLine->create($lineId, $user, 1);
				if ($result <= 0) {
					$this->error = $batchLine->error;
					$this->errors = $batchLine->errors;
					$this->cleanupCreatedShipmentLine($lineId, $user);
					return -1;
				}
				$allocatedQty += (float) $allocationRow->qty;
			}

			if (abs($allocatedQty - (float) $material->qty) > 0.00000001) {
				$this->error = 'FieldServiceAllocationMustMatchQty';
				$this->cleanupCreatedShipmentLine($lineId, $user);
				return -1;
			}
		}

		$sql = 'INSERT INTO '.$this->db->prefix().'fieldservice_material_shipment';
		$sql .= ' (fk_material, fk_expedition, fk_expeditiondet, date_creation)';
		$sql .= ' VALUES ('.((int) $material->id).', '.((int) $shipment->id).', '.((int) $lineId).", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->cleanupCreatedShipmentLine($lineId, $user);
			return -1;
		}

		return 1;
	}

	/**
	 * Best-effort cleanup after a failed material-to-Shipment synchronization.
	 *
	 * @param int $lineId Newly created Shipment line id
	 * @param User $user Acting user
	 * @return void
	 */
	private function cleanupCreatedShipmentLine($lineId, User $user)
	{
		$line = new ExpeditionLigne($this->db);
		if ($line->fetch((int) $lineId) > 0 && $line->delete($user, 1) < 0) {
			$this->errors[] = 'Failed to clean up Shipment line #'.((int) $lineId).': '.$line->error;
		}
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
	 * Check whether a Shipment line belongs to a Field Service managed Shipment.
	 *
	 * @param int $shipmentLineId Shipment line id
	 * @return int<-1,1> 1 mapped, 0 not mapped, negative on error
	 */
	public function isLineOnMappedShipment($shipmentLineId)
	{
		$sql = 'SELECT fws.rowid';
		$sql .= ' FROM '.$this->db->prefix().'expeditiondet as ed';
		$sql .= ' INNER JOIN '.$this->db->prefix().'fieldservice_workorder_shipment as fws ON fws.fk_expedition = ed.fk_expedition';
		$sql .= ' WHERE ed.rowid = '.((int) $shipmentLineId);
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
	 * Verify that Field Service material rows still match their Shipment representation.
	 *
	 * This is a finalization gate. It checks the physical provenance used by
	 * Dolibarr stock movements before Shipment validation/closing can mutate stock.
	 *
	 * @param int $fichinterId Intervention id
	 * @return int<-1,1>
	 */
	public function validateWorkOrderShipmentIntegrity($fichinterId)
	{
		$sql = 'SELECT ed.rowid';
		$sql .= ' FROM '.$this->db->prefix().'fieldservice_workorder_shipment as fws';
		$sql .= ' INNER JOIN '.$this->db->prefix().'expeditiondet as ed ON ed.fk_expedition = fws.fk_expedition';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'fieldservice_material_shipment as fms';
		$sql .= ' ON fms.fk_expedition = ed.fk_expedition AND fms.fk_expeditiondet = ed.rowid';
		$sql .= ' WHERE fws.fk_fichinter = '.((int) $fichinterId);
		$sql .= ' AND fms.rowid IS NULL';
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$unmanagedLine = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if ($unmanagedLine) {
			return $this->failShipmentIntegrity(0, 'unmanaged shipment line #'.((int) $unmanagedLine->rowid));
		}

		$sql = 'SELECT fm.rowid as material_id, fm.fk_product as material_product,';
		$sql .= ' fm.fk_entrepot as material_warehouse, fm.qty as material_qty,';
		$sql .= ' fm.origin_type as material_origin_type, fm.fk_origin_line as material_origin_line,';
		$sql .= ' fms.fk_expedition, fms.fk_expeditiondet,';
		$sql .= ' e.rowid as shipment_id, e.fk_statut as shipment_status, ed.rowid as shipment_line_id,';
		$sql .= ' ed.fk_product as line_product, ed.fk_entrepot as line_warehouse,';
		$sql .= ' ed.qty as line_qty, ed.element_type as line_element_type, ed.fk_elementdet as line_origin_line';
		$sql .= ' FROM '.$this->db->prefix().'fieldservice_material as fm';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'fieldservice_material_shipment as fms ON fms.fk_material = fm.rowid';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'expedition as e ON e.rowid = fms.fk_expedition';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'expeditiondet as ed';
		$sql .= ' ON ed.rowid = fms.fk_expeditiondet AND ed.fk_expedition = fms.fk_expedition';
		$sql .= ' WHERE fm.fk_fichinter = '.((int) $fichinterId);
		$sql .= ' ORDER BY fm.rowid ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$materialId = (int) $obj->material_id;
			if (empty($obj->shipment_id) || empty($obj->shipment_line_id)) {
				$this->db->free($resql);
				return $this->failShipmentIntegrity($materialId, 'missing shipment or shipment line');
			}
			if ((int) $obj->material_product !== (int) $obj->line_product) {
				$this->db->free($resql);
				return $this->failShipmentIntegrity($materialId, 'product mismatch');
			}
			if ((int) $obj->material_warehouse !== (int) $obj->line_warehouse) {
				$this->db->free($resql);
				return $this->failShipmentIntegrity($materialId, 'warehouse mismatch');
			}
			if (abs((float) $obj->material_qty - (float) $obj->line_qty) > 0.00000001) {
				$this->db->free($resql);
				return $this->failShipmentIntegrity($materialId, 'quantity mismatch');
			}

			$hasOrderOrigin = ((string) $obj->material_origin_type === 'commande' && !empty($obj->material_origin_line));
			if ($hasOrderOrigin) {
				if (!in_array((string) $obj->line_element_type, array('order', 'commande'), true) || (int) $obj->line_origin_line !== (int) $obj->material_origin_line) {
					$this->db->free($resql);
					return $this->failShipmentIntegrity($materialId, 'order-line provenance mismatch');
				}
			} elseif (!empty($obj->line_origin_line)) {
				$this->db->free($resql);
				return $this->failShipmentIntegrity($materialId, 'unexpected order-line provenance');
			}

			$product = new Product($this->db);
			if ($product->fetch((int) $obj->material_product, '', '', '', 1, 1, 1) <= 0) {
				$this->db->free($resql);
				$this->error = $product->error;
				$this->errors = $product->errors;
				return -1;
			}

			$shipmentBatch = new ExpeditionLineBatch($this->db);
			$shipmentBatches = $shipmentBatch->fetchAll((int) $obj->shipment_line_id, (int) $obj->material_product);
			if (!is_array($shipmentBatches)) {
				$this->db->free($resql);
				$this->error = $shipmentBatch->error;
				$this->errors = $shipmentBatch->errors;
				return -1;
			}

			if ($product->hasbatch()) {
				if (!isModEnabled('productbatch')) {
					$this->db->free($resql);
					return $this->failShipmentIntegrity($materialId, 'lot/serial module disabled');
				}
				$allocation = new FieldServiceMaterialAllocation($this->db);
				$allocations = $allocation->fetchAllByMaterial($materialId);
				if (!is_array($allocations)) {
					$this->db->free($resql);
					$this->error = $allocation->error;
					$this->errors = $allocation->errors;
					return -1;
				}

				$expected = array();
				foreach ($allocations as $allocationRow) {
					$batch = (string) $allocationRow->batch;
					if ($batch === '') {
						$this->db->free($resql);
						return $this->failShipmentIntegrity($materialId, 'empty lot/serial allocation');
					}
					if (!isset($expected[$batch])) {
						$expected[$batch] = 0.0;
					}
					$expected[$batch] += (float) $allocationRow->qty;
				}

				$actual = array();
				foreach ($shipmentBatches as $batchRow) {
					$batch = (string) $batchRow->batch;
					if ($batch === '' || (int) $batchRow->fk_warehouse !== (int) $obj->material_warehouse || empty($batchRow->fk_origin_stock)) {
						$this->db->free($resql);
						return $this->failShipmentIntegrity($materialId, 'invalid shipment lot/serial provenance');
					}
					if ((int) $obj->shipment_status === Expedition::STATUS_DRAFT) {
						$stockBatch = new Productbatch($this->db);
						if ($stockBatch->fetch((int) $batchRow->fk_origin_stock) <= 0
							|| (int) $stockBatch->fk_product !== (int) $obj->material_product
							|| (int) $stockBatch->warehouseid !== (int) $obj->material_warehouse
							|| (string) $stockBatch->batch !== $batch
							|| (float) $stockBatch->qty + 0.00000001 < (float) $batchRow->qty) {
							$this->db->free($resql);
							return $this->failShipmentIntegrity($materialId, 'shipment lot/serial stock origin mismatch');
						}
					}
					if (!isset($actual[$batch])) {
						$actual[$batch] = 0.0;
					}
					$actual[$batch] += (float) $batchRow->qty;
				}

				ksort($expected, SORT_NATURAL | SORT_FLAG_CASE);
				ksort($actual, SORT_NATURAL | SORT_FLAG_CASE);
				if (array_keys($expected) !== array_keys($actual)) {
					$this->db->free($resql);
					return $this->failShipmentIntegrity($materialId, 'lot/serial set mismatch');
				}
				foreach ($expected as $batch => $qty) {
					if (!isset($actual[$batch]) || abs((float) $qty - (float) $actual[$batch]) > 0.00000001) {
						$this->db->free($resql);
						return $this->failShipmentIntegrity($materialId, 'lot/serial quantity mismatch');
					}
					if ((int) $product->status_batch === 2 && (abs((float) $qty - 1.0) > 0.00000001 || abs((float) $actual[$batch] - 1.0) > 0.00000001)) {
						$this->db->free($resql);
						return $this->failShipmentIntegrity($materialId, 'serial quantity must be one');
					}
				}
				if (abs(array_sum($actual) - (float) $obj->material_qty) > 0.00000001) {
					$this->db->free($resql);
					return $this->failShipmentIntegrity($materialId, 'lot/serial total quantity mismatch');
				}
			} elseif (!empty($shipmentBatches)) {
				$this->db->free($resql);
				return $this->failShipmentIntegrity($materialId, 'unexpected lot/serial rows');
			}
		}
		$this->db->free($resql);
		return 1;
	}

	/**
	 * Store a deterministic integrity failure.
	 *
	 * @param int $materialId Material row id, 0 for Shipment-level failure
	 * @param string $reason Technical reason
	 * @return int<-1,-1>
	 */
	private function failShipmentIntegrity($materialId, $reason)
	{
		$this->error = 'FieldServiceShipmentIntegrityFailed';
		$prefix = $materialId > 0 ? 'Material #'.((int) $materialId).': ' : 'Shipment: ';
		$this->errors = array($prefix.$reason);
		return -1;
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

		$integrity = $this->validateWorkOrderShipmentIntegrity($workOrder->id);
		if ($integrity < 0) {
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
