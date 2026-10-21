# Field Service architecture

## Purpose

Field Service extends Dolibarr 23+ with an operational workflow around the core Intervention object. The core object remains responsible for the work sheet and work/time descriptions. This module adds structured material usage and stock traceability without replacing Dolibarr products, warehouses, stock movements, lots or serial numbers.

## Invariants

1. No Dolibarr core file, modified core copy or core patch belongs in this repository.
2. Core legacy names (`Fichinter`, `fk_fichinter`, `ficheinter`, `FICHINTER_*`) are used only at core integration boundaries.
3. Products, warehouses, stock quantities, lots, serial numbers and stock movements remain authoritative in Dolibarr core tables/classes.
4. Field Service never updates core stock tables directly. Physical stock changes are owned by Dolibarr core Shipment (`Expedition`) lifecycle and its stock movement logic.
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
| Material issue / delivery document | `Expedition` / shipment |
| Stock change | Shipment lifecycle → core `MouvementStock` |
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

`llx_fieldservice_material_alloc` represents the physical lot/serial selection behind a Field Service material line while the related Shipment is still editable.

The same allocation model supports:

- regular stock product: no user-visible allocation is required;
- lot-managed product: one or more selected lots with quantities;
- serial-managed product: normally one allocation per serial number with quantity 1.

Field Service stores the lot/serial text and date snapshots because Dolibarr stock batch row ids are not durable historical identities. When the material line is synchronized to a draft Shipment, the module resolves the current core `product_batch` record and creates the corresponding core Shipment batch detail.

Draft allocation is not an independent stock reservation. Current stock must be checked again before the Shipment is finalized.

## Shipment integration

A work order may have one or more related Dolibarr Shipments. The relationship is kept in `llx_fieldservice_workorder_shipment`; Field Service does not add a second `element_element` origin to an order-based Shipment because core Shipment expects a single commercial origin.

Each Field Service material row maps to one Shipment line through `llx_fieldservice_material_shipment`.

While work is in progress:

1. Field Service material usage is editable.
2. The related Shipment remains Draft.
3. The source warehouse is always the warehouse selected on the material row. A service vehicle is only a possible warehouse/default, never a hard-wired source.
4. LOT/SN allocation is synchronized to the Shipment's batch detail.
5. No Field Service code directly decrements stock.

When a work order is completed, Field Service must finalize every related draft Shipment before allowing the Intervention to become Done. The normal transition is Shipment validation followed by Shipment closing. Which transition physically changes stock remains controlled by Dolibarr's stock configuration. With `STOCK_CALCULATE_ON_SHIPMENT_CLOSE`, closing the Shipment performs the stock movement.

If Shipment finalization fails, Intervention completion must fail as well. A completed work order must therefore never silently retain a draft Shipment containing its consumed materials.

### Order-aware shipments

If the Intervention is related to a customer order, Field Service may create the Shipment with that order as its core origin. Material rows that actually fulfill an order line keep their `commandedet` origin and become ordinary order shipment lines. Additional site materials may coexist on the same Shipment as free shipment lines without being falsely attached to an order line.

The UI should expose ordered, already shipped and remaining quantities using Dolibarr's core order/shipment data. If more than one order is linked to a work order, the user must select the intended order instead of Field Service guessing.

For general service/troubleshooting with no relevant order, the Shipment is standalone.

## Work completion and billing are independent

The core Intervention status remains the operational status:

- Draft / Validated: work is still active;
- Done: physical work is completed.

Field Service deliberately does not use core `Fichinter::STATUS_BILLED` as the work-completion state. Dolibarr 23 itself marks the legacy `FICHINTER_CLASSIFY_BILLED` workflow as deprecated in favor of a dedicated billing field.

`llx_fieldservice_workorder.billing_status` therefore tracks billing independently:

- `0` — work in progress / not ready for billing;
- `1` — completed and waiting for invoicing;
- `2` — partially invoiced;
- `3` — fully invoiced;
- `4` — no invoicing required.

On successful work completion, Field Service will set the billing state to Waiting for invoicing unless the work order has explicitly been marked non-billable. Later invoice integration must derive Partial/Invoiced from actual source-to-invoice-line mappings, not merely from the existence of an invoice linked to the customer or order.

This separation allows operationally completed work orders to be closed immediately while still providing a reliable invoicing queue.

## Future integration

The material model is designed to expose a provider/service layer to other modules rather than requiring them to query Field Service tables directly. Expected consumers include:

- work-sheet/PDF generation;
- completion certificate (TIG) module;
- invoicing preparation;
- reporting and material-cost analysis.

Invoice linkage must support partial/multiple invoices, so a single `fk_facturedet` field is intentionally not part of the initial material table.
