<?php
/**
 * Deleting the plugin deletes the carts it recorded.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i', $wpdb->prefix . 'oobj_ac_carts', $wpdb->prefix . 'oobj_ac_saved' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
delete_option( 'oobj_ac_db_version' );
