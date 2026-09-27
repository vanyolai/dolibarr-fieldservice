<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       fieldservice/class/actions_fieldservice.class.php
 * \ingroup    fieldservice
 * \brief      Field Service hooks for Intervention UI.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
require_once __DIR__.'/fieldserviceworkorderstate.class.php';

/**
 * Field Service hook actions.
 */
class ActionsFieldService extends CommonHookActions
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $resprints = '';

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Show Field Service billing state on the Intervention card.
	 *
	 * This is deliberately a second status dimension. The core Fichinter status
	 * continues to represent operational state (Draft/Validated/Done).
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject $object Intervention
	 * @param string|null $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$this->resprints = '';
		if (($parameters['currentcontext'] ?? '') !== 'interventioncard' || empty($object->id)) {
			return 0;
		}

		$langs->load('fieldservice@fieldservice');

		$state = new FieldServiceWorkOrderState($this->db);
		$result = $state->fetchByIntervention((int) $object->id);
		$storedStatus = $result > 0 ? (int) $state->billing_status : null;
		$billingStatus = $this->resolveBillingStatus(
			(int) $object->status,
			property_exists($object, 'billed'),
			property_exists($object, 'billed') ? (int) $object->billed : 0,
			$storedStatus
		);

		$labelKey = $this->getBillingStatusLabelKey($billingStatus);
		$label = $langs->trans($labelKey);
		$statusCode = $this->getBillingStatusCode($billingStatus);

		$this->resprints .= '<div class="inline-block marginrightonly">';
		$this->resprints .= '<span class="opacitymedium">'.$langs->trans('FieldServiceBillingStatus').':</span> ';
		$this->resprints .= dolGetStatus($label, $label, '', $statusCode, 2);
		$this->resprints .= '</div>';

		return 0;
	}

	/**
	 * Add billing-state column title to Intervention list.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject $object List object
	 * @param string|null $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function printFieldListTitle($parameters, &$object, &$action, $hookmanager)
	{
		// Core now exposes operational status and billed state as separate columns.
		// Do not add a redundant Field Service billing column to the generic list.
		$this->resprints = '';
		return 0;
	}

	/**
	 * Do not add a redundant billing-state value to the Intervention list.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject $object List object
	 * @param string|null $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function printFieldListValue($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = '';
		return 0;
	}

	/**
	 * Resolve the effective Field Service billing state.
	 *
	 * When the core Fichinter exposes a dedicated billed flag, billed=1 is
	 * authoritative. Otherwise the module keeps its standalone behaviour, so
	 * the core enhancement remains optional.
	 *
	 * @param int $coreStatus Fichinter operational status
	 * @param bool $coreBilledAvailable Whether the core billed flag exists
	 * @param int $coreBilled Core billed flag
	 * @param int|null $storedStatus Stored Field Service billing status
	 * @return int
	 */
	private function resolveBillingStatus($coreStatus, $coreBilledAvailable, $coreBilled, $storedStatus)
	{
		if ($coreBilledAvailable && $coreBilled) {
			return FieldServiceWorkOrderState::BILLING_INVOICED;
		}

		if ($storedStatus !== null) {
			return (int) $storedStatus;
		}

		return ((int) $coreStatus === 3)
			? FieldServiceWorkOrderState::BILLING_PENDING
			: FieldServiceWorkOrderState::BILLING_OPEN;
	}

	/**
	 * Return translation key for a billing state.
	 *
	 * @param int $status Billing state
	 * @return string
	 */
	private function getBillingStatusLabelKey($status)
	{
		switch ((int) $status) {
			case FieldServiceWorkOrderState::BILLING_PENDING:
				return 'FieldServiceBillingPending';
			case FieldServiceWorkOrderState::BILLING_PARTIAL:
				return 'FieldServiceBillingPartial';
			case FieldServiceWorkOrderState::BILLING_INVOICED:
				return 'FieldServiceBillingInvoiced';
			case FieldServiceWorkOrderState::BILLING_NOT_BILLABLE:
				return 'FieldServiceBillingNotBillable';
			case FieldServiceWorkOrderState::BILLING_OPEN:
			default:
				return 'FieldServiceBillingOpen';
		}
	}

	/**
	 * Return Dolibarr status color code.
	 *
	 * @param int $status Billing state
	 * @return string
	 */
	private function getBillingStatusCode($status)
	{
		switch ((int) $status) {
			case FieldServiceWorkOrderState::BILLING_PENDING:
				return 'status1';
			case FieldServiceWorkOrderState::BILLING_PARTIAL:
				return 'status3';
			case FieldServiceWorkOrderState::BILLING_INVOICED:
				return 'status6';
			case FieldServiceWorkOrderState::BILLING_NOT_BILLABLE:
				return 'status4';
			case FieldServiceWorkOrderState::BILLING_OPEN:
			default:
				return 'status0';
		}
	}
}
