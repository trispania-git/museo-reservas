<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mr_bookings");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mr_log");
delete_option('mr_settings');
delete_option('mr_group_attendees');
delete_option('mr_db_version');
