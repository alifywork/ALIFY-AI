<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ALIFY_AI_Admin {
    private const FLASH_TRANSIENT = 'alify_ai_new_key_';
    private const NOTICE_TRANSIENT = 'alify_ai_admin_notice_';
    private const TOKEN_FLASH_TRANSIENT = 'alify_ai_new_bearer_';

    private const PAGES = array(
        'alify-ai-connector',
        'alify-ai-connection',
        'alify-ai-permissions',
        'alify-ai-integrations',
        'alify-ai-activity',
        'alify-ai-settings',
    );

    public static function init(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

        add_action( 'admin_post_alify_ai_generate_key', array( __CLASS__, 'generate_key' ) );
        add_action( 'admin_post_alify_ai_revoke_key', array( __CLASS__, 'revoke_key' ) );
        add_action( 'admin_post_alify_ai_create_access_token', array( __CLASS__, 'create_access_token' ) );
        add_action( 'admin_post_alify_ai_revoke_access_token', array( __CLASS__, 'revoke_access_token' ) );
        add_action( 'admin_post_alify_ai_revoke_oauth_client', array( __CLASS__, 'revoke_oauth_client' ) );
        add_action( 'admin_post_alify_ai_save_scopes', array( __CLASS__, 'save_scopes' ) );
        add_action( 'admin_post_alify_ai_save_design_policy', array( __CLASS__, 'save_design_policy' ) );
        add_action( 'admin_post_alify_ai_save_commerce_policy', array( __CLASS__, 'save_commerce_policy' ) );
        add_action( 'admin_post_alify_ai_save_transaction_policy', array( __CLASS__, 'save_transaction_policy' ) );
        add_action( 'admin_post_alify_ai_save_diagnostics', array( __CLASS__, 'save_diagnostics' ) );
        add_action( 'admin_post_alify_ai_save_uninstall_policy', array( __CLASS__, 'save_uninstall_policy' ) );
        add_action( 'admin_post_alify_ai_regenerate_mcp', array( __CLASS__, 'regenerate_mcp' ) );
        add_action( 'admin_post_alify_ai_revoke_mcp', array( __CLASS__, 'revoke_mcp' ) );
        add_action( 'admin_post_alify_ai_apply_approval', array( __CLASS__, 'apply_approval' ) );
        add_action( 'admin_post_alify_ai_cancel_approval', array( __CLASS__, 'cancel_approval' ) );
    }

    public static function menu(): void {
        add_menu_page(
            'ALIFY AI',
            'ALIFY AI',
            'manage_options',
            'alify-ai-connector',
            array( __CLASS__, 'render_overview' ),
            'dashicons-rest-api',
            58
        );

        add_submenu_page( 'alify-ai-connector', 'Overview', 'Overview', 'manage_options', 'alify-ai-connector', array( __CLASS__, 'render_overview' ) );
        add_submenu_page( 'alify-ai-connector', 'ChatGPT Connection', 'Connection', 'manage_options', 'alify-ai-connection', array( __CLASS__, 'render_connection' ) );
        add_submenu_page( 'alify-ai-connector', 'Permissions', 'Permissions', 'manage_options', 'alify-ai-permissions', array( __CLASS__, 'render_permissions' ) );
        add_submenu_page( 'alify-ai-connector', 'Integrations', 'Integrations', 'manage_options', 'alify-ai-integrations', array( __CLASS__, 'render_integrations' ) );
        add_submenu_page( 'alify-ai-connector', 'Activity & Approvals', 'Activity', 'manage_options', 'alify-ai-activity', array( __CLASS__, 'render_activity' ) );
        add_submenu_page( 'alify-ai-connector', 'Advanced Settings', 'Settings', 'manage_options', 'alify-ai-settings', array( __CLASS__, 'render_settings' ) );
    }

    public static function assets( string $hook ): void {
        if ( false === strpos( $hook, 'alify-ai' ) ) {
            return;
        }
        wp_enqueue_style( 'alify-ai-admin', ALIFY_AI_URL . 'assets/css/admin.css', array(), ALIFY_AI_VERSION );
        wp_enqueue_script( 'alify-ai-admin', ALIFY_AI_URL . 'assets/js/admin.js', array(), ALIFY_AI_VERSION, true );
    }

    public static function generate_key(): void {
        self::guard();
        $key = ALIFY_AI_Auth::generate_key();
        set_transient( self::FLASH_TRANSIENT . get_current_user_id(), $key, 120 );
        self::flash( 'A new REST API key was generated. Copy it now; it will only be shown once.', 'success' );
        self::redirect( 'alify-ai-settings' );
    }

    public static function revoke_key(): void {
        self::guard();
        ALIFY_AI_Auth::revoke_key();
        self::flash( 'Legacy REST API access has been revoked.', 'success' );
        self::redirect( 'alify-ai-settings' );
    }

    public static function create_access_token(): void {
        self::guard();
        $user_id = isset( $_POST['token_user_id'] ) ? absint( $_POST['token_user_id'] ) : 0;
        $name = isset( $_POST['token_name'] ) ? sanitize_text_field( wp_unslash( $_POST['token_name'] ) ) : '';
        $expires_days = isset( $_POST['token_expires_days'] ) ? absint( $_POST['token_expires_days'] ) : 30;
        $scopes = array();
        foreach ( ALIFY_AI_Auth::scope_names() as $scope ) {
            $scopes[ $scope ] = isset( $_POST[ 'token_scope_' . $scope ] ) ? 1 : 0;
        }
        $created = ALIFY_AI_Access_Tokens::create( $user_id, $name, $scopes, $expires_days );
        if ( is_wp_error( $created ) ) {
            self::flash( $created->get_error_message(), 'error' );
        } else {
            set_transient( self::TOKEN_FLASH_TRANSIENT . get_current_user_id(), $created, 180 );
            self::flash( 'Per-user bearer token created. Copy it now; the secret will not be shown again.', 'success' );
        }
        self::redirect( 'alify-ai-settings' );
    }

    public static function revoke_access_token(): void {
        self::guard();
        $id = isset( $_POST['token_id'] ) ? absint( $_POST['token_id'] ) : 0;
        if ( ! $id || ! ALIFY_AI_Access_Tokens::revoke( $id ) ) {
            self::flash( 'Could not revoke that access token.', 'error' );
        } else {
            self::flash( 'Access token revoked.', 'success' );
        }
        self::redirect( 'alify-ai-settings' );
    }

    public static function revoke_oauth_client(): void {
        self::guard();
        $id = isset( $_POST['oauth_client_id'] ) ? absint( $_POST['oauth_client_id'] ) : 0;
        if ( ! $id || ! ALIFY_AI_OAuth::revoke_client( $id ) ) {
            self::flash( 'Could not revoke that OAuth client.', 'error' );
        } else {
            self::flash( 'OAuth client and its active OAuth credentials were revoked.', 'success' );
        }
        self::redirect( 'alify-ai-settings' );
    }

    public static function save_scopes(): void {
        self::guard();
        $scopes = array();
        foreach ( ALIFY_AI_Auth::scope_names() as $scope ) {
            $scopes[ $scope ] = isset( $_POST[ $scope ] ) ? 1 : 0;
        }
        ALIFY_AI_Auth::update_scopes( $scopes );
        self::flash( 'Permissions updated.', 'success' );
        self::redirect( 'alify-ai-permissions' );
    }

    public static function save_design_policy(): void {
        self::guard();
        $policy = isset( $_POST['design_policy'] ) ? sanitize_key( wp_unslash( $_POST['design_policy'] ) ) : 'approval_required';
        ALIFY_AI_Approvals::update_policy( $policy );
        self::flash( 'Design policy updated.', 'success' );
        self::redirect( 'alify-ai-settings' );
    }

    public static function save_commerce_policy(): void {
        self::guard();
        $policy = isset( $_POST['commerce_policy'] ) ? sanitize_key( wp_unslash( $_POST['commerce_policy'] ) ) : 'approval_required';
        ALIFY_AI_Approvals::update_commerce_policy( $policy );
        self::flash( 'Commerce policy updated.', 'success' );
        self::redirect( 'alify-ai-settings' );
    }

    public static function save_transaction_policy(): void {
        self::guard();
        $policy = isset( $_POST['transaction_policy'] ) ? sanitize_key( wp_unslash( $_POST['transaction_policy'] ) ) : 'approval_required';
        ALIFY_AI_Approvals::update_transaction_policy( $policy );
        self::flash( 'Transaction policy updated.', 'success' );
        self::redirect( 'alify-ai-settings' );
    }

    public static function save_diagnostics(): void {
        self::guard();
        ALIFY_AI_Diagnostics::set_enabled( isset( $_POST['diagnostics_enabled'] ) );
        self::flash( 'Diagnostics setting updated.', 'success' );
        self::redirect( 'alify-ai-settings' );
    }

    public static function save_uninstall_policy(): void {
        self::guard();
        update_option( 'alify_ai_remove_data_on_uninstall', isset( $_POST['remove_data_on_uninstall'] ) ? 1 : 0, false );
        self::flash( 'Uninstall cleanup setting updated.', 'success' );
        self::redirect( 'alify-ai-settings' );
    }

    public static function regenerate_mcp(): void {
        self::guard();
        ALIFY_AI_MCP::regenerate_connection();
        self::flash( 'A fresh private ChatGPT endpoint has been generated. The previous endpoint no longer works.', 'success' );
        self::redirect( 'alify-ai-connection' );
    }

    public static function revoke_mcp(): void {
        self::guard();
        ALIFY_AI_MCP::revoke_connection();
        self::flash( 'ChatGPT connection disabled. Existing private endpoint has been revoked.', 'success' );
        self::redirect( 'alify-ai-connection' );
    }

    public static function apply_approval(): void {
        self::guard();
        $id = isset( $_POST['approval_id'] ) ? absint( $_POST['approval_id'] ) : 0;
        $result = $id ? ALIFY_AI_Approvals::apply( $id ) : new WP_Error( 'alify_ai_invalid_approval', 'Invalid approval ID.' );
        if ( is_wp_error( $result ) ) {
            self::flash( $result->get_error_message(), 'error' );
        } else {
            self::flash( 'Proposal #' . $id . ' applied successfully.', 'success' );
        }
        self::redirect( 'alify-ai-activity' );
    }

    public static function cancel_approval(): void {
        self::guard();
        $id = isset( $_POST['approval_id'] ) ? absint( $_POST['approval_id'] ) : 0;
        $result = $id ? ALIFY_AI_Approvals::cancel( $id ) : new WP_Error( 'alify_ai_invalid_approval', 'Invalid approval ID.' );
        if ( is_wp_error( $result ) ) {
            self::flash( $result->get_error_message(), 'error' );
        } else {
            self::flash( 'Proposal #' . $id . ' cancelled.', 'success' );
        }
        self::redirect( 'alify-ai-activity' );
    }

    private static function guard(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to manage this connector.', 'alify-ai-connector' ) );
        }
        check_admin_referer( 'alify_ai_settings' );
    }

    private static function redirect( string $page ): void {
        if ( ! in_array( $page, self::PAGES, true ) ) {
            $page = 'alify-ai-connector';
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
        exit;
    }

    private static function flash( string $message, string $type = 'success' ): void {
        set_transient(
            self::NOTICE_TRANSIENT . get_current_user_id(),
            array( 'message' => sanitize_text_field( $message ), 'type' => in_array( $type, array( 'success', 'error', 'info' ), true ) ? $type : 'info' ),
            120
        );
    }

    private static function render_notice(): void {
        $notice = get_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
        if ( ! $notice || ! is_array( $notice ) ) {
            return;
        }
        delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
        $type = isset( $notice['type'] ) ? sanitize_key( $notice['type'] ) : 'info';
        $icon = 'error' === $type ? 'dismiss' : ( 'success' === $type ? 'yes-alt' : 'info-outline' );
        echo '<div class="alify-notice ' . esc_attr( $type ) . '"><span class="dashicons dashicons-' . esc_attr( $icon ) . '"></span><span>' . esc_html( (string) $notice['message'] ) . '</span></div>';
    }

    private static function shell_start( string $title, string $subtitle, string $active ): void {
        ?>
        <div class="wrap alify-admin">
            <div class="alify-topbar">
                <div class="alify-brand">
                    <div class="alify-brand-mark">A</div>
                    <div>
                        <h1><?php echo esc_html( $title ); ?></h1>
                        <p><?php echo esc_html( $subtitle ); ?></p>
                    </div>
                </div>
                <span class="alify-version">ALIFY AI v<?php echo esc_html( ALIFY_AI_VERSION ); ?></span>
            </div>
            <?php self::tabs( $active ); ?>
            <?php self::render_notice(); ?>
        <?php
    }

    private static function shell_end(): void {
        echo '</div>';
    }

    private static function tabs( string $active ): void {
        $tabs = array(
            'alify-ai-connector'    => array( 'Overview', 'dashboard' ),
            'alify-ai-connection'   => array( 'Connection', 'admin-links' ),
            'alify-ai-permissions'  => array( 'Permissions', 'privacy' ),
            'alify-ai-integrations' => array( 'Integrations', 'screenoptions' ),
            'alify-ai-activity'     => array( 'Activity', 'backup' ),
            'alify-ai-settings'     => array( 'Settings', 'admin-generic' ),
        );
        echo '<nav class="alify-tabs" aria-label="ALIFY AI navigation">';
        foreach ( $tabs as $slug => $data ) {
            $class = $slug === $active ? 'is-active' : '';
            echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '"><span class="dashicons dashicons-' . esc_attr( $data[1] ) . '"></span>' . esc_html( $data[0] ) . '</a>';
        }
        echo '</nav>';
    }

    private static function status_badge( bool $ok, string $yes = 'Active', string $no = 'Not detected' ): string {
        return '<span class="alify-status ' . ( $ok ? 'success' : 'neutral' ) . '">' . esc_html( $ok ? $yes : $no ) . '</span>';
    }

    private static function post_form_open( string $action ): void {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'alify_ai_settings' );
        echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
    }

    public static function render_overview(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $scopes          = ALIFY_AI_Auth::get_scopes();
        $enabled_scopes  = count( array_filter( $scopes ) );
        $pending         = ALIFY_AI_Approvals::recent( 100, 'pending' );
        $recent_activity = ALIFY_AI_Audit::recent( 6 );
        $elementor       = ALIFY_AI_Elementor::status();
        $woo             = ALIFY_AI_WooCommerce::status();
        $connected       = ALIFY_AI_MCP::has_connection();
        $https           = is_ssl();

        self::shell_start( 'Overview', 'Your WordPress AI control center at a glance.', 'alify-ai-connector' );
        ?>
        <section class="alify-hero">
            <h2><?php echo $connected ? 'ChatGPT connection is ready.' : 'Connect WordPress to ChatGPT.'; ?></h2>
            <p><?php echo $connected ? 'Your embedded MCP endpoint is active. Review permissions before letting ChatGPT make changes.' : 'Generate one private endpoint, paste it into ChatGPT, and control your site without a separate server.'; ?></p>
            <div class="alify-hero-actions">
                <a class="alify-btn alify-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=alify-ai-connection' ) ); ?>"><span class="dashicons dashicons-admin-links"></span><?php echo $connected ? 'View Connection' : 'Connect ChatGPT'; ?></a>
                <a class="alify-btn alify-btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=alify-ai-permissions' ) ); ?>"><span class="dashicons dashicons-privacy"></span>Review Permissions</a>
            </div>
        </section>

        <div class="alify-grid alify-grid-4">
            <div class="alify-card alify-stat"><div class="alify-stat-top"><div class="alify-stat-icon <?php echo $connected ? 'success' : 'warning'; ?>"><span class="dashicons dashicons-admin-links"></span></div><?php echo $connected ? self::status_badge( true, 'Ready', '' ) : '<span class="alify-status warning">Setup needed</span>'; ?></div><strong class="value"><?php echo $connected ? 'Ready' : 'Off'; ?></strong><span class="label">ChatGPT MCP connection</span></div>
            <div class="alify-card alify-stat"><div class="alify-stat-top"><div class="alify-stat-icon primary"><span class="dashicons dashicons-privacy"></span></div><span class="alify-status neutral"><?php echo esc_html( (string) count( ALIFY_AI_Auth::scope_names() ) ); ?> total</span></div><strong class="value"><?php echo esc_html( (string) $enabled_scopes ); ?></strong><span class="label">Permissions enabled</span></div>
            <div class="alify-card alify-stat"><div class="alify-stat-top"><div class="alify-stat-icon <?php echo count( $pending ) ? 'warning' : 'success'; ?>"><span class="dashicons dashicons-yes-alt"></span></div><span class="alify-status <?php echo count( $pending ) ? 'warning' : 'success'; ?>"><?php echo count( $pending ) ? 'Needs review' : 'Clear'; ?></span></div><strong class="value"><?php echo esc_html( (string) count( $pending ) ); ?></strong><span class="label">Pending approvals</span></div>
            <div class="alify-card alify-stat"><div class="alify-stat-top"><div class="alify-stat-icon <?php echo $https ? 'success' : 'warning'; ?>"><span class="dashicons dashicons-shield-alt"></span></div><span class="alify-status <?php echo $https ? 'success' : 'warning'; ?>"><?php echo $https ? 'Secure' : 'Check HTTPS'; ?></span></div><strong class="value"><?php echo $https ? 'HTTPS' : 'HTTP'; ?></strong><span class="label">Connection security</span></div>
        </div>

        <div class="alify-grid alify-grid-2" style="margin-top:18px;">
            <div class="alify-card">
                <div class="alify-card-header"><h2>Quick setup</h2><a href="<?php echo esc_url( admin_url( 'admin.php?page=alify-ai-connection' ) ); ?>">Open connection →</a></div>
                <div class="alify-card-body">
                    <div class="alify-setup">
                        <div class="alify-step"><div class="alify-step-num">1</div><div><h4>Connect ChatGPT</h4><p>Copy the private MCP URL from the Connection page and add it to your ChatGPT app/custom connection.</p></div></div>
                        <div class="alify-step"><div class="alify-step-num">2</div><div><h4>Choose permissions</h4><p>Enable only the areas ChatGPT should be able to read or modify.</p></div></div>
                        <div class="alify-step"><div class="alify-step-num">3</div><div><h4>Start with a read-only test</h4><p>Ask: “Show me my WordPress site info and capabilities.” Then enable advanced write permissions as needed.</p></div></div>
                    </div>
                </div>
            </div>

            <div class="alify-card">
                <div class="alify-card-header"><h2>Integration health</h2><a href="<?php echo esc_url( admin_url( 'admin.php?page=alify-ai-integrations' ) ); ?>">View all →</a></div>
                <div class="alify-card-body">
                    <table class="alify-table">
                        <tbody>
                            <tr><td><strong>Gutenberg</strong></td><td><?php echo self::status_badge( function_exists( 'parse_blocks' ), 'Available', 'Unavailable' ); ?></td></tr>
                            <tr><td><strong>Elementor</strong></td><td><?php echo self::status_badge( ! empty( $elementor['active'] ) ); ?></td></tr>
                            <tr><td><strong>Advanced Custom Fields</strong></td><td><?php echo self::status_badge( function_exists( 'acf_get_field_groups' ) ); ?></td></tr>
                            <tr><td><strong>WooCommerce</strong></td><td><?php echo self::status_badge( ! empty( $woo['active'] ) ); ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="alify-section-title"><div><h2>Recent activity</h2><p>Latest changes made through ALIFY AI.</p></div><a href="<?php echo esc_url( admin_url( 'admin.php?page=alify-ai-activity' ) ); ?>">View activity →</a></div>
        <div class="alify-card alify-table-wrap">
            <?php self::activity_table( $recent_activity, false ); ?>
        </div>
        <?php
        self::shell_end();
    }

    public static function render_connection(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $connected = ALIFY_AI_MCP::has_connection();
        $mcp_url   = ALIFY_AI_MCP::get_connection_url();
        self::shell_start( 'ChatGPT Connection', 'One private endpoint. No separate Node server or bridge.', 'alify-ai-connection' );
        ?>
        <div class="alify-grid alify-grid-2">
            <div class="alify-card alify-span-2">
                <div class="alify-card-header">
                    <div><h2>Private MCP endpoint</h2><p class="alify-help" style="margin:4px 0 0;">This is the URL you paste into ChatGPT.</p></div>
                    <?php echo $connected ? '<span class="alify-status success">Ready</span>' : '<span class="alify-status warning">Disabled</span>'; ?>
                </div>
                <div class="alify-card-body">
                    <?php if ( $connected ) : ?>
                        <div class="alify-endpoint">
                            <code id="alify-mcp-url"><?php echo esc_html( $mcp_url ); ?></code>
                            <button type="button" class="alify-btn alify-btn-wp" data-alify-copy="#alify-mcp-url"><span class="dashicons dashicons-admin-page"></span>Copy endpoint</button>
                        </div>
                        <p class="alify-help"><strong>Keep this URL private.</strong> The token inside the URL acts as the connection credential. Regenerate it immediately if it is exposed.</p>
                        <div class="alify-actions" style="margin-top:16px;">
                            <?php self::post_form_open( 'alify_ai_regenerate_mcp' ); ?><button class="alify-btn alify-btn-outline" type="submit" data-confirm="Regenerate the endpoint? The current ChatGPT connection will stop working.">Regenerate endpoint</button></form>
                            <?php self::post_form_open( 'alify_ai_revoke_mcp' ); ?><button class="alify-btn alify-btn-danger" type="submit" data-confirm="Disable ChatGPT connection and revoke the current endpoint?">Disable connection</button></form>
                        </div>
                    <?php else : ?>
                        <div class="alify-empty" style="padding:16px 0;text-align:left;">
                            <h3 style="margin:0 0 6px;color:#101828;">ChatGPT connection is disabled</h3>
                            <p style="margin:0 0 14px;">Generate a private endpoint to connect this WordPress site directly to ChatGPT.</p>
                            <?php self::post_form_open( 'alify_ai_regenerate_mcp' ); ?><button class="alify-btn alify-btn-wp" type="submit">Enable & generate endpoint</button></form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="alify-card">
                <div class="alify-card-header"><h2>Connect in 3 steps</h2></div>
                <div class="alify-card-body">
                    <div class="alify-setup">
                        <div class="alify-step"><div class="alify-step-num">1</div><div><h4>Copy endpoint</h4><p>Use the button above. Do not share the endpoint publicly.</p></div></div>
                        <div class="alify-step"><div class="alify-step-num">2</div><div><h4>Add a ChatGPT connection</h4><p>Create an MCP app/custom connection in ChatGPT and paste the endpoint as the server URL.</p></div></div>
                        <div class="alify-step"><div class="alify-step-num">3</div><div><h4>Test the connection</h4><p>Ask ChatGPT: “Show me my WordPress site info and capabilities.”</p></div></div>
                    </div>
                </div>
            </div>

            <div class="alify-card">
                <div class="alify-card-header"><h2>Connection checks</h2></div>
                <div class="alify-card-body">
                    <table class="alify-table">
                        <tbody>
                            <tr><td>Embedded MCP server</td><td><?php echo self::status_badge( $connected, 'Ready', 'Disabled' ); ?></td></tr>
                            <tr><td>HTTPS</td><td><?php echo self::status_badge( is_ssl(), 'Secure', 'Required' ); ?></td></tr>
                            <tr><td>WordPress REST API</td><td><?php echo self::status_badge( true, 'Available', '' ); ?></td></tr>
                            <tr><td>Permissions</td><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=alify-ai-permissions' ) ); ?>">Review access →</a></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="alify-card" style="margin-top:18px;">
            <div class="alify-card-header"><h2>Security notes</h2></div>
            <div class="alify-card-body">
                <ul class="alify-security-list">
                    <li><span class="dashicons dashicons-yes-alt"></span><span>ChatGPT can only use permissions explicitly enabled on the Permissions page.</span></li>
                    <li><span class="dashicons dashicons-yes-alt"></span><span>Design, commerce and transaction changes can be kept behind preview/approval policies.</span></li>
                    <li><span class="dashicons dashicons-yes-alt"></span><span>Arbitrary PHP execution, direct SQL, passwords and WooCommerce payments/refunds remain blocked. Permanent page/post deletion requires explicit double confirmation.</span></li>
                    <li><span class="dashicons dashicons-yes-alt"></span><span>Regenerating the endpoint immediately invalidates the old URL.</span></li>
                </ul>
            </div>
        </div>
        <?php
        self::shell_end();
    }

    public static function render_permissions(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $scopes = ALIFY_AI_Auth::get_scopes();
        $groups = array(
            'Core access' => array(
                'read' => array( 'Read website', 'Site info, content discovery, activity, media/menu reads and integration status.', 'low' ),
                'content' => array( 'Edit content', 'Create and update pages, posts and public custom post type entries.', 'medium' ),
            ),
            'Site management' => array(
                'structure' => array( 'Manage structure', 'Create/update ALIFY-managed custom post types, taxonomies and taxonomy terms.', 'high' ),
                'acf' => array( 'Manage ACF', 'Create ACF field groups/fields and update ACF values.', 'medium' ),
                'media' => array( 'Manage media', 'Upload, edit, replace, regenerate, trash/restore and explicitly delete Media Library files.', 'medium' ),
                'menus' => array( 'Manage menus', 'Create/update/delete menus and items, manage hierarchy/order, auto-add and theme locations with rollback.', 'medium' ),
                'code_read' => array( 'Inspect source code', 'Read/search the active theme and active plugin source files plus inspect this site\'s rendered frontend. Secrets and arbitrary filesystem paths remain blocked.', 'medium' ),
                'code_write' => array( 'Edit source code', 'Approval-gated edits to existing active-theme/parent-theme/active-plugin text source files. Includes stale-write protection, PHP syntax validation, audit history and rollback.', 'high' ),
            ),
            'Advanced actions' => array(
                'design' => array( 'Design editing', 'Preview and apply approved Gutenberg or Elementor document changes.', 'high' ),
                'seo' => array( 'SEO management', 'Read/audit SEO and apply approved title, description, canonical, robots, social and schema metadata through Generic, Yoast or Rank Math adapters.', 'high' ),
                'commerce' => array( 'WooCommerce catalog', 'Manage approved products, attributes, variations, shipping, tax classes, stock and downloads.', 'high' ),
                'orders' => array( 'WooCommerce orders', 'Read customer/order data and manage approval-gated order fields, line items, statuses and notes. Payments/refunds remain blocked.', 'high' ),
                'transactions' => array( 'Multi-action transactions', 'Coordinate multiple approved updates with stale checks and compensation rollback.', 'high' ),
            ),
        );
        self::shell_start( 'Permissions', 'Choose exactly what ChatGPT is allowed to access on this site.', 'alify-ai-permissions' );
        ?>
        <div class="alify-notice info"><span class="dashicons dashicons-shield"></span><span><strong>Recommended:</strong> start with Read + Content. Enable advanced permissions only when you need them.</span></div>
        <div class="alify-notice info"><span class="dashicons dashicons-admin-links"></span><span><strong>OAuth scope negotiation:</strong> ChatGPT is only told about permissions currently enabled here. During an explicit OAuth authorization/re-authorization, ALIFY synchronizes a stale/narrow client request to the full set enabled here that the signed-in WordPress user can actually use, and shows that exact grant on the consent screen before issuing the token. Refresh tokens can never silently expand permissions. <strong>offline_access</strong> is advertised separately for refresh-token continuity.</span></div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'alify_ai_settings' ); ?>
            <input type="hidden" name="action" value="alify_ai_save_scopes">
            <?php foreach ( $groups as $group_name => $permissions ) : ?>
                <div class="alify-card alify-permission-group">
                    <div class="alify-card-header"><h2><?php echo esc_html( $group_name ); ?></h2></div>
                    <div class="alify-card-body">
                        <div class="alify-permission-list">
                            <?php foreach ( $permissions as $key => $permission ) : ?>
                                <label class="alify-permission">
                                    <span class="alify-switch"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( ! empty( $scopes[ $key ] ) ); ?>><span class="alify-slider"></span></span>
                                    <span><h4><?php echo esc_html( $permission[0] ); ?></h4><p><?php echo esc_html( $permission[1] ); ?></p></span>
                                    <span class="alify-risk <?php echo esc_attr( $permission[2] ); ?>"><?php echo esc_html( $permission[2] ); ?> risk</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <div style="position:sticky;bottom:12px;z-index:4;display:flex;justify-content:flex-end;pointer-events:none;"><button type="submit" class="alify-btn alify-btn-wp" style="pointer-events:auto;box-shadow:0 8px 24px rgba(79,70,229,.25);">Save permissions</button></div>
        </form>
        <?php
        self::shell_end();
    }

    public static function render_integrations(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $scopes    = ALIFY_AI_Auth::get_scopes();
        $elementor = ALIFY_AI_Elementor::status();
        $woo       = ALIFY_AI_WooCommerce::status();
        $integrations = array(
            array( 'Gutenberg', 'Native WordPress block editing with preview, approval and rollback support.', function_exists( 'parse_blocks' ), 'design', 'editor-table', 'Built in' ),
            array( 'Elementor', 'Validated Elementor documents, responsive controls, local templates/global widgets, and active Kit global styles.', ! empty( $elementor['active'] ), 'design', 'layout', 'Plugin integration' ),
            array( 'Advanced Custom Fields', 'Read field groups and manage approved custom field structures and values.', function_exists( 'acf_get_field_groups' ), 'acf', 'forms', 'Plugin integration' ),
            array( 'SEO', 'Generic SEO output plus guarded Yoast SEO and Rank Math metadata, social tags, schema types and site audits.', true, 'seo', 'chart-area', 'SEO' ),
            array( 'WooCommerce Catalog', 'Product/catalog reads plus approval-gated price, stock, variation and product changes.', ! empty( $woo['active'] ), 'commerce', 'cart', 'Catalog' ),
            array( 'WooCommerce Orders', 'Scoped order reads plus approval-gated statuses, addresses, line items and notes. Payments/refunds stay disabled.', ALIFY_AI_Orders::available(), 'orders', 'clipboard', 'Sensitive data' ),
            array( 'Media Library', 'Read, upload, replace, process, regenerate, trash/restore and rollback WordPress media.', true, 'media', 'format-image', 'Built in' ),
            array( 'Navigation Menus', 'Full classic menu lifecycle: items, hierarchy, locations, bulk reorder, safe delete and rollback.', true, 'menus', 'menu-alt3', 'Built in' ),
        );
        self::shell_start( 'Integrations', 'See which WordPress features ALIFY AI can work with.', 'alify-ai-integrations' );
        ?>
        <div class="alify-grid alify-grid-3">
            <?php foreach ( $integrations as $item ) : ?>
                <div class="alify-card alify-integration">
                    <div class="alify-integration-top"><div class="alify-integration-icon"><span class="dashicons dashicons-<?php echo esc_attr( $item[4] ); ?>"></span></div><?php echo self::status_badge( (bool) $item[2], 'Detected', 'Not detected' ); ?></div>
                    <h3><?php echo esc_html( $item[0] ); ?></h3>
                    <p><?php echo esc_html( $item[1] ); ?></p>
                    <div class="alify-integration-meta"><span><?php echo esc_html( $item[5] ); ?></span><span>Permission: <strong><?php echo ! empty( $scopes[ $item[3] ] ) ? 'On' : 'Off'; ?></strong></span></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="alify-card" style="margin-top:18px;">
            <div class="alify-card-header"><h2>Need to enable access?</h2></div>
            <div class="alify-card-body" style="display:flex;justify-content:space-between;gap:18px;align-items:center;flex-wrap:wrap;"><p style="margin:0;color:#667085;">Detection only tells you an integration exists. ChatGPT still needs the matching permission before it can modify anything.</p><a class="alify-btn alify-btn-wp" href="<?php echo esc_url( admin_url( 'admin.php?page=alify-ai-permissions' ) ); ?>">Manage permissions</a></div>
        </div>
        <?php
        self::shell_end();
    }

    public static function render_activity(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $pending  = ALIFY_AI_Approvals::recent( 50, 'pending' );
        $activity = ALIFY_AI_Audit::recent( 50 );
        self::shell_start( 'Activity & Approvals', 'Review pending AI proposals and recent connector changes.', 'alify-ai-activity' );
        ?>
        <div class="alify-card">
            <div class="alify-card-header"><div><h2>Pending approvals</h2><p class="alify-help" style="margin:4px 0 0;">Design, commerce and transaction previews waiting for a decision.</p></div><span class="alify-status <?php echo count( $pending ) ? 'warning' : 'success'; ?>"><?php echo esc_html( (string) count( $pending ) ); ?> pending</span></div>
            <div class="alify-table-wrap">
                <?php if ( ! $pending ) : ?>
                    <div class="alify-empty"><span class="dashicons dashicons-yes-alt"></span><div>No pending approvals.</div></div>
                <?php else : ?>
                    <table class="alify-table"><thead><tr><th>ID</th><th>Type</th><th>Target</th><th>Created</th><th>Expires</th><th>Actions</th></tr></thead><tbody>
                    <?php foreach ( $pending as $proposal ) : ?>
                        <tr>
                            <td><strong>#<?php echo esc_html( (string) $proposal['id'] ); ?></strong></td>
                            <td><code><?php echo esc_html( (string) $proposal['kind'] ); ?></code></td>
                            <td><?php echo ! empty( $proposal['object_id'] ) ? '#' . esc_html( (string) $proposal['object_id'] ) : 'Multiple'; ?></td>
                            <td><?php echo esc_html( self::format_time( (string) $proposal['created_at'] ) ); ?></td>
                            <td><?php echo esc_html( self::format_time( (string) $proposal['expires_at'] ) ); ?></td>
                            <td><div class="alify-actions">
                                <?php self::post_form_open( 'alify_ai_apply_approval' ); ?><input type="hidden" name="approval_id" value="<?php echo esc_attr( (string) $proposal['id'] ); ?>"><button class="button button-primary" type="submit" data-confirm="Apply proposal #<?php echo esc_attr( (string) $proposal['id'] ); ?> to WordPress?">Apply</button></form>
                                <?php self::post_form_open( 'alify_ai_cancel_approval' ); ?><input type="hidden" name="approval_id" value="<?php echo esc_attr( (string) $proposal['id'] ); ?>"><button class="button" type="submit">Cancel</button></form>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
            </div>
        </div>

        <div class="alify-section-title"><div><h2>Activity log</h2><p>Most recent changes performed through the connector.</p></div></div>
        <div class="alify-card alify-table-wrap"><?php self::activity_table( $activity, true ); ?></div>
        <?php
        self::shell_end();
    }

    private static function activity_table( array $activity, bool $show_id = true ): void {
        if ( ! $activity ) {
            echo '<div class="alify-empty"><span class="dashicons dashicons-backup"></span><div>No connector activity yet.</div></div>';
            return;
        }
        echo '<table class="alify-table"><thead><tr>';
        if ( $show_id ) {
            echo '<th>ID</th>';
        }
        echo '<th>Action</th><th>Object</th><th>Object ID</th><th>Actor</th><th>Time</th></tr></thead><tbody>';
        foreach ( $activity as $entry ) {
            echo '<tr>';
            if ( $show_id ) {
                echo '<td><strong>#' . esc_html( (string) $entry['id'] ) . '</strong></td>';
            }
            echo '<td><code>' . esc_html( (string) $entry['action'] ) . '</code></td>';
            echo '<td>' . esc_html( ucfirst( str_replace( '_', ' ', (string) $entry['object_type'] ) ) ) . '</td>';
            echo '<td>' . ( ! empty( $entry['object_id'] ) ? esc_html( (string) $entry['object_id'] ) : '—' ) . '</td>';
            $actor = (string) ( $entry['actor_auth_type'] ?? 'system' );
            if ( ! empty( $entry['actor_user_id'] ) ) {
                $actor_user = get_user_by( 'id', (int) $entry['actor_user_id'] );
                if ( $actor_user ) {
                    $actor .= ' · ' . $actor_user->user_login;
                }
            }
            echo '<td><code>' . esc_html( $actor ) . '</code></td>';
            echo '<td>' . esc_html( self::format_time( (string) $entry['created_at'] ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function format_time( string $mysql_utc ): string {
        $timestamp = strtotime( $mysql_utc . ' UTC' );
        if ( ! $timestamp ) {
            return $mysql_utc;
        }
        return wp_date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), $timestamp );
    }

    public static function render_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $new_key = get_transient( self::FLASH_TRANSIENT . get_current_user_id() );
        if ( $new_key ) {
            delete_transient( self::FLASH_TRANSIENT . get_current_user_id() );
        }
        $new_bearer = get_transient( self::TOKEN_FLASH_TRANSIENT . get_current_user_id() );
        if ( $new_bearer ) {
            delete_transient( self::TOKEN_FLASH_TRANSIENT . get_current_user_id() );
        }
        $access_tokens = ALIFY_AI_Access_Tokens::list_tokens( 100 );
        $oauth_clients = ALIFY_AI_OAuth::list_clients( 100 );
        self::shell_start( 'Advanced Settings', 'Approval policies, REST access and connector safety controls.', 'alify-ai-settings' );
        ?>
        <?php if ( $new_key ) : ?>
            <div class="alify-notice success"><span class="dashicons dashicons-admin-network"></span><span><strong>New API key — copy it now:</strong><br><code id="alify-new-key" style="user-select:all;word-break:break-all;"><?php echo esc_html( $new_key ); ?></code> <button type="button" class="button button-small" data-alify-copy="#alify-new-key">Copy</button></span></div>
        <?php endif; ?>
        <?php if ( is_array( $new_bearer ) && ! empty( $new_bearer['token'] ) ) : ?>
            <div class="alify-notice success"><span class="dashicons dashicons-lock"></span><span><strong>New per-user bearer token — copy it now:</strong><br><code id="alify-new-bearer" style="user-select:all;word-break:break-all;"><?php echo esc_html( (string) $new_bearer['token'] ); ?></code> <button type="button" class="button button-small" data-alify-copy="#alify-new-bearer">Copy</button><br><small>Expires: <?php echo esc_html( self::format_time( (string) $new_bearer['expires_at'] ) ); ?></small></span></div>
        <?php endif; ?>

        <div class="alify-card">
            <div class="alify-card-header"><h2>Approval policies</h2><span class="alify-status success">Safety layer</span></div>
            <div class="alify-card-body">
                <div class="alify-form-row">
                    <div><h3>Design changes</h3><div class="hint">Controls Gutenberg and Elementor proposals.</div></div>
                    <div><?php $policy = ALIFY_AI_Approvals::get_policy(); self::policy_form( 'alify_ai_save_design_policy', 'design_policy', $policy, 'Design' ); ?></div>
                </div>
                <div class="alify-form-row">
                    <div><h3>Commerce changes</h3><div class="hint">Controls WooCommerce product/catalog proposals.</div></div>
                    <div><?php $policy = ALIFY_AI_Approvals::get_commerce_policy(); self::policy_form( 'alify_ai_save_commerce_policy', 'commerce_policy', $policy, 'Commerce' ); ?></div>
                </div>
                <div class="alify-form-row">
                    <div><h3>Multi-action transactions</h3><div class="hint">Controls coordinated changes spanning multiple WordPress objects.</div></div>
                    <div><?php $policy = ALIFY_AI_Approvals::get_transaction_policy(); self::policy_form( 'alify_ai_save_transaction_policy', 'transaction_policy', $policy, 'Transaction' ); ?></div>
                </div>
            </div>
        </div>

        <div class="alify-card" style="margin-top:18px;">
            <div class="alify-card-header"><div><h2>Per-user access tokens</h2><p class="alify-help" style="margin:4px 0 0;">Preferred authentication for REST and the header-based MCP endpoint. Tokens inherit both global connector permissions and the assigned WordPress user's native capabilities.</p></div><span class="alify-status neutral"><?php echo esc_html( (string) count( array_filter( $access_tokens, static fn( $row ) => empty( $row['revoked_at'] ) ) ) ); ?> active</span></div>
            <div class="alify-card-body">
                <?php self::post_form_open( 'alify_ai_create_access_token' ); ?>
                <div class="alify-form-row"><div><h3>Token name</h3><div class="hint">Describe where this credential will be used.</div></div><div><input class="regular-text" type="text" name="token_name" required placeholder="ChatGPT — Production"></div></div>
                <div class="alify-form-row"><div><h3>WordPress user</h3><div class="hint">Native WordPress capabilities are checked on every scoped action.</div></div><div><select name="token_user_id" required><?php foreach ( get_users( array( 'orderby' => 'display_name' ) ) as $user ) : ?><option value="<?php echo esc_attr( (string) $user->ID ); ?>"><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></option><?php endforeach; ?></select></div></div>
                <div class="alify-form-row"><div><h3>Expires</h3><div class="hint">Short-lived credentials reduce exposure if copied accidentally.</div></div><div><select name="token_expires_days"><option value="7">7 days</option><option value="30" selected>30 days</option><option value="90">90 days</option><option value="180">180 days</option><option value="365">365 days</option></select></div></div>
                <div class="alify-form-row"><div><h3>Token permissions</h3><div class="hint">These are additionally restricted by global Permissions and the selected user's WordPress capabilities.</div></div><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;"><?php foreach ( ALIFY_AI_Auth::scope_names() as $scope ) : ?><label><input type="checkbox" name="token_scope_<?php echo esc_attr( $scope ); ?>" value="1" <?php checked( in_array( $scope, array( 'read', 'content' ), true ) ); ?>> <?php echo esc_html( ucfirst( $scope ) ); ?></label><?php endforeach; ?></div></div>
                <button class="alify-btn alify-btn-wp" type="submit">Create access token</button>
                </form>

                <?php if ( $access_tokens ) : ?>
                    <div class="alify-table-wrap" style="margin-top:18px;"><table class="alify-table"><thead><tr><th>Name</th><th>User</th><th>Prefix</th><th>Scopes</th><th>Expires</th><th>Last used</th><th>Status</th><th></th></tr></thead><tbody>
                    <?php foreach ( $access_tokens as $token_row ) : $u = get_user_by( 'id', (int) $token_row['user_id'] ); $enabled = array_keys( array_filter( $token_row['scopes'] ) ); ?>
                        <tr><td><strong><?php echo esc_html( (string) $token_row['name'] ); ?></strong></td><td><?php echo esc_html( $u ? $u->user_login : 'Deleted user' ); ?></td><td><code><?php echo esc_html( (string) $token_row['token_prefix'] ); ?>…</code></td><td><?php echo esc_html( implode( ', ', $enabled ) ); ?></td><td><?php echo esc_html( self::format_time( (string) $token_row['expires_at'] ) ); ?></td><td><?php echo ! empty( $token_row['last_used_at'] ) ? esc_html( self::format_time( (string) $token_row['last_used_at'] ) ) : 'Never'; ?></td><td><?php echo self::status_badge( empty( $token_row['revoked_at'] ) && strtotime( (string) $token_row['expires_at'] . ' UTC' ) > time(), 'Active', ! empty( $token_row['revoked_at'] ) ? 'Revoked' : 'Expired' ); ?></td><td><?php if ( empty( $token_row['revoked_at'] ) ) : ?><?php self::post_form_open( 'alify_ai_revoke_access_token' ); ?><input type="hidden" name="token_id" value="<?php echo esc_attr( (string) $token_row['id'] ); ?>"><button class="button" type="submit" data-confirm="Revoke this access token?">Revoke</button></form><?php endif; ?></td></tr>
                    <?php endforeach; ?></tbody></table></div>
                <?php endif; ?>
                <p class="alify-help" style="margin-bottom:0;">Bearer MCP endpoint: <code><?php echo esc_html( ALIFY_AI_MCP::get_public_mcp_base() ); ?></code> with <code>Authorization: Bearer &lt;token&gt;</code>.</p>
            </div>
        </div>

        <div class="alify-card" style="margin-top:18px;">
            <div class="alify-card-header"><div><h2>OAuth 2.1 clients</h2><p class="alify-help" style="margin:4px 0 0;">Public clients register dynamically and use Authorization Code + PKCE S256. Revoking a client also revokes its OAuth access and refresh credentials.</p></div><span class="alify-status neutral"><?php echo esc_html( (string) count( array_filter( $oauth_clients, static fn( $row ) => empty( $row['revoked_at'] ) ) ) ); ?> registered</span></div>
            <div class="alify-card-body">
                <?php if ( $oauth_clients ) : ?>
                    <div class="alify-table-wrap"><table class="alify-table"><thead><tr><th>Client</th><th>Client ID</th><th>Redirect URIs</th><th>Created</th><th>Status</th><th></th></tr></thead><tbody>
                    <?php foreach ( $oauth_clients as $client_row ) : ?>
                        <tr><td><strong><?php echo esc_html( (string) $client_row['client_name'] ); ?></strong></td><td><code><?php echo esc_html( substr( (string) $client_row['client_id'], 0, 28 ) ); ?>…</code></td><td><?php echo esc_html( implode( ', ', $client_row['redirect_uris'] ) ); ?></td><td><?php echo esc_html( self::format_time( (string) $client_row['created_at'] ) ); ?></td><td><?php echo self::status_badge( empty( $client_row['revoked_at'] ), 'Active', 'Revoked' ); ?></td><td><?php if ( empty( $client_row['revoked_at'] ) ) : ?><?php self::post_form_open( 'alify_ai_revoke_oauth_client' ); ?><input type="hidden" name="oauth_client_id" value="<?php echo esc_attr( (string) $client_row['id'] ); ?>"><button class="button" type="submit" data-confirm="Revoke this OAuth client and all of its OAuth credentials?">Revoke</button></form><?php endif; ?></td></tr>
                    <?php endforeach; ?></tbody></table></div>
                <?php else : ?><p class="alify-help">No OAuth clients have registered yet.</p><?php endif; ?>
                <p class="alify-help"><strong>Authorization server:</strong> <code><?php echo esc_html( home_url( '/.well-known/oauth-authorization-server' ) ); ?></code></p>
                <p class="alify-help"><strong>Protected resource:</strong> <code><?php echo esc_html( home_url( '/.well-known/oauth-protected-resource' ) ); ?></code></p>
            </div>
        </div>

        <div class="alify-card" style="margin-top:18px;">
            <div class="alify-card-header"><div><h2>Legacy REST API access</h2><p class="alify-help" style="margin:4px 0 0;">Optional. The private ChatGPT MCP endpoint does not require this key.</p></div><?php echo self::status_badge( ALIFY_AI_Auth::has_key(), 'Configured', 'Off' ); ?></div>
            <div class="alify-card-body">
                <div class="alify-actions">
                    <?php self::post_form_open( 'alify_ai_generate_key' ); ?><button class="alify-btn alify-btn-outline" type="submit"><?php echo ALIFY_AI_Auth::has_key() ? 'Regenerate API key' : 'Generate API key'; ?></button></form>
                    <?php if ( ALIFY_AI_Auth::has_key() ) : ?><?php self::post_form_open( 'alify_ai_revoke_key' ); ?><button class="alify-btn alify-btn-danger" type="submit" data-confirm="Revoke legacy REST API access?">Revoke API key</button></form><?php endif; ?>
                </div>
                <?php if ( ALIFY_AI_Auth::has_key() ) : ?><p class="alify-help" style="margin-bottom:0;">Current key prefix: <code><?php echo esc_html( ALIFY_AI_Auth::get_prefix() ); ?></code></p><?php endif; ?>
            </div>
        </div>

        <div class="alify-card" style="margin-top:18px;">
            <div class="alify-card-header"><div><h2>Diagnostics</h2><p class="alify-help" style="margin:4px 0 0;">Records redacted connector runtime failures to the PHP error log. Secrets are filtered.</p></div><?php echo self::status_badge( ALIFY_AI_Diagnostics::enabled(), 'Enabled', 'Off' ); ?></div>
            <div class="alify-card-body">
                <?php self::post_form_open( 'alify_ai_save_diagnostics' ); ?>
                <label><input type="checkbox" name="diagnostics_enabled" value="1" <?php checked( ALIFY_AI_Diagnostics::enabled() ); ?>> Enable ALIFY connector diagnostics</label>
                <p class="alify-help">WP_DEBUG also enables these diagnostics automatically.</p>
                <button class="alify-btn alify-btn-outline" type="submit">Save diagnostics</button>
                </form>
            </div>
        </div>

        <div class="alify-card" style="margin-top:18px;">
            <div class="alify-card-header"><h2>Uninstall cleanup</h2></div>
            <div class="alify-card-body">
                <?php self::post_form_open( 'alify_ai_save_uninstall_policy' ); ?>
                <label><input type="checkbox" name="remove_data_on_uninstall" value="1" <?php checked( (bool) get_option( 'alify_ai_remove_data_on_uninstall', false ) ); ?>> Delete connector tables, credentials and settings when the plugin is uninstalled</label>
                <p class="alify-help">Leave this off if you want activity history and connector settings preserved after uninstalling.</p>
                <button class="alify-btn alify-btn-outline" type="submit">Save uninstall policy</button>
                </form>
            </div>
        </div>

        <div class="alify-card" style="margin-top:18px;">
            <div class="alify-card-header"><h2>Developer endpoints</h2></div>
            <div class="alify-card-body alify-table-wrap">
                <table class="alify-table"><tbody>
                    <tr><td><strong>Health</strong></td><td><code><?php echo esc_html( rest_url( 'alify-ai/v1/health' ) ); ?></code></td></tr>
                    <tr><td><strong>OpenAPI schema</strong></td><td><code><?php echo esc_html( rest_url( 'alify-ai/v1/openapi' ) ); ?></code></td></tr>
                    <tr><td><strong>Public MCP base</strong></td><td><code><?php echo esc_html( ALIFY_AI_MCP::get_public_mcp_base() ); ?></code></td></tr>
                    <tr><td><strong>OAuth discovery</strong></td><td><code><?php echo esc_html( home_url( '/.well-known/oauth-authorization-server' ) ); ?></code></td></tr>
                    <tr><td><strong>OAuth token endpoint</strong></td><td><code><?php echo esc_html( rest_url( 'alify-ai/v1/oauth/token' ) ); ?></code></td></tr>
                </tbody></table>
            </div>
        </div>

        <div class="alify-card" style="margin-top:18px;">
            <div class="alify-card-header"><h2>Safety boundaries</h2></div>
            <div class="alify-card-body"><ul class="alify-security-list"><li><span class="dashicons dashicons-yes-alt"></span><span>No arbitrary PHP execution or direct SQL.</span></li><li><span class="dashicons dashicons-yes-alt"></span><span>No user/password management. Permanent page/post deletion requires explicit force + confirmation and is marked irreversible.</span></li><li><span class="dashicons dashicons-yes-alt"></span><span>No plugin/theme install/delete through the connector.</span></li><li><span class="dashicons dashicons-yes-alt"></span><span>WooCommerce order changes are approval-gated behind a separate Orders permission. Payment capture, refunds, payment tokens and gateway actions remain disabled.</span></li><li><span class="dashicons dashicons-yes-alt"></span><span>No remote media URL fetching.</span></li></ul></div>
        </div>
        <?php
        self::shell_end();
    }

    private static function policy_form( string $action, string $name, string $current, string $label ): void {
        self::post_form_open( $action );
        ?>
        <label class="alify-radio-card"><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="approval_required" <?php checked( 'approval_required', $current ); ?>><strong>Approval required</strong><span>ChatGPT can create a preview, but a separate approval action is required before anything is applied.</span></label>
        <label class="alify-radio-card"><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="preview_only" <?php checked( 'preview_only', $current ); ?>><strong>Preview only</strong><span>ChatGPT may prepare proposals, but this connector will not apply them.</span></label>
        <button class="alify-btn alify-btn-outline" type="submit">Save <?php echo esc_html( $label ); ?> policy</button>
        </form>
        <?php
    }
}
