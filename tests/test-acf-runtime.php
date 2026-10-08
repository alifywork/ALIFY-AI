<?php

class ALIFY_AI_ACF_Runtime_Test extends WP_UnitTestCase {
    private string $group_key = 'group_alify_runtime_test';
    private string $field_key = 'field_alify_runtime_text';

    public function set_up(): void {
        parent::set_up();
        if ( ! ALIFY_AI_ACF::available() || ! function_exists( 'acf_delete_field_group' ) ) {
            $this->markTestSkipped( 'ACF runtime integration test requires Advanced Custom Fields.' );
        }
        acf_update_field_group( array(
            'key'      => $this->group_key,
            'title'    => 'ALIFY Runtime Test',
            'active'   => 1,
            'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
        ) );
        acf_update_field( array(
            'key'    => $this->field_key,
            'label'  => 'Runtime Text',
            'name'   => 'alify_runtime_text',
            'type'   => 'text',
            'parent' => $this->group_key,
        ) );
    }

    public function tear_down(): void {
        if ( function_exists( 'acf_get_field_group' ) && function_exists( 'acf_delete_field_group' ) ) {
            $group = acf_get_field_group( $this->group_key );
            if ( is_array( $group ) ) {
                acf_delete_field_group( absint( $group['ID'] ?? 0 ) ?: $this->group_key );
            }
        }
        parent::tear_down();
    }

    public function test_value_update_activity_can_be_rolled_back(): void {
        $post_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
        update_field( $this->field_key, 'before', $post_id );

        $updated = ALIFY_AI_ACF::update_values( $post_id, array( $this->field_key => 'after' ) );
        $this->assertIsArray( $updated );
        $this->assertGreaterThan( 0, (int) $updated['activity_id'] );
        $this->assertSame( 'after', get_field( $this->field_key, $post_id, false ) );

        $entry = ALIFY_AI_Audit::get( (int) $updated['activity_id'] );
        $this->assertIsArray( $entry );
        $rolled_back = ALIFY_AI_ACF::rollback_activity( $entry );

        $this->assertIsArray( $rolled_back );
        $this->assertSame( 'before', get_field( $this->field_key, $post_id, false ) );
    }

    public function test_value_rollback_refuses_to_overwrite_newer_acf_changes(): void {
        $post_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
        update_field( $this->field_key, 'before', $post_id );
        $updated = ALIFY_AI_ACF::update_values( $post_id, array( $this->field_key => 'after' ) );
        $this->assertIsArray( $updated );

        update_field( $this->field_key, 'newer', $post_id );
        $entry = ALIFY_AI_Audit::get( (int) $updated['activity_id'] );
        $rolled_back = ALIFY_AI_ACF::rollback_activity( $entry );

        $this->assertWPError( $rolled_back );
        $this->assertSame( 'alify_ai_rollback_conflict', $rolled_back->get_error_code() );
        $this->assertSame( 'newer', get_field( $this->field_key, $post_id, false ) );
    }
}
