<?php

class ALIFY_AI_Approvals_Test extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        ALIFY_AI_Approvals::activate();
    }

    public function test_invalid_approval_kind_is_rejected(): void {
        $result = ALIFY_AI_Approvals::create( 'not_supported', 0, array(), array() );
        $this->assertWPError( $result );
        $this->assertSame( 'alify_ai_invalid_approval_kind', $result->get_error_code() );
    }

    public function test_pending_approval_can_be_cancelled_once(): void {
        $approval = ALIFY_AI_Approvals::create( 'seo', 0, array( 'action' => 'noop' ), array( 'summary' => 'test' ) );
        $this->assertIsArray( $approval );
        $cancelled = ALIFY_AI_Approvals::cancel( (int) $approval['id'] );
        $this->assertIsArray( $cancelled );
        $this->assertSame( 'cancelled', $cancelled['status'] );
        $again = ALIFY_AI_Approvals::cancel( (int) $approval['id'] );
        $this->assertWPError( $again );
    }
}
