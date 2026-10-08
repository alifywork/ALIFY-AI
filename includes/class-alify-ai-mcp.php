<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stateless Streamable-HTTP-style MCP endpoint hosted directly by WordPress.
 *
 * The connection token is intentionally carried in the opaque endpoint path so
 * a user can paste a single URL into an MCP client without configuring custom
 * headers. Treat the URL like a password: regenerate it if it is exposed.
 */
final class ALIFY_AI_MCP {
    private const NS            = 'alify-ai/v1';
    private const OPTION_TOKEN    = 'alify_ai_mcp_connection_token';
    private const OPTION_DISABLED = 'alify_ai_mcp_disabled';
    private const PROTOCOL      = '2026-07-28';
    private const LEGACY_PROTOCOLS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );
    private const DISCOVERY_TTL_MS = 60000;
    private const MAX_REQUEST_BYTES = 12582912; // 12 MiB to allow bounded base64 media uploads.
    private const RATE_LIMIT_PER_MINUTE = 120;
    private static array $tool_cache = array();

    public static function init(): void {
        self::maybe_migrate_connection();
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }


    public static function ensure_connection(): void {
        if ( ! self::has_connection() && ! (bool) get_option( self::OPTION_DISABLED, false ) ) {
            self::regenerate_connection();
        }
    }

    private static function maybe_migrate_connection(): void {
        if ( false === get_option( self::OPTION_TOKEN, false ) && ! (bool) get_option( self::OPTION_DISABLED, false ) ) {
            self::regenerate_connection();
        }
    }

    public static function has_connection(): bool {
        $token = (string) get_option( self::OPTION_TOKEN, '' );
        return strlen( $token ) >= 40;
    }

    public static function regenerate_connection(): string {
        $token = strtolower( bin2hex( random_bytes( 32 ) ) );
        update_option( self::OPTION_TOKEN, $token, false );
        delete_option( self::OPTION_DISABLED );
        return $token;
    }

    public static function revoke_connection(): void {
        delete_option( self::OPTION_TOKEN );
        update_option( self::OPTION_DISABLED, 1, false );
    }

    public static function get_connection_url(): string {
        $token = (string) get_option( self::OPTION_TOKEN, '' );
        if ( '' === $token ) {
            return '';
        }
        return rest_url( self::NS . '/mcp/' . rawurlencode( $token ) );
    }

    public static function get_public_mcp_base(): string {
        return rest_url( self::NS . '/mcp' );
    }

    public static function get_legacy_mcp_template(): string {
        return rest_url( self::NS . '/mcp/{connection-token}' );
    }

    public static function register_routes(): void {
        $routes = array(
            '/mcp/(?P<token>[A-Za-z0-9_-]{40,128})', // Backward-compatible private URL.
            '/mcp', // Preferred bearer-token endpoint for per-user credentials.
        );
        foreach ( $routes as $route ) {
            register_rest_route(
                self::NS,
                $route,
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array( __CLASS__, 'handle_get' ),
                        'permission_callback' => '__return_true',
                    ),
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array( __CLASS__, 'handle_post' ),
                        'permission_callback' => '__return_true',
                    ),
                )
            );
        }
    }

    private static function authenticate( WP_REST_Request $request ) {
        ALIFY_AI_Auth::clear_runtime_context();
        $provided = trim( (string) $request->get_param( 'token' ) );
        if ( '' !== $provided ) {
            $stored = (string) get_option( self::OPTION_TOKEN, '' );
            if ( '' === $stored ) {
                return new WP_Error( 'alify_mcp_disabled', 'ChatGPT connection is disabled.', array( 'status' => 503 ) );
            }
            if ( ! hash_equals( $stored, $provided ) ) {
                return new WP_Error( 'alify_mcp_unauthorized', 'Invalid MCP connection URL.', array( 'status' => 401 ) );
            }
            $context = array(
                'auth_type' => 'legacy_mcp',
                'user_id' => 0,
                'credential_id' => 0,
                'credential_name' => 'Legacy private MCP URL',
                'credential_prefix' => substr( $provided, 0, 10 ),
                'scopes' => ALIFY_AI_Auth::get_scopes(),
            );
            ALIFY_AI_Auth::set_runtime_context( $context );
            return $context;
        }

        $authorization = trim( (string) $request->get_header( 'authorization' ) );
        if ( ! preg_match( '/^Bearer\s+(.+)$/i', $authorization, $match ) ) {
            return new WP_Error( 'alify_mcp_unauthorized', 'Use a private MCP URL or an Authorization: Bearer access token.', array( 'status' => 401 ) );
        }
        $context = ALIFY_AI_Access_Tokens::verify( trim( $match[1] ), self::get_public_mcp_base() );
        if ( is_wp_error( $context ) ) {
            return $context;
        }
        ALIFY_AI_Auth::set_runtime_context( $context );
        return $context;
    }

    private static function oauth_auth_error_response( WP_Error $error ): WP_REST_Response {
        $data = $error->get_error_data();
        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 401;
        $response = new WP_REST_Response(
            array(
                'code' => $error->get_error_code(),
                'message' => $error->get_error_message(),
            ),
            $status
        );
        if ( 401 === $status ) {
            $metadata = home_url( '/.well-known/oauth-protected-resource' );
            $response->header( 'WWW-Authenticate', 'Bearer resource_metadata="' . esc_url_raw( $metadata ) . '"' );
        }
        $response->header( 'Cache-Control', 'no-store' );
        return $response;
    }

    public static function handle_get( WP_REST_Request $request ) {
        $auth = self::authenticate( $request );
        if ( is_wp_error( $auth ) ) {
            return self::oauth_auth_error_response( $auth );
        }
        nocache_headers();
        $accept = strtolower( (string) $request->get_header( 'accept' ) );
        if ( str_contains( $accept, 'text/event-stream' ) ) {
            $response = new WP_REST_Response( array( 'message' => 'This MCP server is stateless and does not open an SSE stream. Use POST JSON-RPC requests.' ), 405 );
            $response->header( 'Allow', 'POST' );
            return $response;
        }
        return new WP_REST_Response(
            array(
                'name'        => 'ALIFY WordPress MCP',
                'version'     => ALIFY_AI_VERSION,
                'status'      => 'ready',
                'transport'   => 'streamable-http-json',
                'message'     => 'Use HTTP POST JSON-RPC requests on this same URL.',
                'tool_count'  => count( self::discovery_tools() ),
                'protocol'    => self::PROTOCOL,
                'supported_protocols' => array_merge( array( self::PROTOCOL ), self::LEGACY_PROTOCOLS ),
                'permissions' => ALIFY_AI_Auth::get_scopes(),
            )
        );
    }

    public static function handle_post( WP_REST_Request $request ) {
        $auth = self::authenticate( $request );
        if ( is_wp_error( $auth ) ) {
            return self::oauth_auth_error_response( $auth );
        }
        $content_length = absint( $request->get_header( 'content-length' ) );
        $body_length = strlen( (string) $request->get_body() );
        if ( $content_length > self::MAX_REQUEST_BYTES || $body_length > self::MAX_REQUEST_BYTES ) {
            ALIFY_AI_Diagnostics::log(
                'Rejected oversized MCP request.',
                array( 'content_length' => $content_length, 'body_length' => $body_length, 'max_bytes' => self::MAX_REQUEST_BYTES )
            );
            return new WP_REST_Response(
                array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32600, 'message' => 'MCP request exceeds the safe request-size limit.' ) ),
                413
            );
        }
        $rate = self::check_rate_limit( $request );
        if ( is_wp_error( $rate ) ) {
            $rate_data = $rate->get_error_data();
            $rate_status = is_array( $rate_data ) && isset( $rate_data['status'] ) ? (int) $rate_data['status'] : 429;
            $response = new WP_REST_Response(
                array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32029, 'message' => $rate->get_error_message() ) ),
                $rate_status
            );
            if ( 429 === $rate_status ) {
                $response->header( 'Retry-After', '60' );
            }
            return $response;
        }
        nocache_headers();

        $message = $request->get_json_params();
        if ( ! is_array( $message ) ) {
            return self::rpc_error( null, -32700, 'Parse error: expected a JSON object.' );
        }

        // This release intentionally uses one JSON-RPC message per HTTP request.
        if ( self::is_list_array( $message ) ) {
            return self::rpc_error( null, -32600, 'JSON-RPC batch requests are not supported.' );
        }

        $id = $message['id'] ?? null;
        if ( is_bool( $id ) || is_array( $id ) || is_object( $id ) || is_resource( $id ) ) {
            return self::rpc_error( null, -32600, 'Invalid JSON-RPC request id.' );
        }
        if ( ! isset( $message['method'] ) || ! is_string( $message['method'] ) || '' === trim( $message['method'] ) ) {
            return self::rpc_error( $id, -32600, 'Invalid JSON-RPC request.' );
        }
        $method = trim( $message['method'] );
        if ( array_key_exists( 'params', $message ) && ! is_array( $message['params'] ) ) {
            return self::rpc_error( $id, -32602, 'Invalid params: params must be a JSON object.' );
        }
        $params = isset( $message['params'] ) ? $message['params'] : array();

        if ( '2.0' !== (string) ( $message['jsonrpc'] ?? '' ) ) {
            return self::rpc_error( $id, -32600, 'Invalid JSON-RPC request.' );
        }

        // MCP notifications intentionally return no JSON-RPC body.
        if ( str_starts_with( $method, 'notifications/' ) ) {
            return new WP_REST_Response( null, 202 );
        }

        if ( 'server/discover' === $method ) {
            return self::rpc_result(
                $id,
                array(
                    'resultType'        => 'complete',
                    'supportedVersions' => array_merge( array( self::PROTOCOL ), self::LEGACY_PROTOCOLS ),
                    'capabilities'      => array( 'tools' => array( 'listChanged' => false ) ),
                    '_meta'             => self::server_result_meta(),
                    'instructions'      => self::server_instructions(),
                    'ttlMs'             => self::DISCOVERY_TTL_MS,
                    'cacheScope'        => 'private',
                )
            );
        }

        // Backward compatibility for MCP clients using the pre-2026 handshake.
        if ( 'initialize' === $method ) {
            $requested = isset( $params['protocolVersion'] ) ? sanitize_text_field( (string) $params['protocolVersion'] ) : self::LEGACY_PROTOCOLS[0];
            $protocol  = in_array( $requested, self::LEGACY_PROTOCOLS, true ) ? $requested : self::LEGACY_PROTOCOLS[0];
            return self::rpc_result(
                $id,
                array(
                    'protocolVersion' => $protocol,
                    'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
                    'serverInfo'      => array( 'name' => 'alify-wordpress', 'version' => ALIFY_AI_VERSION ),
                    'instructions'    => self::server_instructions(),
                )
            );
        }

        if ( 'ping' === $method ) {
            return self::rpc_result( $id, array( 'resultType' => 'complete', '_meta' => self::server_result_meta() ) );
        }

        if ( 'tools/list' === $method ) {
            $tools = self::discovery_tools();
            return self::rpc_result(
                $id,
                array(
                    'resultType' => 'complete',
                    'tools'      => array_values( $tools ),
                    'ttlMs'      => self::DISCOVERY_TTL_MS,
                    'cacheScope' => 'private',
                    '_meta'      => self::server_result_meta(),
                )
            );
        }

        if ( 'tools/call' === $method ) {
            $name = isset( $params['name'] ) && is_string( $params['name'] ) ? sanitize_key( $params['name'] ) : '';
            if ( array_key_exists( 'arguments', $params ) && ! is_array( $params['arguments'] ) ) {
                return self::rpc_error( $id, -32602, 'Invalid params: tools/call arguments must be a JSON object.' );
            }
            $arguments = isset( $params['arguments'] ) ? $params['arguments'] : array();
            $tools     = self::tools();
            if ( '' === $name || ! isset( $tools[ $name ] ) ) {
                return self::rpc_result( $id, self::tool_error( 'Unknown tool: ' . $name ) );
            }
            $result = self::call_tool( $name, $arguments );
            return self::rpc_result( $id, $result );
        }

        return self::rpc_error( $id, -32601, 'Method not found.' );
    }

    private static function check_rate_limit( WP_REST_Request $request ) {
        $context = ALIFY_AI_Auth::get_runtime_context();
        $credential_key = (string) ( $context['auth_type'] ?? 'unknown' ) . ':' . (string) ( $context['credential_id'] ?? 0 ) . ':' . (string) ( $context['credential_prefix'] ?? '' );
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $result = ALIFY_AI_Rate_Limiter::consume( 'mcp', $credential_key . '|' . $ip, self::RATE_LIMIT_PER_MINUTE, 60 );
        if ( is_wp_error( $result ) && 'alify_ai_rate_limited' === $result->get_error_code() ) {
            return new WP_Error( 'alify_mcp_rate_limited', 'Too many MCP requests. Try again shortly.', $result->get_error_data() );
        }
        return $result;
    }

    private static function is_list_array( array $value ): bool {
        if ( array() === $value ) {
            return true;
        }
        return array_keys( $value ) === range( 0, count( $value ) - 1 );
    }

    private static function server_instructions(): string {
        return 'Inspect the WordPress site before editing. Respect enabled permissions. Gutenberg, Elementor, WooCommerce catalog, and transaction changes must be previewed before applying a proposal. Never reveal or repeat MCP credentials.';
    }

    private static function server_result_meta(): array {
        return array(
            'io.modelcontextprotocol/serverInfo' => array(
                'name'    => 'alify-wordpress',
                'version' => ALIFY_AI_VERSION,
            ),
        );
    }

    /**
     * Return the MCP tools this authenticated caller can actually invoke.
     * The 2026-07-28 MCP specification explicitly allows tools/list to vary by
     * authorization. Keeping inaccessible tools out of discovery also avoids
     * ChatGPT scanning actions that will immediately return 403.
     */
    public static function runtime_tool_summary(): array {
        $visible = self::discovery_tools();
        return array(
            'total' => count( self::tools() ),
            'visible' => count( $visible ),
            'code_read_visible' => isset( $visible['get_source_file'] ),
            'code_write_visible' => isset( $visible['preview_source_file_update'] ),
            'acf_options_read_visible' => isset( $visible['get_acf_option_values'] ),
            'acf_options_write_visible' => isset( $visible['update_acf_option_values'] ),
        );
    }

    private static function discovery_tools(): array {
        $visible = array();
        foreach ( self::tools() as $name => $tool ) {
            if ( ! self::tool_available_for_runtime( $name ) ) {
                continue;
            }
            $safe = self::sanitize_discovery_tool( $tool );
            if ( null === $safe ) {
                ALIFY_AI_Diagnostics::log(
                    'Skipped invalid MCP tool during action discovery.',
                    array( 'tool' => $name )
                );
                continue;
            }
            $visible[ $name ] = $safe;
        }
        return $visible;
    }

    private static function tool_available_for_runtime( string $name ): bool {
        if ( ! self::dependency_available_for_tool( $name ) ) {
            return false;
        }

        $requirement = self::tool_scope_requirement( $name );
        foreach ( $requirement['scopes'] as $scope ) {
            $allowed = ALIFY_AI_Auth::runtime_allows_scope( $scope );
            if ( true === $allowed ) {
                if ( 'any' === $requirement['mode'] ) {
                    return true;
                }
                continue;
            }
            if ( 'all' === $requirement['mode'] ) {
                return false;
            }
        }
        return 'all' === $requirement['mode'];
    }

    private static function dependency_available_for_tool( string $name ): bool {
        if ( str_starts_with( $name, 'get_acf_status' ) ) {
            return true;
        }
        if ( str_contains( $name, '_acf_' ) || str_starts_with( $name, 'list_acf_' ) || str_starts_with( $name, 'create_acf_' ) || str_starts_with( $name, 'update_acf_' ) || str_starts_with( $name, 'delete_acf_' ) || str_starts_with( $name, 'get_acf_' ) ) {
            return function_exists( 'acf_get_field_groups' );
        }

        if ( 'get_elementor_status' === $name ) {
            return true;
        }
        if ( str_contains( $name, 'elementor' ) ) {
            return class_exists( 'Elementor\\Plugin' );
        }

        if ( 'get_woocommerce_status' === $name ) {
            return true;
        }
        $woo_prefixes = array(
            'list_order_', 'list_orders', 'get_order', 'preview_order_',
            'list_products', 'get_product', 'preview_product_', 'list_product_',
            'get_product_', 'list_shipping_', 'get_shipping_', 'preview_shipping_',
            'list_tax_classes', 'get_tax_class', 'preview_tax_class_',
        );
        foreach ( $woo_prefixes as $prefix ) {
            if ( str_starts_with( $name, $prefix ) ) {
                return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
            }
        }
        return true;
    }

    /**
     * Map MCP tools to the same permission scopes enforced by their REST route.
     * Most inspection tools require read; only exceptions and mutating groups
     * are listed here.
     *
     * @return array{mode:string,scopes:array<int,string>}
     */
    private static function tool_scope_requirement( string $name ): array {
        $content_write = array( 'create_content','update_content','delete_content','restore_content','restore_content_revision' );
        if ( in_array( $name, $content_write, true ) ) {
            return array( 'mode' => 'all', 'scopes' => array( 'content' ) );
        }

        if ( preg_match( '/^(create|update|delete|attach|detach)_(post_type|taxonomy)/', $name ) || in_array( $name, array( 'create_term','update_term','delete_term' ), true ) ) {
            return array( 'mode' => 'all', 'scopes' => array( 'structure' ) );
        }

        if ( preg_match( '/^(create|update|delete)_acf_/', $name ) || 'update_acf_values' === $name ) {
            return array( 'mode' => 'all', 'scopes' => array( 'acf' ) );
        }

        if ( in_array( $name, array( 'upload_media_base64','update_media','delete_media','restore_media','replace_media_file_base64','edit_media_image','regenerate_media_metadata' ), true ) ) {
            return array( 'mode' => 'all', 'scopes' => array( 'media' ) );
        }

        if ( in_array( $name, array( 'assign_menu_location','unassign_menu_location','create_menu','update_menu','delete_menu','create_menu_item','update_menu_item','delete_menu_item','reorder_menu_items' ), true ) ) {
            return array( 'mode' => 'all', 'scopes' => array( 'menus' ) );
        }

        if ( str_starts_with( $name, 'preview_gutenberg_' ) || str_starts_with( $name, 'preview_elementor_' ) ) {
            return array( 'mode' => 'all', 'scopes' => array( 'design' ) );
        }

        if ( in_array( $name, array( 'get_post_seo','preview_post_seo_update','audit_seo' ), true ) ) {
            return array( 'mode' => 'all', 'scopes' => array( 'seo' ) );
        }

        if ( str_contains( $name, 'order' ) && ! in_array( $name, array( 'list_content_authors' ), true ) ) {
            return array( 'mode' => 'all', 'scopes' => array( 'orders' ) );
        }

        $commerce_writes = array(
            'preview_product_create','preview_product_update','preview_product_trash','preview_product_restore',
            'preview_product_attribute_create','preview_product_attribute_update','preview_product_attribute_delete',
            'preview_product_attribute_term_create','preview_product_attribute_term_update','preview_product_attribute_term_delete',
            'preview_product_variation_create','preview_product_variation_update','preview_product_variation_trash','preview_product_variation_restore',
            'preview_shipping_class_create','preview_shipping_class_update','preview_shipping_class_delete',
            'preview_tax_class_create','preview_tax_class_delete',
        );
        if ( in_array( $name, $commerce_writes, true ) ) {
            return array( 'mode' => 'all', 'scopes' => array( 'commerce' ) );
        }

        if ( 'preview_transaction' === $name ) {
            return array( 'mode' => 'all', 'scopes' => array( 'transactions' ) );
        }

        if ( in_array( $name, array( 'apply_approval','cancel_approval' ), true ) ) {
            return array( 'mode' => 'any', 'scopes' => array( 'design','seo','commerce','orders','transactions','code_write' ) );
        }

        if ( 'rollback_activity' === $name ) {
            return array( 'mode' => 'any', 'scopes' => array( 'content','structure','design','seo','acf','media','commerce','transactions' ) );
        }

        if ( 'preview_source_file_update' === $name ) {
            return array( 'mode' => 'all', 'scopes' => array( 'code_write' ) );
        }
        if ( in_array( $name, array( 'get_active_theme_source_info','list_active_plugins','list_source_files','get_source_file','search_source_code','inspect_frontend_page' ), true ) ) {
            return array( 'mode' => 'all', 'scopes' => array( 'code_read' ) );
        }

        return array( 'mode' => 'all', 'scopes' => array( 'read' ) );
    }

    /**
     * Normalize JSON Schema into a conservative subset accepted by ChatGPT's
     * MCP action scanner. Runtime validation remains authoritative in the REST
     * controllers, so discovery may safely simplify unsupported unions.
     */
    private static function sanitize_discovery_tool( array $tool ): ?array {
        $name = isset( $tool['name'] ) ? (string) $tool['name'] : '';
        if ( '' === $name || ! preg_match( '/^[A-Za-z0-9_.-]{1,128}$/', $name ) ) {
            return null;
        }
        $schema = isset( $tool['inputSchema'] ) && is_array( $tool['inputSchema'] ) ? $tool['inputSchema'] : array( 'type' => 'object' );
        $schema = self::sanitize_discovery_schema_node( $schema, 0 );
        if ( ! is_array( $schema ) ) {
            return null;
        }
        $schema['type'] = 'object';
        if ( ! isset( $schema['properties'] ) ) {
            $schema['properties'] = new stdClass();
        } elseif ( is_array( $schema['properties'] ) && ! $schema['properties'] ) {
            // PHP encodes an empty array as [] but JSON Schema requires the
            // properties keyword to be an object ({}), even when it is empty.
            $schema['properties'] = new stdClass();
        }
        if ( ! isset( $schema['additionalProperties'] ) ) {
            $schema['additionalProperties'] = false;
        }
        $tool['inputSchema'] = $schema;
        return $tool;
    }

    private static function sanitize_discovery_schema_node( $node, int $depth ) {
        if ( $depth > 14 ) {
            return array( 'type' => 'string', 'description' => 'Nested value.' );
        }
        if ( true === $node ) {
            return array( 'type' => 'string' );
        }
        if ( false === $node ) {
            return array( 'type' => 'string' );
        }
        if ( ! is_array( $node ) ) {
            return array( 'type' => 'string' );
        }
        if ( array() === $node ) {
            // An empty JSON Schema is {}, not []. PHP needs stdClass to
            // preserve that distinction when wp_json_encode serializes it.
            return new stdClass();
        }

        // ChatGPT's action compiler has historically rejected some valid JSON
        // Schema composition shapes. Pick a stable non-null branch for the
        // discovery contract while server-side validation keeps full fidelity.
        foreach ( array( 'oneOf', 'anyOf', 'allOf' ) as $composition ) {
            if ( isset( $node[ $composition ] ) && is_array( $node[ $composition ] ) && $node[ $composition ] ) {
                $branches = $node[ $composition ];
                $chosen = null;
                foreach ( $branches as $branch ) {
                    if ( is_array( $branch ) && 'null' !== (string) ( $branch['type'] ?? '' ) ) {
                        $chosen = $branch;
                        break;
                    }
                }
                if ( null === $chosen ) {
                    $chosen = array( 'type' => 'string' );
                }
                $outer_description = isset( $node['description'] ) ? (string) $node['description'] : '';
                $node = is_array( $chosen ) ? $chosen : array( 'type' => 'string' );
                if ( '' !== $outer_description && ! isset( $node['description'] ) ) {
                    $node['description'] = $outer_description;
                }
                break;
            }
        }

        $allowed = array( 'type','description','title','enum','default','minimum','maximum','minLength','maxLength','minItems','maxItems','items','properties','required','additionalProperties','format','pattern' );
        $clean = array();
        foreach ( $allowed as $key ) {
            if ( ! array_key_exists( $key, $node ) ) {
                continue;
            }
            $value = $node[ $key ];
            if ( 'properties' === $key && is_array( $value ) ) {
                $props = array();
                foreach ( $value as $prop_name => $prop_schema ) {
                    $props[ (string) $prop_name ] = self::sanitize_discovery_schema_node( $prop_schema, $depth + 1 );
                }
                $clean['properties'] = $props ? $props : new stdClass();
                continue;
            }
            if ( 'items' === $key ) {
                $clean['items'] = self::sanitize_discovery_schema_node( $value, $depth + 1 );
                continue;
            }
            if ( 'additionalProperties' === $key && is_array( $value ) ) {
                $clean['additionalProperties'] = self::sanitize_discovery_schema_node( $value, $depth + 1 );
                continue;
            }
            if ( 'additionalProperties' === $key ) {
                $clean['additionalProperties'] = (bool) $value;
                continue;
            }
            if ( 'required' === $key ) {
                if ( is_array( $value ) ) {
                    $clean['required'] = array_values( array_filter( array_map( 'strval', $value ), static fn( $v ) => '' !== $v ) );
                }
                continue;
            }
            if ( 'enum' === $key ) {
                if ( is_array( $value ) && $value ) {
                    $clean['enum'] = array_values( $value );
                }
                continue;
            }
            if ( 'type' === $key ) {
                $type = is_string( $value ) ? $value : '';
                $allowed_types = array( 'object','array','string','number','integer','boolean' );
                $clean['type'] = in_array( $type, $allowed_types, true ) ? $type : 'string';
                continue;
            }
            if ( 'exclusiveMinimum' === $key ) {
                continue;
            }
            $clean[ $key ] = $value;
        }

        // Translate exclusiveMinimum from the source schema to the closest
        // conservative primitive constraint supported by the discovery subset.
        if ( isset( $node['exclusiveMinimum'] ) && is_numeric( $node['exclusiveMinimum'] ) && ! isset( $clean['minimum'] ) ) {
            $clean['minimum'] = (float) $node['exclusiveMinimum'];
        }

        return $clean;
    }

    private static function rpc_result( $id, $result ): WP_REST_Response {
        return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ) );
    }

    private static function rpc_error( $id, int $code, string $message ): WP_REST_Response {
        return new WP_REST_Response(
            array(
                'jsonrpc' => '2.0',
                'id'      => $id,
                'error'   => array( 'code' => $code, 'message' => $message ),
            ),
            200
        );
    }

    private static function tool_result( $value, string $message = 'Request completed.' ): array {
        $json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        return array(
            'resultType'        => 'complete',
            '_meta'             => self::server_result_meta(),
            'content'           => array( array( 'type' => 'text', 'text' => $message . "\n" . ( $json ?: '{}' ) ) ),
            'structuredContent' => array( 'result' => $value ),
        );
    }

    private static function tool_error( string $message, $details = null ): array {
        $suffix = null === $details ? '' : "\n" . ( wp_json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '' );
        return array(
            'resultType' => 'complete',
            '_meta' => self::server_result_meta(),
            'isError' => true,
            'content' => array( array( 'type' => 'text', 'text' => $message . $suffix ) ),
        );
    }

    /**
     * Dispatch through the plugin's existing REST controllers so MCP and REST
     * behavior, scopes, approvals, audit logs, and rollback remain identical.
     */
    private static function dispatch( string $method, string $path, array $query = array(), ?array $body = null ) {
        $request = new WP_REST_Request( strtoupper( $method ), '/alify-ai/v1' . $path );
        if ( $query ) {
            $request->set_query_params( $query );
        }
        if ( null !== $body ) {
            $request->set_body_params( $body );
        }

        ALIFY_AI_Auth::begin_internal_mcp();
        try {
            $response = rest_do_request( $request );
        } finally {
            ALIFY_AI_Auth::end_internal_mcp();
        }

        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $status = (int) $response->get_status();
        $data   = $response->get_data();
        if ( $status >= 400 ) {
            $message = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'WordPress request failed.';
            return new WP_Error( 'alify_mcp_upstream', $message, array( 'status' => $status, 'response' => $data ) );
        }
        return $data;
    }

    private static function call_tool( string $name, array $args ): array {
        $route = self::tool_route( $name, $args );
        if ( is_wp_error( $route ) ) {
            return self::tool_error( $route->get_error_message(), $route->get_error_data() );
        }
        if ( ! is_array( $route ) ) {
            return self::tool_error( 'Tool routing failed.' );
        }

        $result = self::dispatch( $route['method'], $route['path'], $route['query'] ?? array(), $route['body'] ?? null );
        if ( is_wp_error( $result ) ) {
            return self::tool_error( $result->get_error_message(), $result->get_error_data() );
        }
        return self::tool_result( $result, (string) ( $route['message'] ?? 'Request completed.' ) );
    }

    private static function required_int( array $args, string $key ) {
        $value = absint( $args[ $key ] ?? 0 );
        return $value > 0 ? $value : new WP_Error( 'alify_mcp_invalid_argument', $key . ' must be a positive integer.' );
    }

    private static function required_string( array $args, string $key ) {
        $value = isset( $args[ $key ] ) ? sanitize_text_field( (string) $args[ $key ] ) : '';
        return '' !== $value ? $value : new WP_Error( 'alify_mcp_invalid_argument', $key . ' is required.' );
    }

    private static function content_path( string $type, ?int $id = null ): string {
        $type = sanitize_key( $type );
        $base = 'page' === $type ? '/pages' : ( 'post' === $type ? '/posts' : '/content/' . rawurlencode( $type ) );
        return null === $id ? $base : $base . '/' . $id;
    }

    private static function tool_route( string $name, array $args ) {
        $type = sanitize_key( (string) ( $args['type'] ?? 'page' ) );
        switch ( $name ) {
            case 'get_site_info':
                return array( 'method' => 'GET', 'path' => '/site', 'message' => 'Loaded WordPress site info.' );
            case 'get_capabilities':
                return array( 'method' => 'GET', 'path' => '/capabilities', 'message' => 'Loaded connector capabilities.' );
            case 'get_active_theme_source_info':
                return array( 'method' => 'GET', 'path' => '/code/theme', 'message' => 'Loaded active theme source-inspection info.' );
            case 'list_active_plugins':
                return array( 'method' => 'GET', 'path' => '/code/plugins', 'message' => 'Listed active plugins available for safe source inspection.' );
            case 'list_source_files':
                return array( 'method' => 'GET', 'path' => '/code/files', 'query' => array_filter( array( 'target'=>$args['target'] ?? 'theme', 'plugin'=>$args['plugin'] ?? '', 'path'=>$args['path'] ?? '' ), static fn( $v ) => '' !== (string) $v ), 'message' => 'Listed safe source files.' );
            case 'get_source_file':
                $path = self::required_string( $args, 'path' ); if ( is_wp_error( $path ) ) return $path;
                return array( 'method' => 'GET', 'path' => '/code/file', 'query' => array( 'target'=>$args['target'] ?? 'theme', 'plugin'=>$args['plugin'] ?? '', 'path'=>$path ), 'message' => 'Loaded source file.' );
            case 'search_source_code':
                $query = self::required_string( $args, 'query' ); if ( is_wp_error( $query ) ) return $query;
                return array( 'method' => 'GET', 'path' => '/code/search', 'query' => array_filter( array( 'target'=>$args['target'] ?? 'theme', 'plugin'=>$args['plugin'] ?? '', 'query'=>$query, 'path'=>$args['path'] ?? '', 'max_matches'=>isset( $args['max_matches'] ) ? absint( $args['max_matches'] ) : 50 ), static fn( $v ) => '' !== (string) $v ), 'message' => 'Searched source code.' );
            case 'inspect_frontend_page':
                return array( 'method' => 'GET', 'path' => '/frontend/inspect', 'query' => array( 'path'=>(string) ( $args['path'] ?? '/' ), 'include_html'=>array_key_exists( 'include_html', $args ) ? (bool) $args['include_html'] : true ), 'message' => 'Inspected rendered frontend page.' );
            case 'preview_source_file_update':
                return array( 'method' => 'POST', 'path' => '/code/preview-update', 'body' => $args, 'message' => 'Prepared source-code update proposal.' );
            case 'list_content':
                unset( $args['type'] );
                return array( 'method' => 'GET', 'path' => self::content_path( $type ), 'query' => $args, 'message' => 'Listed WordPress content.' );
            case 'get_content':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'GET', 'path' => self::content_path( $type, $id ), 'message' => 'Loaded WordPress content.' );
            case 'create_content':
                unset( $args['type'] );
                return array( 'method' => 'POST', 'path' => self::content_path( $type ), 'body' => $args, 'message' => 'Created WordPress content.' );
            case 'update_content':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                unset( $args['type'], $args['id'] );
                return array( 'method' => 'PATCH', 'path' => self::content_path( $type, $id ), 'body' => $args, 'message' => 'Updated WordPress content.' );
            case 'list_content_authors':
                return array( 'method' => 'GET', 'path' => '/content-authors', 'query' => array( 'type' => $type ), 'message' => 'Listed eligible content authors.' );
            case 'list_content_templates':
                if ( ! in_array( $type, array( 'page', 'post' ), true ) ) {
                    return new WP_Error( 'alify_mcp_invalid_argument', 'Templates are exposed by this tool for page or post.' );
                }
                $query = array();
                if ( ! empty( $args['post_id'] ) ) { $query['post_id'] = absint( $args['post_id'] ); }
                return array( 'method' => 'GET', 'path' => self::content_path( $type ) . '/templates', 'query' => $query, 'message' => 'Listed active-theme content templates.' );
            case 'delete_content':
                if ( ! in_array( $type, array( 'page', 'post' ), true ) ) {
                    return new WP_Error( 'alify_mcp_invalid_argument', 'Trash/permanent delete is exposed by this tool for page or post.' );
                }
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'DELETE', 'path' => self::content_path( $type, $id ), 'query' => array( 'force' => ! empty( $args['force'] ), 'confirm_permanent' => ! empty( $args['confirm_permanent'] ) ), 'message' => ! empty( $args['force'] ) ? 'Permanent deletion requested.' : 'Moved content to Trash.' );
            case 'restore_content':
                if ( ! in_array( $type, array( 'page', 'post' ), true ) ) {
                    return new WP_Error( 'alify_mcp_invalid_argument', 'Trash restore is exposed by this tool for page or post.' );
                }
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'POST', 'path' => self::content_path( $type, $id ) . '/restore', 'body' => array(), 'message' => 'Restored content from Trash.' );
            case 'list_content_revisions':
                if ( ! in_array( $type, array( 'page', 'post' ), true ) ) {
                    return new WP_Error( 'alify_mcp_invalid_argument', 'Revision tools are exposed for page or post.' );
                }
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                $query = array(); if ( ! empty( $args['per_page'] ) ) { $query['per_page'] = absint( $args['per_page'] ); }
                return array( 'method' => 'GET', 'path' => self::content_path( $type, $id ) . '/revisions', 'query' => $query, 'message' => 'Listed content revisions.' );
            case 'get_content_revision':
                if ( ! in_array( $type, array( 'page', 'post' ), true ) ) {
                    return new WP_Error( 'alify_mcp_invalid_argument', 'Revision tools are exposed for page or post.' );
                }
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                $revision_id = self::required_int( $args, 'revision_id' ); if ( is_wp_error( $revision_id ) ) return $revision_id;
                return array( 'method' => 'GET', 'path' => self::content_path( $type, $id ) . '/revisions/' . $revision_id, 'message' => 'Loaded content revision.' );
            case 'restore_content_revision':
                if ( ! in_array( $type, array( 'page', 'post' ), true ) ) {
                    return new WP_Error( 'alify_mcp_invalid_argument', 'Revision tools are exposed for page or post.' );
                }
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                $revision_id = self::required_int( $args, 'revision_id' ); if ( is_wp_error( $revision_id ) ) return $revision_id;
                return array( 'method' => 'POST', 'path' => self::content_path( $type, $id ) . '/revisions/' . $revision_id . '/restore', 'body' => array(), 'message' => 'Restored content revision.' );
            case 'list_post_types':
                return array( 'method' => 'GET', 'path' => '/post-types', 'message' => 'Listed post types.' );
            case 'get_post_type':
                $key = self::required_string( $args, 'key' ); if ( is_wp_error( $key ) ) return $key;
                return array( 'method' => 'GET', 'path' => '/post-types/' . rawurlencode( sanitize_key( $key ) ), 'message' => 'Loaded post type.' );
            case 'create_post_type':
                return array( 'method' => 'POST', 'path' => '/post-types', 'body' => $args, 'message' => 'Created custom post type.' );
            case 'update_post_type':
                $key = self::required_string( $args, 'key' ); if ( is_wp_error( $key ) ) return $key;
                unset( $args['key'] );
                return array( 'method' => 'PATCH', 'path' => '/post-types/' . rawurlencode( sanitize_key( $key ) ), 'body' => $args, 'message' => 'Updated custom post type.' );
            case 'delete_post_type':
                $key = self::required_string( $args, 'key' ); if ( is_wp_error( $key ) ) return $key;
                unset( $args['key'] );
                return array( 'method' => 'DELETE', 'path' => '/post-types/' . rawurlencode( sanitize_key( $key ) ), 'body' => $args, 'message' => 'Deleted custom post type registration.' );
            case 'list_taxonomies':
                return array( 'method' => 'GET', 'path' => '/taxonomies', 'message' => 'Listed taxonomies.' );
            case 'get_taxonomy':
                $key = self::required_string( $args, 'key' ); if ( is_wp_error( $key ) ) return $key;
                return array( 'method' => 'GET', 'path' => '/taxonomies/' . rawurlencode( sanitize_key( $key ) ), 'message' => 'Loaded taxonomy.' );
            case 'create_taxonomy':
                return array( 'method' => 'POST', 'path' => '/taxonomies', 'body' => $args, 'message' => 'Created taxonomy.' );
            case 'update_taxonomy':
                $key = self::required_string( $args, 'key' ); if ( is_wp_error( $key ) ) return $key;
                unset( $args['key'] );
                return array( 'method' => 'PATCH', 'path' => '/taxonomies/' . rawurlencode( sanitize_key( $key ) ), 'body' => $args, 'message' => 'Updated taxonomy.' );
            case 'delete_taxonomy':
                $key = self::required_string( $args, 'key' ); if ( is_wp_error( $key ) ) return $key;
                unset( $args['key'] );
                return array( 'method' => 'DELETE', 'path' => '/taxonomies/' . rawurlencode( sanitize_key( $key ) ), 'body' => $args, 'message' => 'Deleted taxonomy registration.' );
            case 'attach_taxonomy_object_type':
                $key = self::required_string( $args, 'key' ); if ( is_wp_error( $key ) ) return $key;
                $object_type = self::required_string( $args, 'object_type' ); if ( is_wp_error( $object_type ) ) return $object_type;
                return array( 'method' => 'POST', 'path' => '/taxonomies/' . rawurlencode( sanitize_key( $key ) ) . '/object-types/' . rawurlencode( sanitize_key( $object_type ) ), 'body' => array(), 'message' => 'Attached taxonomy to post type.' );
            case 'detach_taxonomy_object_type':
                $key = self::required_string( $args, 'key' ); if ( is_wp_error( $key ) ) return $key;
                $object_type = self::required_string( $args, 'object_type' ); if ( is_wp_error( $object_type ) ) return $object_type;
                return array( 'method' => 'DELETE', 'path' => '/taxonomies/' . rawurlencode( sanitize_key( $key ) ) . '/object-types/' . rawurlencode( sanitize_key( $object_type ) ), 'body' => array(), 'message' => 'Detached taxonomy from post type.' );
            case 'list_terms':
                $tax = self::required_string( $args, 'taxonomy' ); if ( is_wp_error( $tax ) ) return $tax;
                unset( $args['taxonomy'] );
                return array( 'method' => 'GET', 'path' => '/taxonomies/' . rawurlencode( sanitize_key( $tax ) ) . '/terms', 'query' => $args, 'message' => 'Listed taxonomy terms.' );
            case 'get_term':
                $tax = self::required_string( $args, 'taxonomy' ); if ( is_wp_error( $tax ) ) return $tax;
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'GET', 'path' => '/taxonomies/' . rawurlencode( sanitize_key( $tax ) ) . '/terms/' . $id, 'message' => 'Loaded taxonomy term.' );
            case 'create_term':
                $tax = self::required_string( $args, 'taxonomy' ); if ( is_wp_error( $tax ) ) return $tax;
                unset( $args['taxonomy'] );
                return array( 'method' => 'POST', 'path' => '/taxonomies/' . rawurlencode( sanitize_key( $tax ) ) . '/terms', 'body' => $args, 'message' => 'Created taxonomy term.' );
            case 'update_term':
                $tax = self::required_string( $args, 'taxonomy' ); if ( is_wp_error( $tax ) ) return $tax;
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                unset( $args['taxonomy'], $args['id'] );
                return array( 'method' => 'PATCH', 'path' => '/taxonomies/' . rawurlencode( sanitize_key( $tax ) ) . '/terms/' . $id, 'body' => $args, 'message' => 'Updated taxonomy term.' );
            case 'delete_term':
                $tax = self::required_string( $args, 'taxonomy' ); if ( is_wp_error( $tax ) ) return $tax;
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                unset( $args['taxonomy'], $args['id'] );
                return array( 'method' => 'DELETE', 'path' => '/taxonomies/' . rawurlencode( sanitize_key( $tax ) ) . '/terms/' . $id, 'body' => $args, 'message' => 'Deleted taxonomy term.' );
            case 'get_acf_status':
                return array( 'method' => 'GET', 'path' => '/acf/status', 'message' => 'Loaded ACF status.' );
            case 'list_acf_groups':
                return array( 'method' => 'GET', 'path' => '/acf/field-groups', 'message' => 'Listed ACF field groups.' );
            case 'get_acf_group':
                $key = self::required_string( $args, 'group_key' ); if ( is_wp_error( $key ) ) return $key;
                return array( 'method' => 'GET', 'path' => '/acf/field-groups/' . rawurlencode( $key ), 'message' => 'Loaded ACF field group.' );
            case 'create_acf_group':
                return array( 'method' => 'POST', 'path' => '/acf/field-groups', 'body' => $args, 'message' => 'Created ACF field group.' );
            case 'update_acf_group':
                $key = self::required_string( $args, 'group_key' ); if ( is_wp_error( $key ) ) return $key;
                unset( $args['group_key'] );
                return array( 'method' => 'PATCH', 'path' => '/acf/field-groups/' . rawurlencode( $key ), 'body' => $args, 'message' => 'Updated ACF field group.' );
            case 'delete_acf_group':
                $key = self::required_string( $args, 'group_key' ); if ( is_wp_error( $key ) ) return $key;
                unset( $args['group_key'] );
                return array( 'method' => 'DELETE', 'path' => '/acf/field-groups/' . rawurlencode( $key ), 'body' => $args, 'message' => 'Deleted ACF field group.' );
            case 'list_acf_fields':
                $key = self::required_string( $args, 'group_key' ); if ( is_wp_error( $key ) ) return $key;
                return array( 'method' => 'GET', 'path' => '/acf/field-groups/' . rawurlencode( $key ) . '/fields', 'message' => 'Listed ACF fields.' );
            case 'get_acf_field':
                $key = self::required_string( $args, 'field_key' ); if ( is_wp_error( $key ) ) return $key;
                return array( 'method' => 'GET', 'path' => '/acf/fields/' . rawurlencode( $key ), 'message' => 'Loaded ACF field.' );
            case 'create_acf_field':
                $key = self::required_string( $args, 'group_key' ); if ( is_wp_error( $key ) ) return $key;
                unset( $args['group_key'] );
                return array( 'method' => 'POST', 'path' => '/acf/field-groups/' . rawurlencode( $key ) . '/fields', 'body' => $args, 'message' => 'Created ACF field.' );
            case 'update_acf_field':
                $key = self::required_string( $args, 'field_key' ); if ( is_wp_error( $key ) ) return $key;
                unset( $args['field_key'] );
                return array( 'method' => 'PATCH', 'path' => '/acf/fields/' . rawurlencode( $key ), 'body' => $args, 'message' => 'Updated ACF field.' );
            case 'delete_acf_field':
                $key = self::required_string( $args, 'field_key' ); if ( is_wp_error( $key ) ) return $key;
                unset( $args['field_key'] );
                return array( 'method' => 'DELETE', 'path' => '/acf/fields/' . rawurlencode( $key ), 'body' => $args, 'message' => 'Deleted ACF field.' );
            case 'get_acf_values':
                $id = self::required_int( $args, 'post_id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'GET', 'path' => '/acf/values/' . $id, 'message' => 'Loaded ACF values.' );
            case 'update_acf_values':
                $id = self::required_int( $args, 'post_id' ); if ( is_wp_error( $id ) ) return $id;
                $values = isset( $args['values'] ) && is_array( $args['values'] ) ? $args['values'] : array();
                return array( 'method' => 'PATCH', 'path' => '/acf/values/' . $id, 'body' => array( 'values' => $values ), 'message' => 'Updated ACF values.' );
            case 'list_acf_option_pages':
                return array( 'method' => 'GET', 'path' => '/acf/options-pages', 'message' => 'Listed ACF options pages.' );
            case 'get_acf_option_values':
                $page = self::required_string( $args, 'page' ); if ( is_wp_error( $page ) ) return $page;
                return array( 'method' => 'GET', 'path' => '/acf/options-pages/' . rawurlencode( sanitize_key( $page ) ) . '/values', 'message' => 'Loaded ACF options-page values.' );
            case 'update_acf_option_values':
                $page = self::required_string( $args, 'page' ); if ( is_wp_error( $page ) ) return $page;
                $values = isset( $args['values'] ) && is_array( $args['values'] ) ? $args['values'] : array();
                return array( 'method' => 'PATCH', 'path' => '/acf/options-pages/' . rawurlencode( sanitize_key( $page ) ) . '/values', 'body' => array( 'values'=>$values ), 'message' => 'Updated ACF options-page values.' );
            case 'list_media':
                return array( 'method' => 'GET', 'path' => '/media', 'query' => $args, 'message' => 'Listed Media Library items.' );
            case 'get_media_limits':
                return array( 'method' => 'GET', 'path' => '/media/limits', 'message' => 'Loaded media upload limits.' );
            case 'upload_media_base64':
                return array( 'method' => 'POST', 'path' => '/media/upload-json', 'body' => $args, 'message' => 'Uploaded media file.' );
            case 'get_media':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'GET', 'path' => '/media/' . $id, 'message' => 'Loaded media item.' );
            case 'get_media_usages':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'GET', 'path' => '/media/' . $id . '/usages', 'message' => 'Loaded media usages.' );
            case 'update_media':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                unset( $args['id'] );
                return array( 'method' => 'PATCH', 'path' => '/media/' . $id, 'body' => $args, 'message' => 'Updated media metadata.' );
            case 'delete_media':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                unset( $args['id'] );
                return array( 'method' => 'DELETE', 'path' => '/media/' . $id, 'body' => $args, 'message' => 'Deleted or trashed media.' );
            case 'restore_media':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'POST', 'path' => '/media/' . $id . '/restore', 'body' => array(), 'message' => 'Restored media from Trash.' );
            case 'replace_media_file_base64':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                unset( $args['id'] );
                return array( 'method' => 'POST', 'path' => '/media/' . $id . '/replace-json', 'body' => $args, 'message' => 'Replaced media file in place.' );
            case 'edit_media_image':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                unset( $args['id'] );
                return array( 'method' => 'POST', 'path' => '/media/' . $id . '/edit', 'body' => $args, 'message' => 'Processed media image.' );
            case 'regenerate_media_metadata':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'POST', 'path' => '/media/' . $id . '/regenerate', 'body' => array(), 'message' => 'Regenerated media metadata.' );
            case 'list_menus':
                return array( 'method' => 'GET', 'path' => '/menus', 'message' => 'Listed menus.' );
            case 'list_menu_locations':
                return array( 'method' => 'GET', 'path' => '/menus/locations', 'message' => 'Listed registered menu locations.' );
            case 'assign_menu_location':
                $location = self::required_string( $args, 'location' ); if ( is_wp_error( $location ) ) return $location;
                $menu_id = self::required_int( $args, 'menu_id' ); if ( is_wp_error( $menu_id ) ) return $menu_id;
                return array( 'method' => 'POST', 'path' => '/menus/locations/' . rawurlencode( $location ), 'body' => array( 'menu_id' => $menu_id ), 'message' => 'Assigned menu location.' );
            case 'unassign_menu_location':
                $location = self::required_string( $args, 'location' ); if ( is_wp_error( $location ) ) return $location;
                return array( 'method' => 'DELETE', 'path' => '/menus/locations/' . rawurlencode( $location ), 'body' => array(), 'message' => 'Unassigned menu location.' );
            case 'get_menu':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'GET', 'path' => '/menus/' . $id, 'message' => 'Loaded menu.' );
            case 'create_menu':
                return array( 'method' => 'POST', 'path' => '/menus', 'body' => $args, 'message' => 'Created menu.' );
            case 'update_menu':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                unset( $args['id'] );
                return array( 'method' => 'PATCH', 'path' => '/menus/' . $id, 'body' => $args, 'message' => 'Updated menu.' );
            case 'delete_menu':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                unset( $args['id'] );
                return array( 'method' => 'DELETE', 'path' => '/menus/' . $id, 'body' => $args, 'message' => 'Deleted menu.' );
            case 'list_menu_items':
                $id = self::required_int( $args, 'menu_id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'GET', 'path' => '/menus/' . $id . '/items', 'message' => 'Listed menu items.' );
            case 'create_menu_item':
                $id = self::required_int( $args, 'menu_id' ); if ( is_wp_error( $id ) ) return $id;
                unset( $args['menu_id'] );
                return array( 'method' => 'POST', 'path' => '/menus/' . $id . '/items', 'body' => $args, 'message' => 'Created menu item.' );
            case 'update_menu_item':
                $menu = self::required_int( $args, 'menu_id' ); if ( is_wp_error( $menu ) ) return $menu;
                $item = self::required_int( $args, 'item_id' ); if ( is_wp_error( $item ) ) return $item;
                unset( $args['menu_id'], $args['item_id'] );
                return array( 'method' => 'PATCH', 'path' => '/menus/' . $menu . '/items/' . $item, 'body' => $args, 'message' => 'Updated menu item.' );
            case 'delete_menu_item':
                $menu = self::required_int( $args, 'menu_id' ); if ( is_wp_error( $menu ) ) return $menu;
                $item = self::required_int( $args, 'item_id' ); if ( is_wp_error( $item ) ) return $item;
                unset( $args['menu_id'], $args['item_id'] );
                return array( 'method' => 'DELETE', 'path' => '/menus/' . $menu . '/items/' . $item, 'body' => $args, 'message' => 'Deleted menu item.' );
            case 'reorder_menu_items':
                $menu = self::required_int( $args, 'menu_id' ); if ( is_wp_error( $menu ) ) return $menu;
                unset( $args['menu_id'] );
                return array( 'method' => 'POST', 'path' => '/menus/' . $menu . '/items/reorder', 'body' => $args, 'message' => 'Reordered menu items.' );
            case 'get_gutenberg_document':
                $id = self::required_int( $args, 'post_id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'GET', 'path' => '/design/gutenberg/' . $id, 'message' => 'Loaded Gutenberg document.' );
            case 'preview_gutenberg_changes':
                $id = self::required_int( $args, 'post_id' ); if ( is_wp_error( $id ) ) return $id;
                unset( $args['post_id'] );
                return array( 'method' => 'POST', 'path' => '/design/gutenberg/' . $id . '/preview', 'body' => $args, 'message' => 'Created Gutenberg preview; no document changes applied yet.' );
            case 'get_gutenberg_status':
                return array( 'method' => 'GET', 'path' => '/design/gutenberg/status', 'message' => 'Loaded Gutenberg capability status.' );
            case 'list_gutenberg_block_types':
                return array( 'method' => 'GET', 'path' => '/design/gutenberg/block-types', 'query' => $args, 'message' => 'Listed registered Gutenberg block types.' );
            case 'get_gutenberg_block_type':
                $name = self::required_string( $args, 'name' ); if ( is_wp_error( $name ) ) return $name;
                return array( 'method' => 'GET', 'path' => '/design/gutenberg/block-type', 'query' => array( 'name' => $name ), 'message' => 'Loaded Gutenberg block type schema.' );
            case 'validate_gutenberg_content':
                return array( 'method' => 'POST', 'path' => '/design/gutenberg/validate', 'body' => $args, 'message' => 'Validated Gutenberg block content.' );
            case 'list_gutenberg_patterns':
                return array( 'method' => 'GET', 'path' => '/design/gutenberg/patterns', 'query' => $args, 'message' => 'Listed Gutenberg patterns.' );
            case 'get_registered_gutenberg_pattern':
                $name = self::required_string( $args, 'name' ); if ( is_wp_error( $name ) ) return $name;
                return array( 'method' => 'GET', 'path' => '/design/gutenberg/patterns/registered', 'query' => array( 'name' => $name ), 'message' => 'Loaded registered Gutenberg pattern.' );
            case 'get_user_gutenberg_pattern':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'GET', 'path' => '/design/gutenberg/patterns/' . $id, 'message' => 'Loaded user Gutenberg pattern.' );
            case 'preview_gutenberg_pattern_create':
                $args['action'] = 'create';
                return array( 'method' => 'POST', 'path' => '/design/gutenberg/patterns/preview', 'body' => $args, 'message' => 'Created Gutenberg pattern proposal; no pattern created yet.' );
            case 'preview_gutenberg_pattern_change':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                unset( $args['id'] );
                return array( 'method' => 'POST', 'path' => '/design/gutenberg/patterns/' . $id . '/preview', 'body' => $args, 'message' => 'Created Gutenberg pattern change proposal.' );
            case 'list_gutenberg_templates':
                return array( 'method' => 'GET', 'path' => '/design/gutenberg/templates', 'query' => $args, 'message' => 'Listed Gutenberg templates.' );
            case 'get_gutenberg_template':
                $id = self::required_string( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'GET', 'path' => '/design/gutenberg/template', 'query' => array( 'id' => $id, 'type' => (string) ( $args['type'] ?? 'wp_template' ) ), 'message' => 'Loaded Gutenberg template.' );
            case 'preview_gutenberg_template_change':
                return array( 'method' => 'POST', 'path' => '/design/gutenberg/templates/preview', 'body' => $args, 'message' => 'Created Gutenberg template proposal; no template change applied yet.' );
            case 'get_elementor_status':
                return array( 'method' => 'GET', 'path' => '/design/elementor/status', 'message' => 'Loaded Elementor capability status.' );
            case 'list_elementor_breakpoints':
                return array( 'method' => 'GET', 'path' => '/design/elementor/breakpoints', 'message' => 'Listed Elementor responsive breakpoints.' );
            case 'list_elementor_widgets':
                return array( 'method' => 'GET', 'path' => '/design/elementor/widgets', 'query' => $args, 'message' => 'Listed registered Elementor widgets and control summaries.' );
            case 'get_elementor_widget':
                $name = self::required_string( $args, 'name' ); if ( is_wp_error( $name ) ) return $name;
                return array( 'method' => 'GET', 'path' => '/design/elementor/widgets/' . rawurlencode( $name ), 'message' => 'Loaded Elementor widget control schema.' );
            case 'get_elementor_document':
                $id = self::required_int( $args, 'post_id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'GET', 'path' => '/design/elementor/' . $id, 'message' => 'Loaded Elementor document, page settings and validation.' );
            case 'validate_elementor_document':
                $id = self::required_int( $args, 'post_id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'GET', 'path' => '/design/elementor/' . $id . '/validate', 'message' => 'Validated Elementor document structure and registered widgets/controls.' );
            case 'preview_elementor_changes':
                $id = self::required_int( $args, 'post_id' ); if ( is_wp_error( $id ) ) return $id;
                unset( $args['post_id'] );
                return array( 'method' => 'POST', 'path' => '/design/elementor/' . $id . '/preview', 'body' => $args, 'message' => 'Created validated Elementor preview; no document changes applied yet.' );
            case 'list_elementor_templates':
                return array( 'method' => 'GET', 'path' => '/design/elementor/templates', 'query' => $args, 'message' => 'Listed local Elementor library templates.' );
            case 'get_elementor_template':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'GET', 'path' => '/design/elementor/templates/' . $id, 'message' => 'Loaded Elementor template document.' );
            case 'preview_elementor_template_create':
                return array( 'method' => 'POST', 'path' => '/design/elementor/templates/preview', 'body' => $args, 'message' => 'Created Elementor template creation proposal; no template written yet.' );
            case 'preview_elementor_template_change':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                unset( $args['id'] );
                return array( 'method' => 'POST', 'path' => '/design/elementor/templates/' . $id . '/preview', 'body' => $args, 'message' => 'Created Elementor template change proposal; no template changes applied yet.' );
            case 'get_elementor_globals':
                return array( 'method' => 'GET', 'path' => '/design/elementor/globals', 'message' => 'Loaded active Elementor Kit global colors, typography and settings.' );
            case 'preview_elementor_globals':
                return array( 'method' => 'POST', 'path' => '/design/elementor/globals/preview', 'body' => $args, 'message' => 'Created Elementor global-style proposal; no Kit settings applied yet.' );
            case 'get_seo_status':
                return array( 'method' => 'GET', 'path' => '/seo/status', 'message' => 'Loaded SEO provider status.' );
            case 'get_post_seo':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                $query = array(); if ( ! empty( $args['provider'] ) ) $query['provider'] = sanitize_key( (string) $args['provider'] );
                return array( 'method' => 'GET', 'path' => '/seo/posts/' . $id, 'query' => $query, 'message' => 'Loaded normalized post SEO metadata.' );
            case 'preview_post_seo_update':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                unset( $args['id'] );
                return array( 'method' => 'POST', 'path' => '/seo/posts/' . $id . '/preview', 'body' => $args, 'message' => 'Created SEO change proposal.' );
            case 'audit_seo':
                return array( 'method' => 'GET', 'path' => '/seo/audit', 'query' => $args, 'message' => 'Audited WordPress SEO metadata.' );
            case 'get_woocommerce_status':
                return array( 'method' => 'GET', 'path' => '/woocommerce/status', 'message' => 'Loaded WooCommerce connector status.' );
            case 'list_order_statuses':
                return array( 'method'=>'GET', 'path'=>'/woocommerce/order-statuses', 'message'=>'Listed WooCommerce order statuses.' );
            case 'list_orders':
                return array( 'method'=>'GET', 'path'=>'/woocommerce/orders', 'query'=>$args, 'message'=>'Listed WooCommerce orders.' );
            case 'get_order':
                $id=self::required_int($args,'id'); if(is_wp_error($id))return $id;
                return array( 'method'=>'GET', 'path'=>'/woocommerce/orders/'.$id, 'message'=>'Loaded WooCommerce order.' );
            case 'preview_order_create':
                $order=isset($args['order'])&&is_array($args['order'])?$args['order']:$args;
                return array( 'method'=>'POST', 'path'=>'/woocommerce/orders/preview', 'body'=>$order, 'message'=>'Created order proposal; no order created yet.' );
            case 'preview_order_update':
                $id=self::required_int($args,'id'); if(is_wp_error($id))return $id; $changes=(array)($args['changes']??array());
                return array( 'method'=>'POST', 'path'=>'/woocommerce/orders/'.$id.'/preview', 'body'=>$changes, 'message'=>'Created order update proposal; no order changed yet.' );
            case 'preview_order_item_create':
                $id=self::required_int($args,'order_id'); if(is_wp_error($id))return $id; $item=(array)($args['item']??array());
                return array( 'method'=>'POST', 'path'=>'/woocommerce/orders/'.$id.'/items/preview', 'body'=>$item, 'message'=>'Created order line-item proposal.' );
            case 'preview_order_item_update':
                $id=self::required_int($args,'order_id'); if(is_wp_error($id))return $id; $item=self::required_int($args,'item_id'); if(is_wp_error($item))return $item; $changes=(array)($args['changes']??array()); $changes['action']='update';
                return array( 'method'=>'POST', 'path'=>'/woocommerce/orders/'.$id.'/items/'.$item.'/preview', 'body'=>$changes, 'message'=>'Created order line-item update proposal.' );
            case 'preview_order_item_delete':
                $id=self::required_int($args,'order_id'); if(is_wp_error($id))return $id; $item=self::required_int($args,'item_id'); if(is_wp_error($item))return $item;
                return array( 'method'=>'POST', 'path'=>'/woocommerce/orders/'.$id.'/items/'.$item.'/preview', 'body'=>array('action'=>'delete','confirm_delete'=>(bool)($args['confirm_delete']??false),'confirm_financial_totals'=>(bool)($args['confirm_financial_totals']??false)), 'message'=>'Created order line-item deletion proposal.' );
            case 'list_order_notes':
                $id=self::required_int($args,'order_id'); if(is_wp_error($id))return $id; $q=$args; unset($q['order_id']);
                return array( 'method'=>'GET', 'path'=>'/woocommerce/orders/'.$id.'/notes', 'query'=>$q, 'message'=>'Listed WooCommerce order notes.' );
            case 'preview_order_note_create':
                $id=self::required_int($args,'order_id'); if(is_wp_error($id))return $id; $body=$args; unset($body['order_id']);
                return array( 'method'=>'POST', 'path'=>'/woocommerce/orders/'.$id.'/notes/preview', 'body'=>$body, 'message'=>'Created order-note proposal.' );
            case 'preview_order_note_delete':
                $id=self::required_int($args,'order_id'); if(is_wp_error($id))return $id; $note=self::required_int($args,'note_id'); if(is_wp_error($note))return $note;
                return array( 'method'=>'POST', 'path'=>'/woocommerce/orders/'.$id.'/notes/'.$note.'/preview', 'body'=>array('confirm_delete'=>(bool)($args['confirm_delete']??false)), 'message'=>'Created order-note deletion proposal.' );
            case 'list_products':
                return array( 'method' => 'GET', 'path' => '/woocommerce/products', 'query' => $args, 'message' => 'Listed WooCommerce products.' );
            case 'get_product':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'GET', 'path' => '/woocommerce/products/' . $id, 'message' => 'Loaded WooCommerce product.' );
            case 'preview_product_create':
                $product = isset( $args['product'] ) && is_array( $args['product'] ) ? $args['product'] : $args;
                return array( 'method' => 'POST', 'path' => '/woocommerce/products/preview', 'body' => $product, 'message' => 'Created product proposal; no product created yet.' );
            case 'preview_product_update':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                $changes = isset( $args['changes'] ) && is_array( $args['changes'] ) ? $args['changes'] : array();
                return array( 'method' => 'POST', 'path' => '/woocommerce/products/' . $id . '/preview', 'body' => $changes, 'message' => 'Created product update proposal; no product changes applied yet.' );
            case 'preview_product_trash':
            case 'preview_product_restore':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                $action = 'preview_product_trash' === $name ? 'product.trash' : 'product.restore';
                return array( 'method' => 'POST', 'path' => '/woocommerce/catalog/preview', 'body' => array( 'operation'=>$action, 'product_id'=>$id ), 'message' => 'Created product lifecycle proposal; no catalog changes applied yet.' );
            case 'list_product_attributes':
                return array( 'method'=>'GET', 'path'=>'/woocommerce/attributes', 'message'=>'Listed global WooCommerce product attributes.' );
            case 'get_product_attribute':
                $id=self::required_int($args,'id'); if(is_wp_error($id))return $id;
                return array('method'=>'GET','path'=>'/woocommerce/attributes/'.$id,'message'=>'Loaded global product attribute.');
            case 'list_product_attribute_terms':
                $id=self::required_int($args,'attribute_id'); if(is_wp_error($id))return $id; $q=$args; unset($q['attribute_id']);
                return array('method'=>'GET','path'=>'/woocommerce/attributes/'.$id.'/terms','query'=>$q,'message'=>'Listed global attribute options.');
            case 'get_product_attribute_term':
                $id=self::required_int($args,'attribute_id'); if(is_wp_error($id))return $id; $term=self::required_int($args,'term_id'); if(is_wp_error($term))return $term;
                return array('method'=>'GET','path'=>'/woocommerce/attributes/'.$id.'/terms/'.$term,'message'=>'Loaded global attribute option.');
            case 'preview_product_attribute_create':
                return array('method'=>'POST','path'=>'/woocommerce/catalog/preview','body'=>array('operation'=>'attribute.create','data'=>(array)($args['attribute']??array())),'message'=>'Created attribute proposal.');
            case 'preview_product_attribute_update':
                $id=self::required_int($args,'id'); if(is_wp_error($id))return $id;
                return array('method'=>'POST','path'=>'/woocommerce/catalog/preview','body'=>array('operation'=>'attribute.update','attribute_id'=>$id,'data'=>(array)($args['changes']??array())),'message'=>'Created attribute update proposal.');
            case 'preview_product_attribute_delete':
                $id=self::required_int($args,'id'); if(is_wp_error($id))return $id;
                return array('method'=>'POST','path'=>'/woocommerce/catalog/preview','body'=>array('operation'=>'attribute.delete','attribute_id'=>$id,'confirm_delete'=>(bool)($args['confirm_delete']??false),'confirm_term_deletion'=>(bool)($args['confirm_term_deletion']??false)),'message'=>'Created attribute deletion proposal.');
            case 'preview_product_attribute_term_create':
                $id=self::required_int($args,'attribute_id'); if(is_wp_error($id))return $id;
                return array('method'=>'POST','path'=>'/woocommerce/catalog/preview','body'=>array('operation'=>'attribute_term.create','attribute_id'=>$id,'data'=>(array)($args['term']??array())),'message'=>'Created attribute term proposal.');
            case 'preview_product_attribute_term_update':
                $id=self::required_int($args,'attribute_id'); if(is_wp_error($id))return $id; $term=self::required_int($args,'term_id'); if(is_wp_error($term))return $term;
                return array('method'=>'POST','path'=>'/woocommerce/catalog/preview','body'=>array('operation'=>'attribute_term.update','attribute_id'=>$id,'term_id'=>$term,'data'=>(array)($args['changes']??array())),'message'=>'Created attribute term update proposal.');
            case 'preview_product_attribute_term_delete':
                $id=self::required_int($args,'attribute_id'); if(is_wp_error($id))return $id; $term=self::required_int($args,'term_id'); if(is_wp_error($term))return $term;
                return array('method'=>'POST','path'=>'/woocommerce/catalog/preview','body'=>array('operation'=>'attribute_term.delete','attribute_id'=>$id,'term_id'=>$term,'confirm_delete'=>(bool)($args['confirm_delete']??false)),'message'=>'Created attribute term deletion proposal.');
            case 'list_product_variations':
                $id=self::required_int($args,'product_id'); if(is_wp_error($id))return $id;
                return array('method'=>'GET','path'=>'/woocommerce/products/'.$id.'/variations','query'=>array('page'=>max(1,(int)($args['page']??1)),'per_page'=>max(1,min(100,(int)($args['per_page']??50)))),'message'=>'Listed product variations.');
            case 'get_product_variation':
                $id=self::required_int($args,'product_id'); if(is_wp_error($id))return $id; $vid=self::required_int($args,'variation_id'); if(is_wp_error($vid))return $vid;
                return array('method'=>'GET','path'=>'/woocommerce/products/'.$id.'/variations/'.$vid,'message'=>'Loaded product variation.');
            case 'preview_product_variation_create':
                $id=self::required_int($args,'product_id'); if(is_wp_error($id))return $id;
                return array('method'=>'POST','path'=>'/woocommerce/catalog/preview','body'=>array('operation'=>'variation.create','product_id'=>$id,'data'=>(array)($args['variation']??array())),'message'=>'Created variation proposal.');
            case 'preview_product_variation_update':
                $id=self::required_int($args,'product_id'); if(is_wp_error($id))return $id; $vid=self::required_int($args,'variation_id'); if(is_wp_error($vid))return $vid;
                return array('method'=>'POST','path'=>'/woocommerce/catalog/preview','body'=>array('operation'=>'variation.update','product_id'=>$id,'variation_id'=>$vid,'data'=>(array)($args['changes']??array())),'message'=>'Created variation update proposal.');
            case 'preview_product_variation_trash':
            case 'preview_product_variation_restore':
                $id=self::required_int($args,'product_id'); if(is_wp_error($id))return $id; $vid=self::required_int($args,'variation_id'); if(is_wp_error($vid))return $vid; $action='preview_product_variation_trash'===$name?'variation.trash':'variation.restore';
                return array('method'=>'POST','path'=>'/woocommerce/catalog/preview','body'=>array('operation'=>$action,'product_id'=>$id,'variation_id'=>$vid),'message'=>'Created variation lifecycle proposal.');
            case 'list_shipping_classes':
                return array('method'=>'GET','path'=>'/woocommerce/shipping-classes','message'=>'Listed WooCommerce shipping classes.');
            case 'get_shipping_class':
                $id=self::required_int($args,'id'); if(is_wp_error($id))return $id; return array('method'=>'GET','path'=>'/woocommerce/shipping-classes/'.$id,'message'=>'Loaded shipping class.');
            case 'preview_shipping_class_create':
                return array('method'=>'POST','path'=>'/woocommerce/catalog/preview','body'=>array('operation'=>'shipping_class.create','data'=>(array)($args['shipping_class']??array())),'message'=>'Created shipping class proposal.');
            case 'preview_shipping_class_update':
                $id=self::required_int($args,'id'); if(is_wp_error($id))return $id; return array('method'=>'POST','path'=>'/woocommerce/catalog/preview','body'=>array('operation'=>'shipping_class.update','term_id'=>$id,'data'=>(array)($args['changes']??array())),'message'=>'Created shipping class update proposal.');
            case 'preview_shipping_class_delete':
                $id=self::required_int($args,'id'); if(is_wp_error($id))return $id; return array('method'=>'POST','path'=>'/woocommerce/catalog/preview','body'=>array('operation'=>'shipping_class.delete','term_id'=>$id,'confirm_delete'=>(bool)($args['confirm_delete']??false),'confirm_in_use'=>(bool)($args['confirm_in_use']??false)),'message'=>'Created shipping class deletion proposal.');
            case 'list_tax_classes':
                return array('method'=>'GET','path'=>'/woocommerce/tax-classes','message'=>'Listed WooCommerce tax classes.');
            case 'get_tax_class':
                $slug=sanitize_title((string)($args['slug']??'')); if(''===$slug)return new WP_Error('alify_ai_tool_argument_required','slug is required.',array('status'=>400)); return array('method'=>'GET','path'=>'/woocommerce/tax-classes/'.$slug,'message'=>'Loaded tax class.');
            case 'preview_tax_class_create':
                return array('method'=>'POST','path'=>'/woocommerce/catalog/preview','body'=>array('operation'=>'tax_class.create','data'=>(array)($args['tax_class']??array())),'message'=>'Created tax class proposal.');
            case 'preview_tax_class_delete':
                $slug=sanitize_title((string)($args['slug']??'')); if(''===$slug)return new WP_Error('alify_ai_tool_argument_required','slug is required.',array('status'=>400)); return array('method'=>'POST','path'=>'/woocommerce/catalog/preview','body'=>array('operation'=>'tax_class.delete','slug'=>$slug,'confirm_delete'=>(bool)($args['confirm_delete']??false),'confirm_in_use'=>(bool)($args['confirm_in_use']??false)),'message'=>'Created tax class deletion proposal.');
            case 'preview_transaction':
                return array( 'method' => 'POST', 'path' => '/transactions/preview', 'body' => $args, 'message' => 'Created transaction proposal; no target changes applied yet.' );
            case 'list_approvals':
                return array( 'method' => 'GET', 'path' => '/approvals', 'query' => $args, 'message' => 'Listed change proposals.' );
            case 'get_approval':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'GET', 'path' => '/approvals/' . $id, 'message' => 'Loaded change proposal.' );
            case 'apply_approval':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'POST', 'path' => '/approvals/' . $id . '/apply', 'body' => array(), 'message' => 'Applied approved WordPress change.' );
            case 'cancel_approval':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'POST', 'path' => '/approvals/' . $id . '/cancel', 'body' => array(), 'message' => 'Cancelled change proposal.' );
            case 'list_activity':
                return array( 'method' => 'GET', 'path' => '/activity', 'query' => $args, 'message' => 'Listed ALIFY connector activity.' );
            case 'rollback_activity':
                $id = self::required_int( $args, 'id' ); if ( is_wp_error( $id ) ) return $id;
                return array( 'method' => 'POST', 'path' => '/rollback/' . $id, 'body' => array(), 'message' => 'Rollback requested.' );
        }
        return new WP_Error( 'alify_mcp_unknown_tool', 'Unknown tool.' );
    }

    private static function schema_object( array $properties = array(), array $required = array(), bool $additional = false ): array {
        $schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => $additional );
        if ( $required ) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    private static function s( string $type = 'string', string $description = '' ): array {
        $out = array( 'type' => $type );
        if ( '' !== $description ) $out['description'] = $description;
        return $out;
    }

    private static function post_type_tool_schema( bool $creating ): array {
        $str = self::s();
        $bool = self::s( 'boolean' );
        $labels = self::schema_object(
            array_fill_keys(
                array( 'name','singular_name','add_new','add_new_item','edit_item','new_item','view_item','view_items','search_items','not_found','not_found_in_trash','parent_item_colon','all_items','archives','attributes','insert_into_item','uploaded_to_this_item','featured_image','set_featured_image','remove_featured_image','use_featured_image','menu_name','filter_items_list','filter_by_date','items_list_navigation','items_list','item_published','item_published_privately','item_reverted_to_draft','item_trashed','item_scheduled','item_updated','item_link','item_link_description' ),
                $str
            )
        );
        $caps = self::schema_object(
            array_fill_keys(
                array( 'edit_post','read_post','delete_post','edit_posts','edit_others_posts','delete_posts','publish_posts','read_private_posts','read','delete_private_posts','delete_published_posts','delete_others_posts','edit_private_posts','edit_published_posts','create_posts' ),
                $str
            )
        );
        $props = array(
            'key' => array( 'type' => 'string', 'maxLength' => 20 ),
            'label' => $str, 'singular' => $str, 'labels' => $labels, 'description' => $str,
            'public' => $bool, 'publicly_queryable' => $bool, 'exclude_from_search' => $bool, 'show_ui' => $bool,
            'show_in_menu' => array( 'oneOf' => array( $bool, $str ) ), 'show_in_nav_menus' => $bool, 'show_in_admin_bar' => $bool,
            'show_in_rest' => $bool, 'rest_base' => $str, 'rest_namespace' => $str, 'late_route_registration' => $bool,
            'menu_position' => array( 'type' => array( 'integer', 'null' ) ), 'menu_icon' => array( 'type' => array( 'string', 'null' ) ),
            'capability_type' => array( 'oneOf' => array( $str, array( 'type' => 'array', 'minItems' => 2, 'maxItems' => 2, 'items' => $str ) ) ),
            'capabilities' => $caps, 'map_meta_cap' => $bool, 'hierarchical' => $bool,
            'supports' => array( 'oneOf' => array(
                array( 'type' => 'boolean', 'enum' => array( false ) ),
                array( 'type' => 'array', 'items' => array( 'oneOf' => array( $str, array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 2, 'items' => true ) ) ) ),
            ) ),
            'taxonomies' => array( 'type' => 'array', 'items' => $str ),
            'has_archive' => array( 'oneOf' => array( $bool, $str ) ),
            'rewrite_slug' => $str,
            'rewrite' => array( 'oneOf' => array( $bool, self::schema_object( array( 'slug' => $str, 'with_front' => $bool, 'feeds' => $bool, 'pages' => $bool, 'ep_mask' => self::s( 'integer' ) ) ) ) ),
            'query_var' => array( 'oneOf' => array( $bool, $str ) ), 'can_export' => $bool,
            'delete_with_user' => array( 'type' => array( 'boolean', 'null' ) ),
            'template' => array( 'type' => 'array', 'items' => array( 'type' => 'array' ) ),
            'template_lock' => array( 'oneOf' => array( array( 'type' => 'boolean', 'enum' => array( false ) ), array( 'type' => 'string', 'enum' => array( 'all', 'insert', 'contentOnly' ) ) ) ),
        );
        return self::schema_object( $props, $creating ? array( 'key', 'label' ) : array() );
    }

    private static function taxonomy_tool_schema( bool $creating ): array {
        $str = self::s(); $bool = self::s( 'boolean' );
        $label_keys = array( 'name','singular_name','menu_name','search_items','popular_items','all_items','parent_item','parent_item_colon','name_field_description','slug_field_description','parent_field_description','desc_field_description','edit_item','view_item','update_item','add_new_item','new_item_name','separate_items_with_commas','add_or_remove_items','choose_from_most_used','not_found','no_terms','filter_by_item','items_list_navigation','items_list','most_used','back_to_items','item_link','item_link_description' );
        $labels = self::schema_object( array_fill_keys( $label_keys, $str ) );
        $caps = self::schema_object( array_fill_keys( array( 'manage_terms','edit_terms','delete_terms','assign_terms' ), $str ) );
        $props = array(
            'key' => array( 'type' => 'string', 'maxLength' => 32 ), 'label' => $str, 'singular' => $str, 'labels' => $labels, 'description' => $str,
            'object_types' => array( 'type' => 'array', 'items' => $str, 'uniqueItems' => true ),
            'public' => $bool, 'publicly_queryable' => $bool, 'hierarchical' => $bool, 'show_ui' => $bool, 'show_in_menu' => $bool,
            'show_in_nav_menus' => $bool, 'show_tagcloud' => $bool, 'show_in_quick_edit' => $bool, 'show_admin_column' => $bool,
            'show_in_rest' => $bool, 'rest_base' => $str, 'rest_namespace' => $str, 'capabilities' => $caps,
            'rewrite_slug' => $str,
            'rewrite' => array( 'oneOf' => array( $bool, self::schema_object( array( 'slug' => $str, 'with_front' => $bool, 'hierarchical' => $bool, 'ep_mask' => self::s( 'integer' ) ) ) ) ),
            'query_var' => array( 'oneOf' => array( $bool, $str ) ), 'sort' => $bool,
            'default_term' => array( 'oneOf' => array( array( 'type' => 'null' ), self::schema_object( array( 'name' => $str, 'slug' => $str, 'description' => $str ), array( 'name' ) ) ) ),
        );
        return self::schema_object( $props, $creating ? array( 'key', 'label' ) : array() );
    }

    private static function acf_group_tool_schema( bool $creating ): array {
        $str = self::s(); $bool = self::s( 'boolean' );
        $rule = self::schema_object( array( 'param' => $str, 'operator' => array( 'type' => 'string', 'enum' => array( '==','!=' ) ), 'value' => $str ), array( 'param','operator','value' ) );
        $props = array(
            'key' => array( 'type' => 'string', 'pattern' => '^group_' ), 'title' => $str, 'active' => $bool,
            'location' => array( 'type' => 'array', 'items' => array( 'type' => 'array', 'items' => $rule ) ),
            'menu_order' => self::s( 'integer' ), 'position' => array( 'type' => 'string', 'enum' => array( 'normal','side','acf_after_title' ) ),
            'style' => array( 'type' => 'string', 'enum' => array( 'default','seamless' ) ), 'label_placement' => array( 'type' => 'string', 'enum' => array( 'top','left' ) ),
            'instruction_placement' => array( 'type' => 'string', 'enum' => array( 'label','field' ) ), 'hide_on_screen' => array( 'type' => 'array', 'items' => $str ),
            'description' => $str, 'show_in_rest' => $bool,
        );
        return self::schema_object( $props, $creating ? array( 'title','location' ) : array() );
    }

    private static function acf_field_tool_schema( bool $creating ): array {
        $str = self::s(); $bool = self::s( 'boolean' );
        $conditional_rule = self::schema_object( array(
            'field' => array( 'type' => 'string', 'pattern' => '^field_' ),
            'operator' => array( 'type' => 'string', 'enum' => array( '==','!=','>','<','>=','<=','==contains','==pattern','==empty','!=empty' ) ),
            'value' => $str,
        ), array( 'field','operator' ) );
        $props = array(
            'key' => array( 'type' => 'string', 'pattern' => '^field_' ), 'parent_key' => $str,
            'label' => $str, 'name' => $str,
            'type' => array( 'type' => 'string', 'enum' => array( 'text','textarea','number','range','email','url','password','image','file','wysiwyg','select','checkbox','radio','button_group','true_false','date_picker','date_time_picker','time_picker','color_picker','message','accordion','tab','group','repeater','post_object','page_link','relationship','taxonomy','user','google_map','oembed','link','gallery','clone' ) ),
            'instructions' => $str, 'required' => $bool,
            'conditional_logic' => array( 'oneOf' => array( array( 'type' => 'integer', 'enum' => array( 0 ) ), array( 'type' => 'array', 'items' => array( 'type' => 'array', 'items' => $conditional_rule ) ) ) ),
            'wrapper' => self::schema_object( array( 'width' => array( 'oneOf' => array( $str, self::s( 'integer' ) ) ), 'class' => $str, 'id' => $str ) ),
            'choices' => array( 'type' => 'object', 'additionalProperties' => $str ), 'sub_fields' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
            'confirm_structure_change' => $bool,
        );
        return self::schema_object( $props, $creating ? array( 'label','name','type' ) : array(), true );
    }

    private static function gutenberg_ops_tool_schema(): array {
        $path=self::s('string'); $operation=array('type'=>'object','required'=>array('type'),'properties'=>array(
            'type'=>array('type'=>'string','enum'=>array('update_attributes','replace_inner_html','replace_block','remove_block','insert_block','insert_blocks','duplicate_block','move_block','replace_document')),
            'path'=>$path,'parent_path'=>$path,'index'=>array('type'=>'integer','minimum'=>0),'attributes'=>self::s('object'),'replace'=>self::s('boolean'),'html'=>self::s('string'),'raw'=>self::s('string')
        ));
        return self::schema_object(array('post_id'=>array('type'=>'integer','minimum'=>1),'operations'=>array('type'=>'array','minItems'=>1,'maxItems'=>50,'items'=>$operation),'note'=>self::s('string')),array('post_id','operations'));
    }
    private static function gutenberg_pattern_tool_schema( bool $create ): array {
        $props=array('action'=>array('type'=>'string','enum'=>array('update','delete','restore')),'title'=>self::s('string'),'slug'=>self::s('string'),'content'=>self::s('string'),'status'=>array('type'=>'string','enum'=>array('publish','draft','private','trash')),'sync_status'=>array('type'=>'string','enum'=>array('synced','unsynced')),'categories'=>array('type'=>'array','items'=>self::s('string')),'force'=>self::s('boolean'),'confirm_permanent'=>self::s('boolean'));
        if($create) unset($props['action'],$props['force'],$props['confirm_permanent']);
        return self::schema_object($props,$create?array('title','content'):array('action'));
    }
    private static function gutenberg_template_tool_schema(): array {
        return self::schema_object(array('action'=>array('type'=>'string','enum'=>array('create','update','delete')),'type'=>array('type'=>'string','enum'=>array('wp_template','wp_template_part')),'id'=>self::s('string'),'theme'=>self::s('string'),'slug'=>self::s('string'),'title'=>self::s('string'),'content'=>self::s('string'),'area'=>self::s('string')),array('action','type'));
    }

    private static function elementor_ops_tool_schema(): array {
        $str = self::s( 'string' );
        $id  = array( 'type' => 'integer', 'minimum' => 1 );
        $operation = self::schema_object( array(
            'type'       => array( 'type' => 'string', 'enum' => array( 'update_settings','set_responsive_setting','set_global_style','update_page_settings','add_element','remove_element','duplicate_element','move_element','replace_element','replace_document' ) ),
            'element_id' => $str,
            'parent_id'  => $str,
            'index'      => array( 'type' => 'integer', 'minimum' => 0 ),
            'settings'   => self::s( 'object' ),
            'replace'    => self::s( 'boolean' ),
            'control'    => $str,
            'device'     => $str,
            'value'      => array(),
            'style_type' => array( 'type' => 'string', 'enum' => array( 'colors','typography' ) ),
            'style_id'   => $str,
            'element'    => self::s( 'object' ),
            'elements'   => array( 'type' => 'array', 'items' => self::s( 'object' ) ),
        ), array( 'type' ), true );
        return self::schema_object( array(
            'post_id'    => $id,
            'operations' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 75, 'items' => $operation ),
            'note'       => $str,
        ), array( 'post_id','operations' ) );
    }

    private static function elementor_template_tool_schema( bool $creating ): array {
        $str = self::s( 'string' );
        $props = array(
            'action'       => array( 'type' => 'string', 'enum' => array( 'update','delete' ) ),
            'title'        => $str,
            'type'         => $str,
            'elements'     => array( 'type' => 'array', 'items' => self::s( 'object' ) ),
            'page_settings'=> self::s( 'object' ),
            'operations'   => self::elementor_ops_tool_schema()['properties']['operations'],
            'status'       => array( 'type' => 'string', 'enum' => array( 'publish','draft','pending','private' ) ),
            'confirm_delete' => self::s( 'boolean' ),
            'note'         => $str,
        );
        if ( $creating ) {
            unset( $props['action'], $props['confirm_delete'] );
            return self::schema_object( $props, array( 'title','type','elements' ) );
        }
        return self::schema_object( $props, array( 'action' ) );
    }

    private static function menu_tool_schema( bool $creating ): array {
        $str = self::s();
        $props = array(
            'name' => $str,
            'slug' => $str,
            'description' => $str,
            'auto_add' => self::s( 'boolean' ),
            'locations' => array( 'type' => 'array', 'items' => $str, 'uniqueItems' => true ),
        );
        return self::schema_object( $props, $creating ? array( 'name' ) : array() );
    }

    private static function menu_item_tool_schema( bool $creating ): array {
        $str = self::s(); $id = array( 'type' => 'integer', 'minimum' => 1 );
        $props = array(
            'title' => $str,
            'type' => array( 'type' => 'string', 'enum' => array( 'custom','post_type','taxonomy','post_type_archive' ) ),
            'object' => $str,
            'object_id' => array( 'type' => 'integer', 'minimum' => 0 ),
            'url' => $str,
            'parent_id' => array( 'type' => 'integer', 'minimum' => 0 ),
            'position' => $id,
            'description' => $str,
            'attr_title' => $str,
            'target' => array( 'type' => 'string', 'enum' => array( '', '_blank' ) ),
            'status' => array( 'type' => 'string', 'enum' => array( 'publish','draft' ) ),
            'classes' => array( 'oneOf' => array( array( 'type' => 'array', 'items' => $str ), $str ) ),
            'xfn' => $str,
        );
        return self::schema_object( $props, $creating ? array( 'type' ) : array() );
    }

    private static function menu_reorder_tool_schema(): array {
        $id = array( 'type' => 'integer', 'minimum' => 1 );
        return self::schema_object( array(
            'menu_id' => $id,
            'complete' => self::s( 'boolean' ),
            'items' => array(
                'type' => 'array', 'minItems' => 1,
                'items' => self::schema_object( array(
                    'item_id' => $id,
                    'position' => $id,
                    'parent_id' => array( 'type' => 'integer', 'minimum' => 0 ),
                ), array( 'item_id' ) ),
            ),
        ), array( 'menu_id','items' ) );
    }

    private static function woocommerce_attribute_tool_schema( bool $creating ): array {
        $props = array(
            'name' => self::s(), 'slug' => self::s(), 'type' => self::s(),
            'order_by' => array( 'type' => 'string', 'enum' => array( 'menu_order','name','name_num','id' ) ),
            'has_archives' => self::s( 'boolean' ),
        );
        return self::schema_object( $props, $creating ? array( 'name' ) : array() );
    }

    private static function woocommerce_term_tool_schema( bool $creating ): array {
        return self::schema_object(
            array( 'name' => self::s(), 'slug' => self::s(), 'description' => self::s() ),
            $creating ? array( 'name' ) : array()
        );
    }

    private static function woocommerce_variation_tool_schema( bool $creating ): array {
        $num_or_str = array( 'oneOf' => array( array( 'type' => 'number' ), array( 'type' => 'string' ) ) );
        $download = self::schema_object( array( 'id' => self::s(), 'name' => self::s(), 'file' => self::s() ), array( 'name','file' ) );
        $props = array(
            'description' => self::s(), 'sku' => self::s(), 'regular_price' => $num_or_str, 'sale_price' => $num_or_str,
            'date_on_sale_from' => self::s(), 'date_on_sale_to' => self::s(), 'status' => self::s(),
            'attributes' => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'string' ) ),
            'manage_stock' => self::s('boolean'), 'stock_quantity' => array( 'oneOf' => array( self::s('number'), array( 'type'=>'null' ) ) ),
            'stock_status' => array( 'type'=>'string', 'enum'=>array('instock','outofstock','onbackorder') ), 'backorders' => array( 'type'=>'string','enum'=>array('no','notify','yes') ),
            'virtual' => self::s('boolean'), 'downloadable' => self::s('boolean'), 'tax_status' => array( 'type'=>'string','enum'=>array('taxable','shipping','none') ), 'tax_class' => self::s(),
            'weight' => $num_or_str, 'length' => $num_or_str, 'width' => $num_or_str, 'height' => $num_or_str,
            'shipping_class_id' => array( 'type'=>'integer','minimum'=>0 ), 'image_id' => array( 'type'=>'integer','minimum'=>0 ), 'menu_order' => self::s('integer'),
            'download_limit' => self::s('integer'), 'download_expiry' => self::s('integer'), 'downloads' => array( 'type'=>'array','items'=>$download,'maxItems'=>50 ),
        );
        return self::schema_object( $props, $creating ? array( 'attributes' ) : array() );
    }

    private static function woocommerce_product_tool_schema( bool $creating ): array {
        $num_or_str = array( 'oneOf' => array( array( 'type' => 'number' ), array( 'type' => 'string' ) ) );
        $download = self::schema_object( array( 'id'=>self::s(), 'name'=>self::s(), 'file'=>self::s() ), array( 'name','file' ) );
        $attribute = self::schema_object( array(
            'id'=>array('type'=>'integer','minimum'=>0), 'name'=>self::s(), 'options'=>array('type'=>'array','items'=>array('oneOf'=>array(self::s('integer'),self::s()))),
            'position'=>self::s('integer'), 'visible'=>self::s('boolean'), 'variation'=>self::s('boolean'),
        ), array( 'options' ) );
        $props = array(
            'type'=>array('type'=>'string','enum'=>array('simple','variable')), 'name'=>self::s(), 'slug'=>self::s(), 'status'=>array('type'=>'string','enum'=>array('draft','pending','private','publish')),
            'description'=>self::s(), 'short_description'=>self::s(), 'purchase_note'=>self::s(), 'sku'=>self::s(), 'regular_price'=>$num_or_str, 'sale_price'=>$num_or_str,
            'date_on_sale_from'=>self::s(), 'date_on_sale_to'=>self::s(), 'manage_stock'=>self::s('boolean'), 'stock_quantity'=>array('oneOf'=>array(self::s('number'),array('type'=>'null'))),
            'low_stock_amount'=>array('oneOf'=>array(self::s('number'),array('type'=>'null'))), 'stock_status'=>array('type'=>'string','enum'=>array('instock','outofstock','onbackorder')), 'backorders'=>array('type'=>'string','enum'=>array('no','notify','yes')),
            'featured'=>self::s('boolean'), 'catalog_visibility'=>array('type'=>'string','enum'=>array('visible','catalog','search','hidden')), 'virtual'=>self::s('boolean'), 'downloadable'=>self::s('boolean'),
            'sold_individually'=>self::s('boolean'), 'reviews_allowed'=>self::s('boolean'), 'tax_status'=>array('type'=>'string','enum'=>array('taxable','shipping','none')), 'tax_class'=>self::s(),
            'weight'=>$num_or_str, 'length'=>$num_or_str, 'width'=>$num_or_str, 'height'=>$num_or_str, 'shipping_class_id'=>array('type'=>'integer','minimum'=>0),
            'category_ids'=>array('type'=>'array','items'=>self::s('integer')), 'tag_ids'=>array('type'=>'array','items'=>self::s('integer')), 'image_id'=>array('type'=>'integer','minimum'=>0),
            'gallery_image_ids'=>array('type'=>'array','items'=>self::s('integer')), 'upsell_ids'=>array('type'=>'array','items'=>self::s('integer')), 'cross_sell_ids'=>array('type'=>'array','items'=>self::s('integer')),
            'menu_order'=>self::s('integer'), 'download_limit'=>self::s('integer'), 'download_expiry'=>self::s('integer'), 'downloads'=>array('type'=>'array','maxItems'=>50,'items'=>$download),
            'attributes'=>array('type'=>'array','maxItems'=>50,'items'=>$attribute), 'default_attributes'=>array('type'=>'object','additionalProperties'=>array('type'=>'string')),
            'variations'=>array('type'=>'array','maxItems'=>100,'items'=>self::woocommerce_variation_tool_schema(true)),
        );
        return self::schema_object( $props, $creating ? array( 'name' ) : array() );
    }

    private static function woocommerce_order_address_tool_schema(): array {
        $str = self::s();
        return self::schema_object( array(
            'first_name'=>$str,'last_name'=>$str,'company'=>$str,'address_1'=>$str,'address_2'=>$str,
            'city'=>$str,'state'=>$str,'postcode'=>$str,'country'=>$str,'email'=>$str,'phone'=>$str,
        ) );
    }

    private static function woocommerce_order_line_tool_schema( bool $creating ): array {
        $money = array( 'oneOf'=>array( self::s('number'), self::s() ) );
        return self::schema_object( array(
            'product_id'=>array('type'=>'integer','minimum'=>1),
            'variation_id'=>array('type'=>'integer','minimum'=>0),
            'quantity'=>array('type'=>'number','exclusiveMinimum'=>0),
            'subtotal'=>$money,'total'=>$money,
            'confirm_financial_totals'=>self::s('boolean'),
        ), $creating ? array('product_id','confirm_financial_totals') : array('confirm_financial_totals') );
    }

    private static function woocommerce_order_tool_schema( bool $creating ): array {
        $props = array(
            'status'=>self::s(), 'customer_id'=>array('type'=>'integer','minimum'=>0), 'customer_note'=>self::s(),
            'billing'=>self::woocommerce_order_address_tool_schema(), 'shipping'=>self::woocommerce_order_address_tool_schema(),
            'confirm_status_side_effects'=>self::s('boolean'),
        );
        if ( $creating ) $props['line_items'] = array( 'type'=>'array','maxItems'=>100,'items'=>self::woocommerce_order_line_tool_schema(true) );
        return self::schema_object( $props );
    }

    private static function tool( string $name, string $title, string $description, array $input, bool $read_only, bool $destructive = false ): array {
        return array(
            'name'        => $name,
            'title'       => $title,
            'description' => $description,
            'inputSchema' => $input,
            'annotations' => array(
                'readOnlyHint'    => $read_only,
                'destructiveHint' => $destructive,
                'openWorldHint'   => false,
                'idempotentHint'  => $read_only,
            ),
        );
    }

    public static function tools(): array {
        if ( self::$tool_cache ) return self::$tool_cache;

        $str = self::s();
        $id  = self::s( 'integer' );
        $obj = array( 'type' => 'object', 'additionalProperties' => true );
        $arr_obj = array( 'type' => 'array', 'items' => $obj );
        $content_fields = array(
            'type' => array( 'type' => 'string', 'default' => 'page', 'description' => 'page, post, or an allowed public custom post type key.' ),
            'title' => $str, 'content' => $str, 'excerpt' => $str, 'status' => $str, 'slug' => $str,
            'date' => array( 'type' => 'string', 'format' => 'date-time', 'description' => 'Site-local RFC3339 publication date. Use status=future for scheduling.' ),
            'date_gmt' => array( 'type' => 'string', 'format' => 'date-time', 'description' => 'UTC RFC3339 publication date.' ),
            'author' => $id, 'template' => $str,
            'featured_media' => $id, 'parent' => $id, 'menu_order' => $id,
            'terms' => $obj,
        );

        $tools = array();
        $add = static function ( array $tool ) use ( &$tools ): void { $tools[ $tool['name'] ] = $tool; };

        $add( self::tool( 'get_site_info', 'Get WordPress site info', 'Inspect the connected WordPress site and active integrations before planning changes.', self::schema_object(), true ) );
        $add( self::tool( 'get_capabilities', 'Get connector capabilities', 'Check enabled permissions and integrations before using write tools.', self::schema_object(), true ) );
        $source_target = array( 'type' => 'string', 'enum' => array( 'theme', 'parent_theme', 'plugin' ), 'default' => 'theme' );
        $add( self::tool( 'get_active_theme_source_info', 'Get active theme source info', 'Inspect the active/parent theme identities available to the safe read-only source-code sandbox.', self::schema_object(), true ) );
        $add( self::tool( 'list_active_plugins', 'List active plugins for source inspection', 'List active directory-based plugins that can be safely inspected. Inactive plugins and single-file shared-root plugins are not exposed.', self::schema_object(), true ) );
        $add( self::tool( 'list_source_files', 'List source files', 'List safe text/source files under the active theme, parent theme, or one active plugin. Dependency/cache/secret/binary paths are excluded.', self::schema_object( array( 'target'=>$source_target, 'plugin'=>$str, 'path'=>$str ) ), true ) );
        $add( self::tool( 'get_source_file', 'Read source file', 'Read one allowlisted theme/plugin source file with path sandboxing, size limits and secret-file blocking.', self::schema_object( array( 'target'=>$source_target, 'plugin'=>$str, 'path'=>$str ), array( 'path' ) ), true ) );
        $add( self::tool( 'search_source_code', 'Search source code', 'Literal case-insensitive search across allowlisted active theme/plugin source files with bounded scan and result limits.', self::schema_object( array( 'target'=>$source_target, 'plugin'=>$str, 'query'=>$str, 'path'=>$str, 'max_matches'=>array( 'type'=>'integer','minimum'=>1,'maximum'=>100 ) ), array( 'query' ) ), true ) );
        $add( self::tool( 'inspect_frontend_page', 'Inspect rendered frontend page', 'Fetch only this WordPress site\'s own frontend path and return status, safe response headers, document summary, discovered assets, and optionally bounded HTML.', self::schema_object( array( 'path'=>array( 'type'=>'string','default'=>'/' ), 'include_html'=>self::s( 'boolean' ) ) ), true ) );
        $add( self::tool( 'preview_source_file_update', 'Preview source file update', 'Prepare an approval-gated edit to one existing allowlisted active-theme, parent-theme, or active-plugin source file. Read the file first and pass expected_sha256 for stale-write protection. PHP is syntax-validated before apply.', self::schema_object( array( 'target'=>$source_target, 'plugin'=>$str, 'path'=>$str, 'content'=>$str, 'expected_sha256'=>$str ), array( 'path','content' ) ), false ) );
        $add( self::tool( 'list_content', 'List WordPress content', 'List pages, posts, or an allowed custom post type, including future or trashed items when requested.', self::schema_object( array( 'type' => $content_fields['type'], 'search' => $str, 'status' => $str, 'author' => $id, 'per_page' => $id ) ), true ) );
        $add( self::tool( 'get_content', 'Get WordPress content', 'Read one page, post, or custom post type item by ID, including author, dates, template, featured image and terms.', self::schema_object( array( 'type' => $content_fields['type'], 'id' => $id ), array( 'id' ) ), true ) );
        $add( self::tool( 'create_content', 'Create WordPress content', 'Create a page, post, or custom post type item. Supports author, theme template and scheduled publication dates. Prefer draft unless the user explicitly requests publishing.', self::schema_object( $content_fields, array( 'title' ) ), false ) );
        $add( self::tool( 'update_content', 'Update WordPress content', 'Update an existing page, post, or custom post type item, including author, dates and theme template.', self::schema_object( array_merge( $content_fields, array( 'id' => $id ) ), array( 'id' ) ), false ) );
        $add( self::tool( 'list_content_authors', 'List content authors', 'List users eligible to author the selected page/post type before assigning an author.', self::schema_object( array( 'type' => $content_fields['type'] ) ), true ) );
        $add( self::tool( 'list_content_templates', 'List page/post templates', 'List active-theme templates available for a page or post.', self::schema_object( array( 'type' => array( 'type' => 'string', 'enum' => array( 'page', 'post' ), 'default' => 'page' ), 'post_id' => $id ) ), true ) );
        $add( self::tool( 'delete_content', 'Trash or permanently delete page/post', 'By default moves a page/post to Trash. Permanent deletion is irreversible and requires force=true plus confirm_permanent=true.', self::schema_object( array( 'type' => array( 'type' => 'string', 'enum' => array( 'page', 'post' ), 'default' => 'page' ), 'id' => $id, 'force' => self::s( 'boolean' ), 'confirm_permanent' => self::s( 'boolean' ) ), array( 'id' ) ), false, true ) );
        $add( self::tool( 'restore_content', 'Restore page/post from Trash', 'Restore a trashed page or post using WordPress trash metadata.', self::schema_object( array( 'type' => array( 'type' => 'string', 'enum' => array( 'page', 'post' ), 'default' => 'page' ), 'id' => $id ), array( 'id' ) ), false ) );
        $add( self::tool( 'list_content_revisions', 'List page/post revisions', 'List stored WordPress revisions for a page or post.', self::schema_object( array( 'type' => array( 'type' => 'string', 'enum' => array( 'page', 'post' ), 'default' => 'page' ), 'id' => $id, 'per_page' => $id ), array( 'id' ) ), true ) );
        $add( self::tool( 'get_content_revision', 'Get page/post revision', 'Read the raw title, content and excerpt from one WordPress revision.', self::schema_object( array( 'type' => array( 'type' => 'string', 'enum' => array( 'page', 'post' ), 'default' => 'page' ), 'id' => $id, 'revision_id' => $id ), array( 'id', 'revision_id' ) ), true ) );
        $add( self::tool( 'restore_content_revision', 'Restore page/post revision', 'Overwrite the current page/post body fields from a selected WordPress revision. The previous state is audit-logged and rollback-capable.', self::schema_object( array( 'type' => array( 'type' => 'string', 'enum' => array( 'page', 'post' ), 'default' => 'page' ), 'id' => $id, 'revision_id' => $id ), array( 'id', 'revision_id' ) ), false, true ) );

        $add( self::tool( 'list_post_types', 'List post types', 'List WordPress post types with visibility, REST, menu, capability, supports, taxonomy and ALIFY-managed definition metadata.', self::schema_object(), true ) );
        $add( self::tool( 'get_post_type', 'Get post type', 'Read one registered post type and its complete ALIFY-managed definition when applicable.', self::schema_object( array( 'key' => $str ), array( 'key' ) ), true ) );
        $add( self::tool( 'create_post_type', 'Create custom post type', 'Create an ALIFY-managed custom post type with labels, capabilities, menu settings, REST settings, supports, taxonomies, rewrites and block template settings. Requires Structure permission.', self::post_type_tool_schema( true ), false ) );
        $update_cpt_schema = self::post_type_tool_schema( false );
        $update_cpt_schema['properties']['key'] = $str;
        $update_cpt_schema['required'] = array( 'key' );
        $add( self::tool( 'update_post_type', 'Update custom post type', 'Update any supported registration setting for an ALIFY-managed custom post type.', $update_cpt_schema, false ) );
        $add( self::tool( 'delete_post_type', 'Delete custom post type registration', 'Delete an ALIFY-managed CPT registration. If content exists, force=true and confirm_orphan_content=true are both required; existing database rows are never silently deleted.', self::schema_object( array( 'key' => $str, 'force' => self::s( 'boolean' ), 'confirm_orphan_content' => self::s( 'boolean' ) ), array( 'key' ) ), false, true ) );
        $add( self::tool( 'list_taxonomies', 'List taxonomies', 'List WordPress taxonomies with visibility, REST, capabilities, post-type associations, term counts and managed definitions.', self::schema_object(), true ) );
        $add( self::tool( 'get_taxonomy', 'Get taxonomy', 'Read one registered taxonomy and its complete ALIFY-managed definition when applicable.', self::schema_object( array( 'key' => $str ), array( 'key' ) ), true ) );
        $add( self::tool( 'create_taxonomy', 'Create taxonomy', 'Create an ALIFY-managed taxonomy with rich labels, capabilities, REST/rewrite settings, default term and post-type associations.', self::taxonomy_tool_schema( true ), false ) );
        $update_tax_schema = self::taxonomy_tool_schema( false ); $update_tax_schema['properties']['key'] = $str; $update_tax_schema['required'] = array( 'key' );
        $add( self::tool( 'update_taxonomy', 'Update taxonomy', 'Update any supported registration setting for an ALIFY-managed taxonomy.', $update_tax_schema, false ) );
        $add( self::tool( 'delete_taxonomy', 'Delete taxonomy registration', 'Delete an ALIFY-managed taxonomy registration. If terms exist, force=true and confirm_term_deletion=true are both required; term rows are never silently deleted.', self::schema_object( array( 'key' => $str, 'force' => self::s( 'boolean' ), 'confirm_term_deletion' => self::s( 'boolean' ) ), array( 'key' ) ), false, true ) );
        $add( self::tool( 'attach_taxonomy_object_type', 'Attach taxonomy to post type', 'Persistently attach an ALIFY-managed taxonomy to a registered post type.', self::schema_object( array( 'key' => $str, 'object_type' => $str ), array( 'key', 'object_type' ) ), false ) );
        $add( self::tool( 'detach_taxonomy_object_type', 'Detach taxonomy from post type', 'Persistently detach an ALIFY-managed taxonomy from a post type without deleting terms or relationships.', self::schema_object( array( 'key' => $str, 'object_type' => $str ), array( 'key', 'object_type' ) ), false, true ) );
        $add( self::tool( 'list_terms', 'List taxonomy terms', 'List terms in a taxonomy with pagination and optional parent filtering.', self::schema_object( array( 'taxonomy' => $str, 'search' => $str, 'per_page' => $id, 'page' => $id, 'parent' => $id ), array( 'taxonomy' ) ), true ) );
        $add( self::tool( 'get_term', 'Get taxonomy term', 'Read one taxonomy term by ID.', self::schema_object( array( 'taxonomy' => $str, 'id' => $id ), array( 'taxonomy', 'id' ) ), true ) );
        $add( self::tool( 'create_term', 'Create taxonomy term', 'Create a taxonomy term. Parent is supported only for hierarchical taxonomies.', self::schema_object( array( 'taxonomy' => $str, 'name' => $str, 'slug' => $str, 'description' => $str, 'parent' => $id ), array( 'taxonomy', 'name' ) ), false ) );
        $add( self::tool( 'update_term', 'Update taxonomy term', 'Update name, slug, description or parent for a taxonomy term.', self::schema_object( array( 'taxonomy' => $str, 'id' => $id, 'name' => $str, 'slug' => $str, 'description' => $str, 'parent' => $id ), array( 'taxonomy', 'id' ) ), false ) );
        $add( self::tool( 'delete_term', 'Delete taxonomy term', 'Delete a term using WordPress term-deletion semantics. Requires confirm_delete=true; optionally supply default_term_id and force_default to preserve assignments.', self::schema_object( array( 'taxonomy' => $str, 'id' => $id, 'confirm_delete' => self::s( 'boolean' ), 'default_term_id' => $id, 'force_default' => self::s( 'boolean' ) ), array( 'taxonomy', 'id', 'confirm_delete' ) ), false, true ) );

        $add( self::tool( 'get_acf_status', 'Get ACF status', 'Check ACF availability, version, PRO/repeater support and supported field types.', self::schema_object(), true ) );
        $add( self::tool( 'list_acf_groups', 'List ACF field groups', 'List ACF field groups with location and display settings.', self::schema_object(), true ) );
        $add( self::tool( 'get_acf_group', 'Get ACF field group', 'Read one ACF field group including its nested field tree.', self::schema_object( array( 'group_key' => $str ), array( 'group_key' ) ), true ) );
        $add( self::tool( 'create_acf_group', 'Create ACF field group', 'Create an ACF field group with location and presentation settings.', self::acf_group_tool_schema( true ), false ) );
        $update_group_schema = self::acf_group_tool_schema( false ); $update_group_schema['properties']['group_key'] = $str; $update_group_schema['required'] = array( 'group_key' );
        $add( self::tool( 'update_acf_group', 'Update ACF field group', 'Update an ACF field group, location rules or display settings.', $update_group_schema, false ) );
        $add( self::tool( 'delete_acf_group', 'Delete ACF field group', 'Delete an ACF field group and its field definitions after explicit confirmation. Stored content values are not silently purged.', self::schema_object( array( 'group_key' => $str, 'confirm_delete' => self::s( 'boolean' ) ), array( 'group_key','confirm_delete' ) ), false, true ) );
        $add( self::tool( 'list_acf_fields', 'List ACF fields', 'List top-level and nested fields for an ACF field group.', self::schema_object( array( 'group_key' => $str ), array( 'group_key' ) ), true ) );
        $add( self::tool( 'get_acf_field', 'Get ACF field', 'Read one ACF field including nested group/repeater subfields and conditional logic.', self::schema_object( array( 'field_key' => $str ), array( 'field_key' ) ), true ) );
        $create_field_schema = self::acf_field_tool_schema( true ); $create_field_schema['properties']['group_key'] = $str; $create_field_schema['required'][] = 'group_key';
        $add( self::tool( 'create_acf_field', 'Create ACF field', 'Create a top-level or nested ACF field. Use parent_key to add a subfield to a group/repeater field, or sub_fields to create a nested tree in one call.', $create_field_schema, false ) );
        $update_field_schema = self::acf_field_tool_schema( false ); $update_field_schema['properties']['field_key'] = $str; $update_field_schema['required'] = array( 'field_key' );
        $add( self::tool( 'update_acf_field', 'Update ACF field', 'Update an ACF field definition, nested parent, conditional logic, wrapper and supported type settings.', $update_field_schema, false ) );
        $add( self::tool( 'delete_acf_field', 'Delete ACF field', 'Delete an ACF field and nested subfield tree after explicit confirmation.', self::schema_object( array( 'field_key' => $str, 'confirm_delete' => self::s( 'boolean' ) ), array( 'field_key','confirm_delete' ) ), false, true ) );
        $add( self::tool( 'get_acf_values', 'Get ACF values', 'Read raw ACF values for a WordPress post ID, including nested group/repeater arrays.', self::schema_object( array( 'post_id' => $id ), array( 'post_id' ) ), true ) );
        $add( self::tool( 'update_acf_values', 'Update ACF values', 'Update ACF values for a WordPress post ID. Nested arrays are supported for group/repeater values.', self::schema_object( array( 'post_id' => $id, 'values' => $obj ), array( 'post_id', 'values' ) ), false ) );
        $add( self::tool( 'list_acf_option_pages', 'List ACF options pages', 'List registered/inferred ACF Options Pages, their storage post_id, matching field groups and field counts.', self::schema_object(), true ) );
        $add( self::tool( 'get_acf_option_values', 'Get ACF options-page values', 'Read raw ACF values assigned to one options page, including nested group/repeater values and field metadata.', self::schema_object( array( 'page'=>$str ), array( 'page' ) ), true ) );
        $add( self::tool( 'update_acf_option_values', 'Update ACF options-page values', 'Update allowlisted fields assigned to one ACF Options Page. Supports nested arrays and creates a rollback-capable activity.', self::schema_object( array( 'page'=>$str, 'values'=>$obj ), array( 'page','values' ) ), false ) );

        $add( self::tool( 'list_media', 'List WordPress media', 'Search or list Media Library attachments with pagination, MIME and parent filters.', self::schema_object( array( 'search' => $str, 'mime_type' => $str, 'per_page' => $id, 'page' => $id, 'parent' => array( 'type' => 'integer', 'minimum' => 0 ) ) ), true ) );
        $add( self::tool( 'get_media_limits', 'Get media upload limits', 'Read WordPress and MCP upload limits before sending file content.', self::schema_object(), true ) );
        $add( self::tool( 'upload_media_base64', 'Upload media file', 'Upload a base64-encoded local file to the WordPress Media Library. Raw file size is bounded by the reported MCP limit.', self::schema_object( array( 'filename' => $str, 'data_base64' => $str, 'mime_type' => $str, 'title' => $str, 'caption' => $str, 'description' => $str, 'alt_text' => $str, 'parent' => $id, 'allow_duplicate' => self::s( 'boolean' ) ), array( 'filename','data_base64' ) ), false ) );
        $add( self::tool( 'get_media', 'Get media item', 'Read one Media Library attachment by ID, including metadata, sizes and usage information.', self::schema_object( array( 'id' => $id ), array( 'id' ) ), true ) );
        $add( self::tool( 'get_media_usages', 'Get media usages', 'Find posts that use an attachment as featured media before deleting it.', self::schema_object( array( 'id' => $id ), array( 'id' ) ), true ) );
        $add( self::tool( 'update_media', 'Update media metadata', 'Update title, caption, description, alt text, or parent for a Media Library item.', self::schema_object( array( 'id' => $id, 'title' => $str, 'caption' => $str, 'description' => $str, 'alt_text' => $str, 'parent' => array( 'type' => 'integer', 'minimum' => 0 ) ), array( 'id' ) ), false ) );
        $add( self::tool( 'delete_media', 'Delete or trash media', 'Trash media when Media Trash is enabled, or permanently delete with a protected file backup. In-use featured media needs explicit confirmation.', self::schema_object( array( 'id' => $id, 'force' => self::s( 'boolean' ), 'confirm_permanent' => self::s( 'boolean' ), 'confirm_in_use' => self::s( 'boolean' ) ), array( 'id' ) ), false, true ) );
        $add( self::tool( 'restore_media', 'Restore trashed media', 'Restore a media attachment from WordPress Media Trash.', self::schema_object( array( 'id' => $id ), array( 'id' ) ), false ) );
        $add( self::tool( 'replace_media_file_base64', 'Replace media file', 'Replace the underlying attachment file while preserving its WordPress attachment ID and URL. Same extension is required.', self::schema_object( array( 'id' => $id, 'filename' => $str, 'data_base64' => $str, 'mime_type' => $str, 'preserve_metadata' => self::s( 'boolean' ) ), array( 'id','filename','data_base64' ) ), false, true ) );
        $add( self::tool( 'edit_media_image', 'Edit media image', 'Resize, crop, rotate, or flip an image attachment with rollback backup.', self::schema_object( array( 'id' => $id, 'operation' => array( 'type' => 'string', 'enum' => array( 'resize','crop','rotate','flip' ) ), 'width' => $id, 'height' => $id, 'x' => array( 'type' => 'integer', 'minimum' => 0 ), 'y' => array( 'type' => 'integer', 'minimum' => 0 ), 'target_width' => $id, 'target_height' => $id, 'angle' => array( 'type' => 'number' ), 'horizontal' => self::s( 'boolean' ), 'vertical' => self::s( 'boolean' ), 'crop' => self::s( 'boolean' ) ), array( 'id','operation' ) ), false, true ) );
        $add( self::tool( 'regenerate_media_metadata', 'Regenerate media metadata', 'Regenerate WordPress attachment metadata and image sub-sizes with rollback backup.', self::schema_object( array( 'id' => $id ), array( 'id' ) ), false, true ) );

        $add( self::tool( 'list_menus', 'List WordPress menus', 'List navigation menus with descriptions, item counts and assigned theme locations.', self::schema_object(), true ) );
        $add( self::tool( 'list_menu_locations', 'List menu locations', 'List theme menu locations and their currently assigned menu IDs.', self::schema_object(), true ) );
        $add( self::tool( 'assign_menu_location', 'Assign menu location', 'Assign an existing menu to one registered theme menu location.', self::schema_object( array( 'location' => $str, 'menu_id' => $id ), array( 'location','menu_id' ) ), false ) );
        $add( self::tool( 'unassign_menu_location', 'Unassign menu location', 'Remove the current menu assignment from one registered theme location.', self::schema_object( array( 'location' => $str ), array( 'location' ) ), false ) );
        $add( self::tool( 'get_menu', 'Get WordPress menu', 'Read a menu with metadata, locations and ordered item snapshots.', self::schema_object( array( 'id' => $id ), array( 'id' ) ), true ) );
        $create_menu_schema = self::menu_tool_schema( true );
        $add( self::tool( 'create_menu', 'Create WordPress menu', 'Create a navigation menu, optional description, and optional theme-location assignments.', $create_menu_schema, false ) );
        $update_menu_schema = self::menu_tool_schema( false ); $update_menu_schema['properties']['id'] = $id; $update_menu_schema['required'] = array( 'id' );
        $add( self::tool( 'update_menu', 'Update WordPress menu', 'Rename, describe, or replace the theme-location assignments for a menu.', $update_menu_schema, false ) );
        $add( self::tool( 'delete_menu', 'Delete WordPress menu', 'Permanently delete a menu and all of its menu-item posts after explicit confirmation. Rollback recreates the structure with new WordPress IDs.', self::schema_object( array( 'id' => $id, 'confirm_delete' => self::s( 'boolean' ) ), array( 'id','confirm_delete' ) ), false, true ) );
        $add( self::tool( 'list_menu_items', 'List menu items', 'List ordered items in a WordPress navigation menu, including hierarchy and link metadata.', self::schema_object( array( 'menu_id' => $id ), array( 'menu_id' ) ), true ) );
        $create_item_schema = self::menu_item_tool_schema( true ); $create_item_schema['properties']['menu_id'] = $id; array_unshift( $create_item_schema['required'], 'menu_id' );
        $add( self::tool( 'create_menu_item', 'Create menu item', 'Add a custom URL, post/page/CPT, taxonomy term, or post-type archive item to a menu.', $create_item_schema, false ) );
        $update_item_schema = self::menu_item_tool_schema( false ); $update_item_schema['properties']['menu_id'] = $id; $update_item_schema['properties']['item_id'] = $id; $update_item_schema['required'] = array( 'menu_id','item_id' );
        $add( self::tool( 'update_menu_item', 'Update menu item', 'Update a menu item target, parent relation, order, label, URL, target, classes, XFN or description.', $update_item_schema, false ) );
        $add( self::tool( 'delete_menu_item', 'Delete menu item', 'Permanently delete a menu item after explicit confirmation. Child items must be explicitly reparented before deletion.', self::schema_object( array( 'menu_id' => $id, 'item_id' => $id, 'confirm_delete' => self::s( 'boolean' ), 'reparent_children_to' => array( 'type' => 'integer', 'minimum' => 0 ) ), array( 'menu_id','item_id','confirm_delete' ) ), false, true ) );
        $add( self::tool( 'reorder_menu_items', 'Reorder menu items', 'Bulk reorder and reparent menu items with same-menu parent validation, unique final positions and cycle protection.', self::menu_reorder_tool_schema(), false ) );

        $add( self::tool( 'get_gutenberg_document', 'Inspect Gutenberg document', 'Read a Gutenberg document as structured nested blocks before editing.', self::schema_object( array( 'post_id' => $id ), array( 'post_id' ) ), true ) );
        $add( self::tool( 'preview_gutenberg_changes', 'Preview Gutenberg changes', 'Create a pending validated Gutenberg block-change proposal. Supports attribute/HTML edits, insert/remove/replace, multi-block insertion, duplicate, move and whole-document replacement.', self::gutenberg_ops_tool_schema(), false ) );
        $add( self::tool( 'get_gutenberg_status', 'Get Gutenberg status', 'Inspect block-theme, registry, user-pattern and block-template capabilities.', self::schema_object(), true ) );
        $add( self::tool( 'list_gutenberg_block_types', 'List Gutenberg block types', 'List server-registered blocks with parent, ancestor, allowed-child and supports constraints.', self::schema_object( array( 'search'=>$str, 'category'=>$str ) ), true ) );
        $add( self::tool( 'get_gutenberg_block_type', 'Get Gutenberg block type', 'Get the server-side attribute schema and nesting constraints for one registered block type.', self::schema_object( array( 'name'=>$str ), array( 'name' ) ), true ) );
        $add( self::tool( 'validate_gutenberg_content', 'Validate Gutenberg content', 'Validate either an existing post_id or raw serialized Gutenberg content against registered block/nesting/attribute constraints.', self::schema_object( array( 'post_id'=>$id, 'content'=>$str ) ), true ) );
        $add( self::tool( 'list_gutenberg_patterns', 'List Gutenberg patterns', 'List registered theme/core patterns and persistent user patterns. source can be all, registered, or user.', self::schema_object( array( 'source'=>array('type'=>'string','enum'=>array('all','registered','user')), 'search'=>$str ) ), true ) );
        $add( self::tool( 'get_registered_gutenberg_pattern', 'Get registered Gutenberg pattern', 'Read a registered theme/core pattern by namespaced pattern name.', self::schema_object( array( 'name'=>$str ), array( 'name' ) ), true ) );
        $add( self::tool( 'get_user_gutenberg_pattern', 'Get user Gutenberg pattern', 'Read one persistent wp_block user pattern, including sync mode and validation.', self::schema_object( array( 'id'=>$id ), array( 'id' ) ), true ) );
        $pattern_create=self::gutenberg_pattern_tool_schema(true);
        $add( self::tool( 'preview_gutenberg_pattern_create', 'Preview Gutenberg pattern creation', 'Create an approval proposal for a synced or unsynced user pattern. No pattern is written until apply_approval.', $pattern_create, false ) );
        $pattern_change=self::gutenberg_pattern_tool_schema(false); $pattern_change['properties']['id']=$id; array_unshift($pattern_change['required'],'id');
        $add( self::tool( 'preview_gutenberg_pattern_change', 'Preview Gutenberg pattern change', 'Preview update, Trash/permanent delete, or Trash restore for a user pattern.', $pattern_change, false, true ) );
        $add( self::tool( 'list_gutenberg_templates', 'List Gutenberg templates', 'List merged block templates or template parts from the theme and database overrides.', self::schema_object( array( 'type'=>array('type'=>'string','enum'=>array('wp_template','wp_template_part')), 'post_type'=>$str, 'area'=>$str ) ), true ) );
        $add( self::tool( 'get_gutenberg_template', 'Get Gutenberg template', 'Read a block template or template part by theme//slug identifier.', self::schema_object( array( 'id'=>$str, 'type'=>array('type'=>'string','enum'=>array('wp_template','wp_template_part')) ), array( 'id' ) ), true ) );
        $add( self::tool( 'preview_gutenberg_template_change', 'Preview Gutenberg template change', 'Create an approval proposal to create/update/delete a customized wp_template or wp_template_part.', self::gutenberg_template_tool_schema(), false, true ) );
        $add( self::tool( 'get_elementor_status', 'Get Elementor status', 'Inspect Elementor Core/Pro availability, document API, widget registry, Kits manager and responsive-breakpoint support.', self::schema_object(), true ) );
        $add( self::tool( 'list_elementor_breakpoints', 'List Elementor breakpoints', 'List active responsive devices/breakpoints used by Elementor responsive controls.', self::schema_object(), true ) );
        $add( self::tool( 'list_elementor_widgets', 'List Elementor widgets', 'List registered Elementor widgets and basic metadata. Optionally search by name/title.', self::schema_object( array( 'search' => $str ), array() ), true ) );
        $add( self::tool( 'get_elementor_widget', 'Get Elementor widget schema', 'Inspect one registered Elementor widget and its editable controls before constructing changes.', self::schema_object( array( 'name' => $str ), array( 'name' ) ), true ) );
        $add( self::tool( 'get_elementor_document', 'Inspect Elementor document', 'Read Elementor element tree, page settings, document summary and validation before editing.', self::schema_object( array( 'post_id' => $id ), array( 'post_id' ) ), true ) );
        $add( self::tool( 'validate_elementor_document', 'Validate Elementor document', 'Validate container/widget hierarchy, widget registration, IDs and known control values without modifying the document.', self::schema_object( array( 'post_id' => $id ), array( 'post_id' ) ), true ) );
        $add( self::tool( 'preview_elementor_changes', 'Preview Elementor changes', 'Create a validated pending Elementor proposal for widget/container settings, responsive settings, global references, page settings, move/duplicate/add/remove/replace operations or whole-document replacement. No changes apply until approval.', self::elementor_ops_tool_schema(), false ) );
        $add( self::tool( 'list_elementor_templates', 'List Elementor templates', 'List local Elementor Library templates with optional type/status/search filters.', self::schema_object( array( 'type' => $str, 'status' => $str, 'search' => $str, 'page' => $id, 'per_page' => $id ) ), true ) );
        $add( self::tool( 'get_elementor_template', 'Get Elementor template', 'Read one local Elementor Library template including its element tree, settings and validation.', self::schema_object( array( 'id' => $id ), array( 'id' ) ), true ) );
        $add( self::tool( 'preview_elementor_template_create', 'Preview Elementor template creation', 'Create an approval proposal for a validated Elementor Library template. No template is written until approval.', self::elementor_template_tool_schema( true ), false ) );
        $template_change = self::elementor_template_tool_schema( false ); $template_change['properties']['id'] = $id; array_unshift( $template_change['required'], 'id' );
        $add( self::tool( 'preview_elementor_template_change', 'Preview Elementor template change', 'Preview update or confirmed deletion of a local Elementor Library template.', $template_change, false, true ) );
        $add( self::tool( 'get_elementor_globals', 'Get Elementor global styles', 'Read the active Elementor Kit global colors, global typography and supported Kit settings.', self::schema_object(), true ) );
        $add( self::tool( 'preview_elementor_globals', 'Preview Elementor global-style changes', 'Create an approval proposal to update active Elementor Kit global colors, typography or Kit settings.', self::schema_object( array( 'settings' => $obj, 'note' => $str ), array( 'settings' ) ), false ) );

        $seo_provider = array( 'type' => 'string', 'enum' => array( 'auto', 'generic', 'yoast', 'rank_math' ), 'default' => 'auto' );
        $seo_fields = self::schema_object( array(
            'title'=>$str, 'description'=>$str, 'canonical'=>array('type'=>'string'), 'focus_keyword'=>$str,
            'robots'=>self::schema_object( array( 'index'=>array('type'=>array('boolean','null')), 'follow'=>array('type'=>array('boolean','null')), 'noarchive'=>self::s('boolean'), 'nosnippet'=>self::s('boolean'), 'noimageindex'=>self::s('boolean') ) ),
            'open_graph_title'=>$str, 'open_graph_description'=>$str, 'open_graph_image'=>array('type'=>'string'), 'open_graph_image_id'=>$id,
            'twitter_title'=>$str, 'twitter_description'=>$str, 'twitter_image'=>array('type'=>'string'), 'twitter_image_id'=>$id, 'twitter_card'=>$str,
            'schema_page_type'=>$str, 'schema_article_type'=>$str, 'schema_json'=>array( 'type'=>array('object','array','null') ),
        ) );
        $add( self::tool( 'get_seo_status', 'Get SEO integration status', 'Detect Yoast SEO, Rank Math and the built-in generic SEO fallback plus supported schema/social capabilities.', self::schema_object(), true ) );
        $add( self::tool( 'get_post_seo', 'Get post SEO metadata', 'Read normalized SEO title, description, canonical, robots, social metadata and schema fields for a post/page/CPT.', self::schema_object( array( 'id'=>$id, 'provider'=>$seo_provider ), array('id') ), true ) );
        $add( self::tool( 'preview_post_seo_update', 'Preview post SEO update', 'Prepare an approval-gated SEO metadata update with stale-write protection. Custom schema_json is only supported by the generic provider.', self::schema_object( array( 'id'=>$id, 'provider'=>$seo_provider, 'fields'=>$seo_fields ), array('id','fields') ), false ) );
        $add( self::tool( 'audit_seo', 'Audit website SEO', 'Audit public WordPress content for missing/duplicate titles and descriptions, length issues, noindex directives, canonical problems and missing social images.', self::schema_object( array( 'provider'=>$seo_provider, 'post_type'=>$str, 'limit'=>self::s('integer') ) ), true ) );

        $add( self::tool( 'get_woocommerce_status', 'Get WooCommerce status', 'Check WooCommerce availability, supported catalog features, and safety boundaries.', self::schema_object(), true ) );
        $add( self::tool( 'list_order_statuses', 'List order statuses', 'List WooCommerce order status slugs and labels. Requires Orders permission.', self::schema_object(), true ) );
        $add( self::tool( 'list_orders', 'List WooCommerce orders', 'List WooCommerce orders with scoped customer/order data. Requires the separate Orders permission.', self::schema_object( array( 'status'=>$str,'customer_id'=>$id,'billing_email'=>$str,'date_after'=>$str,'date_before'=>$str,'page'=>$id,'per_page'=>$id ) ), true ) );
        $add( self::tool( 'get_order', 'Get WooCommerce order', 'Read one WooCommerce order including billing/shipping addresses, line items, shipping, fees, coupons and refund totals. Does not expose payment-token secrets.', self::schema_object( array( 'id'=>$id ), array('id') ), true ) );
        $add( self::tool( 'preview_order_create', 'Preview order creation', 'Prepare a manual WooCommerce order creation proposal. No payment is captured. Statuses with side effects require explicit confirmation.', self::schema_object( array( 'order'=>self::woocommerce_order_tool_schema(true) ), array('order') ), false ) );
        $add( self::tool( 'preview_order_update', 'Preview order update', 'Prepare changes to order status, customer association, customer note or billing/shipping address. Status transitions require explicit side-effect confirmation.', self::schema_object( array( 'id'=>$id,'changes'=>self::woocommerce_order_tool_schema(false) ), array('id','changes') ), false ) );
        $add( self::tool( 'preview_order_item_create', 'Preview order item creation', 'Prepare adding a product/variation line to an existing order. Requires confirm_financial_totals=true.', self::schema_object( array( 'order_id'=>$id,'item'=>self::woocommerce_order_line_tool_schema(true) ), array('order_id','item') ), false ) );
        $add( self::tool( 'preview_order_item_update', 'Preview order item update', 'Prepare quantity/product/subtotal/total changes for an order line. Requires confirm_financial_totals=true.', self::schema_object( array( 'order_id'=>$id,'item_id'=>$id,'changes'=>self::woocommerce_order_line_tool_schema(false) ), array('order_id','item_id','changes') ), false ) );
        $add( self::tool( 'preview_order_item_delete', 'Preview order item deletion', 'Prepare deletion of a product line from an order. Requires confirm_delete=true and confirm_financial_totals=true.', self::schema_object( array( 'order_id'=>$id,'item_id'=>$id,'confirm_delete'=>self::s('boolean'),'confirm_financial_totals'=>self::s('boolean') ), array('order_id','item_id','confirm_delete','confirm_financial_totals') ), false, true ) );
        $add( self::tool( 'list_order_notes', 'List order notes', 'List customer/internal notes for an order.', self::schema_object( array( 'order_id'=>$id,'type'=>array('type'=>'string','enum'=>array('customer','internal')),'limit'=>$id ), array('order_id') ), true ) );
        $add( self::tool( 'preview_order_note_create', 'Preview order note creation', 'Prepare an internal/customer order note. Customer-visible notes require confirm_customer_notification=true because email may be sent.', self::schema_object( array( 'order_id'=>$id,'note'=>$str,'customer_note'=>self::s('boolean'),'confirm_customer_notification'=>self::s('boolean') ), array('order_id','note') ), false ) );
        $add( self::tool( 'preview_order_note_delete', 'Preview order note deletion', 'Prepare deletion of an order note with explicit confirmation.', self::schema_object( array( 'order_id'=>$id,'note_id'=>$id,'confirm_delete'=>self::s('boolean') ), array('order_id','note_id','confirm_delete') ), false, true ) );
        $add( self::tool( 'list_products', 'List WooCommerce products', 'List simple or variable catalog products. Orders are exposed only through the separate Orders permission; payments/refunds remain disabled.', self::schema_object( array( 'search' => $str, 'sku' => $str, 'status' => $str, 'type' => array('type'=>'string','enum'=>array('simple','variable')), 'page' => $id, 'per_page' => $id ) ), true ) );
        $add( self::tool( 'get_product', 'Get WooCommerce product', 'Read one WooCommerce product including attributes, shipping/tax, downloads, and variation IDs.', self::schema_object( array( 'id' => $id ), array( 'id' ) ), true ) );
        $add( self::tool( 'preview_product_create', 'Preview product creation', 'Prepare a simple or variable product creation proposal. Variable creation may include initial variations. No product is created until approval.', self::schema_object( array( 'product' => self::woocommerce_product_tool_schema(true) ), array( 'product' ) ), false ) );
        $add( self::tool( 'preview_product_update', 'Preview product update', 'Prepare a WooCommerce product update proposal covering price, stock, attributes, shipping, tax, downloads and catalog fields.', self::schema_object( array( 'id' => $id, 'changes' => self::woocommerce_product_tool_schema(false) ), array( 'id', 'changes' ) ), false ) );
        $add( self::tool( 'preview_product_trash', 'Preview product Trash', 'Prepare a proposal to move a WooCommerce product to Trash. Orders and historical order items are not touched.', self::schema_object( array( 'id'=>$id ), array( 'id' ) ), false, true ) );
        $add( self::tool( 'preview_product_restore', 'Preview product restore', 'Prepare a proposal to restore a trashed WooCommerce product.', self::schema_object( array( 'id'=>$id ), array( 'id' ) ), false ) );

        $add( self::tool( 'list_product_attributes', 'List global product attributes', 'List global WooCommerce attributes such as Color or Size.', self::schema_object(), true ) );
        $add( self::tool( 'get_product_attribute', 'Get global product attribute', 'Read one global WooCommerce product attribute.', self::schema_object( array( 'id'=>$id ), array( 'id' ) ), true ) );
        $add( self::tool( 'list_product_attribute_terms', 'List product attribute options', 'List terms/options for a global product attribute.', self::schema_object( array( 'attribute_id'=>$id, 'search'=>$str, 'per_page'=>$id ), array( 'attribute_id' ) ), true ) );
        $add( self::tool( 'get_product_attribute_term', 'Get product attribute option', 'Read one term/option from a global product attribute.', self::schema_object( array( 'attribute_id'=>$id, 'term_id'=>$id ), array( 'attribute_id','term_id' ) ), true ) );
        $add( self::tool( 'preview_product_attribute_create', 'Preview global attribute creation', 'Prepare a proposal to create a global WooCommerce product attribute.', self::schema_object( array( 'attribute'=>self::woocommerce_attribute_tool_schema(true) ), array( 'attribute' ) ), false ) );
        $add( self::tool( 'preview_product_attribute_update', 'Preview global attribute update', 'Prepare a proposal to update a global WooCommerce product attribute.', self::schema_object( array( 'id'=>$id, 'changes'=>self::woocommerce_attribute_tool_schema(false) ), array( 'id','changes' ) ), false ) );
        $add( self::tool( 'preview_product_attribute_delete', 'Preview global attribute deletion', 'Prepare deletion of a global attribute. confirm_delete is required; if terms exist confirm_term_deletion is also required.', self::schema_object( array( 'id'=>$id, 'confirm_delete'=>self::s('boolean'), 'confirm_term_deletion'=>self::s('boolean') ), array( 'id','confirm_delete' ) ), false, true ) );
        $add( self::tool( 'preview_product_attribute_term_create', 'Preview attribute option creation', 'Prepare a proposal to create a term/option for a global product attribute.', self::schema_object( array( 'attribute_id'=>$id, 'term'=>self::woocommerce_term_tool_schema(true) ), array( 'attribute_id','term' ) ), false ) );
        $add( self::tool( 'preview_product_attribute_term_update', 'Preview attribute option update', 'Prepare a proposal to rename or edit a global product attribute option.', self::schema_object( array( 'attribute_id'=>$id, 'term_id'=>$id, 'changes'=>self::woocommerce_term_tool_schema(false) ), array( 'attribute_id','term_id','changes' ) ), false ) );
        $add( self::tool( 'preview_product_attribute_term_delete', 'Preview attribute option deletion', 'Prepare deletion of a global product attribute term. Requires confirm_delete=true.', self::schema_object( array( 'attribute_id'=>$id, 'term_id'=>$id, 'confirm_delete'=>self::s('boolean') ), array( 'attribute_id','term_id','confirm_delete' ) ), false, true ) );

        $add( self::tool( 'list_product_variations', 'List product variations', 'List variations for a variable WooCommerce product.', self::schema_object( array( 'product_id'=>$id, 'page'=>self::s('integer'), 'per_page'=>self::s('integer') ), array( 'product_id' ) ), true ) );
        $add( self::tool( 'get_product_variation', 'Get product variation', 'Read one variation including attributes, price, stock, shipping/tax and downloads.', self::schema_object( array( 'product_id'=>$id, 'variation_id'=>$id ), array( 'product_id','variation_id' ) ), true ) );
        $add( self::tool( 'preview_product_variation_create', 'Preview variation creation', 'Prepare a validated variation creation proposal for a variable product.', self::schema_object( array( 'product_id'=>$id, 'variation'=>self::woocommerce_variation_tool_schema(true) ), array( 'product_id','variation' ) ), false ) );
        $add( self::tool( 'preview_product_variation_update', 'Preview variation update', 'Prepare a variation update proposal covering attributes, price, stock, shipping, tax, image and downloads.', self::schema_object( array( 'product_id'=>$id, 'variation_id'=>$id, 'changes'=>self::woocommerce_variation_tool_schema(false) ), array( 'product_id','variation_id','changes' ) ), false ) );
        $add( self::tool( 'preview_product_variation_trash', 'Preview variation Trash', 'Prepare a proposal to move a variation to Trash.', self::schema_object( array( 'product_id'=>$id, 'variation_id'=>$id ), array( 'product_id','variation_id' ) ), false, true ) );
        $add( self::tool( 'preview_product_variation_restore', 'Preview variation restore', 'Prepare a proposal to restore a trashed variation.', self::schema_object( array( 'product_id'=>$id, 'variation_id'=>$id ), array( 'product_id','variation_id' ) ), false ) );

        $add( self::tool( 'list_shipping_classes', 'List shipping classes', 'List WooCommerce product shipping classes.', self::schema_object(), true ) );
        $add( self::tool( 'get_shipping_class', 'Get shipping class', 'Read one WooCommerce product shipping class and usage count.', self::schema_object( array( 'id'=>$id ), array( 'id' ) ), true ) );
        $add( self::tool( 'preview_shipping_class_create', 'Preview shipping class creation', 'Prepare creation of a WooCommerce product shipping class.', self::schema_object( array( 'shipping_class'=>self::woocommerce_term_tool_schema(true) ), array( 'shipping_class' ) ), false ) );
        $add( self::tool( 'preview_shipping_class_update', 'Preview shipping class update', 'Prepare update of a WooCommerce product shipping class.', self::schema_object( array( 'id'=>$id, 'changes'=>self::woocommerce_term_tool_schema(false) ), array( 'id','changes' ) ), false ) );
        $add( self::tool( 'preview_shipping_class_delete', 'Preview shipping class deletion', 'Prepare deletion of a shipping class. Requires confirm_delete; classes assigned to products also require confirm_in_use.', self::schema_object( array( 'id'=>$id, 'confirm_delete'=>self::s('boolean'), 'confirm_in_use'=>self::s('boolean') ), array( 'id','confirm_delete' ) ), false, true ) );

        $add( self::tool( 'list_tax_classes', 'List tax classes', 'List WooCommerce product tax classes, including Standard.', self::schema_object(), true ) );
        $add( self::tool( 'get_tax_class', 'Get tax class', 'Read a WooCommerce product tax class and product usage count.', self::schema_object( array( 'slug'=>$str ), array( 'slug' ) ), true ) );
        $add( self::tool( 'preview_tax_class_create', 'Preview tax class creation', 'Prepare creation of a WooCommerce product tax class.', self::schema_object( array( 'tax_class'=>self::schema_object(array('name'=>$str,'slug'=>$str),array('name')) ), array( 'tax_class' ) ), false ) );
        $add( self::tool( 'preview_tax_class_delete', 'Preview tax class deletion', 'Prepare deletion of a non-Standard tax class. Requires confirm_delete and confirm_in_use when products reference it.', self::schema_object( array( 'slug'=>$str, 'confirm_delete'=>self::s('boolean'), 'confirm_in_use'=>self::s('boolean') ), array( 'slug','confirm_delete' ) ), false, true ) );

        $transaction_op = array(
            'oneOf' => array(
                self::schema_object(
                    array(
                        'action' => array( 'type' => 'string', 'enum' => array( 'content.update' ) ),
                        'id'     => $id,
                        'type'   => array( 'type' => 'string', 'description' => 'WordPress post type key, for example page, post, service.' ),
                        'data'   => $obj,
                    ),
                    array( 'action', 'id', 'type', 'data' )
                ),
                self::schema_object(
                    array(
                        'action' => array( 'type' => 'string', 'enum' => array( 'media.update' ) ),
                        'id'     => $id,
                        'data'   => $obj,
                    ),
                    array( 'action', 'id', 'data' )
                ),
                self::schema_object(
                    array(
                        'action'  => array( 'type' => 'string', 'enum' => array( 'acf.update' ) ),
                        'post_id' => $id,
                        'data'    => self::schema_object( array( 'values' => $obj ), array( 'values' ) ),
                    ),
                    array( 'action', 'post_id', 'data' )
                ),
                self::schema_object(
                    array(
                        'action' => array( 'type' => 'string', 'enum' => array( 'woocommerce.product.update' ) ),
                        'id'     => $id,
                        'data'   => $obj,
                    ),
                    array( 'action', 'id', 'data' )
                ),
            ),
        );
        $add( self::tool( 'preview_transaction', 'Preview multi-action transaction', 'Prepare coordinated content, media, ACF, or WooCommerce updates with stale-target protection and compensation rollback.', self::schema_object( array( 'operations' => array( 'type' => 'array', 'items' => $transaction_op, 'minItems' => 1, 'maxItems' => 20 ), 'note' => $str ), array( 'operations' ) ), false ) );
        $add( self::tool( 'list_approvals', 'List change proposals', 'List pending or historical design, commerce, and transaction proposals.', self::schema_object( array( 'status' => $str, 'limit' => $id ) ), true ) );
        $add( self::tool( 'get_approval', 'Get change proposal', 'Read a proposal before applying or cancelling it.', self::schema_object( array( 'id' => $id ), array( 'id' ) ), true ) );
        $add( self::tool( 'apply_approval', 'Apply approved WordPress change', 'Apply a previously previewed proposal. Use only after the user has authorized the intended change.', self::schema_object( array( 'id' => $id ), array( 'id' ) ), false ) );
        $add( self::tool( 'cancel_approval', 'Cancel change proposal', 'Cancel a pending proposal without modifying its target.', self::schema_object( array( 'id' => $id ), array( 'id' ) ), false ) );
        $add( self::tool( 'list_activity', 'List ALIFY activity', 'Review recent connector activity and rollback-capable activity IDs.', self::schema_object( array( 'limit' => $id ) ), true ) );
        $add( self::tool( 'rollback_activity', 'Rollback WordPress activity', 'Reverse a supported prior connector change. This overwrites current target state and requires explicit user confirmation.', self::schema_object( array( 'id' => $id ), array( 'id' ) ), false, true ) );

        self::$tool_cache = $tools;
        return $tools;
    }
}
