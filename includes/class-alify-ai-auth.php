<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ALIFY_AI_Auth {
    private static int $internal_mcp_depth = 0;
    private static array $runtime_context = array();

    private const OPTION_HASH   = 'alify_ai_api_key_hash';
    private const OPTION_PREFIX = 'alify_ai_api_key_prefix';
    private const OPTION_SCOPES = 'alify_ai_scopes';

    private const DEFAULT_SCOPES = array(
        'read'         => 1,
        'content'      => 1,
        'structure'    => 0,
        'acf'          => 0,
        'media'        => 0,
        'menus'        => 0,
        'design'       => 0,
        'commerce'     => 0,
        'orders'       => 0,
        'seo'          => 0,
        'transactions' => 0,
        'code_read'     => 0,
        'code_write'    => 0,
    );

    private const SCOPE_CAPABILITIES = array(
        'read'         => 'read',
        'content'      => 'edit_posts',
        'structure'    => 'manage_options',
        'acf'          => 'manage_options',
        'media'        => 'upload_files',
        'menus'        => 'edit_theme_options',
        'design'       => 'edit_posts',
        'commerce'     => 'manage_woocommerce',
        'orders'       => 'manage_woocommerce',
        'seo'          => 'edit_posts',
        'transactions' => 'manage_options',
        'code_read'     => 'edit_theme_options',
        'code_write'    => 'edit_theme_options',
    );

    public static function init(): void {
        add_filter( 'rest_pre_dispatch', array( __CLASS__, 'add_no_cache_headers' ), 10, 3 );
    }

    public static function begin_internal_mcp(): void {
        self::$internal_mcp_depth++;
    }

    public static function end_internal_mcp(): void {
        self::$internal_mcp_depth = max( 0, self::$internal_mcp_depth - 1 );
    }

    private static function internal_mcp_active(): bool {
        return self::$internal_mcp_depth > 0;
    }

    public static function set_runtime_context( array $context ): void {
        self::$runtime_context = $context;
    }

    public static function clear_runtime_context(): void {
        self::$runtime_context = array();
    }

    public static function get_runtime_context(): array {
        return self::$runtime_context;
    }

    public static function audit_identity(): array {
        $ctx = self::$runtime_context;
        return array(
            'auth_type' => sanitize_key( (string) ( $ctx['auth_type'] ?? ( is_user_logged_in() ? 'wordpress_user' : 'system' ) ) ),
            'user_id' => absint( $ctx['user_id'] ?? get_current_user_id() ),
            'credential_id' => absint( $ctx['credential_id'] ?? 0 ),
            'credential_name' => sanitize_text_field( (string) ( $ctx['credential_name'] ?? '' ) ),
            'credential_prefix' => sanitize_text_field( (string) ( $ctx['credential_prefix'] ?? '' ) ),
        );
    }

    public static function add_no_cache_headers( $result, $server, $request ) {
        if ( str_starts_with( $request->get_route(), '/alify-ai/v1/' ) ) {
            nocache_headers();
        }
        return $result;
    }

    public static function generate_key(): string {
        $plain = 'alify_' . wp_generate_password( 48, false, false );
        update_option( self::OPTION_HASH, wp_hash_password( $plain ), false );
        update_option( self::OPTION_PREFIX, substr( $plain, 0, 12 ), false );
        if ( ! get_option( self::OPTION_SCOPES ) ) {
            update_option( self::OPTION_SCOPES, self::DEFAULT_SCOPES, false );
        } else {
            self::update_scopes( self::get_scopes() );
        }
        return $plain;
    }

    public static function revoke_key(): void {
        delete_option( self::OPTION_HASH );
        delete_option( self::OPTION_PREFIX );
    }

    public static function has_key(): bool {
        return (bool) get_option( self::OPTION_HASH );
    }

    public static function get_prefix(): string {
        return (string) get_option( self::OPTION_PREFIX, '' );
    }

    public static function get_scopes(): array {
        $stored = get_option( self::OPTION_SCOPES, self::DEFAULT_SCOPES );
        return self::sanitize_scopes( is_array( $stored ) ? $stored : array() );
    }

    public static function sanitize_scopes( array $scopes ): array {
        $clean = array();
        foreach ( array_keys( self::DEFAULT_SCOPES ) as $scope ) {
            $clean[ $scope ] = empty( $scopes[ $scope ] ) ? 0 : 1;
        }
        return $clean;
    }

    public static function scope_names(): array {
        return array_keys( self::DEFAULT_SCOPES );
    }

    /**
     * Return only connector permissions currently enabled by the WordPress
     * administrator. OAuth discovery uses this list so clients do not request
     * permissions that the server policy would immediately reject.
     */
    public static function enabled_scope_names(): array {
        $enabled = array();
        foreach ( self::get_scopes() as $scope => $allowed ) {
            if ( ! empty( $allowed ) ) {
                $enabled[] = (string) $scope;
            }
        }
        return $enabled;
    }

    public static function update_scopes( array $scopes ): void {
        update_option( self::OPTION_SCOPES, self::sanitize_scopes( $scopes ), false );
    }

    /**
     * Explain the three authorization layers for the current request.
     * This prevents capability reports from confusing globally-enabled scopes
     * with scopes actually granted to the current OAuth/PAT credential.
     */
    public static function runtime_scope_report(): array {
        $global = self::get_scopes();
        $context = self::$runtime_context;
        $credential = isset( $context['scopes'] ) && is_array( $context['scopes'] )
            ? self::sanitize_scopes( $context['scopes'] )
            : $global;
        $effective = array();
        $needs_reauthorization = array();
        foreach ( self::scope_names() as $scope ) {
            $allowed = self::context_allows_scope( $context ?: array( 'auth_type' => 'legacy_mcp', 'user_id' => 0, 'scopes' => $global ), $scope );
            $effective[ $scope ] = true === $allowed ? 1 : 0;
            if ( ! empty( $global[ $scope ] ) && empty( $credential[ $scope ] ) ) {
                $needs_reauthorization[] = $scope;
            }
        }
        return array(
            'global' => $global,
            'credential' => $credential,
            'effective' => $effective,
            'needs_reauthorization' => $needs_reauthorization,
            'auth_type' => sanitize_key( (string) ( $context['auth_type'] ?? 'legacy_mcp' ) ),
            'user_id' => absint( $context['user_id'] ?? 0 ),
            'oauth_grant_mode' => 'managed_reauthorization',
        );
    }

    public static function capability_for_scope( string $scope ): string {
        $scope = sanitize_key( $scope );
        return (string) ( self::SCOPE_CAPABILITIES[ $scope ] ?? 'manage_options' );
    }

    /**
     * Authenticate a normal REST request. Bearer credentials take precedence;
     * the legacy X-ALIFY-Key remains supported for backward compatibility.
     */
    private static function authenticate_request( WP_REST_Request $request ) {
        self::clear_runtime_context();
        $authorization = trim( (string) $request->get_header( 'authorization' ) );
        if ( preg_match( '/^Bearer\s+(.+)$/i', $authorization, $match ) ) {
            $verified = ALIFY_AI_Access_Tokens::verify( trim( $match[1] ), rest_url( ltrim( $request->get_route(), '/' ) ) );
            if ( is_wp_error( $verified ) ) {
                return $verified;
            }
            self::set_runtime_context( $verified );
            return $verified;
        }

        $hash = (string) get_option( self::OPTION_HASH, '' );
        if ( '' === $hash ) {
            return new WP_Error( 'alify_ai_not_configured', 'No supported REST credential has been provided.', array( 'status' => 503 ) );
        }
        $provided = trim( (string) $request->get_header( 'x-alify-key' ) );
        if ( '' === $provided || ! wp_check_password( $provided, $hash ) ) {
            return new WP_Error( 'alify_ai_unauthorized', 'Invalid API credential.', array( 'status' => 401 ) );
        }
        $context = array(
            'auth_type' => 'legacy_rest_key',
            'user_id' => 0,
            'credential_id' => 0,
            'credential_name' => 'Legacy REST API key',
            'credential_prefix' => self::get_prefix(),
            'scopes' => self::get_scopes(),
        );
        self::set_runtime_context( $context );
        return $context;
    }

    private static function context_allows_scope( array $context, string $scope ) {
        $scope = sanitize_key( $scope );
        $global = self::get_scopes();
        if ( empty( $global[ $scope ] ) ) {
            return new WP_Error( 'alify_ai_forbidden', 'The WordPress administrator has not enabled the required ' . $scope . ' permission.', array( 'status' => 403 ) );
        }

        $context_scopes = isset( $context['scopes'] ) && is_array( $context['scopes'] ) ? self::sanitize_scopes( $context['scopes'] ) : $global;
        if ( empty( $context_scopes[ $scope ] ) ) {
            return new WP_Error( 'alify_ai_forbidden', 'This credential does not have the required ' . $scope . ' scope.', array( 'status' => 403 ) );
        }

        if ( in_array( (string) ( $context['auth_type'] ?? '' ), array( 'bearer', 'oauth_bearer' ), true ) ) {
            $user_id = absint( $context['user_id'] ?? 0 );
            $capability = self::capability_for_scope( $scope );
            if ( ! $user_id || ! user_can( $user_id, $capability ) ) {
                return new WP_Error(
                    'alify_ai_user_capability_forbidden',
                    'The WordPress user assigned to this credential lacks the capability required for the ' . $scope . ' scope.',
                    array( 'status' => 403, 'required_capability' => $capability )
                );
            }
        }
        return true;
    }

    public static function authorize_any( WP_REST_Request $request, array $scopes ) {
        if ( self::internal_mcp_active() ) {
            $context = self::$runtime_context ?: array(
                'auth_type' => 'legacy_mcp',
                'user_id' => 0,
                'scopes' => self::get_scopes(),
            );
        } else {
            $context = self::authenticate_request( $request );
            if ( is_wp_error( $context ) ) {
                return $context;
            }
        }

        $last_error = null;
        foreach ( $scopes as $scope ) {
            $allowed = self::context_allows_scope( $context, (string) $scope );
            if ( true === $allowed ) {
                return true;
            }
            $last_error = $allowed;
        }
        return $last_error ?: new WP_Error( 'alify_ai_forbidden', 'This credential does not have any of the required permissions.', array( 'status' => 403 ) );
    }

    /**
     * Check whether the credential currently authenticated for this request can
     * use one connector scope. MCP discovery uses this to expose only tools
     * that the caller can actually invoke.
     *
     * @return true|WP_Error
     */
    public static function runtime_allows_scope( string $scope ) {
        $context = self::$runtime_context ?: array(
            'auth_type' => 'legacy_mcp',
            'user_id' => 0,
            'scopes' => self::get_scopes(),
        );
        return self::context_allows_scope( $context, $scope );
    }

    public static function authorize( WP_REST_Request $request, string $scope = 'read' ) {
        if ( self::internal_mcp_active() ) {
            $context = self::$runtime_context ?: array(
                'auth_type' => 'legacy_mcp',
                'user_id' => 0,
                'scopes' => self::get_scopes(),
            );
            return self::context_allows_scope( $context, $scope );
        }

        $context = self::authenticate_request( $request );
        if ( is_wp_error( $context ) ) {
            return $context;
        }
        return self::context_allows_scope( $context, $scope );
    }
}
