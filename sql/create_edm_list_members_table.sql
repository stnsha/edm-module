-- Table `edm_list_members` (EDM module, odb database).
-- Members of a list. status: 1=subscribed, 2=unsubscribed, 3=bounced.
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_list_members`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `edm_list_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `list_id` bigint unsigned NOT NULL,
  `member_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `subscribed_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_list_members_list_id_member_code_index` (`list_id`,`member_code`),
  KEY `edm_list_members_member_code_index` (`member_code`),
  CONSTRAINT `edm_list_members_list_id_foreign` FOREIGN KEY (`list_id`) REFERENCES `edm_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
