<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce catalog adapter.
 *
 * All mutations are approval-gated at REST/MCP level. This class intentionally
 * uses WooCommerce CRUD/public APIs rather than writing product meta directly.
 */
final class ALIFY_AI_WooCommerce {
    private const PRODUCT_TYPES = array( 'simple', 'variable' );
    private const PRODUCT_STATUSES = array( 'draft', 'pending', 'private', 'publish', 'trash' );
    private const STOCK_STATUSES = array( 'instock', 'outofstock', 'onbackorder' );
    private const BACKORDER_VALUES = array( 'no', 'notify', 'yes' );
    private const TAX_STATUSES = array( 'taxable', 'shipping', 'none' );
    private const VISIBILITY_VALUES = array( 'visible', 'catalog', 'search', 'hidden' );
    private const MAX_ATTRIBUTES = 50;
    private const MAX_VARIATIONS_PER_CREATE = 100;
    private const MAX_DOWNLOADS = 50;

    public static function available(): bool {
        return class_exists( 'WooCommerce' )
            && function_exists( 'wc_get_products' )
            && function_exists( 'wc_get_product' )
            && class_exists( 'WC_Product' )
            && class_exists( 'WC_Product_Simple' )
            && class_exists( 'WC_Product_Variable' )
            && class_exists( 'WC_Product_Variation' );
    }

    public static function status(): array {
        return array(
            'active'                    => self::available(),
            'version'                   => defined( 'WC_VERSION' ) ? WC_VERSION : null,
            'catalog_reads'             => true,
            'product_changes'           => 'preview_then_approval',
            'attribute_changes'         => 'preview_then_approval',
            'variation_changes'         => 'preview_then_approval',
            'shipping_class_changes'    => 'preview_then_approval',
            'tax_class_changes'         => 'preview_then_approval',
            'orders'                    => class_exists( 'ALIFY_AI_Orders' ) ? ALIFY_AI_Orders::available() : false,
            'payments'                  => false,
            'refunds'                   => false,
            'customers'                 => false,
            'supported_product_create'  => self::PRODUCT_TYPES,
            'features'                  => array(
                'global_attributes' => function_exists( 'wc_create_attribute' ) && function_exists( 'wc_update_attribute' ) && function_exists( 'wc_delete_attribute' ),
                'variations'        => class_exists( 'WC_Product_Variation' ),
                'downloads'         => class_exists( 'WC_Product_Download' ),
                'shipping_classes'  => taxonomy_exists( 'product_shipping_class' ),
                'tax_classes'       => class_exists( 'WC_Tax' ) && method_exists( 'WC_Tax', 'create_tax_class' ),
            ),
        );
    }

    /* ---------------------------------------------------------------------
     * Products
     * ------------------------------------------------------------------ */

    public static function list_products( array $input = array() ) {
        $ready = self::require_available();
        if ( is_wp_error( $ready ) ) return $ready;

        $limit = max( 1, min( 100, (int) ( $input['per_page'] ?? 20 ) ) );
        $page  = max( 1, (int) ( $input['page'] ?? 1 ) );
        $args  = array(
            'limit'    => $limit,
            'page'     => $page,
            'paginate' => true,
            'orderby'  => 'modified',
            'order'    => 'DESC',
        );

        $status = sanitize_key( (string) ( $input['status'] ?? '' ) );
        if ( in_array( $status, self::PRODUCT_STATUSES, true ) ) $args['status'] = $status;
        $type = sanitize_key( (string) ( $input['type'] ?? '' ) );
        if ( in_array( $type, self::PRODUCT_TYPES, true ) ) $args['type'] = $type;
        $sku = sanitize_text_field( (string) ( $input['sku'] ?? '' ) );
        if ( '' !== $sku ) $args['sku'] = $sku;
        $name = sanitize_text_field( (string) ( $input['search'] ?? '' ) );
        if ( '' !== $name ) $args['name'] = $name;

        try {
            $result = wc_get_products( $args );
        } catch ( Throwable $error ) {
            return new WP_Error( 'alify_ai_woocommerce_query_failed', 'WooCommerce product query failed.', array( 'status' => 500 ) );
        }
        if ( ! is_object( $result ) || ! isset( $result->products ) ) {
            return new WP_Error( 'alify_ai_woocommerce_query_failed', 'WooCommerce product query failed.', array( 'status' => 500 ) );
        }

        return array(
            'items'       => array_map( static fn( $p ) => self::serialize_product( $p, false ), $result->products ),
            'total'       => (int) $result->total,
            'total_pages' => (int) $result->max_num_pages,
            'page'        => $page,
            'per_page'    => $limit,
        );
    }

    public static function get_product( int $id ) {
        $ready = self::require_available();
        if ( is_wp_error( $ready ) ) return $ready;
        $product = self::safe_get_product( $id );
        return is_wp_error( $product ) ? $product : self::serialize_product( $product, true );
    }

    public static function preview_create( array $input ) {
        $ready = self::require_available(); if ( is_wp_error( $ready ) ) return $ready;
        $payload = self::sanitize_product_payload( $input, true, 0 );
        if ( is_wp_error( $payload ) ) return $payload;
        $type = (string) ( $payload['type'] ?? 'simple' );
        return ALIFY_AI_Approvals::create(
            'woocommerce_product_create',
            0,
            array( 'payload' => $payload ),
            array(
                'action'       => 'create',
                'product_type' => $type,
                'changes'      => array_keys( $payload ),
                'proposed'     => $payload,
                'source_hash'  => null,
                'safety'       => self::safety_boundary(),
            )
        );
    }

    public static function preview_update( int $id, array $input ) {
        $product = self::safe_get_product( $id );
        if ( is_wp_error( $product ) ) return $product;
        $before = self::serialize_product( $product, true );
        $payload = self::sanitize_product_payload( $input, false, $id, $product );
        if ( is_wp_error( $payload ) ) return $payload;
        if ( empty( $payload ) ) return new WP_Error( 'alify_ai_no_changes', 'No supported product fields were supplied.', array( 'status' => 400 ) );
        return ALIFY_AI_Approvals::create(
            'woocommerce_product_update',
            $id,
            array( 'payload' => $payload ),
            array(
                'action'      => 'update',
                'changes'     => array_keys( $payload ),
                'before'      => $before,
                'proposed'    => array_merge( $before, $payload ),
                'source_hash' => self::hash_snapshot( $before ),
                'safety'      => self::safety_boundary(),
            )
        );
    }

    public static function preview_product_lifecycle( int $id, string $action ) {
        $action = sanitize_key( $action );
        if ( ! in_array( $action, array( 'trash', 'restore' ), true ) ) {
            return new WP_Error( 'alify_ai_invalid_product_action', 'Product lifecycle action must be trash or restore.', array( 'status' => 400 ) );
        }
        $product = self::safe_get_product( $id );
        if ( is_wp_error( $product ) ) return $product;
        $snapshot = self::serialize_product( $product, true );
        if ( 'trash' === $action && 'trash' === $snapshot['status'] ) return new WP_Error( 'alify_ai_product_already_trashed', 'Product is already in Trash.', array( 'status' => 409 ) );
        if ( 'restore' === $action && 'trash' !== $snapshot['status'] ) return new WP_Error( 'alify_ai_product_not_trashed', 'Only a trashed product can be restored.', array( 'status' => 409 ) );
        return self::preview_catalog_operation(
            'product.' . $action,
            $id,
            array( 'product_id' => $id ),
            array( 'action' => $action, 'before' => $snapshot, 'source_hash' => self::hash_snapshot( $snapshot ) )
        );
    }

    /* ---------------------------------------------------------------------
     * Global attributes and terms
     * ------------------------------------------------------------------ */

    public static function list_attributes() {
        $ready = self::require_available(); if ( is_wp_error( $ready ) ) return $ready;
        if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) return array( 'items' => array(), 'total' => 0 );
        try { $rows = wc_get_attribute_taxonomies(); } catch ( Throwable $e ) { return new WP_Error( 'alify_ai_attribute_query_failed', 'Could not load WooCommerce attributes.', array( 'status' => 500 ) ); }
        $items = array();
        foreach ( (array) $rows as $row ) {
            $id = absint( is_object( $row ) ? ( $row->attribute_id ?? 0 ) : ( $row['attribute_id'] ?? 0 ) );
            if ( $id ) {
                $item = self::get_attribute( $id );
                if ( ! is_wp_error( $item ) ) $items[] = $item;
            }
        }
        return array( 'items' => $items, 'total' => count( $items ) );
    }

    public static function get_attribute( int $id ) {
        $ready = self::require_available(); if ( is_wp_error( $ready ) ) return $ready;
        if ( ! function_exists( 'wc_get_attribute' ) ) return new WP_Error( 'alify_ai_attribute_api_unavailable', 'WooCommerce attribute API is unavailable.', array( 'status' => 409 ) );
        try { $attr = wc_get_attribute( $id ); } catch ( Throwable $e ) { $attr = null; }
        if ( ! $attr ) return new WP_Error( 'alify_ai_attribute_not_found', 'WooCommerce attribute not found.', array( 'status' => 404 ) );
        $item = self::serialize_global_attribute( $attr );
        $taxonomy = (string) ( $item['taxonomy'] ?? '' );
        $item['term_count'] = taxonomy_exists( $taxonomy ) ? max( 0, (int) wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) ) : 0;
        return $item;
    }

    public static function preview_attribute_create( array $input ) {
        $payload = self::sanitize_global_attribute_payload( $input, true );
        if ( is_wp_error( $payload ) ) return $payload;
        return self::preview_catalog_operation( 'attribute.create', 0, array( 'payload' => $payload ), array( 'action' => 'create_attribute', 'proposed' => $payload ) );
    }

    public static function preview_attribute_update( int $id, array $input ) {
        $before = self::get_attribute( $id ); if ( is_wp_error( $before ) ) return $before;
        $payload = self::sanitize_global_attribute_payload( $input, false ); if ( is_wp_error( $payload ) ) return $payload;
        if ( empty( $payload ) ) return new WP_Error( 'alify_ai_no_changes', 'No attribute changes supplied.', array( 'status' => 400 ) );
        return self::preview_catalog_operation( 'attribute.update', $id, array( 'attribute_id' => $id, 'payload' => $payload ), array( 'action' => 'update_attribute', 'before' => $before, 'proposed' => array_merge( $before, $payload ), 'source_hash' => self::hash_snapshot( $before ) ) );
    }

    public static function preview_attribute_delete( int $id, bool $confirm_delete = false, bool $confirm_term_deletion = false ) {
        if ( ! $confirm_delete ) return new WP_Error( 'alify_ai_confirmation_required', 'Deleting a global product attribute requires confirm_delete=true.', array( 'status' => 409 ) );
        $before = self::get_attribute( $id ); if ( is_wp_error( $before ) ) return $before;
        $taxonomy = (string) ( $before['taxonomy'] ?? '' );
        $term_count = taxonomy_exists( $taxonomy ) ? (int) wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) : 0;
        if ( $term_count > 0 && ! $confirm_term_deletion ) return new WP_Error( 'alify_ai_attribute_has_terms', 'WooCommerce deletes this attribute’s terms too. Set confirm_term_deletion=true to continue with the rollback-backed proposal.', array( 'status' => 409, 'term_count' => $term_count ) );
        return self::preview_catalog_operation( 'attribute.delete', $id, array( 'attribute_id' => $id, 'confirm_delete' => true, 'confirm_term_deletion' => $confirm_term_deletion ), array( 'action' => 'delete_attribute', 'before' => $before, 'term_count' => max( 0, $term_count ), 'source_hash' => self::hash_snapshot( $before ) ) );
    }

    public static function list_attribute_terms( int $attribute_id, array $input = array() ) {
        $attr = self::get_attribute( $attribute_id ); if ( is_wp_error( $attr ) ) return $attr;
        $taxonomy = (string) $attr['taxonomy'];
        if ( ! taxonomy_exists( $taxonomy ) ) return new WP_Error( 'alify_ai_attribute_taxonomy_unavailable', 'The attribute taxonomy is not registered yet. Retry on the next request.', array( 'status' => 409 ) );
        $args = array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => max( 1, min( 100, (int) ( $input['per_page'] ?? 100 ) ) ) );
        if ( ! empty( $input['search'] ) ) $args['search'] = sanitize_text_field( (string) $input['search'] );
        $terms = get_terms( $args );
        if ( is_wp_error( $terms ) ) return $terms;
        return array( 'items' => array_map( array( __CLASS__, 'serialize_term' ), $terms ), 'total' => count( $terms ), 'attribute' => $attr );
    }

    public static function get_attribute_term( int $attribute_id, int $term_id ) {
        $attr = self::get_attribute( $attribute_id ); if ( is_wp_error( $attr ) ) return $attr;
        $term = get_term( $term_id, (string) $attr['taxonomy'] );
        if ( ! $term || is_wp_error( $term ) ) return new WP_Error( 'alify_ai_attribute_term_not_found', 'Attribute term not found.', array( 'status' => 404 ) );
        return self::serialize_term( $term );
    }

    public static function preview_attribute_term_create( int $attribute_id, array $input ) {
        $attr = self::get_attribute( $attribute_id ); if ( is_wp_error( $attr ) ) return $attr;
        $payload = self::sanitize_term_payload( $input, true ); if ( is_wp_error( $payload ) ) return $payload;
        return self::preview_catalog_operation( 'attribute_term.create', 0, array( 'attribute_id' => $attribute_id, 'payload' => $payload ), array( 'action' => 'create_attribute_term', 'attribute' => $attr, 'proposed' => $payload ) );
    }

    public static function preview_attribute_term_update( int $attribute_id, int $term_id, array $input ) {
        $before = self::get_attribute_term( $attribute_id, $term_id ); if ( is_wp_error( $before ) ) return $before;
        $payload = self::sanitize_term_payload( $input, false ); if ( is_wp_error( $payload ) ) return $payload;
        if ( empty( $payload ) ) return new WP_Error( 'alify_ai_no_changes', 'No term changes supplied.', array( 'status' => 400 ) );
        return self::preview_catalog_operation( 'attribute_term.update', $term_id, array( 'attribute_id' => $attribute_id, 'term_id' => $term_id, 'payload' => $payload ), array( 'action' => 'update_attribute_term', 'before' => $before, 'proposed' => array_merge( $before, $payload ), 'source_hash' => self::hash_snapshot( $before ) ) );
    }

    public static function preview_attribute_term_delete( int $attribute_id, int $term_id, bool $confirm_delete = false ) {
        if ( ! $confirm_delete ) return new WP_Error( 'alify_ai_confirmation_required', 'Deleting an attribute term requires confirm_delete=true.', array( 'status' => 409 ) );
        $before = self::get_attribute_term( $attribute_id, $term_id ); if ( is_wp_error( $before ) ) return $before;
        return self::preview_catalog_operation( 'attribute_term.delete', $term_id, array( 'attribute_id' => $attribute_id, 'term_id' => $term_id, 'confirm_delete' => true ), array( 'action' => 'delete_attribute_term', 'before' => $before, 'source_hash' => self::hash_snapshot( $before ) ) );
    }

    /* ---------------------------------------------------------------------
     * Variations
     * ------------------------------------------------------------------ */

    public static function list_variations( int $product_id, array $input = array() ) {
        $parent = self::safe_variable_product( $product_id ); if ( is_wp_error( $parent ) ) return $parent;
        $page = max( 1, absint( $input['page'] ?? 1 ) );
        $per_page = max( 1, min( 100, absint( $input['per_page'] ?? 50 ) ) );
        $children = array_map( 'absint', (array) $parent->get_children() );
        $total = count( $children );
        $slice = array_slice( $children, ( $page - 1 ) * $per_page, $per_page );
        $items = array();
        foreach ( $slice as $variation_id ) {
            $variation = self::safe_get_product( $variation_id );
            if ( $variation instanceof WC_Product_Variation ) $items[] = self::serialize_variation( $variation, true );
        }
        usort( $items, static fn( $a, $b ) => ( $a['menu_order'] <=> $b['menu_order'] ) ?: ( $a['id'] <=> $b['id'] ) );
        return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'pages' => max( 1, (int) ceil( $total / $per_page ) ), 'product_id' => $product_id );
    }

    public static function get_variation( int $product_id, int $variation_id ) {
        $parent = self::safe_variable_product( $product_id ); if ( is_wp_error( $parent ) ) return $parent;
        $variation = self::safe_get_product( $variation_id );
        if ( is_wp_error( $variation ) || ! $variation instanceof WC_Product_Variation || (int) $variation->get_parent_id() !== $product_id ) {
            return new WP_Error( 'alify_ai_variation_not_found', 'Product variation not found for this variable product.', array( 'status' => 404 ) );
        }
        return self::serialize_variation( $variation, true );
    }

    public static function preview_variation_create( int $product_id, array $input ) {
        $parent = self::safe_variable_product( $product_id ); if ( is_wp_error( $parent ) ) return $parent;
        $payload = self::sanitize_variation_payload( $input, true, $parent, 0 ); if ( is_wp_error( $payload ) ) return $payload;
        return self::preview_catalog_operation( 'variation.create', 0, array( 'product_id' => $product_id, 'payload' => $payload ), array( 'action' => 'create_variation', 'product_id' => $product_id, 'proposed' => $payload, 'parent_hash' => self::hash_snapshot( self::serialize_product( $parent, true ) ) ) );
    }

    public static function preview_variation_update( int $product_id, int $variation_id, array $input ) {
        $before = self::get_variation( $product_id, $variation_id ); if ( is_wp_error( $before ) ) return $before;
        $parent = self::safe_variable_product( $product_id ); if ( is_wp_error( $parent ) ) return $parent;
        $payload = self::sanitize_variation_payload( $input, false, $parent, $variation_id ); if ( is_wp_error( $payload ) ) return $payload;
        if ( empty( $payload ) ) return new WP_Error( 'alify_ai_no_changes', 'No variation changes supplied.', array( 'status' => 400 ) );
        return self::preview_catalog_operation( 'variation.update', $variation_id, array( 'product_id' => $product_id, 'variation_id' => $variation_id, 'payload' => $payload ), array( 'action' => 'update_variation', 'before' => $before, 'proposed' => array_merge( $before, $payload ), 'source_hash' => self::hash_snapshot( $before ) ) );
    }

    public static function preview_variation_lifecycle( int $product_id, int $variation_id, string $action ) {
        $action = sanitize_key( $action );
        if ( ! in_array( $action, array( 'trash', 'restore' ), true ) ) return new WP_Error( 'alify_ai_invalid_variation_action', 'Variation action must be trash or restore.', array( 'status' => 400 ) );
        $before = self::get_variation( $product_id, $variation_id ); if ( is_wp_error( $before ) ) return $before;
        if ( 'trash' === $action && 'trash' === $before['status'] ) return new WP_Error( 'alify_ai_variation_already_trashed', 'Variation is already in Trash.', array( 'status' => 409 ) );
        if ( 'restore' === $action && 'trash' !== $before['status'] ) return new WP_Error( 'alify_ai_variation_not_trashed', 'Only a trashed variation can be restored.', array( 'status' => 409 ) );
        return self::preview_catalog_operation( 'variation.' . $action, $variation_id, array( 'product_id' => $product_id, 'variation_id' => $variation_id ), array( 'action' => $action . '_variation', 'before' => $before, 'source_hash' => self::hash_snapshot( $before ) ) );
    }

    /* ---------------------------------------------------------------------
     * Shipping and tax classes
     * ------------------------------------------------------------------ */

    public static function list_shipping_classes() {
        $ready = self::require_available(); if ( is_wp_error( $ready ) ) return $ready;
        if ( ! taxonomy_exists( 'product_shipping_class' ) ) return new WP_Error( 'alify_ai_shipping_classes_unavailable', 'WooCommerce shipping classes taxonomy is unavailable.', array( 'status' => 409 ) );
        $terms = get_terms( array( 'taxonomy' => 'product_shipping_class', 'hide_empty' => false ) );
        if ( is_wp_error( $terms ) ) return $terms;
        return array( 'items' => array_map( array( __CLASS__, 'serialize_term' ), $terms ), 'total' => count( $terms ) );
    }

    public static function get_shipping_class( int $id ) {
        $term = get_term( $id, 'product_shipping_class' );
        if ( ! $term || is_wp_error( $term ) ) return new WP_Error( 'alify_ai_shipping_class_not_found', 'Shipping class not found.', array( 'status' => 404 ) );
        $item = self::serialize_term( $term );
        $item['product_count'] = (int) $term->count;
        return $item;
    }

    public static function preview_shipping_class_create( array $input ) {
        $payload = self::sanitize_term_payload( $input, true ); if ( is_wp_error( $payload ) ) return $payload;
        return self::preview_catalog_operation( 'shipping_class.create', 0, array( 'payload' => $payload ), array( 'action' => 'create_shipping_class', 'proposed' => $payload ) );
    }

    public static function preview_shipping_class_update( int $id, array $input ) {
        $before = self::get_shipping_class( $id ); if ( is_wp_error( $before ) ) return $before;
        $payload = self::sanitize_term_payload( $input, false ); if ( is_wp_error( $payload ) ) return $payload;
        if ( empty( $payload ) ) return new WP_Error( 'alify_ai_no_changes', 'No shipping-class changes supplied.', array( 'status' => 400 ) );
        return self::preview_catalog_operation( 'shipping_class.update', $id, array( 'term_id' => $id, 'payload' => $payload ), array( 'action' => 'update_shipping_class', 'before' => $before, 'proposed' => array_merge( $before, $payload ), 'source_hash' => self::hash_snapshot( $before ) ) );
    }

    public static function preview_shipping_class_delete( int $id, bool $confirm_delete = false, bool $confirm_in_use = false ) {
        if ( ! $confirm_delete ) return new WP_Error( 'alify_ai_confirmation_required', 'Deleting a shipping class requires confirm_delete=true.', array( 'status' => 409 ) );
        $before = self::get_shipping_class( $id ); if ( is_wp_error( $before ) ) return $before;
        if ( ! empty( $before['product_count'] ) && ! $confirm_in_use ) return new WP_Error( 'alify_ai_shipping_class_in_use', 'This shipping class is assigned to products. Set confirm_in_use=true to remove the class relationships.', array( 'status' => 409, 'product_count' => (int) $before['product_count'] ) );
        return self::preview_catalog_operation( 'shipping_class.delete', $id, array( 'term_id' => $id, 'confirm_delete' => true, 'confirm_in_use' => $confirm_in_use ), array( 'action' => 'delete_shipping_class', 'before' => $before, 'source_hash' => self::hash_snapshot( $before ) ) );
    }

    public static function list_tax_classes() {
        $ready = self::require_available(); if ( is_wp_error( $ready ) ) return $ready;
        if ( ! class_exists( 'WC_Tax' ) ) return new WP_Error( 'alify_ai_tax_classes_unavailable', 'WooCommerce tax classes are unavailable.', array( 'status' => 409 ) );
        $items = array( array( 'id' => 0, 'name' => 'Standard rate', 'slug' => 'standard', 'standard' => true, 'product_count' => self::tax_class_product_count( '' ), 'rate_count' => self::tax_class_rate_count( '' ) ) );
        try { $classes = WC_Tax::get_tax_rate_classes(); } catch ( Throwable $e ) { $classes = array(); }
        foreach ( (array) $classes as $class ) {
            $id = absint( is_object( $class ) ? ( $class->tax_rate_class_id ?? 0 ) : ( $class['tax_rate_class_id'] ?? 0 ) );
            $name = sanitize_text_field( (string) ( is_object( $class ) ? ( $class->name ?? '' ) : ( $class['name'] ?? '' ) ) );
            $slug = sanitize_title( (string) ( is_object( $class ) ? ( $class->slug ?? '' ) : ( $class['slug'] ?? '' ) ) );
            if ( $slug ) $items[] = array( 'id' => $id, 'name' => $name, 'slug' => $slug, 'standard' => false, 'product_count' => self::tax_class_product_count( $slug ), 'rate_count' => self::tax_class_rate_count( $slug ) );
        }
        return array( 'items' => $items, 'total' => count( $items ) );
    }

    public static function get_tax_class( string $slug ) {
        $slug = sanitize_title( $slug );
        if ( '' === $slug || 'standard' === $slug ) return array( 'id' => 0, 'name' => 'Standard rate', 'slug' => 'standard', 'standard' => true, 'product_count' => self::tax_class_product_count( '' ), 'rate_count' => self::tax_class_rate_count( '' ) );
        if ( ! class_exists( 'WC_Tax' ) || ! method_exists( 'WC_Tax', 'get_tax_class_by' ) ) return new WP_Error( 'alify_ai_tax_classes_unavailable', 'WooCommerce tax classes are unavailable.', array( 'status' => 409 ) );
        try { $class = WC_Tax::get_tax_class_by( 'slug', $slug ); } catch ( Throwable $e ) { $class = false; }
        if ( ! $class || is_wp_error( $class ) ) return new WP_Error( 'alify_ai_tax_class_not_found', 'Tax class not found.', array( 'status' => 404 ) );
        return array( 'name' => sanitize_text_field( (string) $class['name'] ), 'slug' => sanitize_title( (string) $class['slug'] ), 'standard' => false, 'product_count' => self::tax_class_product_count( $slug ), 'rate_count' => self::tax_class_rate_count( $slug ) );
    }

    public static function preview_tax_class_create( array $input ) {
        $name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
        $slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
        if ( '' === $name ) return new WP_Error( 'alify_ai_tax_class_name_required', 'Tax class name is required.', array( 'status' => 400 ) );
        $payload = array( 'name' => $name ); if ( '' !== $slug ) $payload['slug'] = $slug;
        return self::preview_catalog_operation( 'tax_class.create', 0, array( 'payload' => $payload ), array( 'action' => 'create_tax_class', 'proposed' => $payload ) );
    }

    public static function preview_tax_class_delete( string $slug, bool $confirm_delete = false, bool $confirm_in_use = false ) {
        $slug = sanitize_title( $slug );
        if ( '' === $slug || 'standard' === $slug ) return new WP_Error( 'alify_ai_standard_tax_class_protected', 'The Standard tax class cannot be deleted.', array( 'status' => 409 ) );
        if ( ! $confirm_delete ) return new WP_Error( 'alify_ai_confirmation_required', 'Deleting a tax class requires confirm_delete=true.', array( 'status' => 409 ) );
        $before = self::get_tax_class( $slug ); if ( is_wp_error( $before ) ) return $before;
        if ( ! empty( $before['rate_count'] ) ) return new WP_Error( 'alify_ai_tax_class_has_rates', 'This tax class has configured tax rates. The connector will not delete it because WooCommerce does not expose a rollback-safe public tax-rate recreation API.', array( 'status' => 409, 'rate_count' => (int) $before['rate_count'] ) );
        if ( ! empty( $before['product_count'] ) && ! $confirm_in_use ) return new WP_Error( 'alify_ai_tax_class_in_use', 'Products currently reference this tax class. Set confirm_in_use=true to continue.', array( 'status' => 409, 'product_count' => (int) $before['product_count'] ) );
        return self::preview_catalog_operation( 'tax_class.delete', 0, array( 'slug' => $slug, 'confirm_delete' => true, 'confirm_in_use' => $confirm_in_use ), array( 'action' => 'delete_tax_class', 'before' => $before, 'source_hash' => self::hash_snapshot( $before ) ) );
    }

    /* ---------------------------------------------------------------------
     * Approval application
     * ------------------------------------------------------------------ */

    public static function apply_approval( array $approval ) {
        $ready = self::require_available(); if ( is_wp_error( $ready ) ) return $ready;
        if ( 'woocommerce_product_create' === $approval['kind'] ) return self::apply_product_create( $approval );
        if ( 'woocommerce_product_update' === $approval['kind'] ) return self::apply_product_update( $approval );
        if ( 'woocommerce_catalog' !== $approval['kind'] ) return new WP_Error( 'alify_ai_invalid_approval_kind', 'Unsupported WooCommerce approval kind.', array( 'status' => 400 ) );
        return self::apply_catalog_operation( $approval );
    }

    private static function apply_product_create( array $approval ) {
        $payload = (array) ( $approval['request']['payload'] ?? array() );
        $type = (string) ( $payload['type'] ?? 'simple' );
        $product = 'variable' === $type ? new WC_Product_Variable() : new WC_Product_Simple();
        $variations = (array) ( $payload['variations'] ?? array() );
        unset( $payload['type'], $payload['variations'] );
        $result = self::apply_product_payload( $product, $payload ); if ( is_wp_error( $result ) ) return $result;
        $product_id = self::safe_save_product( $product ); if ( is_wp_error( $product_id ) ) return $product_id;

        $created_variations = array();
        if ( 'variable' === $type && $variations ) {
            $fresh_parent = self::safe_variable_product( $product_id );
            if ( is_wp_error( $fresh_parent ) ) { wp_trash_post( $product_id ); return $fresh_parent; }
            foreach ( $variations as $variation_payload ) {
                $clean = self::sanitize_variation_payload( (array) $variation_payload, true, $fresh_parent, 0 );
                if ( is_wp_error( $clean ) ) { wp_trash_post( $product_id ); return $clean; }
                $created = self::create_variation_direct( $fresh_parent, $clean );
                if ( is_wp_error( $created ) ) { wp_trash_post( $product_id ); return $created; }
                $created_variations[] = (int) $created['id'];
            }
            self::sync_variable_product( $product_id );
        }

        $saved = self::safe_get_product( $product_id );
        if ( is_wp_error( $saved ) ) { wp_trash_post( $product_id ); return $saved; }
        $after = self::serialize_product( $saved, true );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( null, $after );
        if ( is_wp_error( $snapshot_ok ) ) { wp_trash_post( $product_id ); return $snapshot_ok; }
        $activity_id = ALIFY_AI_Audit::log( 'create_product', 'product', $product_id, null, $after );
        if ( ! $activity_id ) { wp_trash_post( $product_id ); return new WP_Error( 'alify_ai_audit_failed', 'Product creation was reverted because the activity log could not be stored.', array( 'status' => 500 ) ); }
        return array( 'message' => 'WooCommerce product created.', 'activity_id' => $activity_id, 'item' => $after, 'created_variation_ids' => $created_variations );
    }

    private static function apply_product_update( array $approval ) {
        $payload = (array) ( $approval['request']['payload'] ?? array() );
        $product = self::safe_get_product( (int) $approval['object_id'] ); if ( is_wp_error( $product ) ) return $product;
        $before = self::serialize_product( $product, true );
        $expected = (string) ( $approval['preview']['source_hash'] ?? '' );
        if ( '' === $expected || ! hash_equals( $expected, self::hash_snapshot( $before ) ) ) return new WP_Error( 'alify_ai_stale_product', 'Product changed after preview. Create a fresh proposal before applying.', array( 'status' => 409 ) );
        $result = self::apply_product_payload( $product, $payload ); if ( is_wp_error( $result ) ) return $result;
        $saved_id = self::safe_save_product( $product ); if ( is_wp_error( $saved_id ) ) return $saved_id;
        if ( $product instanceof WC_Product_Variable ) self::sync_variable_product( $product->get_id() );
        $fresh = self::safe_get_product( $product->get_id() );
        if ( is_wp_error( $fresh ) ) { self::restore_snapshot( $product->get_id(), $before ); return $fresh; }
        $after = self::serialize_product( $fresh, true );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before, $after );
        if ( is_wp_error( $snapshot_ok ) ) { self::restore_snapshot( $product->get_id(), $before ); return $snapshot_ok; }
        $activity_id = ALIFY_AI_Audit::log( 'update_product', 'product', $product->get_id(), $before, $after );
        if ( ! $activity_id ) { self::restore_snapshot( $product->get_id(), $before ); return new WP_Error( 'alify_ai_audit_failed', 'Product update was reverted because the activity log could not be stored.', array( 'status' => 500 ) ); }
        return array( 'message' => 'WooCommerce product updated.', 'activity_id' => $activity_id, 'item' => $after );
    }

    private static function apply_catalog_operation( array $approval ) {
        $request = (array) ( $approval['request'] ?? array() );
        $op = sanitize_key( str_replace( '.', '_', (string) ( $request['operation'] ?? '' ) ) );
        $expected = (string) ( $approval['preview']['source_hash'] ?? '' );
        if ( 'variation.create' === (string) ( $request['operation'] ?? '' ) ) {
            $parent = self::safe_variable_product( absint( $request['product_id'] ?? 0 ) );
            if ( is_wp_error( $parent ) ) return $parent;
            $expected_parent = (string) ( $approval['preview']['parent_hash'] ?? '' );
            if ( '' === $expected_parent || ! hash_equals( $expected_parent, self::hash_snapshot( self::serialize_product( $parent, true ) ) ) ) return new WP_Error( 'alify_ai_stale_variable_product', 'Parent variable product changed after preview. Create a fresh variation proposal.', array( 'status' => 409 ) );
        }
        if ( '' !== $expected ) {
            $current = self::current_catalog_snapshot( $request );
            if ( is_wp_error( $current ) ) return $current;
            if ( ! hash_equals( $expected, self::hash_snapshot( $current ) ) ) return new WP_Error( 'alify_ai_stale_woocommerce_resource', 'WooCommerce resource changed after preview. Create a fresh proposal.', array( 'status' => 409 ) );
        }

        switch ( $op ) {
            case 'product_trash': return self::apply_product_trash( absint( $request['product_id'] ?? 0 ) );
            case 'product_restore': return self::apply_product_restore( absint( $request['product_id'] ?? 0 ) );
            case 'attribute_create': return self::apply_attribute_create( (array) ( $request['payload'] ?? array() ) );
            case 'attribute_update': return self::apply_attribute_update( absint( $request['attribute_id'] ?? 0 ), (array) ( $request['payload'] ?? array() ) );
            case 'attribute_delete': return self::apply_attribute_delete( absint( $request['attribute_id'] ?? 0 ) );
            case 'attribute_term_create': return self::apply_attribute_term_create( absint( $request['attribute_id'] ?? 0 ), (array) ( $request['payload'] ?? array() ) );
            case 'attribute_term_update': return self::apply_attribute_term_update( absint( $request['attribute_id'] ?? 0 ), absint( $request['term_id'] ?? 0 ), (array) ( $request['payload'] ?? array() ) );
            case 'attribute_term_delete': return self::apply_attribute_term_delete( absint( $request['attribute_id'] ?? 0 ), absint( $request['term_id'] ?? 0 ) );
            case 'variation_create': return self::apply_variation_create( absint( $request['product_id'] ?? 0 ), (array) ( $request['payload'] ?? array() ) );
            case 'variation_update': return self::apply_variation_update( absint( $request['product_id'] ?? 0 ), absint( $request['variation_id'] ?? 0 ), (array) ( $request['payload'] ?? array() ) );
            case 'variation_trash': return self::apply_variation_trash( absint( $request['product_id'] ?? 0 ), absint( $request['variation_id'] ?? 0 ) );
            case 'variation_restore': return self::apply_variation_restore( absint( $request['product_id'] ?? 0 ), absint( $request['variation_id'] ?? 0 ) );
            case 'shipping_class_create': return self::apply_shipping_class_create( (array) ( $request['payload'] ?? array() ) );
            case 'shipping_class_update': return self::apply_shipping_class_update( absint( $request['term_id'] ?? 0 ), (array) ( $request['payload'] ?? array() ) );
            case 'shipping_class_delete': return self::apply_shipping_class_delete( absint( $request['term_id'] ?? 0 ) );
            case 'tax_class_create': return self::apply_tax_class_create( (array) ( $request['payload'] ?? array() ) );
            case 'tax_class_delete': return self::apply_tax_class_delete( sanitize_title( (string) ( $request['slug'] ?? '' ) ) );
        }
        return new WP_Error( 'alify_ai_invalid_catalog_operation', 'Unsupported WooCommerce catalog operation.', array( 'status' => 400 ) );
    }

    /* ---------------------------------------------------------------------
     * Mutation implementations
     * ------------------------------------------------------------------ */

    private static function apply_product_trash( int $id ) {
        $before = self::get_product( $id ); if ( is_wp_error( $before ) ) return $before;
        $trashed = wp_trash_post( $id ); if ( ! $trashed ) return new WP_Error( 'alify_ai_product_trash_failed', 'Could not move product to Trash.', array( 'status' => 500 ) );
        $after_product = self::safe_get_product( $id ); $after = is_wp_error( $after_product ) ? array( 'id' => $id, 'status' => 'trash' ) : self::serialize_product( $after_product, true );
        return self::audit_or_revert( 'woocommerce_product_trash', 'product', $id, $before, $after, static function () use ( $id ) { return wp_untrash_post( $id ); } );
    }

    private static function apply_product_restore( int $id ) {
        $before = self::get_product( $id ); if ( is_wp_error( $before ) ) return $before;
        if ( ! wp_untrash_post( $id ) ) return new WP_Error( 'alify_ai_product_restore_failed', 'Could not restore product from Trash.', array( 'status' => 500 ) );
        $product = self::safe_get_product( $id ); if ( is_wp_error( $product ) ) return $product;
        $after = self::serialize_product( $product, true );
        return self::audit_or_revert( 'woocommerce_product_restore', 'product', $id, $before, $after, static function () use ( $id ) { return wp_trash_post( $id ); } );
    }

    private static function apply_attribute_create( array $payload ) {
        if ( ! function_exists( 'wc_create_attribute' ) ) return new WP_Error( 'alify_ai_attribute_api_unavailable', 'WooCommerce attribute API is unavailable.', array( 'status' => 409 ) );
        try { $id = wc_create_attribute( $payload ); } catch ( Throwable $e ) { return new WP_Error( 'alify_ai_attribute_create_failed', $e->getMessage(), array( 'status' => 500 ) ); }
        if ( is_wp_error( $id ) ) return $id;
        $after = self::get_attribute( (int) $id ); if ( is_wp_error( $after ) ) { wc_delete_attribute( (int) $id ); return $after; }
        return self::audit_or_revert( 'woocommerce_attribute_create', 'product_attribute', (int) $id, null, $after, static function () use ( $id ) { return wc_delete_attribute( (int) $id ); } );
    }

    private static function apply_attribute_update( int $id, array $payload ) {
        $before = self::get_attribute( $id ); if ( is_wp_error( $before ) ) return $before;
        try { $result = wc_update_attribute( $id, $payload ); } catch ( Throwable $e ) { return new WP_Error( 'alify_ai_attribute_update_failed', $e->getMessage(), array( 'status' => 500 ) ); }
        if ( is_wp_error( $result ) ) return $result;
        $after = self::get_attribute( $id ); if ( is_wp_error( $after ) ) return $after;
        return self::audit_or_revert( 'woocommerce_attribute_update', 'product_attribute', $id, $before, $after, static function () use ( $id, $before ) { return wc_update_attribute( $id, self::attribute_snapshot_to_payload( $before ) ); } );
    }

    private static function apply_attribute_delete( int $id ) {
        $before = self::snapshot_attribute_for_delete( $id );
        if ( is_wp_error( $before ) ) return $before;
        $safe = ALIFY_AI_Audit::validate_snapshot( $before, null );
        if ( is_wp_error( $safe ) ) return $safe;
        try { $deleted = wc_delete_attribute( $id ); } catch ( Throwable $e ) { return new WP_Error( 'alify_ai_attribute_delete_failed', $e->getMessage(), array( 'status' => 500 ) ); }
        if ( ! $deleted ) return new WP_Error( 'alify_ai_attribute_delete_failed', 'Could not delete global attribute.', array( 'status' => 500 ) );
        $log = ALIFY_AI_Audit::log( 'woocommerce_attribute_delete', 'product_attribute', $id, $before, null );
        if ( ! $log ) {
            self::restore_deleted_attribute_snapshot( $before );
            return new WP_Error( 'alify_ai_audit_failed', 'Attribute deletion was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'message' => 'Global product attribute and its terms deleted.', 'activity_id' => $log, 'deleted' => $before );
    }

    private static function apply_attribute_term_create( int $attribute_id, array $payload ) {
        $attr = self::get_attribute( $attribute_id ); if ( is_wp_error( $attr ) ) return $attr;
        $taxonomy = (string) $attr['taxonomy']; if ( ! taxonomy_exists( $taxonomy ) ) return new WP_Error( 'alify_ai_attribute_taxonomy_unavailable', 'Attribute taxonomy is not registered yet.', array( 'status' => 409 ) );
        $result = wp_insert_term( $payload['name'], $taxonomy, array_filter( array( 'slug' => $payload['slug'] ?? '', 'description' => $payload['description'] ?? '' ), static fn( $v ) => '' !== $v ) );
        if ( is_wp_error( $result ) ) return $result;
        $term_id = absint( $result['term_id'] ); $after = self::get_attribute_term( $attribute_id, $term_id );
        return self::audit_or_revert( 'woocommerce_attribute_term_create', $taxonomy, $term_id, null, $after, static function () use ( $term_id, $taxonomy ) { return wp_delete_term( $term_id, $taxonomy ); } );
    }

    private static function apply_attribute_term_update( int $attribute_id, int $term_id, array $payload ) {
        $attr = self::get_attribute( $attribute_id ); if ( is_wp_error( $attr ) ) return $attr;
        $before = self::get_attribute_term( $attribute_id, $term_id ); if ( is_wp_error( $before ) ) return $before;
        $result = wp_update_term( $term_id, (string) $attr['taxonomy'], $payload ); if ( is_wp_error( $result ) ) return $result;
        $after = self::get_attribute_term( $attribute_id, $term_id );
        return self::audit_or_revert( 'woocommerce_attribute_term_update', (string) $attr['taxonomy'], $term_id, $before, $after, static function () use ( $term_id, $attr, $before ) { return wp_update_term( $term_id, (string) $attr['taxonomy'], array( 'name' => $before['name'], 'slug' => $before['slug'], 'description' => $before['description'] ) ); } );
    }

    private static function apply_attribute_term_delete( int $attribute_id, int $term_id ) {
        $attr = self::get_attribute( $attribute_id ); if ( is_wp_error( $attr ) ) return $attr;
        $before = self::snapshot_term_with_relationships( $term_id, (string) $attr['taxonomy'] ); if ( is_wp_error( $before ) ) return $before;
        $safe = ALIFY_AI_Audit::validate_snapshot( $before, null ); if ( is_wp_error( $safe ) ) return $safe;
        $result = wp_delete_term( $term_id, (string) $attr['taxonomy'] ); if ( is_wp_error( $result ) || ! $result ) return is_wp_error( $result ) ? $result : new WP_Error( 'alify_ai_attribute_term_delete_failed', 'Could not delete attribute term.', array( 'status' => 500 ) );
        $log = ALIFY_AI_Audit::log( 'woocommerce_attribute_term_delete', (string) $attr['taxonomy'], $term_id, $before, null );
        if ( ! $log ) {
            self::restore_term_snapshot( $before );
            return new WP_Error( 'alify_ai_audit_failed', 'Attribute term deletion was reverted because its activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'message' => 'Attribute term deleted.', 'activity_id' => $log, 'deleted' => $before );
    }

    private static function apply_variation_create( int $product_id, array $payload ) {
        $parent = self::safe_variable_product( $product_id ); if ( is_wp_error( $parent ) ) return $parent;
        $created = self::create_variation_direct( $parent, $payload ); if ( is_wp_error( $created ) ) return $created;
        $id = (int) $created['id'];
        $log = ALIFY_AI_Audit::log( 'woocommerce_variation_create', 'product_variation', $id, null, $created );
        if ( ! $log ) { wp_trash_post( $id ); return new WP_Error( 'alify_ai_audit_failed', 'Variation creation was reverted because the activity log could not be stored.', array( 'status' => 500 ) ); }
        self::sync_variable_product( $product_id );
        return array( 'message' => 'Variation created.', 'activity_id' => $log, 'item' => $created );
    }

    private static function apply_variation_update( int $product_id, int $variation_id, array $payload ) {
        $before = self::get_variation( $product_id, $variation_id ); if ( is_wp_error( $before ) ) return $before;
        $variation = self::safe_get_product( $variation_id ); if ( is_wp_error( $variation ) ) return $variation;
        $result = self::apply_variation_payload( $variation, $payload ); if ( is_wp_error( $result ) ) return $result;
        $saved = self::safe_save_product( $variation ); if ( is_wp_error( $saved ) ) return $saved;
        self::sync_variable_product( $product_id );
        $after = self::get_variation( $product_id, $variation_id ); if ( is_wp_error( $after ) ) return $after;
        return self::audit_or_revert( 'woocommerce_variation_update', 'product_variation', $variation_id, $before, $after, static function () use ( $variation_id, $before, $product_id ) { $restored = self::restore_variation_snapshot( $variation_id, $before ); if ( is_wp_error( $restored ) ) return $restored; self::sync_variable_product( $product_id ); return true; } );
    }

    private static function apply_variation_trash( int $product_id, int $variation_id ) {
        $before = self::get_variation( $product_id, $variation_id ); if ( is_wp_error( $before ) ) return $before;
        if ( ! wp_trash_post( $variation_id ) ) return new WP_Error( 'alify_ai_variation_trash_failed', 'Could not move variation to Trash.', array( 'status' => 500 ) );
        self::sync_variable_product( $product_id );
        $after = array_merge( $before, array( 'status' => 'trash' ) );
        return self::audit_or_revert( 'woocommerce_variation_trash', 'product_variation', $variation_id, $before, $after, static function () use ( $variation_id, $product_id ) { $restored = wp_untrash_post( $variation_id ); if ( ! $restored ) return false; self::sync_variable_product( $product_id ); return true; } );
    }

    private static function apply_variation_restore( int $product_id, int $variation_id ) {
        $before = self::get_variation( $product_id, $variation_id ); if ( is_wp_error( $before ) ) return $before;
        if ( ! wp_untrash_post( $variation_id ) ) return new WP_Error( 'alify_ai_variation_restore_failed', 'Could not restore variation from Trash.', array( 'status' => 500 ) );
        self::sync_variable_product( $product_id );
        $after = self::get_variation( $product_id, $variation_id ); if ( is_wp_error( $after ) ) return $after;
        return self::audit_or_revert( 'woocommerce_variation_restore', 'product_variation', $variation_id, $before, $after, static function () use ( $variation_id, $product_id ) { $restored = wp_trash_post( $variation_id ); if ( ! $restored ) return false; self::sync_variable_product( $product_id ); return true; } );
    }

    private static function apply_shipping_class_create( array $payload ) {
        $result = wp_insert_term( $payload['name'], 'product_shipping_class', array_filter( array( 'slug' => $payload['slug'] ?? '', 'description' => $payload['description'] ?? '' ), static fn( $v ) => '' !== $v ) );
        if ( is_wp_error( $result ) ) return $result;
        $id = absint( $result['term_id'] ); $after = self::get_shipping_class( $id );
        return self::audit_or_revert( 'woocommerce_shipping_class_create', 'product_shipping_class', $id, null, $after, static function () use ( $id ) { return wp_delete_term( $id, 'product_shipping_class' ); } );
    }

    private static function apply_shipping_class_update( int $id, array $payload ) {
        $before = self::get_shipping_class( $id ); if ( is_wp_error( $before ) ) return $before;
        $result = wp_update_term( $id, 'product_shipping_class', $payload ); if ( is_wp_error( $result ) ) return $result;
        $after = self::get_shipping_class( $id );
        return self::audit_or_revert( 'woocommerce_shipping_class_update', 'product_shipping_class', $id, $before, $after, static function () use ( $id, $before ) { return wp_update_term( $id, 'product_shipping_class', array( 'name' => $before['name'], 'slug' => $before['slug'], 'description' => $before['description'] ) ); } );
    }

    private static function apply_shipping_class_delete( int $id ) {
        $before = self::snapshot_term_with_relationships( $id, 'product_shipping_class' ); if ( is_wp_error( $before ) ) return $before;
        $safe = ALIFY_AI_Audit::validate_snapshot( $before, null ); if ( is_wp_error( $safe ) ) return $safe;
        $result = wp_delete_term( $id, 'product_shipping_class' ); if ( is_wp_error( $result ) || ! $result ) return is_wp_error( $result ) ? $result : new WP_Error( 'alify_ai_shipping_class_delete_failed', 'Could not delete shipping class.', array( 'status' => 500 ) );
        $log = ALIFY_AI_Audit::log( 'woocommerce_shipping_class_delete', 'product_shipping_class', $id, $before, null );
        if ( ! $log ) {
            self::restore_term_snapshot( $before );
            return new WP_Error( 'alify_ai_audit_failed', 'Shipping class deletion was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'message' => 'Shipping class deleted.', 'activity_id' => $log, 'deleted' => $before );
    }

    private static function apply_tax_class_create( array $payload ) {
        if ( ! class_exists( 'WC_Tax' ) || ! method_exists( 'WC_Tax', 'create_tax_class' ) ) return new WP_Error( 'alify_ai_tax_classes_unavailable', 'WooCommerce tax class API is unavailable.', array( 'status' => 409 ) );
        try { $created = WC_Tax::create_tax_class( (string) $payload['name'], (string) ( $payload['slug'] ?? '' ) ); } catch ( Throwable $e ) { return new WP_Error( 'alify_ai_tax_class_create_failed', $e->getMessage(), array( 'status' => 500 ) ); }
        if ( is_wp_error( $created ) ) return $created;
        $after = self::get_tax_class( (string) $created['slug'] ); if ( is_wp_error( $after ) ) return $after;
        $log = ALIFY_AI_Audit::log( 'woocommerce_tax_class_create', 'product_tax_class', 0, null, $after );
        if ( ! $log ) { WC_Tax::delete_tax_class_by( 'slug', (string) $created['slug'] ); return new WP_Error( 'alify_ai_audit_failed', 'Tax class creation was reverted because the activity log could not be stored.', array( 'status' => 500 ) ); }
        return array( 'message' => 'Tax class created.', 'activity_id' => $log, 'item' => $after );
    }

    private static function apply_tax_class_delete( string $slug ) {
        $before = self::get_tax_class( $slug ); if ( is_wp_error( $before ) ) return $before;
        $safe = ALIFY_AI_Audit::validate_snapshot( $before, null ); if ( is_wp_error( $safe ) ) return $safe;
        try { $deleted = WC_Tax::delete_tax_class_by( 'slug', $slug ); } catch ( Throwable $e ) { return new WP_Error( 'alify_ai_tax_class_delete_failed', $e->getMessage(), array( 'status' => 500 ) ); }
        if ( is_wp_error( $deleted ) ) return $deleted;
        if ( ! $deleted ) return new WP_Error( 'alify_ai_tax_class_delete_failed', 'Could not delete tax class.', array( 'status' => 500 ) );
        $log = ALIFY_AI_Audit::log( 'woocommerce_tax_class_delete', 'product_tax_class', 0, $before, null );
        if ( ! $log ) {
            try { WC_Tax::create_tax_class( (string) $before['name'], (string) $before['slug'] ); } catch ( Throwable $e ) { ALIFY_AI_Diagnostics::log_throwable( $e, 'woocommerce_tax_class_revert', array( 'slug' => $slug ) ); }
            return new WP_Error( 'alify_ai_audit_failed', 'Tax class deletion was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'message' => 'Tax class deleted.', 'activity_id' => $log, 'deleted' => $before );
    }

    /* ---------------------------------------------------------------------
     * Transaction / rollback compatibility
     * ------------------------------------------------------------------ */

    public static function update_direct( int $id, array $input ) {
        $product = self::safe_get_product( $id ); if ( is_wp_error( $product ) ) return $product;
        $payload = self::sanitize_product_payload( $input, false, $id, $product ); if ( is_wp_error( $payload ) ) return $payload;
        if ( empty( $payload ) ) return new WP_Error( 'alify_ai_no_changes', 'No supported product fields were supplied.', array( 'status' => 400 ) );
        $before = self::serialize_product( $product, true );
        $result = self::apply_product_payload( $product, $payload ); if ( is_wp_error( $result ) ) return $result;
        $saved_id = self::safe_save_product( $product ); if ( is_wp_error( $saved_id ) ) return $saved_id;
        if ( $product instanceof WC_Product_Variable ) self::sync_variable_product( $id );
        $fresh = self::safe_get_product( $id ); if ( is_wp_error( $fresh ) ) { self::restore_snapshot( $id, $before ); return $fresh; }
        $after = self::serialize_product( $fresh, true );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before, $after ); if ( is_wp_error( $snapshot_ok ) ) { self::restore_snapshot( $id, $before ); return $snapshot_ok; }
        $activity_id = ALIFY_AI_Audit::log( 'update_product', 'product', $id, $before, $after );
        if ( ! $activity_id ) { self::restore_snapshot( $id, $before ); return new WP_Error( 'alify_ai_audit_failed', 'Product update was reverted because the activity log could not be stored.', array( 'status' => 500 ) ); }
        return array( 'activity_id' => $activity_id, 'item' => $after );
    }

    public static function restore_snapshot( int $id, array $snapshot ) {
        $product = self::safe_get_product( $id ); if ( is_wp_error( $product ) ) return $product;
        $payload = self::snapshot_to_product_payload( $snapshot );
        $result = self::apply_product_payload( $product, $payload ); if ( is_wp_error( $result ) ) return $result;
        $saved_id = self::safe_save_product( $product ); if ( is_wp_error( $saved_id ) ) return $saved_id;
        if ( $product instanceof WC_Product_Variable ) self::sync_variable_product( $id );
        $fresh = self::safe_get_product( $id ); return is_wp_error( $fresh ) ? $fresh : self::serialize_product( $fresh, true );
    }

    public static function trash_created_product( int $id ) {
        $product = self::safe_get_product( $id ); if ( is_wp_error( $product ) ) return $product;
        try { $trashed = wp_trash_post( $id ); } catch ( Throwable $error ) { return new WP_Error( 'alify_ai_product_trash_failed', 'Could not move connector-created product to trash.', array( 'status' => 500 ) ); }
        if ( ! $trashed ) return new WP_Error( 'alify_ai_product_trash_failed', 'Could not move connector-created product to trash.', array( 'status' => 500 ) );
        return array( 'id' => $id, 'status' => 'trash' );
    }

    public static function rollback_activity( array $entry ) {
        $action = (string) ( $entry['action'] ?? '' );
        $before = is_array( $entry['before_json'] ?? null ) ? $entry['before_json'] : null;
        $after  = is_array( $entry['after_json'] ?? null ) ? $entry['after_json'] : null;
        $id = absint( $entry['object_id'] ?? 0 );
        $result = null;

        if ( 'update_product' === $action && $before ) $result = self::restore_snapshot( $id, $before );
        elseif ( 'create_product' === $action ) $result = self::trash_created_product( $id );
        elseif ( 'woocommerce_product_trash' === $action ) { $result = wp_untrash_post( $id ) ? self::get_product( $id ) : new WP_Error( 'alify_ai_rollback_failed', 'Could not restore product.', array( 'status' => 500 ) ); }
        elseif ( 'woocommerce_product_restore' === $action ) { $result = wp_trash_post( $id ) ? array( 'id' => $id, 'status' => 'trash' ) : new WP_Error( 'alify_ai_rollback_failed', 'Could not move product back to Trash.', array( 'status' => 500 ) ); }
        elseif ( 'woocommerce_variation_create' === $action ) { $result = wp_trash_post( $id ) ? array( 'id' => $id, 'status' => 'trash' ) : new WP_Error( 'alify_ai_rollback_failed', 'Could not trash created variation.', array( 'status' => 500 ) ); }
        elseif ( 'woocommerce_variation_update' === $action && $before ) $result = self::restore_variation_snapshot( $id, $before );
        elseif ( 'woocommerce_variation_trash' === $action ) { $result = wp_untrash_post( $id ) ? self::serialize_variation( self::safe_get_product( $id ), true ) : new WP_Error( 'alify_ai_rollback_failed', 'Could not restore variation.', array( 'status' => 500 ) ); }
        elseif ( 'woocommerce_variation_restore' === $action ) { $result = wp_trash_post( $id ) ? array( 'id' => $id, 'status' => 'trash' ) : new WP_Error( 'alify_ai_rollback_failed', 'Could not trash variation.', array( 'status' => 500 ) ); }
        elseif ( 'woocommerce_attribute_create' === $action ) { $ok = wc_delete_attribute( $id ); $result = $ok ? array( 'deleted_attribute_id' => $id ) : new WP_Error( 'alify_ai_rollback_failed', 'Could not remove created attribute.', array( 'status' => 500 ) ); }
        elseif ( 'woocommerce_attribute_update' === $action && $before ) { $r = wc_update_attribute( $id, self::attribute_snapshot_to_payload( $before ) ); $result = is_wp_error( $r ) ? $r : self::get_attribute( $id ); }
        elseif ( 'woocommerce_attribute_delete' === $action && $before ) { $result = self::restore_deleted_attribute_snapshot( $before ); }
        elseif ( 'woocommerce_attribute_term_create' === $action ) { $taxonomy = (string) ( $entry['object_type'] ?? '' ); $r = wp_delete_term( $id, $taxonomy ); $result = is_wp_error( $r ) ? $r : array( 'deleted_term_id' => $id ); }
        elseif ( 'woocommerce_attribute_term_update' === $action && $before ) { $taxonomy = (string) ( $entry['object_type'] ?? '' ); $r = wp_update_term( $id, $taxonomy, array( 'name' => $before['name'], 'slug' => $before['slug'], 'description' => $before['description'] ) ); $result = is_wp_error( $r ) ? $r : self::serialize_term( get_term( $id, $taxonomy ) ); }
        elseif ( 'woocommerce_attribute_term_delete' === $action && $before ) { $result = self::restore_term_snapshot( $before ); }
        elseif ( 'woocommerce_shipping_class_create' === $action ) { $r = wp_delete_term( $id, 'product_shipping_class' ); $result = is_wp_error( $r ) ? $r : array( 'deleted_shipping_class_id' => $id ); }
        elseif ( 'woocommerce_shipping_class_update' === $action && $before ) { $r = wp_update_term( $id, 'product_shipping_class', array( 'name' => $before['name'], 'slug' => $before['slug'], 'description' => $before['description'] ) ); $result = is_wp_error( $r ) ? $r : self::get_shipping_class( $id ); }
        elseif ( 'woocommerce_shipping_class_delete' === $action && $before ) { $result = self::restore_term_snapshot( $before ); }
        elseif ( 'woocommerce_tax_class_create' === $action && $after ) { $r = WC_Tax::delete_tax_class_by( 'slug', (string) $after['slug'] ); $result = is_wp_error( $r ) ? $r : array( 'deleted_tax_class' => $after['slug'] ); }
        elseif ( 'woocommerce_tax_class_delete' === $action && $before ) { $r = WC_Tax::create_tax_class( (string) $before['name'], (string) $before['slug'] ); $result = is_wp_error( $r ) ? $r : self::get_tax_class( (string) $before['slug'] ); }
        else return new WP_Error( 'alify_ai_woocommerce_rollback_unsupported', 'This WooCommerce activity cannot be rolled back.', array( 'status' => 400 ) );

        if ( is_wp_error( $result ) ) return $result;
        // Variation mutations affect the parent variable product's child list, prices and
        // stock caches. Rollback must re-sync the parent just like the forward operation.
        if ( str_starts_with( $action, 'woocommerce_variation_' ) ) {
            $parent_id = absint( $before['parent_id'] ?? ( $after['parent_id'] ?? 0 ) );
            if ( ! $parent_id ) {
                $variation = self::safe_get_product( $id );
                if ( ! is_wp_error( $variation ) && $variation instanceof WC_Product_Variation ) $parent_id = absint( $variation->get_parent_id() );
            }
            if ( $parent_id ) self::sync_variable_product( $parent_id );
        }
        $log_id = ALIFY_AI_Audit::log( 'rollback_' . $action, (string) ( $entry['object_type'] ?? 'woocommerce' ), $id, $after, is_array( $result ) ? $result : array( 'result' => $result ) );
        if ( ! $log_id ) return new WP_Error( 'alify_ai_audit_failed', 'WooCommerce change was rolled back but the rollback activity could not be logged.', array( 'status' => 500 ) );
        return array( 'message' => 'WooCommerce activity rolled back.', 'activity_id' => $log_id, 'result' => $result );
    }

    /* ---------------------------------------------------------------------
     * Serialization
     * ------------------------------------------------------------------ */

    public static function serialize_product( $product, bool $full = false ): array {
        if ( is_numeric( $product ) ) $product = wc_get_product( (int) $product );
        if ( ! $product instanceof WC_Product ) return array();
        $data = array(
            'id'                 => (int) $product->get_id(),
            'type'               => (string) $product->get_type(),
            'name'               => (string) $product->get_name(),
            'slug'               => (string) $product->get_slug(),
            'status'             => (string) $product->get_status(),
            'sku'                => (string) $product->get_sku(),
            'price'              => (string) $product->get_price(),
            'regular_price'      => (string) $product->get_regular_price(),
            'sale_price'         => (string) $product->get_sale_price(),
            'date_on_sale_from'  => self::serialize_wc_datetime( $product->get_date_on_sale_from() ),
            'date_on_sale_to'    => self::serialize_wc_datetime( $product->get_date_on_sale_to() ),
            'manage_stock'       => (bool) $product->get_manage_stock(),
            'stock_quantity'     => null === $product->get_stock_quantity() ? null : (float) $product->get_stock_quantity(),
            'stock_status'       => (string) $product->get_stock_status(),
            'backorders'         => (string) $product->get_backorders(),
            'low_stock_amount'   => null === $product->get_low_stock_amount() ? null : (float) $product->get_low_stock_amount(),
            'featured'           => (bool) $product->get_featured(),
            'catalog_visibility' => (string) $product->get_catalog_visibility(),
            'virtual'            => (bool) $product->get_virtual(),
            'downloadable'       => (bool) $product->get_downloadable(),
            'sold_individually'  => (bool) $product->get_sold_individually(),
            'tax_status'         => (string) $product->get_tax_status(),
            'tax_class'          => (string) $product->get_tax_class(),
            'weight'             => (string) $product->get_weight(),
            'dimensions'         => array( 'length' => (string) $product->get_length(), 'width' => (string) $product->get_width(), 'height' => (string) $product->get_height() ),
            'shipping_class_id'  => (int) $product->get_shipping_class_id(),
            'shipping_class'     => (string) $product->get_shipping_class(),
            'image_id'           => (int) $product->get_image_id(),
            'menu_order'         => (int) $product->get_menu_order(),
            'permalink'          => (string) get_permalink( $product->get_id() ),
            'modified'           => $product->get_date_modified() ? $product->get_date_modified()->date( DATE_ATOM ) : null,
        );
        if ( $full ) {
            $data['description']       = (string) $product->get_description();
            $data['short_description'] = (string) $product->get_short_description();
            $data['purchase_note']     = (string) $product->get_purchase_note();
            $data['category_ids']      = array_map( 'intval', $product->get_category_ids() );
            $data['tag_ids']           = array_map( 'intval', $product->get_tag_ids() );
            $data['gallery_image_ids'] = array_map( 'intval', $product->get_gallery_image_ids() );
            $data['upsell_ids']        = array_map( 'intval', $product->get_upsell_ids() );
            $data['cross_sell_ids']    = array_map( 'intval', $product->get_cross_sell_ids() );
            $data['reviews_allowed']   = (bool) $product->get_reviews_allowed();
            $data['download_limit']    = (int) $product->get_download_limit();
            $data['download_expiry']   = (int) $product->get_download_expiry();
            $data['downloads']         = self::serialize_downloads( $product->get_downloads() );
            $data['attributes']        = self::serialize_product_attributes( $product->get_attributes() );
            $data['default_attributes']= method_exists( $product, 'get_default_attributes' ) ? (array) $product->get_default_attributes() : array();
            if ( $product instanceof WC_Product_Variable ) {
                $data['variation_ids'] = array_map( 'intval', $product->get_children() );
            }
        }
        return $data;
    }

    public static function serialize_variation( $variation, bool $full = false ): array {
        if ( ! $variation instanceof WC_Product_Variation ) return array();
        $data = array(
            'id' => (int) $variation->get_id(), 'parent_id' => (int) $variation->get_parent_id(), 'status' => (string) $variation->get_status(),
            'sku' => (string) $variation->get_sku(), 'price' => (string) $variation->get_price(), 'regular_price' => (string) $variation->get_regular_price(), 'sale_price' => (string) $variation->get_sale_price(),
            'date_on_sale_from' => self::serialize_wc_datetime( $variation->get_date_on_sale_from() ), 'date_on_sale_to' => self::serialize_wc_datetime( $variation->get_date_on_sale_to() ),
            'attributes' => (array) $variation->get_attributes(), 'manage_stock' => (bool) $variation->get_manage_stock(), 'stock_quantity' => null === $variation->get_stock_quantity() ? null : (float) $variation->get_stock_quantity(),
            'stock_status' => (string) $variation->get_stock_status(), 'backorders' => (string) $variation->get_backorders(), 'virtual' => (bool) $variation->get_virtual(), 'downloadable' => (bool) $variation->get_downloadable(),
            'tax_status' => (string) $variation->get_tax_status(), 'tax_class' => (string) $variation->get_tax_class(), 'weight' => (string) $variation->get_weight(),
            'dimensions' => array( 'length' => (string) $variation->get_length(), 'width' => (string) $variation->get_width(), 'height' => (string) $variation->get_height() ),
            'shipping_class_id' => (int) $variation->get_shipping_class_id(), 'shipping_class' => (string) $variation->get_shipping_class(), 'image_id' => (int) $variation->get_image_id(), 'menu_order' => (int) $variation->get_menu_order(),
        );
        if ( $full ) {
            $data['description'] = (string) $variation->get_description();
            $data['download_limit'] = (int) $variation->get_download_limit(); $data['download_expiry'] = (int) $variation->get_download_expiry();
            $data['downloads'] = self::serialize_downloads( $variation->get_downloads() );
        }
        return $data;
    }

    public static function hash_snapshot( array $snapshot ): string {
        return hash( 'sha256', wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }

    public static function normalize_payload( array $input, bool $creating = false ) {
        return self::sanitize_product_payload( $input, $creating );
    }

    /* ---------------------------------------------------------------------
     * Validation and payload application
     * ------------------------------------------------------------------ */

    private static function sanitize_product_payload( array $input, bool $creating, int $product_id = 0, $existing = null ) {
        $out = array();
        if ( $creating || array_key_exists( 'type', $input ) ) {
            $type = sanitize_key( (string) ( $input['type'] ?? 'simple' ) );
            if ( ! in_array( $type, self::PRODUCT_TYPES, true ) ) return new WP_Error( 'alify_ai_invalid_product_type', 'Supported product types are simple and variable.', array( 'status' => 400 ) );
            if ( ! $creating && $existing instanceof WC_Product && $type !== $existing->get_type() ) return new WP_Error( 'alify_ai_product_type_change_forbidden', 'Changing an existing product between simple and variable is not supported. Create a new product instead.', array( 'status' => 409 ) );
            $out['type'] = $type;
        }
        if ( array_key_exists( 'name', $input ) ) $out['name'] = sanitize_text_field( (string) $input['name'] );
        if ( $creating && empty( $out['name'] ) ) return new WP_Error( 'alify_ai_product_name_required', 'Product name is required.', array( 'status' => 400 ) );
        if ( array_key_exists( 'slug', $input ) ) $out['slug'] = sanitize_title( (string) $input['slug'] );
        if ( array_key_exists( 'description', $input ) ) $out['description'] = wp_kses_post( (string) $input['description'] );
        if ( array_key_exists( 'short_description', $input ) ) $out['short_description'] = wp_kses_post( (string) $input['short_description'] );
        if ( array_key_exists( 'purchase_note', $input ) ) $out['purchase_note'] = wp_kses_post( (string) $input['purchase_note'] );
        if ( array_key_exists( 'sku', $input ) ) {
            $sku = sanitize_text_field( (string) $input['sku'] );
            if ( '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
                $found = absint( wc_get_product_id_by_sku( $sku ) );
                if ( $found && $found !== $product_id ) return new WP_Error( 'alify_ai_duplicate_sku', 'SKU is already used by another product or variation.', array( 'status' => 409, 'existing_id' => $found ) );
            }
            $out['sku'] = $sku;
        }
        foreach ( array( 'regular_price', 'sale_price', 'weight', 'length', 'width', 'height' ) as $decimal ) {
            if ( array_key_exists( $decimal, $input ) ) {
                $value = (string) $input[ $decimal ];
                $out[ $decimal ] = '' === $value ? '' : wc_format_decimal( $value );
            }
        }
        foreach ( array( 'date_on_sale_from', 'date_on_sale_to' ) as $date_key ) {
            if ( array_key_exists( $date_key, $input ) ) {
                $date = self::sanitize_wc_datetime( $input[ $date_key ] ); if ( is_wp_error( $date ) ) return $date;
                $out[ $date_key ] = $date;
            }
        }
        if ( array_key_exists( 'status', $input ) ) {
            $status = sanitize_key( (string) $input['status'] );
            if ( ! in_array( $status, array( 'draft', 'pending', 'private', 'publish' ), true ) ) return new WP_Error( 'alify_ai_invalid_product_status', 'Unsupported product status.', array( 'status' => 400 ) );
            $out['status'] = $status;
        } elseif ( $creating ) $out['status'] = 'draft';
        foreach ( array( 'manage_stock', 'featured', 'virtual', 'downloadable', 'sold_individually', 'reviews_allowed' ) as $boolean ) if ( array_key_exists( $boolean, $input ) ) $out[ $boolean ] = (bool) $input[ $boolean ];
        if ( array_key_exists( 'stock_quantity', $input ) ) $out['stock_quantity'] = null === $input['stock_quantity'] || '' === $input['stock_quantity'] ? null : (float) $input['stock_quantity'];
        if ( array_key_exists( 'low_stock_amount', $input ) ) $out['low_stock_amount'] = null === $input['low_stock_amount'] || '' === $input['low_stock_amount'] ? null : (float) $input['low_stock_amount'];
        if ( array_key_exists( 'stock_status', $input ) ) { $v = sanitize_key( (string) $input['stock_status'] ); if ( ! in_array( $v, self::STOCK_STATUSES, true ) ) return new WP_Error( 'alify_ai_invalid_stock_status', 'Unsupported stock status.', array( 'status' => 400 ) ); $out['stock_status'] = $v; }
        if ( array_key_exists( 'backorders', $input ) ) { $v = sanitize_key( (string) $input['backorders'] ); if ( ! in_array( $v, self::BACKORDER_VALUES, true ) ) return new WP_Error( 'alify_ai_invalid_backorders', 'backorders must be no, notify, or yes.', array( 'status' => 400 ) ); $out['backorders'] = $v; }
        if ( array_key_exists( 'catalog_visibility', $input ) ) { $v = sanitize_key( (string) $input['catalog_visibility'] ); if ( ! in_array( $v, self::VISIBILITY_VALUES, true ) ) return new WP_Error( 'alify_ai_invalid_catalog_visibility', 'Unsupported catalog visibility.', array( 'status' => 400 ) ); $out['catalog_visibility'] = $v; }
        if ( array_key_exists( 'tax_status', $input ) ) { $v = sanitize_key( (string) $input['tax_status'] ); if ( ! in_array( $v, self::TAX_STATUSES, true ) ) return new WP_Error( 'alify_ai_invalid_tax_status', 'Unsupported tax status.', array( 'status' => 400 ) ); $out['tax_status'] = $v; }
        if ( array_key_exists( 'tax_class', $input ) ) { $tax = self::sanitize_tax_class_value( (string) $input['tax_class'] ); if ( is_wp_error( $tax ) ) return $tax; $out['tax_class'] = $tax; }
        if ( array_key_exists( 'shipping_class_id', $input ) ) { $ship = self::sanitize_shipping_class_id( $input['shipping_class_id'] ); if ( is_wp_error( $ship ) ) return $ship; $out['shipping_class_id'] = $ship; }
        foreach ( array( 'category_ids' => 'product_cat', 'tag_ids' => 'product_tag' ) as $ids_key => $taxonomy ) {
            if ( array_key_exists( $ids_key, $input ) ) { $ids = self::sanitize_term_ids( $input[ $ids_key ], $taxonomy ); if ( is_wp_error( $ids ) ) return $ids; $out[ $ids_key ] = $ids; }
        }
        foreach ( array( 'gallery_image_ids', 'upsell_ids', 'cross_sell_ids' ) as $ids_key ) {
            if ( array_key_exists( $ids_key, $input ) ) { if ( ! is_array( $input[ $ids_key ] ) ) return new WP_Error( 'alify_ai_invalid_product_ids', $ids_key . ' must be an array of IDs.', array( 'status' => 400 ) ); $out[ $ids_key ] = array_values( array_unique( array_filter( array_map( 'absint', $input[ $ids_key ] ) ) ) ); }
        }
        if ( array_key_exists( 'image_id', $input ) ) { $img = self::sanitize_image_id( $input['image_id'] ); if ( is_wp_error( $img ) ) return $img; $out['image_id'] = $img; }
        if ( array_key_exists( 'menu_order', $input ) ) $out['menu_order'] = (int) $input['menu_order'];
        if ( array_key_exists( 'download_limit', $input ) ) $out['download_limit'] = max( -1, (int) $input['download_limit'] );
        if ( array_key_exists( 'download_expiry', $input ) ) $out['download_expiry'] = max( -1, (int) $input['download_expiry'] );
        if ( array_key_exists( 'downloads', $input ) ) { $d = self::sanitize_downloads( $input['downloads'] ); if ( is_wp_error( $d ) ) return $d; $out['downloads'] = $d; }
        if ( array_key_exists( 'attributes', $input ) ) { $a = self::sanitize_product_attributes( $input['attributes'] ); if ( is_wp_error( $a ) ) return $a; $out['attributes'] = $a; }
        if ( array_key_exists( 'default_attributes', $input ) ) { if ( ! is_array( $input['default_attributes'] ) ) return new WP_Error( 'alify_ai_invalid_default_attributes', 'default_attributes must be an object.', array( 'status' => 400 ) ); $out['default_attributes'] = array_map( 'sanitize_title', array_map( 'strval', $input['default_attributes'] ) ); }
        if ( $creating && array_key_exists( 'variations', $input ) ) {
            if ( ! is_array( $input['variations'] ) || count( $input['variations'] ) > self::MAX_VARIATIONS_PER_CREATE ) return new WP_Error( 'alify_ai_invalid_variations', 'variations must be an array with at most 100 items.', array( 'status' => 400 ) );
            $out['variations'] = array_values( $input['variations'] );
        }
        $effective_type = (string) ( $out['type'] ?? ( $existing instanceof WC_Product ? $existing->get_type() : 'simple' ) );
        if ( 'simple' === $effective_type && ! empty( $out['variations'] ) ) return new WP_Error( 'alify_ai_variations_require_variable_product', 'Nested variations require type=variable.', array( 'status' => 400 ) );
        return $out;
    }

    private static function apply_product_payload( WC_Product $product, array $payload ) {
        $setters = array(
            'name'=>'set_name','slug'=>'set_slug','status'=>'set_status','description'=>'set_description','short_description'=>'set_short_description','purchase_note'=>'set_purchase_note','sku'=>'set_sku',
            'regular_price'=>'set_regular_price','sale_price'=>'set_sale_price','manage_stock'=>'set_manage_stock','stock_quantity'=>'set_stock_quantity','low_stock_amount'=>'set_low_stock_amount','stock_status'=>'set_stock_status','backorders'=>'set_backorders',
            'featured'=>'set_featured','catalog_visibility'=>'set_catalog_visibility','virtual'=>'set_virtual','downloadable'=>'set_downloadable','sold_individually'=>'set_sold_individually','reviews_allowed'=>'set_reviews_allowed',
            'tax_status'=>'set_tax_status','tax_class'=>'set_tax_class','weight'=>'set_weight','length'=>'set_length','width'=>'set_width','height'=>'set_height','shipping_class_id'=>'set_shipping_class_id',
            'category_ids'=>'set_category_ids','tag_ids'=>'set_tag_ids','image_id'=>'set_image_id','gallery_image_ids'=>'set_gallery_image_ids','upsell_ids'=>'set_upsell_ids','cross_sell_ids'=>'set_cross_sell_ids','menu_order'=>'set_menu_order',
            'download_limit'=>'set_download_limit','download_expiry'=>'set_download_expiry','default_attributes'=>'set_default_attributes',
        );
        try {
            foreach ( $payload as $key => $value ) {
                if ( in_array( $key, array( 'type', 'variations' ), true ) ) continue;
                if ( 'attributes' === $key ) { $product->set_attributes( self::build_product_attributes( $value ) ); continue; }
                if ( 'downloads' === $key ) { $product->set_downloads( self::build_downloads( $value ) ); continue; }
                if ( in_array( $key, array( 'date_on_sale_from', 'date_on_sale_to' ), true ) ) { $method = 'date_on_sale_from' === $key ? 'set_date_on_sale_from' : 'set_date_on_sale_to'; $product->{$method}( '' === $value || null === $value ? null : self::wc_datetime( $value ) ); continue; }
                if ( isset( $setters[ $key ] ) && is_callable( array( $product, $setters[ $key ] ) ) ) $product->{$setters[ $key ]}( $value );
            }
        } catch ( Throwable $e ) { return new WP_Error( 'alify_ai_product_validation_failed', $e->getMessage(), array( 'status' => 400 ) ); }
        return true;
    }

    private static function sanitize_variation_payload( array $input, bool $creating, WC_Product_Variable $parent, int $variation_id ) {
        $out = array();
        if ( array_key_exists( 'description', $input ) ) $out['description'] = wp_kses_post( (string) $input['description'] );
        if ( array_key_exists( 'sku', $input ) ) {
            $sku = sanitize_text_field( (string) $input['sku'] );
            if ( '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ) { $found = absint( wc_get_product_id_by_sku( $sku ) ); if ( $found && $found !== $variation_id ) return new WP_Error( 'alify_ai_duplicate_sku', 'SKU is already in use.', array( 'status' => 409, 'existing_id' => $found ) ); }
            $out['sku'] = $sku;
        }
        foreach ( array( 'regular_price','sale_price','weight','length','width','height' ) as $decimal ) if ( array_key_exists( $decimal, $input ) ) { $v=(string)$input[$decimal]; $out[$decimal]=''===$v?'':wc_format_decimal($v); }
        foreach ( array( 'date_on_sale_from','date_on_sale_to' ) as $key ) if ( array_key_exists( $key, $input ) ) { $v=self::sanitize_wc_datetime($input[$key]); if(is_wp_error($v))return $v; $out[$key]=$v; }
        if ( array_key_exists( 'status', $input ) ) { $v=sanitize_key((string)$input['status']); if(!in_array($v,array('publish','private','draft','pending'),true))return new WP_Error('alify_ai_invalid_variation_status','Unsupported variation status.',array('status'=>400)); $out['status']=$v; } elseif($creating) $out['status']='publish';
        foreach(array('manage_stock','virtual','downloadable') as $b)if(array_key_exists($b,$input))$out[$b]=(bool)$input[$b];
        if(array_key_exists('stock_quantity',$input))$out['stock_quantity']=null===$input['stock_quantity']||''===$input['stock_quantity']?null:(float)$input['stock_quantity'];
        if(array_key_exists('stock_status',$input)){ $v=sanitize_key((string)$input['stock_status']); if(!in_array($v,self::STOCK_STATUSES,true))return new WP_Error('alify_ai_invalid_stock_status','Unsupported stock status.',array('status'=>400)); $out['stock_status']=$v; }
        if(array_key_exists('backorders',$input)){ $v=sanitize_key((string)$input['backorders']); if(!in_array($v,self::BACKORDER_VALUES,true))return new WP_Error('alify_ai_invalid_backorders','backorders must be no, notify, or yes.',array('status'=>400)); $out['backorders']=$v; }
        if(array_key_exists('tax_status',$input)){ $v=sanitize_key((string)$input['tax_status']); if(!in_array($v,self::TAX_STATUSES,true))return new WP_Error('alify_ai_invalid_tax_status','Unsupported tax status.',array('status'=>400)); $out['tax_status']=$v; }
        if(array_key_exists('tax_class',$input)){ $tax=self::sanitize_tax_class_value((string)$input['tax_class'],true); if(is_wp_error($tax))return $tax; $out['tax_class']=$tax; }
        if(array_key_exists('shipping_class_id',$input)){ $ship=self::sanitize_shipping_class_id($input['shipping_class_id']); if(is_wp_error($ship))return $ship; $out['shipping_class_id']=$ship; }
        if(array_key_exists('image_id',$input)){ $img=self::sanitize_image_id($input['image_id']); if(is_wp_error($img))return $img; $out['image_id']=$img; }
        if(array_key_exists('menu_order',$input))$out['menu_order']=(int)$input['menu_order'];
        if(array_key_exists('download_limit',$input))$out['download_limit']=max(-1,(int)$input['download_limit']);
        if(array_key_exists('download_expiry',$input))$out['download_expiry']=max(-1,(int)$input['download_expiry']);
        if(array_key_exists('downloads',$input)){ $d=self::sanitize_downloads($input['downloads']); if(is_wp_error($d))return $d; $out['downloads']=$d; }
        if(array_key_exists('attributes',$input)){ $attrs=self::sanitize_variation_attributes($parent,$input['attributes']); if(is_wp_error($attrs))return $attrs; $out['attributes']=$attrs; }
        if($creating && empty($out['attributes']))return new WP_Error('alify_ai_variation_attributes_required','A variation must define at least one parent variation attribute.',array('status'=>400));
        return $out;
    }

    private static function apply_variation_payload( WC_Product_Variation $variation, array $payload ) {
        $setters=array('description'=>'set_description','sku'=>'set_sku','regular_price'=>'set_regular_price','sale_price'=>'set_sale_price','status'=>'set_status','manage_stock'=>'set_manage_stock','stock_quantity'=>'set_stock_quantity','stock_status'=>'set_stock_status','backorders'=>'set_backorders','virtual'=>'set_virtual','downloadable'=>'set_downloadable','tax_status'=>'set_tax_status','tax_class'=>'set_tax_class','weight'=>'set_weight','length'=>'set_length','width'=>'set_width','height'=>'set_height','shipping_class_id'=>'set_shipping_class_id','image_id'=>'set_image_id','menu_order'=>'set_menu_order','download_limit'=>'set_download_limit','download_expiry'=>'set_download_expiry','attributes'=>'set_attributes');
        try{ foreach($payload as $key=>$value){ if('downloads'===$key){$variation->set_downloads(self::build_downloads($value));continue;} if(in_array($key,array('date_on_sale_from','date_on_sale_to'),true)){ $m='date_on_sale_from'===$key?'set_date_on_sale_from':'set_date_on_sale_to';$variation->{$m}(''===$value||null===$value?null:self::wc_datetime($value));continue;} if(isset($setters[$key])&&is_callable(array($variation,$setters[$key])))$variation->{$setters[$key]}($value); } }catch(Throwable $e){return new WP_Error('alify_ai_variation_validation_failed',$e->getMessage(),array('status'=>400));}
        return true;
    }

    private static function create_variation_direct( WC_Product_Variable $parent, array $payload ) {
        $variation = new WC_Product_Variation(); $variation->set_parent_id( $parent->get_id() );
        $result=self::apply_variation_payload($variation,$payload); if(is_wp_error($result))return $result;
        $id=self::safe_save_product($variation); if(is_wp_error($id))return $id;
        $fresh=self::safe_get_product($id); if(is_wp_error($fresh)||!$fresh instanceof WC_Product_Variation){wp_trash_post($id);return new WP_Error('alify_ai_variation_reload_failed','Created variation could not be reloaded.',array('status'=>500));}
        return self::serialize_variation($fresh,true);
    }

    private static function restore_variation_snapshot( int $id, array $snapshot ) {
        $variation=self::safe_get_product($id); if(is_wp_error($variation)||!$variation instanceof WC_Product_Variation)return new WP_Error('alify_ai_variation_not_found','Variation not found.',array('status'=>404));
        $payload=array_intersect_key($snapshot,array_flip(array('description','sku','regular_price','sale_price','date_on_sale_from','date_on_sale_to','status','attributes','manage_stock','stock_quantity','stock_status','backorders','virtual','downloadable','tax_status','tax_class','weight','shipping_class_id','image_id','menu_order','download_limit','download_expiry','downloads')));
        if(isset($snapshot['dimensions'])&&is_array($snapshot['dimensions']))$payload=array_merge($payload,$snapshot['dimensions']);
        $r=self::apply_variation_payload($variation,$payload);if(is_wp_error($r))return $r;$saved=self::safe_save_product($variation);if(is_wp_error($saved))return $saved;$fresh=self::safe_get_product($id);return is_wp_error($fresh)?$fresh:self::serialize_variation($fresh,true);
    }

    private static function sanitize_product_attributes( $input ) {
        if(!is_array($input))return new WP_Error('alify_ai_invalid_attributes','attributes must be an array.',array('status'=>400));
        if(count($input)>self::MAX_ATTRIBUTES)return new WP_Error('alify_ai_too_many_attributes','A product may define at most 50 attributes through this connector.',array('status'=>400));
        $out=array();$names=array();
        foreach(array_values($input) as $index=>$raw){
            if(!is_array($raw))return new WP_Error('alify_ai_invalid_attribute','Each product attribute must be an object.',array('status'=>400,'index'=>$index));
            $id=absint($raw['id']??0);$name=sanitize_text_field((string)($raw['name']??''));$position=(int)($raw['position']??$index);$visible=(bool)($raw['visible']??true);$variation=(bool)($raw['variation']??false);
            if($id){$global=self::get_attribute($id);if(is_wp_error($global))return $global;$taxonomy=(string)$global['taxonomy'];$name=$taxonomy;$options=self::sanitize_term_ids($raw['options']??array(),$taxonomy);if(is_wp_error($options))return $options;}
            else{if(''===$name)return new WP_Error('alify_ai_attribute_name_required','Custom product attribute name is required.',array('status'=>400,'index'=>$index));if(!is_array($raw['options']??null))return new WP_Error('alify_ai_invalid_attribute_options','Custom attribute options must be an array of strings.',array('status'=>400,'index'=>$index));$options=array_values(array_unique(array_filter(array_map(static fn($v)=>sanitize_text_field((string)$v),$raw['options']),static fn($v)=>''!==$v)));if(empty($options))return new WP_Error('alify_ai_attribute_options_required','Each product attribute needs at least one option.',array('status'=>400,'index'=>$index));}
            $key=$id?'id:'.$id:'name:'.sanitize_title($name);if(isset($names[$key]))return new WP_Error('alify_ai_duplicate_attribute','Duplicate product attribute supplied.',array('status'=>400,'index'=>$index));$names[$key]=true;
            $out[]=array('id'=>$id,'name'=>$name,'options'=>$options,'position'=>$position,'visible'=>$visible,'variation'=>$variation);
        }
        return $out;
    }

    private static function build_product_attributes( array $items ): array {
        $out=array();
        foreach($items as $item){$a=new WC_Product_Attribute();$a->set_id(absint($item['id']??0));$a->set_name((string)$item['name']);$a->set_options((array)$item['options']);$a->set_position((int)$item['position']);$a->set_visible((bool)$item['visible']);$a->set_variation((bool)$item['variation']);$out[]=$a;}
        return $out;
    }

    private static function serialize_product_attributes( $attributes ): array {
        $out=array();
        foreach((array)$attributes as $attribute){
            if(!$attribute instanceof WC_Product_Attribute)continue;
            $item=array('id'=>(int)$attribute->get_id(),'name'=>(string)$attribute->get_name(),'options'=>(array)$attribute->get_options(),'position'=>(int)$attribute->get_position(),'visible'=>(bool)$attribute->get_visible(),'variation'=>(bool)$attribute->get_variation(),'taxonomy'=>(bool)$attribute->is_taxonomy());
            if($attribute->is_taxonomy()){$item['taxonomy_name']=(string)$attribute->get_name();$terms=array();foreach((array)$attribute->get_terms() as $term)if($term instanceof WP_Term)$terms[]=self::serialize_term($term);$item['terms']=$terms;}
            $out[]=$item;
        }
        return $out;
    }

    private static function sanitize_variation_attributes( WC_Product_Variable $parent, $input ) {
        if(!is_array($input))return new WP_Error('alify_ai_invalid_variation_attributes','attributes must be an object keyed by parent attribute name/taxonomy.',array('status'=>400));
        $allowed=array();
        foreach((array)$parent->get_attributes() as $attribute){
            if(!$attribute instanceof WC_Product_Attribute||!$attribute->get_variation())continue;
            $key=(string)$attribute->get_name();$values=array();
            if($attribute->is_taxonomy()){foreach((array)$attribute->get_terms() as $term)if($term instanceof WP_Term)$values[]=(string)$term->slug;}
            else $values=array_map('strval',(array)$attribute->get_options());
            $allowed[$key]=$values;$allowed[sanitize_title($key)]=$values;
        }
        $out=array();
        foreach($input as $raw_key=>$raw_value){$key=sanitize_text_field((string)$raw_key);$resolved=array_key_exists($key,$allowed)?$key:(array_key_exists(sanitize_title($key),$allowed)?sanitize_title($key):'');if(''===$resolved)return new WP_Error('alify_ai_invalid_variation_attribute','Variation attribute is not enabled for variations on the parent product.',array('status'=>400,'attribute'=>$key));$value=sanitize_text_field((string)$raw_value);if(''!==$value&&!in_array($value,$allowed[$resolved],true)&&!in_array(sanitize_title($value),$allowed[$resolved],true))return new WP_Error('alify_ai_invalid_variation_option','Variation attribute option does not exist on the parent product.',array('status'=>400,'attribute'=>$key,'value'=>$value));$canonical=array_key_exists($key,$allowed)?$key:sanitize_title($key);$out[$canonical]=in_array($value,$allowed[$resolved],true)?$value:sanitize_title($value);}
        return $out;
    }

    private static function sanitize_downloads( $input ) {
        if(!is_array($input))return new WP_Error('alify_ai_invalid_downloads','downloads must be an array.',array('status'=>400));if(count($input)>self::MAX_DOWNLOADS)return new WP_Error('alify_ai_too_many_downloads','At most 50 downloads may be configured.',array('status'=>400));$out=array();
        foreach(array_values($input) as $i=>$raw){if(!is_array($raw))return new WP_Error('alify_ai_invalid_download','Each download must be an object.',array('status'=>400,'index'=>$i));$name=sanitize_text_field((string)($raw['name']??''));$file=esc_url_raw((string)($raw['file']??''),array('http','https'));if(''===$name||''===$file)return new WP_Error('alify_ai_invalid_download','Each download requires name and http/https file URL.',array('status'=>400,'index'=>$i));$id=sanitize_key((string)($raw['id']??''));if(''===$id)$id=md5($file);$out[]=array('id'=>$id,'name'=>$name,'file'=>$file);}
        return $out;
    }

    private static function build_downloads( array $input ): array {
        $out=array();foreach($input as $raw){$d=new WC_Product_Download();$d->set_id((string)$raw['id']);$d->set_name((string)$raw['name']);$d->set_file((string)$raw['file']);$out[(string)$raw['id']]=$d;}return $out;
    }

    private static function serialize_downloads( $downloads ): array {
        $out=array();foreach((array)$downloads as $download){if($download instanceof WC_Product_Download)$out[]=array('id'=>(string)$download->get_id(),'name'=>(string)$download->get_name(),'file'=>(string)$download->get_file());}return $out;
    }

    private static function sanitize_global_attribute_payload( array $input, bool $creating ) {
        $out=array();if(array_key_exists('name',$input))$out['name']=sanitize_text_field((string)$input['name']);if($creating&&empty($out['name']))return new WP_Error('alify_ai_attribute_name_required','Attribute name is required.',array('status'=>400));
        if(array_key_exists('slug',$input))$out['slug']=preg_replace('/^pa_/', '', sanitize_title((string)$input['slug']));
        if(array_key_exists('type',$input)){ $v=sanitize_key((string)$input['type']);$allowed=function_exists('wc_get_attribute_types')?array_keys((array)wc_get_attribute_types()):array('select');if(!in_array($v,$allowed,true))return new WP_Error('alify_ai_invalid_attribute_type','Unsupported WooCommerce attribute type.',array('status'=>400,'allowed'=>$allowed));$out['type']=$v; }
        if(array_key_exists('order_by',$input)){ $v=sanitize_key((string)$input['order_by']);if(!in_array($v,array('menu_order','name','name_num','id'),true))return new WP_Error('alify_ai_invalid_attribute_order','order_by must be menu_order, name, name_num, or id.',array('status'=>400));$out['order_by']=$v; }
        if(array_key_exists('has_archives',$input))$out['has_archives']=(bool)$input['has_archives'];return $out;
    }

    private static function serialize_global_attribute( $attr ): array {
        $id = absint( $attr->id ?? 0 );
        // wc_get_attribute() may expose the taxonomy form (pa_color). Preserve the
        // underscore while detecting it; sanitize_title() would turn it into pa-color.
        $raw_slug = trim( (string) ( $attr->slug ?? '' ) );
        if ( str_starts_with( $raw_slug, 'pa_' ) ) {
            $taxonomy = sanitize_key( $raw_slug );
            $slug = function_exists( 'wc_attribute_taxonomy_slug' ) ? wc_attribute_taxonomy_slug( $taxonomy ) : preg_replace( '/^pa_/', '', $taxonomy );
        } else {
            $slug = function_exists( 'wc_attribute_taxonomy_slug' ) ? wc_attribute_taxonomy_slug( $raw_slug ) : preg_replace( '/^pa_/', '', $raw_slug );
            $slug = sanitize_title( $slug );
            $taxonomy = function_exists( 'wc_attribute_taxonomy_name' ) ? wc_attribute_taxonomy_name( $slug ) : 'pa_' . $slug;
        }
        return array( 'id' => $id, 'name' => (string) ( $attr->name ?? '' ), 'slug' => $slug, 'taxonomy' => $taxonomy, 'type' => (string) ( $attr->type ?? 'select' ), 'order_by' => (string) ( $attr->order_by ?? 'menu_order' ), 'has_archives' => (bool) ( $attr->has_archives ?? false ) );
    }

    private static function attribute_snapshot_to_payload( array $snapshot ): array { return array_intersect_key($snapshot,array_flip(array('name','slug','type','order_by','has_archives'))); }
    private static function sanitize_term_payload(array $input,bool $creating){$out=array();if(array_key_exists('name',$input))$out['name']=sanitize_text_field((string)$input['name']);if($creating&&empty($out['name']))return new WP_Error('alify_ai_term_name_required','Term name is required.',array('status'=>400));if(array_key_exists('slug',$input))$out['slug']=sanitize_title((string)$input['slug']);if(array_key_exists('description',$input))$out['description']=sanitize_textarea_field((string)$input['description']);return $out;}
    public static function serialize_term($term): array { if(!$term instanceof WP_Term)return array();return array('id'=>(int)$term->term_id,'name'=>(string)$term->name,'slug'=>(string)$term->slug,'description'=>(string)$term->description,'count'=>(int)$term->count,'taxonomy'=>(string)$term->taxonomy); }

    private static function sanitize_tax_class_value(string $value,bool $variation=false){$value=sanitize_title($value);if('standard'===$value)$value='';if($variation&&'parent'===$value)return 'parent';if(''===$value)return '';$class=self::get_tax_class($value);return is_wp_error($class)?new WP_Error('alify_ai_invalid_tax_class','Tax class does not exist.',array('status'=>400,'tax_class'=>$value)):$value;}
    private static function sanitize_shipping_class_id($value){$id=absint($value);if(!$id)return 0;$term=get_term($id,'product_shipping_class');if(!$term||is_wp_error($term))return new WP_Error('alify_ai_invalid_shipping_class','Shipping class does not exist.',array('status'=>400));return $id;}
    private static function sanitize_term_ids($input,string $taxonomy){if(!is_array($input))return new WP_Error('alify_ai_invalid_term_ids','Expected an array of term IDs.',array('status'=>400,'taxonomy'=>$taxonomy));$out=array();foreach($input as $raw){$id=absint($raw);$term=$id?get_term($id,$taxonomy):null;if(!$term||is_wp_error($term))return new WP_Error('alify_ai_invalid_term_id','One or more term IDs do not exist.',array('status'=>400,'taxonomy'=>$taxonomy,'term_id'=>$id));$out[]=$id;}return array_values(array_unique($out));}
    private static function sanitize_image_id($value){$id=absint($value);if(!$id)return 0;$post=get_post($id);if(!$post||'attachment'!==$post->post_type||!wp_attachment_is_image($id))return new WP_Error('alify_ai_invalid_image','Image ID must reference an existing image attachment.',array('status'=>400));return $id;}
    private static function sanitize_wc_datetime($value){if(null===$value||''===(string)$value)return '';try{$dt=self::wc_datetime((string)$value);return $dt->date(DATE_ATOM);}catch(Throwable $e){return new WP_Error('alify_ai_invalid_sale_date','Sale date must be a valid ISO/RFC3339 date-time.',array('status'=>400));}}
    private static function wc_datetime(string $value){if(function_exists('wc_string_to_datetime'))return wc_string_to_datetime($value);return new WC_DateTime($value);}
    private static function serialize_wc_datetime($dt): ?string { return $dt&&is_object($dt)&&method_exists($dt,'date')?$dt->date(DATE_ATOM):null; }

    private static function snapshot_to_product_payload(array $snapshot): array {$keys=array('name','slug','status','description','short_description','purchase_note','sku','regular_price','sale_price','date_on_sale_from','date_on_sale_to','manage_stock','stock_quantity','low_stock_amount','stock_status','backorders','featured','catalog_visibility','virtual','downloadable','sold_individually','reviews_allowed','tax_status','tax_class','weight','shipping_class_id','category_ids','tag_ids','image_id','gallery_image_ids','upsell_ids','cross_sell_ids','menu_order','download_limit','download_expiry','downloads','attributes','default_attributes');$out=array_intersect_key($snapshot,array_flip($keys));if(isset($snapshot['dimensions'])&&is_array($snapshot['dimensions']))$out=array_merge($out,$snapshot['dimensions']);return $out;}

    private static function snapshot_term_with_relationships( int $term_id, string $taxonomy ) {
        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) return new WP_Error( 'alify_ai_term_not_found', 'Term not found.', array( 'status' => 404 ) );
        $objects = get_objects_in_term( $term_id, $taxonomy );
        if ( is_wp_error( $objects ) ) $objects = array();
        return array( 'term' => self::serialize_term( $term ), 'taxonomy' => $taxonomy, 'object_ids' => array_map( 'absint', (array) $objects ) );
    }

    private static function restore_term_snapshot( array $snapshot ) {
        $term = (array) ( $snapshot['term'] ?? $snapshot );
        $taxonomy = sanitize_key( (string) ( $snapshot['taxonomy'] ?? $term['taxonomy'] ?? '' ) );
        if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) return new WP_Error( 'alify_ai_restore_taxonomy_missing', 'Cannot restore term because its taxonomy is unavailable.', array( 'status' => 409 ) );
        $insert = wp_insert_term( (string) $term['name'], $taxonomy, array( 'slug' => (string) $term['slug'], 'description' => (string) ( $term['description'] ?? '' ) ) );
        if ( is_wp_error( $insert ) ) return $insert;
        $new_id = absint( $insert['term_id'] );
        foreach ( array_map( 'absint', (array) ( $snapshot['object_ids'] ?? array() ) ) as $object_id ) {
            $assigned = wp_set_object_terms( $object_id, array( $new_id ), $taxonomy, true );
            if ( is_wp_error( $assigned ) ) return $assigned;
        }
        return array( 'restored_term' => self::serialize_term( get_term( $new_id, $taxonomy ) ), 'old_term_id' => absint( $term['id'] ?? 0 ), 'new_term_id' => $new_id, 'restored_object_count' => count( (array) ( $snapshot['object_ids'] ?? array() ) ) );
    }

    private static function snapshot_attribute_for_delete( int $id ) {
        $attribute = self::get_attribute( $id ); if ( is_wp_error( $attribute ) ) return $attribute;
        $taxonomy = (string) $attribute['taxonomy'];
        $terms = array();
        if ( taxonomy_exists( $taxonomy ) ) {
            $rows = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
            if ( is_wp_error( $rows ) ) return $rows;
            foreach ( $rows as $term ) {
                $snapshot = self::snapshot_term_with_relationships( (int) $term->term_id, $taxonomy );
                if ( is_wp_error( $snapshot ) ) return $snapshot;
                $terms[] = $snapshot;
            }
        }
        return array( 'attribute' => $attribute, 'terms' => $terms );
    }

    private static function restore_deleted_attribute_snapshot( array $snapshot ) {
        $attribute = (array) ( $snapshot['attribute'] ?? $snapshot );
        $created = wc_create_attribute( self::attribute_snapshot_to_payload( $attribute ) );
        if ( is_wp_error( $created ) ) return $created;
        $new_attribute = self::get_attribute( (int) $created );
        if ( is_wp_error( $new_attribute ) ) return $new_attribute;
        $taxonomy = (string) $new_attribute['taxonomy'];
        // A just-created global taxonomy may not be registered until the next request. Register a minimal
        // in-request taxonomy solely so the snapshotted terms/relationships can be restored atomically.
        if ( ! taxonomy_exists( $taxonomy ) && function_exists( 'register_taxonomy' ) ) {
            register_taxonomy( $taxonomy, array( 'product' ), array( 'public' => false, 'show_ui' => false, 'hierarchical' => false ) );
        }
        $restored_terms = array();
        foreach ( (array) ( $snapshot['terms'] ?? array() ) as $term_snapshot ) {
            $term_snapshot['taxonomy'] = $taxonomy;
            if ( isset( $term_snapshot['term'] ) && is_array( $term_snapshot['term'] ) ) $term_snapshot['term']['taxonomy'] = $taxonomy;
            $restored = self::restore_term_snapshot( $term_snapshot );
            if ( is_wp_error( $restored ) ) return $restored;
            $restored_terms[] = $restored;
        }
        return array( 'attribute' => $new_attribute, 'old_attribute_id' => absint( $attribute['id'] ?? 0 ), 'new_attribute_id' => (int) $created, 'restored_terms' => $restored_terms );
    }

    private static function tax_class_rate_count( string $slug ): int {
        if ( ! class_exists( 'WC_Tax' ) || ! method_exists( 'WC_Tax', 'get_rates_for_tax_class' ) ) return 0;
        try { $rates = WC_Tax::get_rates_for_tax_class( $slug ); } catch ( Throwable $e ) { return 0; }
        return count( (array) $rates );
    }

    /* ---------------------------------------------------------------------
     * Generic helpers
     * ------------------------------------------------------------------ */

    private static function preview_catalog_operation(string $operation,int $object_id,array $request,array $preview){$request=array_merge(array('operation'=>$operation),$request);$preview['operation']=$operation;$preview['safety']=self::safety_boundary();return ALIFY_AI_Approvals::create('woocommerce_catalog',$object_id,$request,$preview);}
    private static function current_catalog_snapshot(array $request){$op=(string)($request['operation']??'');if(str_starts_with($op,'product.'))return self::get_product(absint($request['product_id']??0));if(str_starts_with($op,'attribute_term.'))return self::get_attribute_term(absint($request['attribute_id']??0),absint($request['term_id']??0));if(str_starts_with($op,'attribute.'))return self::get_attribute(absint($request['attribute_id']??0));if(str_starts_with($op,'variation.'))return self::get_variation(absint($request['product_id']??0),absint($request['variation_id']??0));if(str_starts_with($op,'shipping_class.'))return self::get_shipping_class(absint($request['term_id']??0));if(str_starts_with($op,'tax_class.'))return self::get_tax_class((string)($request['slug']??''));return new WP_Error('alify_ai_invalid_catalog_operation','Unsupported WooCommerce catalog operation.',array('status'=>400));}
    private static function safety_boundary(): array {return array('orders_separate_scope'=>true,'payments_untouched'=>true,'refunds_untouched'=>true,'customers_untouched'=>true,'mutations_require_approval'=>true);}
    private static function require_available(){return self::available()?true:new WP_Error('alify_ai_woocommerce_unavailable','WooCommerce is not active or required CRUD classes are unavailable.',array('status'=>409));}
    private static function safe_get_product(int $id){try{$product=wc_get_product($id);}catch(Throwable $e){return new WP_Error('alify_ai_woocommerce_runtime_failure','WooCommerce failed while loading the product.',array('status'=>500));}if(!$product)return new WP_Error('alify_ai_product_not_found','Product not found.',array('status'=>404));return $product;}
    private static function safe_variable_product(int $id){$product=self::safe_get_product($id);if(is_wp_error($product))return $product;if(!$product instanceof WC_Product_Variable)return new WP_Error('alify_ai_variable_product_required','This operation requires a variable product.',array('status'=>409));return $product;}
    private static function safe_save_product(WC_Product $product){try{$id=$product->save();}catch(Throwable $e){return new WP_Error('alify_ai_product_save_failed',$e->getMessage()?:'WooCommerce failed while saving the product.',array('status'=>500));}return $id?(int)$id:new WP_Error('alify_ai_product_save_failed','Could not save WooCommerce product.',array('status'=>500));}
    private static function sync_variable_product(int $id): void {try{if(class_exists('WC_Product_Variable')&&method_exists('WC_Product_Variable','sync'))WC_Product_Variable::sync($id);}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'woocommerce_variable_sync',array('product_id'=>$id));} }
    private static function tax_class_product_count(string $slug): int {if(!function_exists('get_posts'))return 0;$ids=get_posts(array('post_type'=>array('product','product_variation'),'post_status'=>'any','fields'=>'ids','posts_per_page'=>101,'meta_key'=>'_tax_class','meta_value'=>$slug,'no_found_rows'=>true,'suppress_filters'=>true));return min(101,count((array)$ids));}
    private static function audit_or_revert(string $action,string $type,int $id,$before,$after,callable $revert){
        $snapshot=ALIFY_AI_Audit::validate_snapshot($before,$after);
        if(is_wp_error($snapshot)){
            $reverted=self::run_compensation($revert,'woocommerce_snapshot_revert',array('action'=>$action,'type'=>$type,'id'=>$id));
            if(is_wp_error($reverted)) return new WP_Error('alify_ai_woocommerce_unknown_state','WooCommerce snapshot validation failed and compensation could not be verified; site state requires review.',array('status'=>500,'cause'=>$snapshot->get_error_code(),'compensation_error'=>$reverted->get_error_message()));
            return $snapshot;
        }
        $log=ALIFY_AI_Audit::log($action,$type,$id,$before,$after);
        if(!$log){
            $reverted=self::run_compensation($revert,'woocommerce_audit_revert',array('action'=>$action,'type'=>$type,'id'=>$id));
            if(is_wp_error($reverted)) return new WP_Error('alify_ai_woocommerce_unknown_state','WooCommerce audit logging failed and compensation could not be verified; site state requires review.',array('status'=>500,'compensation_error'=>$reverted->get_error_message()));
            return new WP_Error('alify_ai_audit_failed','WooCommerce change was reverted because the activity log could not be stored.',array('status'=>500));
        }
        return array('message'=>'WooCommerce catalog change applied.','activity_id'=>$log,'item'=>$after);
    }

    private static function run_compensation(callable $revert,string $context,array $details){
        try{$result=$revert();}
        catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,$context,$details);return new WP_Error('alify_ai_compensation_exception',$e->getMessage()?:'Compensation threw an exception.',array('status'=>500));}
        if(is_wp_error($result)){ALIFY_AI_Diagnostics::log($context,array_merge($details,array('error'=>$result->get_error_message())));return $result;}
        if(false===$result){ALIFY_AI_Diagnostics::log($context,array_merge($details,array('error'=>'Compensation returned false.')));return new WP_Error('alify_ai_compensation_failed','Compensation returned false.',array('status'=>500));}
        return true;
    }
}
