-- Sender identities for the EDM module (GetResponse "From fields").
-- Data is owned by edm-api; this file is the canonical DDL and mirrors
-- edm-api/database/migrations/2026_09_08_000001_create_edm_senders_table.php.
-- Applied to the edm-api database (local: edm_local).

CREATE TABLE `edm_senders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(255) NOT NULL,
  `from_name` VARCHAR(255) NOT NULL,
  `reply_to` VARCHAR(255) NULL,
  `status` ENUM('pending','verified','failed') NOT NULL DEFAULT 'pending',
  `verified_at` TIMESTAMP NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `created_by_name` VARCHAR(150) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `edm_senders_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
