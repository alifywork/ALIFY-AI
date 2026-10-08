<?php

class ALIFY_AI_MCP_Validation_Test extends WP_UnitTestCase {
    private string $token = '';

    public function set_up(): void {
        parent::set_up();
        ALIFY_AI_Access_Tokens::activate();
        ALIFY_AI_Auth::update_scopes( array_fill_keys( ALIFY_AI_Auth::scope_names(), 1 ) );
        $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $created = ALIFY_AI_Access_Tokens::create(
            $user_id,
            'MCP validation test',
            array_fill_keys( ALIFY_AI_Auth::scope_names(), 1 ),
            1
        );
        $this->assertIsArray( $created );
        $this->token = $created['token'];
    }

    private function request( array $body ): WP_REST_Request {
        $request = new WP_REST_Request( 'POST', '/alify-ai/v1/mcp' );
        $request->set_header( 'Authorization', 'Bearer ' . $this->token );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
        return $request;
    }

    public function test_non_object_params_are_rejected(): void {
        $response = ALIFY_AI_MCP::handle_post( $this->request( array(
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'ping',
            'params' => 'not-an-object',
        ) ) );
        $this->assertSame( -32602, $response->get_data()['error']['code'] );
    }

    public function test_non_object_tool_arguments_are_rejected(): void {
        $response = ALIFY_AI_MCP::handle_post( $this->request( array(
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => array( 'name' => 'site_info', 'arguments' => 'bad' ),
        ) ) );
        $this->assertSame( -32602, $response->get_data()['error']['code'] );
    }

    public function test_complex_jsonrpc_id_is_rejected(): void {
        $response = ALIFY_AI_MCP::handle_post( $this->request( array(
            'jsonrpc' => '2.0',
            'id' => array( 'nested' => 1 ),
            'method' => 'ping',
        ) ) );
        $this->assertSame( -32600, $response->get_data()['error']['code'] );
        $this->assertNull( $response->get_data()['id'] );
    }


    public function test_boolean_jsonrpc_id_is_rejected(): void {
        $response = ALIFY_AI_MCP::handle_post( $this->request( array(
            'jsonrpc' => '2.0',
            'id' => true,
            'method' => 'ping',
        ) ) );
        $this->assertSame( -32600, $response->get_data()['error']['code'] );
        $this->assertNull( $response->get_data()['id'] );
    }

    public function test_rate_limit_uses_atomic_database_counter(): void {
        global $wpdb;
        ALIFY_AI_Rate_Limiter::activate();
        $table = $wpdb->prefix . 'alify_ai_rate_limits';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        $this->assertSame( $table, $exists );

        $verified = ALIFY_AI_Access_Tokens::verify( $this->token, ALIFY_AI_MCP::get_public_mcp_base() );
        $this->assertIsArray( $verified );
        ALIFY_AI_Auth::set_runtime_context( $verified );
        $_SERVER['REMOTE_ADDR'] = '203.0.113.24';
        $method = new ReflectionMethod( ALIFY_AI_MCP::class, 'check_rate_limit' );
        $method->setAccessible( true );
        $request = new WP_REST_Request( 'POST', '/alify-ai/v1/mcp' );
        for ( $i = 0; $i < 120; $i++ ) {
            $this->assertTrue( $method->invoke( null, $request ) );
        }
        $limited = $method->invoke( null, $request );
        $this->assertWPError( $limited );
        $this->assertSame( 'alify_mcp_rate_limited', $limited->get_error_code() );
        ALIFY_AI_Auth::clear_runtime_context();
    }

    public function test_modern_server_discovery_is_supported(): void {
        $response = ALIFY_AI_MCP::handle_post( $this->request( array(
            'jsonrpc' => '2.0',
            'id' => 'discover-1',
            'method' => 'server/discover',
            'params' => array(
                '_meta' => array(
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => array(),
                    'io.modelcontextprotocol/clientInfo' => array( 'name' => 'phpunit', 'version' => '1' ),
                ),
            ),
        ) ) );
        $data = $response->get_data();
        $this->assertSame( 'complete', $data['result']['resultType'] );
        $this->assertContains( '2026-07-28', $data['result']['supportedVersions'] );
        $this->assertArrayHasKey( 'tools', $data['result']['capabilities'] );
        $this->assertSame( 'private', $data['result']['cacheScope'] );
    }

    public function test_tools_list_uses_modern_complete_result_and_safe_schemas(): void {
        $response = ALIFY_AI_MCP::handle_post( $this->request( array(
            'jsonrpc' => '2.0',
            'id' => 40,
            'method' => 'tools/list',
            'params' => array(
                '_meta' => array(
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => array(),
                ),
            ),
        ) ) );
        $result = $response->get_data()['result'];
        $this->assertSame( 'complete', $result['resultType'] );
        $this->assertSame( 'private', $result['cacheScope'] );
        $this->assertNotEmpty( $result['tools'] );

        foreach ( $result['tools'] as $tool ) {
            $this->assertMatchesRegularExpression( '/^[A-Za-z0-9_.-]{1,128}$/', $tool['name'] );
            $this->assertSame( 'object', $tool['inputSchema']['type'] );
            $json = wp_json_encode( $tool['inputSchema'] );
            $this->assertStringNotContainsString( '"oneOf"', $json );
            $this->assertStringNotContainsString( '"anyOf"', $json );
            $this->assertStringNotContainsString( '"allOf"', $json );
            $this->assertStringNotContainsString( '"type":"null"', $json );
            $this->assertStringNotContainsString( '"properties":[]', $json );
        }
    }

    public function test_tools_list_is_filtered_to_effective_scopes(): void {
        ALIFY_AI_Auth::update_scopes( array( 'read' => 1 ) );
        $response = ALIFY_AI_MCP::handle_post( $this->request( array(
            'jsonrpc' => '2.0',
            'id' => 41,
            'method' => 'tools/list',
            'params' => array(),
        ) ) );
        $names = array_column( $response->get_data()['result']['tools'], 'name' );
        $this->assertContains( 'list_content', $names );
        $this->assertNotContains( 'create_content', $names );
        $this->assertNotContains( 'create_post_type', $names );
        $this->assertNotContains( 'preview_transaction', $names );
    }
}
