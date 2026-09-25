-- Table `edm_member_tags` (EDM module, odb database).
-- Tag assignments per member_code.
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_member_tags`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `edm_member_tags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `member_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tag_id` bigint unsigned NOT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_member_tags_member_code_tag_id_index` (`member_code`,`tag_id`),
  KEY `edm_member_tags_tag_id_foreign` (`tag_id`),
  CONSTRAINT `edm_member_tags_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `edm_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
