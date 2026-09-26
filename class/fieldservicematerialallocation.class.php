<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       fieldservice/class/fieldservicematerialallocation.class.php
 * \ingroup    fieldservice
 * \brief      Physical stock allocation behind a Field Service material line.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/productbatch.class.php';

/**
 * Material allocation.
 *
 * A row stores the selected lot/serial identity and quantity. It does not
 * reserve or modify Dolibarr stock. Actual stock movements are handled by a
 * separate posting service in a later workflow step.
 */
class FieldServiceMaterialAllocation extends CommonObject
{
	/** @var string Object element code. */
	public $element = 'fieldservice_material_alloc';

	/** @var string Database table without prefix. */
	public $table_element = 'fieldservice_material_alloc';

	/** @var int<0,1> Entity is inherited from the parent material line. */
	public $ismultientitymanaged = 0;

	/** @var int<0,1> No extrafields. */
	public $isextrafieldmanaged = 0;

	/** @var int Parent material row id. */
	public $fk_material;
	/** @var float Allocated quantity. */
	public $qty;
	/** @var string|null Lot/serial number. */
	public $batch;
	/** @var int|null Eat-by date snapshot. */
	public $eatby;
	/** @var int|null Sell-by date snapshot. */
	public $sellby;
	/** @var int|null Core stock movement row id. */
	public $fk_stock_movement;
	/** @var int|null Core reverse stock movement row id. */
	public $fk_reverse_stock_movement;
	/** @var int|null Posting date. */
	public $date_posted;
	/** @var int|null Posting user row id. */
	public $fk_user_posted;
	/** @var int|null Creation date. */
	public $date_creation;
	/** @var int|null Modification timestamp. */
	public $tms;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;

		$this->fields = array(
			'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'position' => 1),
			'fk_material' => array('type' => 'integer', 'label' => 'FieldServiceMaterials', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'index' => 1, 'position' => 10),
			'qty' => array('type' => 'double(24,8)', 'label' => 'Qty', 'enabled' => 1, 'visible' => 1, 'default' => 0, 'notnull' => 1, 'position' => 20),
			'batch' => array('type' => 'varchar(128)', 'label' => 'Batch', 'enabled' => 1, 'visible' => 1, 'position' => 30),
			'eatby' => array('type' => 'datetime', 'label' => 'EatByDate', 'enabled' => 1, 'visible' => -1, 'position' => 40),
			'sellby' => array('type' => 'datetime', 'label' => 'SellByDate', 'enabled' => 1, 'visible' => -1, 'position' => 41),
			'fk_stock_movement' => array('type' => 'integer', 'label' => 'StockMovement', 'enabled' => 1, 'visible' => -1, 'position' => 50),
			'fk_reverse_stock_movement' => array('type' => 'integer', 'label' => 'StockMovement', 'enabled' => 1, 'visible' => -1, 'position' => 51),
			'date_posted' => array('type' => 'datetime', 'label' => 'Date', 'enabled' => 1, 'visible' => -1, 'position' => 60),
			'fk_user_posted' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'User', 'enabled' => 1, 'visible' => -1, 'position' => 61),
			'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'position' => 500),
			'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'visible' => -1, 'position' => 501),
		);
	}

	/**
	 * Create allocation row.
	 *
	 * @param User $user User creating the row
	 * @param int<0,1> $notrigger Disable triggers
	 * @return int<-1,max>
	 */
	public function create(User $user, $notrigger = 0)
	{
		if (empty($this->date_creation)) {
			$this->date_creation = dol_now();
		}
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Fetch allocation row.
	 *
	 * @param int $id Row id
	 * @param string|null $ref Unused
	 * @param int<0,1> $noextrafields Do not fetch extrafields
	 * @param int<0,1> $nolines Unused
	 * @return int<-1,1>
	 */
	public function fetch($id, $ref = null, $noextrafields = 0, $nolines = 0)
	{
		return $this->fetchCommon($id, $ref, '', $noextrafields);
	}

	/**
	 * Load allocations for one material line.
	 *
	 * @param int $materialId Parent material row id
	 * @return array<int,FieldServiceMaterialAllocation>|int<-1,-1>
	 */
	public function fetchAllByMaterial($materialId)
	{
		$records = array();
		$sql = 'SELECT rowid';
		$sql .= ' FROM '.$this->db->prefix().$this->table_element;
		$sql .= ' WHERE fk_material = '.((int) $materialId);
		$sql .= ' ORDER BY batch ASC, rowid ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$record = new self($this->db);
			if ($record->fetch((int) $obj->rowid) > 0) {
				$records[$record->id] = $record;
			}
		}

		$this->db->free($resql);
		return $records;
	}

	/**
	 * Delete all unposted allocations for a material line.
	 *
	 * @param int $materialId Parent material row id
	 * @return int<-1,1>
	 */
	public function deleteDraftByMaterial($materialId)
	{
		$sql = 'DELETE FROM '.$this->db->prefix().$this->table_element;
		$sql .= ' WHERE fk_material = '.((int) $materialId);
		$sql .= ' AND fk_stock_movement IS NULL';
		$sql .= ' AND fk_reverse_stock_movement IS NULL';

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Sum allocated quantity for a material line.
	 *
	 * @param int $materialId Parent material row id
	 * @return float|int<-1,-1>
	 */
	public function getAllocatedQty($materialId)
	{
		$sql = 'SELECT SUM(qty) as qty';
		$sql .= ' FROM '.$this->db->prefix().$this->table_element;
		$sql .= ' WHERE fk_material = '.((int) $materialId);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $obj && $obj->qty !== null ? (float) $obj->qty : 0.0;
	}

	/**
	 * Read current Dolibarr lot/serial stock for one product and warehouse.
	 *
	 * This is a read-only view of the core stock model. Available lots/serials
	 * are obtained through Productbatch::findAllForProduct(); this module never
	 * writes core product_stock/product_batch tables directly.
	 *
	 * @param int $productId Product row id
	 * @param int $warehouseId Warehouse row id
	 * @return array<string,array{qty:float,eatby:int|null,sellby:int|null}>|int<-1,-1>
	 */
	public function getAvailableBatches($productId, $warehouseId)
	{
		$batches = array();

		$productBatch = new Productbatch($this->db);
		$batchList = $productBatch->findAllForProduct((int) $productId, (int) $warehouseId, 0, 'pl.batch', 'ASC');
		if (!is_array($batchList)) {
			$this->error = $productBatch->error;
			$this->errors = $productBatch->errors;
			return -1;
		}

		foreach ($batchList as $batchObject) {
			$batch = (string) $batchObject->batch;
			$qty = (float) $batchObject->qty;
			if ($batch === '' || $qty <= 0) {
				continue;
			}

			if (!isset($batches[$batch])) {
				$batches[$batch] = array(
					'qty' => 0.0,
					'eatby' => !empty($batchObject->eatby) ? (int) $batchObject->eatby : null,
					'sellby' => !empty($batchObject->sellby) ? (int) $batchObject->sellby : null,
				);
			}
			$batches[$batch]['qty'] += $qty;
		}

		ksort($batches, SORT_NATURAL | SORT_FLAG_CASE);
		return $batches;
	}
}
