-- ERP SaaS: add tables missing in old live DB (with data from new export)
-- Generated: 2026-05-17 20:59:28
-- Old: erpsaas_old_live.sql (249 tables)
-- New: erpsaas_new.sql (259 tables)
-- Missing tables: 10
--
-- Run in phpMyAdmin on database restored from erpsaas_old_live.sql
-- Tables: hotel_groups, hotel_group_members, hotel_floors, hotel_folio_payments, hotel_ota_settings, hotel_accounting_settings, proformas, proforma_products, purchase_quotations, purchase_quotation_products
-- INSERT IGNORE skips rows when that primary key already exists (safe to re-run).
-- Error "Duplicate entry '1' for key 'PRIMARY'" = you already imported these rows; re-run with this file or skip INSERT sections.
-- STEP 2: erpsaas_old_live_add_missing_columns.sql

/* Uncomment to wipe demo data in these tables only, then re-import:
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE `hotel_groups`;
TRUNCATE TABLE `hotel_group_members`;
TRUNCATE TABLE `hotel_floors`;
TRUNCATE TABLE `hotel_folio_payments`;
TRUNCATE TABLE `hotel_ota_settings`;
TRUNCATE TABLE `hotel_accounting_settings`;
TRUNCATE TABLE `proformas`;
TRUNCATE TABLE `proforma_products`;
TRUNCATE TABLE `purchase_quotations`;
TRUNCATE TABLE `purchase_quotation_products`;
SET FOREIGN_KEY_CHECKS = 1;
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Table: hotel_groups
CREATE TABLE IF NOT EXISTS `hotel_groups` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `company_name` varchar(191) DEFAULT NULL,
  `contact_name` varchar(191) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `branch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `hotel_groups` (`id`, `name`, `company_name`, `contact_name`, `notes`, `branch_id`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Silva Family Holiday', NULL, 'Carlos Silva', 'Family of 4 — connecting rooms requested.', NULL, 2, '2026-05-07 06:14:48', '2026-05-07 06:14:48'),
(2, 'Acme Corp — Q2 Offsite', 'Acme Mozambique LDA', 'Helena Costa', 'Corporate offsite, late check-out, billing under company.', NULL, 2, '2026-05-07 06:14:48', '2026-05-07 06:14:48'),
(3, 'Maputo City Tour Bus', 'Sunrise Tours', 'Pedro Manjate', 'Tour group, 2 nights, Standard rooms.', NULL, 2, '2026-05-07 06:14:48', '2026-05-07 06:14:48');

-- Table: hotel_group_members
CREATE TABLE IF NOT EXISTS `hotel_group_members` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `hotel_group_id` bigint(20) UNSIGNED NOT NULL,
  `hotel_guest_id` bigint(20) UNSIGNED NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'adult',
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `hotel_group_members` (`id`, `hotel_group_id`, `hotel_guest_id`, `role`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'adult', 0, '2026-05-07 06:14:48', '2026-05-07 06:14:48'),
(2, 1, 2, 'adult', 1, '2026-05-07 06:14:48', '2026-05-07 06:14:48'),
(3, 1, 3, 'child', 2, '2026-05-07 06:14:48', '2026-05-07 06:14:48'),
(4, 1, 4, 'child', 3, '2026-05-07 06:14:48', '2026-05-07 06:14:48'),
(5, 2, 5, 'adult', 0, '2026-05-07 06:14:48', '2026-05-07 06:14:48'),
(6, 2, 6, 'adult', 1, '2026-05-07 06:14:48', '2026-05-07 06:14:48'),
(7, 2, 7, 'adult', 2, '2026-05-07 06:14:48', '2026-05-07 06:14:48'),
(8, 3, 8, 'adult', 0, '2026-05-07 06:14:48', '2026-05-07 06:14:48'),
(9, 3, 9, 'adult', 1, '2026-05-07 06:14:48', '2026-05-07 06:14:48'),
(10, 3, 10, 'adult', 2, '2026-05-07 06:14:48', '2026-05-07 06:14:48'),
(11, 3, 11, 'adult', 3, '2026-05-07 06:14:48', '2026-05-07 06:14:48');

-- Table: hotel_floors
CREATE TABLE IF NOT EXISTS `hotel_floors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `level_number` int(11) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `hotel_floors` (`id`, `name`, `level_number`, `sort_order`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Floor 1', NULL, 0, 1, 2, '2026-05-11 12:32:07', '2026-05-11 12:32:07'),
(2, 'Floor 2', NULL, 0, 1, 2, '2026-05-11 12:32:17', '2026-05-11 12:32:17');

-- Table: hotel_folio_payments
CREATE TABLE IF NOT EXISTS `hotel_folio_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `hotel_folio_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `method` varchar(50) NOT NULL,
  `reference` varchar(191) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `accounting_payment_journal_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `hotel_folio_payments` (`id`, `hotel_folio_id`, `amount`, `method`, `reference`, `notes`, `accounting_payment_journal_id`, `created_by`, `created_at`, `updated_at`) VALUES
(9, 11, 160.00, 'cash', 'DEMO-103EF5ED', NULL, NULL, 2, '2026-05-07 06:21:52', '2026-05-07 06:21:52'),
(10, 12, 407.00, 'mpesa', 'DEMO-1F289CD1', NULL, NULL, 2, '2026-05-07 06:21:52', '2026-05-07 06:21:52'),
(11, 13, 165.00, 'card', 'DEMO-4905ABB6', NULL, NULL, 2, '2026-05-07 06:21:52', '2026-05-07 06:21:52'),
(12, 14, 920.00, 'bank_transfer', 'DEMO-376535A5', NULL, NULL, 2, '2026-05-07 06:21:52', '2026-05-07 06:21:52'),
(13, 15, 197.50, 'cash', 'DEMO-618A81B7', NULL, NULL, 2, '2026-05-07 06:21:52', '2026-05-07 06:21:52'),
(14, 16, 502.00, 'mpesa', 'DEMO-A6BE671E', NULL, NULL, 2, '2026-05-07 06:21:52', '2026-05-07 06:21:52'),
(15, 17, 600.00, 'card', 'DEMO-5C15DBC9', NULL, NULL, 2, '2026-05-07 06:21:52', '2026-05-07 06:21:52'),
(16, 18, 270.00, 'bank_transfer', 'DEMO-289E8555', NULL, NULL, 2, '2026-05-07 06:21:52', '2026-05-07 06:21:52'),
(17, 19, 322.00, 'cash', 'DEMO-28174B4E', NULL, NULL, 2, '2026-05-07 06:21:52', '2026-05-07 06:21:52');

-- Table: hotel_ota_settings
CREATE TABLE IF NOT EXISTS `hotel_ota_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `channel` varchar(50) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `api_key` text DEFAULT NULL,
  `api_secret` text DEFAULT NULL,
  `property_id` varchar(191) DEFAULT NULL,
  `webhook_url` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `hotel_ota_settings` (`id`, `channel`, `enabled`, `api_key`, `api_secret`, `property_id`, `webhook_url`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'booking_com', 0, NULL, NULL, NULL, NULL, 2, '2026-05-07 06:11:01', '2026-05-07 06:11:01'),
(2, 'agoda', 0, NULL, NULL, NULL, NULL, 2, '2026-05-07 06:11:01', '2026-05-07 06:11:01'),
(3, 'generic_ical', 0, NULL, NULL, NULL, NULL, 2, '2026-05-07 06:11:01', '2026-05-07 06:11:01');

-- Table: hotel_accounting_settings
CREATE TABLE IF NOT EXISTS `hotel_accounting_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `chart_guest_ar_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Accounts receivable — guest',
  `chart_room_revenue_id` bigint(20) UNSIGNED DEFAULT NULL,
  `chart_restaurant_revenue_id` bigint(20) UNSIGNED DEFAULT NULL,
  `chart_laundry_revenue_id` bigint(20) UNSIGNED DEFAULT NULL,
  `chart_misc_revenue_id` bigint(20) UNSIGNED DEFAULT NULL,
  `chart_vat_payable_id` bigint(20) UNSIGNED DEFAULT NULL,
  `default_vat_rate_percent` decimal(5,2) NOT NULL DEFAULT 16.00,
  `pos_invoice_when_charge_to_room` tinyint(1) NOT NULL DEFAULT 0,
  `folio_payment_bank_map` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`folio_payment_bank_map`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: proformas
CREATE TABLE IF NOT EXISTS `proformas` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `proforma_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `quotation_id` bigint(20) UNSIGNED DEFAULT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `warehouse_id` int(11) NOT NULL DEFAULT 0,
  `proforma_date` date DEFAULT NULL,
  `category_id` int(11) NOT NULL DEFAULT 0,
  `status` int(11) NOT NULL DEFAULT 0,
  `converted_invoice_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_invoice_converted` tinyint(4) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `proformas` (`id`, `proforma_id`, `quotation_id`, `customer_id`, `warehouse_id`, `proforma_date`, `category_id`, `status`, `converted_invoice_id`, `is_invoice_converted`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 13, 35, '2026-03-16', 0, 0, NULL, 0, 2, '2026-05-02 06:57:03', '2026-05-02 06:57:03');

-- Table: proforma_products
CREATE TABLE IF NOT EXISTS `proforma_products` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `proforma_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `product_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `quantity` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `tax` varchar(191) DEFAULT '0',
  `discount` decimal(15,2) DEFAULT 0.00,
  `price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `proforma_products` (`id`, `proforma_id`, `product_id`, `quantity`, `tax`, `discount`, `price`, `description`, `created_at`, `updated_at`) VALUES
(1, 1, 14, 1.0000, '3', 10.00, 4500.00, 'abc', '2026-05-02 06:57:03', '2026-05-02 06:57:03');

-- Table: purchase_quotations
CREATE TABLE IF NOT EXISTS `purchase_quotations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_quotation_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `vender_id` int(11) NOT NULL DEFAULT 0,
  `warehouse_id` int(11) NOT NULL DEFAULT 0,
  `quote_date` date DEFAULT NULL,
  `category_id` int(11) NOT NULL DEFAULT 0,
  `status` int(11) NOT NULL DEFAULT 0,
  `converted_purchase_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_converted` tinyint(4) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: purchase_quotation_products
CREATE TABLE IF NOT EXISTS `purchase_quotation_products` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_quotation_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `product_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `quantity` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `tax` varchar(191) DEFAULT '0',
  `discount` decimal(15,2) DEFAULT 0.00,
  `price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes, AUTO_INCREMENT, foreign keys

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- Indexes for table `hotel_groups`
--
ALTER TABLE `hotel_groups`
  ADD PRIMARY KEY (`id`);

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- AUTO_INCREMENT for table `hotel_groups`
--
ALTER TABLE `hotel_groups`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- Indexes for table `hotel_group_members`
--
ALTER TABLE `hotel_group_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `hotel_group_members_hotel_group_id_foreign` (`hotel_group_id`),
  ADD KEY `hotel_group_members_hotel_guest_id_foreign` (`hotel_guest_id`);

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- AUTO_INCREMENT for table `hotel_group_members`
--
ALTER TABLE `hotel_group_members`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- Constraints for table `hotel_group_members`
--
ALTER TABLE `hotel_group_members`
  ADD CONSTRAINT `hotel_group_members_hotel_group_id_foreign` FOREIGN KEY (`hotel_group_id`) REFERENCES `hotel_groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `hotel_group_members_hotel_guest_id_foreign` FOREIGN KEY (`hotel_guest_id`) REFERENCES `hotel_guests` (`id`) ON DELETE CASCADE;

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- Indexes for table `hotel_floors`
--
ALTER TABLE `hotel_floors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `hotel_floors_created_by_name_unique` (`created_by`,`name`);

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- AUTO_INCREMENT for table `hotel_floors`
--
ALTER TABLE `hotel_floors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- Indexes for table `hotel_folio_payments`
--
ALTER TABLE `hotel_folio_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `hotel_folio_payments_hotel_folio_id_foreign` (`hotel_folio_id`);

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- AUTO_INCREMENT for table `hotel_folio_payments`
--
ALTER TABLE `hotel_folio_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- Constraints for table `hotel_folio_payments`
--
ALTER TABLE `hotel_folio_payments`
  ADD CONSTRAINT `hotel_folio_payments_hotel_folio_id_foreign` FOREIGN KEY (`hotel_folio_id`) REFERENCES `hotel_folios` (`id`) ON DELETE CASCADE;

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- Indexes for table `hotel_ota_settings`
--
ALTER TABLE `hotel_ota_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `hotel_ota_settings_created_by_channel_unique` (`created_by`,`channel`);

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- AUTO_INCREMENT for table `hotel_ota_settings`
--
ALTER TABLE `hotel_ota_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- Indexes for table `hotel_accounting_settings`
--
ALTER TABLE `hotel_accounting_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `hotel_accounting_settings_created_by_unique` (`created_by`);

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- AUTO_INCREMENT for table `hotel_accounting_settings`
--
ALTER TABLE `hotel_accounting_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- Indexes for table `proformas`
--
ALTER TABLE `proformas`
  ADD PRIMARY KEY (`id`);

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- AUTO_INCREMENT for table `proformas`
--
ALTER TABLE `proformas`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- Indexes for table `proforma_products`
--
ALTER TABLE `proforma_products`
  ADD PRIMARY KEY (`id`);

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- AUTO_INCREMENT for table `proforma_products`
--
ALTER TABLE `proforma_products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- Indexes for table `purchase_quotations`
--
ALTER TABLE `purchase_quotations`
  ADD PRIMARY KEY (`id`);

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- AUTO_INCREMENT for table `purchase_quotations`
--
ALTER TABLE `purchase_quotations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- Indexes for table `purchase_quotation_products`
--
ALTER TABLE `purchase_quotation_products`
  ADD PRIMARY KEY (`id`);

-- Skip next block if you get "Multiple primary key" or duplicate key errors.
-- AUTO_INCREMENT for table `purchase_quotation_products`
--
ALTER TABLE `purchase_quotation_products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

SET FOREIGN_KEY_CHECKS = 1;
