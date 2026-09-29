-- Table `edm_approval_files` (EDM module, odb database).
-- Artwork attached to a review request (edm_approvals); files live in
-- uploads/approvals/ under a random name, url is the public address.
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_approval_files`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `edm_approval_files` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `approval_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size_bytes` int unsigned DEFAULT NULL,
  `uploaded_by` int unsigned DEFAULT NULL,
  `uploaded_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_approval_files_approval_id_foreign` (`approval_id`),
  CONSTRAINT `edm_approval_files_approval_id_foreign` FOREIGN KEY (`approval_id`) REFERENCES `edm_approvals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
