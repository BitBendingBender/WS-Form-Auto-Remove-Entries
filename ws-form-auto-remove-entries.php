<?php

/*
 * Plugin Name:       WS Form Auto Remove Entries
 * Plugin URI:        https://github.com/BitBendingBender/WS-Form-Auto-Remove-Entries
 * Description:       Automatically remove entries after a given time.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Laurin Waller
 * Author URI:        https://www.bitbender.ch/
 * License:           MIT License
 * Text Domain:       ws-form-auto-remove-entries
 * Requires Plugins:  WS_Form_PRO
 */

namespace WS_Form_ARE {

    if (!defined('ABSPATH')) exit;

    if (!class_exists('WS_Form_ARE')) {

        class WS_Form_ARE
        {

            const RETENTION_DAYS_DEFAULT = 7;
            const RUN_ALWAYS = false;
            const IN_DRY_MODE = true;

            const LOG_FILE = __DIR__ . '/delete.log';
            const CONFIG_FILE = __DIR__ . '/config.json';
            const PLUGIN_DIR = __DIR__;

            public static ?array $CONFIG = null;

            public static function init()
            {

                if (!file_exists(self::LOG_FILE)) {
                    touch(self::LOG_FILE);
                }

                // write config file first time
                if (!file_exists(self::CONFIG_FILE)) {
                    file_put_contents(self::CONFIG_FILE, json_encode([
                        'retention-days' => self::RETENTION_DAYS_DEFAULT,
                        'run-always' => self::RUN_ALWAYS,
                        'in-dry-mode' => self::IN_DRY_MODE,
                    ]));
                }

                static::loadConfig();

                // Cronjob registrieren
                register_activation_hook(__FILE__, function () {
                    if (!wp_next_scheduled('wsform_cleanup_cron_hook')) {
                        wp_schedule_event(time(), 'daily', 'wsform_cleanup_cron_hook');
                    }
                });

                register_deactivation_hook(__FILE__, function () {
                    wp_clear_scheduled_hook('wsform_cleanup_cron_hook');
                });

                add_action('admin_menu', function() {
                    static::initAdminPages();
                }, 15);

                if (WS_Form_ARE::$CONFIG['run-always']) {
                    static::runCleanupCron();
                } else {
                    add_action('wsform_cleanup_cron_hook', function () {
                        static::runCleanupCron();
                    });
                }
            }

            public static function loadConfig() {
                static::$CONFIG = json_decode(file_get_contents(self::CONFIG_FILE), true);
            }

            public static function saveConfig($config) {
                file_put_contents(self::CONFIG_FILE, json_encode($config));
            }

            public static function initAdminPages() {
                add_submenu_page(
                    'ws-form',
                    'WS From Auto Remove Entries',
                    'WS From Auto Remove Entries',
                    'manage_options_wsform',
                    'ws-form-auto-remove-entries',
                    function() {
                        require static::PLUGIN_DIR . '/pages/configuration.php';
                    }
                );
            }

            public static function runCleanupCron()
            {

                global $wpdb;

                $entry_table = $wpdb->prefix . 'wsf_submit';
                $meta_table = $wpdb->prefix . 'wsf_submit_meta';
                $upload_dir = WP_CONTENT_DIR . '/uploads/';

                $date_limit = date('Y-m-d H:i:s', strtotime('-' . static::$CONFIG['retention-days'] . ' days'));

                // Get All submits that are older.
                $old_entry_ids = $wpdb->get_col(
                    $wpdb->prepare("SELECT id FROM $entry_table WHERE date_added < %s", $date_limit)
                );

                if (empty($old_entry_ids)) {
                    static::prependToLogFile("Keine Einträge gefunden die älter als " . static::$CONFIG['retention-days'] . " sind.");
                    return;
                }

                // get all meta fields, try to find files and in the end delete all of them

                // Hole Dateipfade aus der file-Tabelle
                $placeholders = implode(',', array_fill(0, count($old_entry_ids), '%d'));
                $query = $wpdb->prepare(
                    "SELECT * FROM $meta_table WHERE parent_id IN ($placeholders)",
                    ...$old_entry_ids
                );

                $allMetaEntries = $wpdb->get_results($query);

                $filesdeleted = 0;

                // loop every entry, check if it can be deserialized and if so, print
                foreach ($allMetaEntries as $entry) {
                    if ($metaValue = @unserialize($entry->meta_value)) {

                        if (!is_array($metaValue)) continue;

                        foreach ($metaValue as $metaValueValue) {

                            if (!isset($metaValueValue['path'])) continue;

                            // so appearantly this value exists
                            // log file deletion with timestamp and name of the file deleted
                            static::prependToLogFile("$metaValueValue[name]\t$metaValueValue[path]");

                            $filesdeleted++;

                            if(!static::$CONFIG['in-dry-mode']) {
                                // we suppress warning because we dont care
                                if (!@unlink($upload_dir . $metaValueValue['path'])) {
                                    static::prependToLogFile("Could not Delete File: $metaValueValue[name]");
                                }
                            }

                        }
                    }
                }

                // all meta entries are deleted anyways with the cron running
                foreach ($old_entry_ids as $old_entry_id) {
                    static::prependToLogFile("Deleting Submit Entry: $old_entry_id");
                }

                $placeholders = implode(',', array_fill(0, count($old_entry_ids), '%d'));

                if(!static::$CONFIG['in-dry-mode']) {

                    // first delete all meta values
                    $query = $wpdb->query(
                        $wpdb->prepare("DELETE FROM $meta_table WHERE parent_id IN ($placeholders)", ...$old_entry_ids)
                    );

                    // then delete all entries
                    $query = $wpdb->query(
                        $wpdb->prepare("DELETE FROM $entry_table WHERE id IN ($placeholders)", ...$old_entry_ids)
                    );

                    // cleanup empty folders
                    static::recursiveRemoveEmptySubFolders(substr($upload_dir, 0, -1));

                }

                static::prependToLogFile(count($old_entry_ids) . " entries deleted with $filesdeleted files deleted.");
            }

            public static function prependToLogFile($line)
            {
                $src = fopen(static::LOG_FILE, 'r+');
                $dest = fopen('php://temp', 'w');

                if(static::$CONFIG['in-dry-mode']) {
                    $line = "*DRY MODE*\t" . $line;
                }

                fwrite($dest, date('Y-m-d H:i:s') . "\t" . $line . PHP_EOL);

                stream_copy_to_stream($src, $dest);
                rewind($dest);
                rewind($src);
                stream_copy_to_stream($dest, $src);

                fclose($src);
                fclose($dest);
            }

            public static function recursiveRemoveEmptySubFolders($path)
            {
                $empty = true;
                foreach (glob($path . DIRECTORY_SEPARATOR . "*") as $file) {
                    $empty &= is_dir($file) && static::recursiveRemoveEmptySubFolders($file);
                }
                return $empty && (is_readable($path) && count(scandir($path)) == 2) && rmdir($path);
            }
        }

        WS_Form_ARE::init();

    }
}