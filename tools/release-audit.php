<?php
/** Standalone static release checks; does not bootstrap WordPress. */
$root = dirname( __DIR__ );
$errors = array();
$php_files = array();
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
    if ( 'php' === strtolower( $file->getExtension() ) ) {
        $php_files[] = $file->getPathname();
    }
}
foreach ( $php_files as $file ) {
    $out = array(); $code = 0;
    exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $out, $code );
    if ( 0 !== $code ) { $errors[] = 'PHP lint failed: ' . $file . "\n" . implode( "\n", $out ); }
}
$oauth = file_get_contents( $root . '/includes/class-alify-ai-oauth.php' );
$auth = file_get_contents( $root . '/includes/class-alify-ai-auth.php' );
if ( false === strpos( $auth, 'enabled_scope_names' ) ) {
    $errors[] = 'Enabled OAuth scope discovery helper is missing.';
}
if ( false === strpos( $oauth, "'scopes_supported' => self::advertised_scopes()" ) || false === strpos( $oauth, "'offline_access'" ) ) {
    $errors[] = 'OAuth discovery does not advertise negotiated scopes/offline_access.';
}
if ( false === strpos( $oauth, 'Known connector scopes that are currently disabled' ) || false === strpos( $oauth, 'effective_scopes_for_user' ) ) {
    $errors[] = 'OAuth scope negotiation hardening is missing.';
}
if ( false === strpos( $oauth, 'client_id_issued_at bigint(20) unsigned' ) ) {
    $errors[] = 'OAuth schema is missing client_id_issued_at.';
}
if ( false === strpos( $oauth, "'client_id_issued_at' => time()" ) ) {
    $errors[] = 'OAuth registration does not persist client_id_issued_at.';
}
if ( preg_match( '/catch\s*\(\s*Throwable\s+\$\w+\s*\)\s*\{\s*\}/', file_get_contents( $root . '/includes/class-alify-ai-elementor.php' ) ) ) {
    $errors[] = 'Elementor still contains a silent empty Throwable catch.';
}

$acf = file_get_contents( $root . '/includes/class-alify-ai-acf.php' );
if ( false === strpos( $acf, "'update_acf' === \$action" ) || false === strpos( $acf, 'alify_ai_acf_rollback_unknown_state' ) ) {
    $errors[] = 'ACF value rollback hardening is missing.';
}
$woo = file_get_contents( $root . '/includes/class-alify-ai-woocommerce.php' );
if ( false === strpos( $woo, 'run_compensation' ) || false === strpos( $woo, 'alify_ai_woocommerce_unknown_state' ) ) {
    $errors[] = 'WooCommerce compensation verification is missing.';
}
$transactions = file_get_contents( $root . '/includes/class-alify-ai-transactions.php' );
if ( false === strpos( $transactions, 'alify_ai_transaction_rollback_unknown_state' ) ) {
    $errors[] = 'Partial manual transaction rollback detection is missing.';
}
if ( ! file_exists( $root . '/staging/docker-compose.yml' ) || ! file_exists( $root . '/staging/runtime-smoke.php' ) ) {
    $errors[] = 'Staging runtime harness is incomplete.';
}


$rate = file_get_contents( $root . '/includes/class-alify-ai-rate-limiter.php' );
if ( false === strpos( $rate, 'ON DUPLICATE KEY UPDATE hits=hits+1' ) ) {
    $errors[] = 'Atomic SQL rate-limit increment is missing.';
}
$mcp = file_get_contents( $root . '/includes/class-alify-ai-mcp.php' );
if ( false === strpos( $mcp, 'is_bool( $id )' ) ) {
    $errors[] = 'Boolean JSON-RPC request-id rejection is missing.';
}
if ( false === strpos( $mcp, "private const PROTOCOL      = '2026-07-28'" ) || false === strpos( $mcp, "'server/discover' === \$method" ) ) {
    $errors[] = 'MCP 2026-07-28 server/discover support is missing.';
}
if ( false === strpos( $mcp, 'sanitize_discovery_tool' ) || false === strpos( $mcp, 'tool_available_for_runtime' ) ) {
    $errors[] = 'Scope-aware ChatGPT action discovery hardening is missing.';
}
if ( false === strpos( $mcp, "'resultType' => 'complete'" ) || false === strpos( $mcp, "'cacheScope' => 'private'" ) ) {
    $errors[] = 'Modern MCP list-result metadata is missing.';
}
$orders = file_get_contents( $root . '/includes/class-alify-ai-orders.php' );
if ( false === strpos( $orders, 'alify_ai_woocommerce_order_unknown_state' ) || false === strpos( $orders, 'run_compensation' ) ) {
    $errors[] = 'WooCommerce order compensation verification is missing.';
}

$media = file_get_contents( $root . '/includes/class-alify-ai-media.php' );
if ( false === strpos( $media, 'alify_ai_media_unknown_state' ) || false === strpos( $media, 'compensation_failure' ) ) {
    $errors[] = 'Media compensation unknown-state hardening is missing.';
}
$menus = file_get_contents( $root . '/includes/class-alify-ai-menus.php' );
if ( false === strpos( $menus, 'alify_ai_menu_unknown_state' ) || false === strpos( $menus, 'compensation_failure' ) ) {
    $errors[] = 'Menu compensation unknown-state hardening is missing.';
}


$gutenberg = file_get_contents( $root . '/includes/class-alify-ai-gutenberg.php' );
if ( false === strpos( $gutenberg, 'alify_ai_gutenberg_unknown_state' ) || false === strpos( $gutenberg, 'gutenberg_compensation_failed' ) ) {
    $errors[] = 'Gutenberg compensation verification is missing.';
}
$seo = file_get_contents( $root . '/includes/class-alify-ai-seo.php' );
if ( false === strpos( $seo, 'alify_ai_seo_unknown_state' ) || false === strpos( $seo, 'seo_rollback_compensation_failed' ) ) {
    $errors[] = 'SEO compensation verification is missing.';
}
$structure = file_get_contents( $root . '/includes/class-alify-ai-structure.php' ) . file_get_contents( $root . '/includes/class-alify-ai-rest.php' );
if ( false === strpos( $structure, 'alify_ai_structure_unknown_state' ) || false === strpos( $structure, 'structure_rollback_compensation_failed' ) ) {
    $errors[] = 'Structure rollback compensation verification is missing.';
}
if ( false === strpos( $media, 'media_rollback_log_failed_after_file_restore' ) ) {
    $errors[] = 'Media file rollback log-failure state handling is missing.';
}

$schema_audit_output = array();
$schema_audit_code = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/tools/mcp-schema-audit.php' ) . ' 2>&1', $schema_audit_output, $schema_audit_code );
if ( 0 !== $schema_audit_code ) {
    $errors[] = "MCP discovery schema audit failed:
" . implode( "
", $schema_audit_output );
}


$code_inspection = file_get_contents( $root . '/includes/class-alify-ai-code-inspection.php' );
if ( false === strpos( $auth, "'code_read'" ) || false === strpos( $mcp, "'search_source_code'" ) || false === strpos( $mcp, "'inspect_frontend_page'" ) ) {
    $errors[] = 'Code inspection permission/MCP tools are missing.';
}
if ( false === strpos( $code_inspection, 'realpath(' ) || false === strpos( $code_inspection, 'path_inside' ) || false === strpos( $code_inspection, 'wp_safe_remote_get' ) ) {
    $errors[] = 'Code inspection path/frontend sandboxing is incomplete.';
}
if ( false === strpos( $code_inspection, 'MAX_FILE_BYTES' ) || false === strpos( $code_inspection, 'BLOCKED_BASENAMES' ) || false === strpos( $code_inspection, 'redact_source_secrets' ) ) {
    $errors[] = 'Code inspection size/secret controls are incomplete.';
}


if ( false === strpos( $auth, "'code_write'" ) || false === strpos( $mcp, "'preview_source_file_update'" ) || false === strpos( $code_inspection, 'apply_approval' ) || false === strpos( $code_inspection, 'rollback_activity' ) ) {
    $errors[] = 'Approval-gated source-code write/rollback support is missing.';
}
if ( false === strpos( file_get_contents( $root . '/includes/class-alify-ai-rest.php' ), 'runtime_scope_report' ) ) {
    $errors[] = 'Capabilities endpoint does not distinguish global and effective credential scopes.';
}

$main = file_get_contents( $root . '/alify-ai-connector.php' );
if ( false === strpos( $mcp, "'list_acf_option_pages'" ) || false === strpos( $mcp, "'get_acf_option_values'" ) || false === strpos( $mcp, "'update_acf_option_values'" ) || false === strpos( $acf, 'update_option_values' ) || false === strpos( $acf, "'update_acf_options'" ) ) {
    $errors[] = 'ACF Options Page read/write/rollback support is incomplete.';
}

if ( false === strpos( $mcp, 'runtime_tool_summary' ) || false === strpos( $mcp, "'code_write_visible'" ) || false === strpos( $mcp, "'acf_options_write_visible'" ) ) {
    $errors[] = 'Runtime MCP visibility diagnostics are incomplete.';
}

if ( false === strpos( $main, "ALIFY_AI_VERSION', '0.32.0'" ) ) {
    $errors[] = 'Main plugin version is not 0.32.0.';
}
if ( $errors ) {
    fwrite( STDERR, "Release audit FAILED\n- " . implode( "\n- ", $errors ) . "\n" );
    exit( 1 );
}
echo 'Release audit passed: ' . count( $php_files ) . " PHP files linted.\n";
