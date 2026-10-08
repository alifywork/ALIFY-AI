<?php
/**
 * Plugin Name: ALIFY AI Connector
 * Description: Secure AI control layer for WordPress content, design, media and WooCommerce with scoped permissions, previews, approvals, transactions and rollback.
 * Version: 0.32.0
 * Author: ALIFY
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Text Domain: alify-ai-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ALIFY_AI_VERSION', '0.32.0' );
define( 'ALIFY_AI_FILE', __FILE__ );
define( 'ALIFY_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALIFY_AI_URL', plugin_dir_url( __FILE__ ) );

require_once ALIFY_AI_DIR . 'includes/class-alify-ai-access-tokens.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-rate-limiter.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-oauth.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-auth.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-diagnostics.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-audit.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-structure.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-acf.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-media.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-menus.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-gutenberg.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-elementor.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-woocommerce.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-orders.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-seo.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-code-inspection.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-transactions.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-approvals.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-rest.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-mcp.php';
require_once ALIFY_AI_DIR . 'includes/class-alify-ai-admin.php';

register_activation_hook( __FILE__, static function () {
    ALIFY_AI_Access_Tokens::activate();
    ALIFY_AI_Rate_Limiter::activate();
    ALIFY_AI_OAuth::activate();
    ALIFY_AI_Audit::activate();
    ALIFY_AI_Approvals::activate();
    ALIFY_AI_MCP::ensure_connection();
    if ( ! wp_next_scheduled( 'alify_ai_daily_cleanup' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'alify_ai_daily_cleanup' );
    }
} );

add_action( 'plugins_loaded', static function () {
    ALIFY_AI_Access_Tokens::init();
    ALIFY_AI_Rate_Limiter::init();
    ALIFY_AI_OAuth::init();
    ALIFY_AI_Auth::init();
    ALIFY_AI_Audit::init();
    ALIFY_AI_Approvals::init();
    ALIFY_AI_Structure::init();
    ALIFY_AI_SEO::init();
    ALIFY_AI_REST::init();
    ALIFY_AI_MCP::init();
    if ( ! wp_next_scheduled( 'alify_ai_daily_cleanup' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'alify_ai_daily_cleanup' );
    }

    if ( is_admin() ) {
        ALIFY_AI_Admin::init();
    }
} );

add_action( 'alify_ai_daily_cleanup', static function () {
    ALIFY_AI_Audit::cleanup_old( 90 );
    ALIFY_AI_Media::cleanup_backups( 90 );
    ALIFY_AI_OAuth::cleanup();
    ALIFY_AI_Rate_Limiter::cleanup();
} );

register_deactivation_hook( __FILE__, static function () {
    $timestamp = wp_next_scheduled( 'alify_ai_daily_cleanup' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'alify_ai_daily_cleanup' );
    }
} );
