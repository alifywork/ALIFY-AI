<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( ! (bool) get_option( 'alify_ai_remove_data_on_uninstall', false ) ) {
    return;
}

global $wpdb;

wp_clear_scheduled_hook( 'alify_ai_daily_cleanup' );

$tables = array(
    $wpdb->prefix . 'alify_ai_activity',
    $wpdb->prefix . 'alify_ai_approvals',
    $wpdb->prefix . 'alify_ai_tokens',
    $wpdb->prefix . 'alify_ai_oauth_clients',
    $wpdb->prefix . 'alify_ai_oauth_codes',
    $wpdb->prefix . 'alify_ai_oauth_refresh_tokens',
    $wpdb->prefix . 'alify_ai_rate_limits',
);
foreach ( $tables as $table ) {
    $wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

$options = array(
    'alify_ai_api_key_hash',
    'alify_ai_api_key_prefix',
    'alify_ai_scopes',
    'alify_ai_mcp_connection_token',
    'alify_ai_mcp_disabled',
    'alify_ai_approvals_db_version',
    'alify_ai_audit_db_version',
    'alify_ai_tokens_db_version',
    'alify_ai_oauth_db_version',
    'alify_ai_rate_db_version',
    'alify_ai_design_policy',
    'alify_ai_commerce_policy',
    'alify_ai_transaction_policy',
    'alify_ai_managed_post_types',
    'alify_ai_managed_taxonomies',
    'alify_ai_diagnostics_enabled',
    'alify_ai_remove_data_on_uninstall',
);
foreach ( $options as $option ) {
    delete_option( $option );
    delete_site_option( $option );
}

// Remove connector-owned transient rows without touching unrelated WordPress data.
$prefixes = array(
    '_transient_alify_ai_',
    '_transient_timeout_alify_ai_',
    '_site_transient_alify_ai_',
    '_site_transient_timeout_alify_ai_',
);
foreach ( $prefixes as $prefix ) {
    $pattern = $wpdb->esc_like( $prefix ) . '%';
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
}

// Remove connector-created protected media backups only after explicit opt-in.
$root = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'alify-ai-private' );
$content = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) );
if ( str_starts_with( trailingslashit( $root ), $content ) && is_dir( $root ) ) {
    $delete_tree = static function ( string $path ) use ( &$delete_tree, $root ): void {
        $path = wp_normalize_path( $path );
        if ( ! str_starts_with( trailingslashit( $path ), trailingslashit( $root ) ) ) {
            return;
        }
        if ( is_dir( $path ) ) {
            foreach ( scandir( $path ) ?: array() as $entry ) {
                if ( '.' === $entry || '..' === $entry ) {
                    continue;
                }
                $delete_tree( trailingslashit( $path ) . $entry );
            }
            @rmdir( $path );
        } elseif ( is_file( $path ) || is_link( $path ) ) {
            @unlink( $path );
        }
    };
    $delete_tree( $root );
}
