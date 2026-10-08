<?php

class ALIFY_AI_Smoke_Test extends WP_UnitTestCase {
    public function test_expected_version_is_loaded(): void {
        $this->assertSame( '0.32.0', ALIFY_AI_VERSION );
    }

    public function test_core_classes_are_available(): void {
        $classes = array(
            'ALIFY_AI_Access_Tokens',
            'ALIFY_AI_Auth',
            'ALIFY_AI_Diagnostics',
            'ALIFY_AI_Audit',
            'ALIFY_AI_Approvals',
            'ALIFY_AI_REST',
            'ALIFY_AI_MCP',
            'ALIFY_AI_Code_Inspection',
        );
        foreach ( $classes as $class ) {
            $this->assertTrue( class_exists( $class ), $class . ' should be loaded.' );
        }
    }

    public function test_mcp_connection_can_be_created(): void {
        ALIFY_AI_MCP::revoke_connection();
        ALIFY_AI_MCP::ensure_connection();
        $this->assertNotEmpty( ALIFY_AI_MCP::get_connection_url() );
    }
}
