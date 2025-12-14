-- Template Application Installation SQL
-- 模板应用安装 SQL

-- Example table creation (modify as needed)
-- 示例表创建（根据需要修改）

-- CREATE TABLE IF NOT EXISTS `template_items` (
--     `id` bigint unsigned NOT NULL AUTO_INCREMENT,
--     `tenant_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT 'Tenant ID',
--     `site_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT 'Site ID',
--     `name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Item name',
--     `description` text COMMENT 'Item description',
--     `status` tinyint NOT NULL DEFAULT 1 COMMENT 'Status: 0=disabled, 1=enabled',
--     `created_at` datetime DEFAULT NULL COMMENT 'Created time',
--     `updated_at` datetime DEFAULT NULL COMMENT 'Updated time',
--     PRIMARY KEY (`id`),
--     KEY `idx_tenant_site` (`tenant_id`, `site_id`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Template items';

