-- Table `edm_campaigns` for the EDM module. Data owned by edm-api.
-- Canonical DDL; mirrors edm-api/database/migrations/2026_09_08_000011_create_edm_campaigns_table.php.
-- Applied to the edm-api database (local: edm_local).

CREATE TABLE `edm_campaigns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_b` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preheader` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sender_id` bigint unsigned DEFAULT NULL,
  `list_id` bigint unsigned DEFAULT NULL,
  `segment_id` bigint unsigned DEFAULT NULL,
  `status` enum('draft','pending_submission','under_bpt_review','content_revision','audience_validation','scheduled','sending','completed','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `requested_by` int unsigned DEFAULT NULL,
  `requested_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_campaigns_status_index` (`status`),
  KEY `edm_campaigns_scheduled_at_index` (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
