<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       fieldservice/class/fieldserviceworkorderstate.class.php
 * \ingroup    fieldservice
 * \brief      Field Service metadata that must remain independent from Fichinter operational status.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Field Service work-order metadata.
 *
 * Dolibarr Fichinter status answers whether the work is draft/validated/done.
 * Billing is intentionally tracked separately so a completed intervention can
 * remain visibly pending for invoicing without keeping the work order open.
 */
class FieldServiceWorkOrderState extends CommonObject
{
	public const BILLING_OPEN = 0;
	public const BILLING_PENDING = 1;
	public const BILLING_PARTIAL = 2;
	public const BILLING_INVOICED = 3;
	public const BILLING_NOT_BILLABLE = 4;

	/** @var string */
	public $element = 'fieldservice_workorder';

	/** @var string */
	public $table_element = 'fieldservice_workorder';

	/** @var int<0,1> */
	public $ismultientitymanaged = 1;

	/** @var int<0,1> */
	public $isextrafieldmanaged = 0;

	/** @var int */
	public $entity;
	/** @var int */
	public $fk_fichinter;
	/** @var int */
	public $billing_status;
	/** @var int|null */
	public $date_billing_ready;
	/** @var int|null */
	public $date_billed;
	/** @var int|null */
	public $fk_user_modif;
	/** @var int|null */
	public $date_creation;
	/** @var int|null */
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
			'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => -1, 'default' => 1, 'notnull' => 1, 'index' => 1, 'position' => 5),
			'fk_fichinter' => array('type' => 'integer', 'label' => 'Intervention', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'index' => 1, 'position' => 10),
			'billing_status' => array('type' => 'integer', 'label' => 'FieldServiceBillingStatus', 'enabled' => 1, 'visible' => 1, 'default' => self::BILLING_OPEN, 'notnull' => 1, 'index' => 1, 'position' => 20),
			'date_billing_ready' => array('type' => 'datetime', 'label' => 'FieldServiceBillingReadyDate', 'enabled' => 1, 'visible' => -1, 'position' => 30),
			'date_billed' => array('type' => 'datetime', 'label' => 'FieldServiceBilledDate', 'enabled' => 1, 'visible' => -1, 'position' => 31),
			'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'enabled' => 1, 'visible' => -1, 'position' => 90),
			'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'position' => 500),
			'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'visible' => -1, 'position' => 501),
		);
	}

	/**
	 * Create state row.
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
	 * Fetch state by row id.
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
	 * Fetch state belonging to an Intervention.
	 *
	 * @param int $fichinterId Fichinter row id
	 * @return int<-1,1> 1 found, 0 not found, negative on error
	 */
	public function fetchByIntervention($fichinterId)
	{
		global $conf;

		$sql = 'SELECT rowid';
		$sql .= ' FROM '.$this->db->prefix().$this->table_element;
		$sql .= ' WHERE entity = '.((int) $conf->entity);
		$sql .= ' AND fk_fichinter = '.((int) $fichinterId);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if (!$obj) {
			return 0;
		}

		return $this->fetch((int) $obj->rowid);
	}

	/**
	 * Ensure a metadata row exists for an Intervention.
	 *
	 * @param int $fichinterId Fichinter row id
	 * @param User $user Acting user
	 * @return int<-1,max> Existing/new row id or negative on error
	 */
	public function ensureForIntervention($fichinterId, User $user)
	{
		global $conf;

		$result = $this->fetchByIntervention($fichinterId);
		if ($result < 0) {
			return $result;
		}
		if ($result > 0) {
			return $this->id;
		}

		$this->entity = $conf->entity;
		$this->fk_fichinter = (int) $fichinterId;
		$this->billing_status = self::BILLING_OPEN;
		$this->date_creation = dol_now();
		$this->fk_user_modif = $user->id;

		return $this->create($user);
	}

	/**
	 * Change billing state independently of the Fichinter operational status.
	 *
	 * @param int $status One of BILLING_* constants
	 * @param User $user Acting user
	 * @return int<-1,1>
	 */
	public function setBillingStatus($status, User $user)
	{
		$allowed = array(
			self::BILLING_OPEN,
			self::BILLING_PENDING,
			self::BILLING_PARTIAL,
			self::BILLING_INVOICED,
			self::BILLING_NOT_BILLABLE,
		);
		if (!in_array((int) $status, $allowed, true)) {
			$this->error = 'InvalidBillingStatus';
			return -1;
		}

		$this->billing_status = (int) $status;
		$this->fk_user_modif = $user->id;

		if ($this->billing_status === self::BILLING_PENDING && empty($this->date_billing_ready)) {
			$this->date_billing_ready = dol_now();
		}
		if ($this->billing_status === self::BILLING_INVOICED) {
			$this->date_billed = dol_now();
		} elseif ($this->billing_status !== self::BILLING_INVOICED) {
			$this->date_billed = null;
		}

		return $this->updateCommon($user);
	}

	/**
	 * Return translation key for the current billing state.
	 *
	 * @return string
	 */
	public function getBillingStatusLabelKey()
	{
		switch ((int) $this->billing_status) {
			case self::BILLING_PENDING:
				return 'FieldServiceBillingPending';
			case self::BILLING_PARTIAL:
				return 'FieldServiceBillingPartial';
			case self::BILLING_INVOICED:
				return 'FieldServiceBillingInvoiced';
			case self::BILLING_NOT_BILLABLE:
				return 'FieldServiceBillingNotBillable';
			case self::BILLING_OPEN:
			default:
				return 'FieldServiceBillingOpen';
		}
	}
}
