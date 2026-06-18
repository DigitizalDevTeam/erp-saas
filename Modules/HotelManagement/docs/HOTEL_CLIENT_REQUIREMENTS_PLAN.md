# Hotel Module — Client Requirements Plan (Round 2)

**Project:** ERP SaaS — Hotel Management Module
**Document version:** 1.0
**Created:** 2026-06-18
**Reference site:** [https://erpsaas.uthara.co.mz/login/pt](https://erpsaas.uthara.co.mz/login/pt)
**Predecessor:** `[HOTEL_MODULE_COMPLETION_PLAN.md](./HOTEL_MODULE_COMPLETION_PLAN.md)` (Phases 0–8, completed)

---

## 0. How to read this plan

The module is already a mature PMS (24 models, 24 controllers, 32 migrations, working
reservation → check-in → folio → checkout → invoice → night-audit flow). The new client
list is **mostly extensions and one genuinely new subsystem (warehouse-linked consumables)**,
not a rebuild.

Each requirement below is scored:

- **EXISTS** — already built, only needs verification/polish.
- **EXTEND** — partial implementation, needs additions.
- **NEW** — net-new build.

> ⚠️ **Two requirements are blocked on the client** sending sample formats
> (Financial Reports layouts + official Immigration format). We build the **data + engine**
> now and keep the **layout flexible** so final formatting is a fast swap when samples arrive.

---

## 1. Current flow (verified)

```
Reservation ──▶ Check-in (HotelStay) ──▶ Folio opens
   │                                         │
   │                          ┌──────────────┼───────────────┐
   │                          ▼              ▼               ▼
   │                    room_charge    minibar/laundry    pos_charge / addon
   │                          │              │               │
   │                          └────────▶ HotelFolioLine ◀────┘
   │                                         │
   └──────────────────────────────▶ Payments ▶ Checkout ▶ Journal + ERP Invoice
                                             │
                                       Night Audit (close day)
```

**Reports today** (single `HotelReportController`): `operational`, `financial`, `immigration`,
`occupancy`, `customers`, `alerts`. Operational/financial/immigration have PDF + Excel export.

**Minibar today:** standalone `hotel_minibar_items` catalog → consumption posts a folio line +
writes `hotel_room_consumption_logs`. **No link to ERP warehouse stock.** This is the big gap.

**Booking website today:** `HotelBookingSite` (slug, branding, gallery, amenities, SEO, payment)

- per-room-type cover/gallery images. Public site at `/{slug}`. **No homepage slider, no
structured CMS for categories/amenities beyond JSON blobs.**

**i18n today:** `pt.json` + `hotel-translations.json` (en/pt) + merge scripts. Substantial PT
coverage exists but client reports mixed EN/PT on live site → needs audit + completion.

Key files:

- Reports: `Modules/HotelManagement/Http/Controllers/HotelReportController.php`
- Report views: `Modules/HotelManagement/Resources/views/reports/`
- Routes: `Modules/HotelManagement/Routes/web.php`
- Menu: `Modules/HotelManagement/Resources/views/menu/hotelmanagement.blade.php`
- Minibar service: `Modules/HotelManagement/Services/HotelRoomConsumptionService.php`
- Guest model: `Modules/HotelManagement/Entities/HotelGuest.php`
- Booking site: `Modules/HotelManagement/Entities/HotelBookingSite.php`
- Warehouse (core app): `app/Http/Controllers/WarehouseController.php`, `app/Models/warehouse.php`, `app/Models/WarehouseProduct.php`, `app/Models/ProductService.php`

---

## 2. Requirement → Status → Work matrix


| #   | Requirement                                                   | Status                               | Effort |
| --- | ------------------------------------------------------------- | ------------------------------------ | ------ |
| A   | Financial reports (6 reports)                                 | EXTEND (1 combined exists)           | M      |
| B   | Immigration report (+ visa, country, arrival/departure)       | EXTEND (exists, missing fields)      | S      |
| C   | Hotel operation reports (6 reports)                           | EXTEND (operational/occupancy exist) | M      |
| D   | Minibar & room consumable **warehouse stock control**         | NEW (biggest item)                   | L      |
| E   | Hotel website CMS (categories/types/photos/amenities/pricing) | EXTEND                               | M      |
| F   | Website homepage slider (3 slides)                            | NEW (small)                          | S      |
| G   | Portuguese language completion + de-mixing                    | EXTEND (audit + fill)                | M      |


S = ~0.5–1d, M = ~2–4d, L = ~5–8d.

---

## A. FINANCIAL REPORTS  *(blocked on client sample layouts — build engine now)*

**Client wants 6 distinct reports:** Daily Revenue, Monthly Revenue, Payment Collection,
Outstanding Balance, Cashier Closing, Occupancy Revenue.

**Today:** one `financial()` action (revenue by day + payment method + discounts + paid/pending).

### Plan

1. Add a `reports/financial/` sub-hub view linking the 6 reports.
2. Add controller actions + routes + views + PDF/Excel for each:


| Report              | Data source                                                   | Key columns                                                      |
| ------------------- | ------------------------------------------------------------- | ---------------------------------------------------------------- |
| Daily Revenue       | `hotel_folio_lines` grouped by `revenue_channel` + day        | Date, Room, F&B/POS, Minibar, Addons, Tax, Total                 |
| Monthly Revenue     | same, grouped by month                                        | Month, channels, MoM trend                                       |
| Payment Collection  | `hotel_folio_payments` by method + day                        | Date, Cash, M-Pesa/e-Mola/Mkesh, Card, Bank, Total               |
| Outstanding Balance | `hotel_folios` where `paid_total < grand_total`               | Guest, Folio, Room, Grand, Paid, Balance, Age (days)             |
| Cashier Closing     | `hotel_folio_payments` by `created_by` (cashier) + shift/date | Cashier, opening, collected by method, count, total              |
| Occupancy Revenue   | reservations + folios                                         | Date, Rooms sold, Occupancy %, Room revenue, **ADR**, **RevPAR** |


1. Reuse existing helpers: `dateRange()`, `downloadPdf()`, `SimpleSheetExport`.
2. **Cashier Closing** ties to night-audit/shift roster — reuse `hotel_night_audits` +
  `closed_by`; group payments by user. (Mozambique methods: Cash, M-Pesa, e-Mola, Mkesh, Card, Bank.)

### Files

- Edit `HotelReportController.php` (+6 actions, +6 pdf, +6 export — or a generic export switch).
- New `Resources/views/reports/financial/{daily,monthly,collection,outstanding,cashier,occupancy_revenue}.blade.php` (+ `_pdf` variants).
- Edit `Routes/web.php` (report routes block) + `reports/index.blade.php` hub.

> **Layout caveat:** keep tables generic + column config in one place so client's sample
> formats can be applied without re-querying.

---

## B. IMMIGRATION REPORT  *(blocked on official format — build fields + engine now)*

**Required fields:** Nationality, Passport Number, Visa Number, Country, Arrival Date,
Departure Date.

**Today** (`immigrationRows()` + `immigration_pdf`): reservation_no, full_name, nationality,
id_type, id_number, birth_date, check_in/out, room, role, status.

### Gap

`hotel_guests` has `nationality`, `id_type`, `id_number`, `birth_date`. **Missing:** dedicated
`passport_number`, `visa_number`, `country_of_residence` (distinct from nationality).
Arrival/Departure = use **actual** `hotel_stays.check_in_at` / `checked_out_at`, falling back to
reservation dates (immigration authorities want actual movement dates).

### Plan

1. Migration: add `passport_number`, `visa_number`, `country_of_residence`, `visa_type`,
  `visa_expiry` (nullable) to `hotel_guests`.
2. Add to `HotelGuest::$fillable` + guest create/edit forms (`guests/edit`, reservation guest panel).
3. Update `guestImmigrationFields()` + `immigrationRows()` to emit the new columns and use real
  arrival/departure from `HotelStay`.
4. Update immigration view, `immigration_pdf`, and Excel export header/rows.
5. Add a country dropdown (reuse app country list if present, else ISO list).

### Files

- New migration `..._extend_hotel_guests_immigration_fields.php`.
- Edit `Entities/HotelGuest.php`, `Http/Controllers/HotelReportController.php`,
`Resources/views/guests/edit.blade.php`, reservation guest form,
`reports/immigration*.blade.php`.

---

## C. HOTEL OPERATION REPORTS

**Client wants:** Reservation, Check-in, Check-out, Occupancy, Room Status, Housekeeping.

**Today:** combined `operational()` (occupancy %, check-in/out counts, room status, avg stay)

- `occupancy()`. Need to split into named, listing-style reports (each row = a record, not just
counts), each with PDF/Excel.

### Plan


| Report       | Source                                              | Rows                                                    |
| ------------ | --------------------------------------------------- | ------------------------------------------------------- |
| Reservation  | `hotel_reservations` (filter by date/status/source) | res_no, guest, room type, dates, source, status, amount |
| Check-in     | `hotel_stays` where `check_in_at` in range          | guest, room, check-in time, by-user, nights             |
| Check-out    | `hotel_stays` where `checked_out_at` in range       | guest, room, checkout time, folio total, balance        |
| Occupancy    | (keep existing heatmap) + per-room-type breakdown   | date, rooms sold, available, occupancy %, ADR           |
| Room Status  | `hotel_rooms` snapshot                              | room, type, floor, status, last cleaned, current guest  |
| Housekeeping | `hotel_housekeeping_tasks` + minibar restocks       | room, task, assigned_to, status, completed_at           |


### Files

- Edit `HotelReportController.php` (+ actions) **or** create `HotelOperationReportController.php`
to keep the file manageable (recommended given file is already 570 lines).
- New `Resources/views/reports/operations/*.blade.php`.
- Edit `Routes/web.php` + `reports/index.blade.php` hub (group as "Operations").

---

## D. MINIBAR & ROOM CONSUMABLE STOCK CONTROL  *(largest, net-new subsystem)*

**Client flow:** `Main Warehouse → Room Stock → Guest Consumption → Stock Deduction → Folio`.
Items: water, soft drinks, snacks, soap, shampoo, tissue, towels, other consumables.

**Today:** minibar catalog is **price-only**, no quantities, no warehouse link. ERP warehouse
(`warehouses`, `warehouse_products`, `product_services`) exists separately.

### Design decision

Bridge the hotel module to the existing ERP **product + warehouse** system rather than building
a parallel inventory. Each consumable = an ERP `ProductService`; each room (or floor cart) holds
a small "room stock" tracked in a new hotel table; consumption deducts room stock and posts to
folio; transfers move quantity warehouse → room.

### New schema

- `hotel_consumables` — `product_id` (FK `product_services`), `category`
(water/drink/snack/toiletry/linen/other), `unit_price`, `is_billable`, `par_level`, `is_active`.
*(Towels/soap may be non-billable amenities; water/snacks billable.)*
- `hotel_room_stocks` — `hotel_room_id`, `hotel_consumable_id`, `quantity`, `par_level`. Unique pair.
- `hotel_stock_movements` — `type` (transfer_in/consumption/restock/adjustment/return),
`hotel_consumable_id`, `hotel_room_id?`, `warehouse_id?`, `qty`, `unit_price`, `ref_type/ref_id`
(folio line, stay), `created_by`, `created_at`. → full movement history.

### Flow wiring

1. **Warehouse → Room transfer:** new screen; decrement `warehouse_products.quantity`
  (reuse `WarehouseProduct` logic), increment `hotel_room_stocks.quantity`, log `transfer_in`.
2. **Consumption:** extend `HotelRoomConsumptionService::postConsumption()` — when charge maps to
  a consumable, decrement `hotel_room_stocks`, log `consumption`, then post folio line (existing).
   Block / warn on negative stock (configurable).
3. **Stock deduction** happens at consumption time (above).
4. **Billing/folio entry:** already handled by existing `HotelPosChargeService::addMinibarChargeToFolio`.
5. **Restock:** extend existing `HotelMinibarRestockService` to pull from warehouse to par level,
  logging `restock` movements.

### Reports (consumption)

- Consumption report (by room / item / date / guest).
- Stock movement history (ledger view).
- Low-stock / below-par alert (rooms under `par_level`).

### Migration note

Keep `hotel_minibar_items` working; add a `consumable_id` link so existing minibar data migrates
into the new consumables model without breaking current screens.

### Files

- New migrations: `..._create_hotel_consumables_and_room_stocks.php`, `..._create_hotel_stock_movements.php`, `..._link_minibar_items_to_consumables.php`.
- New entities: `HotelConsumable`, `HotelRoomStock`, `HotelStockMovement`.
- New services: `HotelRoomStockService` (transfer/deduct/restock/adjust), extend `HotelRoomConsumptionService`, `HotelMinibarRestockService`.
- New controllers: `HotelConsumableController` (catalog), `HotelRoomStockController` (room inventory + transfer), consumption reports in report controller.
- New views: `consumables/*`, `room_stock/*` (transfer wizard, room inventory grid, movement ledger).
- Edit: menu, routes, permissions (`HotelPermissionCatalog.php`), accounting settings (COGS account optional).

---

## E. HOTEL WEBSITE MANAGEMENT (CMS)

**Client wants admin control of:** Room Categories, Room Types, Room Photos, Amenities,
Descriptions, Pricing Information.

**Today:** `HotelBookingSite` (display name, tagline, description, gallery, amenities JSON, SEO,
payment) editable at `hotel/booking-site`. Room **types** have `cover_image_path`,
`gallery_images`, `description`, prices — already CMS-able via room-type CRUD. Public site
(`public/home`, `search`, `book`) renders these.

### Gap

- "Room Categories" as a public-facing grouping (distinct from internal room types) — decide:
reuse `unit_category` on room type, or add a light `hotel_website_categories`. **Recommend reuse
room types as the website catalog** (simplest, already imaged) + a "show on website" toggle.
- Amenities: currently a free JSON blob on booking site — upgrade to a managed amenity list
(icon + label) reusable per room type.
- Per-room-type "publish to website", website description (separate from internal notes),
display order.

### Plan

1. Add to `hotel_room_types`: `website_published` (bool), `website_description` (text),
  `website_sort_order`, `website_amenities` (json). Migration + fillable + form fields.
2. Booking-site editor: section to toggle/sort which room types appear, manage amenity master list.
3. Ensure public `home`/`search` pull only `website_published` types with photos + pricing.
4. Photo management already exists on room type CRUD — verify multi-upload + delete works on
  `room_types/edit`.

### Files

- Migration `..._extend_room_types_for_website_cms.php`.
- Edit `Entities/HotelRoomType.php`, `HotelRoomTypeController.php`, `room_types/{create,edit}.blade.php`.
- Edit `HotelBookingSiteController.php`, `booking_site/edit.blade.php`.
- Edit public views `public/home.blade.php`, `public/search.blade.php`, `partials/room-card.blade.php`.

---

## F. WEBSITE HOMEPAGE SLIDER (3 slides)

**Client wants:** up to 3 slides — image, title, description, button URL — managed from backend.

**Today:** booking site has a single `cover_image_path` + gallery; `public/partials/room-slider`
exists for rooms but no editable hero slider.

### Plan

1. Add `homepage_slides` (json) to `hotel_booking_sites` — array of up to 3
  `{image_path, title, description, button_label, button_url}`.
2. Booking-site editor: repeater UI (max 3) with image upload to `public` disk + text fields.
3. Render hero slider on `public/home.blade.php` (lightweight CSS/JS carousel; reuse existing
  slider partial styling).

### Files

- Migration `..._add_homepage_slides_to_booking_sites.php`.
- Edit `Entities/HotelBookingSite.php` (fillable + cast + `slideUrls()` helper),
`HotelBookingSiteController.php` (validate + store uploads),
`booking_site/edit.blade.php`, `public/home.blade.php` (+ new `partials/hero-slider.blade.php`).

---

## G. PORTUGUESE LANGUAGE SUPPORT (complete + de-mix)

**Client:** some screens translated, some labels English, mixed PT+EN. Review whole module +
website.

**Today:** translation pipeline exists (`scripts/extract-hotel-strings.php`,
`hotel-i18n-dict.php`, `build-hotel-translations.php`, `merge-hotel-translations.php`,
`list-missing-hotel-translations.php`) → `resources/lang/pt.json` + `hotel-translations.json`.

### Plan

1. **Audit:** run `php scripts/extract-hotel-strings.php` then
  `php scripts/list-missing-hotel-translations.php` to get every untranslated/missing key
   (admin + public + new screens from A–F).
2. **Hardcoded strings:** grep module Blade/PHP for literals not wrapped in `__()` — especially
  public site views and any new screens. Wrap them.
3. **Fill PT:** add missing keys to `hotel-i18n-dict.php`, rebuild + merge into `pt.json`.
  Use European Portuguese (Mozambique), consistent terminology (e.g. *Quarto, Hóspede, Reserva,
   Check-in, Folha/Conta, Frigobar, Diária*).
4. **De-mix:** fix any keys whose PT value is still English in `pt.json` / `hotel-translations.json`.
5. **Verify on `/login/pt`** and each hotel admin screen + public `/{slug}` in PT locale.
6. Translate all **new** A–F strings as part of each phase (don't defer).

### Files

- `scripts/hotel-i18n-dict.php`, `resources/lang/pt.json`, `resources/lang/hotel-translations.json`.
- Any module views with hardcoded literals.

---

## 3. Suggested sequencing


| Phase  | Scope                                          | Why first                                              |
| ------ | ---------------------------------------------- | ------------------------------------------------------ |
| **R1** | B (immigration fields) + C (operation reports) | Pure data/reporting, low risk, fast client-visible win |
| **R2** | A (financial reports engine)                   | High value; layout finalized when sample arrives       |
| **R3** | D (consumable warehouse stock)                 | Largest; isolate to avoid destabilizing billing        |
| **R4** | E + F (website CMS + slider)                   | Public-facing bundle                                   |
| **R5** | G (PT completion)                              | Runs continuously, final sweep covers R1–R4 strings    |


**Parallelizable:** G runs alongside every phase; A/B/C share report scaffolding.

---

## 4. Cross-cutting conventions (must follow existing patterns)

- **Multi-tenancy:** every query scoped by `Auth::user()->creatorId()` (see existing reports).
- **Permissions:** add gates in `app/Services/HotelPermissionCatalog.php` +
`HotelManagementServiceProvider`; reflect in `role/partials/hotel_permissions.blade.php`.
- **PDF:** reuse `HotelReportController::downloadPdf()` (DomPDF → Browsershot fallback).
- **Excel:** reuse `Exports/SimpleSheetExport`.
- **Menu:** add entries in `menu/hotelmanagement.blade.php` behind `@can`.
- **i18n:** wrap every new string in `__()`; never hardcode.
- **Migrations:** guard with `Schema::hasTable/hasColumn` (matches existing style).
- **Tests:** add feature tests under `tests/Feature/HotelManagement/` for stock deduction + report
totals (mirror `HotelBillingFlowTest`).

---

## 5. Open questions for client (to unblock)

1. **Financial report layouts** — awaiting samples (A). Build engine meanwhile.
2. **Official immigration form** — awaiting format (B). Add fields meanwhile.
3. **Consumables billing** — which items are billable (water/snacks) vs free amenities
  (soap/towels)? Default: water/drinks/snacks billable; toiletries/linen non-billable, toggleable.
4. **Room stock granularity** — per-room minibar stock, or per-floor housekeeping cart? Plan
  supports per-room; floor-cart is a config layer on top.
5. **Website categories** — reuse room types as website catalog (recommended) or separate
  category taxonomy?
6. **Payment methods for collection/cashier reports** — confirm full list (Cash, M-Pesa, e-Mola,
  Mkesh, Card, Bank transfer)?

---

*Maintained in `Modules/HotelManagement/docs/`. Update version/date as phases complete.*