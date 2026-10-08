-- EDM module - upgrade edm_campaigns for the Email creator wizard
-- (DEPLOY.md section 6.2 Path B; a fresh install uses edm_master.sql).
--
-- Adds archived_from (status before archiving, restored by Unarchive),
-- audience (included / excluded segments and contacts), send_now,
-- track_opens, track_clicks, utm_enabled and utm; copies an existing
-- segment_id into audience (include that one segment); then drops
-- segment_id. Every step checks the current schema, so running the file
-- again changes nothing. Back up first (DEPLOY.md section 1).

SET NAMES utf8mb4;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_campaigns' AND COLUMN_NAME = 'audience') = 0,
    'ALTER TABLE `edm_campaigns` ADD COLUMN `audience` json DEFAULT NULL AFTER `reply_to_id`',
    'SELECT ''edm_campaigns.audience already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_campaigns' AND COLUMN_NAME = 'send_now') = 0,
    'ALTER TABLE `edm_campaigns` ADD COLUMN `send_now` tinyint(1) NOT NULL DEFAULT ''0'' AFTER `audience`',
    'SELECT ''edm_campaigns.send_now already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_campaigns' AND COLUMN_NAME = 'track_opens') = 0,
    'ALTER TABLE `edm_campaigns` ADD COLUMN `track_opens` tinyint(1) NOT NULL DEFAULT ''1'' AFTER `send_now`',
    'SELECT ''edm_campaigns.track_opens already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_campaigns' AND COLUMN_NAME = 'track_clicks') = 0,
    'ALTER TABLE `edm_campaigns` ADD COLUMN `track_clicks` tinyint(1) NOT NULL DEFAULT ''1'' AFTER `track_opens`',
    'SELECT ''edm_campaigns.track_clicks already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_campaigns' AND COLUMN_NAME = 'utm_enabled') = 0,
    'ALTER TABLE `edm_campaigns` ADD COLUMN `utm_enabled` tinyint(1) NOT NULL DEFAULT ''1'' AFTER `track_clicks`',
    'SELECT ''edm_campaigns.utm_enabled already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_campaigns' AND COLUMN_NAME = 'utm') = 0,
    'ALTER TABLE `edm_campaigns` ADD COLUMN `utm` json DEFAULT NULL AFTER `utm_enabled`',
    'SELECT ''edm_campaigns.utm already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_campaigns' AND COLUMN_NAME = 'archived_from') = 0,
    'ALTER TABLE `edm_campaigns` ADD COLUMN `archived_from` tinyint unsigned DEFAULT NULL AFTER `status`',
    'SELECT ''edm_campaigns.archived_from already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- segment_id -> audience { include: { segments: [segment_id] } }, then drop it.
SET @has_segment = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_campaigns' AND COLUMN_NAME = 'segment_id') > 0;
SET @s = IF(@has_segment,
    'UPDATE `edm_campaigns`
        SET `audience` = JSON_OBJECT(''include'', JSON_OBJECT(''segments'', JSON_ARRAY(`segment_id`), ''contacts'', JSON_ARRAY()),
                                    ''exclude'', JSON_OBJECT(''segments'', JSON_ARRAY(), ''contacts'', JSON_ARRAY()))
      WHERE `segment_id` IS NOT NULL AND `audience` IS NULL',
    'SELECT ''edm_campaigns.segment_id already gone''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF(@has_segment,
    'ALTER TABLE `edm_campaigns` DROP COLUMN `segment_id`',
    'SELECT ''edm_campaigns.segment_id already gone''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
