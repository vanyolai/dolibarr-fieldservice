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
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
require_once __DIR__.'/class/fieldservicematerial.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('companies', 'interventions', 'products', 'stocks', 'errors', 'fieldservice@fieldservice'));
if (isModEnabled('productbatch')) {
	$langs->load('productbatch');
}

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$materialid = GETPOSTINT('materialid');

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

$form = new Form($db);
$formproduct = new FormProduct($db);
$material = new FieldServiceMaterial($db);

/*
 * Actions
 */

if ($action === 'add') {
	if (!$user->hasRight('fieldservice', 'materials', 'write')) {
		accessforbidden();
	}

	$productid = GETPOSTINT('fk_product');
	$warehouseid = GETPOSTINT('fk_entrepot');
	$qty = GETPOSTFLOAT('qty');
	$description = GETPOST('description', 'restricthtml');
	$dateuse = dol_mktime(12, 0, 0, GETPOSTINT('date_usemonth'), GETPOSTINT('date_useday'), GETPOSTINT('date_useyear'));

	$error = 0;
	$product = new Product($db);
	$warehouse = new Entrepot($db);

	if ($productid <= 0 || $product->fetch($productid) <= 0 || (int) $product->type !== 0) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->trans('Product')), null, 'errors');
		$error++;
	}
	if ($warehouseid <= 0 || $warehouse->fetch($warehouseid) <= 0 || empty($warehouse->statut)) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->trans('Warehouse')), null, 'errors');
		$error++;
	}
	if ($qty <= 0) {
		setEventMessages($langs->trans('ErrorEmptyValueForQty'), null, 'errors');
		$error++;
	}
	if (empty($dateuse)) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->trans('Date')), null, 'errors');
		$error++;
	}

	if (!$error) {
		$material->entity = $conf->entity;
		$material->fk_fichinter = $object->id;
		$material->fk_product = $productid;
		$material->fk_entrepot = $warehouseid;
		$material->qty = $qty;
		$material->fk_unit = null;
		$material->date_use = $dateuse;
		$material->status = FieldServiceMaterial::STATUS_DRAFT;
		$material->description = $description;
		$material->fk_user_create = $user->id;

		$result = $material->create($user);
		if ($result > 0) {
			setEventMessages($langs->trans('FieldServiceMaterialAdded'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
			exit;
		}

		setEventMessages($material->error, $material->errors, 'errors');
	}
}

if ($action === 'update' && $materialid > 0) {
	if (!$user->hasRight('fieldservice', 'materials', 'write')) {
		accessforbidden();
	}

	$result = $material->fetch($materialid);
	if ($result <= 0 || (int) $material->fk_fichinter !== (int) $object->id) {
		accessforbidden();
	}
	if ((int) $material->status !== FieldServiceMaterial::STATUS_DRAFT) {
		setEventMessages($langs->trans('FieldServiceOnlyDraftEditable'), null, 'errors');
		$action = '';
	} else {
		$productid = GETPOSTINT('fk_product');
		$warehouseid = GETPOSTINT('fk_entrepot');
		$qty = GETPOSTFLOAT('qty');
		$description = GETPOST('description', 'restricthtml');
		$dateuse = dol_mktime(12, 0, 0, GETPOSTINT('date_usemonth'), GETPOSTINT('date_useday'), GETPOSTINT('date_useyear'));

		$error = 0;
		$product = new Product($db);
		$warehouse = new Entrepot($db);

		if ($productid <= 0 || $product->fetch($productid) <= 0 || (int) $product->type !== 0) {
			setEventMessages($langs->trans('ErrorFieldRequired', $langs->trans('Product')), null, 'errors');
			$error++;
		}
		if ($warehouseid <= 0 || $warehouse->fetch($warehouseid) <= 0 || empty($warehouse->statut)) {
			setEventMessages($langs->trans('ErrorFieldRequired', $langs->trans('Warehouse')), null, 'errors');
			$error++;
		}
		if ($qty <= 0) {
			setEventMessages($langs->trans('ErrorEmptyValueForQty'), null, 'errors');
			$error++;
		}
		if (empty($dateuse)) {
			setEventMessages($langs->trans('ErrorFieldRequired', $langs->trans('Date')), null, 'errors');
			$error++;
		}

		if (!$error) {
			$material->fk_product = $productid;
			$material->fk_entrepot = $warehouseid;
			$material->qty = $qty;
			$material->date_use = $dateuse;
			$material->description = $description;
			$material->fk_user_modif = $user->id;

			$result = $material->update($user);
			if ($result > 0) {
				setEventMessages($langs->trans('FieldServiceMaterialUpdated'), null, 'mesgs');
				header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
				exit;
			}

			setEventMessages($material->error, $material->errors, 'errors');
		}

		$action = 'edit';
	}
}

if ($action === 'delete' && $materialid > 0) {
	if (!$user->hasRight('fieldservice', 'materials', 'delete')) {
		accessforbidden();
	}

	$result = $material->fetch($materialid);
	if ($result <= 0 || (int) $material->fk_fichinter !== (int) $object->id) {
		accessforbidden();
	}
	if ((int) $material->status !== FieldServiceMaterial::STATUS_DRAFT) {
		setEventMessages($langs->trans('FieldServiceOnlyDraftDeletable'), null, 'errors');
	} else {
		$result = $material->delete($user);
		if ($result > 0) {
			setEventMessages($langs->trans('FieldServiceMaterialDeleted'), null, 'mesgs');
		} else {
			setEventMessages($material->error, $material->errors, 'errors');
		}
	}

	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

$editmaterial = null;
if ($action === 'edit' && $materialid > 0) {
	if (!$user->hasRight('fieldservice', 'materials', 'write')) {
		accessforbidden();
	}

	$editmaterial = new FieldServiceMaterial($db);
	$result = $editmaterial->fetch($materialid);
	if ($result <= 0 || (int) $editmaterial->fk_fichinter !== (int) $object->id) {
		accessforbidden();
	}
	if ((int) $editmaterial->status !== FieldServiceMaterial::STATUS_DRAFT) {
		setEventMessages($langs->trans('FieldServiceOnlyDraftEditable'), null, 'errors');
		$editmaterial = null;
		$action = '';
	}
}

/*
 * View
 */

llxHeader('', $langs->trans('FieldServiceMaterials'), '', '', 0, 0, '', '', '', 'mod-fieldservice page-card_materials');

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

$lines = $material->fetchAllByIntervention($object->id);
if (!is_array($lines)) {
	setEventMessages($material->error, $material->errors, 'errors');
	$lines = array();
}

print load_fiche_titre($langs->trans('FieldServiceMaterials'), '', 'product');

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Product').'</td>';
print '<td>'.$langs->trans('Warehouse').'</td>';
print '<td class="right">'.$langs->trans('Qty').'</td>';
print '<td>'.$langs->trans('Date').'</td>';
print '<td>'.$langs->trans('Description').'</td>';
print '<td>'.$langs->trans('Status').'</td>';
print '<td class="right"></td>';
print '</tr>';

if (empty($lines)) {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
} else {
	foreach ($lines as $line) {
		$product = new Product($db);
		$warehouse = new Entrepot($db);
		$product->fetch((int) $line->fk_product);
		$warehouse->fetch((int) $line->fk_entrepot);

		print '<tr class="oddeven">';
		print '<td>'.$product->getNomUrl(1).' - '.dol_escape_htmltag($product->label).'</td>';
		print '<td>'.$warehouse->getNomUrl(1).'</td>';
		print '<td class="right">'.dol_escape_htmltag((string) ((float) $line->qty + 0)).'</td>';
		print '<td>'.dol_print_date($line->date_use, 'day').'</td>';
		print '<td>'.dol_nl2br(dol_escape_htmltag((string) $line->description)).'</td>';
		print '<td>';
		if ((int) $line->status === FieldServiceMaterial::STATUS_DRAFT) {
			print $langs->trans('Draft');
		} elseif ((int) $line->status === FieldServiceMaterial::STATUS_POSTED) {
			print $langs->trans('FieldServicePosted');
		} else {
			print $langs->trans('FieldServiceReversed');
		}
		print '</td>';
		print '<td class="right nowraponall">';

		if ((int) $line->status === FieldServiceMaterial::STATUS_DRAFT && $user->hasRight('fieldservice', 'materials', 'write')) {
			print '<a class="marginrightonly" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=edit&materialid='.$line->id.'">'.img_edit($langs->trans('Modify')).'</a>';
		}
		if ((int) $line->status === FieldServiceMaterial::STATUS_DRAFT && $user->hasRight('fieldservice', 'materials', 'delete')) {
			print '<form class="inline-block" method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'" onsubmit="return confirm(\''.dol_escape_js($langs->trans('FieldServiceConfirmDeleteMaterial')).'\');">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="delete">';
			print '<input type="hidden" name="materialid" value="'.$line->id.'">';
			print '<button type="submit" class="button-delete bordertransp" title="'.$langs->trans('Delete').'">'.img_delete().'</button>';
			print '</form>';
		}

		print '</td>';
		print '</tr>';
	}
}
print '</table>';
print '</div>';

if ($user->hasRight('fieldservice', 'materials', 'write')) {
	$isEdit = is_object($editmaterial);
	$selectedProduct = $isEdit ? (int) $editmaterial->fk_product : GETPOSTINT('fk_product');
	$selectedWarehouse = $isEdit ? (int) $editmaterial->fk_entrepot : (GETPOSTINT('fk_entrepot') > 0 ? GETPOSTINT('fk_entrepot') : -2);
	$selectedQty = $isEdit ? $editmaterial->qty : GETPOST('qty', 'alphanohtml');
	$selectedDescription = $isEdit ? $editmaterial->description : GETPOST('description', 'restricthtml');
	$selectedDate = $isEdit ? $editmaterial->date_use : dol_now();

	print '<br>';
	print load_fiche_titre($langs->trans($isEdit ? 'Modify' : 'Add'), '', '');

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.($isEdit ? 'update' : 'add').'">';
	if ($isEdit) {
		print '<input type="hidden" name="materialid" value="'.$editmaterial->id.'">';
	}

	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield fieldrequired">'.$langs->trans('Product').'</td><td>';
	print img_picto('', 'product', 'class="pictofixedwidth"');
	$form->select_produits($selectedProduct, 'fk_product', 0, 0, 0, -1, 2, '', 1, array(), 0, '1', 0, 'maxwidth500', 1, 'warehouseopen', null, 0);
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('Warehouse').'</td><td>';
	print img_picto('', 'stock', 'class="pictofixedwidth"');
	print $formproduct->selectWarehouses($selectedWarehouse, 'fk_entrepot', 'warehouseopen', 1, 0, 0, '', 0, 0, array(), 'minwidth300');
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('Qty').'</td><td>';
	print '<input class="flat right maxwidth100" type="text" name="qty" value="'.dol_escape_htmltag((string) $selectedQty).'">';
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('Date').'</td><td>';
	print $form->selectDate($selectedDate, 'date_use', 0, 0, 0);
	print '</td></tr>';

	print '<tr><td>'.$langs->trans('Description').'</td><td>';
	print '<textarea class="flat centpercent" rows="3" name="description">'.dol_escape_htmltag((string) $selectedDescription).'</textarea>';
	print '</td></tr>';
	print '</table>';

	print '<div class="center">';
	print '<input type="submit" class="button button-save" value="'.$langs->trans($isEdit ? 'Save' : 'Add').'">';
	if ($isEdit) {
		print ' ';
		print '<a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">'.$langs->trans('Cancel').'</a>';
	}
	print '</div>';
	print '</form>';
}

print '</div>';
print dol_get_fiche_end();

llxFooter();
$db->close();
