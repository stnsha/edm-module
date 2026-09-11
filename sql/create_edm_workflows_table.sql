-- Table `edm_workflows` for the EDM module. Data owned by edm-api.
-- Canonical DDL; mirrors edm-api/database/migrations/2026_09_08_000015_create_edm_workflows_table.php.
-- Applied to the edm-api database (local: edm_local).

CREATE TABLE `edm_workflows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trigger` enum('new_member','birthday','inactivity','cart_abandonment','soft_bounce','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `status` enum('draft','active','paused') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `definition` json DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
