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
require_once __DIR__.'/class/fieldservicematerialallocation.class.php';

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
$allocation = new FieldServiceMaterialAllocation($db);
$allocmaterial = null;
$allocproduct = null;
$scannedProductId = 0;

/*
 * Actions
 */

if ($action === 'scanproduct') {
	if (!$user->hasRight('fieldservice', 'materials', 'write')) {
		accessforbidden();
	}

	$productBarcode = trim(GETPOST('product_barcode', 'alphanohtml'));
	if ($productBarcode === '') {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->trans('BarCode')), null, 'errors');
	} else {
		$scannedProduct = new Product($db);
		$result = $scannedProduct->fetch(0, '', '', $productBarcode, 0, 1, 1);
		if ($result > 0 && (int) $scannedProduct->type === 0) {
			$scannedProductId = (int) $scannedProduct->id;
			setEventMessages($langs->trans('FieldServiceProductScanned', $scannedProduct->ref), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('FieldServiceProductBarcodeNotFound', $productBarcode), null, 'errors');
		}
	}

	$action = '';
}

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

		if ($product->hasbatch()) {
			if (!isModEnabled('productbatch')) {
				setEventMessages($langs->trans('FieldServiceBatchModuleRequired'), null, 'errors');
			} else {
				// Do not create an incomplete material row. A LOT/SN-managed product
				// becomes persistent only together with a complete allocation.
				$allocmaterial = $material;
				$allocproduct = $product;
				$action = 'prepareadd';
			}
		} else {
			$result = $material->create($user);
			if ($result > 0) {
				setEventMessages($langs->trans('FieldServiceMaterialAdded'), null, 'mesgs');
				header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
				exit;
			}

			setEventMessages($material->error, $material->errors, 'errors');
		}
	}
}

if ($action === 'addallocated') {
	if (!$user->hasRight('fieldservice', 'materials', 'write')) {
		accessforbidden();
	}

	$productid = GETPOSTINT('fk_product');
	$warehouseid = GETPOSTINT('fk_entrepot');
	$qty = GETPOSTFLOAT('qty');
	$description = GETPOST('description', 'restricthtml');
	$dateuse = GETPOSTINT('date_use_ts');

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
	if (!$error && (!isModEnabled('productbatch') || !$product->hasbatch())) {
		setEventMessages($langs->trans('ProductDoesNotUseBatchSerial'), null, 'errors');
		$error++;
	}

	if (!$error) {
		$allocmaterial = new FieldServiceMaterial($db);
		$allocmaterial->entity = $conf->entity;
		$allocmaterial->fk_fichinter = $object->id;
		$allocmaterial->fk_product = $productid;
		$allocmaterial->fk_entrepot = $warehouseid;
		$allocmaterial->qty = $qty;
		$allocmaterial->fk_unit = null;
		$allocmaterial->date_use = $dateuse;
		$allocmaterial->status = FieldServiceMaterial::STATUS_DRAFT;
		$allocmaterial->description = $description;
		$allocmaterial->fk_user_create = $user->id;
		$allocproduct = $product;
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
			$oldProductId = (int) $material->fk_product;
			$oldWarehouseId = (int) $material->fk_entrepot;
			$oldQty = (float) $material->qty;
			$resetAllocation = $oldProductId !== $productid
				|| $oldWarehouseId !== $warehouseid
				|| abs($oldQty - (float) $qty) > 0.00000001;
			$allocatedBeforeUpdate = $resetAllocation ? $allocation->getAllocatedQty($material->id) : 0.0;
			$hadAllocation = is_numeric($allocatedBeforeUpdate) && (float) $allocatedBeforeUpdate > 0;

			$material->fk_product = $productid;
			$material->fk_entrepot = $warehouseid;
			$material->qty = $qty;
			$material->date_use = $dateuse;
			$material->description = $description;
			$material->fk_user_modif = $user->id;

			$db->begin();
			$result = $material->update($user);
			if ($result > 0 && $resetAllocation) {
				$result = $allocation->deleteDraftByMaterial($material->id);
			}

			if ($result > 0) {
				$db->commit();
				setEventMessages($langs->trans('FieldServiceMaterialUpdated'), null, 'mesgs');
				if ($hadAllocation) {
					setEventMessages($langs->trans('FieldServiceAllocationReset'), null, 'warnings');
				}
				header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
				exit;
			}

			$db->rollback();
			setEventMessages($material->error ?: $allocation->error, array_merge($material->errors, $allocation->errors), 'errors');
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
		$db->begin();
		$result = $allocation->deleteDraftByMaterial($material->id);
		if ($result > 0) {
			$result = $material->delete($user);
		}
		if ($result > 0) {
			$db->commit();
			setEventMessages($langs->trans('FieldServiceMaterialDeleted'), null, 'mesgs');
		} else {
			$db->rollback();
			setEventMessages($material->error ?: $allocation->error, array_merge($material->errors, $allocation->errors), 'errors');
		}
	}

	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if (($action === 'allocate' || $action === 'saveallocation') && $materialid > 0) {
	if (!$user->hasRight('fieldservice', 'materials', 'write')) {
		accessforbidden();
	}

	$allocmaterial = new FieldServiceMaterial($db);
	$result = $allocmaterial->fetch($materialid);
	if ($result <= 0 || (int) $allocmaterial->fk_fichinter !== (int) $object->id) {
		accessforbidden();
	}
	if ((int) $allocmaterial->status !== FieldServiceMaterial::STATUS_DRAFT) {
		setEventMessages($langs->trans('FieldServiceOnlyDraftEditable'), null, 'errors');
		$allocmaterial = null;
		$action = '';
	} else {
		$allocproduct = new Product($db);
		$result = $allocproduct->fetch((int) $allocmaterial->fk_product);
		if ($result <= 0 || !isModEnabled('productbatch') || !$allocproduct->hasbatch()) {
			setEventMessages($langs->trans('ProductDoesNotUseBatchSerial'), null, 'errors');
			$allocmaterial = null;
			$allocproduct = null;
			$action = '';
		}
	}
}

if (($action === 'saveallocation' || $action === 'addallocated') && is_object($allocmaterial) && is_object($allocproduct)) {
	$availableBatches = $allocation->getAvailableBatches((int) $allocmaterial->fk_product, (int) $allocmaterial->fk_entrepot);
	if (!is_array($availableBatches)) {
		setEventMessages($allocation->error, $allocation->errors, 'errors');
		$availableBatches = array();
	}

	$newAllocations = array();
	$error = 0;

	if ((int) $allocproduct->status_batch === 2) {
		if (abs((float) $allocmaterial->qty - round((float) $allocmaterial->qty)) > 0.00000001) {
			setEventMessages($langs->trans('FieldServiceSerialQtyMustBeInteger'), null, 'errors');
			$error++;
		}

		$serials = array();
		$selectedSerials = GETPOST('serial_select', 'array');
		if (is_array($selectedSerials)) {
			foreach ($selectedSerials as $serial) {
				$serial = trim(dol_string_nohtmltag((string) $serial));
				if ($serial !== '') {
					$serials[$serial] = $serial;
				}
			}
		}

		$bulkSerials = GETPOST('serial_bulk', 'restricthtml');
		if ($bulkSerials !== '') {
			foreach (preg_split('/\\R+/', (string) $bulkSerials) as $serial) {
				$serial = trim(dol_string_nohtmltag($serial));
				if ($serial !== '') {
					$serials[$serial] = $serial;
				}
			}
		}

		foreach ($serials as $serial) {
			if (!isset($availableBatches[$serial]) || (float) $availableBatches[$serial]['qty'] < 1) {
				setEventMessages($langs->trans('FieldServiceLotSerialNotAvailable', $serial), null, 'errors');
				$error++;
				continue;
			}
			$newAllocations[] = array(
				'batch' => $serial,
				'qty' => 1.0,
				'eatby' => $availableBatches[$serial]['eatby'],
				'sellby' => $availableBatches[$serial]['sellby'],
			);
		}

		if (!$error && count($newAllocations) !== (int) round((float) $allocmaterial->qty)) {
			setEventMessages($langs->trans('FieldServiceAllocationMustMatchQty', (string) ((float) $allocmaterial->qty + 0)), null, 'errors');
			$error++;
		}
	} else {
		$lotBatches = GETPOST('lot_batch', 'array');
		$lotQtys = GETPOST('lot_qty', 'array');
		$allocatedQty = 0.0;

		if (!is_array($lotBatches)) {
			$lotBatches = array();
		}
		if (!is_array($lotQtys)) {
			$lotQtys = array();
		}

		foreach ($lotBatches as $index => $batch) {
			$batch = trim(dol_string_nohtmltag((string) $batch));
			$qty = isset($lotQtys[$index]) ? (float) price2num((string) $lotQtys[$index]) : 0.0;
			if ($qty <= 0) {
				continue;
			}
			if (!isset($availableBatches[$batch])) {
				setEventMessages($langs->trans('FieldServiceLotSerialNotAvailable', $batch), null, 'errors');
				$error++;
				continue;
			}
			if ($qty - (float) $availableBatches[$batch]['qty'] > 0.00000001) {
				setEventMessages($langs->trans('FieldServiceAllocationExceedsStock', $batch, (string) ((float) $availableBatches[$batch]['qty'] + 0)), null, 'errors');
				$error++;
				continue;
			}

			$allocatedQty += $qty;
			$newAllocations[] = array(
				'batch' => $batch,
				'qty' => $qty,
				'eatby' => $availableBatches[$batch]['eatby'],
				'sellby' => $availableBatches[$batch]['sellby'],
			);
		}

		if (!$error && abs($allocatedQty - (float) $allocmaterial->qty) > 0.00000001) {
			setEventMessages($langs->trans('FieldServiceAllocationMustMatchQty', (string) ((float) $allocmaterial->qty + 0)), null, 'errors');
			$error++;
		}
	}

	if (!$error) {
		$isNewMaterial = ($action === 'addallocated');

		$db->begin();
		if ($isNewMaterial) {
			$result = $allocmaterial->create($user);
		} else {
			$result = $allocation->deleteDraftByMaterial($allocmaterial->id);
		}

		if ($result > 0) {
			foreach ($newAllocations as $allocationData) {
				$newAllocation = new FieldServiceMaterialAllocation($db);
				$newAllocation->fk_material = $allocmaterial->id;
				$newAllocation->qty = $allocationData['qty'];
				$newAllocation->batch = $allocationData['batch'];
				$newAllocation->eatby = $allocationData['eatby'];
				$newAllocation->sellby = $allocationData['sellby'];
				$newAllocation->date_creation = dol_now();
				$result = $newAllocation->create($user);
				if ($result <= 0) {
					$allocation->error = $newAllocation->error;
					$allocation->errors = $newAllocation->errors;
					break;
				}
			}
		}

		if ($result > 0) {
			$db->commit();
			setEventMessages($langs->trans($isNewMaterial ? 'FieldServiceMaterialAdded' : 'FieldServiceAllocationSaved'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
			exit;
		}

		$db->rollback();
		if ($isNewMaterial) {
			// The insert was rolled back; keep the object transient for re-rendering.
			$allocmaterial->id = 0;
		}
		setEventMessages($allocmaterial->error ?: $allocation->error, array_merge($allocmaterial->errors, $allocation->errors), 'errors');
	}

	$action = ($action === 'addallocated') ? 'prepareadd' : 'allocate';
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
print '<td>'.$langs->trans('LotSerial').'</td>';
print '<td>'.$langs->trans('Status').'</td>';
print '<td class="right"></td>';
print '</tr>';

if (empty($lines)) {
	print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
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
		print '<td class="nowraponall">';
		if (isModEnabled('productbatch') && $product->hasbatch()) {
			$lineAllocations = $allocation->fetchAllByMaterial($line->id);
			$allocatedQty = 0.0;
			$allocationLabels = array();
			if (is_array($lineAllocations)) {
				foreach ($lineAllocations as $lineAllocation) {
					$allocatedQty += (float) $lineAllocation->qty;
					$allocationLabels[] = (string) $lineAllocation->batch.((int) $product->status_batch === 1 ? ' × '.((float) $lineAllocation->qty + 0) : '');
				}
			}
			$allocationTitle = !empty($allocationLabels) ? implode(', ', $allocationLabels) : $langs->trans('FieldServiceAllocationRequired');
			print '<span title="'.dol_escape_htmltag($allocationTitle, 1).'">'.dol_escape_htmltag((string) ($allocatedQty + 0)).' / '.dol_escape_htmltag((string) ((float) $line->qty + 0)).'</span>';
			if ((int) $line->status === FieldServiceMaterial::STATUS_DRAFT && $user->hasRight('fieldservice', 'materials', 'write')) {
				print ' <a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=allocate&materialid='.$line->id.'" title="'.$langs->trans('FieldServiceAllocate').'">'.img_picto($langs->trans('FieldServiceAllocate'), 'barcode').'</a>';
			}
		} else {
			print '<span class="opacitymedium">-</span>';
		}
		print '</td>';
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
			print '<button type="submit" class="button-delete bordertransp cursorpointer" title="'.$langs->trans('Delete').'">'.img_delete().'</button>';
			print '</form>';
		}

		print '</td>';
		print '</tr>';
	}
}
print '</table>';
print '</div>';

if (is_object($allocmaterial) && is_object($allocproduct)) {
	$currentAllocations = array();
	if (!empty($allocmaterial->id)) {
		$currentAllocations = $allocation->fetchAllByMaterial($allocmaterial->id);
		if (!is_array($currentAllocations)) {
			setEventMessages($allocation->error, $allocation->errors, 'errors');
			$currentAllocations = array();
		}
	}

	$availableBatches = $allocation->getAvailableBatches((int) $allocmaterial->fk_product, (int) $allocmaterial->fk_entrepot);
	if (!is_array($availableBatches)) {
		setEventMessages($allocation->error, $allocation->errors, 'errors');
		$availableBatches = array();
	}

	$currentByBatch = array();
	foreach ($currentAllocations as $currentAllocation) {
		$batchKey = (string) $currentAllocation->batch;
		if (!isset($currentByBatch[$batchKey])) {
			$currentByBatch[$batchKey] = 0.0;
		}
		$currentByBatch[$batchKey] += (float) $currentAllocation->qty;
	}

	$warehouse = new Entrepot($db);
	$warehouse->fetch((int) $allocmaterial->fk_entrepot);

	print '<br>';
	print load_fiche_titre($langs->trans('FieldServiceAllocation'), '', 'barcode');

	print '<div class="fichecenter">';
	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield">'.$langs->trans('Product').'</td><td>'.$allocproduct->getNomUrl(1).' - '.dol_escape_htmltag($allocproduct->label).'</td></tr>';
	print '<tr><td>'.$langs->trans('Warehouse').'</td><td>'.$warehouse->getNomUrl(1).'</td></tr>';
	print '<tr><td>'.$langs->trans('Qty').'</td><td>'.dol_escape_htmltag((string) ((float) $allocmaterial->qty + 0)).'</td></tr>';
	print '</table>';
	print '</div>';

	$unavailableCurrent = array();
	foreach ($currentByBatch as $batch => $qty) {
		if (!isset($availableBatches[$batch])) {
			$unavailableCurrent[] = $batch;
		}
	}
	if (!empty($unavailableCurrent)) {
		print '<div class="warning">'.$langs->trans('FieldServiceAllocatedLotNoLongerAvailable', dol_escape_htmltag(implode(', ', $unavailableCurrent))).'</div>';
	}

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	if (!empty($allocmaterial->id)) {
		print '<input type="hidden" name="action" value="saveallocation">';
		print '<input type="hidden" name="materialid" value="'.$allocmaterial->id.'">';
	} else {
		print '<input type="hidden" name="action" value="addallocated">';
		print '<input type="hidden" name="fk_product" value="'.((int) $allocmaterial->fk_product).'">';
		print '<input type="hidden" name="fk_entrepot" value="'.((int) $allocmaterial->fk_entrepot).'">';
		print '<input type="hidden" name="qty" value="'.dol_escape_htmltag((string) $allocmaterial->qty, 1).'">';
		print '<input type="hidden" name="date_use_ts" value="'.((int) $allocmaterial->date_use).'">';
		print '<input type="hidden" name="description" value="'.dol_escape_htmltag((string) $allocmaterial->description, 1).'">';
	}

	if ((int) $allocproduct->status_batch === 2) {
		$submittedSelection = GETPOST('serial_select', 'array');
		$hasSubmittedSerials = GETPOSTISSET('serial_select') || GETPOSTISSET('serial_bulk');
		$selectedSerials = array();
		if ($hasSubmittedSerials && is_array($submittedSelection)) {
			foreach ($submittedSelection as $serial) {
				$selectedSerials[(string) $serial] = true;
			}
		} elseif (!$hasSubmittedSerials) {
			foreach ($currentByBatch as $serial => $qty) {
				$selectedSerials[$serial] = true;
			}
		}

		print '<br><table class="border centpercent tableforfield">';
		$bulkValue = GETPOSTISSET('serial_bulk') ? GETPOST('serial_bulk', 'restricthtml') : '';
		print '<tr><td class="titlefield">'.$langs->trans('FieldServiceSerialScan').'</td><td>';
		print '<textarea name="serial_bulk" class="flat minwidth500" rows="5" autocomplete="off" autofocus placeholder="'.$langs->trans('FieldServiceSerialScanHelp').'">'.dol_escape_htmltag((string) $bulkValue).'</textarea>';
		print '<div class="opacitymedium">'.$langs->trans('FieldServiceSerialScanHelp').'</div>';
		print '</td></tr>';

		print '<tr><td>'.$langs->trans('FieldServiceSerialList').'</td><td>';
		print '<select name="serial_select[]" class="flat minwidth500" multiple size="'.max(4, min(12, count($availableBatches))).'">';
		foreach ($availableBatches as $serial => $batchData) {
			print '<option value="'.dol_escape_htmltag($serial, 1).'"'.(isset($selectedSerials[$serial]) ? ' selected' : '').'>';
			print dol_escape_htmltag($serial).' — '.$langs->trans('CurrentStock').': '.dol_escape_htmltag((string) ((float) $batchData['qty'] + 0));
			print '</option>';
		}
		print '</select>';
		print '</td></tr>';

		print '</table>';
	} else {
		$submittedBatch = GETPOST('lot_batch', 'array');
		$submittedQty = GETPOST('lot_qty', 'array');
		$submittedByBatch = array();
		if (GETPOSTISSET('lot_batch') && is_array($submittedBatch) && is_array($submittedQty)) {
			foreach ($submittedBatch as $index => $batch) {
				$submittedByBatch[(string) $batch] = isset($submittedQty[$index]) ? (string) $submittedQty[$index] : '';
			}
		}

		print '<br><div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans('BatchNumberShort').'</td>';
		print '<td class="right">'.$langs->trans('CurrentStock').'</td>';
		print '<td>'.$langs->trans('SellByDate').'</td>';
		print '<td>'.$langs->trans('EatByDate').'</td>';
		print '<td class="right">'.$langs->trans('Qty').'</td>';
		print '</tr>';

		if (empty($availableBatches)) {
			print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('StockIsRequiredToChooseWhichLotToUse').'</span></td></tr>';
		} else {
			foreach ($availableBatches as $batch => $batchData) {
				if (GETPOSTISSET('lot_batch')) {
					$inputQty = $submittedByBatch[$batch] ?? '';
				} else {
					$inputQty = isset($currentByBatch[$batch]) ? (string) ($currentByBatch[$batch] + 0) : '';
				}
				print '<tr class="oddeven">';
				print '<td>'.dol_escape_htmltag($batch).'<input type="hidden" name="lot_batch[]" value="'.dol_escape_htmltag($batch, 1).'"></td>';
				print '<td class="right">'.dol_escape_htmltag((string) ((float) $batchData['qty'] + 0)).'</td>';
				print '<td>'.(!empty($batchData['sellby']) ? dol_print_date($batchData['sellby'], 'day') : '').'</td>';
				print '<td>'.(!empty($batchData['eatby']) ? dol_print_date($batchData['eatby'], 'day') : '').'</td>';
				print '<td class="right"><input type="text" class="flat right maxwidth100" name="lot_qty[]" value="'.dol_escape_htmltag($inputQty, 1).'"></td>';
				print '</tr>';
			}
		}
		print '</table>';
		print '</div>';
	}

	print '<div class="center">';
	print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
	print ' <a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">'.$langs->trans('Cancel').'</a>';
	print '</div>';
	print '</form>';
}

if (!is_object($allocmaterial) && $user->hasRight('fieldservice', 'materials', 'write')) {
	$isEdit = is_object($editmaterial);

	// Keep every submitted value after a validation error. Without this, selectDate()
	// would silently fall back to today and an edit form could fall back to stored values.
	$hasSubmittedValues = GETPOSTISSET('fk_product')
		|| GETPOSTISSET('fk_entrepot')
		|| GETPOSTISSET('qty')
		|| GETPOSTISSET('date_useyear')
		|| GETPOSTISSET('description');

	if ($hasSubmittedValues || $scannedProductId > 0) {
		$selectedProduct = $scannedProductId > 0 ? $scannedProductId : GETPOSTINT('fk_product');
		$selectedWarehouse = GETPOSTINT('fk_entrepot') > 0 ? GETPOSTINT('fk_entrepot') : -2;
		$postedQty = GETPOST('qty', 'alphanohtml');
		$selectedQty = ($scannedProductId > 0 && $postedQty === '') ? '1' : $postedQty;
		$selectedDescription = GETPOST('description', 'restricthtml');

		if (GETPOSTINT('date_useyear') > 0 && GETPOSTINT('date_usemonth') > 0 && GETPOSTINT('date_useday') > 0) {
			$selectedDate = dol_mktime(12, 0, 0, GETPOSTINT('date_usemonth'), GETPOSTINT('date_useday'), GETPOSTINT('date_useyear'));
		} else {
			$selectedDate = dol_now();
		}
	} else {
		$selectedProduct = $isEdit ? (int) $editmaterial->fk_product : 0;
		$selectedWarehouse = $isEdit ? (int) $editmaterial->fk_entrepot : -2;
		$selectedQty = $isEdit ? $editmaterial->qty : '';
		$selectedDescription = $isEdit ? $editmaterial->description : '';
		$selectedDate = $isEdit ? $editmaterial->date_use : dol_now();
	}

	print '<br>';
	print load_fiche_titre($langs->trans($isEdit ? 'Modify' : 'Add'), '', '');

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	if ($isEdit) {
		print '<input type="hidden" name="materialid" value="'.$editmaterial->id.'">';
	}

	print '<table class="border centpercent tableforfield">';
	if (!$isEdit) {
		print '<tr><td class="titlefield">'.$langs->trans('FieldServiceProductBarcode').'</td><td>';
		print img_picto('', 'barcode', 'class="pictofixedwidth"');
		print '<input type="text" class="flat minwidth300" name="product_barcode" value="" autocomplete="off" autofocus placeholder="'.$langs->trans('FieldServiceScanProductBarcode').'">';
		print ' <button type="submit" class="button" name="action" value="scanproduct">'.$langs->trans('Search').'</button>';
		print '</td></tr>';
	}
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
	print '<button type="submit" class="button button-save" name="action" value="'.($isEdit ? 'update' : 'add').'">'.$langs->trans($isEdit ? 'Save' : 'Add').'</button>';
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
