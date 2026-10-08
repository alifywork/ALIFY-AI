<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * OAuth 2.1 authorization-code + PKCE server for public MCP clients.
 *
 * Deliberately supports public clients only. No client secret is issued or
 * accepted. Authorization codes are one-time, short lived and bound to a
 * S256 PKCE challenge. Refresh tokens rotate on every successful use.
 */
final class ALIFY_AI_OAuth {
    private const NS = 'alify-ai/v1';
    private const OPTION_DB_VERSION = 'alify_ai_oauth_db_version';
    private const DB_VERSION = '1.1.0';
    private const CODE_TTL = 300;
    private const ACCESS_TTL = 3600;
    private const REFRESH_TTL = 2592000; // 30 days.
    private const CLIENT_REG_LIMIT = 20;

    private static string $clients_table = '';
    private static string $codes_table = '';
    private static string $refresh_table = '';

    public static function init(): void {
        self::set_tables();
        if ( self::DB_VERSION !== (string) get_option( self::OPTION_DB_VERSION, '' ) ) {
            self::activate();
        }
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'admin_post_alify_ai_oauth_authorize', array( __CLASS__, 'handle_authorize' ) );
        add_action( 'admin_post_nopriv_alify_ai_oauth_authorize', array( __CLASS__, 'handle_authorize' ) );
        add_action( 'parse_request', array( __CLASS__, 'serve_well_known' ), 1 );
    }

    public static function activate(): void {
        global $wpdb;
        self::set_tables();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE " . self::$clients_table . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_id varchar(96) NOT NULL,
            client_name varchar(160) NOT NULL,
            client_id_issued_at bigint(20) unsigned NOT NULL DEFAULT 0,
            redirect_uris longtext NOT NULL,
            created_at datetime NOT NULL,
            last_used_at datetime NULL,
            revoked_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY client_id (client_id),
            KEY revoked_at (revoked_at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE " . self::$codes_table . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code_hash char(64) NOT NULL,
            client_id varchar(96) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            redirect_uri text NOT NULL,
            resource text NOT NULL,
            scopes longtext NOT NULL,
            code_challenge varchar(128) NOT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            consumed_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code_hash (code_hash),
            KEY client_id (client_id),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE " . self::$refresh_table . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_hash char(64) NOT NULL,
            token_prefix varchar(24) NOT NULL,
            client_id varchar(96) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            resource text NOT NULL,
            scopes longtext NOT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            last_used_at datetime NULL,
            revoked_at datetime NULL,
            replaced_by bigint(20) unsigned NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            KEY client_id (client_id),
            KEY user_id (user_id),
            KEY revoked_at (revoked_at),
            KEY expires_at (expires_at)
        ) {$charset};" );

        update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
    }

    private static function set_tables(): void {
        global $wpdb;
        self::$clients_table = $wpdb->prefix . 'alify_ai_oauth_clients';
        self::$codes_table = $wpdb->prefix . 'alify_ai_oauth_codes';
        self::$refresh_table = $wpdb->prefix . 'alify_ai_oauth_refresh_tokens';
    }

    public static function register_routes(): void {
        register_rest_route( self::NS, '/oauth/register', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( __CLASS__, 'register_client' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( self::NS, '/oauth/token', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( __CLASS__, 'token' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( self::NS, '/oauth/revoke', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( __CLASS__, 'revoke' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( self::NS, '/oauth/metadata', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => static fn() => rest_ensure_response( self::authorization_server_metadata() ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( self::NS, '/oauth/protected-resource', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => static fn() => rest_ensure_response( self::protected_resource_metadata() ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function serve_well_known( $wp ): void {
        $path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
        $path = '/' . ltrim( $path, '/' );
        $base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        $base = '/' . trim( $base, '/' );
        if ( '/' === $base ) {
            $base = '';
        }
        $map = array(
            $base . '/.well-known/oauth-authorization-server' => self::authorization_server_metadata(),
            $base . '/.well-known/oauth-protected-resource' => self::protected_resource_metadata(),
        );
        if ( ! isset( $map[ $path ] ) ) {
            return;
        }
        nocache_headers();
        header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
        echo wp_json_encode( $map[ $path ], JSON_UNESCAPED_SLASHES );
        exit;
    }

    public static function authorization_server_metadata(): array {
        return array(
            'issuer' => home_url( '/' ),
            'authorization_endpoint' => self::authorization_endpoint(),
            'token_endpoint' => rest_url( self::NS . '/oauth/token' ),
            'registration_endpoint' => rest_url( self::NS . '/oauth/register' ),
            'revocation_endpoint' => rest_url( self::NS . '/oauth/revoke' ),
            'response_types_supported' => array( 'code' ),
            'grant_types_supported' => array( 'authorization_code', 'refresh_token' ),
            'code_challenge_methods_supported' => array( 'S256' ),
            'token_endpoint_auth_methods_supported' => array( 'none' ),
            'scopes_supported' => self::advertised_scopes(),
            'resource_indicators_supported' => true,
        );
    }

    public static function protected_resource_metadata(): array {
        return array(
            'resource' => ALIFY_AI_MCP::get_public_mcp_base(),
            'authorization_servers' => array( home_url( '/' ) ),
            'bearer_methods_supported' => array( 'header' ),
            'scopes_supported' => self::advertised_scopes(),
        );
    }

    public static function authorization_endpoint(): string {
        return admin_url( 'admin-post.php?action=alify_ai_oauth_authorize' );
    }

    public static function register_client( WP_REST_Request $request ) {
        global $wpdb;
        $registration_rate = self::allow_registration_request();
        if ( is_wp_error( $registration_rate ) ) {
            $data = $registration_rate->get_error_data();
            $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 429;
            return self::oauth_error( 429 === $status ? 'rate_limit_exceeded' : 'temporarily_unavailable', $registration_rate->get_error_message(), $status );
        }
        $name = sanitize_text_field( (string) $request->get_param( 'client_name' ) );
        $uris = $request->get_param( 'redirect_uris' );
        if ( '' === $name ) {
            $name = 'OAuth client';
        }
        if ( ! is_array( $uris ) || ! $uris ) {
            return self::oauth_error( 'invalid_client_metadata', 'redirect_uris must be a non-empty array.', 400 );
        }
        $clean = array();
        foreach ( array_slice( $uris, 0, 10 ) as $uri ) {
            $valid = self::validate_redirect_uri( (string) $uri );
            if ( is_wp_error( $valid ) ) {
                return self::oauth_error( 'invalid_redirect_uri', $valid->get_error_message(), 400 );
            }
            $clean[] = $valid;
        }
        $clean = array_values( array_unique( $clean ) );
        $client_id = 'alify_oauth_' . bin2hex( random_bytes( 18 ) );
        $ok = $wpdb->insert( self::$clients_table, array(
            'client_id' => $client_id,
            'client_id_issued_at' => time(),
            'client_name' => substr( $name, 0, 160 ),
            'redirect_uris' => wp_json_encode( $clean ),
            'created_at' => current_time( 'mysql', true ),
        ), array( '%s', '%d', '%s', '%s', '%s' ) );
        if ( false === $ok ) {
            return self::oauth_error( 'server_error', 'Could not register OAuth client.', 500 );
        }
        return new WP_REST_Response( array(
            'client_id' => $client_id,
            'client_id_issued_at' => time(),
            'client_name' => $name,
            'redirect_uris' => $clean,
            'token_endpoint_auth_method' => 'none',
            'grant_types' => array( 'authorization_code', 'refresh_token' ),
            'response_types' => array( 'code' ),
        ), 201 );
    }

    public static function handle_authorize(): void {
        $params = 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ? wp_unslash( $_POST ) : wp_unslash( $_GET );
        $validated = self::validate_authorize_request( $params );
        if ( is_wp_error( $validated ) ) {
            self::render_error( $validated->get_error_message(), 400 );
        }
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( self::current_authorize_url() ) );
            exit;
        }
        $user_id = get_current_user_id();
        $requested_effective = self::effective_scopes_for_user( $user_id, $validated['scopes'] );
        if ( is_wp_error( $requested_effective ) ) {
            self::redirect_authorize_error( $validated, 'access_denied', $requested_effective->get_error_message() );
        }

        // ALIFY uses an administrator-managed permission policy. ChatGPT can
        // retain an older OAuth scope request (for example `read content`)
        // after more connector permissions are enabled. During an explicit
        // authorization/reauthorization we therefore present the signed-in
        // user with every currently enabled permission their WordPress account
        // can exercise. The user must approve that complete grant on the
        // consent screen. Refresh-token rotation never expands the grant.
        $managed = self::managed_scopes_for_user( $user_id, $requested_effective );
        if ( is_wp_error( $managed ) ) {
            self::redirect_authorize_error( $validated, 'access_denied', $managed->get_error_message() );
        }
        $validated['requested_scopes'] = $requested_effective;
        $validated['scopes'] = $managed;
        $validated['scope']  = implode( ' ', $managed );
        $validated['scope_expansion'] = array_values( array_diff( $managed, $requested_effective ) );

        if ( $validated['scope_expansion'] ) {
            ALIFY_AI_Diagnostics::log( 'oauth_managed_scope_expansion', array(
                'client_id_prefix' => substr( (string) $validated['client_id'], 0, 20 ),
                'user_id' => $user_id,
                'requested' => array_values( $requested_effective ),
                'added_by_policy' => $validated['scope_expansion'],
                'granted' => array_values( $managed ),
            ) );
        }

        if ( 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
            self::render_consent( $validated );
        }
        if ( ! isset( $params['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) $params['_wpnonce'] ), 'alify_ai_oauth_consent' ) ) {
            self::render_error( 'The OAuth consent request expired. Start authorization again.', 403 );
        }
        $decision = sanitize_key( (string) ( $params['decision'] ?? '' ) );
        if ( 'approve' !== $decision ) {
            self::redirect_authorize_error( $validated, 'access_denied', 'The user denied this authorization request.' );
        }
        $code = self::issue_code( $validated, $user_id );
        if ( is_wp_error( $code ) ) {
            self::redirect_authorize_error( $validated, 'server_error', $code->get_error_message() );
        }
        $location = add_query_arg( array_filter( array(
            'code' => $code,
            'state' => $validated['state'],
        ), static fn( $v ) => '' !== $v ), $validated['redirect_uri'] );
        wp_redirect( $location, 302, 'ALIFY OAuth' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit;
    }

    private static function validate_authorize_request( array $params ) {
        $client_id = sanitize_text_field( (string) ( $params['client_id'] ?? '' ) );
        $redirect_uri = esc_url_raw( (string) ( $params['redirect_uri'] ?? '' ) );
        $response_type = sanitize_key( (string) ( $params['response_type'] ?? '' ) );
        $challenge = trim( (string) ( $params['code_challenge'] ?? '' ) );
        $method = strtoupper( trim( (string) ( $params['code_challenge_method'] ?? '' ) ) );
        $state = substr( (string) ( $params['state'] ?? '' ), 0, 512 );
        if ( preg_match( '/[\x00-\x1F\x7F]/', $state ) ) {
            return new WP_Error( 'invalid_state', 'OAuth state contains invalid control characters.' );
        }
        $scope_string = trim( preg_replace( '/\s+/', ' ', (string) ( $params['scope'] ?? 'read' ) ) );
        $resource = esc_url_raw( (string) ( $params['resource'] ?? ALIFY_AI_MCP::get_public_mcp_base() ) );
        $client = self::get_client( $client_id );
        if ( ! $client ) {
            return new WP_Error( 'invalid_client', 'Unknown or revoked OAuth client.' );
        }
        $registered = json_decode( (string) $client['redirect_uris'], true );
        if ( ! is_array( $registered ) || ! in_array( $redirect_uri, $registered, true ) ) {
            return new WP_Error( 'invalid_redirect_uri', 'The redirect URI is not registered for this client.' );
        }
        if ( ! hash_equals( untrailingslashit( ALIFY_AI_MCP::get_public_mcp_base() ), untrailingslashit( $resource ) ) ) {
            self::redirect_authorize_error( array( 'redirect_uri' => $redirect_uri, 'state' => $state ), 'invalid_target', 'This authorization server only issues OAuth tokens for the ALIFY MCP resource.' );
        }
        if ( 'code' !== $response_type ) {
            self::redirect_authorize_error( array( 'redirect_uri' => $redirect_uri, 'state' => $state ), 'unsupported_response_type', 'Only response_type=code is supported.' );
        }
        if ( 'S256' !== $method || ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $challenge ) ) {
            self::redirect_authorize_error( array( 'redirect_uri' => $redirect_uri, 'state' => $state ), 'invalid_request', 'PKCE with code_challenge_method=S256 is required.' );
        }
        $scopes = self::parse_scopes( $scope_string );
        if ( is_wp_error( $scopes ) ) {
            self::redirect_authorize_error( array( 'redirect_uri' => $redirect_uri, 'state' => $state ), 'invalid_scope', $scopes->get_error_message() );
        }
        return array(
            'client_id' => $client_id,
            'client_name' => (string) $client['client_name'],
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'scope' => implode( ' ', $scopes ),
            'resource' => $resource,
            'scopes' => $scopes,
            'state' => $state,
        );
    }

    public static function token( WP_REST_Request $request ) {
        nocache_headers();
        $grant = sanitize_key( (string) $request->get_param( 'grant_type' ) );
        if ( 'authorization_code' === $grant ) {
            return self::exchange_code( $request );
        }
        if ( 'refresh_token' === $grant ) {
            return self::exchange_refresh( $request );
        }
        return self::oauth_error( 'unsupported_grant_type', 'Supported grant types are authorization_code and refresh_token.', 400 );
    }

    private static function exchange_code( WP_REST_Request $request ) {
        global $wpdb;
        $code = trim( (string) $request->get_param( 'code' ) );
        $client_id = sanitize_text_field( (string) $request->get_param( 'client_id' ) );
        $redirect_uri = esc_url_raw( (string) $request->get_param( 'redirect_uri' ) );
        $verifier = trim( (string) $request->get_param( 'code_verifier' ) );
        if ( '' === $code || '' === $client_id || '' === $redirect_uri ) {
            return self::oauth_error( 'invalid_request', 'code, client_id and redirect_uri are required.', 400 );
        }
        if ( ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier ) ) {
            return self::oauth_error( 'invalid_grant', 'A valid PKCE code_verifier is required.', 400 );
        }
        $hash = self::hash_secret( $code );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::$codes_table . ' WHERE code_hash=%s LIMIT 1', $hash ), ARRAY_A );
        if ( ! $row || ! empty( $row['consumed_at'] ) || strtotime( (string) $row['expires_at'] . ' UTC' ) <= time() ) {
            return self::oauth_error( 'invalid_grant', 'Authorization code is invalid, expired or already used.', 400 );
        }
        if ( ! hash_equals( (string) $row['client_id'], $client_id ) || ! hash_equals( (string) $row['redirect_uri'], $redirect_uri ) ) {
            return self::oauth_error( 'invalid_grant', 'Authorization code binding does not match this request.', 400 );
        }
        $requested_resource = esc_url_raw( (string) $request->get_param( 'resource' ) );
        if ( '' !== $requested_resource && ! hash_equals( untrailingslashit( (string) $row['resource'] ), untrailingslashit( $requested_resource ) ) ) {
            return self::oauth_error( 'invalid_target', 'Requested resource does not match the authorization grant.', 400 );
        }
        $computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
        if ( ! hash_equals( (string) $row['code_challenge'], $computed ) ) {
            return self::oauth_error( 'invalid_grant', 'PKCE verification failed.', 400 );
        }
        // Consume first. A code is never retried if subsequent issuance fails.
        $updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::$codes_table . ' SET consumed_at=%s WHERE id=%d AND consumed_at IS NULL', current_time( 'mysql', true ), absint( $row['id'] ) ) );
        if ( 1 !== $updated ) {
            return self::oauth_error( 'invalid_grant', 'Authorization code was already used.', 400 );
        }
        $wpdb->update( self::$clients_table, array( 'last_used_at' => current_time( 'mysql', true ) ), array( 'client_id' => $client_id ), array( '%s' ), array( '%s' ) );
        $scopes = json_decode( (string) $row['scopes'], true );
        $pair = self::issue_token_pair( absint( $row['user_id'] ), $client_id, is_array( $scopes ) ? $scopes : array(), (string) $row['resource'] );
        return self::strip_internal_token_fields( $pair );
    }

    private static function exchange_refresh( WP_REST_Request $request ) {
        global $wpdb;
        $raw = trim( (string) $request->get_param( 'refresh_token' ) );
        $client_id = sanitize_text_field( (string) $request->get_param( 'client_id' ) );
        if ( '' === $raw || '' === $client_id ) {
            return self::oauth_error( 'invalid_request', 'refresh_token and client_id are required.', 400 );
        }
        $hash = self::hash_secret( $raw );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::$refresh_table . ' WHERE token_hash=%s LIMIT 1', $hash ), ARRAY_A );
        if ( ! $row || ! empty( $row['revoked_at'] ) || strtotime( (string) $row['expires_at'] . ' UTC' ) <= time() || ! hash_equals( (string) $row['client_id'], $client_id ) ) {
            return self::oauth_error( 'invalid_grant', 'Refresh token is invalid or expired.', 400 );
        }
        $scopes = json_decode( (string) $row['scopes'], true );
        $scopes = is_array( $scopes ) ? array_values( $scopes ) : array();
        $requested_resource = esc_url_raw( (string) $request->get_param( 'resource' ) );
        if ( '' !== $requested_resource && ! hash_equals( untrailingslashit( (string) $row['resource'] ), untrailingslashit( $requested_resource ) ) ) {
            return self::oauth_error( 'invalid_target', 'Requested resource does not match this refresh token.', 400 );
        }
        $requested = trim( (string) $request->get_param( 'scope' ) );
        if ( '' !== $requested ) {
            $requested_scopes = self::parse_scopes( $requested );
            if ( is_wp_error( $requested_scopes ) || array_diff( $requested_scopes, $scopes ) ) {
                return self::oauth_error( 'invalid_scope', 'Refresh requests may only narrow the originally granted scopes.', 400 );
            }
            $scopes = $requested_scopes;
        }
        // Revoke before replacement to enforce rotation/replay resistance.
        $updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::$refresh_table . ' SET revoked_at=%s,last_used_at=%s WHERE id=%d AND revoked_at IS NULL', current_time( 'mysql', true ), current_time( 'mysql', true ), absint( $row['id'] ) ) );
        if ( 1 !== $updated ) {
            return self::oauth_error( 'invalid_grant', 'Refresh token has already been rotated.', 400 );
        }
        $pair = self::issue_token_pair( absint( $row['user_id'] ), $client_id, $scopes, (string) $row['resource'] );
        if ( $pair instanceof WP_REST_Response ) {
            $data = $pair->get_data();
            if ( isset( $data['_refresh_id'] ) ) {
                $wpdb->update( self::$refresh_table, array( 'replaced_by' => absint( $data['_refresh_id'] ) ), array( 'id' => absint( $row['id'] ) ), array( '%d' ), array( '%d' ) );
                unset( $data['_refresh_id'] );
                $pair->set_data( $data );
            }
        }
        return $pair;
    }

    private static function strip_internal_token_fields( $response ) {
        if ( $response instanceof WP_REST_Response ) {
            $data = $response->get_data();
            if ( is_array( $data ) && array_key_exists( '_refresh_id', $data ) ) {
                unset( $data['_refresh_id'] );
                $response->set_data( $data );
            }
        }
        return $response;
    }

    private static function issue_token_pair( int $user_id, string $client_id, array $scope_names, string $resource ) {
        global $wpdb;
        $effective = self::effective_scopes_for_user( $user_id, $scope_names );
        if ( is_wp_error( $effective ) ) {
            return self::oauth_error( 'invalid_scope', $effective->get_error_message(), 400 );
        }
        $scope_names = $effective;
        $scope_map = array_fill_keys( ALIFY_AI_Auth::scope_names(), 0 );
        foreach ( $scope_names as $scope ) {
            if ( array_key_exists( $scope, $scope_map ) ) {
                $scope_map[ $scope ] = 1;
            }
        }
        $access = ALIFY_AI_Access_Tokens::create_oauth( $user_id, 'OAuth: ' . substr( $client_id, 0, 32 ), $scope_map, self::ACCESS_TTL, $client_id, $resource );
        if ( is_wp_error( $access ) ) {
            return self::oauth_error( 'server_error', $access->get_error_message(), 500 );
        }
        $refresh = 'alify_rt_' . bin2hex( random_bytes( 32 ) );
        $expires = gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_TTL );
        $ok = $wpdb->insert( self::$refresh_table, array(
            'token_hash' => self::hash_secret( $refresh ),
            'token_prefix' => substr( $refresh, 0, 20 ),
            'client_id' => $client_id,
            'user_id' => $user_id,
            'resource' => $resource,
            'scopes' => wp_json_encode( array_values( $scope_names ) ),
            'created_at' => current_time( 'mysql', true ),
            'expires_at' => $expires,
        ), array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ) );
        if ( false === $ok ) {
            ALIFY_AI_Access_Tokens::revoke( absint( $access['id'] ) );
            return self::oauth_error( 'server_error', 'Could not issue refresh token.', 500 );
        }
        return new WP_REST_Response( array(
            'access_token' => $access['token'],
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope' => implode( ' ', array_values( $scope_names ) ),
            '_refresh_id' => (int) $wpdb->insert_id,
        ), 200, array( 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache' ) );
    }

    public static function revoke( WP_REST_Request $request ) {
        global $wpdb;
        $token = trim( (string) $request->get_param( 'token' ) );
        $client_id = sanitize_text_field( (string) $request->get_param( 'client_id' ) );
        if ( '' === $token ) {
            return self::oauth_error( 'invalid_request', 'token is required.', 400 );
        }
        if ( str_starts_with( $token, 'alify_rt_' ) ) {
            $hash = self::hash_secret( $token );
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,client_id FROM ' . self::$refresh_table . ' WHERE token_hash=%s LIMIT 1', $hash ), ARRAY_A );
            if ( $row && ( '' === $client_id || hash_equals( (string) $row['client_id'], $client_id ) ) ) {
                $wpdb->update( self::$refresh_table, array( 'revoked_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $row['id'] ) ), array( '%s' ), array( '%d' ) );
            }
        } elseif ( str_starts_with( $token, 'alify_pat_' ) || str_starts_with( $token, 'alify_oat_' ) ) {
            ALIFY_AI_Access_Tokens::revoke_raw( $token, $client_id );
        }
        // RFC 7009: revocation endpoint returns 200 for unknown tokens too.
        return new WP_REST_Response( null, 200, array( 'Cache-Control' => 'no-store' ) );
    }

    private static function issue_code( array $request, int $user_id ) {
        global $wpdb;
        $raw = 'alify_ac_' . bin2hex( random_bytes( 32 ) );
        $ok = $wpdb->insert( self::$codes_table, array(
            'code_hash' => self::hash_secret( $raw ),
            'client_id' => $request['client_id'],
            'user_id' => $user_id,
            'redirect_uri' => $request['redirect_uri'],
            'resource' => $request['resource'],
            'scopes' => wp_json_encode( array_values( $request['scopes'] ) ),
            'code_challenge' => $request['code_challenge'],
            'created_at' => current_time( 'mysql', true ),
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL ),
        ), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
        return false === $ok ? new WP_Error( 'oauth_code_failed', 'Could not create authorization code.' ) : $raw;
    }

    /**
     * OAuth scopes are negotiated, not treated as an all-or-nothing request.
     *
     * Known connector scopes that are currently disabled by the administrator
     * are omitted from the grant rather than making the entire authorization
     * fail. Unknown scopes remain an OAuth invalid_scope error. This is
     * important for MCP clients that cache discovery metadata or ask for a
     * broader permission set than the current WordPress policy allows.
     */
    private static function parse_scopes( string $scope_string ) {
        $names = array_values( array_unique( array_filter( preg_split( '/\s+/', trim( $scope_string ) ) ?: array() ) ) );
        if ( ! $names ) {
            $names = array( 'read' );
        }

        $known    = ALIFY_AI_Auth::scope_names();
        $enabled  = ALIFY_AI_Auth::enabled_scope_names();
        $granted  = array();
        $offline  = false;

        foreach ( $names as $raw_scope ) {
            $scope = sanitize_key( (string) $raw_scope );
            if ( 'offline_access' === $scope ) {
                $offline = true;
                continue;
            }
            if ( ! in_array( $scope, $known, true ) ) {
                return new WP_Error( 'invalid_scope', 'Requested scope is unknown: ' . $scope );
            }
            if ( in_array( $scope, $enabled, true ) ) {
                $granted[] = $scope;
            }
        }

        // A broad client request must still be able to connect when only a
        // subset of permissions is enabled. Prefer read as the safe baseline.
        if ( ! $granted && in_array( 'read', $enabled, true ) ) {
            $granted[] = 'read';
        }
        if ( ! $granted ) {
            return new WP_Error( 'invalid_scope', 'None of the requested WordPress permissions are currently enabled.' );
        }
        if ( $offline ) {
            $granted[] = 'offline_access';
        }
        return array_values( array_unique( $granted ) );
    }

    /**
     * Narrow a negotiated OAuth grant to permissions the signed-in WordPress
     * user can actually exercise. offline_access is protocol metadata and does
     * not map to a WordPress capability.
     */
    private static function effective_scopes_for_user( int $user_id, array $scopes ) {
        $enabled = ALIFY_AI_Auth::enabled_scope_names();
        $result  = array();
        $offline = in_array( 'offline_access', $scopes, true );

        foreach ( $scopes as $scope ) {
            $scope = sanitize_key( (string) $scope );
            if ( 'offline_access' === $scope ) {
                continue;
            }
            if ( ! in_array( $scope, $enabled, true ) ) {
                continue;
            }
            $cap = ALIFY_AI_Auth::capability_for_scope( $scope );
            if ( user_can( $user_id, $cap ) ) {
                $result[] = $scope;
            }
        }

        if ( ! $result && in_array( 'read', $enabled, true ) && user_can( $user_id, ALIFY_AI_Auth::capability_for_scope( 'read' ) ) ) {
            $result[] = 'read';
        }
        if ( ! $result ) {
            return new WP_Error( 'oauth_user_scope_forbidden', 'This WordPress account does not have access to any currently enabled connector permission.' );
        }
        if ( $offline ) {
            $result[] = 'offline_access';
        }
        return array_values( array_unique( $result ) );
    }

    /**
     * Build the explicit reauthorization grant from the current WordPress
     * administrator policy rather than trusting a stale client-side scope
     * request. Only scopes enabled globally AND permitted by the signed-in
     * WordPress user's native capabilities are included. offline_access is
     * preserved only when the OAuth client requested it.
     *
     * This expansion happens only on the interactive authorization endpoint,
     * where the complete grant is displayed for consent. Refresh grants keep
     * their original scope ceiling and therefore cannot silently gain access.
     */
    private static function managed_scopes_for_user( int $user_id, array $requested_scopes ) {
        $enabled = ALIFY_AI_Auth::enabled_scope_names();
        $managed = array();

        foreach ( $enabled as $scope ) {
            $scope = sanitize_key( (string) $scope );
            $cap = ALIFY_AI_Auth::capability_for_scope( $scope );
            if ( user_can( $user_id, $cap ) ) {
                $managed[] = $scope;
            }
        }

        if ( ! $managed ) {
            return new WP_Error( 'oauth_user_scope_forbidden', 'This WordPress account does not have access to any currently enabled connector permission.' );
        }

        if ( in_array( 'offline_access', $requested_scopes, true ) ) {
            $managed[] = 'offline_access';
        }

        return array_values( array_unique( $managed ) );
    }

    /**
     * Discovery must advertise the policy that can actually be granted now.
     * ChatGPT uses offline_access to maintain a refresh-token based connection.
     */
    private static function advertised_scopes(): array {
        $scopes = ALIFY_AI_Auth::enabled_scope_names();
        $scopes[] = 'offline_access';
        return array_values( array_unique( $scopes ) );
    }

    private static function get_client( string $client_id ): ?array {
        global $wpdb;
        if ( '' === $client_id ) {
            return null;
        }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::$clients_table . ' WHERE client_id=%s AND revoked_at IS NULL LIMIT 1', $client_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    public static function list_clients( int $limit = 100 ): array {
        global $wpdb;
        $limit = max( 1, min( 200, $limit ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT id,client_id,client_name,redirect_uris,created_at,last_used_at,revoked_at FROM ' . self::$clients_table . ' ORDER BY id DESC LIMIT %d', $limit ),
            ARRAY_A
        );
        foreach ( $rows ?: array() as &$row ) {
            $row['id'] = absint( $row['id'] );
            $decoded = json_decode( (string) $row['redirect_uris'], true );
            $row['redirect_uris'] = is_array( $decoded ) ? $decoded : array();
        }
        unset( $row );
        return $rows ?: array();
    }

    public static function revoke_client( int $id ): bool {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT client_id FROM ' . self::$clients_table . ' WHERE id=%d LIMIT 1', $id ), ARRAY_A );
        if ( ! $row ) {
            return false;
        }
        $client_id = (string) $row['client_id'];
        $ok = $wpdb->update(
            self::$clients_table,
            array( 'revoked_at' => current_time( 'mysql', true ) ),
            array( 'id' => $id ),
            array( '%s' ),
            array( '%d' )
        );
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::$refresh_table . ' SET revoked_at=%s WHERE client_id=%s AND revoked_at IS NULL', current_time( 'mysql', true ), $client_id ) );
        ALIFY_AI_Access_Tokens::revoke_by_client( $client_id );
        return false !== $ok;
    }

    public static function cleanup(): void {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $code_cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
        $refresh_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::$codes_table . ' WHERE expires_at < %s OR (consumed_at IS NOT NULL AND consumed_at < %s)', $now, $code_cutoff ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::$refresh_table . ' WHERE (expires_at < %s) OR (revoked_at IS NOT NULL AND revoked_at < %s)', $now, $refresh_cutoff ) );
        ALIFY_AI_Access_Tokens::cleanup_oauth_tokens();
    }

    private static function validate_redirect_uri( string $uri ) {
        $uri = esc_url_raw( trim( $uri ) );
        $parts = wp_parse_url( $uri );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['fragment'] ) ) {
            return new WP_Error( 'oauth_redirect_invalid', 'Redirect URIs must be absolute and must not contain fragments.' );
        }
        $scheme = strtolower( (string) $parts['scheme'] );
        $host = strtolower( (string) $parts['host'] );
        $loopback = in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true );
        if ( 'https' !== $scheme && ! ( $loopback && 'http' === $scheme ) ) {
            return new WP_Error( 'oauth_redirect_https', 'Redirect URIs must use HTTPS, except loopback localhost URIs.' );
        }
        return $uri;
    }

    private static function render_consent( array $request ): void {
        $user = wp_get_current_user();
        nocache_headers();
        header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
        $hidden = array( 'client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'scope', 'resource', 'state' );
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Authorize ALIFY Connector</title>';
        echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;color:#1d2327;margin:0;padding:40px 18px}.card{max-width:620px;margin:auto;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:28px;box-shadow:0 2px 8px rgba(0,0,0,.04)}h1{font-size:24px;margin-top:0}.scope{padding:10px 12px;background:#f6f7f7;border-radius:6px;margin:6px 0}.actions{display:flex;gap:10px;margin-top:24px}.btn{border:0;border-radius:6px;padding:10px 16px;font-weight:600;cursor:pointer}.primary{background:#2271b1;color:white}.secondary{background:#dcdcde;color:#1d2327}.muted{color:#646970;font-size:13px}</style></head><body><div class="card">';
        echo '<h1>Authorize ' . esc_html( $request['client_name'] ) . '</h1><p>Signed in as <strong>' . esc_html( $user->user_login ) . '</strong>. Approving will grant the following permissions currently enabled by the WordPress administrator and allowed for this account:</p>';
        if ( ! empty( $request['scope_expansion'] ) ) {
            echo '<p class="muted"><strong>Permission sync:</strong> this OAuth client requested an older/narrower scope set. ALIFY is showing the additional enabled permissions below so you can explicitly approve the updated connector grant.</p>';
        }
        foreach ( $request['scopes'] as $scope ) {
            if ( 'offline_access' === $scope ) {
                echo '<div class="scope"><strong>Offline access</strong> <span class="muted">(keep this connection signed in with rotating refresh tokens)</span></div>';
                continue;
            }
            echo '<div class="scope"><strong>' . esc_html( ucfirst( $scope ) ) . '</strong> <span class="muted">(' . esc_html( ALIFY_AI_Auth::capability_for_scope( $scope ) ) . ')</span></div>';
        }
        echo '<p class="muted">This is the exact permission set that will be stored in the new OAuth credential. Access remains limited by WordPress capabilities. Refresh tokens may narrow this grant but never expand it; expanding permissions requires this consent screen again. The authorization code uses PKCE S256 and expires in five minutes.</p><form method="post" action="' . esc_url( self::authorization_endpoint() ) . '">';
        wp_nonce_field( 'alify_ai_oauth_consent' );
        foreach ( $hidden as $key ) {
            $value = 'scope' === $key ? $request['scope'] : ( $request[ $key ] ?? '' );
            echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">';
        }
        echo '<div class="actions"><button class="btn primary" type="submit" name="decision" value="approve">Authorize</button><button class="btn secondary" type="submit" name="decision" value="deny">Deny</button></div></form></div></body></html>';
        exit;
    }

    private static function redirect_authorize_error( array $request, string $error, string $description ): void {
        if ( empty( $request['redirect_uri'] ) ) {
            self::render_error( $description, 400 );
        }
        $args = array( 'error' => $error, 'error_description' => $description );
        if ( ! empty( $request['state'] ) ) {
            $args['state'] = $request['state'];
        }
        $location = add_query_arg( $args, $request['redirect_uri'] );
        wp_redirect( $location, 302, 'ALIFY OAuth' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit;
    }

    private static function render_error( string $message, int $status ): void {
        status_header( $status );
        nocache_headers();
        header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
        echo '<!doctype html><html><body style="font-family:sans-serif;padding:40px"><h1>OAuth request error</h1><p>' . esc_html( $message ) . '</p></body></html>';
        exit;
    }

    private static function current_authorize_url(): string {
        $query = isset( $_GET ) && is_array( $_GET ) ? wp_unslash( $_GET ) : array();
        unset( $query['_wpnonce'], $query['decision'] );
        return esc_url_raw( add_query_arg( array_map( 'sanitize_text_field', $query ), self::authorization_endpoint() ) );
    }

    private static function oauth_error( string $error, string $description, int $status ): WP_REST_Response {
        return new WP_REST_Response( array( 'error' => $error, 'error_description' => $description ), $status, array( 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache' ) );
    }

    private static function hash_secret( string $raw ): string {
        return hash_hmac( 'sha256', $raw, wp_salt( 'auth' ) );
    }

    private static function allow_registration_request() {
        $ip = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        return ALIFY_AI_Rate_Limiter::consume( 'oauth_registration', $ip, self::CLIENT_REG_LIMIT, HOUR_IN_SECONDS );
    }
}
