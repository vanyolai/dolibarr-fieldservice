<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       fieldservice/billing.php
 * \ingroup    fieldservice
 * \brief      Completed Field Service work orders waiting for invoicing.
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once __DIR__.'/class/fieldserviceworkorderstate.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('companies', 'interventions', 'fieldservice@fieldservice'));

if (!$user->hasRight('fieldservice', 'materials', 'read')) {
	accessforbidden();
}

llxHeader('', $langs->trans('FieldServiceBillingQueue'), '', '', 0, 0, '', '', '', 'mod-fieldservice page-billing');

print load_fiche_titre($langs->trans('FieldServiceBillingQueue'), '', 'bill');

print '<div class="opacitymedium marginbottomonly">'.$langs->trans('FieldServiceBillingQueueHelp').'</div>';

$fichinterProbe = new Fichinter($db);
$hasCoreBilled = isset($fichinterProbe->fields['facture']) && property_exists($fichinterProbe, 'billed');

$sql = 'SELECT f.rowid, f.ref, f.ref_client, f.fk_statut, f.datet,';
if ($hasCoreBilled) {
	$sql .= ' f.facture as billed,';
}
$sql .= ' s.rowid as socid, s.nom as company_name,';
$sql .= ' fswo.billing_status, fswo.date_billing_ready';
$sql .= ' FROM '.$db->prefix().'fichinter as f';
$sql .= ' INNER JOIN '.$db->prefix().'societe as s ON s.rowid = f.fk_soc';
$sql .= ' LEFT JOIN '.$db->prefix().'fieldservice_workorder as fswo';
$sql .= ' ON fswo.fk_fichinter = f.rowid AND fswo.entity = f.entity';
$sql .= ' WHERE f.entity IN ('.getEntity('intervention').')';
$sql .= ' AND f.fk_statut = '.Fichinter::STATUS_CLOSED;
if ($hasCoreBilled) {
	$sql .= ' AND f.facture = 0';
}
$sql .= ' AND (fswo.billing_status IS NULL';
$sql .= ' OR fswo.billing_status IN ('.FieldServiceWorkOrderState::BILLING_OPEN.','.FieldServiceWorkOrderState::BILLING_PENDING.','.FieldServiceWorkOrderState::BILLING_PARTIAL.'))';
$sql .= ' ORDER BY COALESCE(fswo.date_billing_ready, f.datet, f.tms) ASC, f.rowid ASC';

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	llxFooter();
	$db->close();
	exit;
}

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Ref').'</td>';
print '<td>'.$langs->trans('ThirdParty').'</td>';
print '<td>'.$langs->trans('RefCustomer').'</td>';
print '<td>'.$langs->trans('FieldServiceWorkCompleted').'</td>';
print '<td>'.$langs->trans('FieldServiceBillingStatus').'</td>';
print '</tr>';

$num = $db->num_rows($resql);
if (!$num) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
}

while ($obj = $db->fetch_object($resql)) {
	$intervention = new Fichinter($db);
	$intervention->id = (int) $obj->rowid;
	$intervention->ref = $obj->ref;

	$thirdparty = new Societe($db);
	$thirdparty->id = (int) $obj->socid;
	$thirdparty->name = $obj->company_name;

	$billingStatus = $obj->billing_status === null
		? FieldServiceWorkOrderState::BILLING_PENDING
		: (int) $obj->billing_status;
	if ($billingStatus === FieldServiceWorkOrderState::BILLING_OPEN) {
		$billingStatus = FieldServiceWorkOrderState::BILLING_PENDING;
	}

	if ($billingStatus === FieldServiceWorkOrderState::BILLING_PARTIAL) {
		$label = $langs->trans('FieldServiceBillingPartial');
		$statusHtml = dolGetStatus($label, $label, '', 'status3', 2);
	} else {
		$label = $langs->trans('FieldServiceBillingPending');
		$statusHtml = dolGetStatus($label, $label, '', 'status1', 2);
	}

	print '<tr class="oddeven">';
	print '<td><a href="'.DOL_URL_ROOT.'/fichinter/card.php?id='.$intervention->id.'">'.img_object('', 'intervention', 'class="pictofixedwidth"').dol_escape_htmltag($intervention->ref).'</a></td>';
	print '<td><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.$thirdparty->id.'">'.dol_escape_htmltag($thirdparty->name).'</a></td>';
	print '<td>'.dol_escape_htmltag((string) $obj->ref_client).'</td>';
	print '<td>'.(!empty($obj->datet) ? dol_print_date($db->jdate($obj->datet), 'day') : '').'</td>';
	print '<td>'.$statusHtml.'</td>';
	print '</tr>';
}

print '</table>';
print '</div>';

$db->free($resql);
llxFooter();
$db->close();
