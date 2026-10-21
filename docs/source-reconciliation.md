# FieldService source / consumer reconciliation

> Reviewed on 2026-10-21. Pre-stable; not an integration-test approval.

## Snapshots

- Standalone source `main`: `e5485594dddc` (foundation + development guide).
- Shipment branch `feature/shipment-integration`: originally `6f5a52669763`,
  with `main` merged into the branch as `973fcccaf36a`.
- Dolibarr `production` consumer subtree, imported on 2026-10-01:
  `htdocs/custom/fieldservice` from split `4c5c59aec3c`.

Nine code/translation files differ; the SQL schema and architecture document
are content-identical across the consumer and the shipment branch.

## Reconciliation decisions

| File(s) | Decision |
| --- | --- |
| `billing.php` | Keep feature's optional core `billed` detection and queue filter |
| `class/actions_fieldservice.class.php` | Restore the consumer's billing column on Intervention lists **only** when core does not have an independent billed field; keep the feature's billed-state handling |
| `class/fieldservicematerial.class.php` | Preserve feature's material-tab count method |
| `class/fieldserviceshipmentservice.class.php` | Retain feature's explicit order-line provenance, strict shipment integrity, and protective operations; do not restore consumer's implicit single-order default |
| `core/modules/modFieldService.class.php` | Keep feature's counted material tab and restore legacy Intervention-list hook registration |
| `core/triggers/interface_99_modFieldService_FieldServiceTriggers.class.php` | Keep feature's protection of mapped Shipment lines and finalized shipments |
| `material.php` | Preserve order-source selection and warnings; restore visible billing state on the material tab |
| `langs/en_US/fieldservice.lang` and `langs/hu_HU/fieldservice.lang` | Retain complete feature translation keys and consistent Posted/Könyvelt terminology |

The `vanyolai/dolibarr:production` Fichinter still uses `STATUS_BILLED`
as a core status; it lacks a dedicated `fields['facture']` and `billed`
combination. Consequently, removing the FieldService column unconditionally
would hide invoice classification in the core Intervention list. The hook is
now conditional and supports both core shapes.

## Required tests before PR #4 can be merged

1. PHP lint and permissions; HU/EN key coverage.
2. Intervention list/card/material tab with legacy core and a core exposing
   an independent billed flag. Ensure header/data/footer alignment.
3. Draft material create/update/delete, per-line warehouse and linked draft
   Shipment synchronization.
4. Regular, LOT and serial-managed products; scanner entry and stock settings.
5. Zero, one and multiple linked customer orders; explicit source lines, extra
   materials, availability and over-shipment rejection.
6. Protect mapped Shipment rows and block finalized Shipment deletion.
7. Intervention close: successful, failed, partial and idempotent retries;
   stock updates exactly according to Dolibarr's core configuration.
8. Billing queue, legacy billed status, partially invoiced and multi-invoice
   cases; insufficient-stock and concurrent edits.

The standalone source remains authoritative. Neither `main` nor
`vanyolai/dolibarr:production` is changed during this reconciliation.
After testing and merging PR #4 to `main`, compare the accepted source to the
consumer again and then perform a controlled subtree update.
