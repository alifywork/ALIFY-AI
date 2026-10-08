<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ALIFY_AI_Audit {
    private const OPTION_DB_VERSION = 'alify_ai_audit_db_version';
    private const DB_VERSION = '1.2.0';
    private const DEFAULT_RETENTION_DAYS = 90;
    private const MAX_SNAPSHOT_BYTES = 2097152; // 2 MiB combined before/after JSON.

    private static string $table = '';

    public static function init(): void {
        global $wpdb;
        self::$table = $wpdb->prefix . 'alify_ai_activity';
        if ( self::DB_VERSION !== (string) get_option( self::OPTION_DB_VERSION, '' ) ) {
            self::activate();
        }
    }

    public static function activate(): void {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'alify_ai_activity';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            action varchar(50) NOT NULL,
            object_type varchar(50) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            actor_auth_type varchar(40) NOT NULL DEFAULT 'system',
            actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            credential_id bigint(20) unsigned NOT NULL DEFAULT 0,
            credential_prefix varchar(32) NOT NULL DEFAULT '',
            before_json longtext NULL,
            after_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY object_lookup (object_type, object_id),
            KEY actor_user_id (actor_user_id),
            KEY credential_id (credential_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
    }

    public static function max_snapshot_bytes(): int {
        return self::MAX_SNAPSHOT_BYTES;
    }

    /**
     * Validate that a rollback snapshot is JSON serializable and bounded before
     * a write occurs. This prevents large Elementor/Gutenberg documents from
     * exhausting DB/memory while still promising rollback support.
     */
    public static function validate_snapshot( $before = null, $after = null ) {
        $before_json = self::encode_snapshot( $before );
        if ( is_wp_error( $before_json ) ) {
            return $before_json;
        }
        $after_json = self::encode_snapshot( $after );
        if ( is_wp_error( $after_json ) ) {
            return $after_json;
        }

        $bytes = strlen( (string) $before_json ) + strlen( (string) $after_json );
        if ( $bytes > self::MAX_SNAPSHOT_BYTES ) {
            return new WP_Error(
                'alify_ai_snapshot_too_large',
                'This change is too large for safe rollback logging. Split the change into smaller operations.',
                array( 'status' => 413, 'bytes' => $bytes, 'max_bytes' => self::MAX_SNAPSHOT_BYTES )
            );
        }
        return true;
    }

    public static function log( string $action, string $object_type, int $object_id, $before = null, $after = null ): int {
        global $wpdb;

        if ( '' === self::$table ) {
            self::init();
        }

        $before_json = self::encode_snapshot( $before );
        $after_json  = self::encode_snapshot( $after );
        if ( is_wp_error( $before_json ) || is_wp_error( $after_json ) ) {
            ALIFY_AI_Diagnostics::log( 'audit_snapshot_encode_failed', array( 'action' => $action, 'object_type' => $object_type, 'object_id' => $object_id ) );
            return 0;
        }
        if ( strlen( (string) $before_json ) + strlen( (string) $after_json ) > self::MAX_SNAPSHOT_BYTES ) {
            ALIFY_AI_Diagnostics::log( 'audit_snapshot_too_large', array( 'action' => $action, 'object_type' => $object_type, 'object_id' => $object_id ) );
            return 0;
        }

        $identity = ALIFY_AI_Auth::audit_identity();
        $inserted = $wpdb->insert(
            self::$table,
            array(
                'action'            => sanitize_key( $action ),
                'object_type'       => sanitize_key( $object_type ),
                'object_id'         => absint( $object_id ),
                'actor_auth_type'   => sanitize_key( (string) $identity['auth_type'] ),
                'actor_user_id'     => absint( $identity['user_id'] ),
                'credential_id'     => absint( $identity['credential_id'] ),
                'credential_prefix' => sanitize_text_field( (string) $identity['credential_prefix'] ),
                'before_json'       => $before_json,
                'after_json'        => $after_json,
                'created_at'        => current_time( 'mysql', true ),
            ),
            array( '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
        );

        if ( false === $inserted ) {
            ALIFY_AI_Diagnostics::log( 'audit_insert_failed', array( 'action' => $action, 'object_type' => $object_type, 'object_id' => $object_id, 'db_error' => (string) $wpdb->last_error ) );
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Recent activity intentionally omits snapshot blobs. Rollback/details use
     * get(), so admin/MCP listing stays small even after large site edits.
     */
    public static function recent( int $limit = 50 ): array {
        global $wpdb;

        if ( '' === self::$table ) {
            self::init();
        }

        $limit = max( 1, min( 100, $limit ) );
        $rows  = $wpdb->get_results(
            $wpdb->prepare( "SELECT id, action, object_type, object_id, actor_auth_type, actor_user_id, credential_id, credential_prefix, created_at FROM " . self::$table . " ORDER BY id DESC LIMIT %d", $limit ),
            ARRAY_A
        );

        return array_map(
            static function ( array $row ): array {
                $row['id']            = (int) $row['id'];
                $row['object_id']     = (int) $row['object_id'];
                $row['actor_user_id'] = (int) ( $row['actor_user_id'] ?? 0 );
                $row['credential_id'] = (int) ( $row['credential_id'] ?? 0 );
                return $row;
            },
            $rows ?: array()
        );
    }

    public static function get( int $id ): ?array {
        global $wpdb;

        if ( '' === self::$table ) {
            self::init();
        }

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::$table . " WHERE id = %d", $id ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        $row['id']          = (int) $row['id'];
        $row['object_id']   = (int) $row['object_id'];
        $row['before_json'] = self::decode_snapshot( $row['before_json'] );
        $row['after_json']  = self::decode_snapshot( $row['after_json'] );
        return $row;
    }

    /**
     * Remove a just-created audit row when the guarded destructive operation
     * itself fails. This is intentionally narrow and is not exposed over REST.
     */
    public static function delete_entry( int $id ): bool {
        global $wpdb;
        if ( '' === self::$table ) {
            self::init();
        }
        if ( $id <= 0 ) {
            return false;
        }
        return false !== $wpdb->delete( self::$table, array( 'id' => $id ), array( '%d' ) );
    }

    public static function cleanup_old( int $days = self::DEFAULT_RETENTION_DAYS ): int {
        global $wpdb;
        if ( '' === self::$table ) {
            self::init();
        }
        $days = max( 7, min( 3650, $days ) );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
        $deleted = $wpdb->query(
            $wpdb->prepare( 'DELETE FROM ' . self::$table . ' WHERE created_at < %s', $cutoff )
        );
        return false === $deleted ? 0 : (int) $deleted;
    }

    private static function encode_snapshot( $value ) {
        if ( null === $value ) {
            return null;
        }
        $encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $encoded ) {
            return new WP_Error( 'alify_ai_snapshot_encode_failed', 'Could not serialize rollback snapshot.', array( 'status' => 500 ) );
        }
        return $encoded;
    }

    private static function decode_snapshot( $value ) {
        if ( null === $value || '' === $value ) {
            return null;
        }
        $decoded = json_decode( (string) $value, true );
        return JSON_ERROR_NONE === json_last_error() ? $decoded : null;
    }
}
