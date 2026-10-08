<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Per-user bearer credentials for REST/MCP access.
 *
 * Tokens are shown once, stored only as HMAC-SHA256 digests and always bound
 * to a WordPress user plus an explicit scope set. Effective authorization is
 * the intersection of: global connector scopes, token scopes and the user's
 * native WordPress capabilities.
 */
final class ALIFY_AI_Access_Tokens {
    private const OPTION_DB_VERSION = 'alify_ai_tokens_db_version';
    private const DB_VERSION = '1.2.0';
    private const TOKEN_PREFIX = 'alify_pat_';
    private const OAUTH_TOKEN_PREFIX = 'alify_oat_';
    private static string $table = '';

    public static function init(): void {
        global $wpdb;
        self::$table = $wpdb->prefix . 'alify_ai_tokens';
        if ( self::DB_VERSION !== (string) get_option( self::OPTION_DB_VERSION, '' ) ) {
            self::activate();
        }
    }

    public static function activate(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'alify_ai_tokens';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            name varchar(120) NOT NULL,
            token_prefix varchar(24) NOT NULL,
            token_hash char(64) NOT NULL,
            token_kind varchar(20) NOT NULL DEFAULT 'personal',
            client_id varchar(96) NULL,
            resource text NULL,
            scopes longtext NOT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NULL,
            last_used_at datetime NULL,
            revoked_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY token_prefix (token_prefix),
            KEY token_kind (token_kind),
            KEY client_id (client_id),
            KEY user_id (user_id),
            KEY revoked_at (revoked_at)
        ) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
        self::$table = $table;
    }

    public static function create( int $user_id, string $name, array $scopes, int $expires_days = 30 ) {
        $name = sanitize_text_field( $name );
        if ( '' === $name ) {
            return new WP_Error( 'alify_ai_token_name_required', 'A token name is required.', array( 'status' => 400 ) );
        }
        $expires_days = max( 1, min( 365, $expires_days ) );
        return self::create_internal( $user_id, $name, $scopes, DAY_IN_SECONDS * $expires_days, self::TOKEN_PREFIX, 'personal' );
    }

    public static function create_oauth( int $user_id, string $name, array $scopes, int $expires_seconds, string $client_id, string $resource ) {
        return self::create_internal( $user_id, $name, $scopes, max( 300, min( DAY_IN_SECONDS, $expires_seconds ) ), self::OAUTH_TOKEN_PREFIX, 'oauth', sanitize_text_field( $client_id ), esc_url_raw( $resource ) );
    }

    private static function create_internal( int $user_id, string $name, array $scopes, int $expires_seconds, string $prefix, string $kind, string $client_id = '', string $resource = '' ) {
        global $wpdb;
        self::ensure_table();
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            return new WP_Error( 'alify_ai_token_user_missing', 'The selected WordPress user does not exist.', array( 'status' => 400 ) );
        }
        $clean_scopes = ALIFY_AI_Auth::sanitize_scopes( $scopes );
        if ( ! array_filter( $clean_scopes ) ) {
            return new WP_Error( 'alify_ai_token_scope_required', 'Enable at least one token permission.', array( 'status' => 400 ) );
        }
        $raw = $prefix . bin2hex( random_bytes( 32 ) );
        $expires = gmdate( 'Y-m-d H:i:s', time() + $expires_seconds );
        $inserted = $wpdb->insert(
            self::$table,
            array(
                'user_id' => $user_id,
                'name' => sanitize_text_field( $name ),
                'token_prefix' => substr( $raw, 0, 20 ),
                'token_hash' => self::hash_token( $raw ),
                'token_kind' => sanitize_key( $kind ),
                'client_id' => '' !== $client_id ? $client_id : null,
                'resource' => '' !== $resource ? $resource : null,
                'scopes' => wp_json_encode( $clean_scopes ),
                'created_at' => current_time( 'mysql', true ),
                'expires_at' => $expires,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
        if ( false === $inserted ) {
            return new WP_Error( 'alify_ai_token_create_failed', 'Could not create the access token.', array( 'status' => 500 ) );
        }
        return array(
            'id' => (int) $wpdb->insert_id,
            'token' => $raw,
            'prefix' => substr( $raw, 0, 20 ),
            'expires_at' => $expires,
            'user_id' => $user_id,
            'scopes' => $clean_scopes,
            'token_kind' => $kind,
            'client_id' => $client_id,
            'resource' => $resource,
        );
    }

    public static function verify( string $raw, string $resource = '' ) {
        global $wpdb;
        self::ensure_table();
        $raw = trim( $raw );
        if ( ( ! str_starts_with( $raw, self::TOKEN_PREFIX ) && ! str_starts_with( $raw, self::OAUTH_TOKEN_PREFIX ) ) || strlen( $raw ) < 40 ) {
            return new WP_Error( 'alify_ai_bearer_invalid', 'Invalid bearer token.', array( 'status' => 401 ) );
        }
        $hash = self::hash_token( $raw );
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::$table . ' WHERE token_hash = %s LIMIT 1', $hash ),
            ARRAY_A
        );
        if ( ! $row || ! hash_equals( (string) $row['token_hash'], $hash ) ) {
            return new WP_Error( 'alify_ai_bearer_invalid', 'Invalid bearer token.', array( 'status' => 401 ) );
        }
        if ( ! empty( $row['revoked_at'] ) ) {
            return new WP_Error( 'alify_ai_bearer_revoked', 'This bearer token has been revoked.', array( 'status' => 401 ) );
        }
        if ( ! empty( $row['expires_at'] ) && strtotime( (string) $row['expires_at'] . ' UTC' ) <= time() ) {
            return new WP_Error( 'alify_ai_bearer_expired', 'This bearer token has expired.', array( 'status' => 401 ) );
        }
        $user_id = absint( $row['user_id'] );
        if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
            return new WP_Error( 'alify_ai_bearer_user_missing', 'The WordPress user for this token no longer exists.', array( 'status' => 401 ) );
        }
        if ( 'oauth' === (string) ( $row['token_kind'] ?? '' ) ) {
            $bound_resource = (string) ( $row['resource'] ?? '' );
            if ( '' === $bound_resource || '' === $resource || ! hash_equals( untrailingslashit( $bound_resource ), untrailingslashit( esc_url_raw( $resource ) ) ) ) {
                return new WP_Error( 'alify_ai_bearer_resource_mismatch', 'This OAuth access token is not valid for the requested resource.', array( 'status' => 401 ) );
            }
        }
        $scopes = json_decode( (string) $row['scopes'], true );
        $scopes = ALIFY_AI_Auth::sanitize_scopes( is_array( $scopes ) ? $scopes : array() );

        // Usage tracking is intentionally best-effort and never blocks auth.
        $wpdb->update(
            self::$table,
            array( 'last_used_at' => current_time( 'mysql', true ) ),
            array( 'id' => absint( $row['id'] ) ),
            array( '%s' ),
            array( '%d' )
        );

        return array(
            'auth_type' => ( 'oauth' === (string) ( $row['token_kind'] ?? 'personal' ) ? 'oauth_bearer' : 'bearer' ),
            'credential_id' => absint( $row['id'] ),
            'credential_name' => (string) $row['name'],
            'credential_prefix' => (string) $row['token_prefix'],
            'oauth_client_id' => (string) ( $row['client_id'] ?? '' ),
            'oauth_resource' => (string) ( $row['resource'] ?? '' ),
            'user_id' => $user_id,
            'scopes' => $scopes,
        );
    }

    public static function revoke( int $id ): bool {
        global $wpdb;
        self::ensure_table();
        if ( $id <= 0 ) {
            return false;
        }
        return false !== $wpdb->update(
            self::$table,
            array( 'revoked_at' => current_time( 'mysql', true ) ),
            array( 'id' => $id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    public static function revoke_raw( string $raw, string $client_id = '' ): bool {
        global $wpdb;
        self::ensure_table();
        $hash = self::hash_token( trim( $raw ) );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,client_id FROM ' . self::$table . ' WHERE token_hash=%s LIMIT 1', $hash ), ARRAY_A );
        if ( ! $row ) {
            return false;
        }
        if ( '' !== $client_id && ! hash_equals( (string) ( $row['client_id'] ?? '' ), $client_id ) ) {
            return false;
        }
        return self::revoke( absint( $row['id'] ) );
    }

    public static function revoke_by_client( string $client_id ): int {
        global $wpdb;
        self::ensure_table();
        $client_id = sanitize_text_field( $client_id );
        if ( '' === $client_id ) {
            return 0;
        }
        $result = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::$table . ' SET revoked_at=%s WHERE client_id=%s AND revoked_at IS NULL',
                current_time( 'mysql', true ),
                $client_id
            )
        );
        return false === $result ? 0 : (int) $result;
    }

    public static function list_tokens( int $limit = 100 ): array {
        global $wpdb;
        self::ensure_table();
        $limit = max( 1, min( 200, $limit ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT id,user_id,name,token_prefix,token_kind,client_id,scopes,created_at,expires_at,last_used_at,revoked_at FROM " . self::$table . " WHERE token_kind='personal' OR token_kind='' OR token_kind IS NULL ORDER BY id DESC LIMIT %d", $limit ),
            ARRAY_A
        );
        foreach ( $rows ?: array() as &$row ) {
            $row['id'] = absint( $row['id'] );
            $row['user_id'] = absint( $row['user_id'] );
            $decoded = json_decode( (string) $row['scopes'], true );
            $row['scopes'] = ALIFY_AI_Auth::sanitize_scopes( is_array( $decoded ) ? $decoded : array() );
        }
        unset( $row );
        return $rows ?: array();
    }

    public static function cleanup_oauth_tokens(): void {
        global $wpdb;
        self::ensure_table();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM " . self::$table . " WHERE token_kind='oauth' AND ((expires_at IS NOT NULL AND expires_at < %s) OR (revoked_at IS NOT NULL AND revoked_at < %s))",
                $cutoff,
                $cutoff
            )
        );
    }

    private static function hash_token( string $raw ): string {
        return hash_hmac( 'sha256', $raw, wp_salt( 'auth' ) );
    }

    private static function ensure_table(): void {
        if ( '' === self::$table ) {
            self::init();
        }
    }
}
