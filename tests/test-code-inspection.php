<?php

class ALIFY_AI_Code_Inspection_Test extends WP_UnitTestCase {
    public function test_code_read_scope_exists_and_is_disabled_by_default_policy_shape(): void {
        $this->assertContains( 'code_read', ALIFY_AI_Auth::scope_names() );
        $this->assertContains( 'code_write', ALIFY_AI_Auth::scope_names() );
        $this->assertSame( 'edit_theme_options', ALIFY_AI_Auth::capability_for_scope( 'code_read' ) );
        $this->assertSame( 'edit_theme_options', ALIFY_AI_Auth::capability_for_scope( 'code_write' ) );
    }

    public function test_active_theme_info_is_exposed_without_absolute_paths(): void {
        $info = ALIFY_AI_Code_Inspection::active_theme();
        $this->assertArrayHasKey( 'stylesheet', $info );
        $this->assertArrayHasKey( 'roots', $info );
        $this->assertArrayNotHasKey( 'path', $info );
    }

    public function test_theme_path_traversal_is_rejected(): void {
        $result = ALIFY_AI_Code_Inspection::read_file( 'theme', '', '../wp-config.php' );
        $this->assertWPError( $result );
        $this->assertSame( 'alify_ai_code_path_traversal', $result->get_error_code() );
    }

    public function test_frontend_inspection_rejects_absolute_external_url(): void {
        $result = ALIFY_AI_Code_Inspection::inspect_frontend( 'https://example.com/', false );
        $this->assertWPError( $result );
        $this->assertSame( 'alify_ai_frontend_invalid_path', $result->get_error_code() );
    }

    public function test_mcp_exposes_read_only_code_inspection_tools(): void {
        $tools = ALIFY_AI_MCP::tools();
        foreach ( array( 'get_active_theme_source_info', 'list_active_plugins', 'list_source_files', 'get_source_file', 'search_source_code', 'inspect_frontend_page' ) as $name ) {
            $this->assertArrayHasKey( $name, $tools );
            $this->assertTrue( (bool) $tools[ $name ]['annotations']['readOnlyHint'] );
            $this->assertFalse( (bool) $tools[ $name ]['annotations']['destructiveHint'] );
        }
    }

    public function test_mcp_exposes_approval_gated_code_write_tool(): void {
        $tools = ALIFY_AI_MCP::tools();
        $this->assertArrayHasKey( 'preview_source_file_update', $tools );
        $this->assertFalse( (bool) $tools['preview_source_file_update']['annotations']['readOnlyHint'] );
    }
}
