<?php

class ALIFY_AI_Access_Tokens_Test extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        ALIFY_AI_Access_Tokens::activate();
        ALIFY_AI_Auth::update_scopes( array_fill_keys( ALIFY_AI_Auth::scope_names(), 1 ) );
    }

    public function test_token_is_bound_to_user_scopes_and_can_be_revoked(): void {
        $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $created = ALIFY_AI_Access_Tokens::create(
            $user_id,
            'PHPUnit token',
            array( 'read' => 1, 'content' => 1 ),
            7
        );
        $this->assertIsArray( $created );
        $this->assertStringStartsWith( 'alify_pat_', $created['token'] );

        $verified = ALIFY_AI_Access_Tokens::verify( $created['token'] );
        $this->assertIsArray( $verified );
        $this->assertSame( $user_id, $verified['user_id'] );
        $this->assertSame( 1, $verified['scopes']['read'] );
        $this->assertSame( 0, $verified['scopes']['structure'] );

        $this->assertTrue( ALIFY_AI_Access_Tokens::revoke( (int) $created['id'] ) );
        $revoked = ALIFY_AI_Access_Tokens::verify( $created['token'] );
        $this->assertWPError( $revoked );
        $this->assertSame( 'alify_ai_bearer_revoked', $revoked->get_error_code() );
    }

    public function test_bearer_rest_auth_honors_wordpress_capabilities(): void {
        $author_id = self::factory()->user->create( array( 'role' => 'author' ) );
        $created = ALIFY_AI_Access_Tokens::create(
            $author_id,
            'Author token',
            array( 'read' => 1, 'content' => 1, 'structure' => 1 ),
            7
        );
        $request = new WP_REST_Request( 'GET', '/alify-ai/v1/site' );
        $request->set_header( 'Authorization', 'Bearer ' . $created['token'] );
        $this->assertTrue( ALIFY_AI_Auth::authorize( $request, 'content' ) );

        $structure = ALIFY_AI_Auth::authorize( $request, 'structure' );
        $this->assertWPError( $structure );
        $this->assertSame( 'alify_ai_user_capability_forbidden', $structure->get_error_code() );
    }
}
