-- Table `edm_master_tags` (EDM module, odb database).
-- Master list of newsletter tags (e.g. Promotion, Birthday, Health tips) to
-- group and find newsletters. A newsletter holds its tags as edm_campaigns.tag_ids
-- (JSON list of ids). Tags are created from the Email creator Setup step (type a
-- new name) or picked from this list; names are unique among active rows (checked
-- by the app, case-insensitive, so a deleted tag never blocks re-creating it).
-- Not the contact tags (edm_tags / edm_member_tags, kept for the warehouse sync).
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_master_tags`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `edm_master_tags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_master_tags_name_index` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
