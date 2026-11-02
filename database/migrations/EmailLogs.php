<?php

namespace FluentMailMigrations;

class EmailLogs
{
    /**
     * Version of the log-table schema this build expects. Stored in an option
     * so the queue's per-minute ensureSchema() is an option read, not a set of
     * SHOW COLUMNS / SHOW INDEX queries on every run.
     */
    const SCHEMA_VERSION = '2026-11-02-retry-state';

    /**
     * Migrate the table.
     *
     * @return void
     */
    public static function migrate()
    {
        global $wpdb;

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . FLUENT_MAIL_DB_PREFIX.'email_logs';

        // Table name is static/hard-coded, so wpdb->prepare() is sufficient
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table) {
            $sql = "CREATE TABLE $table (
                `id` INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `site_id` INT UNSIGNED NULL,
                `to` TEXT NULL,
                `from` VARCHAR(255),
                `subject` VARCHAR(255),
                `body` LONGTEXT NULL,
                `headers` LONGTEXT NULL,
                `attachments` LONGTEXT NULL,
                `status` VARCHAR(20) DEFAULT 'pending',
                `response` TEXT NULL,
                `extra` TEXT NULL,
                `retries` INT UNSIGNED NULL DEFAULT 0,
                `auto_retries` TINYINT UNSIGNED NULL DEFAULT 0,
                `resent_count` INT UNSIGNED NULL DEFAULT 0,
                `source` VARCHAR(255) NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                INDEX `created_at_status` (`created_at`, `status`),
                INDEX `status_updated_at` (`status`, `updated_at`),
                INDEX `status_auto_retries_updated_at` (`status`, `auto_retries`, `updated_at`)
            ) $charsetCollate;";

            dbDelta($sql);
        } else {
            self::maybeUpgradeColumns($table);
            self::maybeUpgradeIndexes($table);
            self::maybeUpgradeQueueIndex($table);
        }

        if (
            self::columnType($table, 'auto_retries') !== null
            && self::hasIndex($table, 'status_updated_at')
            && self::hasIndex($table, 'status_auto_retries_updated_at')
        ) {
            update_option('fluentmail_db_schema', self::SCHEMA_VERSION, false);
        }
    }

    /**
     * Add the automatic-retry counter to tables created before the email queue.
     *
     * Kept separate from maybeUpgradeIndexes() so index-focused callers and
     * tests only touch indexes. Nullable with a zero default, so the ALTER is
     * safe for rows written by older releases.
     *
     * @param string $table
     * @return bool
     */
    public static function maybeUpgradeColumns($table)
    {
        global $wpdb;

        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        if (self::columnType($table, 'auto_retries') === null) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `auto_retries` TINYINT UNSIGNED NULL DEFAULT 0");
        }

        return self::columnType($table, 'auto_retries') !== null;
    }

    /**
     * Add the queue's status/last-attempt and retry-stage indexes to existing
     * tables.
     *
     * The queue scans pending rows, the rate limiter counts recently attempted
     * rows, and the retry query narrows on the `auto_retries` stage; all of
     * them filter on status first and then on updated_at.
     *
     * @param string $table
     * @return void
     */
    public static function maybeUpgradeQueueIndex($table)
    {
        global $wpdb;

        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return;
        }

        if (!self::hasIndex($table, 'status_updated_at')) {
            $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `status_updated_at` (`status`, `updated_at`)");
        }

        if (!self::hasIndex($table, 'status_auto_retries_updated_at')) {
            $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `status_auto_retries_updated_at` (`status`, `auto_retries`, `updated_at`)");
        }
    }

    /**
     * Bring an existing site's log table up to the current schema.
     *
     * Checked while the plugin loads; a site that has already finished the
     * upgrade returns early.
     *
     * @param string|null $table
     * @return bool
     */
    public static function maybeUpgradeSchema($table = null)
    {
        global $wpdb;

        if (get_option('fluentmail_db_schema') === self::SCHEMA_VERSION) {
            return true;
        }

        $table = $table ?: $wpdb->prefix . FLUENT_MAIL_DB_PREFIX . 'email_logs';

        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        // Table name is static/hard-coded, so wpdb->prepare() is sufficient
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table) {
            self::migrate();
            return get_option('fluentmail_db_schema') === self::SCHEMA_VERSION;
        }

        $columnsOk = self::maybeUpgradeColumns($table);
        if (!$columnsOk) {
            return false;
        }

        self::maybeUpgradeIndexes($table);
        self::maybeUpgradeQueueIndex($table);

        if (self::hasIndex($table, 'status_updated_at') && self::hasIndex($table, 'status_auto_retries_updated_at')) {
            update_option('fluentmail_db_schema', self::SCHEMA_VERSION, false);
            return true;
        }

        return false;
    }

    /**
     * Replace the old single-column `status` index with one that leads on
     * `created_at`.
     *
     * Every query against this table constrains a date range - the dashboard
     * counters pair it with a status, while the report chart, the day/time
     * heatmap and the pruning cron filter on the date alone. Leading with
     * `created_at` therefore serves all of them from one index. Leading with
     * `status` would only serve the first group, and barely: a log is
     * overwhelmingly 'sent', so that column narrows almost nothing.
     *
     * Runs once on activation, on a new site or on a database version upgrade,
     * and never again after it completes.
     *
     * @param string $table
     * @return void
     */
    private static function maybeUpgradeIndexes($table)
    {
        global $wpdb;

        if (!self::hasIndex($table, 'created_at_status')) {
            $wpdb->query("ALTER TABLE $table ADD INDEX `created_at_status` (`created_at`, `status`)");
        }

        /*
         * Dropped last, and only once the replacement is confirmed present, so
         * the table is never left with no index covering `status` at all.
         */
        if (self::hasIndex($table, 'status') && self::hasIndex($table, 'created_at_status')) {
            $wpdb->query("ALTER TABLE $table DROP INDEX `status`");
        }
    }

    /**
     * Widen `to` from VARCHAR(255) to TEXT.
     *
     * Recipients are stored as a PHP-serialized array of `{email, name}` rows.
     * Five typical addresses already exceed 255 characters, MySQL silently
     * clips the blob, `is_serialized()` then rejects it, and the log UI prints
     * the truncated `a:7:{i:0;a:1:{s:5:"email";...` string. Sending is
     * unaffected because the provider reads the in-memory list.
     *
     * Existing sites are widened only when "delete all logs" has just emptied
     * the table (Logger::delete()), where the ALTER is instant. Changing a
     * column type rebuilds the table and blocks writes while it runs, so it
     * must never run on a page load or on activation against a table that can
     * hold millions of rows. Idempotent: a TEXT/BLOB column is left alone.
     *
     * @param string $table
     * @return bool True when `to` can hold a serialized recipient list.
     */
    public static function maybeWidenToColumn($table)
    {
        global $wpdb;

        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        $type = self::columnType($table, 'to');

        if ($type === null) {
            return false;
        }

        if (self::isUnboundedStringType($type)) {
            return true;
        }

        $wpdb->query("ALTER TABLE `{$table}` MODIFY `to` TEXT NULL");

        $type = self::columnType($table, 'to');

        return $type !== null && self::isUnboundedStringType($type);
    }

    /**
     * @param string $table
     * @param string $column
     * @return string|null Lowercased MySQL type, or null when the column is missing.
     */
    private static function columnType($table, $column)
    {
        global $wpdb;

        if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column),
            ARRAY_A
        );

        if (!is_array($row) || empty($row['Type'])) {
            return null;
        }

        return strtolower((string)$row['Type']);
    }

    /**
     * @param string $type Lowercased MySQL column type from SHOW COLUMNS.
     * @return bool
     */
    private static function isUnboundedStringType($type)
    {
        return (bool)preg_match('/(text|blob)/', $type);
    }

    /**
     * @param string $table
     * @param string $indexName
     * @return bool
     */
    private static function hasIndex($table, $indexName)
    {
        global $wpdb;

        // Table name is static/hard-coded, so wpdb->prepare() is sufficient
        return (bool)$wpdb->get_var(
            $wpdb->prepare("SHOW INDEX FROM $table WHERE Key_name = %s", $indexName)
        );
    }
}
