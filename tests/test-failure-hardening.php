<?php

class Test_ALIFY_AI_Failure_Hardening extends WP_UnitTestCase {
    public function test_media_compensation_failure_is_reported_as_unknown_state(): void {
        $method = new ReflectionMethod( ALIFY_AI_Media::class, 'compensation_failure' );
        $method->setAccessible( true );
        $error = $method->invoke( null, 'update_media', 123, new WP_Error( 'restore_failed', 'restore failed' ), null );
        $this->assertWPError( $error );
        $this->assertSame( 'alify_ai_media_unknown_state', $error->get_error_code() );
        $this->assertSame( 123, (int) $error->get_error_data()['attachment_id'] );
    }

    public function test_menu_compensation_failure_is_reported_as_unknown_state(): void {
        $method = new ReflectionMethod( ALIFY_AI_Menus::class, 'compensation_failure' );
        $method->setAccessible( true );
        $error = $method->invoke( null, 'reorder_menu_items', 7, 11, new WP_Error( 'restore_failed', 'restore failed' ) );
        $this->assertWPError( $error );
        $this->assertSame( 'alify_ai_menu_unknown_state', $error->get_error_code() );
        $data = $error->get_error_data();
        $this->assertSame( 7, (int) $data['menu_id'] );
        $this->assertSame( 11, (int) $data['item_id'] );
    }


    public function test_phase_26_unknown_state_guards_are_present(): void {
        $root = dirname( __DIR__ );
        $gutenberg = file_get_contents( $root . '/includes/class-alify-ai-gutenberg.php' );
        $seo = file_get_contents( $root . '/includes/class-alify-ai-seo.php' );
        $structure = file_get_contents( $root . '/includes/class-alify-ai-structure.php' ) . file_get_contents( $root . '/includes/class-alify-ai-rest.php' );
        $media = file_get_contents( $root . '/includes/class-alify-ai-media.php' );
        $this->assertStringContainsString( 'alify_ai_gutenberg_unknown_state', $gutenberg );
        $this->assertStringContainsString( 'alify_ai_seo_unknown_state', $seo );
        $this->assertStringContainsString( 'alify_ai_structure_unknown_state', $structure );
        $this->assertStringContainsString( 'media_rollback_log_failed_after_file_restore', $media );
    }
}
