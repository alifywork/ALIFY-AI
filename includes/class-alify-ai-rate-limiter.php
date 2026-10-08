<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Small database-backed fixed-window limiter shared by unauthenticated and MCP
 * endpoints. INSERT ... ON DUPLICATE KEY UPDATE makes increments atomic across
 * concurrent PHP workers, unlike transient get/set counters.
 */
final class ALIFY_AI_Rate_Limiter {
    private const OPTION_DB_VERSION = 'alify_ai_rate_db_version';
    private const DB_VERSION = '1.0.0';
    private static string $table = '';

    public static function init(): void {
        self::ensure_table();
    }

    public static function activate(): void {
        self::ensure_table( true );
    }

    public static function consume( string $namespace, string $identity, int $limit, int $window_seconds ) {
        global $wpdb;
        self::ensure_table();
        $limit = max( 1, $limit );
        $window_seconds = max( 1, $window_seconds );
        $window = (int) floor( time() / $window_seconds );
        $bucket_key = hash( 'sha256', sanitize_key( $namespace ) . '|' . $identity . '|' . $window . '|' . $window_seconds );
        $expires = gmdate( 'Y-m-d H:i:s', ( ( $window + 1 ) * $window_seconds ) + 60 );

        $sql = $wpdb->prepare(
            'INSERT INTO ' . self::$table . ' (bucket_key,hits,expires_at) VALUES (%s,1,%s) ON DUPLICATE KEY UPDATE hits=hits+1,expires_at=VALUES(expires_at)',
            $bucket_key,
            $expires
        );
        if ( false === $wpdb->query( $sql ) ) {
            ALIFY_AI_Diagnostics::log( 'rate_limit_db_failure', array( 'namespace' => $namespace, 'db_error' => (string) $wpdb->last_error ) );
            return new WP_Error( 'alify_ai_rate_limit_unavailable', 'Rate-limit state could not be stored. Retry shortly.', array( 'status' => 503 ) );
        }
        $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM ' . self::$table . ' WHERE bucket_key=%s', $bucket_key ) );
        if ( $count > $limit ) {
            return new WP_Error( 'alify_ai_rate_limited', 'Too many requests. Try again shortly.', array( 'status' => 429, 'retry_after' => $window_seconds ) );
        }
        return true;
    }

    public static function cleanup(): int {
        global $wpdb;
        self::ensure_table();
        $deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::$table . ' WHERE expires_at < %s', current_time( 'mysql', true ) ) );
        return false === $deleted ? 0 : (int) $deleted;
    }

    public static function table_name(): string {
        self::ensure_table();
        return self::$table;
    }

    private static function ensure_table( bool $force = false ): void {
        global $wpdb;
        if ( '' === self::$table ) {
            self::$table = $wpdb->prefix . 'alify_ai_rate_limits';
        }
        if ( ! $force && self::DB_VERSION === (string) get_option( self::OPTION_DB_VERSION, '' ) ) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE " . self::$table . " (
            bucket_key char(64) NOT NULL,
            hits int(10) unsigned NOT NULL DEFAULT 0,
            expires_at datetime NOT NULL,
            PRIMARY KEY (bucket_key),
            KEY expires_at (expires_at)
        ) {$charset};" );
        update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
    }
}
