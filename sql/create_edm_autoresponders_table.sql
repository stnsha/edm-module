-- Table `edm_autoresponders` for the EDM module. Data owned by edm-api.
-- Canonical DDL; mirrors edm-api/database/migrations/2026_09_08_000016_create_edm_autoresponders_table.php.
-- Applied to the edm-api database (local: edm_local).

CREATE TABLE `edm_autoresponders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `list_id` bigint unsigned DEFAULT NULL,
  `offset_days` int NOT NULL DEFAULT '0',
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('draft','active','paused') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_autoresponders_list_id_foreign` (`list_id`),
  CONSTRAINT `edm_autoresponders_list_id_foreign` FOREIGN KEY (`list_id`) REFERENCES `edm_lists` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
