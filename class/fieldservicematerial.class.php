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
}
