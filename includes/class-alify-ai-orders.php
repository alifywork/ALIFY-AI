<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce order adapter.
 *
 * Orders are deliberately separated from the catalog scope because order
 * mutations can trigger email, stock, tax and accounting side effects.
 * Payments, refunds, gateway calls and payment-token mutations are never
 * performed by this adapter.
 */
final class ALIFY_AI_Orders {
    private const MAX_PAGE_SIZE = 100;
    private const MAX_LINE_ITEMS = 100;
    private const MAX_NOTE_LENGTH = 5000;
    private const ADDRESS_FIELDS = array(
        'first_name', 'last_name', 'company', 'address_1', 'address_2',
        'city', 'state', 'postcode', 'country', 'email', 'phone',
    );

    public static function available(): bool {
        return ALIFY_AI_WooCommerce::available()
            && function_exists( 'wc_get_orders' )
            && function_exists( 'wc_get_order' )
            && function_exists( 'wc_create_order' )
            && function_exists( 'wc_format_decimal' )
            && class_exists( 'WC_Order' )
            && class_exists( 'WC_Order_Item_Product' );
    }

    public static function status(): array {
        return array(
            'active'                    => self::available(),
            'reads'                     => self::available(),
            'create'                    => 'preview_then_approval',
            'order_updates'             => 'preview_then_approval',
            'line_item_changes'         => 'preview_then_approval',
            'notes'                     => 'preview_then_approval',
            'payments'                  => false,
            'refunds'                   => false,
            'payment_tokens'            => false,
            'gateway_capture_void'      => false,
            'permanent_order_delete'    => false,
            'hpos_safe_crud'            => true,
            'status_side_effect_guard'  => true,
            'customer_note_email_guard' => true,
        );
    }

    public static function list_statuses(): array {
        $statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
        $items = array();
        foreach ( (array) $statuses as $key => $label ) {
            $slug = str_starts_with( (string) $key, 'wc-' ) ? substr( (string) $key, 3 ) : (string) $key;
            $items[] = array( 'slug' => sanitize_key( $slug ), 'label' => wp_strip_all_tags( (string) $label ) );
        }
        return array( 'items' => $items, 'total' => count( $items ) );
    }

    public static function list_orders( array $input = array() ) {
        $ready = self::require_available();
        if ( is_wp_error( $ready ) ) return $ready;

        $limit = max( 1, min( self::MAX_PAGE_SIZE, (int) ( $input['per_page'] ?? 20 ) ) );
        $page  = max( 1, (int) ( $input['page'] ?? 1 ) );
        $args = array(
            'limit'    => $limit,
            'page'     => $page,
            'paginate' => true,
            'orderby'  => 'date',
            'order'    => 'DESC',
        );

        if ( ! empty( $input['status'] ) ) {
            $status = sanitize_key( (string) $input['status'] );
            if ( ! self::valid_status( $status ) ) return new WP_Error( 'alify_ai_invalid_order_status', 'Unknown WooCommerce order status.', array( 'status' => 400 ) );
            $args['status'] = $status;
        }
        if ( isset( $input['customer_id'] ) && '' !== (string) $input['customer_id'] ) $args['customer_id'] = absint( $input['customer_id'] );
        if ( ! empty( $input['billing_email'] ) ) $args['billing_email'] = sanitize_email( (string) $input['billing_email'] );
        $after_date  = ! empty( $input['date_after'] ) ? self::sanitize_query_date( (string) $input['date_after'] ) : '';
        $before_date = ! empty( $input['date_before'] ) ? self::sanitize_query_date( (string) $input['date_before'] ) : '';
        if ( $after_date && $before_date ) $args['date_created'] = $after_date . '...' . $before_date;
        elseif ( $after_date ) $args['date_created'] = '>=' . $after_date;
        elseif ( $before_date ) $args['date_created'] = '<=' . $before_date;
        if ( ! empty( $input['order_id'] ) ) $args['id'] = absint( $input['order_id'] );

        try {
            $result = wc_get_orders( $args );
        } catch ( Throwable $e ) {
            return new WP_Error( 'alify_ai_order_query_failed', 'WooCommerce failed while querying orders.', array( 'status' => 500 ) );
        }

        $orders = array(); $total = 0; $pages = 1;
        if ( is_object( $result ) && isset( $result->orders ) ) {
            $orders = (array) $result->orders;
            $total  = (int) ( $result->total ?? count( $orders ) );
            $pages  = (int) ( $result->max_num_pages ?? 1 );
        } elseif ( is_array( $result ) ) {
            $orders = $result; $total = count( $orders );
        }

        return array(
            'items'       => array_values( array_filter( array_map( static fn( $o ) => $o instanceof WC_Order ? self::serialize_order( $o, false ) : null, $orders ) ) ),
            'total'       => $total,
            'total_pages' => max( 1, $pages ),
            'page'        => $page,
            'per_page'    => $limit,
        );
    }

    public static function get_order( int $id ) {
        $order = self::safe_get_order( $id );
        return is_wp_error( $order ) ? $order : self::serialize_order( $order, true );
    }

    public static function list_notes( int $order_id, array $input = array() ) {
        $order = self::safe_get_order( $order_id );
        if ( is_wp_error( $order ) ) return $order;
        if ( ! function_exists( 'wc_get_order_notes' ) ) return new WP_Error( 'alify_ai_order_notes_unavailable', 'WooCommerce order-note API is unavailable.', array( 'status' => 409 ) );
        $limit = max( 1, min( 100, (int) ( $input['limit'] ?? 50 ) ) );
        $type = sanitize_key( (string) ( $input['type'] ?? '' ) );
        if ( ! in_array( $type, array( '', 'customer', 'internal' ), true ) ) return new WP_Error( 'alify_ai_invalid_note_type', 'Note type must be customer or internal.', array( 'status' => 400 ) );
        try {
            $notes = wc_get_order_notes( array( 'order_id' => $order_id, 'limit' => $limit, 'orderby' => 'date_created', 'order' => 'DESC', 'type' => $type ) );
        } catch ( Throwable $e ) {
            return new WP_Error( 'alify_ai_order_note_query_failed', 'Could not load order notes.', array( 'status' => 500 ) );
        }
        return array( 'items' => array_map( array( __CLASS__, 'serialize_note' ), (array) $notes ), 'total' => count( (array) $notes ) );
    }

    public static function preview_create( array $input ) {
        $ready = self::require_available(); if ( is_wp_error( $ready ) ) return $ready;
        $payload = self::sanitize_order_create_payload( $input );
        if ( is_wp_error( $payload ) ) return $payload;
        return self::proposal( 'order.create', 0, array( 'payload' => $payload ), array(
            'action' => 'create_order', 'proposed' => $payload,
            'warning' => 'Creating an order can trigger third-party hooks or notifications. Payments are not captured by this connector.',
        ) );
    }

    public static function preview_update( int $id, array $input ) {
        $order = self::safe_get_order( $id ); if ( is_wp_error( $order ) ) return $order;
        $before = self::serialize_order( $order, true );
        $payload = self::sanitize_order_update_payload( $input, $order );
        if ( is_wp_error( $payload ) ) return $payload;
        if ( empty( $payload ) ) return new WP_Error( 'alify_ai_no_changes', 'No supported order fields were supplied.', array( 'status' => 400 ) );
        $preview = array( 'action' => 'update_order', 'before' => $before, 'proposed' => $payload, 'source_hash' => self::hash_snapshot( $before ) );
        if ( isset( $payload['status'] ) && $payload['status'] !== $before['status'] ) {
            $preview['external_side_effect_possible'] = true;
            $preview['warning'] = 'WooCommerce status transitions can trigger email, stock, webhooks or third-party workflows. Rollback restores data but cannot undo already-sent external side effects.';
        }
        return self::proposal( 'order.update', $id, array( 'order_id' => $id, 'payload' => $payload ), $preview );
    }

    public static function preview_item_create( int $order_id, array $input ) {
        $order = self::safe_get_order( $order_id ); if ( is_wp_error( $order ) ) return $order;
        if ( empty( $input['confirm_financial_totals'] ) ) return new WP_Error( 'alify_ai_order_total_confirmation_required', 'Line-item changes alter order totals. Set confirm_financial_totals=true to preview this change.', array( 'status' => 409 ) );
        $payload = self::sanitize_line_item_payload( $input, true, 0 ); if ( is_wp_error( $payload ) ) return $payload;
        $before = self::serialize_order( $order, true );
        return self::proposal( 'item.create', $order_id, array( 'order_id' => $order_id, 'payload' => $payload ), array( 'action' => 'create_order_item', 'before' => $before, 'proposed' => $payload, 'source_hash' => self::hash_snapshot( $before ) ) );
    }

    public static function preview_item_update( int $order_id, int $item_id, array $input ) {
        $order = self::safe_get_order( $order_id ); if ( is_wp_error( $order ) ) return $order;
        $item = self::safe_line_item( $order, $item_id ); if ( is_wp_error( $item ) ) return $item;
        if ( empty( $input['confirm_financial_totals'] ) ) return new WP_Error( 'alify_ai_order_total_confirmation_required', 'Line-item changes alter order totals. Set confirm_financial_totals=true to preview this change.', array( 'status' => 409 ) );
        $payload = self::sanitize_line_item_payload( $input, false, $item_id ); if ( is_wp_error( $payload ) ) return $payload;
        if ( empty( $payload ) ) return new WP_Error( 'alify_ai_no_changes', 'No supported line-item fields were supplied.', array( 'status' => 400 ) );
        $before = self::serialize_order( $order, true );
        return self::proposal( 'item.update', $item_id, array( 'order_id' => $order_id, 'item_id' => $item_id, 'payload' => $payload ), array( 'action' => 'update_order_item', 'before' => $before, 'item_before' => self::serialize_line_item( $item ), 'proposed' => $payload, 'source_hash' => self::hash_snapshot( $before ) ) );
    }

    public static function preview_item_delete( int $order_id, int $item_id, array $input ) {
        $order = self::safe_get_order( $order_id ); if ( is_wp_error( $order ) ) return $order;
        $item = self::safe_line_item( $order, $item_id ); if ( is_wp_error( $item ) ) return $item;
        if ( empty( $input['confirm_delete'] ) || empty( $input['confirm_financial_totals'] ) ) return new WP_Error( 'alify_ai_order_item_delete_confirmation_required', 'Deleting an order item requires confirm_delete=true and confirm_financial_totals=true.', array( 'status' => 409 ) );
        $before = self::serialize_order( $order, true );
        return self::proposal( 'item.delete', $item_id, array( 'order_id' => $order_id, 'item_id' => $item_id ), array( 'action' => 'delete_order_item', 'before' => $before, 'item_before' => self::serialize_line_item( $item ), 'source_hash' => self::hash_snapshot( $before ) ) );
    }

    public static function preview_note_create( int $order_id, array $input ) {
        $order = self::safe_get_order( $order_id ); if ( is_wp_error( $order ) ) return $order;
        $note = trim( wp_strip_all_tags( (string) ( $input['note'] ?? '' ) ) );
        if ( '' === $note ) return new WP_Error( 'alify_ai_order_note_required', 'Order note text is required.', array( 'status' => 400 ) );
        if ( strlen( $note ) > self::MAX_NOTE_LENGTH ) return new WP_Error( 'alify_ai_order_note_too_long', 'Order note is too long.', array( 'status' => 413 ) );
        $customer = ! empty( $input['customer_note'] );
        if ( $customer && empty( $input['confirm_customer_notification'] ) ) return new WP_Error( 'alify_ai_customer_note_confirmation_required', 'Customer notes may trigger customer notification email. Set confirm_customer_notification=true to continue.', array( 'status' => 409 ) );
        return self::proposal( 'note.create', $order_id, array( 'order_id' => $order_id, 'note' => $note, 'customer_note' => $customer ), array( 'action' => 'create_order_note', 'customer_note' => $customer, 'proposed' => array( 'note' => $note ), 'external_side_effect_possible' => $customer ) );
    }

    public static function preview_note_delete( int $order_id, int $note_id, array $input ) {
        $order = self::safe_get_order( $order_id ); if ( is_wp_error( $order ) ) return $order;
        if ( empty( $input['confirm_delete'] ) ) return new WP_Error( 'alify_ai_confirmation_required', 'Deleting an order note requires confirm_delete=true.', array( 'status' => 409 ) );
        $note = self::get_note( $order_id, $note_id ); if ( is_wp_error( $note ) ) return $note;
        return self::proposal( 'note.delete', $note_id, array( 'order_id' => $order_id, 'note_id' => $note_id ), array( 'action' => 'delete_order_note', 'before' => $note ) );
    }

    public static function apply_approval( array $approval ) {
        $ready = self::require_available(); if ( is_wp_error( $ready ) ) return $ready;
        if ( 'woocommerce_order' !== (string) ( $approval['kind'] ?? '' ) ) return new WP_Error( 'alify_ai_invalid_approval_kind', 'Unsupported order approval kind.', array( 'status' => 400 ) );
        $request = (array) ( $approval['request'] ?? array() );
        $op = (string) ( $request['operation'] ?? '' );

        if ( isset( $approval['preview']['source_hash'] ) && '' !== (string) $approval['preview']['source_hash'] ) {
            $order = self::safe_get_order( absint( $request['order_id'] ?? 0 ) ); if ( is_wp_error( $order ) ) return $order;
            $current = self::serialize_order( $order, true );
            if ( ! hash_equals( (string) $approval['preview']['source_hash'], self::hash_snapshot( $current ) ) ) return new WP_Error( 'alify_ai_stale_order', 'Order changed after preview. Create a fresh proposal before applying.', array( 'status' => 409 ) );
        }

        switch ( $op ) {
            case 'order.create': return self::apply_create( (array) ( $request['payload'] ?? array() ) );
            case 'order.update': return self::apply_update( absint( $request['order_id'] ?? 0 ), (array) ( $request['payload'] ?? array() ) );
            case 'item.create': return self::apply_item_create( absint( $request['order_id'] ?? 0 ), (array) ( $request['payload'] ?? array() ) );
            case 'item.update': return self::apply_item_update( absint( $request['order_id'] ?? 0 ), absint( $request['item_id'] ?? 0 ), (array) ( $request['payload'] ?? array() ) );
            case 'item.delete': return self::apply_item_delete( absint( $request['order_id'] ?? 0 ), absint( $request['item_id'] ?? 0 ) );
            case 'note.create': return self::apply_note_create( absint( $request['order_id'] ?? 0 ), (string) ( $request['note'] ?? '' ), ! empty( $request['customer_note'] ) );
            case 'note.delete': return self::apply_note_delete( absint( $request['order_id'] ?? 0 ), absint( $request['note_id'] ?? 0 ) );
        }
        return new WP_Error( 'alify_ai_invalid_order_operation', 'Unsupported WooCommerce order operation.', array( 'status' => 400 ) );
    }

    private static function apply_create( array $payload ) {
        try {
            $args = array( 'created_via' => 'alify-ai' );
            if ( isset( $payload['customer_id'] ) ) $args['customer_id'] = absint( $payload['customer_id'] );
            if ( isset( $payload['status'] ) ) $args['status'] = (string) $payload['status'];
            $order = wc_create_order( $args );
        } catch ( Throwable $e ) {
            return new WP_Error( 'alify_ai_order_create_failed', $e->getMessage() ?: 'WooCommerce failed while creating the order.', array( 'status' => 500 ) );
        }
        if ( is_wp_error( $order ) ) return $order;
        if ( ! $order instanceof WC_Order ) return new WP_Error( 'alify_ai_order_create_failed', 'WooCommerce did not return a valid order.', array( 'status' => 500 ) );
        $id = (int) $order->get_id();

        $applied = self::apply_order_fields( $order, $payload, true );
        if ( is_wp_error( $applied ) ) { self::delete_connector_created_order( $order ); return $applied; }
        foreach ( (array) ( $payload['line_items'] ?? array() ) as $line ) {
            $item = self::add_line_item( $order, (array) $line );
            if ( is_wp_error( $item ) ) { self::delete_connector_created_order( $order ); return $item; }
        }
        try { $order->calculate_totals( true ); $order->save(); } catch ( Throwable $e ) { self::delete_connector_created_order( $order ); return new WP_Error( 'alify_ai_order_save_failed', 'Order creation failed while calculating totals.', array( 'status' => 500 ) ); }
        $after = self::serialize_order( $order, true );
        $snap = ALIFY_AI_Audit::validate_snapshot( null, $after ); if ( is_wp_error( $snap ) ) { self::delete_connector_created_order( $order ); return $snap; }
        $log = ALIFY_AI_Audit::log( 'woocommerce_order_create', 'shop_order', $id, null, $after );
        if ( ! $log ) { self::delete_connector_created_order( $order ); return new WP_Error( 'alify_ai_audit_failed', 'Order creation was reverted because the activity log could not be stored.', array( 'status' => 500 ) ); }
        return array( 'message' => 'WooCommerce order created.', 'activity_id' => $log, 'item' => $after );
    }

    private static function apply_update( int $id, array $payload ) {
        $order = self::safe_get_order( $id ); if ( is_wp_error( $order ) ) return $order;
        $before = self::serialize_order( $order, true );
        $applied = self::apply_order_fields( $order, $payload, false ); if ( is_wp_error( $applied ) ) return $applied;
        try { $order->save(); } catch ( Throwable $e ) {
            // A third-party save hook may throw after WooCommerce has already persisted part of the change.
            // Best-effort restoration keeps the data model consistent even on hook failures.
            self::restore_order_fields_snapshot( $id, $before );
            return new WP_Error( 'alify_ai_order_save_failed', $e->getMessage() ?: 'WooCommerce failed while saving the order.', array( 'status' => 500 ) );
        }
        $fresh = self::safe_get_order( $id ); if ( is_wp_error( $fresh ) ) { self::restore_order_fields_snapshot( $id, $before ); return $fresh; }
        $after = self::serialize_order( $fresh, true );
        return self::audit_or_revert( 'woocommerce_order_update', $id, $before, $after, static function () use ( $id, $before ) { return self::restore_order_fields_snapshot( $id, $before ); }, $after );
    }

    private static function apply_item_create( int $order_id, array $payload ) {
        $order = self::safe_get_order( $order_id ); if ( is_wp_error( $order ) ) return $order;
        $before = self::snapshot_order_for_line_rollback( $order );
        $item = self::add_line_item( $order, $payload );
        if ( is_wp_error( $item ) ) {
            // add_product() may have persisted an item before a later hook/reload failure.
            self::restore_line_items_snapshot( $order_id, $before );
            return $item;
        }
        try { $order->calculate_totals( true ); $order->save(); } catch ( Throwable $e ) { self::restore_line_items_snapshot( $order_id, $before ); return new WP_Error( 'alify_ai_order_item_save_failed', 'Could not save the new order item.', array( 'status' => 500 ) ); }
        $fresh = self::safe_get_order( $order_id ); if ( is_wp_error( $fresh ) ) { self::restore_line_items_snapshot( $order_id, $before ); return $fresh; }
        $after_public = self::serialize_order( $fresh, true );
        $after = self::snapshot_order_for_line_rollback( $fresh );
        return self::audit_or_revert( 'woocommerce_order_item_create', $order_id, $before, $after, static function () use ( $order_id, $before ) { return self::restore_line_items_snapshot( $order_id, $before ); }, $after_public );
    }

    private static function apply_item_update( int $order_id, int $item_id, array $payload ) {
        $order = self::safe_get_order( $order_id ); if ( is_wp_error( $order ) ) return $order;
        $item = self::safe_line_item( $order, $item_id ); if ( is_wp_error( $item ) ) return $item;
        $before = self::snapshot_order_for_line_rollback( $order );
        $result = self::apply_line_item_payload( $item, $payload ); if ( is_wp_error( $result ) ) return $result;
        try { $item->save(); $order->calculate_totals( true ); $order->save(); } catch ( Throwable $e ) { self::restore_line_items_snapshot( $order_id, $before ); return new WP_Error( 'alify_ai_order_item_save_failed', 'Could not save the order item.', array( 'status' => 500 ) ); }
        $fresh = self::safe_get_order( $order_id );
        if ( is_wp_error( $fresh ) ) { self::restore_line_items_snapshot( $order_id, $before ); return $fresh; }
        $after_public = self::serialize_order( $fresh, true );
        $after = self::snapshot_order_for_line_rollback( $fresh );
        return self::audit_or_revert( 'woocommerce_order_item_update', $order_id, $before, $after, static function () use ( $order_id, $before ) { return self::restore_line_items_snapshot( $order_id, $before ); }, $after_public );
    }

    private static function apply_item_delete( int $order_id, int $item_id ) {
        $order = self::safe_get_order( $order_id ); if ( is_wp_error( $order ) ) return $order;
        $item = self::safe_line_item( $order, $item_id ); if ( is_wp_error( $item ) ) return $item;
        $before = self::snapshot_order_for_line_rollback( $order );
        try { $order->remove_item( $item_id ); $order->calculate_totals( true ); $order->save(); } catch ( Throwable $e ) { self::restore_line_items_snapshot( $order_id, $before ); return new WP_Error( 'alify_ai_order_item_delete_failed', 'Could not delete the order item.', array( 'status' => 500 ) ); }
        $fresh = self::safe_get_order( $order_id ); if ( is_wp_error( $fresh ) ) { self::restore_line_items_snapshot( $order_id, $before ); return $fresh; }
        $after_public = self::serialize_order( $fresh, true );
        $after = self::snapshot_order_for_line_rollback( $fresh );
        return self::audit_or_revert( 'woocommerce_order_item_delete', $order_id, $before, $after, static function () use ( $order_id, $before ) { return self::restore_line_items_snapshot( $order_id, $before ); }, $after_public );
    }

    private static function apply_note_create( int $order_id, string $note, bool $customer ) {
        $order = self::safe_get_order( $order_id ); if ( is_wp_error( $order ) ) return $order;
        try { $note_id = $order->add_order_note( $note, $customer ? 1 : 0, true ); } catch ( Throwable $e ) { return new WP_Error( 'alify_ai_order_note_create_failed', 'Could not create order note.', array( 'status' => 500 ) ); }
        if ( ! $note_id ) return new WP_Error( 'alify_ai_order_note_create_failed', 'Could not create order note.', array( 'status' => 500 ) );
        $after = self::get_note( $order_id, (int) $note_id );
        if ( is_wp_error( $after ) ) $after = array( 'id' => (int) $note_id, 'note' => $note, 'customer_note' => $customer );
        $snap = ALIFY_AI_Audit::validate_snapshot( null, $after ); if ( is_wp_error( $snap ) ) { self::delete_note_direct( (int) $note_id ); return $snap; }
        $log = ALIFY_AI_Audit::log( 'woocommerce_order_note_create', 'shop_order_note', (int) $note_id, null, array_merge( array( 'order_id' => $order_id ), $after ) );
        if ( ! $log ) { self::delete_note_direct( (int) $note_id ); return new WP_Error( 'alify_ai_audit_failed', 'Order note creation was reverted because the activity log could not be stored.', array( 'status' => 500 ) ); }
        return array( 'message' => 'Order note created.', 'activity_id' => $log, 'item' => $after );
    }

    private static function apply_note_delete( int $order_id, int $note_id ) {
        $before = self::get_note( $order_id, $note_id ); if ( is_wp_error( $before ) ) return $before;
        $deleted = self::delete_note_direct( $note_id ); if ( is_wp_error( $deleted ) ) return $deleted;
        $after = array( 'id' => $note_id, 'order_id' => $order_id, 'deleted' => true );
        $snap = ALIFY_AI_Audit::validate_snapshot( array_merge( array( 'order_id' => $order_id ), $before ), $after );
        if ( is_wp_error( $snap ) ) { self::restore_note_snapshot( $order_id, $before ); return $snap; }
        $log = ALIFY_AI_Audit::log( 'woocommerce_order_note_delete', 'shop_order_note', $note_id, array_merge( array( 'order_id' => $order_id ), $before ), $after );
        if ( ! $log ) { self::restore_note_snapshot( $order_id, $before ); return new WP_Error( 'alify_ai_audit_failed', 'Order note deletion was reverted because the activity log could not be stored.', array( 'status' => 500 ) ); }
        return array( 'message' => 'Order note deleted.', 'activity_id' => $log, 'item' => $after );
    }

    public static function rollback_activity( array $entry ) {
        $action = (string) ( $entry['action'] ?? '' );
        $id = absint( $entry['object_id'] ?? 0 );
        $before = is_array( $entry['before_json'] ?? null ) ? $entry['before_json'] : null;
        $after = is_array( $entry['after_json'] ?? null ) ? $entry['after_json'] : null;
        $result = null;

        if ( 'woocommerce_order_create' === $action ) {
            $order = self::safe_get_order( $id ); if ( is_wp_error( $order ) ) return $order;
            $result = self::delete_connector_created_order( $order );
        } elseif ( 'woocommerce_order_update' === $action && $before ) {
            $result = self::restore_order_fields_snapshot( $id, $before );
        } elseif ( in_array( $action, array( 'woocommerce_order_item_create', 'woocommerce_order_item_update', 'woocommerce_order_item_delete' ), true ) && $before ) {
            $result = self::restore_line_items_snapshot( $id, $before );
        } elseif ( 'woocommerce_order_note_create' === $action ) {
            $result = self::delete_note_direct( $id );
        } elseif ( 'woocommerce_order_note_delete' === $action && $before ) {
            $order_id = absint( $before['order_id'] ?? 0 );
            $result = self::restore_note_snapshot( $order_id, $before );
        } else {
            return new WP_Error( 'alify_ai_order_rollback_unsupported', 'This WooCommerce order activity cannot be rolled back.', array( 'status' => 400 ) );
        }
        if ( is_wp_error( $result ) ) return $result;
        $log = ALIFY_AI_Audit::log( 'rollback_' . $action, (string) ( $entry['object_type'] ?? 'shop_order' ), $id, $after, is_array( $result ) ? $result : array( 'result' => $result ) );
        if ( ! $log ) return new WP_Error( 'alify_ai_audit_failed', 'Order change was rolled back, but the rollback activity could not be logged.', array( 'status' => 500 ) );
        return array( 'message' => 'WooCommerce order activity rolled back.', 'activity_id' => $log, 'result' => $result );
    }

    public static function serialize_order( WC_Order $order, bool $full = false ): array {
        $data = array(
            'id' => (int) $order->get_id(),
            'number' => (string) $order->get_order_number(),
            'status' => (string) $order->get_status(),
            'currency' => (string) $order->get_currency(),
            'total' => (string) $order->get_total(),
            'subtotal' => (string) $order->get_subtotal(),
            'total_tax' => (string) $order->get_total_tax(),
            'shipping_total' => (string) $order->get_shipping_total(),
            'discount_total' => (string) $order->get_discount_total(),
            'customer_id' => (int) $order->get_customer_id(),
            'billing_email' => (string) $order->get_billing_email(),
            'payment_method' => (string) $order->get_payment_method(),
            'payment_method_title' => (string) $order->get_payment_method_title(),
            'transaction_id' => (string) $order->get_transaction_id(),
            'date_created' => self::serialize_datetime( $order->get_date_created() ),
            'date_modified' => self::serialize_datetime( $order->get_date_modified() ),
            'date_paid' => self::serialize_datetime( $order->get_date_paid() ),
            'date_completed' => self::serialize_datetime( $order->get_date_completed() ),
            'needs_payment' => (bool) $order->needs_payment(),
            'needs_processing' => method_exists( $order, 'needs_processing' ) ? (bool) $order->needs_processing() : null,
            'customer_note' => (string) $order->get_customer_note(),
            'created_via' => (string) $order->get_created_via(),
        );
        if ( $full ) {
            $data['billing'] = self::serialize_address( $order, 'billing' );
            $data['shipping'] = self::serialize_address( $order, 'shipping' );
            $data['line_items'] = array_values( array_map( array( __CLASS__, 'serialize_line_item' ), $order->get_items( 'line_item' ) ) );
            $data['shipping_lines'] = array_values( array_map( array( __CLASS__, 'serialize_shipping_item' ), $order->get_items( 'shipping' ) ) );
            $data['fee_lines'] = array_values( array_map( array( __CLASS__, 'serialize_fee_item' ), $order->get_items( 'fee' ) ) );
            $data['coupon_lines'] = array_values( array_map( array( __CLASS__, 'serialize_coupon_item' ), $order->get_items( 'coupon' ) ) );
            $data['refund_total'] = (string) $order->get_total_refunded();
            $data['refund_count'] = count( $order->get_refunds() );
        }
        return $data;
    }

    public static function serialize_line_item( $item ): array {
        if ( ! $item instanceof WC_Order_Item_Product ) return array();
        $product = $item->get_product();
        return array(
            'id' => (int) $item->get_id(),
            'name' => (string) $item->get_name(),
            'product_id' => (int) $item->get_product_id(),
            'variation_id' => (int) $item->get_variation_id(),
            'sku' => $product instanceof WC_Product ? (string) $product->get_sku() : '',
            'quantity' => (float) $item->get_quantity(),
            'subtotal' => (string) $item->get_subtotal(),
            'subtotal_tax' => (string) $item->get_subtotal_tax(),
            'total' => (string) $item->get_total(),
            'total_tax' => (string) $item->get_total_tax(),
            'tax_class' => (string) $item->get_tax_class(),
            'variation' => (array) $item->get_variation_attributes(),
            'meta' => self::public_item_meta( $item ),
        );
    }

    public static function serialize_note( $note ): array {
        return array(
            'id' => absint( is_object( $note ) ? ( $note->id ?? 0 ) : 0 ),
            'note' => wp_strip_all_tags( (string) ( is_object( $note ) ? ( $note->content ?? '' ) : '' ) ),
            'customer_note' => (bool) ( is_object( $note ) ? ( $note->customer_note ?? false ) : false ),
            'added_by' => (string) ( is_object( $note ) ? ( $note->added_by ?? '' ) : '' ),
            'date_created' => self::serialize_datetime( is_object( $note ) ? ( $note->date_created ?? null ) : null ),
        );
    }

    private static function serialize_address( WC_Order $order, string $type ): array {
        $out = array();
        foreach ( self::ADDRESS_FIELDS as $field ) {
            $method = 'get_' . $type . '_' . $field;
            if ( is_callable( array( $order, $method ) ) ) $out[ $field ] = (string) $order->{$method}();
        }
        return $out;
    }

    private static function serialize_shipping_item( $item ): array {
        return array( 'id' => (int) $item->get_id(), 'method_title' => method_exists( $item, 'get_method_title' ) ? (string) $item->get_method_title() : '', 'method_id' => method_exists( $item, 'get_method_id' ) ? (string) $item->get_method_id() : '', 'instance_id' => method_exists( $item, 'get_instance_id' ) ? (int) $item->get_instance_id() : 0, 'total' => method_exists( $item, 'get_total' ) ? (string) $item->get_total() : '' );
    }
    private static function serialize_fee_item( $item ): array { return array( 'id' => (int) $item->get_id(), 'name' => (string) $item->get_name(), 'total' => method_exists( $item, 'get_total' ) ? (string) $item->get_total() : '', 'tax_class' => method_exists( $item, 'get_tax_class' ) ? (string) $item->get_tax_class() : '' ); }
    private static function serialize_coupon_item( $item ): array { return array( 'id' => (int) $item->get_id(), 'code' => method_exists( $item, 'get_code' ) ? (string) $item->get_code() : (string) $item->get_name(), 'discount' => method_exists( $item, 'get_discount' ) ? (string) $item->get_discount() : '' ); }

    private static function sanitize_order_create_payload( array $input ) {
        $payload = self::sanitize_order_update_payload( $input, null ); if ( is_wp_error( $payload ) ) return $payload;
        if ( isset( $input['line_items'] ) ) {
            $lines = (array) $input['line_items'];
            if ( count( $lines ) > self::MAX_LINE_ITEMS ) return new WP_Error( 'alify_ai_too_many_order_items', 'Too many line items in one order.', array( 'status' => 413 ) );
            $payload['line_items'] = array();
            foreach ( $lines as $line ) { $clean = self::sanitize_line_item_payload( (array) $line, true, 0 ); if ( is_wp_error( $clean ) ) return $clean; $payload['line_items'][] = $clean; }
        }
        if ( ! isset( $payload['status'] ) ) $payload['status'] = 'pending';
        if ( in_array( $payload['status'], array( 'processing', 'completed', 'refunded', 'cancelled' ), true ) && empty( $input['confirm_status_side_effects'] ) ) return new WP_Error( 'alify_ai_order_status_confirmation_required', 'Creating directly in this status may trigger WooCommerce hooks/emails. Set confirm_status_side_effects=true.', array( 'status' => 409 ) );
        return $payload;
    }

    private static function sanitize_order_update_payload( array $input, ?WC_Order $order ) {
        $out = array();
        if ( array_key_exists( 'status', $input ) ) {
            $status = sanitize_key( (string) $input['status'] );
            if ( ! self::valid_status( $status ) ) return new WP_Error( 'alify_ai_invalid_order_status', 'Unknown WooCommerce order status.', array( 'status' => 400 ) );
            $current = $order ? (string) $order->get_status() : '';
            if ( $order && $status !== $current && empty( $input['confirm_status_side_effects'] ) ) return new WP_Error( 'alify_ai_order_status_confirmation_required', 'Order status changes can trigger email, stock or third-party workflow side effects. Set confirm_status_side_effects=true.', array( 'status' => 409 ) );
            $out['status'] = $status;
        }
        if ( array_key_exists( 'customer_id', $input ) ) {
            $customer_id = absint( $input['customer_id'] );
            if ( $customer_id && ! get_user_by( 'id', $customer_id ) ) return new WP_Error( 'alify_ai_customer_not_found', 'WordPress/WooCommerce customer user not found.', array( 'status' => 404 ) );
            $out['customer_id'] = $customer_id;
        }
        if ( array_key_exists( 'customer_note', $input ) ) $out['customer_note'] = sanitize_textarea_field( (string) $input['customer_note'] );
        if ( isset( $input['billing'] ) ) $out['billing'] = self::sanitize_address( (array) $input['billing'], true );
        if ( isset( $input['shipping'] ) ) $out['shipping'] = self::sanitize_address( (array) $input['shipping'], false );
        return $out;
    }

    private static function sanitize_address( array $address, bool $billing ): array {
        $out = array();
        foreach ( self::ADDRESS_FIELDS as $field ) {
            if ( ! array_key_exists( $field, $address ) ) continue;
            if ( ! $billing && in_array( $field, array( 'email', 'phone' ), true ) ) continue;
            $value = (string) $address[ $field ];
            $out[ $field ] = 'email' === $field ? sanitize_email( $value ) : sanitize_text_field( $value );
        }
        return $out;
    }

    private static function sanitize_line_item_payload( array $input, bool $creating, int $item_id ) {
        $out = array();
        if ( array_key_exists( 'product_id', $input ) || $creating ) {
            $product_id = absint( $input['product_id'] ?? 0 );
            if ( ! $product_id ) return new WP_Error( 'alify_ai_product_required', 'product_id is required.', array( 'status' => 400 ) );
            $product = wc_get_product( $product_id );
            if ( ! $product instanceof WC_Product ) return new WP_Error( 'alify_ai_product_not_found', 'WooCommerce product not found.', array( 'status' => 404 ) );
            if ( $product instanceof WC_Product_Variable && empty( $input['variation_id'] ) ) return new WP_Error( 'alify_ai_variation_required', 'A variable parent product requires a variation_id for an order line.', array( 'status' => 400 ) );
            $out['product_id'] = $product_id;
        }
        if ( array_key_exists( 'variation_id', $input ) ) {
            $variation_id = absint( $input['variation_id'] );
            if ( $variation_id ) {
                $variation = wc_get_product( $variation_id );
                if ( ! $variation instanceof WC_Product_Variation ) return new WP_Error( 'alify_ai_variation_not_found', 'WooCommerce variation not found.', array( 'status' => 404 ) );
                if ( isset( $out['product_id'] ) && (int) $variation->get_parent_id() !== (int) $out['product_id'] ) return new WP_Error( 'alify_ai_variation_parent_mismatch', 'Variation does not belong to the supplied parent product.', array( 'status' => 409 ) );
            }
            $out['variation_id'] = $variation_id;
        }
        if ( array_key_exists( 'quantity', $input ) || $creating ) {
            $quantity = (float) ( $input['quantity'] ?? 1 );
            if ( $quantity <= 0 || $quantity > 100000 ) return new WP_Error( 'alify_ai_invalid_order_item_quantity', 'Order item quantity must be greater than zero.', array( 'status' => 400 ) );
            $out['quantity'] = $quantity;
        }
        foreach ( array( 'subtotal', 'total' ) as $key ) {
            if ( array_key_exists( $key, $input ) ) {
                $value = wc_format_decimal( $input[ $key ] );
                if ( ! is_numeric( $value ) || (float) $value < 0 ) return new WP_Error( 'alify_ai_invalid_order_item_total', $key . ' must be a non-negative decimal.', array( 'status' => 400 ) );
                $out[ $key ] = (string) $value;
            }
        }
        return $out;
    }

    private static function apply_order_fields( WC_Order $order, array $payload, bool $creating ) {
        try {
            if ( isset( $payload['customer_id'] ) ) $order->set_customer_id( (int) $payload['customer_id'] );
            if ( isset( $payload['customer_note'] ) ) $order->set_customer_note( (string) $payload['customer_note'] );
            foreach ( array( 'billing', 'shipping' ) as $type ) {
                if ( ! isset( $payload[ $type ] ) ) continue;
                foreach ( (array) $payload[ $type ] as $field => $value ) {
                    $method = 'set_' . $type . '_' . $field;
                    if ( is_callable( array( $order, $method ) ) ) $order->{$method}( $value );
                }
            }
            if ( isset( $payload['status'] ) ) $order->set_status( (string) $payload['status'] );
        } catch ( Throwable $e ) {
            return new WP_Error( 'alify_ai_invalid_order_data', $e->getMessage() ?: 'WooCommerce rejected the order data.', array( 'status' => 400 ) );
        }
        return true;
    }

    private static function add_line_item( WC_Order $order, array $payload ) {
        $product_id = absint( $payload['variation_id'] ?? 0 );
        if ( ! $product_id ) $product_id = absint( $payload['product_id'] ?? 0 );
        $product = wc_get_product( $product_id );
        if ( ! $product instanceof WC_Product ) return new WP_Error( 'alify_ai_product_not_found', 'WooCommerce product/variation not found.', array( 'status' => 404 ) );
        try {
            $item_id = $order->add_product( $product, (float) ( $payload['quantity'] ?? 1 ) );
            if ( ! $item_id ) return new WP_Error( 'alify_ai_order_item_create_failed', 'WooCommerce could not add the product to the order.', array( 'status' => 500 ) );
            $item = $order->get_item( $item_id );
            if ( ! $item instanceof WC_Order_Item_Product ) return new WP_Error( 'alify_ai_order_item_create_failed', 'Created line item could not be reloaded.', array( 'status' => 500 ) );
            if ( array_key_exists( 'subtotal', $payload ) ) $item->set_subtotal( $payload['subtotal'] );
            if ( array_key_exists( 'total', $payload ) ) $item->set_total( $payload['total'] );
            $item->save();
            return $item;
        } catch ( Throwable $e ) {
            return new WP_Error( 'alify_ai_order_item_create_failed', $e->getMessage() ?: 'WooCommerce failed while adding the order item.', array( 'status' => 500 ) );
        }
    }

    private static function apply_line_item_payload( WC_Order_Item_Product $item, array $payload ) {
        try {
            if ( isset( $payload['product_id'] ) || isset( $payload['variation_id'] ) ) {
                $product_id = absint( $payload['variation_id'] ?? 0 );
                if ( ! $product_id ) $product_id = absint( $payload['product_id'] ?? $item->get_product_id() );
                $product = wc_get_product( $product_id );
                if ( ! $product instanceof WC_Product ) return new WP_Error( 'alify_ai_product_not_found', 'WooCommerce product/variation not found.', array( 'status' => 404 ) );
                $item->set_product( $product );
            }
            if ( isset( $payload['quantity'] ) ) $item->set_quantity( $payload['quantity'] );
            if ( array_key_exists( 'subtotal', $payload ) ) $item->set_subtotal( $payload['subtotal'] );
            if ( array_key_exists( 'total', $payload ) ) $item->set_total( $payload['total'] );
        } catch ( Throwable $e ) {
            return new WP_Error( 'alify_ai_invalid_order_item_data', $e->getMessage() ?: 'WooCommerce rejected the order item data.', array( 'status' => 400 ) );
        }
        return true;
    }

    private static function restore_order_fields_snapshot( int $order_id, array $snapshot ) {
        $order = self::safe_get_order( $order_id ); if ( is_wp_error( $order ) ) return $order;
        $fields = array_intersect_key( $snapshot, array_flip( array( 'status', 'customer_id', 'customer_note', 'billing', 'shipping' ) ) );
        $restored = self::apply_order_fields( $order, $fields, false ); if ( is_wp_error( $restored ) ) return $restored;
        try { $order->save(); } catch ( Throwable $e ) { return new WP_Error( 'alify_ai_order_restore_failed', 'Could not restore the order fields.', array( 'status' => 500 ) ); }
        $fresh = self::safe_get_order( $order_id );
        return is_wp_error( $fresh ) ? $fresh : self::serialize_order( $fresh, true );
    }

    private static function snapshot_order_for_line_rollback( WC_Order $order ): array {
        $snapshot = self::serialize_order( $order, true );
        $snapshot['line_items_restore'] = array();
        foreach ( (array) $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) continue;
            $meta = array();
            foreach ( (array) $item->get_meta_data() as $m ) {
                $data = method_exists( $m, 'get_data' ) ? $m->get_data() : array();
                $key = (string) ( $data['key'] ?? '' );
                if ( '' === $key ) continue;
                $value = $data['value'] ?? null;
                if ( is_scalar( $value ) || is_array( $value ) || null === $value ) $meta[] = array( 'key'=>$key, 'value'=>$value );
            }
            $snapshot['line_items_restore'][] = array(
                'name'=>(string)$item->get_name(), 'product_id'=>(int)$item->get_product_id(), 'variation_id'=>(int)$item->get_variation_id(),
                'quantity'=>(float)$item->get_quantity(), 'subtotal'=>(string)$item->get_subtotal(), 'subtotal_tax'=>(string)$item->get_subtotal_tax(),
                'total'=>(string)$item->get_total(), 'total_tax'=>(string)$item->get_total_tax(), 'tax_class'=>(string)$item->get_tax_class(),
                'taxes'=>method_exists($item,'get_taxes')?(array)$item->get_taxes():array(), 'meta'=>$meta,
            );
        }
        return $snapshot;
    }

    private static function restore_line_items_snapshot( int $order_id, array $snapshot ) {
        $order = self::safe_get_order( $order_id ); if ( is_wp_error( $order ) ) return $order;
        try {
            foreach ( (array) $order->get_items( 'line_item' ) as $item_id => $item ) $order->remove_item( $item_id );
            $lines = isset( $snapshot['line_items_restore'] ) ? (array) $snapshot['line_items_restore'] : (array) ( $snapshot['line_items'] ?? array() );
            foreach ( $lines as $line ) {
                $item = new WC_Order_Item_Product();
                if ( method_exists( $item, 'set_name' ) ) $item->set_name( (string) ( $line['name'] ?? '' ) );
                if ( method_exists( $item, 'set_product_id' ) ) $item->set_product_id( absint( $line['product_id'] ?? 0 ) );
                if ( method_exists( $item, 'set_variation_id' ) ) $item->set_variation_id( absint( $line['variation_id'] ?? 0 ) );
                $item->set_quantity( (float) ( $line['quantity'] ?? 1 ) );
                $item->set_subtotal( (string) ( $line['subtotal'] ?? '0' ) );
                $item->set_total( (string) ( $line['total'] ?? '0' ) );
                if ( method_exists( $item, 'set_tax_class' ) ) $item->set_tax_class( (string) ( $line['tax_class'] ?? '' ) );
                if ( method_exists( $item, 'set_taxes' ) && isset( $line['taxes'] ) ) $item->set_taxes( (array) $line['taxes'] );
                foreach ( (array) ( $line['meta'] ?? array() ) as $meta ) {
                    $key = (string) ( $meta['key'] ?? '' ); if ( '' === $key || ! method_exists( $item, 'add_meta_data' ) ) continue;
                    $item->add_meta_data( $key, $meta['value'] ?? null, false );
                }
                $order->add_item( $item );
            }
            // Preserve the historical item tax snapshot; do not recalculate tax rates from current rules.
            $order->calculate_totals( false ); $order->save();
        } catch ( Throwable $e ) {
            return new WP_Error( 'alify_ai_order_restore_failed', 'Could not restore the order line-item snapshot.', array( 'status' => 500 ) );
        }
        $fresh = self::safe_get_order( $order_id );
        return is_wp_error( $fresh ) ? $fresh : self::serialize_order( $fresh, true );
    }

    private static function safe_get_order( int $id ) {
        $ready = self::require_available(); if ( is_wp_error( $ready ) ) return $ready;
        try { $order = wc_get_order( $id ); } catch ( Throwable $e ) { return new WP_Error( 'alify_ai_order_load_failed', 'WooCommerce failed while loading the order.', array( 'status' => 500 ) ); }
        if ( ! $order instanceof WC_Order ) return new WP_Error( 'alify_ai_order_not_found', 'WooCommerce order not found.', array( 'status' => 404 ) );
        return $order;
    }

    private static function safe_line_item( WC_Order $order, int $item_id ) {
        try { $item = $order->get_item( $item_id ); } catch ( Throwable $e ) { $item = false; }
        if ( ! $item instanceof WC_Order_Item_Product ) return new WP_Error( 'alify_ai_order_item_not_found', 'Order product line item not found.', array( 'status' => 404 ) );
        return $item;
    }

    private static function get_note( int $order_id, int $note_id ) {
        if ( ! function_exists( 'wc_get_order_notes' ) ) return new WP_Error( 'alify_ai_order_notes_unavailable', 'WooCommerce order-note API is unavailable.', array( 'status' => 409 ) );
        try { $notes = wc_get_order_notes( array( 'order_id' => $order_id, 'limit' => 100, 'orderby' => 'date_created', 'order' => 'DESC' ) ); } catch ( Throwable $e ) { $notes = array(); }
        foreach ( (array) $notes as $note ) if ( absint( $note->id ?? 0 ) === $note_id ) return self::serialize_note( $note );
        return new WP_Error( 'alify_ai_order_note_not_found', 'Order note not found.', array( 'status' => 404 ) );
    }

    private static function delete_note_direct( int $note_id ) {
        if ( function_exists( 'wc_delete_order_note' ) ) {
            try { $result = wc_delete_order_note( $note_id ); } catch ( Throwable $e ) { return new WP_Error( 'alify_ai_order_note_delete_failed', 'Could not delete order note.', array( 'status' => 500 ) ); }
            if ( is_wp_error( $result ) ) return $result;
            return array( 'deleted_note_id' => $note_id );
        }
        if ( function_exists( 'wp_delete_comment' ) && wp_delete_comment( $note_id, true ) ) return array( 'deleted_note_id' => $note_id );
        return new WP_Error( 'alify_ai_order_note_delete_failed', 'Could not delete order note.', array( 'status' => 500 ) );
    }

    private static function restore_note_snapshot( int $order_id, array $snapshot ) {
        $order = self::safe_get_order( $order_id ); if ( is_wp_error( $order ) ) return $order;
        // Rollback deliberately avoids WC_Order::add_order_note() for customer notes because that
        // fires woocommerce_new_customer_note and can send a second customer email.
        if ( function_exists( 'wp_insert_comment' ) ) {
            $comment = array(
                'comment_post_ID' => $order_id,
                'comment_author' => 'WooCommerce',
                'comment_author_email' => 'woocommerce@noreply.local',
                'comment_author_url' => '',
                'comment_content' => (string) ( $snapshot['note'] ?? '' ),
                'comment_agent' => 'WooCommerce',
                'comment_type' => 'order_note',
                'comment_parent' => 0,
                'comment_approved' => 1,
            );
            $new_id = wp_insert_comment( $comment );
            if ( $new_id && ! empty( $snapshot['customer_note'] ) && function_exists( 'add_comment_meta' ) ) add_comment_meta( $new_id, 'is_customer_note', 1 );
        } else {
            try { $new_id = $order->add_order_note( (string) ( $snapshot['note'] ?? '' ), ! empty( $snapshot['customer_note'] ) ? 1 : 0, true ); } catch ( Throwable $e ) { $new_id = 0; }
        }
        if ( ! $new_id ) return new WP_Error( 'alify_ai_order_note_restore_failed', 'Could not recreate deleted order note.', array( 'status' => 500 ) );
        return array( 'old_note_id' => absint( $snapshot['id'] ?? 0 ), 'new_note_id' => (int) $new_id, 'order_id' => $order_id, 'customer_notification_replayed' => false );
    }

    private static function delete_connector_created_order( WC_Order $order ) {
        $id = (int) $order->get_id();
        try { $order->delete( true ); } catch ( Throwable $e ) { return new WP_Error( 'alify_ai_order_delete_failed', 'Could not remove connector-created order during rollback.', array( 'status' => 500 ) ); }
        return array( 'deleted_order_id' => $id );
    }

    private static function valid_status( string $status ): bool {
        $statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
        return isset( $statuses[ 'wc-' . $status ] ) || isset( $statuses[ $status ] );
    }

    private static function sanitize_query_date( string $date ): string {
        $date = sanitize_text_field( $date );
        $timestamp = strtotime( $date );
        return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : $date;
    }

    private static function serialize_datetime( $dt ): ?string {
        if ( ! $dt || ! is_object( $dt ) || ! method_exists( $dt, 'date' ) ) return null;
        try { return $dt->date( DATE_ATOM ); } catch ( Throwable $e ) { return null; }
    }

    private static function public_item_meta( $item ): array {
        $out = array();
        if ( ! method_exists( $item, 'get_meta_data' ) ) return $out;
        foreach ( (array) $item->get_meta_data() as $meta ) {
            $data = method_exists( $meta, 'get_data' ) ? $meta->get_data() : array();
            $key = (string) ( $data['key'] ?? '' );
            if ( '' === $key || str_starts_with( $key, '_' ) ) continue;
            $value = $data['value'] ?? null;
            if ( is_scalar( $value ) || null === $value ) $out[] = array( 'key' => $key, 'value' => $value );
        }
        return $out;
    }

    private static function proposal( string $operation, int $object_id, array $request, array $preview ) {
        $request = array_merge( array( 'operation' => $operation ), $request );
        $preview['operation'] = $operation;
        $preview['safety'] = self::safety_boundary();
        return ALIFY_AI_Approvals::create( 'woocommerce_order', $object_id, $request, $preview );
    }

    private static function safety_boundary(): array {
        return array(
            'mutations_require_approval' => true,
            'payments_disabled' => true,
            'refunds_disabled' => true,
            'payment_tokens_disabled' => true,
            'gateway_actions_disabled' => true,
            'permanent_existing_order_delete_disabled' => true,
            'status_changes_may_trigger_external_side_effects' => true,
        );
    }

    private static function hash_snapshot( array $snapshot ): string {
        return hash( 'sha256', wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '' );
    }

    private static function require_available() {
        return self::available() ? true : new WP_Error( 'alify_ai_woocommerce_orders_unavailable', 'WooCommerce order CRUD APIs are unavailable.', array( 'status' => 409 ) );
    }

    private static function audit_or_revert( string $action, int $order_id, array $before, array $after, callable $revert, ?array $response_item = null ) {
        $snapshot = ALIFY_AI_Audit::validate_snapshot( $before, $after );
        if ( is_wp_error( $snapshot ) ) {
            $reverted = self::run_compensation( $revert, $order_id, $action . '_snapshot_revert' );
            if ( is_wp_error( $reverted ) ) {
                return new WP_Error( 'alify_ai_woocommerce_order_unknown_state', 'Order snapshot validation failed and compensation could not be verified; order state requires review.', array( 'status' => 500, 'cause' => $snapshot->get_error_code(), 'compensation_error' => $reverted->get_error_message() ) );
            }
            return $snapshot;
        }
        $log = ALIFY_AI_Audit::log( $action, 'shop_order', $order_id, $before, $after );
        if ( ! $log ) {
            $reverted = self::run_compensation( $revert, $order_id, $action . '_audit_revert' );
            if ( is_wp_error( $reverted ) ) {
                return new WP_Error( 'alify_ai_woocommerce_order_unknown_state', 'Order audit logging failed and compensation could not be verified; order state requires review.', array( 'status' => 500, 'compensation_error' => $reverted->get_error_message() ) );
            }
            return new WP_Error( 'alify_ai_audit_failed', 'WooCommerce order change was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'message' => 'WooCommerce order change applied.', 'activity_id' => $log, 'item' => $response_item ?? $after );
    }

    private static function run_compensation( callable $revert, int $order_id, string $context ) {
        try {
            $result = $revert();
        } catch ( Throwable $e ) {
            ALIFY_AI_Diagnostics::log_throwable( $e, 'woocommerce_order_revert', array( 'order_id' => $order_id, 'context' => $context ) );
            return new WP_Error( 'alify_ai_order_compensation_exception', $e->getMessage() ?: 'Order compensation threw an exception.', array( 'status' => 500 ) );
        }
        if ( is_wp_error( $result ) ) {
            ALIFY_AI_Diagnostics::log( 'woocommerce_order_revert', array( 'order_id' => $order_id, 'context' => $context, 'error' => $result->get_error_message() ) );
            return $result;
        }
        if ( false === $result ) {
            ALIFY_AI_Diagnostics::log( 'woocommerce_order_revert', array( 'order_id' => $order_id, 'context' => $context, 'error' => 'Compensation returned false.' ) );
            return new WP_Error( 'alify_ai_order_compensation_failed', 'Order compensation returned false.', array( 'status' => 500 ) );
        }
        return true;
    }
}
