<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       fieldservice/class/fieldservicematerial.class.php
 * \ingroup    fieldservice
 * \brief      Field Service material usage object.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Material used on a Field Service work sheet.
 *
 * The underlying Dolibarr work-sheet object is the core Fichinter object.
 */
class FieldServiceMaterial extends CommonObject
{
	public const STATUS_DRAFT = 0;
	public const STATUS_POSTED = 1;
	public const STATUS_REVERSED = 2;

	/** @var string Object element code. */
	public $element = 'fieldservice_material';

	/** @var string Database table without prefix. */
	public $table_element = 'fieldservice_material';

	/** @var string Icon. */
	public $picto = 'product';

	/** @var int<0,1> Manage entity directly on this object. */
	public $ismultientitymanaged = 1;

	/** @var int<0,1> No extrafields in the first milestone. */
	public $isextrafieldmanaged = 0;

	/** @var int Entity. */
	public $entity;
	/** @var int Core Fichinter rowid. */
	public $fk_fichinter;
	/** @var int Product rowid. */
	public $fk_product;
	/** @var int Warehouse rowid. */
	public $fk_entrepot;
	/** @var float Quantity used. */
	public $qty;
	/** @var int|null Unit rowid. */
	public $fk_unit;
	/** @var int|null Usage date timestamp. */
	public $date_use;
	/** @var int Posting status. */
	public $status;
	/** @var string|null Origin object type. */
	public $origin_type;
	/** @var int|null Origin line rowid. */
	public $fk_origin_line;
	/** @var string|null Free description. */
	public $description;
	/** @var int|null Creating user rowid. */
	public $fk_user_create;
	/** @var int|null Modifying user rowid. */
	public $fk_user_modif;
	/** @var int|null Creation timestamp. */
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
			'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'index' => 1, 'position' => 1),
			'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => -1, 'default' => 1, 'notnull' => 1, 'index' => 1, 'position' => 5),
			'fk_fichinter' => array('type' => 'integer', 'label' => 'Intervention', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'index' => 1, 'position' => 10),
			'fk_product' => array('type' => 'integer:Product:product/class/product.class.php', 'label' => 'Product', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'index' => 1, 'position' => 20),
			'fk_entrepot' => array('type' => 'integer:Entrepot:product/stock/class/entrepot.class.php', 'label' => 'Warehouse', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'index' => 1, 'position' => 30),
			'qty' => array('type' => 'double(24,8)', 'label' => 'Qty', 'enabled' => 1, 'visible' => 1, 'default' => 0, 'notnull' => 1, 'position' => 40),
			'fk_unit' => array('type' => 'integer', 'label' => 'Unit', 'enabled' => 1, 'visible' => -1, 'position' => 45),
			'date_use' => array('type' => 'datetime', 'label' => 'Date', 'enabled' => 1, 'visible' => 1, 'position' => 50),
			'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'default' => self::STATUS_DRAFT, 'notnull' => 1, 'index' => 1, 'position' => 60),
			'origin_type' => array('type' => 'varchar(32)', 'label' => 'Origin', 'enabled' => 1, 'visible' => -1, 'position' => 70),
			'fk_origin_line' => array('type' => 'integer', 'label' => 'OriginLine', 'enabled' => 1, 'visible' => -1, 'position' => 71),
			'description' => array('type' => 'text', 'label' => 'Description', 'enabled' => 1, 'visible' => 1, 'position' => 80),
			'fk_user_create' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => 1, 'visible' => -1, 'position' => 90),
			'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'enabled' => 1, 'visible' => -1, 'position' => 91),
			'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'position' => 500),
			'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'visible' => -1, 'notnull' => 0, 'position' => 501),
		);
	}

	/**
	 * Create material line.
	 *
	 * @param User $user User creating the line
	 * @param int<0,1> $notrigger Disable triggers
	 * @return int<-1,max> New row id or negative value on error
	 */
	public function create(User $user, $notrigger = 0)
	{
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Fetch material line.
	 *
	 * @param int $id Row id
	 * @param string|null $ref Unused reference parameter for CommonObject compatibility
	 * @param int<0,1> $noextrafields Do not fetch extrafields
	 * @param int<0,1> $nolines Unused
	 * @return int<-1,1>
	 */
	public function fetch($id, $ref = null, $noextrafields = 0, $nolines = 0)
	{
		return $this->fetchCommon($id, $ref, '', $noextrafields);
	}

	/**
	 * Update material line.
	 *
	 * @param User $user User updating the line
	 * @param int<0,1> $notrigger Disable triggers
	 * @return int<-1,1>
	 */
	public function update(User $user, $notrigger = 0)
	{
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete material line.
	 *
	 * Only draft material lines should be passed to this method by callers.
	 *
	 * @param User $user User deleting the line
	 * @param int<0,1> $notrigger Disable triggers
	 * @return int<-1,1>
	 */
	public function delete(User $user, $notrigger = 0)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	/**
	 * Count material lines belonging to one Intervention.
	 *
	 * Used by Dolibarr's external-tab badge mechanism.
	 *
	 * @param int $fichinterId Core Fichinter id
	 * @param mixed $unused Compatibility argument supplied by tab loader
	 * @return int
	 */
	public function countForIntervention($fichinterId, $unused = null)
	{
		global $conf;

		$sql = 'SELECT COUNT(rowid) as nb';
		$sql .= ' FROM '.$this->db->prefix().$this->table_element;
		$sql .= ' WHERE entity = '.((int) $conf->entity);
		$sql .= ' AND fk_fichinter = '.((int) $fichinterId);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $obj ? (int) $obj->nb : 0;
	}

	/**
	 * Load material lines belonging to one Intervention.
	 *
	 * @param int $fichinterId Core Fichinter id
	 * @return array<int,FieldServiceMaterial>|int<-1,-1>
	 */
	public function fetchAllByIntervention($fichinterId)
	{
		global $conf;

		$records = array();
		$sql = 'SELECT rowid';
		$sql .= ' FROM '.$this->db->prefix().$this->table_element;
		$sql .= ' WHERE entity = '.((int) $conf->entity);
		$sql .= ' AND fk_fichinter = '.((int) $fichinterId);
		$sql .= ' ORDER BY date_use ASC, rowid ASC';

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
}
