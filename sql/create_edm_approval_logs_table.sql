-- Table `edm_approval_logs` (EDM module, odb database).
-- Activity log of a review request (approval/edit.php Activity Log card):
-- event = created | updated | attachment_added | attachment_removed |
-- approved | rejected (a category string, not a status); changes = JSON
-- [{label, from, to}] for field edits; summary = one-line description.
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_approval_logs`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `edm_approval_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `approval_id` bigint unsigned NOT NULL,
  `event` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `changes` json DEFAULT NULL,
  `actor_id` int unsigned DEFAULT NULL,
  `actor_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_approval_logs_approval_id_foreign` (`approval_id`),
  CONSTRAINT `edm_approval_logs_approval_id_foreign` FOREIGN KEY (`approval_id`) REFERENCES `edm_approvals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
