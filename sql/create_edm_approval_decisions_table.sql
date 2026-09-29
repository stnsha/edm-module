-- Table `edm_approval_decisions` (EDM module, odb database).
-- One row per stage decision on a review request: stage = bpt (spec 5.2
-- steps 2-3, BPT team) or audience (step 4, BI/CRM) - a category string;
-- decision: 2=approved, 3=rejected; checks = JSON list of the checklist
-- keys that were ticked (Approval::BPT_CHECKS).
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_approval_decisions`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `edm_approval_decisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `approval_id` bigint unsigned NOT NULL,
  `stage` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `decision` tinyint unsigned NOT NULL,
  `checks` json DEFAULT NULL,
  `comment` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `decided_by` int unsigned DEFAULT NULL,
  `decided_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_approval_decisions_approval_id_stage_index` (`approval_id`,`stage`),
  CONSTRAINT `edm_approval_decisions_approval_id_foreign` FOREIGN KEY (`approval_id`) REFERENCES `edm_approvals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
