<?php

class ALIFY_AI_WooCommerce_Runtime_Test extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product_Variable' ) || ! function_exists( 'wc_get_product' ) ) {
            $this->markTestSkipped( 'WooCommerce runtime integration test requires WooCommerce.' );
        }
    }

    public function test_variable_product_variation_rollback_keeps_parent_loadable(): void {
        $parent = new WC_Product_Variable();
        $parent->set_name( 'ALIFY Runtime Variable' );
        $parent->set_status( 'publish' );

        $attribute = new WC_Product_Attribute();
        $attribute->set_name( 'Size' );
        $attribute->set_options( array( 'Small', 'Large' ) );
        $attribute->set_visible( true );
        $attribute->set_variation( true );
        $parent->set_attributes( array( $attribute ) );
        $parent_id = $parent->save();

        $variation = new WC_Product_Variation();
        $variation->set_parent_id( $parent_id );
        $variation->set_attributes( array( 'size' => 'Small' ) );
        $variation->set_regular_price( '10' );
        $variation_id = $variation->save();
        WC_Product_Variable::sync( $parent_id );

        $before = ALIFY_AI_WooCommerce::get_product( $parent_id );
        $this->assertIsArray( $before );
        $loaded = wc_get_product( $variation_id );
        $this->assertInstanceOf( WC_Product_Variation::class, $loaded );

        wp_trash_post( $variation_id );
        WC_Product_Variable::sync( $parent_id );
        $this->assertNotFalse( wp_untrash_post( $variation_id ) );
        WC_Product_Variable::sync( $parent_id );
        clean_post_cache( $parent_id );

        $fresh_parent = wc_get_product( $parent_id );
        $this->assertInstanceOf( WC_Product_Variable::class, $fresh_parent );
        $this->assertContains( $variation_id, array_map( 'intval', $fresh_parent->get_children() ) );

        wp_delete_post( $variation_id, true );
        wp_delete_post( $parent_id, true );
    }
}
