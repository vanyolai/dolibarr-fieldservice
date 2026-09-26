<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       fieldservice/core/triggers/interface_99_modFieldService_FieldServiceTriggers.class.php
 * \ingroup    fieldservice
 * \brief      Field Service lifecycle triggers.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once dol_buildpath('/fieldservice/class/fieldserviceworkorderstate.class.php');
require_once dol_buildpath('/fieldservice/class/fieldserviceshipmentservice.class.php');

/**
 * Field Service triggers.
 */
class InterfaceFieldServiceTriggers extends DolibarrTriggers
{
	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'technic';
		$this->description = 'Field Service lifecycle triggers';
		$this->version = self::VERSIONS['dev'];
		$this->picto = 'tools';
	}

	/**
	 * Run trigger.
	 *
	 * @param string $action Trigger code
	 * @param CommonObject $object Trigger object
	 * @param User $user Acting user
	 * @param Translate $langs Translation handler
	 * @param Conf $conf Configuration
	 * @return int<-1,1>
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('fieldservice')) {
			return 0;
		}

		if ($action === 'FICHINTER_CLOSE') {
			return $this->onInterventionClose($object, $user);
		}

		return 0;
	}

	/**
	 * Separate operational completion from invoicing.
	 *
	 * Shipment finalization will be added to this same close gate by the
	 * shipment-integration service. For now this persists the billing queue
	 * transition and does not alter Dolibarr core Fichinter status semantics.
	 *
	 * @param CommonObject $object Fichinter being closed
	 * @param User $user Acting user
	 * @return int<-1,1>
	 */
	private function onInterventionClose($object, User $user)
	{
		$shipmentService = new FieldServiceShipmentService($this->db);
		$result = $shipmentService->finalizeWorkOrderShipments($object, $user);
		if ($result < 0) {
			$this->error = $shipmentService->error;
			$this->errors = $shipmentService->errors;
			return -1;
		}

		$state = new FieldServiceWorkOrderState($this->db);
		$result = $state->ensureForIntervention((int) $object->id, $user);
		if ($result < 0) {
			$this->error = $state->error;
			$this->errors = $state->errors;
			return -1;
		}

		if ((int) $state->billing_status === FieldServiceWorkOrderState::BILLING_OPEN) {
			$result = $state->setBillingStatus(FieldServiceWorkOrderState::BILLING_PENDING, $user);
			if ($result < 0) {
				$this->error = $state->error;
				$this->errors = $state->errors;
				return -1;
			}
		}

		return 1;
	}
}
