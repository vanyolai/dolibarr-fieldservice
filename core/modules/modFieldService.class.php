<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       fieldservice/core/modules/modFieldService.class.php
 * \ingroup    fieldservice
 * \brief      Module descriptor for Field Service.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module FieldService.
 */
class modFieldService extends DolibarrModules
{
	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		// Local/private external module range. Reserve a public ID range before distribution.
		$this->numero = 550000;
		$this->rights_class = 'fieldservice';
		$this->family = 'technic';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModuleFieldServiceDesc';
		$this->descriptionlong = 'ModuleFieldServiceDesc';
		$this->editor_name = 'Krisztian Vanyolai';
		$this->editor_url = 'https://github.com/vanyolai/dolibarr-fieldservice';
		$this->version = '0.1.0-dev';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'tools';

		$this->module_parts = array(
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(
				'data' => array('interventioncard'),
			),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0,
		);

		$this->dirs = array();
		$this->config_page_url = array();
		$this->hidden = false;

		// Stock requires Product. ProductBatch remains optional so the module also works
		// with installations that do not use lot/serial tracking.
		$this->depends = array('modFicheinter', 'modStock', 'modExpedition');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('fieldservice@fieldservice');

		$this->phpmin = array(7, 2);
		$this->need_dolibarr_version = array(23, 0);
		$this->need_javascript_ajax = 0;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();
		$this->const = array();

		if (!isModEnabled('fieldservice')) {
			$conf->fieldservice = new stdClass();
			$conf->fieldservice->enabled = 0;
		}

		// Add material usage as an Intervention object tab. "intervention" is the
		// object type expected by Dolibarr's tab system; the underlying core class
		// remains Fichinter.
		$this->tabs = array();
		$this->tabs[] = array(
			'data' => 'intervention:+fieldservice_materials:FieldServiceMaterials,FieldServiceMaterial,/fieldservice/class/fieldservicematerial.class.php,countForIntervention:fieldservice@fieldservice:$user->hasRight("fieldservice", "materials", "read"):/fieldservice/material.php?id=__ID__'
		);

		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();

		$this->rights = array();
		$r = 0;

		$this->rights[$r][0] = 55000001;
		$this->rights[$r][1] = 'FieldServiceReadMaterials';
		$this->rights[$r][4] = 'materials';
		$this->rights[$r][5] = 'read';
		$r++;

		$this->rights[$r][0] = 55000002;
		$this->rights[$r][1] = 'FieldServiceWriteMaterials';
		$this->rights[$r][4] = 'materials';
		$this->rights[$r][5] = 'write';
		$r++;

		$this->rights[$r][0] = 55000003;
		$this->rights[$r][1] = 'FieldServicePostMaterials';
		$this->rights[$r][4] = 'materials';
		$this->rights[$r][5] = 'post';
		$r++;

		$this->rights[$r][0] = 55000004;
		$this->rights[$r][1] = 'FieldServiceDeleteMaterials';
		$this->rights[$r][4] = 'materials';
		$this->rights[$r][5] = 'delete';

		// Field Service now has an operational queue in addition to the
		// Intervention object tab.
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => '',
			'type' => 'top',
			'titre' => 'ModuleFieldServiceName',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'fieldservice',
			'leftmenu' => '',
			'url' => '/fieldservice/billing.php',
			'langs' => 'fieldservice@fieldservice',
			'position' => 90,
			'enabled' => "isModEnabled('fieldservice')",
			'perms' => '$user->hasRight("fieldservice", "materials", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=fieldservice',
			'type' => 'left',
			'titre' => 'FieldServiceBillingQueue',
			'mainmenu' => 'fieldservice',
			'leftmenu' => 'fieldservice_billing',
			'url' => '/fieldservice/billing.php',
			'langs' => 'fieldservice@fieldservice',
			'position' => 91,
			'enabled' => "isModEnabled('fieldservice')",
			'perms' => '$user->hasRight("fieldservice", "materials", "read")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Enable module.
	 *
	 * @param string $options Options when enabling module
	 * @return int 1 on success, <= 0 on error
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/fieldservice/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		$sql = array();
		return $this->_init($sql, $options);
	}

	/**
	 * Disable module.
	 *
	 * Data tables are intentionally preserved.
	 *
	 * @param string $options Options when disabling module
	 * @return int 1 on success, <= 0 on error
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
