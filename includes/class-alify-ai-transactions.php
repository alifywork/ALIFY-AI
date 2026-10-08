<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ALIFY_AI_Transactions {
    private const MAX_OPERATIONS = 20;

    public static function required_scopes( array $operations ): array {
        $scopes = array();
        foreach ( $operations as $operation ) {
            if ( ! is_array( $operation ) ) { continue; }
            $action = (string) ( $operation['action'] ?? '' );
            if ( 'content.update' === $action ) { $scopes[] = 'content'; }
            elseif ( 'media.update' === $action ) { $scopes[] = 'media'; }
            elseif ( 'acf.update' === $action ) { $scopes[] = 'acf'; }
            elseif ( 'woocommerce.product.update' === $action ) { $scopes[] = 'commerce'; }
        }
        return array_values( array_unique( $scopes ) );
    }

    public static function preview( array $input ) {
        $operations = $input['operations'] ?? null;
        if ( ! is_array( $operations ) || empty( $operations ) ) {
            return new WP_Error( 'alify_ai_transaction_operations_required', 'Transaction operations are required.', array( 'status' => 400 ) );
        }
        if ( count( $operations ) > self::MAX_OPERATIONS ) {
            return new WP_Error( 'alify_ai_transaction_too_large', 'A transaction may contain at most 20 operations.', array( 'status' => 400 ) );
        }

        $normalized = array();
        $previews   = array();
        foreach ( array_values( $operations ) as $index => $operation ) {
            if ( ! is_array( $operation ) ) {
                return new WP_Error( 'alify_ai_invalid_transaction_operation', 'Every transaction operation must be an object.', array( 'status' => 400, 'operation_index' => $index ) );
            }
            $prepared = self::prepare_operation( $operation );
            if ( is_wp_error( $prepared ) ) {
                $prepared->add_data( array_merge( (array) $prepared->get_error_data(), array( 'operation_index' => $index ) ) );
                return $prepared;
            }
            $normalized[] = $prepared['operation'];
            $previews[]   = $prepared['preview'];
        }

        return ALIFY_AI_Approvals::create(
            'transaction',
            0,
            array( 'operations' => $normalized ),
            array(
                'atomic'          => true,
                'operation_count' => count( $normalized ),
                'operations'      => $previews,
                'failure_policy'  => 'rollback_applied_operations_in_reverse_order',
            )
        );
    }

    public static function apply_approval( array $approval ) {
        $operations = (array) ( $approval['request']['operations'] ?? array() );
        $previews   = (array) ( $approval['preview']['operations'] ?? array() );
        if ( empty( $operations ) || count( $operations ) !== count( $previews ) ) {
            return new WP_Error( 'alify_ai_invalid_transaction', 'Transaction proposal is incomplete.', array( 'status' => 400 ) );
        }

        // Validate every snapshot before mutating anything.
        foreach ( $operations as $index => $operation ) {
            $fresh = self::prepare_operation( $operation );
            if ( is_wp_error( $fresh ) ) {
                return $fresh;
            }
            $expected = (string) ( $previews[ $index ]['source_hash'] ?? '' );
            $current  = (string) ( $fresh['preview']['source_hash'] ?? '' );
            if ( '' === $expected || '' === $current || ! hash_equals( $expected, $current ) ) {
                return new WP_Error( 'alify_ai_stale_transaction', 'One or more transaction targets changed after preview. Create a fresh transaction proposal.', array( 'status' => 409, 'operation_index' => $index ) );
            }
        }

        $activity_ids = array();
        $results      = array();
        foreach ( $operations as $index => $operation ) {
            $result = self::execute_operation( $operation );
            if ( is_wp_error( $result ) ) {
                $rollback_results = array();
                foreach ( array_reverse( $activity_ids ) as $activity_id ) {
                    $rollback = self::rollback_activity( $activity_id );
                    $rollback_results[] = is_wp_error( $rollback ) ? array( 'activity_id' => $activity_id, 'error' => $rollback->get_error_message() ) : array( 'activity_id' => $activity_id, 'rolled_back' => true );
                }
                $rollback_incomplete = (bool) array_filter( $rollback_results, static fn( $item ) => isset( $item['error'] ) );
                if ( $rollback_incomplete ) {
                    ALIFY_AI_Diagnostics::log( 'transaction_rollback_incomplete', array( 'operation_index' => $index, 'rollback_results' => $rollback_results ) );
                }
                return new WP_Error(
                    $rollback_incomplete ? 'alify_ai_transaction_failed_unknown_state' : 'alify_ai_transaction_failed',
                    ( $rollback_incomplete ? 'Transaction failed and one or more compensating rollbacks also failed; site state requires review: ' : 'Transaction failed and already-applied operations were rolled back: ' ) . $result->get_error_message(),
                    array( 'status' => $rollback_incomplete ? 500 : 409, 'operation_index' => $index, 'rollback_incomplete' => $rollback_incomplete, 'rollback_results' => $rollback_results )
                );
            }
            $activity_id = absint( $result['activity_id'] ?? 0 );
            if ( $activity_id ) {
                $activity_ids[] = $activity_id;
            }
            $results[] = $result;
        }

        $transaction_activity = ALIFY_AI_Audit::log(
            'transaction',
            'transaction',
            0,
            null,
            array( 'activity_ids' => $activity_ids, 'operation_count' => count( $operations ) )
        );
        if ( ! $transaction_activity ) {
            $rollback_results = array();
            foreach ( array_reverse( $activity_ids ) as $activity_id ) {
                $rollback = self::rollback_activity( $activity_id );
                $rollback_results[] = is_wp_error( $rollback )
                    ? array( 'activity_id' => $activity_id, 'error' => $rollback->get_error_message() )
                    : array( 'activity_id' => $activity_id, 'rolled_back' => true );
            }
            $rollback_incomplete = (bool) array_filter( $rollback_results, static fn( $item ) => isset( $item['error'] ) );
            if ( $rollback_incomplete ) {
                ALIFY_AI_Diagnostics::log( 'transaction_audit_rollback_incomplete', array( 'rollback_results' => $rollback_results ) );
            }
            return new WP_Error(
                $rollback_incomplete ? 'alify_ai_transaction_audit_failed_unknown_state' : 'alify_ai_transaction_audit_failed',
                $rollback_incomplete ? 'The transaction-level activity log failed and one or more compensating rollbacks also failed; site state requires review.' : 'Transaction was reverted because its transaction-level activity log could not be stored.',
                array( 'status' => 500, 'rollback_incomplete' => $rollback_incomplete, 'rollback_results' => $rollback_results )
            );
        }

        return array(
            'message'     => 'Transaction applied atomically.',
            'activity_id' => $transaction_activity,
            'activities'  => $activity_ids,
            'results'     => $results,
        );
    }

    public static function rollback_transaction( array $entry ) {
        $activity_ids = array_map( 'absint', (array) ( $entry['after_json']['activity_ids'] ?? array() ) );
        if ( empty( $activity_ids ) ) {
            return new WP_Error( 'alify_ai_transaction_empty', 'Transaction activity does not contain rollback targets.', array( 'status' => 400 ) );
        }
        $results = array();
        foreach ( array_reverse( $activity_ids ) as $activity_id ) {
            $result = self::rollback_activity( $activity_id );
            if ( is_wp_error( $result ) ) {
                $partial = ! empty( $results );
                if ( $partial ) {
                    ALIFY_AI_Diagnostics::log( 'transaction_manual_rollback_partial', array( 'failed_activity_id' => $activity_id, 'restored' => $results, 'error' => $result->get_error_message() ) );
                }
                return new WP_Error(
                    $partial ? 'alify_ai_transaction_rollback_unknown_state' : 'alify_ai_transaction_rollback_failed',
                    ( $partial ? 'Transaction rollback partially completed before an operation failed; site state requires review: ' : 'Transaction rollback could not start because an operation could not be restored: ' ) . $result->get_error_message(),
                    array( 'status' => $partial ? 500 : 409, 'activity_id' => $activity_id, 'restored' => $results, 'partial' => $partial )
                );
            }
            $results[] = array( 'activity_id' => $activity_id, 'result' => $result );
        }
        $log_id = ALIFY_AI_Audit::log( 'rollback_transaction', 'transaction', 0, $entry['after_json'], array( 'rolled_back_activity_ids' => array_reverse( $activity_ids ) ) );
        if ( ! $log_id ) {
            return new WP_Error( 'alify_ai_audit_failed', 'Transaction targets were rolled back but the rollback log could not be stored.', array( 'status' => 500, 'results' => $results ) );
        }
        return array( 'message' => 'Transaction rolled back.', 'activity_id' => $log_id, 'results' => $results );
    }

    public static function rollback_activity( int $activity_id ) {
        $entry = ALIFY_AI_Audit::get( $activity_id );
        if ( ! $entry ) {
            return new WP_Error( 'alify_ai_activity_not_found', 'Activity entry not found.', array( 'status' => 404 ) );
        }

        if ( 'update' === $entry['action'] && is_array( $entry['before_json'] ) ) {
            return self::restore_content_snapshot( $entry['object_id'], $entry['object_type'], $entry['before_json'] );
        }
        if ( 'update_media' === $entry['action'] && is_array( $entry['before_json'] ) ) {
            return ALIFY_AI_Media::update_media( $entry['object_id'], $entry['before_json'] );
        }
        if ( in_array( $entry['action'], array( 'update_acf', 'update_acf_options' ), true ) && is_array( $entry['before_json'] ) ) {
            return ALIFY_AI_ACF::rollback_activity( $entry );
        }
        if ( 'update_product' === $entry['action'] && is_array( $entry['before_json'] ) ) {
            $current = ALIFY_AI_WooCommerce::get_product( $entry['object_id'] );
            if ( is_wp_error( $current ) ) { return $current; }
            $restored = ALIFY_AI_WooCommerce::restore_snapshot( $entry['object_id'], $entry['before_json'] );
            if ( is_wp_error( $restored ) ) { return $restored; }
            $log_id = ALIFY_AI_Audit::log( 'rollback_product', 'product', $entry['object_id'], $current, $restored );
            return array( 'activity_id' => $log_id, 'item' => $restored );
        }
        if ( 'create_product' === $entry['action'] ) {
            $result = ALIFY_AI_WooCommerce::trash_created_product( $entry['object_id'] );
            if ( is_wp_error( $result ) ) { return $result; }
            $log_id = ALIFY_AI_Audit::log( 'rollback_create_product', 'product', $entry['object_id'], $entry['after_json'], $result );
            return array( 'activity_id' => $log_id, 'item' => $result );
        }
        return new WP_Error( 'alify_ai_transaction_rollback_unsupported', 'This activity type is not supported by transaction rollback.', array( 'status' => 400 ) );
    }

    private static function prepare_operation( array $operation ) {
        $action = sanitize_key( str_replace( '.', '_', (string) ( $operation['action'] ?? '' ) ) );
        $data   = is_array( $operation['data'] ?? null ) ? $operation['data'] : array();

        if ( 'content_update' === $action ) {
            $id   = absint( $operation['id'] ?? 0 );
            $type = sanitize_key( (string) ( $operation['type'] ?? '' ) );
            $snapshot = self::content_snapshot( $id, $type );
            if ( is_wp_error( $snapshot ) ) { return $snapshot; }
            $clean = self::sanitize_content_data( $data, $type );
            if ( is_wp_error( $clean ) ) { return $clean; }
            if ( empty( $clean ) ) { return new WP_Error( 'alify_ai_no_changes', 'Content update has no supported fields.', array( 'status' => 400 ) ); }
            return array(
                'operation' => array( 'action' => 'content.update', 'id' => $id, 'type' => $type, 'data' => $clean ),
                'preview'   => array( 'action' => 'content.update', 'id' => $id, 'type' => $type, 'changes' => array_keys( $clean ), 'source_hash' => self::hash_snapshot( $snapshot ) ),
            );
        }

        if ( 'media_update' === $action ) {
            $id = absint( $operation['id'] ?? 0 );
            $snapshot = ALIFY_AI_Media::get_media( $id );
            if ( is_wp_error( $snapshot ) ) { return $snapshot; }
            $allowed = array_intersect_key( $data, array_flip( array( 'title', 'caption', 'description', 'alt_text' ) ) );
            if ( empty( $allowed ) ) { return new WP_Error( 'alify_ai_no_changes', 'Media update has no supported fields.', array( 'status' => 400 ) ); }
            return array(
                'operation' => array( 'action' => 'media.update', 'id' => $id, 'data' => $allowed ),
                'preview'   => array( 'action' => 'media.update', 'id' => $id, 'changes' => array_keys( $allowed ), 'source_hash' => self::hash_snapshot( $snapshot ) ),
            );
        }

        if ( 'acf_update' === $action ) {
            $post_id = absint( $operation['post_id'] ?? 0 );
            if ( ! ALIFY_AI_ACF::available() ) { return new WP_Error( 'alify_ai_acf_unavailable', 'Advanced Custom Fields is not active.', array( 'status' => 409 ) ); }
            if ( ! get_post( $post_id ) ) { return new WP_Error( 'alify_ai_not_found', 'Post not found.', array( 'status' => 404 ) ); }
            $values = is_array( $data['values'] ?? null ) ? $data['values'] : $data;
            if ( empty( $values ) ) { return new WP_Error( 'alify_ai_no_changes', 'ACF update has no values.', array( 'status' => 400 ) ); }
            $snapshot = ALIFY_AI_ACF::get_values( $post_id );
            return array(
                'operation' => array( 'action' => 'acf.update', 'post_id' => $post_id, 'data' => array( 'values' => $values ) ),
                'preview'   => array( 'action' => 'acf.update', 'post_id' => $post_id, 'fields' => array_keys( $values ), 'source_hash' => self::hash_snapshot( $snapshot ) ),
            );
        }

        if ( 'woocommerce_product_update' === $action ) {
            $id = absint( $operation['id'] ?? 0 );
            $snapshot = ALIFY_AI_WooCommerce::get_product( $id );
            if ( is_wp_error( $snapshot ) ) { return $snapshot; }
            $clean = ALIFY_AI_WooCommerce::normalize_payload( $data, false );
            if ( is_wp_error( $clean ) ) { return $clean; }
            if ( empty( $clean ) ) { return new WP_Error( 'alify_ai_no_changes', 'WooCommerce product update has no supported fields.', array( 'status' => 400 ) ); }
            return array(
                'operation' => array( 'action' => 'woocommerce.product.update', 'id' => $id, 'data' => $clean ),
                'preview'   => array( 'action' => 'woocommerce.product.update', 'id' => $id, 'changes' => array_keys( $clean ), 'source_hash' => self::hash_snapshot( $snapshot ) ),
            );
        }

        return new WP_Error( 'alify_ai_transaction_action_unsupported', 'Unsupported transaction action. Supported actions: content.update, media.update, acf.update, woocommerce.product.update.', array( 'status' => 400 ) );
    }

    private static function execute_operation( array $operation ) {
        $action = (string) ( $operation['action'] ?? '' );
        if ( 'content.update' === $action ) {
            return self::update_content( absint( $operation['id'] ), sanitize_key( (string) $operation['type'] ), (array) $operation['data'] );
        }
        if ( 'media.update' === $action ) {
            return ALIFY_AI_Media::update_media( absint( $operation['id'] ), (array) $operation['data'] );
        }
        if ( 'acf.update' === $action ) {
            return ALIFY_AI_ACF::update_values( absint( $operation['post_id'] ), (array) ( $operation['data']['values'] ?? array() ) );
        }
        if ( 'woocommerce.product.update' === $action ) {
            return ALIFY_AI_WooCommerce::update_direct( absint( $operation['id'] ), (array) $operation['data'] );
        }
        return new WP_Error( 'alify_ai_transaction_action_unsupported', 'Unsupported transaction action.', array( 'status' => 400 ) );
    }

    private static function content_snapshot( int $id, string $type ) {
        $post = get_post( $id );
        $blocked = array( 'attachment', 'revision', 'nav_menu_item', 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_order_placehold' );
        if ( ! $post || $post->post_type !== $type || in_array( $type, $blocked, true ) || str_starts_with( $type, 'shop_order' ) ) {
            return new WP_Error( 'alify_ai_not_found', 'Content transaction target not found or requires a dedicated connector integration.', array( 'status' => 404 ) );
        }
        $object = get_post_type_object( $type );
        if ( ! $object || ! $object->show_ui ) {
            return new WP_Error( 'alify_ai_post_type_forbidden', 'This post type is not exposed for transaction content operations.', array( 'status' => 403 ) );
        }
        $terms = array();
        foreach ( get_object_taxonomies( $type ) as $taxonomy ) {
            $ids = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $ids ) ) { $terms[ $taxonomy ] = array_map( 'intval', $ids ); }
        }
        return array(
            'id' => $id, 'type' => $type, 'title' => $post->post_title, 'content' => $post->post_content,
            'excerpt' => $post->post_excerpt, 'status' => $post->post_status, 'slug' => $post->post_name,
            'parent' => (int) $post->post_parent, 'menu_order' => (int) $post->menu_order,
            'featured_media' => (int) get_post_thumbnail_id( $id ), 'terms' => $terms,
        );
    }

    private static function sanitize_content_data( array $data, string $type = '' ) {
        $out = array();
        if ( array_key_exists( 'title', $data ) ) { $out['title'] = sanitize_text_field( (string) $data['title'] ); }
        if ( array_key_exists( 'content', $data ) ) { $out['content'] = wp_kses_post( (string) $data['content'] ); }
        if ( array_key_exists( 'excerpt', $data ) ) { $out['excerpt'] = wp_kses_post( (string) $data['excerpt'] ); }
        if ( array_key_exists( 'slug', $data ) ) { $out['slug'] = sanitize_title( (string) $data['slug'] ); }
        if ( array_key_exists( 'status', $data ) ) {
            $status = sanitize_key( (string) $data['status'] );
            if ( ! in_array( $status, array( 'draft', 'pending', 'publish', 'private', 'future' ), true ) ) {
                return new WP_Error( 'alify_ai_invalid_status', 'Unsupported post status.', array( 'status' => 400 ) );
            }
            $out['status'] = $status;
        }
        if ( array_key_exists( 'parent', $data ) ) {
            $parent = absint( $data['parent'] );
            if ( $parent && ( ! get_post( $parent ) || ( $type && get_post_type( $parent ) !== $type ) ) ) {
                return new WP_Error( 'alify_ai_invalid_parent', 'Parent must reference an existing item of the same post type.', array( 'status' => 400 ) );
            }
            $out['parent'] = $parent;
        }
        if ( array_key_exists( 'menu_order', $data ) ) { $out['menu_order'] = (int) $data['menu_order']; }
        if ( array_key_exists( 'featured_media', $data ) ) {
            $attachment_id = absint( $data['featured_media'] );
            if ( $attachment_id ) {
                $attachment = get_post( $attachment_id );
                if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $attachment_id ) ) {
                    return new WP_Error( 'alify_ai_invalid_featured_media', 'featured_media must reference an existing image attachment.', array( 'status' => 400 ) );
                }
            }
            $out['featured_media'] = $attachment_id;
        }
        if ( array_key_exists( 'terms', $data ) ) {
            if ( ! is_array( $data['terms'] ) ) {
                return new WP_Error( 'alify_ai_invalid_terms', 'terms must be an object keyed by taxonomy.', array( 'status' => 400 ) );
            }
            $out['terms'] = array();
            foreach ( $data['terms'] as $taxonomy => $ids ) {
                $taxonomy = sanitize_key( (string) $taxonomy );
                if ( ! taxonomy_exists( $taxonomy ) || ( $type && ! is_object_in_taxonomy( $type, $taxonomy ) ) ) {
                    return new WP_Error( 'alify_ai_invalid_taxonomy', 'A supplied taxonomy is not attached to this post type.', array( 'status' => 400, 'taxonomy' => $taxonomy ) );
                }
                if ( ! is_array( $ids ) ) {
                    return new WP_Error( 'alify_ai_invalid_terms', 'Each taxonomy value must be an array of existing term IDs.', array( 'status' => 400, 'taxonomy' => $taxonomy ) );
                }
                $clean_ids = array();
                foreach ( $ids as $term_id ) {
                    $term_id = absint( $term_id );
                    if ( $term_id && ! term_exists( $term_id, $taxonomy ) ) {
                        return new WP_Error( 'alify_ai_term_not_found', 'A supplied taxonomy term does not exist.', array( 'status' => 400, 'taxonomy' => $taxonomy, 'term_id' => $term_id ) );
                    }
                    if ( $term_id ) { $clean_ids[] = $term_id; }
                }
                $out['terms'][ $taxonomy ] = array_values( array_unique( $clean_ids ) );
            }
        }
        return $out;
    }

    private static function update_content( int $id, string $type, array $data ) {
        $before = self::content_snapshot( $id, $type );
        if ( is_wp_error( $before ) ) { return $before; }

        $clean = self::sanitize_content_data( $data, $type );
        if ( is_wp_error( $clean ) ) { return $clean; }
        $data = $clean;

        $payload = array( 'ID' => $id );
        $map = array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status', 'slug' => 'post_name', 'parent' => 'post_parent', 'menu_order' => 'menu_order' );
        foreach ( $map as $key => $wp_key ) {
            if ( array_key_exists( $key, $data ) ) { $payload[ $wp_key ] = $data[ $key ]; }
        }
        if ( count( $payload ) > 1 ) {
            $updated = wp_update_post( $payload, true );
            if ( is_wp_error( $updated ) ) { return $updated; }
        }
        if ( array_key_exists( 'featured_media', $data ) ) {
            $ok = true;
            if ( $data['featured_media'] ) {
                if ( (int) get_post_thumbnail_id( $id ) !== (int) $data['featured_media'] ) {
                    $ok = set_post_thumbnail( $id, $data['featured_media'] );
                }
            } else {
                delete_post_thumbnail( $id );
            }
            if ( $data['featured_media'] && ! $ok ) {
                self::apply_content_snapshot_raw( $id, $type, $before );
                return new WP_Error( 'alify_ai_featured_media_failed', 'Could not set the featured image.', array( 'status' => 500 ) );
            }
        }
        foreach ( (array) ( $data['terms'] ?? array() ) as $taxonomy => $ids ) {
            $term_result = wp_set_object_terms( $id, $ids, $taxonomy, false );
            if ( is_wp_error( $term_result ) ) {
                self::apply_content_snapshot_raw( $id, $type, $before );
                return $term_result;
            }
        }
        clean_post_cache( $id );
        $after = self::content_snapshot( $id, $type );
        if ( is_wp_error( $after ) ) {
            self::apply_content_snapshot_raw( $id, $type, $before );
            return $after;
        }
        $before_log = self::transaction_snapshot_to_rest_shape( $before );
        $after_log  = self::transaction_snapshot_to_rest_shape( $after );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before_log, $after_log );
        if ( is_wp_error( $snapshot_ok ) ) {
            self::apply_content_snapshot_raw( $id, $type, $before );
            return $snapshot_ok;
        }
        $activity_id = ALIFY_AI_Audit::log( 'update', $type, $id, $before_log, $after_log );
        if ( ! $activity_id ) {
            self::apply_content_snapshot_raw( $id, $type, $before );
            return new WP_Error( 'alify_ai_audit_failed', 'Content update was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'activity_id' => $activity_id, 'item' => $after );
    }

    private static function restore_content_snapshot( int $id, string $type, array $snapshot ) {
        $current = self::content_snapshot( $id, $type );
        if ( is_wp_error( $current ) ) { return $current; }
        $restored = self::apply_content_snapshot_raw( $id, $type, $snapshot );
        if ( is_wp_error( $restored ) ) { return $restored; }
        $after = self::content_snapshot( $id, $type );
        if ( is_wp_error( $after ) ) { return $after; }
        $log_id = ALIFY_AI_Audit::log( 'rollback_content', $type, $id, self::transaction_snapshot_to_rest_shape( $current ), self::transaction_snapshot_to_rest_shape( $after ) );
        if ( ! $log_id ) {
            return new WP_Error( 'alify_ai_audit_failed', 'Content was restored but the rollback activity could not be logged.', array( 'status' => 500 ) );
        }
        return array( 'activity_id' => $log_id, 'item' => $after );
    }

    private static function apply_content_snapshot_raw( int $id, string $type, array $snapshot ) {
        $content = is_array( $snapshot['content'] ?? null ) ? (string) ( $snapshot['content']['raw'] ?? '' ) : (string) ( $snapshot['content'] ?? '' );
        $excerpt = is_array( $snapshot['excerpt'] ?? null ) ? (string) ( $snapshot['excerpt']['raw'] ?? '' ) : (string) ( $snapshot['excerpt'] ?? '' );
        $terms = (array) ( $snapshot['terms'] ?? array() );
        $normalized_terms = array();
        foreach ( $terms as $taxonomy => $items ) {
            if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $type, $taxonomy ) ) { continue; }
            $normalized_terms[ $taxonomy ] = array();
            foreach ( (array) $items as $item ) {
                $term_id = is_array( $item ) ? (int) ( $item['id'] ?? 0 ) : (int) $item;
                if ( $term_id ) { $normalized_terms[ $taxonomy ][] = $term_id; }
            }
        }
        $result = wp_update_post(
            array(
                'ID' => $id,
                'post_title' => (string) ( $snapshot['title'] ?? '' ),
                'post_content' => $content,
                'post_excerpt' => $excerpt,
                'post_status' => (string) ( $snapshot['status'] ?? 'draft' ),
                'post_name' => (string) ( $snapshot['slug'] ?? '' ),
                'post_parent' => (int) ( $snapshot['parent'] ?? 0 ),
                'menu_order' => (int) ( $snapshot['menu_order'] ?? 0 ),
            ),
            true
        );
        if ( is_wp_error( $result ) ) { return $result; }
        $featured = (int) ( $snapshot['featured_media'] ?? 0 );
        $featured ? set_post_thumbnail( $id, $featured ) : delete_post_thumbnail( $id );
        foreach ( $normalized_terms as $taxonomy => $ids ) {
            $term_result = wp_set_object_terms( $id, $ids, $taxonomy, false );
            if ( is_wp_error( $term_result ) ) { return $term_result; }
        }
        clean_post_cache( $id );
        return true;
    }

    private static function transaction_snapshot_to_rest_shape( array $snapshot ): array {
        $terms = array();
        foreach ( (array) ( $snapshot['terms'] ?? array() ) as $taxonomy => $ids ) {
            $terms[ $taxonomy ] = array_map( static fn( $id ) => array( 'id' => (int) $id ), (array) $ids );
        }
        return array(
            'id' => (int) ( $snapshot['id'] ?? 0 ), 'type' => (string) ( $snapshot['type'] ?? '' ),
            'title' => (string) ( $snapshot['title'] ?? '' ), 'content' => array( 'raw' => (string) ( $snapshot['content'] ?? '' ) ),
            'excerpt' => array( 'raw' => (string) ( $snapshot['excerpt'] ?? '' ) ), 'status' => (string) ( $snapshot['status'] ?? '' ),
            'slug' => (string) ( $snapshot['slug'] ?? '' ), 'parent' => (int) ( $snapshot['parent'] ?? 0 ),
            'menu_order' => (int) ( $snapshot['menu_order'] ?? 0 ), 'featured_media' => (int) ( $snapshot['featured_media'] ?? 0 ),
            'terms' => $terms,
        );
    }

    private static function hash_snapshot( $snapshot ): string {
        return hash( 'sha256', wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }
}
