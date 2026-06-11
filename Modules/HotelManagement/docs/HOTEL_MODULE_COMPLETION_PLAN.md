# Hotel Module — Completion Plan

**Project:** ERP SaaS — Hotel Management Module  
**Document version:** 1.0  
**Created:** 2026-06-11  
**Scope:** Internal PMS completion (OTA, website, online payments deferred to future)

---

## Executive Summary

The Hotel Management module is a substantial internal PMS (~143 files, 18 models, 20 controllers, 27 migrations). Core inventory, reservations, check-in, folios, minibar, POS charge-to-room, accounting journals, and reports **do work**.

What makes it feel incomplete is a **split between working backend and cosmetic/static UI**, plus **missing industry features** (extra bed/addons catalog, unified checkout payment, ERP invoice, guest CRUD) and **almost zero Portuguese translations** for hotel-specific strings.

**Recommended priority:** Phase 0 (i18n) + Phase 1 (checkout billing) + Phase 2 (addons/extra bed) — highest client impact.

---

## Part 1 — Current State Analysis

### 1.1 Module Architecture

| Layer | Status |
|-------|--------|
| **Inventory** — floors, room types, rooms, rate plans, room map | ✅ Solid |
| **Reservations** — CRUD, calendar, drag-reschedule, rate quotes, store-and-check-in | ✅ Solid (no `show` page) |
| **Front desk** — in-house list, check-in, check-out | ✅ Core works |
| **Folio billing** — lines, payments, final bill print | ✅ Backend works |
| **Minibar** — catalog, daily minibar UI, consumption → folio | ✅ Works |
| **Housekeeping** — tasks, room status sync | ✅ Works |
| **POS integration** — charge to room from restaurant/POS | ✅ Works |
| **Accounting** — COA mapping, journal on payment & checkout | ✅ Works (when configured) |
| **Night audit** — run/close business day | ✅ Backend works |
| **Reports** — operational, financial, occupancy, customers | ✅ Works |
| **Groups** — bulk group booking | ⚠️ Create/show only |
| **OTA** | ❌ Settings UI only, sync is stub |
| **API** | ❌ Placeholder |
| **Portuguese i18n** | ❌ Hotel strings missing from `pt.json` |

### 1.2 Key File Locations

| Area | Path |
|------|------|
| Module root | `Modules/HotelManagement/` |
| Routes | `Modules/HotelManagement/Routes/web.php` |
| Controllers | `Modules/HotelManagement/Http/Controllers/` |
| Entities | `Modules/HotelManagement/Entities/` |
| Services | `Modules/HotelManagement/Services/` |
| Views | `Modules/HotelManagement/Resources/views/` |
| Migrations | `Modules/HotelManagement/Database/Migrations/` |
| Menu | `Modules/HotelManagement/Resources/views/menu/hotelmanagement.blade.php` |
| Permissions | `app/Services/HotelPermissionCatalog.php` |
| Portuguese lang | `resources/lang/pt.json` |

### 1.3 Data Model Flow

```
Reservation → Check-in → Stay + Folio → Services / Minibar / POS
    → Folio Lines → Payments → Check-out + Journal
```

**Core tables:** `hotel_rooms`, `hotel_room_types`, `hotel_floors`, `hotel_rate_plans`, `hotel_reservations`, `hotel_stays`, `hotel_folios`, `hotel_folio_lines`, `hotel_folio_payments`, `hotel_minibar_items`, `hotel_room_consumption_logs`, `hotel_housekeeping_tasks`, `hotel_night_audits`, `hotel_accounting_settings`, `hotel_guests`, `hotel_groups`, `hotel_group_members`, `hotel_ota_settings`.

### 1.4 Folio Line Types (Current)

| Type | Purpose |
|------|---------|
| `room_charge` | Lodging at check-in |
| `minibar_charge` | Minibar consumption |
| `laundry_charge` | Laundry |
| `pos_charge` | Restaurant/POS |
| `misc_charge` | Manual extras |

There is **no dedicated `extra_bed_charge`** or addons catalog — extras are ad-hoc `misc_charge` lines.

### 1.5 ERP Integration (Current)

| Integration | Status |
|-------------|--------|
| Accounting journals (`HotelFolioAccountingService`) | ✅ Implemented |
| POS charge-to-room | ✅ Implemented |
| Night audit / business day lock | ✅ Implemented |
| ERP `Invoice` from folio (`invoice_id`) | ✅ `HotelFolioInvoiceService` at checkout |
| CRM `Customer` link (`customer_id` on guests) | ❌ Column exists, not wired |
| Reservation confirmation email | ✅ Implemented |

---

## Part 2 — Gaps & Broken Areas

### 2.1 Critical UX Gaps

| Screen | File | Problem |
|--------|------|---------|
| **Checkout** | `Resources/views/frontdesk/checkout.blade.php` | Shows real folio data, but **payment form does not submit**. Hardcoded reference `MP250512114233`. Checkout POST only — payment done separately on folio page. |
| **Receipt** | `Resources/views/frontdesk/receipt.blade.php` | **Fake payment history** (demo dates). "Issue receipt/invoice" buttons have no handlers. |
| **Night audit previews** | `Resources/views/night_audit/partials/_summary_*.blade.php` | **Static mock numbers** (68.42% occupancy, MZN 23750 revenue). Run action is real; preview cards are fake. |
| **Quick actions** | Checkout screen | "Extend stay", "Change room", "Reprint document" — **buttons with no backend**. |
| **Dashboard** | `Resources/views/dashboard.blade.php` | "Issue invoice" links to reservations index, not invoice flow. |

**Controller note** (`HotelFrontdeskController.php`):

> Check-out screen (UI). Backend settlement is wired separately.

### 2.2 Client Requirements vs Current State

| Requirement | Current State |
|-------------|---------------|
| Room management | ✅ Done (Phase 7 workflow) |
| Extra bed / addons | ❌ No catalog, no reservation linkage, no dedicated charge type |
| Minibar tracking | ✅ Works (catalog + consumption logs → folio) |
| Checkout + billing flow | ⚠️ Split across 3 screens; checkout payment UI unwired |
| Portuguese language | ❌ `pt.json` has zero hotel-specific keys |
| check-in → stay → services → checkout → invoice | ⚠️ Flow exists but invoice step missing |
| Extras linked to billing | ⚠️ Minibar/POS yes; extra bed/addons no |
| Guest management | ⚠️ Guests only via reservations — no standalone CRUD |
| Reservation detail page | ❌ Route exists, no `show()` method |
| External booking / OTA / website / online pay | ❌ Stub only — **deferred** |

### 2.3 Missing Controller Methods

- `HotelReservationController` — no `show()`
- `HotelGroupController` — no `edit`, `update`, `destroy`
- No `HotelGuestController`

### 2.4 Scheduled Commands

| Command | Schedule | Behavior |
|---------|----------|----------|
| `hotelmanagement:ota-sync` | Every 15 min | **Stub** — logs only |
| `hotelmanagement:send-birthday-promotions` | Daily 08:00 | Logs + optional Twilio |
| `hotelmanagement:daily-owner-summary` | Daily 22:00 | Logs occupancy/revenue |
| `hotelmanagement:low-occupancy-alert` | Daily 09:30 | Logs low forecast |

---

## Part 3 — Industry Target (Internal PMS)

Minimum complete flow for production use:

```
Reservation → Assign Room → Check-in → Folio opens
    ↓
During stay: minibar, laundry, extra bed, POS, manual charges → all on folio
    ↓
Checkout screen: review charges → take payment (cash/card/etc.) → post journal
    ↓
Generate invoice/receipt → close stay → room → cleaning
    ↓
Night audit: roll day, lock POS, summary from real data
```

**Out of scope (this plan):** OTA sync, hotel website, online booking, Stripe/PayPal/local gateway.

---

## Part 4 — Implementation Phases

### Phase 0 — Foundation & i18n ✅ COMPLETED (2026-06-11)

**Duration:** 1–2 days  
**Goal:** Full hotel UI in English, Chinese, Spanish, French, Portuguese.

| # | Task | Status |
|---|------|--------|
| 0.1 | Export all hotel `__()` strings | ✅ `scripts/extract-hotel-strings.php` |
| 0.2 | Add 580+ keys to `en`, `zh`, `es`, `fr`, `pt` JSON files | ✅ merged |
| 0.3 | Fix Portuguese-as-key strings in views | ✅ `reservations/index.blade.php` |
| 0.4 | Remove hardcoded demo strings | ✅ checkout + receipt views |
| 0.5 | Role permission labels | ✅ 13 strings added |

**Artifacts:** `scripts/hotel-i18n-dict.php`, `scripts/build-hotel-translations.php`, `scripts/merge-hotel-translations.php`, `resources/lang/hotel-translations.json`

**Re-run after new hotel strings:**
```bash
php scripts/extract-hotel-strings.php
php scripts/list-missing-hotel-translations.php
# Add missing keys to scripts/hotel-i18n-dict.php
php scripts/build-hotel-translations.php
php scripts/merge-hotel-translations.php
```

**Deliverable:** Full i18n for hotel module in all 5 supported languages.

---

### Phase 1 — Unify Checkout & Billing ✅ COMPLETED (2026-06-11)

**Duration:** 3–5 days  
**Priority:** HIGHEST  
**Goal:** One coherent front-desk checkout experience.

| # | Task | Status |
|---|------|--------|
| 1.1 | `HotelFolioPaymentService` — shared payment recording + accounting journal | ✅ |
| 1.2 | Checkout form records payment + completes check-out in one POST | ✅ |
| 1.3 | Removed hardcoded demo data (guest contact, payment reference, receipt history) | ✅ |
| 1.4 | Receipt screen uses real folio totals + payment history + printable receipt | ✅ |
| 1.5 | Checkout observations saved to `hotel_stays.notes` | ✅ |
| 1.6 | Live balance validation + force-checkout checkbox | ✅ |

**New routes:**
- `POST hotel/frontdesk/receipt/{reservation}` → `hotel.frontdesk.receipt.store`
- `GET hotel/folios/{folio}/payments/{payment}/receipt` → `hotel.folios.payments.receipt`

**New files:**
- `Services/HotelFolioPaymentService.php`
- `Resources/views/folios/payment_receipt_print.blade.php`

**Acceptance criteria:**
- Front desk completes pay + checkout from checkout screen in one action.
- Receipt screen records payments and prints payment receipt.

---

### Phase 2 — Extras, Addons & Extra Bed ✅ COMPLETED (2026-06-11)

**Duration:** 3–4 days  
**Goal:** Structured extras, not random misc charges.

| # | Task | Status |
|---|------|--------|
| 2.1 | Migration + entities `HotelAddon`, `HotelReservationAddon` | ✅ |
| 2.2 | Addons catalog CRUD — menu: **Addons & extras** | ✅ |
| 2.3 | Reservation form: select addons at booking | ✅ |
| 2.4 | Auto-add folio lines at check-in (+ in-house reservation update) | ✅ |
| 2.5 | Extra bed per-night + occupancy validation | ✅ |
| 2.6 | Folio line type `addon_charge` + `hotel_addon_id` | ✅ |
| 2.7 | Revenue channel mapping (room/restaurant/misc) | ✅ |

**New files:** `HotelAddonService`, `HotelAddonController`, `addons/*` views, migration `2026_06_11_120000_create_hotel_addons_and_reservation_addons.php`

**Acceptance criteria:**
- Extra bed on reservation appears on folio and final bill at check-in.
- Addon catalog manageable from **Hotel → Addons & extras**.

---

### Phase 3 — Complete Guest & Reservation Lifecycle ✅ COMPLETED (2026-06-11)

**Duration:** 2–3 days  
**Goal:** No dead buttons; full reservation history.

| # | Task | Status |
|---|------|--------|
| 3.1 | `HotelGuestController` — index, show, edit; search, history, preferences, VIP | ✅ |
| 3.2 | `HotelReservationController::show()` — timeline: booking → check-in → folio → checkout | ✅ |
| 3.3 | **Extend stay** — update `check_out_date`, add pro-rated `room_charge`, recalc totals | ✅ |
| 3.4 | **Change room** — transfer stay; rate difference line; update room statuses | ✅ |
| 3.5 | Group reservations — edit/update members, cancel group | ✅ |
| 3.6 | Link guest → CRM — optional `customer_id` sync when guest has email/phone | ✅ |

**New routes:**
- `GET hotel/guests` → `hotel.guests.index` (index, show, edit, update)
- `POST hotel/guests/{guest}/sync-crm` → `hotel.guests.sync-crm`
- `GET hotel/reservations/{reservation}` → `hotel.reservations.show`
- `POST hotel/frontdesk/extend-stay/{reservation}` → `hotel.frontdesk.extend-stay`
- `POST hotel/frontdesk/change-room/{reservation}` → `hotel.frontdesk.change-room`
- `hotel/groups` resource — edit, update, destroy (cancel group)

**New files:**
- `Services/HotelStayOperationsService.php`
- `Services/HotelGuestCrmSyncService.php`
- `Http/Controllers/HotelGuestController.php`
- `Resources/views/guests/*`, `reservations/show.blade.php`, `groups/edit.blade.php`

**Acceptance criteria:**
- Every reservation has a detail page with full history. ✅
- Extend stay and change room quick actions work from checkout screen. ✅

---

### Phase 4 — Invoice & Document Generation ✅ COMPLETED (2026-06-11)

**Duration:** 2–3 days  
**Goal:** Formal invoice at checkout (ERP-native).

| # | Task | Status |
|---|------|--------|
| 4.1 | `HotelFolioInvoiceService` — create `Invoice` from folio lines + guest | ✅ |
| 4.2 | Populate `hotel_folios.invoice_id` | ✅ |
| 4.3 | Invoice-receipt — ERP invoice PDF or enhanced `final_bill_print` | ✅ |
| 4.4 | VAT from `hotel_accounting_settings`; line-level tax from folio | ✅ |
| 4.5 | Reprint quick action → invoice PDF download | ✅ |

**New route:** `GET hotel/folios/{folio}/tax-invoice` → `hotel.folios.tax-invoice` (redirects to ERP `invoice.pdf`)

**New file:** `Services/HotelFolioInvoiceService.php`

**Flow:** On checkout (after accounting journal finalize), ERP `Invoice` + `InvoiceProduct` lines are created; folio payments copied to `InvoicePayment`. Guest auto-linked to CRM `Customer` when possible.

**Acceptance criteria:**
- Checkout produces downloadable tax invoice. ✅
- Accounting journal + invoice document both exist. ✅

---

### Phase 5 — Minibar & Services Polish ✅ COMPLETED (2026-06-11)

**Duration:** 1–2 days  
**Goal:** Production-ready minibar workflow.

| # | Task | Status |
|---|------|--------|
| 5.1 | Minibar catalog CRUD UI (manage items, not only auto-seeded defaults) | ✅ |
| 5.2 | Restock workflow after checkout — reset room minibar checklist | ✅ |
| 5.3 | Optional: pre-checkout minibar scan flag before checkout allowed | ✅ |
| 5.4 | Service date on all charges in checkout breakdown | ✅ |

**New routes:**
- `hotel/minibar-items` resource + `hotel.minibar-items.seed-defaults`
- `POST hotel/frontdesk/verify-minibar/{stay}`
- `POST hotel/housekeeping/minibar-restock/{restock}/complete`

**New files:** `HotelMinibarItemController`, `HotelMinibarRestockService`, `minibar_items/*` views, migration `2026_06_11_150000_hotel_minibar_restock_and_checkout_flags.php`

**Acceptance criteria:**
- Staff can add/edit/remove minibar items without DB access. ✅
- Consumption audit trail visible on checkout. ✅

---

### Phase 6 — Night Audit & Reports ✅ COMPLETED (2026-06-11)

**Duration:** 2 days  
**Goal:** Real data everywhere; no mock UI.

| # | Task | Status |
|---|------|--------|
| 6.1 | Replace static preview cards with DB queries for selected business date | ✅ |
| 6.2 | Pre-audit checklist: open folios, unposted charges, room status anomalies | ✅ |
| 6.3 | Post-audit PDF export of closed day summary | ✅ |
| 6.4 | Verify dashboard KPIs are live (not cached/stale) | ✅ |

**New files:**
- `Services/HotelNightAuditPreviewService.php`
- `Resources/views/night_audit/partials/_checklist.blade.php`
- `Resources/views/night_audit/export_print.blade.php`

**New routes:**
- `GET hotel/night-audit/preview` → `hotel.night-audit.preview` (JSON)
- `GET hotel/night-audit/export` → `hotel.night-audit.export` (print/PDF)

**Acceptance criteria:**
- Night audit preview shows real occupancy, revenue, payment breakdown. ✅
- Pre-audit checklist flags open folios and room anomalies before close. ✅
- Export page generates printable/PDF summary for selected date. ✅
- Dashboard KPIs confirmed live (`HotelDashboardController` — direct DB queries, no cache). ✅

---

### Phase 7 — Room Management Enhancements ✅ COMPLETED (2026-06-11)

**Duration:** 1–2 days  
**Goal:** Industry-standard room status workflow.

| # | Task | Status |
|---|------|--------|
| 7.1 | Room status workflow: available → occupied → cleaning → available | ✅ |
| 7.2 | Housekeeping marks room clean → status back to available | ✅ |
| 7.3 | Out-of-order / maintenance blocks assignment | ✅ |
| 7.4 | Bulk status update from room map | ✅ |
| 7.5 | Enforce `max_occupancy` + extra beds on reservation | ✅ |

**New files:**
- `Services/HotelRoomStatusService.php`

**New routes:**
- `POST hotel/rooms/bulk-status` → `hotel.rooms.bulk-status`
- `POST hotel/rooms/{room}/mark-ready` → `hotel.rooms.mark-ready`

**Acceptance criteria:**
- Room map reflects real-time status with no manual DB edits. ✅
- Cannot assign maintenance/cleaning/occupied room to new guest. ✅
- Bulk status + “Mark ready” on cleaning rooms from room map. ✅
- Occupancy cap enforced at booking, check-in, and in live summary. ✅

---

### Phase 8 — Testing, Permissions & Seed Data ✅ COMPLETED (2026-06-11)

**Duration:** 2 days  
**Goal:** Demo-ready, regression-safe module.

| # | Task | Status |
|---|------|--------|
| 8.1 | End-to-end test script: reservation → check-in → minibar → addon → pay → checkout → invoice → night audit | ✅ |
| 8.2 | PHPUnit/feature tests: checkout flow, folio totals, addon charges, payment journal | ✅ |
| 8.3 | Permission review: front desk vs housekeeping vs manager roles | ✅ |
| 8.4 | Update `HotelDummyDataSeeder` for full-flow client demo | ✅ |

**New artifacts:**
- `Console/HotelE2eDemoCommand.php` — `php artisan hotelmanagement:e2e-demo` (optional `--checkout`)
- `Database/Seeders/HotelDemoRolesSeeder.php` — Hotel Manager, Front Desk, Housekeeping, Rooms Setup
- `tests/Unit/HotelManagement/HotelFolioPaymentServiceTest.php`
- `tests/Unit/HotelManagement/HotelFolioTest.php`
- `tests/Unit/HotelManagement/HotelAuthorizationTest.php`
- `tests/Feature/HotelManagement/HotelBillingFlowTest.php`

**Demo reservation:** `RSV-DEMO-FLOW` — in-house guest with extra bed, minibar line, partial payment.

**Run demo:**
```bash
php artisan db:seed --class="Modules\HotelManagement\Database\Seeders\HotelDummyDataSeeder"
php artisan hotelmanagement:e2e-demo
php artisan hotelmanagement:e2e-demo --checkout
php artisan test --filter=HotelManagement
```

**Acceptance criteria:**
- Automated tests cover critical billing path. ✅
- Demo seeder produces realistic end-to-end scenario. ✅

---

## Part 5 — Future Phases (Deferred)

| Phase | Scope | Notes |
|-------|-------|-------|
| **F9 — Channel manager** | Booking.com, Expedia API | Implement real `OtaSyncCommand` |
| **F10 — Direct booking engine** | Public hotel website module | Availability API from reservations |
| **F11 — Online payments** | Stripe / PayPal / local gateway | Prepayment on booking |
| **F12 — Mobile housekeeping** | PWA or API | Minibar scan, task updates |

Existing `hotel_ota_settings` table and API route stub are ready for extension.

**Detailed plan:** [`HOTEL_DIRECT_BOOKING_WEBSITE_PLAN.md`](./HOTEL_DIRECT_BOOKING_WEBSITE_PLAN.md) — SaaS URL model (`/{slug}`), guest flow, OTA sync, phases F9–F11.

---

## Part 6 — Sprint Timeline

| Week | Phases | Focus |
|------|--------|-------|
| **Week 1** | Phase 0 + Phase 1 | Portuguese + unified checkout |
| **Week 2** | Phase 2 + Phase 3 | Addons/extra bed + guest/reservation lifecycle |
| **Week 3** | Phase 4 + Phase 5 + Phase 6 | Invoice + minibar polish + night audit |
| **Week 4** | Phase 7 + Phase 8 | Room workflow + testing |

**Minimum viable (few days):** Phase 0 + Phase 1 + Phase 2 only.

---

## Part 7 — Definition of Done

The hotel module is **complete for internal use** when all items below are checked:

- [x] Full flow: **Reservation → Check-in → Services → Pay → Checkout → Invoice** (Phase 8 demo + e2e command)
- [x] Checkout screen fully functional (no static/demo data) — Phase 1
- [x] **Extra bed & addons** — catalog + reservation + folio linkage (Phase 2)
- [x] **Minibar** — catalog management + folio audit trail (Phase 5)
- [x] **Night audit** — real data, not mocks (Phase 6)
- [x] **Portuguese** — all hotel screens translated (Phase 0 — also zh, es, fr)
- [x] **No dead buttons** — extend stay, change room, receipt, reprint all work (Phases 1–3)
- [x] **ERP invoice** generated and linked to folio (`invoice_id`)
- [x] End-to-end demo passes with seeder data (Phase 8 — `hotelmanagement:e2e-demo`)
- [x] Permissions correct for front desk / housekeeping / manager roles (Phase 8 — `HotelDemoRolesSeeder` + unit tests)

---

## Part 8 — Client Summary Table

| Area | Today | After Plan |
|------|-------|------------|
| Room management | Working | Polished workflow (Phase 7) |
| Extra bed / addons | Missing | Full catalog + billing |
| Minibar | Working | + catalog CRUD |
| Checkout + billing | Split / static UI | Unified checkout |
| Portuguese (+ zh, es, fr) | Broken for hotel | ✅ Complete (Phase 0) |
| Invoice | Journal only | Journal + invoice document |
| OTA / website / online pay | Stub | Deferred (future) |

---

## Appendix A — Maturity Assessment

| Area | Maturity |
|------|----------|
| Rooms / types / floors / rates | Production-ready |
| Reservations + calendar | Production-ready (no show page) |
| Check-in / folio / check-out | Core flow works; checkout/receipt UI incomplete |
| Minibar / consumption / housekeeping | Production-ready |
| Folio payments + accounting journals | Production-ready (when COA configured) |
| POS charge-to-room | Production-ready |
| Night audit execution | Production-ready; preview + checklist + export live |
| Reports | Production-ready |
| OTA / channel sync | Settings only — sync stub |
| ERP invoices from folio | Not built |
| Portuguese i18n | Mostly missing |
| API | Placeholder |

---

## Appendix B — Web Routes Reference

All routes under `verified` + `auth` + `XSS` + `revalidate` middleware. Prefix: `hotel.*`

| Area | Route name pattern |
|------|-------------------|
| Dashboard | `hotel.dashboard` |
| Accounting settings | `hotel.accounting-settings.*` |
| Calendar | `hotel.calendar`, `hotel.calendar.events` |
| Reports | `hotel.reports.*` |
| OTA settings | `hotel.ota-settings.*` |
| Groups | `hotel.groups.*` (index, create, store, show only) |
| Reservations | `hotel.reservations.*` (missing show) |
| Folios | `hotel.folios.show`, `hotel.folios.payments.store`, `hotel.folios.lines.store` |
| Rooms | `hotel.rooms.*`, `hotel.rooms.map`, `hotel.rooms.consumption.*` |
| Housekeeping | `hotel.housekeeping.*`, `hotel.housekeeping.minibar.*` |
| Front desk | `hotel.frontdesk.in-house`, `checkout`, `receipt`, `check-in`, `check-out` |
| Night audit | `hotel.night-audit.*` |
| POS integration | `hotel.pos.in-house-guests`, `hotel.pos.charge` |

---

## Appendix C — Permissions

Defined in `app/Services/HotelPermissionCatalog.php`. Menu gates in `HotelManagementServiceProvider::registerHotelGates()`.

**Demo roles** (seed via `HotelDemoRolesSeeder`):

| Role | Typical use | Key permissions |
|------|-------------|-----------------|
| Hotel Manager | Owner / GM | All hotel permissions |
| Hotel Front Desk | Reception | Reservations + front desk + folio |
| Hotel Front Desk (view) | Trainee | View-only front desk + folio |
| Hotel Housekeeping | HK lead | Housekeeping + minibar + night audit manage |
| Hotel Rooms Setup | Inventory | Room types, floors, rooms |

| Permission area | Gate examples |
|-----------------|---------------|
| Module access | `hotel.menu.module` |
| Dashboard | `hotel.menu.dashboard` |
| Reservations | `hotel.menu.reservations` |
| Front desk | `hotel.menu.frontdesk` |
| Force checkout | `force hotel checkout` |
| Folio | view/manage folio permissions |
| Night audit | `hotel.menu.night_audit` |

---

*Document maintained in `Modules/HotelManagement/docs/`. Update version and date when phases are completed.*
