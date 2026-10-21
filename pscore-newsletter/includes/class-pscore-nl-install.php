<?php

defined('ABSPATH') || exit;

final class PSCORE_NL_Install {
    public static function maybe_upgrade(): void {
        if (get_option('pscore_nl_db_version') !== PSCORE_NL_VERSION) {
            self::create_tables();
        }
    }

    public static function activate(): void {
        self::create_tables();
        self::create_defaults();
        PSCORE_NL_Scheduler::ensure_schedule();
        flush_rewrite_rules(false);
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(PSCORE_NL_Scheduler::HOOK);
        delete_transient(PSCORE_NL_Scheduler::LOCK);
    }

    private static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $sql = [];
        $sql[] = 'CREATE TABLE ' . PSCORE_NL_Plugin::table('lists') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            description text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $charset;";

        $sql[] = 'CREATE TABLE ' . PSCORE_NL_Plugin::table('subscribers') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(190) NOT NULL,
            name varchar(190) NOT NULL DEFAULT '',
            first_name varchar(190) NOT NULL DEFAULT '',
            last_name varchar(190) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            confirm_token varchar(64) NOT NULL,
            unsubscribe_token varchar(64) NOT NULL,
            created_at datetime NOT NULL,
            confirmed_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            UNIQUE KEY confirm_token (confirm_token),
            UNIQUE KEY unsubscribe_token (unsubscribe_token)
        ) $charset;";

        $sql[] = 'CREATE TABLE ' . PSCORE_NL_Plugin::table('subscriber_lists') . " (
            subscriber_id bigint(20) unsigned NOT NULL,
            list_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL,
            PRIMARY KEY (subscriber_id, list_id),
            KEY list_status (list_id, status)
        ) $charset;";

        $sql[] = 'CREATE TABLE ' . PSCORE_NL_Plugin::table('forms') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            title varchar(255) NOT NULL DEFAULT '',
            description text NOT NULL,
            button_label varchar(100) NOT NULL DEFAULT 'Subscribe',
            success_message text NOT NULL,
            show_name tinyint(1) NOT NULL DEFAULT 1,
            name_mode varchar(20) NOT NULL DEFAULT 'full',
            email_label varchar(100) NOT NULL DEFAULT 'Email',
            full_name_label varchar(100) NOT NULL DEFAULT 'Full name',
            first_name_label varchar(100) NOT NULL DEFAULT 'First name',
            last_name_label varchar(100) NOT NULL DEFAULT 'Last name',
            list_ids text NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id)
        ) $charset;";

        $sql[] = 'CREATE TABLE ' . PSCORE_NL_Plugin::table('queue') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) unsigned NOT NULL,
            subscriber_id bigint(20) unsigned NOT NULL,
            run_key bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            scheduled_at bigint(20) unsigned NOT NULL,
            sent_at bigint(20) unsigned NOT NULL DEFAULT 0,
            error text NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY campaign_recipient_run (campaign_id, subscriber_id, run_key),
            KEY pending (status, scheduled_at)
        ) $charset;";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }
        update_option('pscore_nl_db_version', PSCORE_NL_VERSION, false);
    }

    private static function create_defaults(): void {
        global $wpdb;
        $lists = PSCORE_NL_Plugin::table('lists');
        if (!(int) $wpdb->get_var("SELECT COUNT(*) FROM $lists")) {
            $wpdb->insert($lists, [
                'name' => 'Newsletter',
                'slug' => 'newsletter',
                'description' => 'General newsletter',
                'created_at' => current_time('mysql'),
            ]);
        }

        $forms = PSCORE_NL_Plugin::table('forms');
        if (!(int) $wpdb->get_var("SELECT COUNT(*) FROM $forms")) {
            $list_id = (int) $wpdb->get_var("SELECT id FROM $lists ORDER BY id ASC LIMIT 1");
            $wpdb->insert($forms, [
                'name' => 'Default subscription form',
                'title' => 'Subscribe to our newsletter',
                'description' => 'Receive news and updates by email.',
                'button_label' => 'Subscribe',
                'success_message' => 'Please check your inbox and confirm your subscription.',
                'show_name' => 1,
                'name_mode' => 'full',
                'email_label' => 'Email',
                'full_name_label' => 'Full name',
                'first_name_label' => 'First name',
                'last_name_label' => 'Last name',
                'list_ids' => wp_json_encode([$list_id]),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
        }
    }
}

