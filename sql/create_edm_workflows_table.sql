-- Table `edm_workflows` (EDM module, odb database).
-- Automation workflows. status: 1=draft, 2=active, 3=paused.
-- trigger_type: 'event' (trigger = a built-in key or an edm_triggers key) or
-- 'schedule' (trigger NULL). timing JSON - event: { send: now|delay, delay_value,
-- delay_unit: minutes|hours|days, at_time: HH:MM|null }; schedule: { repeat:
-- once|daily|weekly|monthly, date (once, Y-m-d), time HH:MM, weekday 1-7 (weekly,
-- 1 = Monday), day 1-31 (monthly) }. See Workflow::timingInput().
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_workflows`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `edm_workflows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trigger_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'event',
  `trigger` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timing` json DEFAULT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `definition` json DEFAULT NULL,
  `content_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content_id` bigint unsigned DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
