<?php

class ALIFY_AI_ACF_Options_Test extends WP_UnitTestCase {
    public function test_mcp_exposes_acf_options_tools(): void {
        $tools = ALIFY_AI_MCP::tools();
        $this->assertArrayHasKey( 'list_acf_option_pages', $tools );
        $this->assertArrayHasKey( 'get_acf_option_values', $tools );
        $this->assertArrayHasKey( 'update_acf_option_values', $tools );
        $this->assertTrue( (bool) $tools['list_acf_option_pages']['annotations']['readOnlyHint'] );
        $this->assertFalse( (bool) $tools['update_acf_option_values']['annotations']['readOnlyHint'] );
    }

    public function test_options_api_gracefully_handles_acf_absence(): void {
        if ( ALIFY_AI_ACF::available() ) {
            $this->markTestSkipped( 'This assertion is only for the no-ACF runtime.' );
        }
        $this->assertSame( array(), ALIFY_AI_ACF::list_option_pages() );
    }

    public function test_runtime_tool_summary_reports_code_and_options_visibility_keys(): void {
        $summary = ALIFY_AI_MCP::runtime_tool_summary();
        foreach ( array( 'total','visible','code_read_visible','code_write_visible','acf_options_read_visible','acf_options_write_visible' ) as $key ) {
            $this->assertArrayHasKey( $key, $summary );
        }
    }
}
