# Dolibarr Field Service

Field Service is an external Dolibarr module for field work, service and installation workflows.

Initial scope for Dolibarr 23+:

- use the core Intervention object as the work-sheet/work-order container;
- record materials used during the work;
- select the source warehouse, including service vehicles modeled as normal Dolibarr warehouses;
- use Dolibarr's native stock, lot and serial-number facilities;
- keep material usage traceable to stock movements and, where available, originating commercial lines;
- provide a clean integration point for completion certificates and invoicing later.

## Architecture rules

This repository is the module root. When installed as a Git subtree it belongs at:

```text
htdocs/custom/fieldservice/
```

The repository must not contain modified Dolibarr core files, copies of core files used as replacements, or core patches. Integration with Dolibarr must use supported extension mechanisms such as module descriptors, object tabs, hooks, triggers and public/core classes and APIs.

Core legacy names such as `Fichinter`, `fk_fichinter` and `ficheinter` are used only where required by the Dolibarr API or database model. New Field Service code uses English names.

Existing Dolibarr translation keys should be reused whenever they already express the required concept. Module-specific translation keys use the `FieldService` prefix.

## Repository / subtree workflow

The detailed development history lives in this repository. A Dolibarr 23 fork can consume the stable `main` branch as a squashed subtree:

```bash
git remote add fieldservice git@github.com:vanyolai/dolibarr-fieldservice.git
git fetch fieldservice
git subtree add --prefix=htdocs/custom/fieldservice fieldservice main --squash
```

Updates can then be pulled with:

```bash
git fetch fieldservice
git subtree pull --prefix=htdocs/custom/fieldservice fieldservice main --squash
```

## Status

Early development. The first milestone defines the module skeleton and material-usage data model. Stock posting and LOT/SN allocation logic will be added in subsequent commits.
