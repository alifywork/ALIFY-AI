<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ALIFY_AI_REST {
    private const NS = 'alify-ai/v1';

    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes(): void {
        self::route( '/health', WP_REST_Server::READABLE, array( __CLASS__, 'health' ), null );
        self::route( '/openapi', WP_REST_Server::READABLE, array( __CLASS__, 'openapi' ), null );
        self::route( '/connector', WP_REST_Server::READABLE, array( __CLASS__, 'connector_manifest' ), null );
        self::route( '/site', WP_REST_Server::READABLE, array( __CLASS__, 'site_info' ), 'read' );
        self::route( '/capabilities', WP_REST_Server::READABLE, array( __CLASS__, 'capabilities' ), 'read' );

        // Read-only source-code and rendered frontend inspection. These routes
        // use a dedicated permission because source code can contain sensitive
        // implementation details even when secrets/config files are blocked.
        self::route( '/code/theme', WP_REST_Server::READABLE, array( __CLASS__, 'code_theme' ), 'code_read' );
        self::route( '/code/plugins', WP_REST_Server::READABLE, array( __CLASS__, 'code_plugins' ), 'code_read' );
        self::route( '/code/files', WP_REST_Server::READABLE, array( __CLASS__, 'code_files' ), 'code_read' );
        self::route( '/code/file', WP_REST_Server::READABLE, array( __CLASS__, 'code_file' ), 'code_read' );
        self::route( '/code/search', WP_REST_Server::READABLE, array( __CLASS__, 'code_search' ), 'code_read' );
        self::route( '/frontend/inspect', WP_REST_Server::READABLE, array( __CLASS__, 'frontend_inspect' ), 'code_read' );
        self::route( '/code/preview-update', WP_REST_Server::CREATABLE, array( __CLASS__, 'code_preview_update' ), 'code_write' );

        foreach ( array( 'pages' => 'page', 'posts' => 'post' ) as $route => $type ) {
            register_rest_route(
                self::NS,
                '/' . $route,
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => static fn( $request ) => self::list_content( $request, $type ),
                        'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize( $request, 'read' ),
                    ),
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => static fn( $request ) => self::create_content( $request, $type ),
                        'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize( $request, 'content' ),
                    ),
                )
            );
            register_rest_route(
                self::NS,
                '/' . $route . '/(?P<id>\d+)',
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => static fn( $request ) => self::get_content( $request, $type ),
                        'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize( $request, 'read' ),
                    ),
                    array(
                        'methods'             => WP_REST_Server::EDITABLE,
                        'callback'            => static fn( $request ) => self::update_content( $request, $type ),
                        'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize( $request, 'content' ),
                    ),
                    array(
                        'methods'             => WP_REST_Server::DELETABLE,
                        'callback'            => static fn( $request ) => self::delete_content( $request, $type ),
                        'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize( $request, 'content' ),
                    ),
                )
            );

            register_rest_route(
                self::NS,
                '/' . $route . '/templates',
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => static fn( $request ) => self::content_templates( $request, $type ),
                    'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize( $request, 'read' ),
                )
            );
            register_rest_route(
                self::NS,
                '/' . $route . '/(?P<id>\d+)/restore',
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => static fn( $request ) => self::restore_trashed_content( $request, $type ),
                    'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize( $request, 'content' ),
                )
            );
            register_rest_route(
                self::NS,
                '/' . $route . '/(?P<id>\d+)/revisions',
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => static fn( $request ) => self::list_revisions( $request, $type ),
                    'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize( $request, 'read' ),
                )
            );
            register_rest_route(
                self::NS,
                '/' . $route . '/(?P<id>\d+)/revisions/(?P<revision_id>\d+)',
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => static fn( $request ) => self::get_revision( $request, $type ),
                    'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize( $request, 'read' ),
                )
            );
            register_rest_route(
                self::NS,
                '/' . $route . '/(?P<id>\d+)/revisions/(?P<revision_id>\d+)/restore',
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => static fn( $request ) => self::restore_revision( $request, $type ),
                    'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize( $request, 'content' ),
                )
            );
        }

        self::route( '/content-authors', WP_REST_Server::READABLE, array( __CLASS__, 'content_authors' ), 'read' );

        register_rest_route(
            self::NS,
            '/content/(?P<type>[a-z0-9_-]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'list_custom_content' ),
                    'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize( $request, 'read' ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( __CLASS__, 'create_custom_content' ),
                    'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize( $request, 'content' ),
                ),
            )
        );
        register_rest_route(
            self::NS,
            '/content/(?P<type>[a-z0-9_-]+)/(?P<id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'get_custom_content' ),
                    'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize( $request, 'read' ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( __CLASS__, 'update_custom_content' ),
                    'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize( $request, 'content' ),
                ),
            )
        );

        self::route( '/post-types', WP_REST_Server::READABLE, array( __CLASS__, 'post_types' ), 'read' );
        self::route( '/post-types', WP_REST_Server::CREATABLE, array( __CLASS__, 'create_post_type' ), 'structure' );
        self::route( '/post-types/(?P<key>[a-z0-9_-]+)', WP_REST_Server::READABLE, array( __CLASS__, 'get_post_type' ), 'read' );
        self::route( '/post-types/(?P<key>[a-z0-9_-]+)', WP_REST_Server::EDITABLE, array( __CLASS__, 'update_post_type' ), 'structure' );
        self::route( '/post-types/(?P<key>[a-z0-9_-]+)', WP_REST_Server::DELETABLE, array( __CLASS__, 'delete_post_type' ), 'structure' );
        self::route( '/taxonomies', WP_REST_Server::READABLE, array( __CLASS__, 'taxonomies' ), 'read' );
        self::route( '/taxonomies', WP_REST_Server::CREATABLE, array( __CLASS__, 'create_taxonomy' ), 'structure' );
        self::route( '/taxonomies/(?P<key>[a-z0-9_-]+)', WP_REST_Server::READABLE, array( __CLASS__, 'get_taxonomy' ), 'read' );
        self::route( '/taxonomies/(?P<key>[a-z0-9_-]+)', WP_REST_Server::EDITABLE, array( __CLASS__, 'update_taxonomy' ), 'structure' );
        self::route( '/taxonomies/(?P<key>[a-z0-9_-]+)', WP_REST_Server::DELETABLE, array( __CLASS__, 'delete_taxonomy' ), 'structure' );
        self::route( '/taxonomies/(?P<key>[a-z0-9_-]+)/object-types/(?P<object_type>[a-z0-9_-]+)', WP_REST_Server::CREATABLE, array( __CLASS__, 'attach_taxonomy_object_type' ), 'structure' );
        self::route( '/taxonomies/(?P<key>[a-z0-9_-]+)/object-types/(?P<object_type>[a-z0-9_-]+)', WP_REST_Server::DELETABLE, array( __CLASS__, 'detach_taxonomy_object_type' ), 'structure' );
        self::route( '/taxonomies/(?P<taxonomy>[a-z0-9_-]+)/terms', WP_REST_Server::READABLE, array( __CLASS__, 'terms' ), 'read' );
        self::route( '/taxonomies/(?P<taxonomy>[a-z0-9_-]+)/terms', WP_REST_Server::CREATABLE, array( __CLASS__, 'create_term' ), 'structure' );
        self::route( '/taxonomies/(?P<taxonomy>[a-z0-9_-]+)/terms/(?P<id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'get_term' ), 'read' );
        self::route( '/taxonomies/(?P<taxonomy>[a-z0-9_-]+)/terms/(?P<id>\d+)', WP_REST_Server::EDITABLE, array( __CLASS__, 'update_term' ), 'structure' );
        self::route( '/taxonomies/(?P<taxonomy>[a-z0-9_-]+)/terms/(?P<id>\d+)', WP_REST_Server::DELETABLE, array( __CLASS__, 'delete_term' ), 'structure' );

        self::route( '/acf/status', WP_REST_Server::READABLE, array( __CLASS__, 'acf_status' ), 'read' );
        self::route( '/acf/field-groups', WP_REST_Server::READABLE, array( __CLASS__, 'acf_groups' ), 'read' );
        self::route( '/acf/field-groups', WP_REST_Server::CREATABLE, array( __CLASS__, 'create_acf_group' ), 'acf' );
        self::route( '/acf/field-groups/(?P<group_key>[a-zA-Z0-9_-]+)', WP_REST_Server::READABLE, array( __CLASS__, 'get_acf_group' ), 'read' );
        self::route( '/acf/field-groups/(?P<group_key>[a-zA-Z0-9_-]+)', WP_REST_Server::EDITABLE, array( __CLASS__, 'update_acf_group' ), 'acf' );
        self::route( '/acf/field-groups/(?P<group_key>[a-zA-Z0-9_-]+)', WP_REST_Server::DELETABLE, array( __CLASS__, 'delete_acf_group' ), 'acf' );
        self::route( '/acf/field-groups/(?P<group_key>[a-zA-Z0-9_-]+)/fields', WP_REST_Server::READABLE, array( __CLASS__, 'acf_fields' ), 'read' );
        self::route( '/acf/field-groups/(?P<group_key>[a-zA-Z0-9_-]+)/fields', WP_REST_Server::CREATABLE, array( __CLASS__, 'create_acf_field' ), 'acf' );
        self::route( '/acf/fields/(?P<field_key>[a-zA-Z0-9_-]+)', WP_REST_Server::READABLE, array( __CLASS__, 'get_acf_field' ), 'read' );
        self::route( '/acf/fields/(?P<field_key>[a-zA-Z0-9_-]+)', WP_REST_Server::EDITABLE, array( __CLASS__, 'update_acf_field' ), 'acf' );
        self::route( '/acf/fields/(?P<field_key>[a-zA-Z0-9_-]+)', WP_REST_Server::DELETABLE, array( __CLASS__, 'delete_acf_field' ), 'acf' );
        self::route( '/acf/values/(?P<post_id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'acf_values' ), 'read' );
        self::route( '/acf/values/(?P<post_id>\d+)', WP_REST_Server::EDITABLE, array( __CLASS__, 'update_acf_values' ), 'acf' );
        self::route( '/acf/options-pages', WP_REST_Server::READABLE, array( __CLASS__, 'acf_option_pages' ), 'read' );
        self::route( '/acf/options-pages/(?P<page>[a-zA-Z0-9_-]+)/values', WP_REST_Server::READABLE, array( __CLASS__, 'acf_option_values' ), 'read' );
        self::route( '/acf/options-pages/(?P<page>[a-zA-Z0-9_-]+)/values', WP_REST_Server::EDITABLE, array( __CLASS__, 'update_acf_option_values' ), 'acf' );

        self::route( '/media', WP_REST_Server::READABLE, array( __CLASS__, 'media' ), 'read' );
        self::route( '/media/limits', WP_REST_Server::READABLE, array( __CLASS__, 'media_limits' ), 'read' );
        self::route( '/media/upload', WP_REST_Server::CREATABLE, array( __CLASS__, 'upload_media' ), 'media' );
        self::route( '/media/upload-json', WP_REST_Server::CREATABLE, array( __CLASS__, 'upload_media_json' ), 'media' );
        self::route( '/media/(?P<id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'get_media' ), 'read' );
        self::route( '/media/(?P<id>\d+)', WP_REST_Server::EDITABLE, array( __CLASS__, 'update_media' ), 'media' );
        self::route( '/media/(?P<id>\d+)', WP_REST_Server::DELETABLE, array( __CLASS__, 'delete_media' ), 'media' );
        self::route( '/media/(?P<id>\d+)/restore', WP_REST_Server::CREATABLE, array( __CLASS__, 'restore_media' ), 'media' );
        self::route( '/media/(?P<id>\d+)/usages', WP_REST_Server::READABLE, array( __CLASS__, 'media_usages' ), 'read' );
        self::route( '/media/(?P<id>\d+)/replace', WP_REST_Server::CREATABLE, array( __CLASS__, 'replace_media_file' ), 'media' );
        self::route( '/media/(?P<id>\d+)/replace-json', WP_REST_Server::CREATABLE, array( __CLASS__, 'replace_media_file_json' ), 'media' );
        self::route( '/media/(?P<id>\d+)/edit', WP_REST_Server::CREATABLE, array( __CLASS__, 'edit_media_image' ), 'media' );
        self::route( '/media/(?P<id>\d+)/regenerate', WP_REST_Server::CREATABLE, array( __CLASS__, 'regenerate_media_metadata' ), 'media' );

        self::route( '/menus', WP_REST_Server::READABLE, array( __CLASS__, 'menus' ), 'read' );
        self::route( '/menus', WP_REST_Server::CREATABLE, array( __CLASS__, 'create_menu' ), 'menus' );
        self::route( '/menus/locations', WP_REST_Server::READABLE, array( __CLASS__, 'menu_locations' ), 'read' );
        self::route( '/menus/locations/(?P<location>[a-zA-Z0-9_-]+)', WP_REST_Server::CREATABLE, array( __CLASS__, 'assign_menu_location' ), 'menus' );
        self::route( '/menus/locations/(?P<location>[a-zA-Z0-9_-]+)', WP_REST_Server::DELETABLE, array( __CLASS__, 'unassign_menu_location' ), 'menus' );
        self::route( '/menus/(?P<id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'get_menu' ), 'read' );
        self::route( '/menus/(?P<id>\d+)', WP_REST_Server::EDITABLE, array( __CLASS__, 'update_menu' ), 'menus' );
        self::route( '/menus/(?P<id>\d+)', WP_REST_Server::DELETABLE, array( __CLASS__, 'delete_menu' ), 'menus' );
        self::route( '/menus/(?P<id>\d+)/items', WP_REST_Server::READABLE, array( __CLASS__, 'menu_items' ), 'read' );
        self::route( '/menus/(?P<id>\d+)/items', WP_REST_Server::CREATABLE, array( __CLASS__, 'create_menu_item' ), 'menus' );
        self::route( '/menus/(?P<id>\d+)/items/reorder', WP_REST_Server::CREATABLE, array( __CLASS__, 'reorder_menu_items' ), 'menus' );
        self::route( '/menus/(?P<id>\d+)/items/(?P<item_id>\d+)', WP_REST_Server::EDITABLE, array( __CLASS__, 'update_menu_item' ), 'menus' );
        self::route( '/menus/(?P<id>\d+)/items/(?P<item_id>\d+)', WP_REST_Server::DELETABLE, array( __CLASS__, 'delete_menu_item' ), 'menus' );

        self::route( '/design/gutenberg/(?P<post_id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'gutenberg_document' ), 'read' );
        self::route( '/design/gutenberg/(?P<post_id>\d+)/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'gutenberg_preview' ), 'design' );
        self::route( '/design/gutenberg/status', WP_REST_Server::READABLE, array( __CLASS__, 'gutenberg_status' ), 'read' );
        self::route( '/design/gutenberg/block-types', WP_REST_Server::READABLE, array( __CLASS__, 'gutenberg_block_types' ), 'read' );
        self::route( '/design/gutenberg/block-type', WP_REST_Server::READABLE, array( __CLASS__, 'gutenberg_block_type' ), 'read' );
        self::route( '/design/gutenberg/validate', WP_REST_Server::CREATABLE, array( __CLASS__, 'gutenberg_validate' ), 'read' );
        self::route( '/design/gutenberg/patterns', WP_REST_Server::READABLE, array( __CLASS__, 'gutenberg_patterns' ), 'read' );
        self::route( '/design/gutenberg/patterns/registered', WP_REST_Server::READABLE, array( __CLASS__, 'gutenberg_registered_pattern' ), 'read' );
        self::route( '/design/gutenberg/patterns/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'gutenberg_pattern_create_preview' ), 'design' );
        self::route( '/design/gutenberg/patterns/(?P<id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'gutenberg_user_pattern' ), 'read' );
        self::route( '/design/gutenberg/patterns/(?P<id>\d+)/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'gutenberg_pattern_preview' ), 'design' );
        self::route( '/design/gutenberg/templates', WP_REST_Server::READABLE, array( __CLASS__, 'gutenberg_templates' ), 'read' );
        self::route( '/design/gutenberg/template', WP_REST_Server::READABLE, array( __CLASS__, 'gutenberg_template' ), 'read' );
        self::route( '/design/gutenberg/templates/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'gutenberg_template_preview' ), 'design' );
        self::route( '/design/elementor/status', WP_REST_Server::READABLE, array( __CLASS__, 'elementor_status' ), 'read' );
        self::route( '/design/elementor/breakpoints', WP_REST_Server::READABLE, array( __CLASS__, 'elementor_breakpoints' ), 'read' );
        self::route( '/design/elementor/widgets', WP_REST_Server::READABLE, array( __CLASS__, 'elementor_widgets' ), 'read' );
        self::route( '/design/elementor/widgets/(?P<name>[a-zA-Z0-9_-]+)', WP_REST_Server::READABLE, array( __CLASS__, 'elementor_widget' ), 'read' );
        self::route( '/design/elementor/templates', WP_REST_Server::READABLE, array( __CLASS__, 'elementor_templates' ), 'read' );
        self::route( '/design/elementor/templates/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'elementor_template_create_preview' ), 'design' );
        self::route( '/design/elementor/templates/(?P<id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'elementor_template' ), 'read' );
        self::route( '/design/elementor/templates/(?P<id>\d+)/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'elementor_template_preview' ), 'design' );
        self::route( '/design/elementor/globals', WP_REST_Server::READABLE, array( __CLASS__, 'elementor_globals' ), 'read' );
        self::route( '/design/elementor/globals/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'elementor_globals_preview' ), 'design' );
        self::route( '/design/elementor/(?P<post_id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'elementor_document' ), 'read' );
        self::route( '/design/elementor/(?P<post_id>\d+)/validate', WP_REST_Server::READABLE, array( __CLASS__, 'elementor_validate' ), 'read' );
        self::route( '/design/elementor/(?P<post_id>\d+)/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'elementor_preview' ), 'design' );

        self::route( '/seo/status', WP_REST_Server::READABLE, array( __CLASS__, 'seo_status' ), 'read' );
        self::route( '/seo/posts/(?P<id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'seo_post' ), 'seo' );
        self::route( '/seo/posts/(?P<id>\d+)/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'seo_post_preview' ), 'seo' );
        self::route( '/seo/audit', WP_REST_Server::READABLE, array( __CLASS__, 'seo_audit' ), 'seo' );

        self::route( '/woocommerce/status', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_status' ), 'read' );
        self::route( '/woocommerce/products', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_products' ), 'read' );
        self::route( '/woocommerce/products/(?P<id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_product' ), 'read' );
        self::route( '/woocommerce/products/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'woocommerce_product_create_preview' ), 'commerce' );
        self::route( '/woocommerce/products/(?P<id>\d+)/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'woocommerce_product_update_preview' ), 'commerce' );
        self::route( '/woocommerce/products/(?P<id>\d+)/variations', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_variations' ), 'read' );
        self::route( '/woocommerce/products/(?P<id>\d+)/variations/(?P<variation_id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_variation' ), 'read' );
        self::route( '/woocommerce/attributes', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_attributes' ), 'read' );
        self::route( '/woocommerce/attributes/(?P<id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_attribute' ), 'read' );
        self::route( '/woocommerce/attributes/(?P<id>\d+)/terms', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_attribute_terms' ), 'read' );
        self::route( '/woocommerce/attributes/(?P<id>\d+)/terms/(?P<term_id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_attribute_term' ), 'read' );
        self::route( '/woocommerce/shipping-classes', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_shipping_classes' ), 'read' );
        self::route( '/woocommerce/shipping-classes/(?P<id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_shipping_class' ), 'read' );
        self::route( '/woocommerce/tax-classes', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_tax_classes' ), 'read' );
        self::route( '/woocommerce/tax-classes/(?P<slug>[a-zA-Z0-9_-]+)', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_tax_class' ), 'read' );
        self::route( '/woocommerce/catalog/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'woocommerce_catalog_preview' ), 'commerce' );
        self::route( '/woocommerce/order-statuses', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_order_statuses' ), 'orders' );
        self::route( '/woocommerce/orders', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_orders' ), 'orders' );
        self::route( '/woocommerce/orders/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'woocommerce_order_create_preview' ), 'orders' );
        self::route( '/woocommerce/orders/(?P<id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_order' ), 'orders' );
        self::route( '/woocommerce/orders/(?P<id>\d+)/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'woocommerce_order_update_preview' ), 'orders' );
        self::route( '/woocommerce/orders/(?P<id>\d+)/items/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'woocommerce_order_item_create_preview' ), 'orders' );
        self::route( '/woocommerce/orders/(?P<id>\d+)/items/(?P<item_id>\d+)/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'woocommerce_order_item_preview' ), 'orders' );
        self::route( '/woocommerce/orders/(?P<id>\d+)/notes', WP_REST_Server::READABLE, array( __CLASS__, 'woocommerce_order_notes' ), 'orders' );
        self::route( '/woocommerce/orders/(?P<id>\d+)/notes/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'woocommerce_order_note_create_preview' ), 'orders' );
        self::route( '/woocommerce/orders/(?P<id>\d+)/notes/(?P<note_id>\d+)/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'woocommerce_order_note_delete_preview' ), 'orders' );
        self::route( '/transactions/preview', WP_REST_Server::CREATABLE, array( __CLASS__, 'transaction_preview' ), 'transactions' );

        self::route( '/approvals', WP_REST_Server::READABLE, array( __CLASS__, 'approvals' ), 'read' );
        self::route( '/approvals/(?P<id>\d+)', WP_REST_Server::READABLE, array( __CLASS__, 'get_approval' ), 'read' );
        register_rest_route(
            self::NS,
            '/approvals/(?P<id>\d+)/apply',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'apply_approval' ),
                'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize_any( $request, array( 'design', 'seo', 'commerce', 'orders', 'transactions' ) ),
            )
        );
        register_rest_route(
            self::NS,
            '/approvals/(?P<id>\d+)/cancel',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'cancel_approval' ),
                'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize_any( $request, array( 'design', 'seo', 'commerce', 'orders', 'transactions' ) ),
            )
        );

        self::route( '/activity', WP_REST_Server::READABLE, array( __CLASS__, 'activity' ), 'read' );
        register_rest_route(
            self::NS,
            '/rollback/(?P<id>\d+)',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'rollback' ),
                'permission_callback' => static fn( $request ) => ALIFY_AI_Auth::authorize_any( $request, array( 'content', 'structure', 'design', 'seo', 'acf', 'media', 'commerce', 'transactions' ) ),
            )
        );
    }

    private static function route( string $route, string $methods, callable $callback, ?string $scope ): void {
        register_rest_route(
            self::NS,
            $route,
            array(
                'methods'             => $methods,
                'callback'            => $callback,
                'permission_callback' => null === $scope ? '__return_true' : static fn( $request ) => ALIFY_AI_Auth::authorize( $request, $scope ),
            )
        );
    }

    public static function health(): WP_REST_Response {
        return new WP_REST_Response( array( 'ok' => true, 'plugin' => 'ALIFY AI Connector', 'version' => ALIFY_AI_VERSION, 'phase' => 32 ) );
    }

    public static function connector_manifest(): WP_REST_Response {
        return new WP_REST_Response(
            array(
                'name'        => 'ALIFY AI Connector',
                'version'     => ALIFY_AI_VERSION,
                'api_base'    => rest_url( self::NS ),
                'openapi_url' => rest_url( self::NS . '/openapi' ),
                'health_url'  => rest_url( self::NS . '/health' ),
                'mcp_base'    => ALIFY_AI_MCP::get_public_mcp_base(),
                'auth'        => array(
                    'preferred' => array(
                        'type' => 'bearer',
                        'header_name' => 'Authorization',
                        'format' => 'Bearer <per-user-token>',
                        'note' => 'Create a scoped, expiring per-user token in WordPress > ALIFY AI > Settings.',
                    ),
                    'legacy' => array(
                        'type' => 'api_key',
                        'header_name' => 'X-ALIFY-Key',
                        'note' => 'Legacy global REST key remains supported for backward compatibility.',
                    ),
                ),
                'workflow'    => array(
                    'discover' => array( 'site', 'capabilities' ),
                    'preview_before_apply' => array( 'design', 'seo', 'woocommerce', 'transactions' ),
                    'audit' => 'activity',
                    'rollback' => 'rollback/{activity_id}',
                ),
            )
        );
    }

    public static function site_info(): WP_REST_Response {
        $theme = wp_get_theme();
        return new WP_REST_Response(
            array(
                'name'        => get_bloginfo( 'name' ),
                'description' => get_bloginfo( 'description' ),
                'url'         => home_url( '/' ),
                'admin_url'   => admin_url(),
                'wp_version'  => get_bloginfo( 'version' ),
                'language'    => get_bloginfo( 'language' ),
                'timezone'    => wp_timezone_string(),
                'theme'       => array( 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ) ),
                'acf'         => ALIFY_AI_ACF::status(),
                'woocommerce' => ALIFY_AI_WooCommerce::status(),
                'seo'         => ALIFY_AI_SEO::status(),
                'counts'      => array(
                    'pages' => (int) ( wp_count_posts( 'page' )->publish ?? 0 ),
                    'posts' => (int) ( wp_count_posts( 'post' )->publish ?? 0 ),
                    'media' => (int) ( wp_count_posts( 'attachment' )->inherit ?? 0 ),
                ),
            )
        );
    }

    public static function capabilities(): WP_REST_Response {
        return new WP_REST_Response(
            array(
                'version' => ALIFY_AI_VERSION,
                'phase'   => 32,
                'scopes'  => ( ALIFY_AI_Auth::runtime_scope_report()['effective'] ?? ALIFY_AI_Auth::get_scopes() ),
                'scope_access' => ALIFY_AI_Auth::runtime_scope_report(),
                'features' => array(
                    'mcp'        => array( 'protocol' => '2026-07-28', 'server_discover' => true, 'scope_filtered_tools' => true, 'legacy_initialize' => true ),
                    'content'    => array( 'enabled' => true, 'trash' => true, 'permanent_delete_with_confirmation' => true, 'scheduling' => true, 'authors' => true, 'theme_templates' => true, 'revisions' => true, 'restore_revision' => true ),
                    'cpt'        => true,
                    'taxonomies' => true,
                    'acf'        => array( 'available'=>ALIFY_AI_ACF::available(), 'post_values'=>true, 'options_pages'=>true, 'options_write'=>true, 'nested_values'=>true, 'rollback'=>true ),
                    'media'      => true,
                    'menus'      => true,
                    'gutenberg'  => array_merge( array( 'enabled' => true, 'deep_validation' => true, 'patterns' => true, 'synced_patterns' => true, 'templates' => true, 'template_parts' => true, 'approval_gated_entity_writes' => true ), ALIFY_AI_Gutenberg::status() ),
                    'elementor'    => ALIFY_AI_Elementor::status(),
                    'media_upload' => array_merge( array( 'enabled' => true, 'mcp_base64' => true ), ALIFY_AI_Media::get_upload_limits() ),
                    'woocommerce'  => ALIFY_AI_WooCommerce::status(),
                    'seo'          => ALIFY_AI_SEO::status(),
                    'approvals'    => array(
                        'design_policy'      => ALIFY_AI_Approvals::get_policy(),
                        'commerce_policy'    => ALIFY_AI_Approvals::get_commerce_policy(),
                        'transaction_policy' => ALIFY_AI_Approvals::get_transaction_policy(),
                        'stale_write_protection' => true,
                    ),
                    'transactions' => array( 'atomic_preflight' => true, 'max_operations' => 20, 'compensation_rollback' => true ),
                    'code_inspection' => array( 'read_only' => false, 'active_theme' => true, 'active_plugins' => true, 'literal_search' => true, 'frontend_html' => true, 'frontend_assets' => true, 'path_sandbox' => true, 'secret_file_blocklist' => true, 'code_write' => true, 'approval_gated_writes' => true, 'stale_write_protection' => true, 'php_syntax_validation' => true, 'rollback' => true ),
                    'mcp'          => array_merge( array( 'embedded' => true, 'tool_count' => count( ALIFY_AI_MCP::tools() ), 'single_url_connection' => true, 'bearer_auth' => true, 'per_user_credentials' => true, 'oauth_2_1' => true, 'pkce_s256' => true, 'dynamic_client_registration' => true, 'refresh_token_rotation' => true ), ALIFY_AI_MCP::runtime_tool_summary() ),
                    'rollback'     => array( 'content_create_to_trash', 'post_updates', 'content_trash', 'content_untrash', 'revision_restore', 'post_type_create_update_delete', 'media_upload_update_delete_replace_edit_regenerate', 'acf_value_updates', 'acf_group_create_update_delete', 'acf_field_create_update_delete', 'gutenberg_updates', 'gutenberg_pattern_create_update_delete_restore', 'gutenberg_template_create_update_delete', 'elementor_document_template_global_style_updates', 'woocommerce_products_attributes_variations_shipping_tax', 'seo_metadata', 'connector_transactions' ),
                ),
            )
        );
    }

    public static function code_theme( WP_REST_Request $request ) {
        return new WP_REST_Response( ALIFY_AI_Code_Inspection::active_theme() );
    }

    public static function code_plugins( WP_REST_Request $request ) {
        return new WP_REST_Response( ALIFY_AI_Code_Inspection::active_plugins() );
    }

    public static function code_files( WP_REST_Request $request ) {
        $result = ALIFY_AI_Code_Inspection::list_files(
            sanitize_key( (string) ( $request->get_param( 'target' ) ?: 'theme' ) ),
            sanitize_key( (string) $request->get_param( 'plugin' ) ),
            sanitize_text_field( (string) $request->get_param( 'path' ) )
        );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function code_file( WP_REST_Request $request ) {
        $result = ALIFY_AI_Code_Inspection::read_file(
            sanitize_key( (string) ( $request->get_param( 'target' ) ?: 'theme' ) ),
            sanitize_key( (string) $request->get_param( 'plugin' ) ),
            sanitize_text_field( (string) $request->get_param( 'path' ) )
        );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function code_search( WP_REST_Request $request ) {
        $result = ALIFY_AI_Code_Inspection::search_code(
            sanitize_key( (string) ( $request->get_param( 'target' ) ?: 'theme' ) ),
            sanitize_key( (string) $request->get_param( 'plugin' ) ),
            (string) $request->get_param( 'query' ),
            sanitize_text_field( (string) $request->get_param( 'path' ) ),
            absint( $request->get_param( 'max_matches' ) ?: 50 )
        );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function code_preview_update( WP_REST_Request $request ) {
        $params = self::params( $request );
        $result = ALIFY_AI_Code_Inspection::preview_update(
            sanitize_key( (string) ( $params['target'] ?? 'theme' ) ),
            sanitize_key( (string) ( $params['plugin'] ?? '' ) ),
            (string) ( $params['path'] ?? '' ),
            (string) ( $params['content'] ?? '' ),
            (string) ( $params['expected_sha256'] ?? '' )
        );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'Source-code update proposal created. No file has been changed yet.', 'approval' => $result ), 201 );
    }

    public static function frontend_inspect( WP_REST_Request $request ) {
        $include_html = null === $request->get_param( 'include_html' ) ? true : rest_sanitize_boolean( $request->get_param( 'include_html' ) );
        $result = ALIFY_AI_Code_Inspection::inspect_frontend(
            (string) ( $request->get_param( 'path' ) ?: '/' ),
            $include_html
        );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function list_custom_content( WP_REST_Request $request ) {
        $type = sanitize_key( (string) $request['type'] );
        $valid = self::validate_content_type( $type );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }
        return self::list_content( $request, $type );
    }

    public static function get_custom_content( WP_REST_Request $request ) {
        $type = sanitize_key( (string) $request['type'] );
        $valid = self::validate_content_type( $type );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }
        return self::get_content( $request, $type );
    }

    public static function create_custom_content( WP_REST_Request $request ) {
        $type = sanitize_key( (string) $request['type'] );
        $valid = self::validate_content_type( $type );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }
        return self::create_content( $request, $type );
    }

    public static function update_custom_content( WP_REST_Request $request ) {
        $type = sanitize_key( (string) $request['type'] );
        $valid = self::validate_content_type( $type );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }
        return self::update_content( $request, $type );
    }

    private static function validate_content_type( string $type ) {
        $blocked = array( 'attachment', 'revision', 'nav_menu_item', 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_order_placehold' );
        if ( in_array( $type, $blocked, true ) || str_starts_with( $type, 'shop_order' ) || ! post_type_exists( $type ) ) {
            return new WP_Error( 'alify_ai_post_type_not_found', 'Supported post type not found or requires a dedicated connector integration.', array( 'status' => 404 ) );
        }
        $object = get_post_type_object( $type );
        if ( ! $object || ! $object->show_ui ) {
            return new WP_Error( 'alify_ai_post_type_forbidden', 'This post type is not exposed for content operations.', array( 'status' => 403 ) );
        }
        return true;
    }

    private static function list_content( WP_REST_Request $request, string $type ): WP_REST_Response {
        $per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
        $status = sanitize_key( (string) $request->get_param( 'status' ) );
        if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'any' ), true ) ) {
            $status = 'any';
        }
        $args = array(
            'post_type'      => $type,
            'post_status'    => $status,
            'posts_per_page' => $per_page,
            's'              => sanitize_text_field( (string) $request->get_param( 'search' ) ),
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        );
        $author = absint( $request->get_param( 'author' ) );
        if ( $author ) {
            $args['author'] = $author;
        }
        $query = new WP_Query( $args );
        return new WP_REST_Response( array_map( array( __CLASS__, 'serialize_post' ), $query->posts ) );
    }

    private static function get_content( WP_REST_Request $request, string $type ) {
        $post = get_post( absint( $request['id'] ) );
        if ( ! $post || $type !== $post->post_type ) {
            return new WP_Error( 'alify_ai_not_found', 'Content not found.', array( 'status' => 404 ) );
        }
        return new WP_REST_Response( self::serialize_post( $post, true ) );
    }

    public static function content_authors( WP_REST_Request $request ) {
        $type = sanitize_key( (string) ( $request->get_param( 'type' ) ?: 'post' ) );
        $valid = self::validate_content_type( $type );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }
        $post_type = get_post_type_object( $type );
        $capability = $post_type && isset( $post_type->cap->edit_posts ) ? (string) $post_type->cap->edit_posts : 'edit_posts';
        $users = get_users(
            array(
                'capability' => $capability,
                'number'     => 100,
                'orderby'    => 'display_name',
                'order'      => 'ASC',
            )
        );
        $items = array_map(
            static fn( WP_User $user ): array => array(
                'id'           => (int) $user->ID,
                'display_name' => $user->display_name,
                'user_login'   => $user->user_login,
            ),
            $users
        );
        return new WP_REST_Response( $items );
    }

    private static function content_templates( WP_REST_Request $request, string $type ) {
        $post = null;
        $post_id = absint( $request->get_param( 'post_id' ) );
        if ( $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post || $post->post_type !== $type ) {
                return new WP_Error( 'alify_ai_not_found', 'Content not found for template context.', array( 'status' => 404 ) );
            }
        }
        $templates = wp_get_theme()->get_page_templates( $post, $type );
        $items = array( array( 'file' => 'default', 'name' => 'Default template' ) );
        foreach ( $templates as $file => $name ) {
            $items[] = array( 'file' => (string) $file, 'name' => (string) $name );
        }
        return new WP_REST_Response(
            array(
                'post_type'   => $type,
                'theme'       => wp_get_theme()->get( 'Name' ),
                'block_theme' => wp_is_block_theme(),
                'templates'   => $items,
            )
        );
    }

    private static function list_revisions( WP_REST_Request $request, string $type ) {
        $post_id = absint( $request['id'] );
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== $type ) {
            return new WP_Error( 'alify_ai_not_found', 'Content not found.', array( 'status' => 404 ) );
        }
        if ( ! wp_revisions_enabled( $post ) ) {
            return new WP_REST_Response( array( 'enabled' => false, 'items' => array() ) );
        }
        $per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
        $revisions = wp_get_post_revisions( $post_id, array( 'posts_per_page' => $per_page ) );
        return new WP_REST_Response(
            array(
                'enabled' => true,
                'items'   => array_values( array_map( array( __CLASS__, 'serialize_revision' ), $revisions ) ),
            )
        );
    }

    private static function get_revision( WP_REST_Request $request, string $type ) {
        $post_id = absint( $request['id'] );
        $revision_id = absint( $request['revision_id'] );
        $post = get_post( $post_id );
        $revision = wp_get_post_revision( $revision_id );
        if ( ! $post || $post->post_type !== $type || ! $revision || (int) $revision->post_parent !== $post_id ) {
            return new WP_Error( 'alify_ai_revision_not_found', 'Revision not found for this content item.', array( 'status' => 404 ) );
        }
        return new WP_REST_Response( self::serialize_revision( $revision, true ) );
    }

    private static function restore_revision( WP_REST_Request $request, string $type ) {
        $post_id = absint( $request['id'] );
        $revision_id = absint( $request['revision_id'] );
        $post = get_post( $post_id );
        $revision = wp_get_post_revision( $revision_id );
        if ( ! $post || $post->post_type !== $type || ! $revision || (int) $revision->post_parent !== $post_id ) {
            return new WP_Error( 'alify_ai_revision_not_found', 'Revision not found for this content item.', array( 'status' => 404 ) );
        }

        $before = self::snapshot_post( $post );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before, null );
        if ( is_wp_error( $snapshot_ok ) ) {
            return $snapshot_ok;
        }

        try {
            $restored_id = wp_restore_post_revision( $revision_id );
        } catch ( Throwable $e ) {
            return new WP_Error( 'alify_ai_revision_restore_failed', 'Revision restore failed: ' . $e->getMessage(), array( 'status' => 500 ) );
        }
        if ( ! $restored_id ) {
            return new WP_Error( 'alify_ai_revision_restore_failed', 'WordPress could not restore this revision.', array( 'status' => 500 ) );
        }

        clean_post_cache( $post_id );
        $after_post = get_post( $post_id );
        if ( ! $after_post ) {
            self::restore_serialized_post( $before );
            return new WP_Error( 'alify_ai_revision_restore_failed', 'Content disappeared after revision restore.', array( 'status' => 500 ) );
        }
        $after = self::snapshot_post( $after_post );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before, $after );
        if ( is_wp_error( $snapshot_ok ) ) {
            self::restore_serialized_post( $before );
            return $snapshot_ok;
        }
        $log_id = ALIFY_AI_Audit::log( 'restore_revision', $type, $post_id, $before, $after );
        if ( ! $log_id ) {
            self::restore_serialized_post( $before );
            return new WP_Error( 'alify_ai_audit_failed', 'Revision restore was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return new WP_REST_Response(
            array(
                'message'     => 'Revision restored.',
                'revision_id' => $revision_id,
                'activity_id' => $log_id,
                'item'        => self::serialize_post( $after_post, true ),
            )
        );
    }

    private static function restore_trashed_content( WP_REST_Request $request, string $type ) {
        $post_id = absint( $request['id'] );
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== $type ) {
            return new WP_Error( 'alify_ai_not_found', 'Content not found.', array( 'status' => 404 ) );
        }
        if ( 'trash' !== $post->post_status ) {
            return new WP_Error( 'alify_ai_not_trashed', 'Content is not in the Trash.', array( 'status' => 409 ) );
        }
        $before = self::snapshot_post( $post );
        $result = wp_untrash_post( $post_id );
        if ( ! $result ) {
            return new WP_Error( 'alify_ai_untrash_failed', 'WordPress could not restore this content from Trash.', array( 'status' => 500 ) );
        }
        clean_post_cache( $post_id );
        $after_post = get_post( $post_id );
        if ( ! $after_post ) {
            wp_trash_post( $post_id );
            return new WP_Error( 'alify_ai_post_missing_after_write', 'Content could not be reloaded after the WordPress write; the restore was reverted where possible.', array( 'status' => 500 ) );
        }
        $after = self::snapshot_post( $after_post );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before, $after );
        if ( is_wp_error( $snapshot_ok ) ) {
            wp_trash_post( $post_id );
            return $snapshot_ok;
        }
        $log_id = ALIFY_AI_Audit::log( 'untrash', $type, $post_id, $before, $after );
        if ( ! $log_id ) {
            wp_trash_post( $post_id );
            return new WP_Error( 'alify_ai_audit_failed', 'Trash restore was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return new WP_REST_Response( array( 'message' => 'Content restored from Trash.', 'activity_id' => $log_id, 'item' => self::serialize_post( $after_post, true ) ) );
    }

    private static function delete_content( WP_REST_Request $request, string $type ) {
        $post_id = absint( $request['id'] );
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== $type ) {
            return new WP_Error( 'alify_ai_not_found', 'Content not found.', array( 'status' => 404 ) );
        }
        $force = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );
        $confirm = filter_var( $request->get_param( 'confirm_permanent' ), FILTER_VALIDATE_BOOLEAN );
        $before = self::snapshot_post( $post );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before, null );
        if ( is_wp_error( $snapshot_ok ) ) {
            return $snapshot_ok;
        }

        if ( ! $force ) {
            if ( ! EMPTY_TRASH_DAYS ) {
                return new WP_Error( 'alify_ai_trash_disabled', 'WordPress Trash is disabled. Permanent deletion requires force=true and confirm_permanent=true.', array( 'status' => 409 ) );
            }
            if ( 'trash' === $post->post_status ) {
                return new WP_Error( 'alify_ai_already_trashed', 'Content is already in Trash. Restore it or use confirmed permanent deletion.', array( 'status' => 409 ) );
            }
            $result = wp_trash_post( $post_id );
            if ( ! $result ) {
                return new WP_Error( 'alify_ai_trash_failed', 'WordPress could not move this content to Trash.', array( 'status' => 500 ) );
            }
            clean_post_cache( $post_id );
            $after_post = get_post( $post_id );
            if ( ! $after_post ) {
                wp_untrash_post( $post_id );
                return new WP_Error( 'alify_ai_post_missing_after_write', 'Content could not be reloaded after moving it to Trash.', array( 'status' => 500 ) );
            }
            $after = self::snapshot_post( $after_post );
            $log_id = ALIFY_AI_Audit::log( 'trash', $type, $post_id, $before, $after );
            if ( ! $log_id ) {
                wp_untrash_post( $post_id );
                self::restore_serialized_post( $before );
                return new WP_Error( 'alify_ai_audit_failed', 'Trash operation was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
            }
            return new WP_REST_Response( array( 'message' => 'Content moved to Trash.', 'activity_id' => $log_id, 'item' => self::serialize_post( $after_post, true ) ) );
        }

        if ( ! $confirm ) {
            return new WP_Error( 'alify_ai_permanent_delete_confirmation_required', 'Permanent deletion is irreversible. Set confirm_permanent=true to continue.', array( 'status' => 400 ) );
        }

        // Refuse the destructive operation unless its rollback snapshot can at
        // least be recorded first. Permanent deletion itself is intentionally
        // marked irreversible because WordPress removes comments/meta/terms.
        $log_id = ALIFY_AI_Audit::log( 'delete_permanent', $type, $post_id, $before, null );
        if ( ! $log_id ) {
            return new WP_Error( 'alify_ai_audit_failed', 'Permanent deletion was refused because its audit record could not be stored.', array( 'status' => 500 ) );
        }
        $result = wp_delete_post( $post_id, true );
        if ( ! $result ) {
            ALIFY_AI_Audit::delete_entry( $log_id );
            return new WP_Error( 'alify_ai_delete_failed', 'WordPress could not permanently delete this content.', array( 'status' => 500 ) );
        }
        return new WP_REST_Response(
            array(
                'message'      => 'Content permanently deleted.',
                'activity_id'  => $log_id,
                'deleted'      => true,
                'irreversible' => true,
                'previous'     => $before,
            )
        );
    }

    private static function serialize_revision( WP_Post $revision, bool $full = false ): array {
        $author_id = (int) $revision->post_author;
        $data = array(
            'id'          => (int) $revision->ID,
            'parent'      => (int) $revision->post_parent,
            'author'      => $author_id,
            'author_name' => (string) get_the_author_meta( 'display_name', $author_id ),
            'date'        => get_post_time( DATE_ATOM, false, $revision ),
            'date_gmt'    => get_post_time( DATE_ATOM, true, $revision ),
            'title'       => $revision->post_title,
        );
        if ( $full ) {
            $data['content'] = array( 'raw' => $revision->post_content );
            $data['excerpt'] = array( 'raw' => $revision->post_excerpt );
        }
        return $data;
    }

    private static function create_content( WP_REST_Request $request, string $type ) {
        $payload = self::sanitize_post_payload( $request, true, $type );
        if ( is_wp_error( $payload ) ) {
            return $payload;
        }
        $extras = self::prepare_post_extras( $type, $request );
        if ( is_wp_error( $extras ) ) {
            return $extras;
        }

        $payload['post_type'] = $type;
        $post_id = wp_insert_post( $payload, true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }
        if ( ! $post_id ) {
            return new WP_Error( 'alify_ai_create_failed', 'WordPress could not create this content item.', array( 'status' => 500 ) );
        }

        $extra_result = self::apply_prepared_post_extras( $post_id, $extras );
        if ( is_wp_error( $extra_result ) ) {
            wp_delete_post( $post_id, true );
            return $extra_result;
        }

        clean_post_cache( $post_id );
        $after_post = get_post( $post_id );
        if ( ! $after_post ) {
            return new WP_Error( 'alify_ai_post_missing_after_write', 'Created content could not be reloaded after insertion.', array( 'status' => 500 ) );
        }
        $after = self::snapshot_post( $after_post );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( null, $after );
        if ( is_wp_error( $snapshot_ok ) ) {
            wp_delete_post( $post_id, true );
            return $snapshot_ok;
        }
        $log_id = ALIFY_AI_Audit::log( 'create', $type, $post_id, null, $after );
        if ( ! $log_id ) {
            wp_delete_post( $post_id, true );
            return new WP_Error( 'alify_ai_audit_failed', 'Content creation was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return new WP_REST_Response( array( 'message' => 'Content created.', 'activity_id' => $log_id, 'item' => self::serialize_post( $after_post, true ) ), 201 );
    }

    private static function update_content( WP_REST_Request $request, string $type ) {
        $post_id = absint( $request['id'] );
        $post = get_post( $post_id );
        if ( ! $post || $type !== $post->post_type ) {
            return new WP_Error( 'alify_ai_not_found', 'Content not found.', array( 'status' => 404 ) );
        }
        if ( 'trash' === $post->post_status ) {
            return new WP_Error( 'alify_ai_content_trashed', 'Restore this content from Trash before editing it.', array( 'status' => 409 ) );
        }

        $before = self::snapshot_post( $post );
        $payload = self::sanitize_post_payload( $request, false, $type );
        if ( is_wp_error( $payload ) ) {
            return $payload;
        }
        $extras = self::prepare_post_extras( $type, $request );
        if ( is_wp_error( $extras ) ) {
            return $extras;
        }
        if ( empty( $payload ) && empty( $extras ) ) {
            return new WP_Error( 'alify_ai_no_changes', 'No supported fields were supplied.', array( 'status' => 400 ) );
        }

        if ( count( $payload ) > 0 ) {
            $payload['ID'] = $post_id;
            $result = wp_update_post( $payload, true );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            if ( ! $result ) {
                return new WP_Error( 'alify_ai_update_failed', 'WordPress could not update this content item.', array( 'status' => 500 ) );
            }
        }

        $extra_result = self::apply_prepared_post_extras( $post_id, $extras );
        if ( is_wp_error( $extra_result ) ) {
            self::restore_serialized_post( $before );
            return $extra_result;
        }

        clean_post_cache( $post_id );
        $after_post = get_post( $post_id );
        if ( ! $after_post ) {
            self::restore_serialized_post( $before );
            return new WP_Error( 'alify_ai_post_missing_after_write', 'Updated content could not be reloaded after the WordPress write.', array( 'status' => 500 ) );
        }
        $after = self::snapshot_post( $after_post );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before, $after );
        if ( is_wp_error( $snapshot_ok ) ) {
            self::restore_serialized_post( $before );
            return $snapshot_ok;
        }
        $log_id = ALIFY_AI_Audit::log( 'update', $type, $post_id, $before, $after );
        if ( ! $log_id ) {
            self::restore_serialized_post( $before );
            return new WP_Error( 'alify_ai_audit_failed', 'Content update was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return new WP_REST_Response( array( 'message' => 'Content updated.', 'activity_id' => $log_id, 'item' => self::serialize_post( $after_post, true ) ) );
    }

    private static function sanitize_post_payload( WP_REST_Request $request, bool $creating, string $type ) {
        $params = self::params( $request );
        $payload = array();
        if ( array_key_exists( 'title', $params ) ) {
            $payload['post_title'] = sanitize_text_field( (string) $params['title'] );
        }
        if ( array_key_exists( 'content', $params ) ) {
            $payload['post_content'] = wp_kses_post( (string) $params['content'] );
        }
        if ( array_key_exists( 'excerpt', $params ) ) {
            $payload['post_excerpt'] = wp_kses_post( (string) $params['excerpt'] );
        }
        if ( array_key_exists( 'slug', $params ) ) {
            $payload['post_name'] = sanitize_title( (string) $params['slug'] );
        }
        if ( array_key_exists( 'status', $params ) ) {
            $status = sanitize_key( (string) $params['status'] );
            if ( ! in_array( $status, array( 'draft', 'pending', 'publish', 'private', 'future' ), true ) ) {
                return new WP_Error( 'alify_ai_invalid_status', 'Unsupported post status. Use the delete endpoint for Trash.', array( 'status' => 400 ) );
            }
            $payload['post_status'] = $status;
        } elseif ( $creating ) {
            $payload['post_status'] = 'draft';
        }
        if ( array_key_exists( 'parent', $params ) ) {
            $parent = absint( $params['parent'] );
            if ( $parent ) {
                $parent_post = get_post( $parent );
                if ( ! $parent_post || $parent_post->post_type !== $type ) {
                    return new WP_Error( 'alify_ai_invalid_parent', 'parent must reference content of the same post type.', array( 'status' => 400 ) );
                }
            }
            $payload['post_parent'] = $parent;
        }
        if ( array_key_exists( 'menu_order', $params ) ) {
            $payload['menu_order'] = (int) $params['menu_order'];
        }
        if ( array_key_exists( 'author', $params ) ) {
            $author_id = absint( $params['author'] );
            $author = get_userdata( $author_id );
            if ( ! $author ) {
                return new WP_Error( 'alify_ai_invalid_author', 'Invalid author ID.', array( 'status' => 400 ) );
            }
            $post_type = get_post_type_object( $type );
            $capability = $post_type && isset( $post_type->cap->edit_posts ) ? (string) $post_type->cap->edit_posts : 'edit_posts';
            if ( ! user_can( $author, $capability ) ) {
                return new WP_Error( 'alify_ai_author_forbidden', 'The selected user cannot author this content type.', array( 'status' => 400 ) );
            }
            $payload['post_author'] = $author_id;
        }

        $has_date = array_key_exists( 'date', $params ) && null !== $params['date'] && '' !== (string) $params['date'];
        $has_date_gmt = array_key_exists( 'date_gmt', $params ) && null !== $params['date_gmt'] && '' !== (string) $params['date_gmt'];
        if ( $has_date ) {
            $date_data = rest_get_date_with_gmt( (string) $params['date'] );
            if ( ! $date_data ) {
                return new WP_Error( 'alify_ai_invalid_date', 'date must be a valid RFC3339 timestamp.', array( 'status' => 400 ) );
            }
            list( $payload['post_date'], $payload['post_date_gmt'] ) = $date_data;
            $payload['edit_date'] = true;
        } elseif ( $has_date_gmt ) {
            $date_data = rest_get_date_with_gmt( (string) $params['date_gmt'], true );
            if ( ! $date_data ) {
                return new WP_Error( 'alify_ai_invalid_date_gmt', 'date_gmt must be a valid RFC3339 UTC timestamp.', array( 'status' => 400 ) );
            }
            list( $payload['post_date'], $payload['post_date_gmt'] ) = $date_data;
            $payload['edit_date'] = true;
        }

        if ( 'future' === ( $payload['post_status'] ?? '' ) ) {
            $future_gmt = (string) ( $payload['post_date_gmt'] ?? '' );
            if ( '' === $future_gmt && ! $creating ) {
                $existing = get_post( absint( $request['id'] ?? 0 ) );
                $future_gmt = $existing ? (string) $existing->post_date_gmt : '';
            }
            if ( '' === $future_gmt || strtotime( $future_gmt . ' UTC' ) <= time() ) {
                return new WP_Error( 'alify_ai_invalid_schedule', 'status=future requires a publication date in the future.', array( 'status' => 400 ) );
            }
        }

        if ( $creating && empty( $payload['post_title'] ) ) {
            return new WP_Error( 'alify_ai_title_required', 'Title is required.', array( 'status' => 400 ) );
        }
        return $payload;
    }

    private static function prepare_post_extras( string $type, WP_REST_Request $request ) {
        $params = self::params( $request );
        $extras = array();

        if ( array_key_exists( 'featured_media', $params ) ) {
            $attachment_id = absint( $params['featured_media'] );
            if ( 0 !== $attachment_id ) {
                $attachment = get_post( $attachment_id );
                if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $attachment_id ) ) {
                    return new WP_Error( 'alify_ai_invalid_featured_media', 'featured_media must reference an existing image attachment.', array( 'status' => 400 ) );
                }
            }
            $extras['featured_media'] = $attachment_id;
        }

        if ( array_key_exists( 'template', $params ) ) {
            $template = sanitize_text_field( (string) $params['template'] );
            $template = '' === $template ? 'default' : $template;
            if ( 'default' !== $template ) {
                $context_post = get_post( absint( $request['id'] ?? 0 ) );
                if ( $context_post && $context_post->post_type !== $type ) {
                    $context_post = null;
                }
                $templates = wp_get_theme()->get_page_templates( $context_post, $type );
                if ( ! array_key_exists( $template, $templates ) ) {
                    return new WP_Error( 'alify_ai_invalid_template', 'The requested theme template is not available for this post type.', array( 'status' => 400, 'template' => $template ) );
                }
            }
            $extras['template'] = $template;
        }

        if ( array_key_exists( 'terms', $params ) ) {
            if ( ! is_array( $params['terms'] ) ) {
                return new WP_Error( 'alify_ai_invalid_terms', 'terms must be an object keyed by taxonomy.', array( 'status' => 400 ) );
            }
            $extras['terms'] = array();
            foreach ( $params['terms'] as $taxonomy => $terms ) {
                $taxonomy = sanitize_key( (string) $taxonomy );
                if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $type, $taxonomy ) ) {
                    return new WP_Error( 'alify_ai_invalid_taxonomy', 'A supplied taxonomy is not attached to this post type.', array( 'status' => 400, 'taxonomy' => $taxonomy ) );
                }
                if ( ! is_array( $terms ) ) {
                    return new WP_Error( 'alify_ai_invalid_terms', 'Each taxonomy value must be an array of existing term IDs or slugs.', array( 'status' => 400, 'taxonomy' => $taxonomy ) );
                }
                $clean = array();
                foreach ( $terms as $term ) {
                    $term_ref = is_numeric( $term ) ? absint( $term ) : sanitize_title( (string) $term );
                    if ( '' === (string) $term_ref || 0 === $term_ref ) {
                        continue;
                    }
                    $exists = term_exists( $term_ref, $taxonomy );
                    if ( ! $exists ) {
                        return new WP_Error( 'alify_ai_term_not_found', 'A supplied taxonomy term does not exist. Create the term explicitly before assigning it.', array( 'status' => 400, 'taxonomy' => $taxonomy, 'term' => $term_ref ) );
                    }
                    $clean[] = $term_ref;
                }
                $extras['terms'][ $taxonomy ] = array_values( array_unique( $clean, SORT_REGULAR ) );
            }
        }
        return $extras;
    }

    private static function apply_prepared_post_extras( int $post_id, array $extras ) {
        if ( array_key_exists( 'featured_media', $extras ) ) {
            $attachment_id = absint( $extras['featured_media'] );
            if ( 0 === $attachment_id ) {
                delete_post_thumbnail( $post_id );
            } elseif ( (int) get_post_thumbnail_id( $post_id ) !== $attachment_id && ! set_post_thumbnail( $post_id, $attachment_id ) ) {
                return new WP_Error( 'alify_ai_featured_media_failed', 'Could not set the featured image.', array( 'status' => 500 ) );
            }
        }
        if ( array_key_exists( 'template', $extras ) ) {
            $updated = update_post_meta( $post_id, '_wp_page_template', (string) $extras['template'] );
            if ( false === $updated && (string) get_post_meta( $post_id, '_wp_page_template', true ) !== (string) $extras['template'] ) {
                return new WP_Error( 'alify_ai_template_failed', 'Could not update the theme template.', array( 'status' => 500 ) );
            }
        }
        foreach ( (array) ( $extras['terms'] ?? array() ) as $taxonomy => $terms ) {
            $result = wp_set_object_terms( $post_id, $terms, $taxonomy, false );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }
        return true;
    }

    private static function restore_serialized_post( array $snapshot ): void {
        $post_id = absint( $snapshot['id'] ?? 0 );
        if ( ! $post_id || ! get_post( $post_id ) ) {
            return;
        }
        $content = is_array( $snapshot['content'] ?? null ) ? (string) ( $snapshot['content']['raw'] ?? '' ) : '';
        $excerpt = is_array( $snapshot['excerpt'] ?? null ) ? (string) ( $snapshot['excerpt']['raw'] ?? '' ) : '';
        $restore = array(
            'ID'           => $post_id,
            'post_title'   => (string) ( $snapshot['title'] ?? '' ),
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => (string) ( $snapshot['status'] ?? 'draft' ),
            'post_name'    => (string) ( $snapshot['slug'] ?? '' ),
            'post_parent'  => absint( $snapshot['parent'] ?? 0 ),
            'menu_order'   => (int) ( $snapshot['menu_order'] ?? 0 ),
            'post_author'  => absint( $snapshot['author'] ?? 0 ),
        );
        if ( ! empty( $snapshot['date_raw'] ) ) {
            $restore['post_date'] = (string) $snapshot['date_raw'];
            $restore['edit_date'] = true;
        }
        if ( ! empty( $snapshot['date_gmt_raw'] ) ) {
            $restore['post_date_gmt'] = (string) $snapshot['date_gmt_raw'];
            $restore['edit_date'] = true;
        }
        wp_update_post( $restore, true );
        update_post_meta( $post_id, '_wp_page_template', (string) ( $snapshot['template'] ?? 'default' ) );
        $featured = absint( $snapshot['featured_media'] ?? 0 );
        $featured ? set_post_thumbnail( $post_id, $featured ) : delete_post_thumbnail( $post_id );
        foreach ( (array) ( $snapshot['terms'] ?? array() ) as $taxonomy => $items ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $ids = array();
            foreach ( (array) $items as $item ) {
                $ids[] = is_array( $item ) ? absint( $item['id'] ?? 0 ) : absint( $item );
            }
            wp_set_object_terms( $post_id, array_filter( $ids ), $taxonomy, false );
        }
        clean_post_cache( $post_id );
    }

    private static function snapshot_post( WP_Post $post ): array {
        $author_id = (int) $post->post_author;
        $template = (string) get_post_meta( $post->ID, '_wp_page_template', true );
        if ( '' === $template ) {
            $template = 'default';
        }
        $data = array(
            'id'             => (int) $post->ID,
            'type'           => $post->post_type,
            'title'          => $post->post_title,
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'date_raw'       => $post->post_date,
            'date_gmt_raw'   => $post->post_date_gmt,
            'author'         => $author_id,
            'template'       => $template,
            'parent'         => (int) $post->post_parent,
            'menu_order'     => (int) $post->menu_order,
            'featured_media' => (int) get_post_thumbnail_id( $post->ID ),
            'content'        => array( 'raw' => $post->post_content ),
            'excerpt'        => array( 'raw' => $post->post_excerpt ),
            'terms'          => array(),
        );
        foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
            $terms = wp_get_post_terms( $post->ID, $taxonomy );
            if ( ! is_wp_error( $terms ) ) {
                $data['terms'][ $taxonomy ] = array_map(
                    static fn( $term ) => array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug ),
                    $terms
                );
            }
        }
        return $data;
    }

    private static function serialize_post( WP_Post $post, bool $full = false ): array {
        $author_id = (int) $post->post_author;
        $template = (string) get_post_meta( $post->ID, '_wp_page_template', true );
        if ( '' === $template ) {
            $template = 'default';
        }
        $data = array(
            'id'             => (int) $post->ID,
            'type'           => $post->post_type,
            'title'          => get_the_title( $post ),
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'link'           => get_permalink( $post ),
            'date'           => get_post_time( DATE_ATOM, false, $post ),
            'date_gmt'       => get_post_time( DATE_ATOM, true, $post ),
            'date_raw'       => $post->post_date,
            'date_gmt_raw'   => $post->post_date_gmt,
            'modified'       => get_post_modified_time( DATE_ATOM, false, $post ),
            'modified_gmt'   => get_post_modified_time( DATE_ATOM, true, $post ),
            'author'         => $author_id,
            'author_name'    => (string) get_the_author_meta( 'display_name', $author_id ),
            'template'       => $template,
            'parent'         => (int) $post->post_parent,
            'menu_order'     => (int) $post->menu_order,
            'featured_media' => (int) get_post_thumbnail_id( $post->ID ),
            'revisions'      => wp_revisions_enabled( $post ),
        );
        if ( $full ) {
            $data['content'] = array( 'raw' => $post->post_content, 'rendered' => apply_filters( 'the_content', $post->post_content ) );
            $data['excerpt'] = array( 'raw' => $post->post_excerpt, 'rendered' => get_the_excerpt( $post ) );
            $data['terms'] = array();
            foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
                $terms = wp_get_post_terms( $post->ID, $taxonomy );
                if ( ! is_wp_error( $terms ) ) {
                    $data['terms'][ $taxonomy ] = array_map(
                        static fn( $term ) => array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug ),
                        $terms
                    );
                }
            }
        }
        return $data;
    }

    public static function post_types(): WP_REST_Response {
        return new WP_REST_Response( ALIFY_AI_Structure::list_post_types() );
    }

    public static function get_post_type( WP_REST_Request $request ) {
        $result = ALIFY_AI_Structure::get_post_type( sanitize_key( (string) $request['key'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function create_post_type( WP_REST_Request $request ) {
        $result = ALIFY_AI_Structure::upsert_post_type( self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
    }

    public static function update_post_type( WP_REST_Request $request ) {
        $result = ALIFY_AI_Structure::upsert_post_type( self::params( $request ), sanitize_key( (string) $request['key'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function delete_post_type( WP_REST_Request $request ) {
        $result = ALIFY_AI_Structure::delete_post_type( sanitize_key( (string) $request['key'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function taxonomies(): WP_REST_Response {
        return new WP_REST_Response( ALIFY_AI_Structure::list_taxonomies() );
    }

    public static function get_taxonomy( WP_REST_Request $request ) {
        $result = ALIFY_AI_Structure::get_taxonomy( sanitize_key( (string) $request['key'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function create_taxonomy( WP_REST_Request $request ) {
        $result = ALIFY_AI_Structure::upsert_taxonomy( self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
    }

    public static function update_taxonomy( WP_REST_Request $request ) {
        $result = ALIFY_AI_Structure::upsert_taxonomy( self::params( $request ), sanitize_key( (string) $request['key'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function delete_taxonomy( WP_REST_Request $request ) {
        $result = ALIFY_AI_Structure::delete_taxonomy( sanitize_key( (string) $request['key'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function attach_taxonomy_object_type( WP_REST_Request $request ) {
        $result = ALIFY_AI_Structure::attach_taxonomy_object_type( sanitize_key( (string) $request['key'] ), sanitize_key( (string) $request['object_type'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function detach_taxonomy_object_type( WP_REST_Request $request ) {
        $result = ALIFY_AI_Structure::detach_taxonomy_object_type( sanitize_key( (string) $request['key'] ), sanitize_key( (string) $request['object_type'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function terms( WP_REST_Request $request ) {
        $parent = null;
        if ( null !== $request->get_param( 'parent' ) && '' !== $request->get_param( 'parent' ) ) $parent = absint( $request->get_param( 'parent' ) );
        $result = ALIFY_AI_Structure::list_terms(
            sanitize_key( (string) $request['taxonomy'] ),
            (int) ( $request->get_param( 'per_page' ) ?: 100 ),
            (string) $request->get_param( 'search' ),
            (int) ( $request->get_param( 'page' ) ?: 1 ),
            $parent
        );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function get_term( WP_REST_Request $request ) {
        $result = ALIFY_AI_Structure::get_term( sanitize_key( (string) $request['taxonomy'] ), absint( $request['id'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function create_term( WP_REST_Request $request ) {
        $result = ALIFY_AI_Structure::create_term( sanitize_key( (string) $request['taxonomy'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
    }

    public static function update_term( WP_REST_Request $request ) {
        $result = ALIFY_AI_Structure::update_term( sanitize_key( (string) $request['taxonomy'] ), absint( $request['id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function delete_term( WP_REST_Request $request ) {
        $result = ALIFY_AI_Structure::delete_term( sanitize_key( (string) $request['taxonomy'] ), absint( $request['id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function acf_status(): WP_REST_Response {
        return new WP_REST_Response( ALIFY_AI_ACF::status() );
    }

    public static function acf_groups() {
        if ( ! ALIFY_AI_ACF::available() ) {
            return new WP_Error( 'alify_ai_acf_unavailable', 'Advanced Custom Fields is not active.', array( 'status' => 409 ) );
        }
        return new WP_REST_Response( ALIFY_AI_ACF::list_groups() );
    }

    public static function create_acf_group( WP_REST_Request $request ) {
        $result = ALIFY_AI_ACF::create_group( self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
    }

    public static function get_acf_group( WP_REST_Request $request ) {
        $result = ALIFY_AI_ACF::get_group( sanitize_text_field( (string) $request['group_key'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function update_acf_group( WP_REST_Request $request ) {
        $result = ALIFY_AI_ACF::update_group( sanitize_text_field( (string) $request['group_key'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function delete_acf_group( WP_REST_Request $request ) {
        $result = ALIFY_AI_ACF::delete_group( sanitize_text_field( (string) $request['group_key'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function acf_fields( WP_REST_Request $request ) {
        if ( ! ALIFY_AI_ACF::available() ) {
            return new WP_Error( 'alify_ai_acf_unavailable', 'Advanced Custom Fields is not active.', array( 'status' => 409 ) );
        }
        return new WP_REST_Response( ALIFY_AI_ACF::list_fields( sanitize_text_field( (string) $request['group_key'] ) ) );
    }

    public static function create_acf_field( WP_REST_Request $request ) {
        $result = ALIFY_AI_ACF::create_field( sanitize_text_field( (string) $request['group_key'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
    }

    public static function get_acf_field( WP_REST_Request $request ) {
        $result = ALIFY_AI_ACF::get_field( sanitize_text_field( (string) $request['field_key'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function update_acf_field( WP_REST_Request $request ) {
        $result = ALIFY_AI_ACF::update_field_definition( sanitize_text_field( (string) $request['field_key'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function delete_acf_field( WP_REST_Request $request ) {
        $result = ALIFY_AI_ACF::delete_field_definition( sanitize_text_field( (string) $request['field_key'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function acf_values( WP_REST_Request $request ) {
        if ( ! ALIFY_AI_ACF::available() ) {
            return new WP_Error( 'alify_ai_acf_unavailable', 'Advanced Custom Fields is not active.', array( 'status' => 409 ) );
        }
        $post_id = absint( $request['post_id'] );
        if ( ! get_post( $post_id ) ) {
            return new WP_Error( 'alify_ai_not_found', 'Post not found.', array( 'status' => 404 ) );
        }
        return new WP_REST_Response( ALIFY_AI_ACF::get_values( $post_id ) );
    }

    public static function update_acf_values( WP_REST_Request $request ) {
        $params = self::params( $request );
        $values = isset( $params['values'] ) && is_array( $params['values'] ) ? $params['values'] : $params;
        $result = ALIFY_AI_ACF::update_values( absint( $request['post_id'] ), $values );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function acf_option_pages( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( ALIFY_AI_ACF::list_option_pages() );
    }

    public static function acf_option_values( WP_REST_Request $request ) {
        $result = ALIFY_AI_ACF::get_option_values( sanitize_key( (string) $request['page'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function update_acf_option_values( WP_REST_Request $request ) {
        $params = self::params( $request );
        $values = isset( $params['values'] ) && is_array( $params['values'] ) ? $params['values'] : $params;
        $result = ALIFY_AI_ACF::update_option_values( sanitize_key( (string) $request['page'] ), $values );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function media( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( ALIFY_AI_Media::list_media( (int) ( $request->get_param( 'per_page' ) ?: 20 ), (string) $request->get_param( 'search' ), (string) $request->get_param( 'mime_type' ), (int) ( $request->get_param( 'page' ) ?: 1 ), null === $request->get_param( 'parent' ) ? -1 : (int) $request->get_param( 'parent' ) ) );
    }

    public static function media_limits(): WP_REST_Response { return new WP_REST_Response( ALIFY_AI_Media::get_upload_limits() ); }

    public static function get_media( WP_REST_Request $request ) {
        $result = ALIFY_AI_Media::get_media( absint( $request['id'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function update_media( WP_REST_Request $request ) {
        $result = ALIFY_AI_Media::update_media( absint( $request['id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function upload_media( WP_REST_Request $request ) {
        $files = $request->get_file_params();
        $file  = is_array( $files ) && isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : array();
        $params = (array) $request->get_params(); unset( $params['file'] );
        $result = ALIFY_AI_Media::upload( $file, $params );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
    }

    public static function upload_media_json( WP_REST_Request $request ) {
        $result = ALIFY_AI_Media::upload_base64( self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
    }

    public static function delete_media( WP_REST_Request $request ) {
        $result = ALIFY_AI_Media::delete_media( absint( $request['id'] ), array_merge( (array) $request->get_query_params(), self::params( $request ) ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function restore_media( WP_REST_Request $request ) {
        $result = ALIFY_AI_Media::restore_media( absint( $request['id'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function media_usages( WP_REST_Request $request ): WP_REST_Response { return new WP_REST_Response( ALIFY_AI_Media::usages( absint( $request['id'] ) ) ); }

    public static function replace_media_file( WP_REST_Request $request ) {
        $files = $request->get_file_params(); $file = is_array( $files ) && isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : array();
        $params = (array) $request->get_params(); unset( $params['file'] );
        $result = ALIFY_AI_Media::replace_file( absint( $request['id'] ), $file, $params );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function replace_media_file_json( WP_REST_Request $request ) {
        $result = ALIFY_AI_Media::replace_file_base64( absint( $request['id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function edit_media_image( WP_REST_Request $request ) {
        $result = ALIFY_AI_Media::edit_image( absint( $request['id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function regenerate_media_metadata( WP_REST_Request $request ) {
        $result = ALIFY_AI_Media::regenerate_metadata( absint( $request['id'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function menus(): WP_REST_Response {
        return new WP_REST_Response( ALIFY_AI_Menus::list_menus() );
    }

    public static function menu_locations(): WP_REST_Response {
        return new WP_REST_Response( ALIFY_AI_Menus::locations() );
    }

    public static function get_menu( WP_REST_Request $request ) {
        $result = ALIFY_AI_Menus::get_menu( absint( $request['id'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function create_menu( WP_REST_Request $request ) {
        $result = ALIFY_AI_Menus::create_menu( self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
    }

    public static function update_menu( WP_REST_Request $request ) {
        $result = ALIFY_AI_Menus::update_menu( absint( $request['id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function delete_menu( WP_REST_Request $request ) {
        $result = ALIFY_AI_Menus::delete_menu( absint( $request['id'] ), array_merge( (array) $request->get_query_params(), self::params( $request ) ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function assign_menu_location( WP_REST_Request $request ) {
        $params = self::params( $request );
        $result = ALIFY_AI_Menus::assign_location( sanitize_key( (string) $request['location'] ), absint( $params['menu_id'] ?? 0 ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function unassign_menu_location( WP_REST_Request $request ) {
        $result = ALIFY_AI_Menus::unassign_location( sanitize_key( (string) $request['location'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function menu_items( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( ALIFY_AI_Menus::list_items( absint( $request['id'] ) ) );
    }

    public static function create_menu_item( WP_REST_Request $request ) {
        $result = ALIFY_AI_Menus::create_item( absint( $request['id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
    }

    public static function update_menu_item( WP_REST_Request $request ) {
        $result = ALIFY_AI_Menus::update_item( absint( $request['id'] ), absint( $request['item_id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function delete_menu_item( WP_REST_Request $request ) {
        $result = ALIFY_AI_Menus::delete_item( absint( $request['id'] ), absint( $request['item_id'] ), array_merge( (array) $request->get_query_params(), self::params( $request ) ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function reorder_menu_items( WP_REST_Request $request ) {
        $result = ALIFY_AI_Menus::reorder_items( absint( $request['id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function gutenberg_document( WP_REST_Request $request ) {
        $result = ALIFY_AI_Gutenberg::document( absint( $request['post_id'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function gutenberg_preview( WP_REST_Request $request ) {
        $result = ALIFY_AI_Gutenberg::preview( absint( $request['post_id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'Gutenberg preview created. No page content has been changed.', 'approval' => $result ), 201 );
    }


    public static function gutenberg_status(): WP_REST_Response { return new WP_REST_Response( ALIFY_AI_Gutenberg::status() ); }
    public static function gutenberg_block_types( WP_REST_Request $request ): WP_REST_Response { return new WP_REST_Response( ALIFY_AI_Gutenberg::list_block_types( (array) $request->get_params() ) ); }
    public static function gutenberg_block_type( WP_REST_Request $request ) { $result=ALIFY_AI_Gutenberg::get_block_type( (string) $request->get_param('name') ); return is_wp_error($result)?$result:new WP_REST_Response($result); }
    public static function gutenberg_validate( WP_REST_Request $request ) { $result=ALIFY_AI_Gutenberg::validate_content( self::params($request) ); return is_wp_error($result)?$result:new WP_REST_Response($result); }
    public static function gutenberg_patterns( WP_REST_Request $request ): WP_REST_Response { return new WP_REST_Response( ALIFY_AI_Gutenberg::list_patterns( (array) $request->get_params() ) ); }
    public static function gutenberg_registered_pattern( WP_REST_Request $request ) { $result=ALIFY_AI_Gutenberg::get_registered_pattern( (string) $request->get_param('name') ); return is_wp_error($result)?$result:new WP_REST_Response($result); }
    public static function gutenberg_user_pattern( WP_REST_Request $request ) { $result=ALIFY_AI_Gutenberg::get_user_pattern( absint($request['id']) ); return is_wp_error($result)?$result:new WP_REST_Response($result); }
    public static function gutenberg_pattern_create_preview( WP_REST_Request $request ) { $result=ALIFY_AI_Gutenberg::preview_pattern(0,self::params($request)); return is_wp_error($result)?$result:new WP_REST_Response(array('message'=>'Pattern proposal created. No pattern change has been applied yet.','approval'=>$result),201); }
    public static function gutenberg_pattern_preview( WP_REST_Request $request ) { $result=ALIFY_AI_Gutenberg::preview_pattern(absint($request['id']),self::params($request)); return is_wp_error($result)?$result:new WP_REST_Response(array('message'=>'Pattern proposal created. No pattern change has been applied yet.','approval'=>$result),201); }
    public static function gutenberg_templates( WP_REST_Request $request ) { $result=ALIFY_AI_Gutenberg::list_templates((array)$request->get_params()); return is_wp_error($result)?$result:new WP_REST_Response($result); }
    public static function gutenberg_template( WP_REST_Request $request ) { $result=ALIFY_AI_Gutenberg::get_template((string)$request->get_param('id'),(string)($request->get_param('type')?:'wp_template')); return is_wp_error($result)?$result:new WP_REST_Response($result); }
    public static function gutenberg_template_preview( WP_REST_Request $request ) { $result=ALIFY_AI_Gutenberg::preview_template(self::params($request)); return is_wp_error($result)?$result:new WP_REST_Response(array('message'=>'Template proposal created. No template change has been applied yet.','approval'=>$result),201); }

    public static function elementor_status(): WP_REST_Response {
        return new WP_REST_Response( ALIFY_AI_Elementor::status() );
    }

    public static function elementor_breakpoints(): WP_REST_Response { return new WP_REST_Response( ALIFY_AI_Elementor::breakpoints() ); }
    public static function elementor_widgets( WP_REST_Request $request ): WP_REST_Response { return new WP_REST_Response( ALIFY_AI_Elementor::widgets( (array) $request->get_params() ) ); }
    public static function elementor_widget( WP_REST_Request $request ) { $result = ALIFY_AI_Elementor::widget( (string) $request['name'] ); return is_wp_error( $result ) ? $result : new WP_REST_Response( $result ); }
    public static function elementor_templates( WP_REST_Request $request ) { $result = ALIFY_AI_Elementor::list_templates( (array) $request->get_params() ); return is_wp_error( $result ) ? $result : new WP_REST_Response( $result ); }
    public static function elementor_template( WP_REST_Request $request ) { $result = ALIFY_AI_Elementor::get_template( absint( $request['id'] ) ); return is_wp_error( $result ) ? $result : new WP_REST_Response( $result ); }
    public static function elementor_template_create_preview( WP_REST_Request $request ) { $result = ALIFY_AI_Elementor::preview_template( 0, self::params( $request ) ); return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'Elementor template proposal created. No template has been changed yet.', 'approval' => $result ), 201 ); }
    public static function elementor_template_preview( WP_REST_Request $request ) { $result = ALIFY_AI_Elementor::preview_template( absint( $request['id'] ), self::params( $request ) ); return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'Elementor template proposal created. No template has been changed yet.', 'approval' => $result ), 201 ); }
    public static function elementor_globals() { $result = ALIFY_AI_Elementor::globals(); return is_wp_error( $result ) ? $result : new WP_REST_Response( $result ); }
    public static function elementor_globals_preview( WP_REST_Request $request ) { $result = ALIFY_AI_Elementor::preview_globals( self::params( $request ) ); return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'Elementor global-style proposal created. No Kit settings have been changed yet.', 'approval' => $result ), 201 ); }
    public static function elementor_validate( WP_REST_Request $request ) { $result = ALIFY_AI_Elementor::validate_document( absint( $request['post_id'] ) ); return is_wp_error( $result ) ? $result : new WP_REST_Response( $result ); }

    public static function elementor_document( WP_REST_Request $request ) {
        $result = ALIFY_AI_Elementor::document( absint( $request['post_id'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function elementor_preview( WP_REST_Request $request ) {
        $result = ALIFY_AI_Elementor::preview( absint( $request['post_id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'Elementor preview created. No page content has been changed.', 'approval' => $result ), 201 );
    }

    public static function seo_status(): WP_REST_Response {
        return new WP_REST_Response( ALIFY_AI_SEO::status() );
    }

    public static function seo_post( WP_REST_Request $request ) {
        $result = ALIFY_AI_SEO::get_post( absint( $request['id'] ), (string) ( $request->get_param( 'provider' ) ?: 'auto' ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function seo_post_preview( WP_REST_Request $request ) {
        $result = ALIFY_AI_SEO::preview_post_update( absint( $request['id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'SEO proposal created. No SEO metadata has been changed yet.', 'approval' => $result ), 201 );
    }

    public static function seo_audit( WP_REST_Request $request ) {
        $result = ALIFY_AI_SEO::audit( (array) $request->get_params() );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_status(): WP_REST_Response {
        return new WP_REST_Response( ALIFY_AI_WooCommerce::status() );
    }

    public static function woocommerce_products( WP_REST_Request $request ) {
        $result = ALIFY_AI_WooCommerce::list_products( (array) $request->get_params() );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_product( WP_REST_Request $request ) {
        $result = ALIFY_AI_WooCommerce::get_product( absint( $request['id'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_product_create_preview( WP_REST_Request $request ) {
        $result = ALIFY_AI_WooCommerce::preview_create( self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'WooCommerce product creation preview created. No product has been created yet.', 'approval' => $result ), 201 );
    }

    public static function woocommerce_product_update_preview( WP_REST_Request $request ) {
        $result = ALIFY_AI_WooCommerce::preview_update( absint( $request['id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'WooCommerce product update preview created. No product has been changed yet.', 'approval' => $result ), 201 );
    }

    public static function woocommerce_variations( WP_REST_Request $request ) {
        $result = ALIFY_AI_WooCommerce::list_variations( absint( $request['id'] ), (array) $request->get_params() );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_variation( WP_REST_Request $request ) {
        $result = ALIFY_AI_WooCommerce::get_variation( absint( $request['id'] ), absint( $request['variation_id'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_attributes() {
        $result = ALIFY_AI_WooCommerce::list_attributes();
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_attribute( WP_REST_Request $request ) {
        $result = ALIFY_AI_WooCommerce::get_attribute( absint( $request['id'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_attribute_terms( WP_REST_Request $request ) {
        $result = ALIFY_AI_WooCommerce::list_attribute_terms( absint( $request['id'] ), (array) $request->get_params() );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_attribute_term( WP_REST_Request $request ) {
        $result = ALIFY_AI_WooCommerce::get_attribute_term( absint( $request['id'] ), absint( $request['term_id'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_shipping_classes() {
        $result = ALIFY_AI_WooCommerce::list_shipping_classes();
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_shipping_class( WP_REST_Request $request ) {
        $result = ALIFY_AI_WooCommerce::get_shipping_class( absint( $request['id'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_tax_classes() {
        $result = ALIFY_AI_WooCommerce::list_tax_classes();
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_tax_class( WP_REST_Request $request ) {
        $result = ALIFY_AI_WooCommerce::get_tax_class( sanitize_title( (string) $request['slug'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_catalog_preview( WP_REST_Request $request ) {
        $p = self::params( $request );
        $op = (string) ( $p['operation'] ?? '' );
        switch ( $op ) {
            case 'product.trash': case 'product.restore':
                $result = ALIFY_AI_WooCommerce::preview_product_lifecycle( absint( $p['product_id'] ?? 0 ), substr( $op, 8 ) ); break;
            case 'attribute.create':
                $result = ALIFY_AI_WooCommerce::preview_attribute_create( (array) ( $p['data'] ?? array() ) ); break;
            case 'attribute.update':
                $result = ALIFY_AI_WooCommerce::preview_attribute_update( absint( $p['attribute_id'] ?? 0 ), (array) ( $p['data'] ?? array() ) ); break;
            case 'attribute.delete':
                $result = ALIFY_AI_WooCommerce::preview_attribute_delete( absint( $p['attribute_id'] ?? 0 ), ! empty( $p['confirm_delete'] ), ! empty( $p['confirm_term_deletion'] ) ); break;
            case 'attribute_term.create':
                $result = ALIFY_AI_WooCommerce::preview_attribute_term_create( absint( $p['attribute_id'] ?? 0 ), (array) ( $p['data'] ?? array() ) ); break;
            case 'attribute_term.update':
                $result = ALIFY_AI_WooCommerce::preview_attribute_term_update( absint( $p['attribute_id'] ?? 0 ), absint( $p['term_id'] ?? 0 ), (array) ( $p['data'] ?? array() ) ); break;
            case 'attribute_term.delete':
                $result = ALIFY_AI_WooCommerce::preview_attribute_term_delete( absint( $p['attribute_id'] ?? 0 ), absint( $p['term_id'] ?? 0 ), ! empty( $p['confirm_delete'] ) ); break;
            case 'variation.create':
                $result = ALIFY_AI_WooCommerce::preview_variation_create( absint( $p['product_id'] ?? 0 ), (array) ( $p['data'] ?? array() ) ); break;
            case 'variation.update':
                $result = ALIFY_AI_WooCommerce::preview_variation_update( absint( $p['product_id'] ?? 0 ), absint( $p['variation_id'] ?? 0 ), (array) ( $p['data'] ?? array() ) ); break;
            case 'variation.trash': case 'variation.restore':
                $result = ALIFY_AI_WooCommerce::preview_variation_lifecycle( absint( $p['product_id'] ?? 0 ), absint( $p['variation_id'] ?? 0 ), substr( $op, 10 ) ); break;
            case 'shipping_class.create':
                $result = ALIFY_AI_WooCommerce::preview_shipping_class_create( (array) ( $p['data'] ?? array() ) ); break;
            case 'shipping_class.update':
                $result = ALIFY_AI_WooCommerce::preview_shipping_class_update( absint( $p['term_id'] ?? 0 ), (array) ( $p['data'] ?? array() ) ); break;
            case 'shipping_class.delete':
                $result = ALIFY_AI_WooCommerce::preview_shipping_class_delete( absint( $p['term_id'] ?? 0 ), ! empty( $p['confirm_delete'] ), ! empty( $p['confirm_in_use'] ) ); break;
            case 'tax_class.create':
                $result = ALIFY_AI_WooCommerce::preview_tax_class_create( (array) ( $p['data'] ?? array() ) ); break;
            case 'tax_class.delete':
                $result = ALIFY_AI_WooCommerce::preview_tax_class_delete( (string) ( $p['slug'] ?? '' ), ! empty( $p['confirm_delete'] ), ! empty( $p['confirm_in_use'] ) ); break;
            default:
                $result = new WP_Error( 'alify_ai_invalid_catalog_operation', 'Unsupported WooCommerce catalog preview operation.', array( 'status' => 400 ) );
        }
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'WooCommerce catalog proposal created. No catalog changes have been applied yet.', 'approval' => $result ), 201 );
    }

    public static function woocommerce_order_statuses() {
        return new WP_REST_Response( ALIFY_AI_Orders::list_statuses() );
    }

    public static function woocommerce_orders( WP_REST_Request $request ) {
        $result = ALIFY_AI_Orders::list_orders( (array) $request->get_params() );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_order( WP_REST_Request $request ) {
        $result = ALIFY_AI_Orders::get_order( absint( $request['id'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_order_create_preview( WP_REST_Request $request ) {
        $result = ALIFY_AI_Orders::preview_create( self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'WooCommerce order creation proposal created. No order has been created yet.', 'approval' => $result ), 201 );
    }

    public static function woocommerce_order_update_preview( WP_REST_Request $request ) {
        $result = ALIFY_AI_Orders::preview_update( absint( $request['id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'WooCommerce order update proposal created. No order has been changed yet.', 'approval' => $result ), 201 );
    }

    public static function woocommerce_order_item_create_preview( WP_REST_Request $request ) {
        $result = ALIFY_AI_Orders::preview_item_create( absint( $request['id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'Order line-item creation proposal created.', 'approval' => $result ), 201 );
    }

    public static function woocommerce_order_item_preview( WP_REST_Request $request ) {
        $params = self::params( $request );
        $action = sanitize_key( (string) ( $params['action'] ?? 'update' ) );
        if ( 'delete' === $action ) $result = ALIFY_AI_Orders::preview_item_delete( absint( $request['id'] ), absint( $request['item_id'] ), $params );
        else $result = ALIFY_AI_Orders::preview_item_update( absint( $request['id'] ), absint( $request['item_id'] ), $params );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'Order line-item proposal created.', 'approval' => $result ), 201 );
    }

    public static function woocommerce_order_notes( WP_REST_Request $request ) {
        $result = ALIFY_AI_Orders::list_notes( absint( $request['id'] ), (array) $request->get_params() );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function woocommerce_order_note_create_preview( WP_REST_Request $request ) {
        $result = ALIFY_AI_Orders::preview_note_create( absint( $request['id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'Order note proposal created.', 'approval' => $result ), 201 );
    }

    public static function woocommerce_order_note_delete_preview( WP_REST_Request $request ) {
        $result = ALIFY_AI_Orders::preview_note_delete( absint( $request['id'] ), absint( $request['note_id'] ), self::params( $request ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'Order note deletion proposal created.', 'approval' => $result ), 201 );
    }

    public static function transaction_preview( WP_REST_Request $request ) {
        $params = self::params( $request );
        foreach ( ALIFY_AI_Transactions::required_scopes( (array) ( $params['operations'] ?? array() ) ) as $scope ) {
            $auth = ALIFY_AI_Auth::authorize( $request, $scope );
            if ( is_wp_error( $auth ) ) { return $auth; }
        }
        $result = ALIFY_AI_Transactions::preview( $params );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'Transaction preview created. No transaction changes have been applied yet.', 'approval' => $result ), 201 );
    }

    public static function approvals( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( ALIFY_AI_Approvals::recent( (int) ( $request->get_param( 'limit' ) ?: 30 ), (string) $request->get_param( 'status' ) ) );
    }

    public static function get_approval( WP_REST_Request $request ) {
        $approval = ALIFY_AI_Approvals::get( absint( $request['id'] ) );
        return $approval ? new WP_REST_Response( $approval ) : new WP_Error( 'alify_ai_approval_not_found', 'Approval proposal not found.', array( 'status' => 404 ) );
    }

    public static function apply_approval( WP_REST_Request $request ) {
        $approval = ALIFY_AI_Approvals::get( absint( $request['id'] ) );
        if ( ! $approval ) {
            return new WP_Error( 'alify_ai_approval_not_found', 'Approval proposal not found.', array( 'status' => 404 ) );
        }
        $scope = self::approval_scope( $approval['kind'] );
        $auth = ALIFY_AI_Auth::authorize( $request, $scope );
        if ( is_wp_error( $auth ) ) {
            return $auth;
        }
        if ( 'transaction' === $approval['kind'] ) {
            foreach ( ALIFY_AI_Transactions::required_scopes( (array) ( $approval['request']['operations'] ?? array() ) ) as $underlying_scope ) {
                $auth = ALIFY_AI_Auth::authorize( $request, $underlying_scope );
                if ( is_wp_error( $auth ) ) { return $auth; }
            }
        }
        $result = ALIFY_AI_Approvals::apply( absint( $request['id'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
    }

    public static function cancel_approval( WP_REST_Request $request ) {
        $approval = ALIFY_AI_Approvals::get( absint( $request['id'] ) );
        if ( ! $approval ) {
            return new WP_Error( 'alify_ai_approval_not_found', 'Approval proposal not found.', array( 'status' => 404 ) );
        }
        $scope = self::approval_scope( $approval['kind'] );
        $auth = ALIFY_AI_Auth::authorize( $request, $scope );
        if ( is_wp_error( $auth ) ) {
            return $auth;
        }
        $result = ALIFY_AI_Approvals::cancel( absint( $request['id'] ) );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'Proposal cancelled.', 'approval' => $result ) );
    }

    private static function approval_scope( string $kind ): string {
        if ( 'woocommerce_order' === $kind ) { return 'orders'; }
        if ( 'seo' === $kind ) { return 'seo'; }
        if ( str_starts_with( $kind, 'woocommerce_' ) ) { return 'commerce'; }
        if ( 'transaction' === $kind ) { return 'transactions'; }
        if ( 'code' === $kind ) { return 'code_write'; }
        return 'design';
    }

    public static function activity( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( ALIFY_AI_Audit::recent( (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
    }

    public static function rollback( WP_REST_Request $request ) {
        $entry = ALIFY_AI_Audit::get( absint( $request['id'] ) );
        if ( ! $entry ) {
            return new WP_Error( 'alify_ai_activity_not_found', 'Activity entry not found.', array( 'status' => 404 ) );
        }
        $scope = 'content';
        if ( in_array( $entry['action'], array( 'create_post_type', 'update_post_type', 'delete_post_type', 'rollback_create_post_type', 'rollback_update_post_type', 'rollback_delete_post_type', 'create_taxonomy', 'update_taxonomy', 'delete_taxonomy', 'rollback_create_taxonomy', 'rollback_update_taxonomy', 'rollback_delete_taxonomy', 'create_term', 'update_term', 'delete_term', 'rollback_create_term', 'rollback_update_term', 'rollback_delete_term' ), true ) ) { $scope = 'structure'; }
        if ( in_array( $entry['action'], array( 'update_gutenberg', 'update_elementor', 'create_elementor_template', 'update_elementor_template', 'delete_elementor_template', 'update_elementor_globals', 'rollback_create_elementor_template', 'rollback_update_elementor_template', 'rollback_delete_elementor_template', 'rollback_update_elementor_globals', 'create_gutenberg_pattern','update_gutenberg_pattern','delete_gutenberg_pattern','restore_gutenberg_pattern','rollback_create_gutenberg_pattern','rollback_update_gutenberg_pattern','rollback_delete_gutenberg_pattern','rollback_restore_gutenberg_pattern','create_gutenberg_template','update_gutenberg_template','delete_gutenberg_template','rollback_create_gutenberg_template','rollback_update_gutenberg_template','rollback_delete_gutenberg_template' ), true ) ) { $scope = 'design'; }
        if ( in_array( $entry['action'], array( 'upload_media', 'update_media', 'trash_media', 'restore_media', 'delete_media', 'replace_media_file', 'edit_media_image', 'regenerate_media_metadata', 'rollback_upload_media', 'rollback_update_media', 'rollback_trash_media', 'rollback_restore_media', 'rollback_delete_media', 'rollback_replace_media_file', 'rollback_edit_media_image', 'rollback_regenerate_media_metadata' ), true ) ) { $scope = 'media'; }
        if ( in_array( $entry['action'], array( 'update_acf', 'update_acf_options', 'rollback_update_acf_options', 'create_acf_group', 'update_acf_group', 'delete_acf_group', 'create_acf_field', 'update_acf_field', 'delete_acf_field', 'rollback_create_acf_group', 'rollback_update_acf_group', 'rollback_delete_acf_group', 'rollback_create_acf_field', 'rollback_update_acf_field', 'rollback_delete_acf_field' ), true ) ) { $scope = 'acf'; }
        if ( in_array( $entry['action'], array( 'create_menu', 'update_menu', 'delete_menu', 'create_menu_item', 'update_menu_item', 'delete_menu_item', 'reorder_menu_items', 'assign_menu_location', 'unassign_menu_location', 'rollback_create_menu', 'rollback_update_menu', 'rollback_delete_menu', 'rollback_create_menu_item', 'rollback_update_menu_item', 'rollback_delete_menu_item', 'rollback_reorder_menu_items', 'rollback_assign_menu_location', 'rollback_unassign_menu_location' ), true ) ) { $scope = 'menus'; }
        if ( str_starts_with( (string) $entry['action'], 'woocommerce_order_' ) || str_starts_with( (string) $entry['action'], 'rollback_woocommerce_order_' ) ) { $scope = 'orders'; }
        elseif ( in_array( $entry['action'], array( 'update_product', 'create_product' ), true ) || str_starts_with( (string) $entry['action'], 'woocommerce_' ) ) { $scope = 'commerce'; }
        if ( in_array( $entry['action'], array( 'update_seo' ), true ) ) { $scope = 'seo'; }
        if ( 'transaction' === $entry['action'] ) { $scope = 'transactions'; }
        if ( in_array( $entry['action'], array( 'update_source_file', 'rollback_update_source_file' ), true ) ) { $scope = 'code_write'; }
        $auth = ALIFY_AI_Auth::authorize( $request, $scope );
        if ( is_wp_error( $auth ) ) { return $auth; }
        if ( 'create' === $entry['action'] && post_type_exists( $entry['object_type'] ) ) {
            $post = get_post( $entry['object_id'] );
            if ( ! $post ) {
                return new WP_Error( 'alify_ai_not_found', 'Created content no longer exists.', array( 'status' => 404 ) );
            }
            if ( ! EMPTY_TRASH_DAYS ) {
                return new WP_Error( 'alify_ai_rollback_conflict', 'WordPress Trash is disabled, so a create operation cannot be safely rolled back.', array( 'status' => 409 ) );
            }
            $current = self::snapshot_post( $post );
            if ( ! wp_trash_post( $entry['object_id'] ) ) {
                return new WP_Error( 'alify_ai_rollback_failed', 'Could not move the created content to Trash.', array( 'status' => 500 ) );
            }
            $after_post = get_post( $entry['object_id'] );
            if ( ! $after_post ) {
                return new WP_Error( 'alify_ai_post_missing_after_write', 'Rolled-back content could not be reloaded.', array( 'status' => 500 ) );
            }
            $after = self::snapshot_post( $after_post );
            $log_id = ALIFY_AI_Audit::log( 'rollback_create', $entry['object_type'], $entry['object_id'], $current, $after );
            if ( ! $log_id ) {
                wp_untrash_post( $entry['object_id'] );
                return new WP_Error( 'alify_ai_audit_failed', 'Create rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) );
            }
            return new WP_REST_Response( array( 'message' => 'Created content moved to Trash.', 'activity_id' => $log_id, 'item' => self::serialize_post( $after_post, true ) ) );
        }
        if ( in_array( $entry['action'], array( 'update', 'restore_revision' ), true ) && $entry['before_json'] && post_type_exists( $entry['object_type'] ) ) {
            $before = $entry['before_json'];
            $post = get_post( $entry['object_id'] );
            if ( ! $post ) {
                return new WP_Error( 'alify_ai_not_found', 'Original content no longer exists.', array( 'status' => 404 ) );
            }
            $current = self::snapshot_post( $post );
            self::restore_serialized_post( $before );
            $restored = get_post( $entry['object_id'] );
            if ( ! $restored ) {
                return new WP_Error( 'alify_ai_rollback_failed', 'Content could not be restored.', array( 'status' => 500 ) );
            }
            $after = self::snapshot_post( $restored );
            $log_id = ALIFY_AI_Audit::log( 'rollback_' . $entry['action'], $entry['object_type'], $entry['object_id'], $current, $after );
            if ( ! $log_id ) {
                return new WP_Error( 'alify_ai_audit_failed', 'Content was restored but the rollback activity could not be logged.', array( 'status' => 500 ) );
            }
            return new WP_REST_Response( array( 'message' => 'Content change rolled back.', 'activity_id' => $log_id, 'item' => self::serialize_post( $restored, true ) ) );
        }
        if ( 'trash' === $entry['action'] && is_array( $entry['before_json'] ) ) {
            $post = get_post( $entry['object_id'] );
            if ( ! $post || 'trash' !== $post->post_status ) {
                return new WP_Error( 'alify_ai_rollback_conflict', 'The content is no longer in Trash.', array( 'status' => 409 ) );
            }
            $current = self::snapshot_post( $post );
            if ( ! wp_untrash_post( $entry['object_id'] ) ) {
                return new WP_Error( 'alify_ai_rollback_failed', 'Could not restore the content from Trash.', array( 'status' => 500 ) );
            }
            self::restore_serialized_post( $entry['before_json'] );
            $after_post = get_post( $entry['object_id'] );
            if ( ! $after_post ) {
                return new WP_Error( 'alify_ai_post_missing_after_write', 'Restored content could not be reloaded.', array( 'status' => 500 ) );
            }
            $after = self::snapshot_post( $after_post );
            $log_id = ALIFY_AI_Audit::log( 'rollback_trash', $entry['object_type'], $entry['object_id'], $current, $after );
            if ( ! $log_id ) {
                return new WP_Error( 'alify_ai_audit_failed', 'Trash rollback succeeded but could not be logged.', array( 'status' => 500 ) );
            }
            return new WP_REST_Response( array( 'message' => 'Trash operation rolled back.', 'activity_id' => $log_id, 'item' => self::serialize_post( $after_post, true ) ) );
        }
        if ( 'untrash' === $entry['action'] && is_array( $entry['before_json'] ) ) {
            $post = get_post( $entry['object_id'] );
            if ( ! $post ) {
                return new WP_Error( 'alify_ai_not_found', 'Content no longer exists.', array( 'status' => 404 ) );
            }
            $current = self::snapshot_post( $post );
            if ( ! EMPTY_TRASH_DAYS ) {
                return new WP_Error( 'alify_ai_rollback_conflict', 'WordPress Trash is disabled, so this restore cannot be rolled back safely.', array( 'status' => 409 ) );
            }
            if ( ! wp_trash_post( $entry['object_id'] ) ) {
                return new WP_Error( 'alify_ai_rollback_failed', 'Could not move the content back to Trash.', array( 'status' => 500 ) );
            }
            $after_post = get_post( $entry['object_id'] );
            if ( ! $after_post ) {
                return new WP_Error( 'alify_ai_post_missing_after_write', 'Trashed content could not be reloaded.', array( 'status' => 500 ) );
            }
            $after = self::snapshot_post( $after_post );
            $log_id = ALIFY_AI_Audit::log( 'rollback_untrash', $entry['object_type'], $entry['object_id'], $current, $after );
            if ( ! $log_id ) {
                return new WP_Error( 'alify_ai_audit_failed', 'Restore rollback succeeded but could not be logged.', array( 'status' => 500 ) );
            }
            return new WP_REST_Response( array( 'message' => 'Restore-from-Trash operation rolled back.', 'activity_id' => $log_id, 'item' => self::serialize_post( $after_post, true ) ) );
        }
        if ( in_array( $entry['action'], array( 'create_post_type', 'update_post_type', 'delete_post_type' ), true ) ) {
            $before = is_array( $entry['before_json'] ) ? $entry['before_json'] : null;
            $after  = is_array( $entry['after_json'] ) ? $entry['after_json'] : null;
            $key = sanitize_key( (string) ( $before['key'] ?? $after['key'] ?? '' ) );
            if ( '' === $key ) {
                return new WP_Error( 'alify_ai_rollback_failed', 'The post type key is missing from the activity snapshot.', array( 'status' => 500 ) );
            }
            $current_all = ALIFY_AI_Structure::get_managed_post_types();
            $current = $current_all[ $key ] ?? null;
            if ( 'create_post_type' === $entry['action'] ) {
                if ( ALIFY_AI_Structure::post_type_content_count( $key ) > 0 ) {
                    return new WP_Error( 'alify_ai_rollback_conflict', 'Cannot rollback CPT creation while content exists in that post type.', array( 'status' => 409 ) );
                }
                $result = ALIFY_AI_Structure::restore_post_type_definition( $key, null );
                $restored = null;
            } else {
                $result = ALIFY_AI_Structure::restore_post_type_definition( $key, $before );
                $restored = $before;
            }
            if ( is_wp_error( $result ) ) return $result;
            $log_id = ALIFY_AI_Audit::log( 'rollback_' . $entry['action'], 'post_type', 0, $current, $restored );
            if ( ! $log_id ) {
                $compensated = ALIFY_AI_Structure::restore_post_type_definition( $key, $current );
                if ( is_wp_error( $compensated ) ) {
                    ALIFY_AI_Diagnostics::log( 'structure_rollback_compensation_failed', array( 'kind' => 'post_type', 'key' => $key, 'error' => $compensated->get_error_message() ) );
                    return new WP_Error( 'alify_ai_structure_unknown_state', 'CPT rollback logging failed and the pre-rollback structure could not be restored; site state requires review.', array( 'status' => 500 ) );
                }
                return new WP_Error( 'alify_ai_audit_failed', 'CPT rollback was reverted because the rollback activity could not be logged.', array( 'status' => 500 ) );
            }
            return new WP_REST_Response( array( 'message' => 'Custom post type change rolled back.', 'activity_id' => $log_id, 'key' => $key ) );
        }
        if ( in_array( $entry['action'], array( 'create_taxonomy', 'update_taxonomy', 'delete_taxonomy' ), true ) ) {
            $before = is_array( $entry['before_json'] ) ? $entry['before_json'] : null;
            $after  = is_array( $entry['after_json'] ) ? $entry['after_json'] : null;
            $key = sanitize_key( (string) ( $before['key'] ?? $after['key'] ?? '' ) );
            if ( '' === $key ) return new WP_Error( 'alify_ai_rollback_failed', 'The taxonomy key is missing from the activity snapshot.', array( 'status' => 500 ) );
            $current_all = ALIFY_AI_Structure::get_managed_taxonomies();
            $current = $current_all[ $key ] ?? null;
            if ( 'create_taxonomy' === $entry['action'] ) {
                $count = ALIFY_AI_Structure::taxonomy_term_count( $key );
                if ( is_wp_error( $count ) ) return $count;
                if ( $count > 0 ) return new WP_Error( 'alify_ai_rollback_conflict', 'Cannot rollback taxonomy creation while terms exist in that taxonomy.', array( 'status' => 409 ) );
                $result = ALIFY_AI_Structure::restore_taxonomy_definition( $key, null );
                $restored = null;
            } else {
                $result = ALIFY_AI_Structure::restore_taxonomy_definition( $key, $before );
                $restored = $before;
            }
            if ( is_wp_error( $result ) ) return $result;
            $log_id = ALIFY_AI_Audit::log( 'rollback_' . $entry['action'], 'taxonomy', 0, $current, $restored );
            if ( ! $log_id ) {
                $compensated = ALIFY_AI_Structure::restore_taxonomy_definition( $key, $current );
                if ( is_wp_error( $compensated ) ) {
                    ALIFY_AI_Diagnostics::log( 'structure_rollback_compensation_failed', array( 'kind' => 'taxonomy', 'key' => $key, 'error' => $compensated->get_error_message() ) );
                    return new WP_Error( 'alify_ai_structure_unknown_state', 'Taxonomy rollback logging failed and the pre-rollback structure could not be restored; site state requires review.', array( 'status' => 500 ) );
                }
                return new WP_Error( 'alify_ai_audit_failed', 'Taxonomy rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) );
            }
            return new WP_REST_Response( array( 'message' => 'Taxonomy change rolled back.', 'activity_id' => $log_id, 'key' => $key ) );
        }
        if ( 'create_term' === $entry['action'] && is_array( $entry['after_json'] ) ) {
            $taxonomy = sanitize_key( (string) ( $entry['after_json']['taxonomy'] ?? '' ) );
            $term_id = absint( $entry['object_id'] );
            $term = get_term( $term_id, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) return new WP_Error( 'alify_ai_term_not_found', 'Created term no longer exists.', array( 'status' => 404 ) );
            if ( (int) $term->count > 0 ) return new WP_Error( 'alify_ai_rollback_conflict', 'Cannot rollback term creation while objects are assigned to the term.', array( 'status' => 409 ) );
            $children = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'parent' => $term_id, 'fields' => 'ids' ) );
            if ( is_wp_error( $children ) ) return $children;
            if ( ! empty( $children ) ) return new WP_Error( 'alify_ai_rollback_conflict', 'Cannot rollback term creation while child terms depend on it.', array( 'status' => 409 ) );
            $current = ALIFY_AI_Structure::get_term( $taxonomy, $term_id );
            $log_id = ALIFY_AI_Audit::log( 'rollback_create_term', 'term', $term_id, $current, array( 'taxonomy' => $taxonomy, 'id' => $term_id, 'deleted' => true ) );
            if ( ! $log_id ) return new WP_Error( 'alify_ai_audit_failed', 'Could not store the rollback activity, so the term was not deleted.', array( 'status' => 500 ) );
            $deleted = wp_delete_term( $term_id, $taxonomy );
            if ( is_wp_error( $deleted ) || false === $deleted || 0 === $deleted ) {
                ALIFY_AI_Audit::delete_entry( $log_id );
                return is_wp_error( $deleted ) ? $deleted : new WP_Error( 'alify_ai_rollback_failed', 'Could not delete the created term.', array( 'status' => 500 ) );
            }
            return new WP_REST_Response( array( 'message' => 'Created term removed.', 'activity_id' => $log_id ) );
        }
        if ( 'update_term' === $entry['action'] && is_array( $entry['before_json'] ) ) {
            $taxonomy = sanitize_key( (string) ( $entry['before_json']['taxonomy'] ?? '' ) );
            $term_id = absint( $entry['object_id'] );
            $current = ALIFY_AI_Structure::get_term( $taxonomy, $term_id );
            if ( is_wp_error( $current ) ) return $current;
            $restored = ALIFY_AI_Structure::restore_term_snapshot( $taxonomy, $term_id, $entry['before_json'] );
            if ( is_wp_error( $restored ) ) return $restored;
            $after_restore = ALIFY_AI_Structure::get_term( $taxonomy, $term_id );
            if ( is_wp_error( $after_restore ) ) return $after_restore;
            $log_id = ALIFY_AI_Audit::log( 'rollback_update_term', 'term', $term_id, $current, $after_restore );
            if ( ! $log_id ) {
                $compensated = ALIFY_AI_Structure::restore_term_snapshot( $taxonomy, $term_id, $current );
                if ( is_wp_error( $compensated ) ) {
                    ALIFY_AI_Diagnostics::log( 'structure_rollback_compensation_failed', array( 'kind' => 'term', 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'error' => $compensated->get_error_message() ) );
                    return new WP_Error( 'alify_ai_structure_unknown_state', 'Term rollback logging failed and the pre-rollback term could not be restored; site state requires review.', array( 'status' => 500 ) );
                }
                return new WP_Error( 'alify_ai_audit_failed', 'Term rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) );
            }
            return new WP_REST_Response( array( 'message' => 'Term update rolled back.', 'activity_id' => $log_id, 'term' => $after_restore ) );
        }
        if ( 'delete_term' === $entry['action'] && is_array( $entry['before_json'] ) ) {
            $restored = ALIFY_AI_Structure::restore_deleted_term_snapshot( $entry['before_json'] );
            if ( is_wp_error( $restored ) ) return $restored;
            $taxonomy = sanitize_key( (string) ( $restored['taxonomy'] ?? '' ) );
            $new_id = absint( $restored['id'] ?? 0 );
            $log_id = ALIFY_AI_Audit::log( 'rollback_delete_term', 'term', $new_id, null, $restored );
            if ( ! $log_id ) {
                $removed = ( $new_id && $taxonomy ) ? wp_delete_term( $new_id, $taxonomy ) : false;
                if ( ! $removed || is_wp_error( $removed ) || ( $new_id && ! is_wp_error( get_term( $new_id, $taxonomy ) ) && get_term( $new_id, $taxonomy ) ) ) {
                    ALIFY_AI_Diagnostics::log( 'structure_rollback_compensation_failed', array( 'kind' => 'term', 'taxonomy' => $taxonomy, 'term_id' => $new_id, 'stage' => 'delete_recreated_term' ) );
                    return new WP_Error( 'alify_ai_structure_unknown_state', 'Deleted term was recreated, rollback logging failed, and the recreation could not be verified as removed; site state requires review.', array( 'status' => 500 ) );
                }
                return new WP_Error( 'alify_ai_audit_failed', 'Deleted term was recreated but the rollback activity could not be stored, so the recreation was removed.', array( 'status' => 500 ) );
            }
            return new WP_REST_Response( array( 'message' => 'Deleted term recreated and relationships restored.', 'activity_id' => $log_id, 'term' => $restored ) );
        }
        if ( in_array( $entry['action'], array( 'create_menu', 'update_menu', 'delete_menu', 'create_menu_item', 'update_menu_item', 'delete_menu_item', 'reorder_menu_items', 'assign_menu_location', 'unassign_menu_location' ), true ) ) {
            $result = ALIFY_AI_Menus::rollback_activity( $entry );
            return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
        }
        if ( in_array( $entry['action'], array( 'create_acf_group', 'update_acf_group', 'delete_acf_group', 'create_acf_field', 'update_acf_field', 'delete_acf_field', 'update_acf_options' ), true ) ) {
            $result = ALIFY_AI_ACF::rollback_activity( $entry );
            return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
        }
        if ( in_array( $entry['action'], array( 'upload_media', 'update_media', 'trash_media', 'restore_media', 'delete_media', 'replace_media_file', 'edit_media_image', 'regenerate_media_metadata' ), true ) ) {
            $result = ALIFY_AI_Media::rollback_activity( $entry );
            return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
        }
        if ( 'update_acf' === $entry['action'] && is_array( $entry['before_json'] ) ) {
            $result = ALIFY_AI_ACF::update_values( $entry['object_id'], $entry['before_json'] );
            return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'message' => 'ACF values rolled back.', 'result' => $result ) );
        }
        if ( in_array( $entry['action'], array( 'create_gutenberg_pattern','update_gutenberg_pattern','delete_gutenberg_pattern','restore_gutenberg_pattern','create_gutenberg_template','update_gutenberg_template','delete_gutenberg_template' ), true ) ) {
            $result = ALIFY_AI_Gutenberg::rollback_activity( $entry );
            return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
        }
        if ( 'update_gutenberg' === $entry['action'] && is_array( $entry['before_json'] ) ) {
            $result = ALIFY_AI_Gutenberg::rollback( $entry['object_id'], $entry['before_json'] );
            return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
        }
        if ( in_array( $entry['action'], array( 'update_elementor', 'create_elementor_template', 'update_elementor_template', 'delete_elementor_template', 'update_elementor_globals' ), true ) ) {
            $result = ALIFY_AI_Elementor::rollback_activity( $entry );
            return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
        }
        if ( str_starts_with( (string) $entry['action'], 'woocommerce_order_' ) ) {
            $result = ALIFY_AI_Orders::rollback_activity( $entry );
            return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
        }
        if ( in_array( $entry['action'], array( 'update_product', 'create_product' ), true ) || str_starts_with( (string) $entry['action'], 'woocommerce_' ) ) {
            $result = ALIFY_AI_WooCommerce::rollback_activity( $entry );
            return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
        }
        if ( in_array( $entry['action'], array( 'update_seo' ), true ) ) {
            $result = ALIFY_AI_SEO::rollback_activity( $entry );
            return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
        }
        if ( 'transaction' === $entry['action'] ) {
            $result = ALIFY_AI_Transactions::rollback_transaction( $entry );
            return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
        }
        if ( 'update_source_file' === $entry['action'] ) {
            $result = ALIFY_AI_Code_Inspection::rollback_activity( $entry );
            return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
        }
        return new WP_Error( 'alify_ai_rollback_unsupported', 'Rollback is not supported for this activity type.', array( 'status' => 400 ) );
    }

    private static function params( WP_REST_Request $request ): array {
        $params = $request->get_json_params();
        return is_array( $params ) && $params ? $params : (array) $request->get_params();
    }

    public static function openapi(): WP_REST_Response {
        $base = rest_url( self::NS );
        $paths = array(
            '/health' => array( 'get' => self::op( 'healthCheck', 'Check connector availability', false ) ),
            '/connector' => array( 'get' => self::op( 'getConnectorManifest', 'Get public connector discovery metadata and OpenAPI URL', false ) ),
            '/site' => array( 'get' => self::op( 'getSiteInfo', 'Get WordPress site information' ) ),
            '/capabilities' => array( 'get' => self::op( 'getCapabilities', 'List connector capabilities and enabled scopes' ) ),
            '/content-authors' => array( 'get' => self::op( 'listContentAuthors', 'List eligible content authors for a post type', true, null, array( self::query_param( 'type', 'string' ) ) ) ),
            '/pages' => self::content_collection_ops( 'Page' ),
            '/pages/templates' => array( 'get' => self::op( 'listPageTemplates', 'List active-theme templates available to pages', true, null, array( self::query_param( 'post_id', 'integer' ) ) ) ),
            '/pages/{id}' => self::content_item_ops( 'Page' ),
            '/pages/{id}/restore' => array( 'post' => self::op( 'restorePageFromTrash', 'Restore a page from Trash', true, null, array( self::path_param( 'id', 'integer' ) ) ) ),
            '/pages/{id}/revisions' => array( 'get' => self::op( 'listPageRevisions', 'List page revisions', true, null, array( self::path_param( 'id', 'integer' ), self::query_param( 'per_page', 'integer' ) ) ) ),
            '/pages/{id}/revisions/{revision_id}' => array( 'get' => self::op( 'getPageRevision', 'Get a page revision', true, null, array( self::path_param( 'id', 'integer' ), self::path_param( 'revision_id', 'integer' ) ) ) ),
            '/pages/{id}/revisions/{revision_id}/restore' => array( 'post' => self::op( 'restorePageRevision', 'Restore a page revision', true, null, array( self::path_param( 'id', 'integer' ), self::path_param( 'revision_id', 'integer' ) ) ) ),
            '/posts' => self::content_collection_ops( 'Post' ),
            '/posts/templates' => array( 'get' => self::op( 'listPostTemplates', 'List active-theme templates available to posts', true, null, array( self::query_param( 'post_id', 'integer' ) ) ) ),
            '/posts/{id}' => self::content_item_ops( 'Post' ),
            '/posts/{id}/restore' => array( 'post' => self::op( 'restorePostFromTrash', 'Restore a post from Trash', true, null, array( self::path_param( 'id', 'integer' ) ) ) ),
            '/posts/{id}/revisions' => array( 'get' => self::op( 'listPostRevisions', 'List post revisions', true, null, array( self::path_param( 'id', 'integer' ), self::query_param( 'per_page', 'integer' ) ) ) ),
            '/posts/{id}/revisions/{revision_id}' => array( 'get' => self::op( 'getPostRevision', 'Get a post revision', true, null, array( self::path_param( 'id', 'integer' ), self::path_param( 'revision_id', 'integer' ) ) ) ),
            '/posts/{id}/revisions/{revision_id}/restore' => array( 'post' => self::op( 'restorePostRevision', 'Restore a post revision', true, null, array( self::path_param( 'id', 'integer' ), self::path_param( 'revision_id', 'integer' ) ) ) ),
            '/content/{type}' => self::custom_content_collection_ops(),
            '/content/{type}/{id}' => self::custom_content_item_ops(),
            '/post-types' => array(
                'get'  => self::op( 'listPostTypes', 'List registered post types' ),
                'post' => self::op( 'createPostType', 'Create an ALIFY-managed custom post type', true, self::json_body( self::post_type_schema( true ) ) ),
            ),
            '/post-types/{key}' => array(
                'get' => self::op( 'getPostType', 'Get one registered post type and its ALIFY-managed definition', true, null, array( self::path_param( 'key', 'string' ) ) ),
                'patch' => self::op( 'updatePostType', 'Update an ALIFY-managed custom post type', true, self::json_body( self::post_type_schema( false ) ), array( self::path_param( 'key', 'string' ) ) ),
                'delete' => self::op( 'deletePostType', 'Delete an ALIFY-managed custom post type registration. Existing content blocks deletion unless orphaning is explicitly confirmed.', true, self::json_body( array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array( 'force' => array( 'type' => 'boolean' ), 'confirm_orphan_content' => array( 'type' => 'boolean' ) ) ) ), array( self::path_param( 'key', 'string' ) ) ),
            ),
            '/taxonomies' => array(
                'get'  => self::op( 'listTaxonomies', 'List registered taxonomies' ),
                'post' => self::op( 'createTaxonomy', 'Create an ALIFY-managed taxonomy', true, self::json_body( self::taxonomy_schema( true ) ) ),
            ),
            '/taxonomies/{key}' => array(
                'get' => self::op( 'getTaxonomy', 'Get one registered taxonomy and its ALIFY-managed definition', true, null, array( self::path_param( 'key', 'string' ) ) ),
                'patch' => self::op( 'updateTaxonomy', 'Update an ALIFY-managed taxonomy', true, self::json_body( self::taxonomy_schema( false ) ), array( self::path_param( 'key', 'string' ) ) ),
                'delete' => self::op( 'deleteTaxonomy', 'Delete an ALIFY-managed taxonomy registration. Existing terms require explicit orphan confirmation.', true, self::json_body( array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array( 'force' => array( 'type' => 'boolean' ), 'confirm_term_deletion' => array( 'type' => 'boolean' ) ) ) ), array( self::path_param( 'key', 'string' ) ) ),
            ),
            '/taxonomies/{key}/object-types/{object_type}' => array(
                'post' => self::op( 'attachTaxonomyObjectType', 'Attach a managed taxonomy to a registered post type', true, null, array( self::path_param( 'key', 'string' ), self::path_param( 'object_type', 'string' ) ) ),
                'delete' => self::op( 'detachTaxonomyObjectType', 'Detach a managed taxonomy from a post type', true, null, array( self::path_param( 'key', 'string' ), self::path_param( 'object_type', 'string' ) ) ),
            ),
            '/taxonomies/{taxonomy}/terms' => array(
                'get'  => self::op( 'listTerms', 'List taxonomy terms', true, null, array( self::path_param( 'taxonomy', 'string' ), self::query_param( 'search', 'string' ), self::query_param( 'per_page', 'integer' ), self::query_param( 'page', 'integer' ), self::query_param( 'parent', 'integer' ) ) ),
                'post' => self::op( 'createTerm', 'Create a taxonomy term', true, self::json_body( self::term_schema( true ) ), array( self::path_param( 'taxonomy', 'string' ) ) ),
            ),
            '/taxonomies/{taxonomy}/terms/{id}' => array(
                'get' => self::op( 'getTerm', 'Get a taxonomy term', true, null, array( self::path_param( 'taxonomy', 'string' ), self::path_param( 'id', 'integer' ) ) ),
                'patch' => self::op( 'updateTerm', 'Update a taxonomy term', true, self::json_body( self::term_schema( false ) ), array( self::path_param( 'taxonomy', 'string' ), self::path_param( 'id', 'integer' ) ) ),
                'delete' => self::op( 'deleteTerm', 'Delete a taxonomy term. Requires confirm_delete=true; optional default_term_id can preserve assignments.', true, self::json_body( array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'confirm_delete' ), 'properties' => array( 'confirm_delete' => array( 'type' => 'boolean' ), 'default_term_id' => array( 'type' => 'integer' ), 'force_default' => array( 'type' => 'boolean' ) ) ) ), array( self::path_param( 'taxonomy', 'string' ), self::path_param( 'id', 'integer' ) ) ),
            ),
            '/acf/status' => array( 'get' => self::op( 'getAcfStatus', 'Check whether ACF is available' ) ),
            '/acf/field-groups' => array(
                'get'  => self::op( 'listAcfFieldGroups', 'List ACF field groups' ),
                'post' => self::op( 'createAcfFieldGroup', 'Create an ACF field group', true, self::json_body( self::acf_group_schema( true ) ) ),
            ),
            '/acf/field-groups/{group_key}' => array(
                'get' => self::op( 'getAcfFieldGroup', 'Get an ACF field group including nested fields', true, null, array( self::path_param( 'group_key', 'string' ) ) ),
                'patch' => self::op( 'updateAcfFieldGroup', 'Update ACF field-group settings and location rules', true, self::json_body( self::acf_group_schema( false ) ), array( self::path_param( 'group_key', 'string' ) ) ),
                'delete' => self::op( 'deleteAcfFieldGroup', 'Delete an ACF field group after explicit confirmation', true, self::json_body( array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'confirm_delete' ), 'properties' => array( 'confirm_delete' => array( 'type' => 'boolean' ) ) ) ), array( self::path_param( 'group_key', 'string' ) ) ),
            ),
            '/acf/field-groups/{group_key}/fields' => array(
                'get'  => self::op( 'listAcfFields', 'List fields in an ACF group including nested subfields', true, null, array( self::path_param( 'group_key', 'string' ) ) ),
                'post' => self::op( 'createAcfField', 'Create a top-level or nested field inside an ACF group', true, self::json_body( self::acf_field_schema( true ) ), array( self::path_param( 'group_key', 'string' ) ) ),
            ),
            '/acf/fields/{field_key}' => array(
                'get' => self::op( 'getAcfField', 'Get one ACF field including nested subfields', true, null, array( self::path_param( 'field_key', 'string' ) ) ),
                'patch' => self::op( 'updateAcfField', 'Update an ACF field, nested parent, conditional logic, wrapper and type settings', true, self::json_body( self::acf_field_schema( false ) ), array( self::path_param( 'field_key', 'string' ) ) ),
                'delete' => self::op( 'deleteAcfField', 'Delete an ACF field tree after explicit confirmation', true, self::json_body( array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'confirm_delete' ), 'properties' => array( 'confirm_delete' => array( 'type' => 'boolean' ) ) ) ), array( self::path_param( 'field_key', 'string' ) ) ),
            ),
            '/acf/values/{post_id}' => array(
                'get'   => self::op( 'getAcfValues', 'Get ACF values for a content item', true, null, array( self::path_param( 'post_id', 'integer' ) ) ),
                'patch' => self::op( 'updateAcfValues', 'Update ACF values for a content item', true, self::json_body( array( 'type' => 'object', 'properties' => array( 'values' => array( 'type' => 'object', 'additionalProperties' => true ) ) ) ), array( self::path_param( 'post_id', 'integer' ) ) ),
            ),
            '/media' => array( 'get' => self::op( 'listMedia', 'List Media Library items with pagination', true, null, array( self::query_param( 'search', 'string' ), self::query_param( 'mime_type', 'string' ), self::query_param( 'per_page', 'integer' ), self::query_param( 'page', 'integer' ), self::query_param( 'parent', 'integer' ) ) ) ),
            '/media/limits' => array( 'get' => self::op( 'getMediaUploadLimits', 'Get WordPress and MCP media upload limits' ) ),
            '/media/upload' => array( 'post' => self::op( 'uploadMedia', 'Upload a local multipart file to the WordPress Media Library', true, self::multipart_media_body() ) ),
            '/media/upload-json' => array( 'post' => self::op( 'uploadMediaBase64', 'Upload a base64-encoded file for MCP/JSON clients', true, self::json_body( self::media_base64_schema() ) ) ),
            '/media/{id}' => array(
                'get'   => self::op( 'getMedia', 'Get a Media Library item including file metadata and usages', true, null, array( self::path_param( 'id', 'integer' ) ) ),
                'patch' => self::op( 'updateMedia', 'Update media title, caption, description, alt text or parent', true, self::json_body( array( 'type' => 'object', 'properties' => array( 'title' => array( 'type' => 'string' ), 'caption' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ), 'alt_text' => array( 'type' => 'string' ), 'parent' => array( 'type' => 'integer' ) ) ) ), array( self::path_param( 'id', 'integer' ) ) ),
                'delete' => self::op( 'deleteMedia', 'Trash media or permanently delete it with protected file backup and explicit confirmation', true, self::json_body( array( 'type' => 'object', 'properties' => array( 'force' => array( 'type' => 'boolean' ), 'confirm_permanent' => array( 'type' => 'boolean' ), 'confirm_in_use' => array( 'type' => 'boolean' ) ) ) ), array( self::path_param( 'id', 'integer' ) ) ),
            ),
            '/media/{id}/restore' => array( 'post' => self::op( 'restoreMedia', 'Restore a trashed media attachment', true, null, array( self::path_param( 'id', 'integer' ) ) ) ),
            '/media/{id}/usages' => array( 'get' => self::op( 'getMediaUsages', 'Find featured-image usages for an attachment', true, null, array( self::path_param( 'id', 'integer' ) ) ) ),
            '/media/{id}/replace' => array( 'post' => self::op( 'replaceMediaFile', 'Replace an attachment file in place using multipart upload; same extension required', true, self::multipart_media_body(), array( self::path_param( 'id', 'integer' ) ) ) ),
            '/media/{id}/replace-json' => array( 'post' => self::op( 'replaceMediaFileBase64', 'Replace an attachment file in place using base64 content', true, self::json_body( self::media_base64_schema() ), array( self::path_param( 'id', 'integer' ) ) ) ),
            '/media/{id}/edit' => array( 'post' => self::op( 'editMediaImage', 'Resize, crop, rotate or flip an image attachment', true, self::json_body( self::media_edit_schema() ), array( self::path_param( 'id', 'integer' ) ) ) ),
            '/media/{id}/regenerate' => array( 'post' => self::op( 'regenerateMediaMetadata', 'Regenerate WordPress attachment metadata and image sub-sizes', true, null, array( self::path_param( 'id', 'integer' ) ) ) ),
            '/menus' => array(
                'get'  => self::op( 'listMenus', 'List WordPress navigation menus' ),
                'post' => self::op( 'createMenu', 'Create a navigation menu and optionally assign theme locations', true, self::json_body( self::menu_schema( true ) ) ),
            ),
            '/menus/locations' => array( 'get' => self::op( 'listMenuLocations', 'List registered theme menu locations and assignments' ) ),
            '/menus/locations/{location}' => array(
                'post' => self::op( 'assignMenuLocation', 'Assign a menu to one registered theme location', true, self::json_body( array( 'type' => 'object', 'required' => array( 'menu_id' ), 'properties' => array( 'menu_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), 'additionalProperties' => false ) ), array( self::path_param( 'location', 'string' ) ) ),
                'delete' => self::op( 'unassignMenuLocation', 'Unassign the current menu from one registered theme location', true, null, array( self::path_param( 'location', 'string' ) ) ),
            ),
            '/menus/{id}' => array(
                'get'    => self::op( 'getMenu', 'Get a menu with metadata, locations and ordered items', true, null, array( self::path_param( 'id', 'integer' ) ) ),
                'patch'  => self::op( 'updateMenu', 'Rename, describe or replace theme-location assignments for a menu', true, self::json_body( self::menu_schema( false ) ), array( self::path_param( 'id', 'integer' ) ) ),
                'delete' => self::op( 'deleteMenu', 'Permanently delete a menu and all its menu items after explicit confirmation', true, self::json_body( array( 'type' => 'object', 'required' => array( 'confirm_delete' ), 'properties' => array( 'confirm_delete' => array( 'type' => 'boolean' ) ), 'additionalProperties' => false ) ), array( self::path_param( 'id', 'integer' ) ) ),
            ),
            '/menus/{id}/items' => array(
                'get'  => self::op( 'listMenuItems', 'List ordered items in a menu', true, null, array( self::path_param( 'id', 'integer' ) ) ),
                'post' => self::op( 'createMenuItem', 'Add a post, taxonomy term, post-type archive or custom URL menu item', true, self::json_body( self::menu_item_schema( true ) ), array( self::path_param( 'id', 'integer' ) ) ),
            ),
            '/menus/{id}/items/reorder' => array(
                'post' => self::op( 'reorderMenuItems', 'Bulk reorder and reparent menu items with hierarchy-cycle validation', true, self::json_body( self::menu_reorder_schema() ), array( self::path_param( 'id', 'integer' ) ) ),
            ),
            '/menus/{id}/items/{item_id}' => array(
                'patch'  => self::op( 'updateMenuItem', 'Update menu item target, hierarchy, order, label, URL, classes and link metadata', true, self::json_body( self::menu_item_schema( false ) ), array( self::path_param( 'id', 'integer' ), self::path_param( 'item_id', 'integer' ) ) ),
                'delete' => self::op( 'deleteMenuItem', 'Permanently delete a menu item with explicit confirmation and safe child reparenting', true, self::json_body( array( 'type' => 'object', 'required' => array( 'confirm_delete' ), 'properties' => array( 'confirm_delete' => array( 'type' => 'boolean' ), 'reparent_children_to' => array( 'type' => 'integer', 'minimum' => 0 ) ), 'additionalProperties' => false ) ), array( self::path_param( 'id', 'integer' ), self::path_param( 'item_id', 'integer' ) ) ),
            ),
            '/design/gutenberg/{post_id}' => array(
                'get' => self::op( 'getGutenbergDocument', 'Inspect a Gutenberg block tree using stable index paths', true, null, array( self::path_param( 'post_id', 'integer' ) ) ),
            ),
            '/design/gutenberg/{post_id}/preview' => array(
                'post' => self::op( 'previewGutenbergChanges', 'Create a dry-run Gutenberg change proposal without changing page content', true, self::json_body( self::gutenberg_operations_schema() ), array( self::path_param( 'post_id', 'integer' ) ) ),
            ),
            '/design/gutenberg/status' => array( 'get' => self::op( 'getGutenbergStatus', 'Inspect Gutenberg, block-theme, pattern and template capabilities' ) ),
            '/design/gutenberg/block-types' => array( 'get' => self::op( 'listGutenbergBlockTypes', 'List registered block types and server-side constraints', true, null, array( self::query_param( 'search','string' ), self::query_param( 'category','string' ) ) ) ),
            '/design/gutenberg/block-type' => array( 'get' => self::op( 'getGutenbergBlockType', 'Get one registered Gutenberg block type schema', true, null, array( self::query_param( 'name','string' ) ) ) ),
            '/design/gutenberg/validate' => array( 'post' => self::op( 'validateGutenbergContent', 'Validate raw Gutenberg content or an existing post against registered block constraints', true, self::json_body( array( 'type'=>'object','properties'=>array( 'post_id'=>array('type'=>'integer','minimum'=>1), 'content'=>array('type'=>'string') ) ) ) ) ),
            '/design/gutenberg/patterns' => array( 'get' => self::op( 'listGutenbergPatterns', 'List registered theme/core patterns and user-created patterns', true, null, array( self::query_param('source','string'), self::query_param('search','string') ) ) ),
            '/design/gutenberg/patterns/registered' => array( 'get' => self::op( 'getRegisteredGutenbergPattern', 'Get a registered theme/core block pattern', true, null, array( self::query_param('name','string') ) ) ),
            '/design/gutenberg/patterns/preview' => array( 'post' => self::op( 'previewGutenbergPatternCreate', 'Preview creation of a synced or unsynced user pattern', true, self::json_body( self::gutenberg_pattern_schema(true) ) ) ),
            '/design/gutenberg/patterns/{id}' => array( 'get' => self::op( 'getUserGutenbergPattern', 'Get a user-created wp_block pattern', true, null, array( self::path_param('id','integer') ) ) ),
            '/design/gutenberg/patterns/{id}/preview' => array( 'post' => self::op( 'previewGutenbergPatternChange', 'Preview update, Trash, permanent delete, or restore for a user pattern', true, self::json_body( self::gutenberg_pattern_schema(false) ), array( self::path_param('id','integer') ) ) ),
            '/design/gutenberg/templates' => array( 'get' => self::op( 'listGutenbergTemplates', 'List merged block templates or template parts', true, null, array( self::query_param('type','string'), self::query_param('post_type','string'), self::query_param('area','string') ) ) ),
            '/design/gutenberg/template' => array( 'get' => self::op( 'getGutenbergTemplate', 'Get a block template or template part by theme//slug identifier', true, null, array( self::query_param('id','string'), self::query_param('type','string') ) ) ),
            '/design/gutenberg/templates/preview' => array( 'post' => self::op( 'previewGutenbergTemplateChange', 'Preview create, update or delete for a customized block template/template-part', true, self::json_body( self::gutenberg_template_schema() ) ) ),
            '/design/elementor/status' => array( 'get' => self::op( 'getElementorStatus', 'Check Elementor Core/Pro, document, widget, breakpoint, Kit and template-library capabilities' ) ),
            '/design/elementor/breakpoints' => array( 'get' => self::op( 'listElementorBreakpoints', 'List active and registered Elementor responsive breakpoints' ) ),
            '/design/elementor/widgets' => array( 'get' => self::op( 'listElementorWidgets', 'List registered Elementor widgets and control counts', true, null, array( self::query_param('search','string'), self::query_param('category','string') ) ) ),
            '/design/elementor/widgets/{name}' => array( 'get' => self::op( 'getElementorWidget', 'Get a registered Elementor widget and its control schema', true, null, array( self::path_param('name','string') ) ) ),
            '/design/elementor/templates' => array( 'get' => self::op( 'listElementorTemplates', 'List local Elementor templates/global widgets from elementor_library', true, null, array( self::query_param('type','string'), self::query_param('status','string'), self::query_param('search','string'), self::query_param('page','integer'), self::query_param('per_page','integer') ) ) ),
            '/design/elementor/templates/preview' => array( 'post' => self::op( 'previewElementorTemplateCreate', 'Preview creation of a local Elementor template/global widget', true, self::json_body( self::elementor_template_schema(true) ) ) ),
            '/design/elementor/templates/{id}' => array( 'get' => self::op( 'getElementorTemplate', 'Get local Elementor template structure and page settings', true, null, array( self::path_param('id','integer') ) ) ),
            '/design/elementor/templates/{id}/preview' => array( 'post' => self::op( 'previewElementorTemplateChange', 'Preview update or permanent delete for a local Elementor template', true, self::json_body( self::elementor_template_schema(false) ), array( self::path_param('id','integer') ) ) ),
            '/design/elementor/globals' => array( 'get' => self::op( 'getElementorGlobals', 'Read active Elementor Kit global colors, typography, breakpoints and settings' ) ),
            '/design/elementor/globals/preview' => array( 'post' => self::op( 'previewElementorGlobals', 'Preview active Elementor Kit/global-style setting changes', true, self::json_body( array( 'type'=>'object','required'=>array('settings'),'properties'=>array('settings'=>array('type'=>'object','additionalProperties'=>true)) ) ) ) ),
            '/design/elementor/{post_id}' => array(
                'get' => self::op( 'getElementorDocument', 'Inspect an Elementor element tree, page settings and validation state', true, null, array( self::path_param( 'post_id', 'integer' ) ) ),
            ),
            '/design/elementor/{post_id}/validate' => array( 'get' => self::op( 'validateElementorDocument', 'Validate Elementor element IDs, widget registry references and element instantiation', true, null, array( self::path_param('post_id','integer') ) ) ),
            '/design/elementor/{post_id}/preview' => array(
                'post' => self::op( 'previewElementorChanges', 'Create a validated dry-run Elementor change proposal without changing page content', true, self::json_body( self::elementor_operations_schema() ), array( self::path_param( 'post_id', 'integer' ) ) ),
            ),
            '/seo/status' => array( 'get' => self::op( 'getSeoStatus', 'Detect Generic SEO, Yoast SEO and Rank Math capabilities' ) ),
            '/seo/posts/{id}' => array( 'get' => self::op( 'getPostSeo', 'Read normalized SEO metadata and rule audit for one post', true, null, array( self::path_param('id','integer'), self::query_param('provider','string') ) ) ),
            '/seo/posts/{id}/preview' => array( 'post' => self::op( 'previewPostSeoUpdate', 'Preview SEO metadata changes before approval', true, self::json_body( array( 'type'=>'object','additionalProperties'=>true ) ), array( self::path_param('id','integer') ) ) ),
            '/seo/audit' => array( 'get' => self::op( 'auditSeo', 'Audit public WordPress content for missing/duplicate/length/robots/social SEO issues', true, null, array( self::query_param('provider','string'), self::query_param('post_type','string'), self::query_param('limit','integer') ) ) ),
            '/woocommerce/status' => array( 'get' => self::op( 'getWooCommerceStatus', 'Check WooCommerce availability and connector safety boundaries' ) ),
            '/woocommerce/products' => array( 'get' => self::op( 'listWooCommerceProducts', 'List WooCommerce catalog products', true, null, array( self::query_param( 'search', 'string' ), self::query_param( 'sku', 'string' ), self::query_param( 'status', 'string' ), self::query_param( 'type', 'string' ), self::query_param( 'page', 'integer' ), self::query_param( 'per_page', 'integer' ) ) ) ),
            '/woocommerce/products/{id}' => array( 'get' => self::op( 'getWooCommerceProduct', 'Get a WooCommerce catalog product', true, null, array( self::path_param( 'id', 'integer' ) ) ) ),
            '/woocommerce/products/preview' => array( 'post' => self::op( 'previewWooCommerceProductCreate', 'Preview creation of a simple or variable WooCommerce product; no product is written yet', true, self::json_body( self::woocommerce_product_schema( true ) ) ) ),
            '/woocommerce/products/{id}/preview' => array( 'post' => self::op( 'previewWooCommerceProductUpdate', 'Preview WooCommerce product changes; price, stock and publish changes require later approval', true, self::json_body( self::woocommerce_product_schema( false ) ), array( self::path_param( 'id', 'integer' ) ) ) ),
            '/woocommerce/products/{id}/variations' => array( 'get' => self::op( 'listWooCommerceVariations', 'List variations for a variable product', true, null, array( self::path_param( 'id', 'integer' ), self::query_param( 'page', 'integer' ), self::query_param( 'per_page', 'integer' ) ) ) ),
            '/woocommerce/products/{id}/variations/{variation_id}' => array( 'get' => self::op( 'getWooCommerceVariation', 'Get one variation for a variable product', true, null, array( self::path_param( 'id', 'integer' ), self::path_param( 'variation_id', 'integer' ) ) ) ),
            '/woocommerce/attributes' => array( 'get' => self::op( 'listWooCommerceAttributes', 'List global WooCommerce product attributes' ) ),
            '/woocommerce/attributes/{id}' => array( 'get' => self::op( 'getWooCommerceAttribute', 'Get a global WooCommerce product attribute', true, null, array( self::path_param( 'id', 'integer' ) ) ) ),
            '/woocommerce/attributes/{id}/terms' => array( 'get' => self::op( 'listWooCommerceAttributeTerms', 'List terms/options for a global product attribute', true, null, array( self::path_param( 'id', 'integer' ), self::query_param( 'search', 'string' ), self::query_param( 'per_page', 'integer' ) ) ) ),
            '/woocommerce/attributes/{id}/terms/{term_id}' => array( 'get' => self::op( 'getWooCommerceAttributeTerm', 'Get a global product attribute term', true, null, array( self::path_param( 'id', 'integer' ), self::path_param( 'term_id', 'integer' ) ) ) ),
            '/woocommerce/shipping-classes' => array( 'get' => self::op( 'listWooCommerceShippingClasses', 'List WooCommerce product shipping classes' ) ),
            '/woocommerce/shipping-classes/{id}' => array( 'get' => self::op( 'getWooCommerceShippingClass', 'Get a WooCommerce shipping class', true, null, array( self::path_param( 'id', 'integer' ) ) ) ),
            '/woocommerce/tax-classes' => array( 'get' => self::op( 'listWooCommerceTaxClasses', 'List WooCommerce product tax classes' ) ),
            '/woocommerce/tax-classes/{slug}' => array( 'get' => self::op( 'getWooCommerceTaxClass', 'Get a WooCommerce product tax class', true, null, array( self::path_param( 'slug', 'string' ) ) ) ),
            '/woocommerce/catalog/preview' => array( 'post' => self::op( 'previewWooCommerceCatalogOperation', 'Preview an approval-gated WooCommerce catalog lifecycle operation', true, self::json_body( self::woocommerce_catalog_operation_schema() ) ) ),
            '/woocommerce/order-statuses' => array( 'get' => self::op( 'listWooCommerceOrderStatuses', 'List registered WooCommerce order statuses' ) ),
            '/woocommerce/orders' => array( 'get' => self::op( 'listWooCommerceOrders', 'List WooCommerce orders. Requires the separate Orders permission.', true, null, array( self::query_param('status','string'), self::query_param('customer_id','integer'), self::query_param('billing_email','string'), self::query_param('date_after','string'), self::query_param('date_before','string'), self::query_param('page','integer'), self::query_param('per_page','integer') ) ) ),
            '/woocommerce/orders/preview' => array( 'post' => self::op( 'previewWooCommerceOrderCreate', 'Preview creation of a WooCommerce order without capturing payment', true, self::json_body( array( 'type'=>'object','additionalProperties'=>true ) ) ) ),
            '/woocommerce/orders/{id}' => array( 'get' => self::op( 'getWooCommerceOrder', 'Get a WooCommerce order including addresses and order items', true, null, array( self::path_param('id','integer') ) ) ),
            '/woocommerce/orders/{id}/preview' => array( 'post' => self::op( 'previewWooCommerceOrderUpdate', 'Preview status, customer-note, customer or address changes; status transitions require explicit side-effect confirmation', true, self::json_body( array( 'type'=>'object','additionalProperties'=>true ) ), array( self::path_param('id','integer') ) ) ),
            '/woocommerce/orders/{id}/items/preview' => array( 'post' => self::op( 'previewWooCommerceOrderItemCreate', 'Preview adding a product line to an order; financial-total confirmation is required', true, self::json_body( array( 'type'=>'object','additionalProperties'=>true ) ), array( self::path_param('id','integer') ) ) ),
            '/woocommerce/orders/{id}/items/{item_id}/preview' => array( 'post' => self::op( 'previewWooCommerceOrderItemChange', 'Preview update/delete of an order line item', true, self::json_body( array( 'type'=>'object','additionalProperties'=>true ) ), array( self::path_param('id','integer'), self::path_param('item_id','integer') ) ) ),
            '/woocommerce/orders/{id}/notes' => array( 'get' => self::op( 'listWooCommerceOrderNotes', 'List internal/customer notes for a WooCommerce order', true, null, array( self::path_param('id','integer'), self::query_param('type','string'), self::query_param('limit','integer') ) ) ),
            '/woocommerce/orders/{id}/notes/preview' => array( 'post' => self::op( 'previewWooCommerceOrderNoteCreate', 'Preview an internal or customer-visible order note; customer note requires notification confirmation', true, self::json_body( array( 'type'=>'object','additionalProperties'=>true ) ), array( self::path_param('id','integer') ) ) ),
            '/woocommerce/orders/{id}/notes/{note_id}/preview' => array( 'post' => self::op( 'previewWooCommerceOrderNoteDelete', 'Preview deletion of an order note', true, self::json_body( array( 'type'=>'object','additionalProperties'=>true ) ), array( self::path_param('id','integer'), self::path_param('note_id','integer') ) ) ),
            '/transactions/preview' => array( 'post' => self::op( 'previewTransaction', 'Preview a multi-action atomic transaction with stale-target hashes and compensation rollback', true, self::json_body( self::transaction_schema() ) ) ),
            '/approvals' => array( 'get' => self::op( 'listApprovals', 'List recent design, commerce, order, and transaction proposals', true, null, array( self::query_param( 'status', 'string' ), self::query_param( 'limit', 'integer' ) ) ) ),
            '/approvals/{id}' => array( 'get' => self::op( 'getApproval', 'Get an approval proposal and preview', true, null, array( self::path_param( 'id', 'integer' ) ) ) ),
            '/approvals/{id}/apply' => array( 'post' => self::op( 'applyApproval', 'Apply a pending design, commerce, order, or transaction proposal after stale-target verification', true, null, array( self::path_param( 'id', 'integer' ) ) ) ),
            '/approvals/{id}/cancel' => array( 'post' => self::op( 'cancelApproval', 'Cancel a pending approval proposal', true, null, array( self::path_param( 'id', 'integer' ) ) ) ),
            '/activity' => array( 'get' => self::op( 'listActivity', 'List recent AI activity', true, null, array( self::query_param( 'limit', 'integer' ) ) ) ),
            '/rollback/{id}' => array( 'post' => self::op( 'rollbackChange', 'Rollback a supported content, media, ACF, Gutenberg, Elementor, WooCommerce catalog/order, or transaction change by activity ID', true, null, array( self::path_param( 'id', 'integer' ) ) ) ),
        );

        $schema = array(
            'openapi' => '3.1.0',
            'info' => array( 'title' => 'ALIFY AI Connector API', 'version' => ALIFY_AI_VERSION, 'description' => 'Secure WordPress control API for approved AI clients. Gutenberg-aware WordPress control API with block validation, user patterns, synced patterns, block templates/template parts, previews, approvals, rollback, and broader site-management tools.' ),
            'servers' => array( array( 'url' => $base ) ),
            'components' => array( 'securitySchemes' => array( 'AlifyKey' => array( 'type' => 'apiKey', 'in' => 'header', 'name' => 'X-ALIFY-Key' ) ) ),
            'security' => array( array( 'AlifyKey' => array() ) ),
            'paths' => $paths,
        );
        return new WP_REST_Response( $schema );
    }

    private static function gutenberg_operations_schema(): array {
        return array(
            'type' => 'object',
            'required' => array( 'operations' ),
            'properties' => array(
                'operations' => array(
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 50,
                    'items' => array(
                        'type' => 'object',
                        'required' => array( 'type' ),
                        'properties' => array(
                            'type' => array( 'type' => 'string', 'enum' => array( 'update_attributes', 'replace_inner_html', 'replace_block', 'remove_block', 'insert_block', 'insert_blocks', 'duplicate_block', 'move_block', 'replace_document' ) ),
                            'path' => array( 'type' => 'string', 'description' => 'Zero-based block path such as 0.2.1.' ),
                            'parent_path' => array( 'type' => 'string', 'description' => 'Parent path for insert_block; blank means document root.' ),
                            'index' => array( 'type' => 'integer', 'minimum' => 0 ),
                            'attributes' => array( 'type' => 'object', 'additionalProperties' => true ),
                            'replace' => array( 'type' => 'boolean' ),
                            'html' => array( 'type' => 'string' ),
                            'raw' => array( 'type' => 'string', 'description' => 'Exactly one serialized Gutenberg block.' ),
                        ),
                    ),
                ),
            ),
        );
    }


    private static function gutenberg_pattern_schema( bool $create ): array {
        $props=array(
            'action'=>array('type'=>'string','enum'=>array('create','update','delete','restore')),
            'title'=>array('type'=>'string'),'slug'=>array('type'=>'string'),'content'=>array('type'=>'string'),
            'status'=>array('type'=>'string','enum'=>array('publish','draft','private','trash')),
            'sync_status'=>array('type'=>'string','enum'=>array('synced','unsynced')),
            'categories'=>array('type'=>'array','items'=>array('type'=>'string')),
            'force'=>array('type'=>'boolean'),'confirm_permanent'=>array('type'=>'boolean')
        );
        return array('type'=>'object','properties'=>$props,'required'=>$create?array('title','content'):array('action'));
    }
    private static function gutenberg_template_schema(): array {
        return array('type'=>'object','required'=>array('action','type'),'properties'=>array(
            'action'=>array('type'=>'string','enum'=>array('create','update','delete')),
            'type'=>array('type'=>'string','enum'=>array('wp_template','wp_template_part')),
            'id'=>array('type'=>'string','description'=>'Existing theme//slug identifier for update/delete.'),
            'theme'=>array('type'=>'string'),'slug'=>array('type'=>'string'),'title'=>array('type'=>'string'),'content'=>array('type'=>'string'),'area'=>array('type'=>'string')
        ));
    }

    private static function elementor_operations_schema(): array {
        return array(
            'type' => 'object',
            'required' => array( 'operations' ),
            'properties' => array(
                'operations' => array(
                    'type' => 'array', 'minItems' => 1, 'maxItems' => 75,
                    'items' => array(
                        'type' => 'object', 'required' => array( 'type' ),
                        'properties' => array(
                            'type' => array( 'type'=>'string', 'enum'=>array( 'update_settings','set_responsive_setting','set_global_style','update_page_settings','add_element','remove_element','duplicate_element','move_element','replace_element','replace_document' ) ),
                            'element_id'=>array('type'=>'string'), 'parent_id'=>array('type'=>'string'), 'index'=>array('type'=>'integer','minimum'=>0),
                            'settings'=>array('type'=>'object','additionalProperties'=>true), 'replace'=>array('type'=>'boolean'),
                            'control'=>array('type'=>'string'), 'device'=>array('type'=>'string'), 'value'=>array(),
                            'style_type'=>array('type'=>'string','enum'=>array('colors','typography')), 'style_id'=>array('type'=>'string'),
                            'element'=>array('type'=>'object','additionalProperties'=>true),
                            'elements'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true)),
                        ),
                        'additionalProperties'=>true,
                    ),
                ),
                'note'=>array('type'=>'string'),
            ),
        );
    }

    private static function elementor_template_schema( bool $create ): array {
        $props = array(
            'action'=>array('type'=>'string','enum'=>array('create','update','delete')),
            'title'=>array('type'=>'string'), 'type'=>array('type'=>'string'),
            'status'=>array('type'=>'string','enum'=>array('publish','draft','pending','private')),
            'elements'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true)),
            'page_settings'=>array('type'=>'object','additionalProperties'=>true),
            'operations'=>self::elementor_operations_schema()['properties']['operations'],
            'confirm_delete'=>array('type'=>'boolean'),
        );
        return array('type'=>'object','properties'=>$props,'required'=>$create?array('title','type','elements'):array('action'));
    }

    private static function content_collection_ops( string $label ): array {
        return array(
            'get' => self::op( 'list' . $label . 's', 'List ' . strtolower( $label ) . 's', true, null, array( self::query_param( 'search', 'string' ), self::query_param( 'status', 'string' ), self::query_param( 'author', 'integer' ), self::query_param( 'per_page', 'integer' ) ) ),
            'post' => self::op( 'create' . $label, 'Create a ' . strtolower( $label ), true, self::json_body( self::content_schema( true ) ) ),
        );
    }

    private static function content_item_ops( string $label ): array {
        return array(
            'get' => self::op( 'get' . $label, 'Get a ' . strtolower( $label ), true, null, array( self::path_param( 'id', 'integer' ) ) ),
            'patch' => self::op( 'update' . $label, 'Update a ' . strtolower( $label ), true, self::json_body( self::content_schema( false ) ), array( self::path_param( 'id', 'integer' ) ) ),
            'delete' => self::op( 'delete' . $label, 'Move a ' . strtolower( $label ) . ' to Trash or permanently delete it with explicit confirmation', true, null, array( self::path_param( 'id', 'integer' ), self::query_param( 'force', 'boolean' ), self::query_param( 'confirm_permanent', 'boolean' ) ) ),
        );
    }

    private static function custom_content_collection_ops(): array {
        $type = self::path_param( 'type', 'string' );
        return array(
            'get' => self::op( 'listCustomContent', 'List entries for a registered post type', true, null, array( $type, self::query_param( 'search', 'string' ), self::query_param( 'status', 'string' ), self::query_param( 'per_page', 'integer' ) ) ),
            'post' => self::op( 'createCustomContent', 'Create an entry for a registered post type', true, self::json_body( self::content_schema( true ) ), array( $type ) ),
        );
    }

    private static function custom_content_item_ops(): array {
        $params = array( self::path_param( 'type', 'string' ), self::path_param( 'id', 'integer' ) );
        return array(
            'get' => self::op( 'getCustomContent', 'Get a custom post type entry', true, null, $params ),
            'patch' => self::op( 'updateCustomContent', 'Update a custom post type entry', true, self::json_body( self::content_schema( false ) ), $params ),
        );
    }

    private static function content_schema( bool $require_title ): array {
        $schema = array(
            'type' => 'object',
            'properties' => array(
                'title' => array( 'type' => 'string' ),
                'content' => array( 'type' => 'string' ),
                'excerpt' => array( 'type' => 'string' ),
                'slug' => array( 'type' => 'string' ),
                'status' => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'publish', 'private', 'future' ) ),
                'date' => array( 'type' => 'string', 'format' => 'date-time', 'description' => 'Local/site publication time in RFC3339. Use status=future to schedule.' ),
                'date_gmt' => array( 'type' => 'string', 'format' => 'date-time', 'description' => 'UTC publication time in RFC3339.' ),
                'author' => array( 'type' => 'integer' ),
                'template' => array( 'type' => 'string', 'description' => 'Active-theme template file or default.' ),
                'parent' => array( 'type' => 'integer' ),
                'menu_order' => array( 'type' => 'integer' ),
                'featured_media' => array( 'type' => 'integer' ),
                'terms' => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'array', 'items' => array( 'oneOf' => array( array( 'type' => 'integer' ), array( 'type' => 'string' ) ) ) ) ),
            ),
        );
        if ( $require_title ) {
            $schema['required'] = array( 'title' );
        }
        return $schema;
    }

    private static function post_type_schema( bool $creating ): array {
        $labels = array(
            'type' => 'object', 'additionalProperties' => false,
            'properties' => array_fill_keys(
                array( 'name','singular_name','add_new','add_new_item','edit_item','new_item','view_item','view_items','search_items','not_found','not_found_in_trash','parent_item_colon','all_items','archives','attributes','insert_into_item','uploaded_to_this_item','featured_image','set_featured_image','remove_featured_image','use_featured_image','menu_name','filter_items_list','filter_by_date','items_list_navigation','items_list','item_published','item_published_privately','item_reverted_to_draft','item_trashed','item_scheduled','item_updated','item_link','item_link_description' ),
                array( 'type' => 'string' )
            ),
        );
        $caps = array(
            'type' => 'object', 'additionalProperties' => false,
            'properties' => array_fill_keys(
                array( 'edit_post','read_post','delete_post','edit_posts','edit_others_posts','delete_posts','publish_posts','read_private_posts','read','delete_private_posts','delete_published_posts','delete_others_posts','edit_private_posts','edit_published_posts','create_posts' ),
                array( 'type' => 'string' )
            ),
        );
        $rewrite = array(
            'oneOf' => array(
                array( 'type' => 'boolean' ),
                array(
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => array(
                        'slug' => array( 'type' => 'string' ), 'with_front' => array( 'type' => 'boolean' ),
                        'feeds' => array( 'type' => 'boolean' ), 'pages' => array( 'type' => 'boolean' ), 'ep_mask' => array( 'type' => 'integer' ),
                    ),
                ),
            ),
        );
        $schema = array(
            'type' => 'object', 'additionalProperties' => false,
            'properties' => array(
                'key' => array( 'type' => 'string', 'maxLength' => 20 ),
                'label' => array( 'type' => 'string' ), 'singular' => array( 'type' => 'string' ), 'labels' => $labels,
                'description' => array( 'type' => 'string' ),
                'public' => array( 'type' => 'boolean' ), 'publicly_queryable' => array( 'type' => 'boolean' ),
                'exclude_from_search' => array( 'type' => 'boolean' ), 'show_ui' => array( 'type' => 'boolean' ),
                'show_in_menu' => array( 'oneOf' => array( array( 'type' => 'boolean' ), array( 'type' => 'string' ) ) ),
                'show_in_nav_menus' => array( 'type' => 'boolean' ), 'show_in_admin_bar' => array( 'type' => 'boolean' ),
                'show_in_rest' => array( 'type' => 'boolean' ), 'rest_base' => array( 'type' => 'string' ), 'rest_namespace' => array( 'type' => 'string' ),
                'late_route_registration' => array( 'type' => 'boolean' ),
                'menu_position' => array( 'oneOf' => array( array( 'type' => 'integer' ), array( 'type' => 'null' ) ) ), 'menu_icon' => array( 'type' => array( 'string', 'null' ) ),
                'capability_type' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'array', 'minItems' => 2, 'maxItems' => 2, 'items' => array( 'type' => 'string' ) ) ) ),
                'capabilities' => $caps, 'map_meta_cap' => array( 'type' => 'boolean' ),
                'hierarchical' => array( 'type' => 'boolean' ),
                'supports' => array( 'oneOf' => array(
                    array( 'type' => 'boolean', 'enum' => array( false ) ),
                    array( 'type' => 'array', 'items' => array( 'oneOf' => array(
                        array( 'type' => 'string' ),
                        array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 2, 'items' => true ),
                    ) ) ),
                ) ),
                'taxonomies' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'has_archive' => array( 'oneOf' => array( array( 'type' => 'boolean' ), array( 'type' => 'string' ) ) ),
                'rewrite_slug' => array( 'type' => 'string', 'deprecated' => true ), 'rewrite' => $rewrite,
                'query_var' => array( 'oneOf' => array( array( 'type' => 'boolean' ), array( 'type' => 'string' ) ) ),
                'can_export' => array( 'type' => 'boolean' ), 'delete_with_user' => array( 'type' => array( 'boolean', 'null' ) ),
                'template' => array( 'type' => 'array', 'items' => array( 'type' => 'array' ) ),
                'template_lock' => array( 'oneOf' => array( array( 'type' => 'boolean', 'enum' => array( false ) ), array( 'type' => 'string', 'enum' => array( 'all', 'insert', 'contentOnly' ) ) ) ),
            ),
        );
        if ( $creating ) { $schema['required'] = array( 'key', 'label' ); }
        return $schema;
    }

    private static function taxonomy_schema( bool $creating ): array {
        $label_keys = array( 'name','singular_name','menu_name','search_items','popular_items','all_items','parent_item','parent_item_colon','name_field_description','slug_field_description','parent_field_description','desc_field_description','edit_item','view_item','update_item','add_new_item','new_item_name','separate_items_with_commas','add_or_remove_items','choose_from_most_used','not_found','no_terms','filter_by_item','items_list_navigation','items_list','most_used','back_to_items','item_link','item_link_description' );
        $labels = array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array_fill_keys( $label_keys, array( 'type' => 'string' ) ) );
        $caps = array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array_fill_keys( array( 'manage_terms','edit_terms','delete_terms','assign_terms' ), array( 'type' => 'string' ) ) );
        $rewrite = array( 'oneOf' => array(
            array( 'type' => 'boolean' ),
            array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'slug' => array( 'type' => 'string' ), 'with_front' => array( 'type' => 'boolean' ),
                'hierarchical' => array( 'type' => 'boolean' ), 'ep_mask' => array( 'type' => 'integer' ),
            ) ),
        ) );
        $schema = array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
            'key' => array( 'type' => 'string', 'maxLength' => 32 ), 'label' => array( 'type' => 'string' ), 'singular' => array( 'type' => 'string' ),
            'labels' => $labels, 'description' => array( 'type' => 'string' ),
            'object_types' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'uniqueItems' => true ),
            'public' => array( 'type' => 'boolean' ), 'publicly_queryable' => array( 'type' => 'boolean' ), 'hierarchical' => array( 'type' => 'boolean' ),
            'show_ui' => array( 'type' => 'boolean' ), 'show_in_menu' => array( 'type' => 'boolean' ), 'show_in_nav_menus' => array( 'type' => 'boolean' ),
            'show_tagcloud' => array( 'type' => 'boolean' ), 'show_in_quick_edit' => array( 'type' => 'boolean' ), 'show_admin_column' => array( 'type' => 'boolean' ),
            'show_in_rest' => array( 'type' => 'boolean' ), 'rest_base' => array( 'type' => 'string' ), 'rest_namespace' => array( 'type' => 'string' ),
            'capabilities' => $caps, 'rewrite_slug' => array( 'type' => 'string', 'deprecated' => true ), 'rewrite' => $rewrite,
            'query_var' => array( 'oneOf' => array( array( 'type' => 'boolean' ), array( 'type' => 'string' ) ) ), 'sort' => array( 'type' => 'boolean' ),
            'default_term' => array( 'oneOf' => array( array( 'type' => 'null' ), array( 'type' => 'object', 'required' => array( 'name' ), 'additionalProperties' => false, 'properties' => array( 'name' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ) ) ) ) ),
        ) );
        if ( $creating ) $schema['required'] = array( 'key', 'label' );
        return $schema;
    }

    private static function term_schema( bool $creating ): array {
        $schema = array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
            'name' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ), 'parent' => array( 'type' => 'integer', 'minimum' => 0 ),
        ) );
        if ( $creating ) $schema['required'] = array( 'name' );
        return $schema;
    }

    private static function acf_group_schema( bool $creating ): array {
        $rule = array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
            'param' => array( 'type' => 'string' ), 'operator' => array( 'type' => 'string', 'enum' => array( '==', '!=' ) ), 'value' => array( 'type' => 'string' ),
        ), 'required' => array( 'param', 'operator', 'value' ) );
        $schema = array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
            'key' => array( 'type' => 'string', 'pattern' => '^group_' ), 'title' => array( 'type' => 'string' ),
            'active' => array( 'type' => 'boolean' ), 'location' => array( 'type' => 'array', 'items' => array( 'type' => 'array', 'items' => $rule ) ),
            'menu_order' => array( 'type' => 'integer' ), 'position' => array( 'type' => 'string', 'enum' => array( 'normal','side','acf_after_title' ) ),
            'style' => array( 'type' => 'string', 'enum' => array( 'default','seamless' ) ),
            'label_placement' => array( 'type' => 'string', 'enum' => array( 'top','left' ) ),
            'instruction_placement' => array( 'type' => 'string', 'enum' => array( 'label','field' ) ),
            'hide_on_screen' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
            'description' => array( 'type' => 'string' ), 'show_in_rest' => array( 'type' => 'boolean' ),
        ) );
        if ( $creating ) $schema['required'] = array( 'title', 'location' );
        return $schema;
    }

    private static function acf_field_schema( bool $creating ): array {
        $conditional_rule = array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
            'field' => array( 'type' => 'string', 'pattern' => '^field_' ),
            'operator' => array( 'type' => 'string', 'enum' => array( '==','!=','>','<','>=','<=','==contains','==pattern','==empty','!=empty' ) ),
            'value' => array( 'type' => 'string' ),
        ), 'required' => array( 'field', 'operator' ) );
        $wrapper = array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
            'width' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'integer' ) ) ), 'class' => array( 'type' => 'string' ), 'id' => array( 'type' => 'string' ),
        ) );
        $schema = array( 'type' => 'object', 'additionalProperties' => true, 'properties' => array(
            'key' => array( 'type' => 'string', 'pattern' => '^field_' ), 'parent_key' => array( 'type' => 'string' ),
            'label' => array( 'type' => 'string' ), 'name' => array( 'type' => 'string' ),
            'type' => array( 'type' => 'string', 'enum' => array( 'text','textarea','number','range','email','url','password','image','file','wysiwyg','select','checkbox','radio','button_group','true_false','date_picker','date_time_picker','time_picker','color_picker','message','accordion','tab','group','repeater','post_object','page_link','relationship','taxonomy','user','google_map','oembed','link','gallery','clone' ) ),
            'instructions' => array( 'type' => 'string' ), 'required' => array( 'type' => 'boolean' ), 'wrapper' => $wrapper,
            'conditional_logic' => array( 'oneOf' => array( array( 'type' => 'integer', 'enum' => array( 0 ) ), array( 'type' => 'array', 'items' => array( 'type' => 'array', 'items' => $conditional_rule ) ) ) ),
            'choices' => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'string' ) ),
            'sub_fields' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
            'confirm_structure_change' => array( 'type' => 'boolean' ),
        ) );
        if ( $creating ) $schema['required'] = array( 'label', 'name', 'type' );
        return $schema;
    }

    private static function woocommerce_product_schema( bool $creating ): array {
        $download = array(
            'type' => 'object', 'additionalProperties' => false,
            'required' => array( 'name', 'file' ),
            'properties' => array(
                'id' => array( 'type' => 'string' ), 'name' => array( 'type' => 'string' ), 'file' => array( 'type' => 'string', 'format' => 'uri' ),
            ),
        );
        $attribute = array(
            'type' => 'object', 'additionalProperties' => false,
            'properties' => array(
                'id' => array( 'type' => 'integer', 'minimum' => 0 ),
                'name' => array( 'type' => 'string' ),
                'options' => array( 'type' => 'array', 'items' => array( 'oneOf' => array( array( 'type' => 'integer' ), array( 'type' => 'string' ) ) ) ),
                'position' => array( 'type' => 'integer' ), 'visible' => array( 'type' => 'boolean' ), 'variation' => array( 'type' => 'boolean' ),
            ),
            'required' => array( 'options' ),
        );
        $variation = self::woocommerce_variation_schema( true );
        $schema = array(
            'type' => 'object', 'additionalProperties' => false,
            'properties' => array(
                'type' => array( 'type' => 'string', 'enum' => array( 'simple', 'variable' ) ),
                'name' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ),
                'status' => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private', 'publish' ) ),
                'description' => array( 'type' => 'string' ), 'short_description' => array( 'type' => 'string' ), 'purchase_note' => array( 'type' => 'string' ),
                'sku' => array( 'type' => 'string' ),
                'regular_price' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'number' ) ) ),
                'sale_price' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'number' ) ) ),
                'date_on_sale_from' => array( 'oneOf' => array( array( 'type' => 'string', 'format' => 'date-time' ), array( 'type' => 'null' ) ) ),
                'date_on_sale_to' => array( 'oneOf' => array( array( 'type' => 'string', 'format' => 'date-time' ), array( 'type' => 'null' ) ) ),
                'manage_stock' => array( 'type' => 'boolean' ), 'stock_quantity' => array( 'oneOf' => array( array( 'type' => 'number' ), array( 'type' => 'null' ) ) ),
                'low_stock_amount' => array( 'oneOf' => array( array( 'type' => 'number' ), array( 'type' => 'null' ) ) ),
                'stock_status' => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
                'backorders' => array( 'type' => 'string', 'enum' => array( 'no', 'notify', 'yes' ) ),
                'featured' => array( 'type' => 'boolean' ), 'catalog_visibility' => array( 'type' => 'string', 'enum' => array( 'visible', 'catalog', 'search', 'hidden' ) ),
                'virtual' => array( 'type' => 'boolean' ), 'downloadable' => array( 'type' => 'boolean' ), 'sold_individually' => array( 'type' => 'boolean' ), 'reviews_allowed' => array( 'type' => 'boolean' ),
                'tax_status' => array( 'type' => 'string', 'enum' => array( 'taxable', 'shipping', 'none' ) ), 'tax_class' => array( 'type' => 'string' ),
                'weight' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'number' ) ) ),
                'length' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'number' ) ) ),
                'width' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'number' ) ) ),
                'height' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'number' ) ) ),
                'shipping_class_id' => array( 'type' => 'integer', 'minimum' => 0 ),
                'category_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ), 'tag_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                'image_id' => array( 'type' => 'integer', 'minimum' => 0 ), 'gallery_image_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                'upsell_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ), 'cross_sell_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                'menu_order' => array( 'type' => 'integer' ), 'download_limit' => array( 'type' => 'integer', 'minimum' => -1 ), 'download_expiry' => array( 'type' => 'integer', 'minimum' => -1 ),
                'downloads' => array( 'type' => 'array', 'maxItems' => 50, 'items' => $download ),
                'attributes' => array( 'type' => 'array', 'maxItems' => 50, 'items' => $attribute ),
                'default_attributes' => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'string' ) ),
                'variations' => array( 'type' => 'array', 'maxItems' => 100, 'items' => $variation ),
            ),
        );
        if ( $creating ) $schema['required'] = array( 'name' );
        return $schema;
    }

    private static function woocommerce_variation_schema( bool $creating ): array {
        $download = array(
            'type' => 'object', 'additionalProperties' => false, 'required' => array( 'name', 'file' ),
            'properties' => array( 'id' => array( 'type' => 'string' ), 'name' => array( 'type' => 'string' ), 'file' => array( 'type' => 'string', 'format' => 'uri' ) ),
        );
        $schema = array(
            'type' => 'object', 'additionalProperties' => false,
            'properties' => array(
                'description' => array( 'type' => 'string' ), 'sku' => array( 'type' => 'string' ),
                'regular_price' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'number' ) ) ), 'sale_price' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'number' ) ) ),
                'date_on_sale_from' => array( 'oneOf' => array( array( 'type' => 'string', 'format' => 'date-time' ), array( 'type' => 'null' ) ) ), 'date_on_sale_to' => array( 'oneOf' => array( array( 'type' => 'string', 'format' => 'date-time' ), array( 'type' => 'null' ) ) ),
                'status' => array( 'type' => 'string', 'enum' => array( 'publish', 'private', 'draft', 'pending' ) ),
                'attributes' => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'string' ) ),
                'manage_stock' => array( 'type' => 'boolean' ), 'stock_quantity' => array( 'oneOf' => array( array( 'type' => 'number' ), array( 'type' => 'null' ) ) ),
                'stock_status' => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ), 'backorders' => array( 'type' => 'string', 'enum' => array( 'no', 'notify', 'yes' ) ),
                'virtual' => array( 'type' => 'boolean' ), 'downloadable' => array( 'type' => 'boolean' ), 'tax_status' => array( 'type' => 'string', 'enum' => array( 'taxable', 'shipping', 'none' ) ), 'tax_class' => array( 'type' => 'string' ),
                'weight' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'number' ) ) ), 'length' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'number' ) ) ),
                'width' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'number' ) ) ), 'height' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'number' ) ) ),
                'shipping_class_id' => array( 'type' => 'integer', 'minimum' => 0 ), 'image_id' => array( 'type' => 'integer', 'minimum' => 0 ), 'menu_order' => array( 'type' => 'integer' ),
                'download_limit' => array( 'type' => 'integer', 'minimum' => -1 ), 'download_expiry' => array( 'type' => 'integer', 'minimum' => -1 ), 'downloads' => array( 'type' => 'array', 'maxItems' => 50, 'items' => $download ),
            ),
        );
        if ( $creating ) $schema['required'] = array( 'attributes' );
        return $schema;
    }

    private static function woocommerce_catalog_operation_schema(): array {
        $object = array( 'type' => 'object', 'additionalProperties' => true );
        return array(
            'type' => 'object', 'additionalProperties' => false, 'required' => array( 'operation' ),
            'properties' => array(
                'operation' => array( 'type' => 'string', 'enum' => array(
                    'product.trash','product.restore','attribute.create','attribute.update','attribute.delete','attribute_term.create','attribute_term.update','attribute_term.delete',
                    'variation.create','variation.update','variation.trash','variation.restore','shipping_class.create','shipping_class.update','shipping_class.delete','tax_class.create','tax_class.delete',
                ) ),
                'product_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'variation_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                'attribute_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'term_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'slug' => array( 'type' => 'string' ),
                'data' => $object, 'confirm_delete' => array( 'type' => 'boolean' ), 'confirm_term_deletion' => array( 'type' => 'boolean' ), 'confirm_in_use' => array( 'type' => 'boolean' ),
            ),
        );
    }

    private static function transaction_schema(): array {
        $object = array( 'type' => 'object', 'additionalProperties' => true );
        $operation = array(
            'oneOf' => array(
                array(
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => array( 'action', 'id', 'type', 'data' ),
                    'properties' => array(
                        'action' => array( 'type' => 'string', 'enum' => array( 'content.update' ) ),
                        'id'     => array( 'type' => 'integer', 'minimum' => 1 ),
                        'type'   => array( 'type' => 'string', 'description' => 'WordPress post type key, for example page, post, or service.' ),
                        'data'   => $object,
                    ),
                ),
                array(
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => array( 'action', 'id', 'data' ),
                    'properties' => array(
                        'action' => array( 'type' => 'string', 'enum' => array( 'media.update' ) ),
                        'id'     => array( 'type' => 'integer', 'minimum' => 1 ),
                        'data'   => $object,
                    ),
                ),
                array(
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => array( 'action', 'post_id', 'data' ),
                    'properties' => array(
                        'action'  => array( 'type' => 'string', 'enum' => array( 'acf.update' ) ),
                        'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                        'data'    => array(
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => array( 'values' ),
                            'properties' => array( 'values' => $object ),
                        ),
                    ),
                ),
                array(
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => array( 'action', 'id', 'data' ),
                    'properties' => array(
                        'action' => array( 'type' => 'string', 'enum' => array( 'woocommerce.product.update' ) ),
                        'id'     => array( 'type' => 'integer', 'minimum' => 1 ),
                        'data'   => $object,
                    ),
                ),
            ),
        );

        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array( 'operations' ),
            'properties' => array(
                'operations' => array(
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 20,
                    'items' => $operation,
                ),
                'note' => array( 'type' => 'string' ),
            ),
        );
    }


    private static function menu_schema( bool $creating ): array {
        $schema = array(
            'type' => 'object',
            'properties' => array(
                'name' => array( 'type' => 'string', 'minLength' => 1 ),
                'slug' => array( 'type' => 'string' ),
                'description' => array( 'type' => 'string' ),
                'auto_add' => array( 'type' => 'boolean' ),
                'locations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'uniqueItems' => true ),
            ),
            'additionalProperties' => false,
        );
        if ( $creating ) $schema['required'] = array( 'name' );
        return $schema;
    }

    private static function menu_item_schema( bool $creating ): array {
        $schema = array(
            'type' => 'object',
            'properties' => array(
                'title' => array( 'type' => 'string' ),
                'type' => array( 'type' => 'string', 'enum' => array( 'custom','post_type','taxonomy','post_type_archive' ) ),
                'object' => array( 'type' => 'string' ),
                'object_id' => array( 'type' => 'integer', 'minimum' => 0 ),
                'url' => array( 'type' => 'string' ),
                'parent_id' => array( 'type' => 'integer', 'minimum' => 0 ),
                'position' => array( 'type' => 'integer', 'minimum' => 1 ),
                'description' => array( 'type' => 'string' ),
                'attr_title' => array( 'type' => 'string' ),
                'target' => array( 'type' => 'string', 'enum' => array( '', '_blank' ) ),
                'status' => array( 'type' => 'string', 'enum' => array( 'publish','draft' ) ),
                'classes' => array( 'oneOf' => array( array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), array( 'type' => 'string' ) ) ),
                'xfn' => array( 'type' => 'string' ),
            ),
            'additionalProperties' => false,
        );
        if ( $creating ) $schema['required'] = array( 'type' );
        return $schema;
    }

    private static function menu_reorder_schema(): array {
        return array(
            'type' => 'object',
            'required' => array( 'items' ),
            'properties' => array(
                'complete' => array( 'type' => 'boolean' ),
                'items' => array(
                    'type' => 'array', 'minItems' => 1,
                    'items' => array(
                        'type' => 'object', 'required' => array( 'item_id' ),
                        'properties' => array(
                            'item_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                            'position' => array( 'type' => 'integer', 'minimum' => 1 ),
                            'parent_id' => array( 'type' => 'integer', 'minimum' => 0 ),
                        ),
                        'additionalProperties' => false,
                    ),
                ),
            ),
            'additionalProperties' => false,
        );
    }

    private static function media_base64_schema(): array {
        return array(
            'type' => 'object', 'additionalProperties' => false,
            'required' => array( 'filename', 'data_base64' ),
            'properties' => array(
                'filename' => array( 'type' => 'string' ), 'data_base64' => array( 'type' => 'string' ), 'mime_type' => array( 'type' => 'string' ),
                'title' => array( 'type' => 'string' ), 'caption' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ), 'alt_text' => array( 'type' => 'string' ),
                'parent' => array( 'type' => 'integer' ), 'allow_duplicate' => array( 'type' => 'boolean' ), 'preserve_metadata' => array( 'type' => 'boolean' ),
            ),
        );
    }

    private static function media_edit_schema(): array {
        return array(
            'type' => 'object', 'additionalProperties' => false, 'required' => array( 'operation' ),
            'properties' => array(
                'operation' => array( 'type' => 'string', 'enum' => array( 'resize','crop','rotate','flip' ) ),
                'width' => array( 'type' => 'integer' ), 'height' => array( 'type' => 'integer' ),
                'x' => array( 'type' => 'integer' ), 'y' => array( 'type' => 'integer' ), 'target_width' => array( 'type' => 'integer' ), 'target_height' => array( 'type' => 'integer' ),
                'angle' => array( 'type' => 'number' ), 'horizontal' => array( 'type' => 'boolean' ), 'vertical' => array( 'type' => 'boolean' ),
                'crop' => array( 'oneOf' => array( array( 'type' => 'boolean' ), array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'minItems' => 2, 'maxItems' => 2 ) ) ),
            ),
        );
    }

    private static function multipart_media_body(): array {
        return array(
            'required' => true,
            'content' => array(
                'multipart/form-data' => array(
                    'schema' => array(
                        'type' => 'object',
                        'required' => array( 'file' ),
                        'properties' => array(
                            'file' => array( 'type' => 'string', 'format' => 'binary' ),
                            'title' => array( 'type' => 'string' ),
                            'caption' => array( 'type' => 'string' ),
                            'description' => array( 'type' => 'string' ),
                            'alt_text' => array( 'type' => 'string' ),
                            'parent' => array( 'type' => 'integer' ),
                        ),
                    ),
                ),
            ),
        );
    }

    private static function json_body( array $schema ): array {
        return array( 'required' => true, 'content' => array( 'application/json' => array( 'schema' => $schema ) ) );
    }

    private static function op( string $id, string $summary, bool $secured = true, ?array $body = null, array $parameters = array() ): array {
        $op = array( 'operationId' => $id, 'summary' => $summary, 'responses' => array( '200' => array( 'description' => 'Success' ) ) );
        if ( ! $secured ) { $op['security'] = array(); }
        if ( $body ) { $op['requestBody'] = $body; }
        if ( $parameters ) { $op['parameters'] = $parameters; }
        return $op;
    }

    private static function path_param( string $name, string $type ): array {
        return array( 'name' => $name, 'in' => 'path', 'required' => true, 'schema' => array( 'type' => $type ) );
    }

    private static function query_param( string $name, string $type ): array {
        return array( 'name' => $name, 'in' => 'query', 'required' => false, 'schema' => array( 'type' => $type ) );
    }
}
