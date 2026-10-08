<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$failures = array();
$check = static function ( $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) $failures[] = $message;
};

$check( defined( 'ALIFY_AI_VERSION' ), 'Plugin version constant is missing.' );
$check( class_exists( 'ALIFY_AI_REST' ), 'REST controller did not load.' );
$check( class_exists( 'ALIFY_AI_MCP' ), 'MCP controller did not load.' );
$check( class_exists( 'ALIFY_AI_Audit' ), 'Audit subsystem did not load.' );

$health = ALIFY_AI_REST::health( new WP_REST_Request( 'GET', '/alify-ai/v1/health' ) );
$data = $health instanceof WP_REST_Response ? $health->get_data() : array();
$check( ( $data['ok'] ?? false ) === true, 'Health endpoint is not OK.' );
$check( (int) ( $data['phase'] ?? 0 ) === 32, 'Health endpoint phase is not 32.' );

$post_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'ALIFY Runtime Smoke' ), true );
$check( ! is_wp_error( $post_id ) && $post_id > 0, 'Could not create smoke-test page.' );
if ( ! is_wp_error( $post_id ) && $post_id > 0 ) wp_delete_post( $post_id, true );

if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' ) ) {
    $product = new WC_Product_Simple();
    $product->set_name( 'ALIFY Smoke Product' );
    $product->set_regular_price( '12.00' );
    $product_id = $product->save();
    $check( $product_id > 0 && wc_get_product( $product_id ) instanceof WC_Product, 'WooCommerce product create/reload failed.' );
    if ( $product_id ) wp_delete_post( $product_id, true );
}

if ( $failures ) {
    foreach ( $failures as $failure ) fwrite( STDERR, "FAIL: {$failure}\n" );
    exit( 1 );
}
echo "ALIFY runtime smoke passed for version " . ALIFY_AI_VERSION . "\n";
