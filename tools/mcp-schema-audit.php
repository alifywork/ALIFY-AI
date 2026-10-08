<?php
/** Standalone MCP discovery-schema audit; does not bootstrap WordPress. */
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'ALIFY_AI_VERSION' ) ) {
    define( 'ALIFY_AI_VERSION', '0.32.0' );
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $value ) {
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
    }
}
if ( ! class_exists( 'ALIFY_AI_Diagnostics' ) ) {
    final class ALIFY_AI_Diagnostics {
        public static function log( $message, $context = array() ): void {}
    }
}
require_once dirname( __DIR__ ) . '/includes/class-alify-ai-mcp.php';

$method = new ReflectionMethod( ALIFY_AI_MCP::class, 'sanitize_discovery_tool' );
$method->setAccessible( true );
$errors = array();
$count = 0;
foreach ( ALIFY_AI_MCP::tools() as $name => $tool ) {
    $count++;
    $safe = $method->invoke( null, $tool );
    if ( ! is_array( $safe ) || ! isset( $safe['inputSchema'] ) ) {
        $errors[] = $name . ': sanitizer did not return a tool schema.';
        continue;
    }
    $json = json_encode( $safe['inputSchema'], JSON_UNESCAPED_SLASHES );
    if ( false === $json ) {
        $errors[] = $name . ': schema could not be JSON encoded.';
        continue;
    }
    foreach ( array( '"oneOf"', '"anyOf"', '"allOf"', '"type":"null"', '"properties":[]' ) as $forbidden ) {
        if ( false !== strpos( $json, $forbidden ) ) {
            $errors[] = $name . ': discovery schema contains incompatible shape ' . $forbidden . '.';
        }
    }
    $decoded = json_decode( $json );
    if ( ! is_object( $decoded ) || ! isset( $decoded->type ) || 'object' !== $decoded->type ) {
        $errors[] = $name . ': root inputSchema is not a JSON object schema.';
    }
    if ( ! isset( $decoded->properties ) || ! is_object( $decoded->properties ) ) {
        $errors[] = $name . ': properties is not encoded as a JSON object.';
    }
}
if ( $errors ) {
    fwrite( STDERR, "MCP schema audit FAILED\n- " . implode( "\n- ", $errors ) . "\n" );
    exit( 1 );
}
echo 'MCP schema audit passed: ' . $count . " tool schemas normalized.\n";
