-- EDM module - clear all data.
-- Empties every edm_* table (20) and resets AUTO_INCREMENT; the schema stays.
-- Every row is lost, including seed data, settings, senders, suppressions,
-- send history and soft-deleted rows. Not reversible: back up first.
-- staff.edm (role tier) is not touched.
-- Files under uploads/ (assets) are not removed by this script.
-- To restore the starter data afterwards, run edm_seed.sql (and edm_seed_uat.sql
-- for UAT only).
--
-- DELETE, not TRUNCATE: MySQL refuses to TRUNCATE a table referenced by a
-- foreign key while foreign key checks are on, and phpMyAdmin's "Enable foreign
-- key checks" option (on by default) overrides SET FOREIGN_KEY_CHECKS = 0.
-- Children are deleted before their parents, so this runs with checks on or off.

SET NAMES utf8mb4;

DELETE FROM `edm_calendar_slots`;
DELETE FROM `edm_autoresponders`;
DELETE FROM `edm_workflows`;
DELETE FROM `edm_triggers`;
DELETE FROM `edm_assets`;
DELETE FROM `edm_templates`;
DELETE FROM `edm_campaign_content`;
DELETE FROM `edm_campaigns`;
DELETE FROM `edm_master_tags`;
DELETE FROM `edm_ses_events`;
DELETE FROM `edm_send_log`;
DELETE FROM `edm_suppressions`;
DELETE FROM `edm_settings`;
DELETE FROM `edm_sending_domains`;
DELETE FROM `edm_member_tags`;
DELETE FROM `edm_segments`;
DELETE FROM `edm_contacts`;
DELETE FROM `edm_tags`;
DELETE FROM `edm_custom_fields`;
DELETE FROM `edm_senders`;

-- Restart ids at 1.
ALTER TABLE `edm_calendar_slots` AUTO_INCREMENT = 1;
ALTER TABLE `edm_autoresponders` AUTO_INCREMENT = 1;
ALTER TABLE `edm_workflows` AUTO_INCREMENT = 1;
ALTER TABLE `edm_triggers` AUTO_INCREMENT = 1;
ALTER TABLE `edm_assets` AUTO_INCREMENT = 1;
ALTER TABLE `edm_templates` AUTO_INCREMENT = 1;
ALTER TABLE `edm_campaign_content` AUTO_INCREMENT = 1;
ALTER TABLE `edm_campaigns` AUTO_INCREMENT = 1;
ALTER TABLE `edm_master_tags` AUTO_INCREMENT = 1;
ALTER TABLE `edm_ses_events` AUTO_INCREMENT = 1;
ALTER TABLE `edm_send_log` AUTO_INCREMENT = 1;
ALTER TABLE `edm_suppressions` AUTO_INCREMENT = 1;
ALTER TABLE `edm_settings` AUTO_INCREMENT = 1;
ALTER TABLE `edm_sending_domains` AUTO_INCREMENT = 1;
ALTER TABLE `edm_member_tags` AUTO_INCREMENT = 1;
ALTER TABLE `edm_segments` AUTO_INCREMENT = 1;
ALTER TABLE `edm_contacts` AUTO_INCREMENT = 1;
ALTER TABLE `edm_tags` AUTO_INCREMENT = 1;
ALTER TABLE `edm_custom_fields` AUTO_INCREMENT = 1;
ALTER TABLE `edm_senders` AUTO_INCREMENT = 1;
