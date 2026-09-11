-- Table `edm_calendar_slots` for the EDM module. Data owned by edm-api.
-- Canonical DDL; mirrors edm-api/database/migrations/2026_09_08_000019_create_edm_calendar_slots_table.php.
-- Applied to the edm-api database (local: edm_local).

CREATE TABLE `edm_calendar_slots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slot_date` date NOT NULL,
  `slot_label` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `campaign_id` bigint unsigned DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_calendar_slots_slot_date_index` (`slot_date`),
  KEY `edm_calendar_slots_campaign_id_foreign` (`campaign_id`),
  CONSTRAINT `edm_calendar_slots_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `edm_campaigns` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
