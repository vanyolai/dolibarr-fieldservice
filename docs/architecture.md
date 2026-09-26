# Field Service architecture

## Purpose

Field Service extends Dolibarr 23+ with an operational workflow around the core Intervention object. The core object remains responsible for the work sheet and work/time descriptions. This module adds structured material usage and stock traceability without replacing Dolibarr products, warehouses, stock movements, lots or serial numbers.

## Invariants

1. No Dolibarr core file, modified core copy or core patch belongs in this repository.
2. Core legacy names (`Fichinter`, `fk_fichinter`, `ficheinter`, `FICHINTER_*`) are used only at core integration boundaries.
3. Products, warehouses, stock quantities, lots, serial numbers and stock movements remain authoritative in Dolibarr core tables/classes.
4. Field Service never updates core stock tables directly. Stock changes must go through the core stock movement API.
5. A service vehicle is a normal Dolibarr warehouse. Field Service adds no parallel warehouse concept.
6. Existing Dolibarr translation keys are reused whenever they express the required concept. New module keys use the `FieldService` prefix.
7. Optional enhancements in a Dolibarr fork must not become mandatory dependencies of this module.

## Core mapping

| Field Service concept | Dolibarr 23 core |
| --- | --- |
| Work sheet / work order | `Fichinter` / Intervention |
| Work/time lines | `FichinterLigne` |
| Product | `Product` |
| Warehouse / service vehicle | `Entrepot` |
| Stock change | `MouvementStock` |
| LOT / serial tracking | Product Batch module and core batch/lot data |
| Customer order source | `Commande` / `commandedet` |
| Project | `Project` |

## Material model

`llx_fieldservice_material` is the logical material-usage line. One row represents one product taken from one source warehouse for one Intervention.

Important fields:

- `fk_fichinter`: core work sheet;
- `fk_product`: core product;
- `fk_entrepot`: core source warehouse;
- `qty`: total material quantity used;
- `fk_unit`: optional core unit;
- `date_use`: physical usage date/time;
- `status`: posting state;
- `origin_type` + `fk_origin_line`: optional trace to a source commercial line;
- standard author/modification audit fields.

A material quantity that comes from two warehouses is represented by two material lines. This keeps warehouse ownership unambiguous.

## Allocation model

`llx_fieldservice_material_alloc` represents the physical stock allocation behind a material line.

The same table is used for every stock-managed product:

- normal product: one allocation with an empty `batch`;
- lot-managed product: one or more allocations with lot identifier and quantities;
- serial-managed product: normally one allocation per serial number with quantity 1.

Each posted allocation stores the core stock movement row id. If usage is reversed later, the reverse stock movement id is stored separately.

The module intentionally does not use `llx_product_batch.rowid` as permanent historical identity. Core batch stock rows may disappear when their quantity reaches zero. Historical traceability therefore uses product + warehouse + batch/serial string + Field Service allocation + core stock movement id.

`eatby` and `sellby` are snapshots for traceability and use the same datetime granularity as Dolibarr 23 core batch data. They are not authoritative lot master data.

## Material status machine

### Draft (`0`)

Editable. No stock movement has been posted yet.

### Posted (`1`)

The physical material usage has been posted through Dolibarr core stock movement APIs. Posted quantities and allocations are immutable from the normal UI.

### Reversed (`2`)

The original material usage remains in history, but compensating core stock movements have returned the quantities. Reversal is explicit; reopening an Intervention must never silently restore stock.

## Planned posting workflow

1. Technician selects a source warehouse (often their service vehicle).
2. Technician adds a product and quantity.
3. If the product is lot/serial managed, Field Service requires allocations from currently available core stock.
4. The line remains Draft until the user explicitly posts material usage.
5. Posting validates the complete allocation and calls Dolibarr `MouvementStock` methods inside a transaction.
6. Each resulting stock movement id is recorded on its allocation.
7. Only after every allocation succeeds does the material line become Posted.
8. Correction of a Posted line is performed by an explicit reversal followed by a new Draft line if necessary.

Posting must be transactional and idempotent: retrying a completed request must not create duplicate stock movements.

## Intervention lifecycle

Validation or closure of the core Intervention is an administrative document event, not the physical stock event. Material stock is therefore posted explicitly from Field Service.

A future `FICHINTER_CLOSE` trigger may block closure while Draft material lines exist. Reopening a closed Intervention will not automatically reverse material usage.

## Future integration

The material model is designed to expose a provider/service layer to other modules rather than requiring them to query Field Service tables directly. Expected consumers include:

- work-sheet/PDF generation;
- completion certificate (TIG) module;
- invoicing preparation;
- reporting and material-cost analysis.

Invoice linkage must support partial/multiple invoices, so a single `fk_facturedet` field is intentionally not part of the initial material table.
