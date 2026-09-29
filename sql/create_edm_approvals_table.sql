-- Table `edm_approvals` (EDM module, odb database).
-- Review requests / approval steps per campaign. status: 1=pending, 2=approved, 3=rejected.
-- A request raised from approval/edit.php carries title, requester, objective,
-- audience brief and copywriting (HTML from the Quill editors); artwork files
-- are in edm_approval_files.
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_approvals`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `edm_approvals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requested_by` int unsigned DEFAULT NULL,
  `requested_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `objective` mediumtext COLLATE utf8mb4_unicode_ci,
  `audience_brief` mediumtext COLLATE utf8mb4_unicode_ci,
  `copywriting` mediumtext COLLATE utf8mb4_unicode_ci,
  `step` tinyint unsigned NOT NULL DEFAULT '1',
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `reviewer_id` int unsigned DEFAULT NULL,
  `reviewer_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_approvals_campaign_id_step_index` (`campaign_id`,`step`),
  CONSTRAINT `edm_approvals_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `edm_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
