<?php

class ALIFY_AI_OAuth_Test extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        ALIFY_AI_Access_Tokens::activate();
        ALIFY_AI_OAuth::activate();
        ALIFY_AI_Auth::update_scopes( array_fill_keys( ALIFY_AI_Auth::scope_names(), 1 ) );
    }

    public function test_metadata_advertises_pkce_and_public_client_auth(): void {
        $metadata = ALIFY_AI_OAuth::authorization_server_metadata();
        $this->assertContains( 'S256', $metadata['code_challenge_methods_supported'] );
        $this->assertContains( 'authorization_code', $metadata['grant_types_supported'] );
        $this->assertContains( 'refresh_token', $metadata['grant_types_supported'] );
        $this->assertSame( array( 'none' ), $metadata['token_endpoint_auth_methods_supported'] );
    }

    public function test_dynamic_client_registration_accepts_https_redirect(): void {
        $request = new WP_REST_Request( 'POST', '/alify-ai/v1/oauth/register' );
        $request->set_param( 'client_name', 'PHPUnit MCP client' );
        $request->set_param( 'redirect_uris', array( 'https://client.example/oauth/callback' ) );
        $response = ALIFY_AI_OAuth::register_client( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 201, $response->get_status() );
        $data = $response->get_data();
        $this->assertStringStartsWith( 'alify_oauth_', $data['client_id'] );
        $this->assertSame( 'none', $data['token_endpoint_auth_method'] );
    }

    public function test_oauth_access_token_is_resource_bound_and_capability_checked(): void {
        $author_id = self::factory()->user->create( array( 'role' => 'author' ) );
        $scopes = array( 'read' => 1, 'content' => 1, 'structure' => 1 );
        $created = ALIFY_AI_Access_Tokens::create_oauth(
            $author_id,
            'OAuth PHPUnit',
            $scopes,
            3600,
            'alify_oauth_test',
            ALIFY_AI_MCP::get_public_mcp_base()
        );
        $this->assertIsArray( $created );
        $this->assertStringStartsWith( 'alify_oat_', $created['token'] );

        $ok = ALIFY_AI_Access_Tokens::verify( $created['token'], ALIFY_AI_MCP::get_public_mcp_base() );
        $this->assertIsArray( $ok );
        $this->assertSame( 'oauth_bearer', $ok['auth_type'] );

        $wrong_resource = ALIFY_AI_Access_Tokens::verify( $created['token'], rest_url( 'alify-ai/v1/site' ) );
        $this->assertWPError( $wrong_resource );
        $this->assertSame( 'alify_ai_bearer_resource_mismatch', $wrong_resource->get_error_code() );

        ALIFY_AI_Auth::set_runtime_context( $ok );
        ALIFY_AI_Auth::begin_internal_mcp();
        $request = new WP_REST_Request( 'GET', '/alify-ai/v1/site' );
        $content = ALIFY_AI_Auth::authorize( $request, 'content' );
        $structure = ALIFY_AI_Auth::authorize( $request, 'structure' );
        ALIFY_AI_Auth::end_internal_mcp();
        ALIFY_AI_Auth::clear_runtime_context();
        $this->assertTrue( $content );
        $this->assertWPError( $structure );
        $this->assertSame( 'alify_ai_user_capability_forbidden', $structure->get_error_code() );
    }
    public function test_authorization_code_exchange_and_refresh_rotation(): void {
        global $wpdb;
        $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $register = new WP_REST_Request( 'POST', '/alify-ai/v1/oauth/register' );
        $register->set_param( 'client_name', 'Exchange test' );
        $register->set_param( 'redirect_uris', array( 'https://client.example/callback' ) );
        $client_response = ALIFY_AI_OAuth::register_client( $register );
        $client_id = $client_response->get_data()['client_id'];

        $verifier = str_repeat( 'A', 64 );
        $challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
        $code = 'alify_ac_' . bin2hex( random_bytes( 32 ) );
        $code_hash = hash_hmac( 'sha256', $code, wp_salt( 'auth' ) );
        $resource = ALIFY_AI_MCP::get_public_mcp_base();
        $wpdb->insert(
            $wpdb->prefix . 'alify_ai_oauth_codes',
            array(
                'code_hash' => $code_hash,
                'client_id' => $client_id,
                'user_id' => $user_id,
                'redirect_uri' => 'https://client.example/callback',
                'resource' => $resource,
                'scopes' => wp_json_encode( array( 'read', 'content' ) ),
                'code_challenge' => $challenge,
                'created_at' => current_time( 'mysql', true ),
                'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ),
            )
        );

        $request = new WP_REST_Request( 'POST', '/alify-ai/v1/oauth/token' );
        $request->set_param( 'grant_type', 'authorization_code' );
        $request->set_param( 'code', $code );
        $request->set_param( 'client_id', $client_id );
        $request->set_param( 'redirect_uri', 'https://client.example/callback' );
        $request->set_param( 'code_verifier', $verifier );
        $request->set_param( 'resource', $resource );
        $response = ALIFY_AI_OAuth::token( $request );
        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertStringStartsWith( 'alify_oat_', $data['access_token'] );
        $this->assertStringStartsWith( 'alify_rt_', $data['refresh_token'] );
        $this->assertArrayNotHasKey( '_refresh_id', $data );
        $this->assertSame( 3600, $data['expires_in'] );

        $old_refresh = $data['refresh_token'];
        $refresh = new WP_REST_Request( 'POST', '/alify-ai/v1/oauth/token' );
        $refresh->set_param( 'grant_type', 'refresh_token' );
        $refresh->set_param( 'refresh_token', $old_refresh );
        $refresh->set_param( 'client_id', $client_id );
        $refresh->set_param( 'resource', $resource );
        $rotated = ALIFY_AI_OAuth::token( $refresh );
        $this->assertSame( 200, $rotated->get_status() );
        $this->assertNotSame( $old_refresh, $rotated->get_data()['refresh_token'] );

        $replay = ALIFY_AI_OAuth::token( $refresh );
        $this->assertSame( 400, $replay->get_status() );
        $this->assertSame( 'invalid_grant', $replay->get_data()['error'] );
    }

    public function test_oauth_clients_schema_has_issued_at_column(): void {
        global $wpdb;
        $column = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}alify_ai_oauth_clients LIKE 'client_id_issued_at'", ARRAY_A );
        $this->assertIsArray( $column );
        $this->assertSame( 'client_id_issued_at', $column['Field'] );
    }

    public function test_dynamic_registration_persists_issued_at(): void {
        global $wpdb;
        $request = new WP_REST_Request( 'POST', '/alify-ai/v1/oauth/register' );
        $request->set_param( 'client_name', 'Issued-at regression' );
        $request->set_param( 'redirect_uris', array( 'https://client.example/issued-at' ) );
        $response = ALIFY_AI_OAuth::register_client( $request );
        $this->assertSame( 201, $response->get_status() );
        $client_id = $response->get_data()['client_id'];
        $issued_at = $wpdb->get_var( $wpdb->prepare( "SELECT client_id_issued_at FROM {$wpdb->prefix}alify_ai_oauth_clients WHERE client_id=%s", $client_id ) );
        $this->assertGreaterThan( 0, (int) $issued_at );
    }

    public function test_authorization_code_grant_rejects_missing_binding_fields(): void {
        $request = new WP_REST_Request( 'POST', '/alify-ai/v1/oauth/token' );
        $request->set_param( 'grant_type', 'authorization_code' );
        $request->set_param( 'code_verifier', str_repeat( 'A', 64 ) );
        $response = ALIFY_AI_OAuth::token( $request );
        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'invalid_request', $response->get_data()['error'] );
    }

    public function test_refresh_grant_rejects_missing_client_and_token(): void {
        $request = new WP_REST_Request( 'POST', '/alify-ai/v1/oauth/token' );
        $request->set_param( 'grant_type', 'refresh_token' );
        $response = ALIFY_AI_OAuth::token( $request );
        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'invalid_request', $response->get_data()['error'] );
    }


    public function test_dynamic_registration_rate_limit_is_atomic_and_enforced(): void {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.77';
        for ( $i = 0; $i < 20; $i++ ) {
            $request = new WP_REST_Request( 'POST', '/alify-ai/v1/oauth/register' );
            $request->set_param( 'client_name', 'Rate test ' . $i );
            $request->set_param( 'redirect_uris', array( 'https://client.example/rate-' . $i ) );
            $response = ALIFY_AI_OAuth::register_client( $request );
            $this->assertSame( 201, $response->get_status() );
        }
        $blocked = new WP_REST_Request( 'POST', '/alify-ai/v1/oauth/register' );
        $blocked->set_param( 'client_name', 'Rate blocked' );
        $blocked->set_param( 'redirect_uris', array( 'https://client.example/rate-blocked' ) );
        $response = ALIFY_AI_OAuth::register_client( $blocked );
        $this->assertSame( 429, $response->get_status() );
        $this->assertSame( 'rate_limit_exceeded', $response->get_data()['error'] );
        unset( $_SERVER['REMOTE_ADDR'] );
    }

    public function test_discovery_advertises_only_enabled_scopes_plus_offline_access(): void {
        ALIFY_AI_Auth::update_scopes( array(
            'read' => 1,
            'content' => 1,
            'structure' => 0,
            'acf' => 0,
            'media' => 0,
            'menus' => 0,
            'design' => 0,
            'commerce' => 0,
            'orders' => 0,
            'seo' => 0,
            'transactions' => 0,
        ) );
        $metadata = ALIFY_AI_OAuth::authorization_server_metadata();
        $this->assertContains( 'read', $metadata['scopes_supported'] );
        $this->assertContains( 'content', $metadata['scopes_supported'] );
        $this->assertContains( 'offline_access', $metadata['scopes_supported'] );
        $this->assertNotContains( 'structure', $metadata['scopes_supported'] );
        $this->assertNotContains( 'acf', $metadata['scopes_supported'] );
    }

    public function test_disabled_known_scopes_are_narrowed_instead_of_invalid_scope(): void {
        ALIFY_AI_Auth::update_scopes( array(
            'read' => 1,
            'content' => 1,
            'structure' => 0,
        ) );
        $method = new ReflectionMethod( ALIFY_AI_OAuth::class, 'parse_scopes' );
        $method->setAccessible( true );
        $result = $method->invoke( null, 'read content structure offline_access' );
        $this->assertIsArray( $result );
        $this->assertSame( array( 'read', 'content', 'offline_access' ), $result );
    }

    public function test_unknown_scope_still_returns_invalid_scope(): void {
        $method = new ReflectionMethod( ALIFY_AI_OAuth::class, 'parse_scopes' );
        $method->setAccessible( true );
        $result = $method->invoke( null, 'read definitely_not_a_scope' );
        $this->assertWPError( $result );
        $this->assertSame( 'invalid_scope', $result->get_error_code() );
    }

    public function test_user_capability_negotiation_narrows_admin_only_scope(): void {
        ALIFY_AI_Auth::update_scopes( array_fill_keys( ALIFY_AI_Auth::scope_names(), 1 ) );
        $author_id = self::factory()->user->create( array( 'role' => 'author' ) );
        $method = new ReflectionMethod( ALIFY_AI_OAuth::class, 'effective_scopes_for_user' );
        $method->setAccessible( true );
        $result = $method->invoke( null, $author_id, array( 'read', 'content', 'structure', 'offline_access' ) );
        $this->assertIsArray( $result );
        $this->assertContains( 'read', $result );
        $this->assertContains( 'content', $result );
        $this->assertContains( 'offline_access', $result );
        $this->assertNotContains( 'structure', $result );
    }

    public function test_managed_reauthorization_expands_stale_client_scope_to_enabled_admin_permissions(): void {
        ALIFY_AI_Auth::update_scopes( array(
            'read' => 1,
            'content' => 1,
            'structure' => 1,
            'acf' => 1,
            'media' => 1,
            'menus' => 1,
            'design' => 1,
            'commerce' => 0,
            'orders' => 0,
            'seo' => 1,
            'transactions' => 1,
            'code_read' => 1,
            'code_write' => 0,
        ) );
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $method = new ReflectionMethod( ALIFY_AI_OAuth::class, 'managed_scopes_for_user' );
        $method->setAccessible( true );
        $result = $method->invoke( null, $admin_id, array( 'read', 'content', 'offline_access' ) );
        $this->assertIsArray( $result );
        foreach ( array( 'read', 'content', 'structure', 'acf', 'media', 'menus', 'design', 'seo', 'transactions', 'code_read', 'offline_access' ) as $scope ) {
            $this->assertContains( $scope, $result );
        }
        $this->assertNotContains( 'commerce', $result );
        $this->assertNotContains( 'orders', $result );
        $this->assertNotContains( 'code_write', $result );
    }

    public function test_managed_reauthorization_never_grants_beyond_wordpress_user_capabilities(): void {
        ALIFY_AI_Auth::update_scopes( array_fill_keys( ALIFY_AI_Auth::scope_names(), 1 ) );
        $author_id = self::factory()->user->create( array( 'role' => 'author' ) );
        $method = new ReflectionMethod( ALIFY_AI_OAuth::class, 'managed_scopes_for_user' );
        $method->setAccessible( true );
        $result = $method->invoke( null, $author_id, array( 'read', 'content', 'offline_access' ) );
        $this->assertIsArray( $result );
        $this->assertContains( 'read', $result );
        $this->assertContains( 'content', $result );
        $this->assertContains( 'offline_access', $result );
        $this->assertNotContains( 'structure', $result );
        $this->assertNotContains( 'acf', $result );
        $this->assertNotContains( 'menus', $result );
        $this->assertNotContains( 'code_read', $result );
        $this->assertNotContains( 'code_write', $result );
    }

    public function test_refresh_scope_ceiling_remains_original_grant(): void {
        $oauth = file_get_contents( dirname( __DIR__ ) . '/includes/class-alify-ai-oauth.php' );
        $this->assertStringContainsString( 'Refresh grants keep', $oauth );
        $this->assertStringContainsString( 'array_diff( $requested_scopes, $scopes )', $oauth );
    }

}
