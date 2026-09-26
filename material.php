<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       fieldservice/material.php
 * \ingroup    fieldservice
 * \brief      Material usage tab for a Dolibarr Intervention.
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/fichinter.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('companies', 'interventions', 'products', 'stocks', 'fieldservice@fieldservice'));
if (isModEnabled('productbatch')) {
	$langs->load('productbatch');
}

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');

if (!$user->hasRight('fieldservice', 'materials', 'read')) {
	accessforbidden();
}

if ($user->socid) {
	$socid = $user->socid;
}

$result = restrictedArea($user, 'ficheinter', $id, 'fichinter');

$object = new Fichinter($db);
$result = $object->fetch($id, $ref);
if ($result <= 0) {
	dol_print_error($db, $object->error, $object->errors);
	exit;
}

$object->fetch_thirdparty();

llxHeader('', $langs->trans('FieldServiceMaterials'), '', '', 0, 0, '', '', '', 'mod-fieldservice page-card_materials');

$form = new Form($db);
$head = fichinter_prepare_head($object);
print dol_get_fiche_head($head, 'fieldservice_materials', $langs->trans('InterventionCard'), -1, $object->picto);

$linkback = '<a href="'.DOL_URL_ROOT.'/fichinter/list.php?restore_lastsearch_values=1'.(!empty($socid) ? '&socid='.$socid : '').'">'.$langs->trans('BackToList').'</a>';

$morehtmlref = '<div class="refidno">';
$morehtmlref .= $form->editfieldkey('RefCustomer', 'ref_client', $object->ref_client, $object, 0, 'string', '', 0, 1);
$morehtmlref .= $form->editfieldval('RefCustomer', 'ref_client', $object->ref_client, $object, 0, 'string', '', null, null, '', 1);
$morehtmlref .= '<br>'.$object->thirdparty->getNomUrl(1, 'customer');
$morehtmlref .= '</div>';

dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';
print load_fiche_titre($langs->trans('FieldServiceMaterials'), '', 'product');
print '<div class="opacitymedium">'.$langs->trans('FieldServiceMaterialsInitialHelp').'</div>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
