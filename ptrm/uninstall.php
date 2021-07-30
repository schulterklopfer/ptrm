<?php

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

$pmt_table_name = $wpdb->prefix . 'ptrm';
$contentid_table_name = $wpdb->prefix . 'ptrm_contentid';
delete_option( 'ptrm_db_version' );
$wpdb->query( "DROP TABLE $pmt_table_name" );
$wpdb->query( "DROP TABLE $contentid_table_name" );

