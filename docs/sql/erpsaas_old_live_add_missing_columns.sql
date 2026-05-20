-- ERP SaaS: add columns missing on EXISTING tables (old live → new schema)
-- Run AFTER erpsaas_old_live_add_missing_tables_with_data.sql
-- Generated for phpMyAdmin — skip any statement that errors with "Duplicate column name"

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 2026_05_02 document_flow_chains
ALTER TABLE `quotations` ADD COLUMN `converted_proforma_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `is_converted`;
ALTER TABLE `invoices` ADD COLUMN `quotation_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `pos_id`;
ALTER TABLE `invoices` ADD COLUMN `proforma_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `quotation_id`;
ALTER TABLE `purchases` ADD COLUMN `purchase_quotation_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `purchase_id`;
ALTER TABLE `purchases` ADD COLUMN `converted_bill_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `purchase_quotation_id`;
ALTER TABLE `bills` ADD COLUMN `purchase_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `order_number`;
ALTER TABLE `bills` ADD COLUMN `suppress_inventory` tinyint(1) NOT NULL DEFAULT 0 AFTER `purchase_id`;

-- 2026_05_07 hotel guest preferences
ALTER TABLE `hotel_guests` MODIFY `id_type` ENUM('id','passport','letter') NULL;
ALTER TABLE `hotel_guests` ADD COLUMN `nationality` varchar(100) DEFAULT NULL AFTER `id_number`;
ALTER TABLE `hotel_guests` ADD COLUMN `preferred_room_type_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `nationality`;
ALTER TABLE `hotel_guests` ADD COLUMN `preferred_floor` varchar(50) DEFAULT NULL AFTER `preferred_room_type_id`;
ALTER TABLE `hotel_guests` ADD COLUMN `preferred_payment_method` varchar(50) DEFAULT NULL AFTER `preferred_floor`;
ALTER TABLE `hotel_guests` ADD COLUMN `credit_limit` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `preferred_payment_method`;
ALTER TABLE `hotel_guests` ADD COLUMN `notes` text DEFAULT NULL AFTER `credit_limit`;

-- 2026_05_07 hotel groups (requires hotel_groups table)
ALTER TABLE `hotel_reservations` ADD COLUMN `hotel_group_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `hotel_guest_id`;
ALTER TABLE `hotel_reservations` ADD KEY `hotel_reservations_hotel_group_id_foreign` (`hotel_group_id`);
ALTER TABLE `hotel_reservations` ADD CONSTRAINT `hotel_reservations_hotel_group_id_foreign` FOREIGN KEY (`hotel_group_id`) REFERENCES `hotel_groups` (`id`) ON DELETE SET NULL;

-- 2026_05_11 room types / rooms / rate plans / reservations / guests
ALTER TABLE `hotel_room_types` ADD COLUMN `unit_category` enum('room','apartment') NOT NULL DEFAULT 'room' AFTER `description`;
ALTER TABLE `hotel_room_types` ADD COLUMN `nightly_rate` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `base_price`;
ALTER TABLE `hotel_room_types` ADD COLUMN `monthly_rate` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `nightly_rate`;
ALTER TABLE `hotel_room_types` ADD COLUMN `default_billing_mode` enum('nightly','monthly') NOT NULL DEFAULT 'nightly' AFTER `monthly_rate`;
UPDATE `hotel_room_types` SET `nightly_rate` = `base_price` WHERE `nightly_rate` = 0;

ALTER TABLE `hotel_rooms` ADD COLUMN `hotel_floor_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `hotel_room_type_id`;
ALTER TABLE `hotel_rooms` ADD COLUMN `monthly_override_price` decimal(15,2) DEFAULT NULL AFTER `override_price`;
ALTER TABLE `hotel_rooms` ADD KEY `hotel_rooms_hotel_floor_id_foreign` (`hotel_floor_id`);
ALTER TABLE `hotel_rooms` ADD CONSTRAINT `hotel_rooms_hotel_floor_id_foreign` FOREIGN KEY (`hotel_floor_id`) REFERENCES `hotel_floors` (`id`) ON DELETE SET NULL;

ALTER TABLE `hotel_rate_plans` ADD COLUMN `monthly_rate` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `rate`;
ALTER TABLE `hotel_rate_plans` ADD COLUMN `billing_mode` enum('nightly','monthly','both') NOT NULL DEFAULT 'nightly' AFTER `monthly_rate`;
ALTER TABLE `hotel_rate_plans` ADD COLUMN `priority` int(11) NOT NULL DEFAULT 100 AFTER `billing_mode`;

ALTER TABLE `hotel_guests` ADD COLUMN `preferred_hotel_floor_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `preferred_floor`;
ALTER TABLE `hotel_guests` ADD COLUMN `allergies` text DEFAULT NULL AFTER `notes`;
ALTER TABLE `hotel_guests` ADD COLUMN `preferences` text DEFAULT NULL AFTER `allergies`;
ALTER TABLE `hotel_guests` ADD COLUMN `drink_preferences` text DEFAULT NULL AFTER `preferences`;
ALTER TABLE `hotel_guests` ADD KEY `hotel_guests_preferred_hotel_floor_id_foreign` (`preferred_hotel_floor_id`);
ALTER TABLE `hotel_guests` ADD CONSTRAINT `hotel_guests_preferred_hotel_floor_id_foreign` FOREIGN KEY (`preferred_hotel_floor_id`) REFERENCES `hotel_floors` (`id`) ON DELETE SET NULL;

ALTER TABLE `hotel_reservations` ADD COLUMN `hotel_rate_plan_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `hotel_room_id`;
ALTER TABLE `hotel_reservations` ADD COLUMN `billing_mode` enum('nightly','monthly') NOT NULL DEFAULT 'nightly' AFTER `status`;
ALTER TABLE `hotel_reservations` ADD COLUMN `estimated_arrival_time` time DEFAULT NULL AFTER `check_out_at`;
ALTER TABLE `hotel_reservations` ADD COLUMN `parking_requested` tinyint(1) NOT NULL DEFAULT 0 AFTER `estimated_arrival_time`;
ALTER TABLE `hotel_reservations` ADD COLUMN `discount_reason` text DEFAULT NULL AFTER `discount_amount`;
ALTER TABLE `hotel_reservations` ADD COLUMN `guest_email_sent_at` timestamp NULL DEFAULT NULL AFTER `discount_reason`;
ALTER TABLE `hotel_reservations` ADD KEY `hotel_reservations_hotel_rate_plan_id_foreign` (`hotel_rate_plan_id`);
ALTER TABLE `hotel_reservations` ADD CONSTRAINT `hotel_reservations_hotel_rate_plan_id_foreign` FOREIGN KEY (`hotel_rate_plan_id`) REFERENCES `hotel_rate_plans` (`id`) ON DELETE SET NULL;

-- 2026_05_14 hotel accounting
ALTER TABLE `hotel_folios` ADD COLUMN `finalize_journal_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `status`;
ALTER TABLE `hotel_folios` ADD COLUMN `invoice_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `finalize_journal_id`;
ALTER TABLE `hotel_folio_lines` ADD COLUMN `revenue_channel` varchar(32) DEFAULT NULL AFTER `line_type`;
ALTER TABLE `hotel_guests` ADD COLUMN `customer_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `created_by`;

SET FOREIGN_KEY_CHECKS = 1;

-- Hotel permissions (from 2026_05_11_180600 + 2026_05_13_120000)
INSERT INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`)
SELECT 'show hotel room', 'web', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'show hotel room' AND `guard_name` = 'web');
INSERT INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`)
SELECT 'show hotel reservation', 'web', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'show hotel reservation' AND `guard_name` = 'web');
INSERT INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`)
SELECT 'show hotel frontdesk', 'web', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'show hotel frontdesk' AND `guard_name` = 'web');
INSERT INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`)
SELECT 'force hotel checkout', 'web', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'force hotel checkout' AND `guard_name` = 'web');

INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.id, r.id FROM `permissions` p
INNER JOIN `roles` r ON r.name = 'company' AND r.guard_name = 'web'
WHERE p.guard_name = 'web' AND p.name IN (
  'show hotel room', 'show hotel reservation', 'show hotel frontdesk', 'force hotel checkout'
);

-- Mark Laravel migrations as applied (optional; use next batch after your current max)
INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
('2026_05_02_140000_document_flow_chains', 18),
('2026_05_07_140000_extend_hotel_guests_with_preferences', 18),
('2026_05_07_150000_create_hotel_groups_and_members', 18),
('2026_05_07_160000_create_hotel_folio_payments_table', 18),
('2026_05_07_170000_create_hotel_ota_settings_table', 18),
('2026_05_11_180000_create_hotel_floors_table', 18),
('2026_05_11_180100_extend_hotel_room_types_for_unit_pricing', 18),
('2026_05_11_180200_extend_hotel_rooms_for_floor_and_rates', 18),
('2026_05_11_180300_extend_hotel_rate_plans_for_billing_modes', 18),
('2026_05_11_180400_extend_hotel_guests_for_alert_profiles', 18),
('2026_05_11_180500_extend_hotel_reservations_for_pricing_alerts_email', 18),
('2026_05_11_180600_add_hotel_view_permissions', 18),
('2026_05_13_120000_add_force_hotel_checkout_permission', 18),
('2026_05_14_100000_hotel_accounting_coa_and_folio_links', 18);
