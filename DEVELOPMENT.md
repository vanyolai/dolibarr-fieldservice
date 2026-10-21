# Development Guide — FieldService

> Last reviewed: 2026-10-19
>
> FieldService is still pre-stable. Repository state and active PRs must be inspected before every subtree update.

## Repository role

```text
canonical source: vanyolai/dolibarr-fieldservice
intended source:  main
consumer prefix:  htdocs/custom/fieldservice
subtree mode:     squashed
```

The standalone repository must remain authoritative. If an integrated Dolibarr tree contains a newer accepted snapshot than `main`, resolve that drift in this repository before the next normal subtree pull.

## Core architecture

FieldService extends the native Dolibarr **Intervention / Fichinter** object rather than replacing it.

Core concepts stay authoritative:
- Intervention/work sheet: `Fichinter`;
- products: `Product`;
- warehouses/service vehicles: `Entrepot`;
- stock movements: native stock APIs;
- LOT/SN: native batch/serial facilities;
- customer-order origin: `Commande` / `commandedet`.

No modified Dolibarr core files belong in this repository.

A service vehicle is a normal Dolibarr warehouse, not a parallel inventory model.

## Current implementation state

Standalone `main` currently contains:
- module skeleton and material data model;
- material usage CRUD;
- scanner-friendly product entry;
- LOT and serial allocation;
- atomic creation of batch-managed material lines.

Recent validated main commits:
- `8a4821bb46d0` — module/data-model scaffold;
- `493e65d75b18` — material usage CRUD;
- `d917d728ab58` — LOT/SN allocation and scanner-first flow.

Draft PR #4, **Shipment integration and billing queue**, is the next major block. It adds:
- synchronization of material usage to draft native Shipments;
- per-material warehouse preservation;
- LOT/SN detail in Shipment batch data;
- no direct stock-table writes;
- close/finalization through core Expedition lifecycle;
- order-aware origin when exactly one linked order is unambiguous;
- free extra materials alongside order lines;
- independent operational completion and billing status;
- dedicated Billing Queue.

## Invariants

- FieldService never writes Dolibarr stock tables directly.
- Native shipment/stock configuration remains authoritative.
- Material rows and allocations are domain data; stock effects are delegated to native lifecycle/API.
- LOT/SN identity must remain traceable.
- Work completion and invoice classification are separate concerns.
- Do not conflate FieldService billing state with unrelated core Fichinter billed-state backports.
- Other modules should consume FieldService through a provider/service layer, not direct SQL against FieldService tables.

## Roadmap

### Milestone 1 — accept shipment integration
- complete end-to-end tests for PR #4;
- verify add/edit/delete synchronization with draft Shipment;
- verify per-line warehouse and LOT/SN;
- verify work-order closure finalizes shipments correctly;
- verify Dolibarr's configured shipment stock timing remains authoritative;
- resolve source/consumer drift and merge the accepted tree to standalone `main`.

### Milestone 2 — billing lifecycle
- validate Waiting for invoicing / Partially invoiced / Invoiced / No invoicing required;
- verify Billing Queue filters and permissions;
- design partial/multiple invoice linkage without a single `fk_facturedet` shortcut.

### Milestone 3 — integration API
- expose stable provider/service methods for:
  - work-sheet/PDF generation;
  - CompletionCertificate/TIG;
  - invoicing preparation;
  - material-cost/reporting consumers.

### Milestone 4 — stock/reversal hardening
- verify retry/idempotency;
- define explicit reversal behavior;
- preserve original movement IDs and LOT/SN history;
- test concurrency and insufficient-stock failures.

### Milestone 5 — release hardening
- update architecture docs to match the accepted shipment model;
- add DB-backed/integrated regression coverage;
- verify HU/EN language keys and permissions;
- only then treat `main` as stable subtree source without special caveats.

## Subtree update

Do not blindly pull until the source branch is known to contain the accepted integrated tree.

Intended long-term command:

```bash
git subtree pull \
  --prefix=htdocs/custom/fieldservice \
  https://github.com/vanyolai/dolibarr-fieldservice.git \
  main \
  --squash \
  -m "Update FieldService subtree"
```
