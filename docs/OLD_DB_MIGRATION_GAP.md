# Old live database vs current migrations

Generated: 2026-05-17 20:41:31

Source dump: `old_live_database.sql`

## Executive summary

| Metric | Value |
|--------|-------|
| Tables in old dump | 249 |
| Migrations applied in old dump | 319 |
| Last migration in old dump | `2026_04_25_124000_assign_journal_approval_permissions_to_company_role` |
| Migration files in repo | 332 |
| **Pending migrations** (in repo, not in old dump) | **14** |
| **Tables missing in old dump** | **10** |

## Recommended upgrade path

1. Restore `old_live_database.sql` to MySQL/MariaDB.
2. Deploy current application code.
3. Run pending migrations in order:

```bash
cd /path/to/erp-saas
php artisan migrate --force
```

Laravel will apply only migrations **not** listed in the `migrations` table (everything after `2026_04_25_124000_assign_journal_approval_permissions_to_company_role`).

## Tables missing in old database

These tables are created by current code but **not present** in the SQL dump:

| # | Table | Migration file(s) |
|--:|-------|-------------------|
| 1 | `hotel_accounting_settings` | `2026_05_14_100000_hotel_accounting_coa_and_folio_links.php` |
| 2 | `hotel_floors` | `2026_05_11_180000_create_hotel_floors_table.php` |
| 3 | `hotel_folio_payments` | `2026_05_07_160000_create_hotel_folio_payments_table.php` |
| 4 | `hotel_group_members` | `2026_05_07_150000_create_hotel_groups_and_members.php` |
| 5 | `hotel_groups` | `2026_05_07_150000_create_hotel_groups_and_members.php` |
| 6 | `hotel_ota_settings` | `2026_05_07_170000_create_hotel_ota_settings_table.php` |
| 7 | `proforma_products` | `2026_05_02_140000_document_flow_chains.php` |
| 8 | `proformas` | `2026_05_02_140000_document_flow_chains.php` |
| 9 | `purchase_quotation_products` | `2026_05_02_140000_document_flow_chains.php` |
| 10 | `purchase_quotations` | `2026_05_02_140000_document_flow_chains.php` |

## Pending migrations (run in this order)

Total: **14** files.

| # | Migration | Creates table(s) | Type |
|--:|-----------|------------------|------|
| 1 | `2026_05_02_140000_document_flow_chains` | `proformas`, `proforma_products`, `purchase_quotations`, `purchase_quotation_products` **(new table)** | CREATE |
| 2 | `2026_05_07_140000_extend_hotel_guests_with_preferences` | — | ALTER / data |
| 3 | `2026_05_07_150000_create_hotel_groups_and_members` | `hotel_groups`, `hotel_group_members` **(new table)** | CREATE |
| 4 | `2026_05_07_160000_create_hotel_folio_payments_table` | `hotel_folio_payments` **(new table)** | CREATE |
| 5 | `2026_05_07_170000_create_hotel_ota_settings_table` | `hotel_ota_settings` **(new table)** | CREATE |
| 6 | `2026_05_11_180000_create_hotel_floors_table` | `hotel_floors` **(new table)** | CREATE |
| 7 | `2026_05_11_180100_extend_hotel_room_types_for_unit_pricing` | — | ALTER / data |
| 8 | `2026_05_11_180200_extend_hotel_rooms_for_floor_and_rates` | — | ALTER / data |
| 9 | `2026_05_11_180300_extend_hotel_rate_plans_for_billing_modes` | — | ALTER / data |
| 10 | `2026_05_11_180400_extend_hotel_guests_for_alert_profiles` | — | ALTER / data |
| 11 | `2026_05_11_180500_extend_hotel_reservations_for_pricing_alerts_email` | — | ALTER / data |
| 12 | `2026_05_11_180600_add_hotel_view_permissions` | — | other |
| 13 | `2026_05_13_120000_add_force_hotel_checkout_permission` | — | other |
| 14 | `2026_05_14_100000_hotel_accounting_coa_and_folio_links` | `hotel_accounting_settings` **(new table)** | CREATE |

### Pending migration file paths

- `2026_05_02_140000_document_flow_chains` → `database/migrations/2026_05_02_140000_document_flow_chains.php`
- `2026_05_07_140000_extend_hotel_guests_with_preferences` → `Modules/HotelManagement/Database/Migrations/2026_05_07_140000_extend_hotel_guests_with_preferences.php`
- `2026_05_07_150000_create_hotel_groups_and_members` → `Modules/HotelManagement/Database/Migrations/2026_05_07_150000_create_hotel_groups_and_members.php`
- `2026_05_07_160000_create_hotel_folio_payments_table` → `Modules/HotelManagement/Database/Migrations/2026_05_07_160000_create_hotel_folio_payments_table.php`
- `2026_05_07_170000_create_hotel_ota_settings_table` → `Modules/HotelManagement/Database/Migrations/2026_05_07_170000_create_hotel_ota_settings_table.php`
- `2026_05_11_180000_create_hotel_floors_table` → `Modules/HotelManagement/Database/Migrations/2026_05_11_180000_create_hotel_floors_table.php`
- `2026_05_11_180100_extend_hotel_room_types_for_unit_pricing` → `Modules/HotelManagement/Database/Migrations/2026_05_11_180100_extend_hotel_room_types_for_unit_pricing.php`
- `2026_05_11_180200_extend_hotel_rooms_for_floor_and_rates` → `Modules/HotelManagement/Database/Migrations/2026_05_11_180200_extend_hotel_rooms_for_floor_and_rates.php`
- `2026_05_11_180300_extend_hotel_rate_plans_for_billing_modes` → `Modules/HotelManagement/Database/Migrations/2026_05_11_180300_extend_hotel_rate_plans_for_billing_modes.php`
- `2026_05_11_180400_extend_hotel_guests_for_alert_profiles` → `Modules/HotelManagement/Database/Migrations/2026_05_11_180400_extend_hotel_guests_for_alert_profiles.php`
- `2026_05_11_180500_extend_hotel_reservations_for_pricing_alerts_email` → `Modules/HotelManagement/Database/Migrations/2026_05_11_180500_extend_hotel_reservations_for_pricing_alerts_email.php`
- `2026_05_11_180600_add_hotel_view_permissions` → `Modules/HotelManagement/Database/Migrations/2026_05_11_180600_add_hotel_view_permissions.php`
- `2026_05_13_120000_add_force_hotel_checkout_permission` → `Modules/HotelManagement/Database/Migrations/2026_05_13_120000_add_force_hotel_checkout_permission.php`
- `2026_05_14_100000_hotel_accounting_coa_and_folio_links` → `Modules/HotelManagement/Database/Migrations/2026_05_14_100000_hotel_accounting_coa_and_folio_links.php`

## Hotel module note

Old dump includes core hotel tables (`hotel_folios`, `hotel_guests`, etc.) through batch 17, but **not**:

- `hotel_accounting_settings`
- `hotel_floors`
- `hotel_folio_payments`
- `hotel_group_members`
- `hotel_groups`
- `hotel_ota_settings`

Configure **Hotel accounting (COA)** after running `2026_05_14_100000_hotel_accounting_coa_and_folio_links`.

### Column changes (same pending run, no new table)

| Migration | Affected table(s) | Adds |
|-----------|-------------------|------|
| `2026_05_07_140000_extend_hotel_guests_with_preferences` | `hotel_guests` | guest preference fields |
| `2026_05_11_180100` … `180500` | `hotel_room_types`, `hotel_rooms`, `hotel_rate_plans`, `hotel_guests`, `hotel_reservations` | pricing, floors, alerts |
| `2026_05_14_100000_hotel_accounting_coa_and_folio_links` | `hotel_folios`, `hotel_folio_lines`, `hotel_folio_payments`, `hotel_guests` | `finalize_journal_id`, `invoice_id`, `revenue_channel`, payment journal link, `customer_id` |

## Tables in old dump without `Schema::create` in repo

Usually legacy tables created by older migration style (multiline `Schema::create`) or third-party packages. **11** tables — see `docs/tables_only_in_old_dump.txt`.

## Appendix: migrations already applied in old dump

First: `2014_10_12_000000_create_users_table` — Last: `2026_04_25_124000_assign_journal_approval_permissions_to_company_role` (319 total).
