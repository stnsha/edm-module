-- Table `edm_campaign_qa` (EDM module, odb database).
-- Automated QA runs on a campaign's saved design (spec 5.2 step 5 / section
-- 11). status: 1=queued, 2=running, 3=passed, 4=passed with warnings,
-- 5=failed. results = JSON list of checks [{key, label, result: pass|warn|fail,
-- summary, details[]}]. trigger = what queued it (category string: bpt_approved,
-- resaved, manual). Queued runs are worked by cron/qa.php (every minute via
-- cron/edm-send.bat) or by the review page when it polls.
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_campaign_qa`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `edm_campaign_qa` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `approval_id` bigint unsigned DEFAULT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `trigger` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `results` json DEFAULT NULL,
  `queued_by` int unsigned DEFAULT NULL,
  `queued_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `started_at` datetime NULL DEFAULT NULL,
  `finished_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_campaign_qa_campaign_id_foreign` (`campaign_id`),
  KEY `edm_campaign_qa_status_index` (`status`),
  CONSTRAINT `edm_campaign_qa_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `edm_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
