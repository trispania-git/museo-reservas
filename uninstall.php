<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mr_bookings");
delete_option('mr_settings');
