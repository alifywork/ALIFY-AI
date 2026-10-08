<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ALIFY_AI_Gutenberg {
    public static function document( int $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'alify_ai_not_found', 'Content not found.', array( 'status' => 404 ) );
        }

        $blocks = parse_blocks( (string) $post->post_content );
        return array(
            'post_id'      => (int) $post->ID,
            'post_type'    => $post->post_type,
            'title'        => get_the_title( $post ),
            'editor'       => 'gutenberg',
            'block_count'  => self::count_blocks( $blocks ),
            'content_hash' => self::hash_content( (string) $post->post_content ),
            'blocks'       => self::summarize_blocks( $blocks ),
            'validation'   => self::validate_blocks( $blocks ),
            'raw_content'  => (string) $post->post_content,
        );
    }

    public static function preview( int $post_id, array $payload ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'alify_ai_not_found', 'Content not found.', array( 'status' => 404 ) );
        }

        $operations = isset( $payload['operations'] ) && is_array( $payload['operations'] ) ? array_values( $payload['operations'] ) : array();
        if ( empty( $operations ) ) {
            return new WP_Error( 'alify_ai_operations_required', 'At least one Gutenberg operation is required.', array( 'status' => 400 ) );
        }
        if ( count( $operations ) > 50 ) {
            return new WP_Error( 'alify_ai_too_many_operations', 'A proposal can contain at most 50 operations.', array( 'status' => 400 ) );
        }
        if ( self::contains_unsafe_payload( $operations ) ) {
            return new WP_Error( 'alify_ai_unsafe_gutenberg_payload', 'Script tags, embedded executable markup, inline event handlers and javascript: URLs are not accepted by Gutenberg design operations.', array( 'status' => 400 ) );
        }

        $before_raw = (string) $post->post_content;
        $blocks = parse_blocks( $before_raw );
        $result = self::apply_operations_to_blocks( $blocks, $operations );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $after_raw = serialize_blocks( $result['blocks'] );
        if ( $after_raw === $before_raw ) {
            return new WP_Error( 'alify_ai_no_changes', 'The supplied operations do not change the Gutenberg document.', array( 'status' => 400 ) );
        }

        $request = array(
            'base_hash'  => self::hash_content( $before_raw ),
            'operations' => $operations,
        );
        $preview = array(
            'post_id'       => $post_id,
            'post_type'     => $post->post_type,
            'title'         => get_the_title( $post ),
            'operation_count' => count( $operations ),
            'changes'       => $result['changes'],
            'before_hash'   => self::hash_content( $before_raw ),
            'after_hash'    => self::hash_content( $after_raw ),
            'before_blocks' => self::count_blocks( $blocks ),
            'after_blocks'  => self::count_blocks( $result['blocks'] ),
            'after_preview' => self::summarize_blocks( $result['blocks'] ),
            'validation'    => $result['validation'] ?? self::validate_blocks( $result['blocks'] ),
        );

        return ALIFY_AI_Approvals::create( 'gutenberg', $post_id, $request, $preview );
    }

    public static function apply_approval( array $approval ) {
        $request = is_array( $approval['request'] ?? null ) ? $approval['request'] : array();
        $resource = sanitize_key( (string) ( $request['resource'] ?? 'document' ) );
        if ( 'pattern' === $resource ) {
            return self::apply_pattern_approval( $approval, $request );
        }
        if ( 'template' === $resource ) {
            return self::apply_template_approval( $approval, $request );
        }

        $post_id = absint( $approval['object_id'] ?? 0 );
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'alify_ai_not_found', 'Content no longer exists.', array( 'status' => 404 ) );
        }
        $operations = isset( $request['operations'] ) && is_array( $request['operations'] ) ? $request['operations'] : array();
        $before_raw = (string) $post->post_content;
        $current_hash = self::hash_content( $before_raw );
        if ( ! hash_equals( (string) ( $request['base_hash'] ?? '' ), $current_hash ) ) {
            return new WP_Error(
                'alify_ai_stale_proposal',
                'The Gutenberg document changed after this preview was created. Create a fresh proposal before applying.',
                array( 'status' => 409, 'current_hash' => $current_hash )
            );
        }

        $blocks = parse_blocks( $before_raw );
        $result = self::apply_operations_to_blocks( $blocks, $operations );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $after_raw = serialize_blocks( $result['blocks'] );

        $before_snapshot = array( 'raw_content' => $before_raw, 'hash' => self::hash_content( $before_raw ) );
        $after_snapshot  = array( 'raw_content' => $after_raw, 'hash' => self::hash_content( $after_raw ), 'changes' => $result['changes'] );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before_snapshot, $after_snapshot );
        if ( is_wp_error( $snapshot_ok ) ) {
            return $snapshot_ok;
        }

        $updated = wp_update_post(
            array(
                'ID'           => $post_id,
                'post_content' => $after_raw,
            ),
            true
        );
        if ( is_wp_error( $updated ) ) {
            return $updated;
        }

        clean_post_cache( $post_id );
        $activity_id = ALIFY_AI_Audit::log( 'update_gutenberg', 'gutenberg', $post_id, $before_snapshot, $after_snapshot );
        if ( ! $activity_id ) {
            $reverted = wp_update_post( array( 'ID' => $post_id, 'post_content' => $before_raw ), true );
            clean_post_cache( $post_id );
            $verified = ! is_wp_error( $reverted ) && hash_equals( self::hash_content( $before_raw ), self::hash_content( (string) get_post_field( 'post_content', $post_id ) ) );
            if ( ! $verified ) {
                ALIFY_AI_Diagnostics::log( 'gutenberg_compensation_failed', array( 'post_id' => $post_id, 'stage' => 'apply_audit_failure' ) );
                return new WP_Error( 'alify_ai_gutenberg_unknown_state', 'Gutenberg activity logging failed and the original content could not be verified as restored; site state requires review.', array( 'status' => 500 ) );
            }
            return new WP_Error( 'alify_ai_audit_failed', 'Gutenberg change was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }

        return array(
            'message'     => 'Gutenberg proposal applied.',
            'activity_id' => $activity_id,
            'document'    => self::document( $post_id ),
        );
    }

    public static function rollback( int $post_id, array $before ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'alify_ai_not_found', 'Content no longer exists.', array( 'status' => 404 ) );
        }
        if ( ! array_key_exists( 'raw_content', $before ) ) {
            return new WP_Error( 'alify_ai_invalid_snapshot', 'Gutenberg rollback snapshot is incomplete.', array( 'status' => 400 ) );
        }

        $current = (string) $post->post_content;
        $target  = (string) $before['raw_content'];
        $current_snapshot = array( 'raw_content' => $current, 'hash' => self::hash_content( $current ) );
        $target_snapshot  = array( 'raw_content' => $target, 'hash' => self::hash_content( $target ) );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $current_snapshot, $target_snapshot );
        if ( is_wp_error( $snapshot_ok ) ) { return $snapshot_ok; }

        $result = wp_update_post( array( 'ID' => $post_id, 'post_content' => $target ), true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        clean_post_cache( $post_id );
        $after = (string) get_post_field( 'post_content', $post_id );
        $activity_id = ALIFY_AI_Audit::log(
            'rollback_gutenberg',
            'gutenberg',
            $post_id,
            $current_snapshot,
            array( 'raw_content' => $after, 'hash' => self::hash_content( $after ) )
        );
        if ( ! $activity_id ) {
            $reverted = wp_update_post( array( 'ID' => $post_id, 'post_content' => $current ), true );
            clean_post_cache( $post_id );
            $verified = ! is_wp_error( $reverted ) && hash_equals( self::hash_content( $current ), self::hash_content( (string) get_post_field( 'post_content', $post_id ) ) );
            if ( ! $verified ) {
                ALIFY_AI_Diagnostics::log( 'gutenberg_compensation_failed', array( 'post_id' => $post_id, 'stage' => 'rollback_audit_failure' ) );
                return new WP_Error( 'alify_ai_gutenberg_unknown_state', 'Gutenberg rollback logging failed and the pre-rollback content could not be verified as restored; site state requires review.', array( 'status' => 500 ) );
            }
            return new WP_Error( 'alify_ai_audit_failed', 'Gutenberg rollback was reverted because the rollback log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'message' => 'Gutenberg change rolled back.', 'activity_id' => $activity_id, 'document' => self::document( $post_id ) );
    }


    public static function status(): array {
        $user_patterns = 0;
        if ( post_type_exists( 'wp_block' ) ) {
            $counts = wp_count_posts( 'wp_block' );
            foreach ( array( 'publish','draft','private','trash' ) as $status ) $user_patterns += isset( $counts->{$status} ) ? (int) $counts->{$status} : 0;
        }
        return array(
            'available'            => function_exists( 'parse_blocks' ) && class_exists( 'WP_Block_Type_Registry' ),
            'block_theme'          => function_exists( 'wp_is_block_theme' ) ? (bool) wp_is_block_theme() : false,
            'registered_blocks'    => class_exists( 'WP_Block_Type_Registry' ) ? count( WP_Block_Type_Registry::get_instance()->get_all_registered() ) : 0,
            'registered_patterns'  => class_exists( 'WP_Block_Patterns_Registry' ) ? count( WP_Block_Patterns_Registry::get_instance()->get_all_registered() ) : 0,
            'user_patterns'        => $user_patterns,
            'template_api'         => function_exists( 'get_block_templates' ) && post_type_exists( 'wp_template' ),
            'supports_approvals'   => true,
        );
    }

    public static function list_block_types( array $params = array() ): array {
        if ( ! class_exists( 'WP_Block_Type_Registry' ) ) return array();
        $search = strtolower( trim( (string) ( $params['search'] ?? '' ) ) );
        $category = sanitize_key( (string) ( $params['category'] ?? '' ) );
        $items = array();
        foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
            if ( $search && ! str_contains( strtolower( $name . ' ' . (string) $type->title . ' ' . (string) $type->description ), $search ) ) continue;
            if ( $category && sanitize_key( (string) $type->category ) !== $category ) continue;
            $items[] = self::block_type_summary( $type );
        }
        usort( $items, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );
        return $items;
    }

    public static function get_block_type( string $name ) {
        if ( ! class_exists( 'WP_Block_Type_Registry' ) ) return new WP_Error( 'alify_ai_gutenberg_unavailable', 'Block registry is unavailable.', array( 'status' => 503 ) );
        $type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
        if ( ! $type ) return new WP_Error( 'alify_ai_block_type_not_found', 'Registered block type not found.', array( 'status' => 404 ) );
        return self::block_type_summary( $type, true );
    }

    public static function validate_content( array $payload ) {
        $raw = '';
        if ( isset( $payload['post_id'] ) ) {
            $post = get_post( absint( $payload['post_id'] ) );
            if ( ! $post ) return new WP_Error( 'alify_ai_not_found', 'Content not found.', array( 'status' => 404 ) );
            $raw = (string) $post->post_content;
        } else {
            $raw = (string) ( $payload['content'] ?? $payload['raw'] ?? '' );
        }
        if ( strlen( $raw ) > 2 * 1024 * 1024 ) return new WP_Error( 'alify_ai_gutenberg_too_large', 'Gutenberg content exceeds the 2 MiB validation limit.', array( 'status' => 413 ) );
        if ( self::contains_unsafe_payload( $raw ) ) return new WP_Error( 'alify_ai_unsafe_gutenberg_payload', 'Unsafe executable markup was detected.', array( 'status' => 400 ) );
        $blocks = parse_blocks( $raw );
        return self::validate_blocks( $blocks );
    }

    public static function list_patterns( array $params = array() ): array {
        $source = sanitize_key( (string) ( $params['source'] ?? 'all' ) );
        $search = strtolower( trim( (string) ( $params['search'] ?? '' ) ) );
        $items = array();
        if ( in_array( $source, array( 'all', 'registered' ), true ) && class_exists( 'WP_Block_Patterns_Registry' ) ) {
            foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
                $title = (string) ( $pattern['title'] ?? $pattern['name'] ?? '' );
                $name = (string) ( $pattern['name'] ?? '' );
                if ( $search && ! str_contains( strtolower( $title . ' ' . $name ), $search ) ) continue;
                $items[] = array(
                    'source' => 'registered', 'name' => $name, 'title' => $title,
                    'categories' => array_values( (array) ( $pattern['categories'] ?? array() ) ),
                    'block_types' => array_values( (array) ( $pattern['blockTypes'] ?? array() ) ),
                    'inserter' => ! isset( $pattern['inserter'] ) || (bool) $pattern['inserter'],
                    'content_hash' => self::hash_content( (string) ( $pattern['content'] ?? '' ) ),
                );
            }
        }
        if ( in_array( $source, array( 'all', 'user' ), true ) && post_type_exists( 'wp_block' ) ) {
            $query = array( 'post_type' => 'wp_block', 'post_status' => array( 'publish','draft','private','trash' ), 'posts_per_page' => 100, 'orderby' => 'ID', 'order' => 'DESC' );
            if ( $search ) $query['s'] = $search;
            foreach ( get_posts( $query ) as $post ) $items[] = self::pattern_snapshot( $post );
        }
        return $items;
    }

    public static function get_registered_pattern( string $name ) {
        if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) return new WP_Error( 'alify_ai_patterns_unavailable', 'Registered pattern registry is unavailable.', array( 'status' => 503 ) );
        $pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $name );
        if ( ! $pattern ) return new WP_Error( 'alify_ai_pattern_not_found', 'Registered pattern not found.', array( 'status' => 404 ) );
        $validation = self::validate_blocks( parse_blocks( (string) ( $pattern['content'] ?? '' ) ) );
        return array_merge( $pattern, array( 'source' => 'registered', 'validation' => $validation ) );
    }

    public static function get_user_pattern( int $id ) {
        $post = get_post( $id );
        if ( ! $post || 'wp_block' !== $post->post_type ) return new WP_Error( 'alify_ai_pattern_not_found', 'User pattern not found.', array( 'status' => 404 ) );
        $snapshot = self::pattern_snapshot( $post );
        $snapshot['validation'] = self::validate_blocks( parse_blocks( (string) $post->post_content ) );
        return $snapshot;
    }

    public static function preview_pattern( int $id, array $payload ) {
        $action = sanitize_key( (string) ( $payload['action'] ?? ( $id ? 'update' : 'create' ) ) );
        if ( ! in_array( $action, array( 'create','update','delete','restore' ), true ) ) return new WP_Error( 'alify_ai_invalid_pattern_action', 'Pattern action must be create, update, delete, or restore.', array( 'status' => 400 ) );
        $before = null;
        if ( $id ) {
            $before = self::get_user_pattern( $id );
            if ( is_wp_error( $before ) ) return $before;
        }
        if ( in_array( $action, array( 'update','delete','restore' ), true ) && ! $before ) return new WP_Error( 'alify_ai_pattern_not_found', 'A user pattern is required for this action.', array( 'status' => 404 ) );
        $data = self::normalize_pattern_payload( $payload, $before );
        if ( is_wp_error( $data ) ) return $data;
        if ( in_array( $action, array( 'create','update' ), true ) ) {
            $validation = self::validate_blocks( parse_blocks( (string) $data['content'] ) );
            if ( ! $validation['valid'] ) return new WP_Error( 'alify_ai_invalid_pattern_blocks', 'Pattern contains invalid Gutenberg blocks.', array( 'status' => 400, 'validation' => $validation ) );
        } else { $validation = null; }
        if ( 'delete' === $action && ! empty( $payload['force'] ) && empty( $payload['confirm_permanent'] ) ) return new WP_Error( 'alify_ai_confirmation_required', 'Permanent pattern deletion requires confirm_permanent=true.', array( 'status' => 400 ) );
        $request = array( 'resource' => 'pattern', 'action' => $action, 'id' => $id, 'base_hash' => $before ? self::hash_content( wp_json_encode( $before ) ) : '', 'data' => $data, 'force' => ! empty( $payload['force'] ) );
        $preview = array( 'action' => $action, 'pattern_id' => $id, 'before' => $before, 'after' => in_array( $action, array( 'create','update' ), true ) ? $data : null, 'validation' => $validation );
        return ALIFY_AI_Approvals::create( 'gutenberg', $id, $request, $preview );
    }

    public static function list_templates( array $params = array() ) {
        if ( ! function_exists( 'get_block_templates' ) ) return new WP_Error( 'alify_ai_templates_unavailable', 'Block template APIs are unavailable.', array( 'status' => 503 ) );
        $type = self::template_type( $params['type'] ?? 'wp_template' );
        if ( is_wp_error( $type ) ) return $type;
        $query = array();
        if ( ! empty( $params['post_type'] ) ) $query['post_type'] = sanitize_key( (string) $params['post_type'] );
        if ( 'wp_template_part' === $type && ! empty( $params['area'] ) ) $query['area'] = sanitize_key( (string) $params['area'] );
        $items = array();
        foreach ( get_block_templates( $query, $type ) as $template ) $items[] = self::template_snapshot( $template, $type );
        return $items;
    }

    public static function get_template( string $id, string $type = 'wp_template' ) {
        if ( ! function_exists( 'get_block_template' ) ) return new WP_Error( 'alify_ai_templates_unavailable', 'Block template APIs are unavailable.', array( 'status' => 503 ) );
        $type = self::template_type( $type );
        if ( is_wp_error( $type ) ) return $type;
        $template = get_block_template( $id, $type );
        if ( ! $template ) return new WP_Error( 'alify_ai_template_not_found', 'Block template not found.', array( 'status' => 404 ) );
        $snapshot = self::template_snapshot( $template, $type, true );
        $snapshot['validation'] = self::validate_blocks( parse_blocks( (string) $template->content ) );
        return $snapshot;
    }

    public static function preview_template( array $payload ) {
        $action = sanitize_key( (string) ( $payload['action'] ?? 'update' ) );
        if ( ! in_array( $action, array( 'create','update','delete' ), true ) ) return new WP_Error( 'alify_ai_invalid_template_action', 'Template action must be create, update, or delete.', array( 'status' => 400 ) );
        $type = self::template_type( $payload['type'] ?? 'wp_template' );
        if ( is_wp_error( $type ) ) return $type;
        $theme = sanitize_key( (string) ( $payload['theme'] ?? get_stylesheet() ) );
        if ( function_exists( 'wp_get_theme' ) ) { $theme_obj = wp_get_theme( $theme ); if ( method_exists( $theme_obj, 'exists' ) && ! $theme_obj->exists() ) return new WP_Error( 'alify_ai_theme_not_found', 'Template theme stylesheet is not installed.', array( 'status' => 404 ) ); }
        $slug = sanitize_title( (string) ( $payload['slug'] ?? '' ) );
        $id = (string) ( $payload['id'] ?? ( $slug ? $theme . '//' . $slug : '' ) );
        $before = null;
        if ( $id ) {
            $candidate = self::get_template( $id, $type );
            if ( ! is_wp_error( $candidate ) ) $before = $candidate;
        }
        if ( in_array( $action, array( 'update','delete' ), true ) && ! $before ) return new WP_Error( 'alify_ai_template_not_found', 'Template not found.', array( 'status' => 404 ) );
        if ( 'create' === $action && ( ! $slug || $before ) ) return new WP_Error( 'alify_ai_template_conflict', 'Create requires a new non-empty template slug.', array( 'status' => 409 ) );
        $data = self::normalize_template_payload( $payload, $before, $type, $theme, $slug );
        if ( is_wp_error( $data ) ) return $data;
        $validation = null;
        if ( in_array( $action, array( 'create','update' ), true ) ) {
            $validation = self::validate_blocks( parse_blocks( (string) $data['content'] ) );
            if ( ! $validation['valid'] ) return new WP_Error( 'alify_ai_invalid_template_blocks', 'Template contains invalid Gutenberg blocks.', array( 'status' => 400, 'validation' => $validation ) );
        }
        $request = array( 'resource' => 'template', 'action' => $action, 'type' => $type, 'id' => $id, 'base_hash' => $before ? self::hash_content( wp_json_encode( $before ) ) : '', 'data' => $data );
        $preview = array( 'action' => $action, 'type' => $type, 'id' => $id, 'before' => $before, 'after' => in_array( $action, array( 'create','update' ), true ) ? $data : null, 'validation' => $validation );
        return ALIFY_AI_Approvals::create( 'gutenberg', absint( $before['wp_id'] ?? 0 ), $request, $preview );
    }

    public static function rollback_activity( array $entry ) {
        $action = (string) ( $entry['action'] ?? '' );
        if ( str_ends_with( $action, '_gutenberg_pattern' ) ) return self::rollback_pattern_activity( $entry );
        if ( str_ends_with( $action, '_gutenberg_template' ) ) return self::rollback_template_activity( $entry );
        return new WP_Error( 'alify_ai_rollback_unsupported', 'Unsupported Gutenberg entity rollback.', array( 'status' => 400 ) );
    }

    private static function apply_pattern_approval( array $approval, array $request ) {
        $action = sanitize_key( (string) ( $request['action'] ?? '' ) );
        $id = absint( $request['id'] ?? 0 );
        $before = $id ? self::get_user_pattern( $id ) : null;
        if ( is_wp_error( $before ) ) return $before;
        $base = (string) ( $request['base_hash'] ?? '' );
        if ( $before && $base && ! hash_equals( $base, self::hash_content( wp_json_encode( $before ) ) ) ) return new WP_Error( 'alify_ai_stale_proposal', 'Pattern changed after preview. Create a fresh proposal.', array( 'status' => 409 ) );
        $data = (array) ( $request['data'] ?? array() );
        if ( 'create' === $action ) {
            $post_id = wp_insert_post( array( 'post_type'=>'wp_block','post_status'=>$data['status'],'post_title'=>$data['title'],'post_name'=>$data['slug'],'post_content'=>$data['content'] ), true );
            if ( is_wp_error( $post_id ) || ! $post_id ) return is_wp_error( $post_id ) ? $post_id : new WP_Error( 'alify_ai_pattern_create_failed', 'Could not create pattern.', array( 'status' => 500 ) );
            $meta_result = self::apply_pattern_meta( $post_id, $data );
            if ( is_wp_error( $meta_result ) ) { wp_delete_post( $post_id, true ); return $meta_result; }
            $after = self::get_user_pattern( $post_id );
            $activity_id = ALIFY_AI_Audit::log( 'create_gutenberg_pattern', 'gutenberg_pattern', $post_id, null, $after );
            if ( ! $activity_id ) { wp_delete_post( $post_id, true ); return new WP_Error( 'alify_ai_audit_failed', 'Pattern creation was reverted because the activity log failed.', array( 'status' => 500 ) ); }
            return array( 'message'=>'Pattern created.','activity_id'=>$activity_id,'pattern'=>$after );
        }
        if ( 'update' === $action ) {
            $result = wp_update_post( array( 'ID'=>$id,'post_status'=>$data['status'],'post_title'=>$data['title'],'post_name'=>$data['slug'],'post_content'=>$data['content'] ), true );
            if ( is_wp_error( $result ) || ! $result ) return is_wp_error( $result ) ? $result : new WP_Error( 'alify_ai_pattern_update_failed', 'Could not update pattern.', array( 'status'=>500 ) );
            $meta_result = self::apply_pattern_meta( $id, $data );
            if ( is_wp_error( $meta_result ) ) { self::restore_pattern_snapshot( $before, $id ); return $meta_result; }
            $after = self::get_user_pattern( $id );
            $activity_id = ALIFY_AI_Audit::log( 'update_gutenberg_pattern','gutenberg_pattern',$id,$before,$after );
            if ( ! $activity_id ) { self::restore_pattern_snapshot( $before, $id ); return new WP_Error( 'alify_ai_audit_failed','Pattern update was reverted because the activity log failed.',array('status'=>500) ); }
            return array( 'message'=>'Pattern updated.','activity_id'=>$activity_id,'pattern'=>$after );
        }
        if ( 'delete' === $action ) {
            $force = ! empty( $request['force'] );
            if ( $force ) {
                $deleted = wp_delete_post( $id, true );
            } else {
                if ( defined( 'EMPTY_TRASH_DAYS' ) && 0 === (int) EMPTY_TRASH_DAYS ) return new WP_Error( 'alify_ai_trash_disabled', 'WordPress Trash is disabled. Use force=true with permanent-delete confirmation to delete this pattern.', array( 'status' => 409 ) );
                $deleted = wp_trash_post( $id );
            }
            if ( ! $deleted ) return new WP_Error( 'alify_ai_pattern_delete_failed', 'Could not delete pattern.', array( 'status'=>500 ) );
            $after = array( 'id'=>$id, 'deleted'=>true, 'permanent'=>$force );
            $activity_id = ALIFY_AI_Audit::log( 'delete_gutenberg_pattern','gutenberg_pattern',$id,$before,$after );
            if ( ! $activity_id ) { self::restore_pattern_snapshot( $before, $id ); return new WP_Error( 'alify_ai_audit_failed','Pattern deletion was reverted because the activity log failed.',array('status'=>500) ); }
            return array( 'message'=>$force ? 'Pattern permanently deleted.' : 'Pattern moved to Trash.','activity_id'=>$activity_id );
        }
        if ( 'restore' === $action ) {
            if ( 'trash' !== (string) ( $before['status'] ?? '' ) ) return new WP_Error( 'alify_ai_pattern_not_trashed','Pattern is not in Trash.',array('status'=>409) );
            $restored = wp_untrash_post( $id );
            if ( ! $restored ) return new WP_Error( 'alify_ai_pattern_restore_failed','Could not restore pattern.',array('status'=>500) );
            $after = self::get_user_pattern( $id );
            $activity_id = ALIFY_AI_Audit::log( 'restore_gutenberg_pattern','gutenberg_pattern',$id,$before,$after );
            if ( ! $activity_id ) {
                $reverted = wp_trash_post( $id );
                $verify = get_post( $id );
                if ( ! $reverted || ! $verify || 'trash' !== $verify->post_status ) {
                    ALIFY_AI_Diagnostics::log( 'gutenberg_pattern_compensation_failed', array( 'post_id' => $id, 'stage' => 'restore_audit_failure' ) );
                    return new WP_Error( 'alify_ai_gutenberg_unknown_state', 'Pattern restore logging failed and compensation could not be verified; site state requires review.', array( 'status' => 500 ) );
                }
                return new WP_Error( 'alify_ai_audit_failed','Pattern restore was reverted because the activity log failed.',array('status'=>500) );
            }
            return array( 'message'=>'Pattern restored.','activity_id'=>$activity_id,'pattern'=>$after );
        }
        return new WP_Error( 'alify_ai_invalid_pattern_action','Unsupported pattern action.',array('status'=>400) );
    }

    private static function apply_template_approval( array $approval, array $request ) {
        $action = sanitize_key( (string) ( $request['action'] ?? '' ) );
        $type = self::template_type( $request['type'] ?? 'wp_template' ); if ( is_wp_error( $type ) ) return $type;
        $id = (string) ( $request['id'] ?? '' );
        $before = $id ? self::get_template( $id, $type ) : null; if ( is_wp_error( $before ) ) $before = null;
        $base = (string) ( $request['base_hash'] ?? '' );
        if ( $before && $base && ! hash_equals( $base, self::hash_content( wp_json_encode( $before ) ) ) ) return new WP_Error( 'alify_ai_stale_proposal','Template changed after preview. Create a fresh proposal.',array('status'=>409) );
        $data = (array) ( $request['data'] ?? array() );
        if ( 'create' === $action || 'update' === $action ) {
            $post_id = absint( $before['wp_id'] ?? 0 );
            $postarr = array( 'post_type'=>$type,'post_status'=>'publish','post_title'=>$data['title'],'post_name'=>$data['slug'],'post_content'=>$data['content'] );
            if ( $post_id ) $postarr['ID']=$post_id;
            $saved = wp_insert_post( $postarr, true );
            if ( is_wp_error( $saved ) || ! $saved ) return is_wp_error( $saved ) ? $saved : new WP_Error( 'alify_ai_template_save_failed','Could not save template.',array('status'=>500) );
            if ( ! taxonomy_exists( 'wp_theme' ) ) { self::compensate_template_save( $saved, $before ); return new WP_Error( 'alify_ai_template_taxonomy_missing', 'WordPress wp_theme taxonomy is unavailable.', array( 'status' => 503 ) ); }
            $theme_terms = wp_set_object_terms( $saved, $data['theme'], 'wp_theme', false );
            if ( is_wp_error( $theme_terms ) ) { self::compensate_template_save( $saved, $before ); return $theme_terms; }
            if ( 'wp_template_part' === $type ) {
                if ( ! taxonomy_exists( 'wp_template_part_area' ) ) { self::compensate_template_save( $saved, $before ); return new WP_Error( 'alify_ai_template_area_taxonomy_missing', 'WordPress template-part area taxonomy is unavailable.', array( 'status' => 503 ) ); }
                $area_terms = wp_set_object_terms( $saved, $data['area'], 'wp_template_part_area', false );
                if ( is_wp_error( $area_terms ) ) { self::compensate_template_save( $saved, $before ); return $area_terms; }
            }
            clean_post_cache( $saved );
            $new_id = $data['theme'].'//'.$data['slug'];
            $after = self::get_template( $new_id, $type );
            if ( is_wp_error( $after ) ) $after = array_merge( $data, array('id'=>$new_id,'wp_id'=>$saved,'type'=>$type) );
            $audit_action = $action . '_gutenberg_template';
            $activity_id = ALIFY_AI_Audit::log( $audit_action,'gutenberg_template',$saved,$before,$after );
            if ( ! $activity_id ) {
                if ( $before && absint( $before['wp_id'] ?? 0 ) ) self::restore_template_snapshot( $before ); else wp_delete_post( $saved, true );
                return new WP_Error( 'alify_ai_audit_failed','Template save was reverted because the activity log failed.',array('status'=>500) );
            }
            return array( 'message'=>'create' === $action ? 'Template created.' : 'Template updated.','activity_id'=>$activity_id,'template'=>$after );
        }
        if ( 'delete' === $action ) {
            $post_id = absint( $before['wp_id'] ?? 0 );
            if ( ! $post_id ) return new WP_Error( 'alify_ai_template_theme_owned','This template has no customized database record to delete; it is provided by the theme.',array('status'=>409) );
            if ( ! wp_delete_post( $post_id, true ) ) return new WP_Error( 'alify_ai_template_delete_failed','Could not delete customized template.',array('status'=>500) );
            $activity_id = ALIFY_AI_Audit::log( 'delete_gutenberg_template','gutenberg_template',$post_id,$before,array('id'=>$id,'deleted'=>true) );
            if ( ! $activity_id ) { self::restore_template_snapshot( $before ); return new WP_Error( 'alify_ai_audit_failed','Template deletion was reverted because the activity log failed.',array('status'=>500) ); }
            return array( 'message'=>'Customized template deleted; a theme fallback may become active.','activity_id'=>$activity_id );
        }
        return new WP_Error( 'alify_ai_invalid_template_action','Unsupported template action.',array('status'=>400) );
    }

    private static function rollback_pattern_activity( array $entry ) {
        $action=(string)$entry['action']; $id=absint($entry['object_id']);
        $current = $id ? self::get_user_pattern($id) : null; if ( is_wp_error($current) ) $current=null;
        if ( 'create_gutenberg_pattern' === $action ) {
            if ( !$current ) return new WP_Error('alify_ai_pattern_not_found','Created pattern no longer exists.',array('status'=>404));
            $log=ALIFY_AI_Audit::log('rollback_create_gutenberg_pattern','gutenberg_pattern',$id,$current,array('deleted'=>true)); if(!$log) return new WP_Error('alify_ai_audit_failed','Could not store rollback log.',array('status'=>500));
            if(!wp_delete_post($id,true)){ ALIFY_AI_Audit::delete_entry($log); return new WP_Error('alify_ai_rollback_failed','Could not remove created pattern.',array('status'=>500)); }
            return array('message'=>'Created pattern removed.','activity_id'=>$log);
        }
        if ( in_array($action,array('update_gutenberg_pattern','delete_gutenberg_pattern','restore_gutenberg_pattern'),true) && is_array($entry['before_json']) ) {
            $restored=self::restore_pattern_snapshot($entry['before_json'],$id); if(is_wp_error($restored)) return $restored;
            $after=self::get_user_pattern(absint($restored['id']??$id)); if(is_wp_error($after)) return $after;
            $log=ALIFY_AI_Audit::log('rollback_'.$action,'gutenberg_pattern',absint($after['id']),$current,$after); if(!$log) return new WP_Error('alify_ai_audit_failed','Pattern restored but rollback log failed.',array('status'=>500));
            return array('message'=>'Pattern change rolled back.','activity_id'=>$log,'pattern'=>$after);
        }
        return new WP_Error('alify_ai_rollback_unsupported','Pattern rollback is unsupported for this activity.',array('status'=>400));
    }

    private static function rollback_template_activity( array $entry ) {
        $action=(string)$entry['action']; $before=is_array($entry['before_json'])?$entry['before_json']:null; $after=is_array($entry['after_json'])?$entry['after_json']:null;
        if ( 'create_gutenberg_template' === $action ) {
            $wp_id=absint($after['wp_id']??$entry['object_id']); if(!$wp_id || !get_post($wp_id)) return new WP_Error('alify_ai_template_not_found','Created template no longer exists.',array('status'=>404));
            $log=ALIFY_AI_Audit::log('rollback_create_gutenberg_template','gutenberg_template',$wp_id,$after,array('deleted'=>true)); if(!$log) return new WP_Error('alify_ai_audit_failed','Could not store rollback log.',array('status'=>500));
            if(!wp_delete_post($wp_id,true)){ALIFY_AI_Audit::delete_entry($log);return new WP_Error('alify_ai_rollback_failed','Could not remove created template.',array('status'=>500));}
            return array('message'=>'Created template removed.','activity_id'=>$log);
        }
        if ( in_array($action,array('update_gutenberg_template','delete_gutenberg_template'),true) && $before ) {
            if ( 'update_gutenberg_template' === $action && 0 === absint( $before['wp_id'] ?? 0 ) ) {
                $created_id = absint( $after['wp_id'] ?? $entry['object_id'] ?? 0 );
                if ( $created_id && get_post( $created_id ) && ! wp_delete_post( $created_id, true ) ) return new WP_Error( 'alify_ai_rollback_failed', 'Could not remove the customized override.', array( 'status' => 500 ) );
                $restored = self::get_template( (string) $before['id'], (string) $before['type'] );
                if ( is_wp_error( $restored ) ) $restored = $before;
            } else {
                $restored=self::restore_template_snapshot($before); if(is_wp_error($restored)) return $restored;
            }
            $log=ALIFY_AI_Audit::log('rollback_'.$action,'gutenberg_template',absint($restored['wp_id']??0),$after,$restored); if(!$log) return new WP_Error('alify_ai_audit_failed','Template restored but rollback log failed.',array('status'=>500));
            return array('message'=>'Template change rolled back.','activity_id'=>$log,'template'=>$restored);
        }
        return new WP_Error('alify_ai_rollback_unsupported','Template rollback is unsupported for this activity.',array('status'=>400));
    }

    private static function block_type_summary( $type, bool $full = false ): array {
        $data=array('name'=>$type->name,'title'=>(string)$type->title,'description'=>(string)$type->description,'category'=>$type->category,'parent'=>$type->parent,'ancestor'=>$type->ancestor,'allowed_blocks'=>$type->allowed_blocks,'supports'=>$type->supports,'is_dynamic'=>method_exists($type,'is_dynamic')?(bool)$type->is_dynamic():!empty($type->render_callback));
        if($full){$data['attributes']=method_exists($type,'get_attributes')?$type->get_attributes():(array)$type->attributes;$data['keywords']=$type->keywords;$data['styles']=$type->styles;$data['uses_context']=$type->uses_context;$data['provides_context']=$type->provides_context;$data['selectors']=$type->selectors??array();}
        return $data;
    }

    private static function validate_blocks( array $blocks ): array {
        $errors=array();$warnings=array();$count=0;$max_depth=0;
        self::validate_block_level($blocks,array(),array(),$errors,$warnings,$count,$max_depth);
        return array('valid'=>empty($errors),'block_count'=>$count,'max_depth'=>$max_depth,'errors'=>$errors,'warnings'=>$warnings);
    }

    private static function validate_block_level( array $blocks, array $ancestors, array $path, array &$errors, array &$warnings, int &$count, int &$max_depth ): void {
        if(count($blocks)>500){$errors[]=array('code'=>'too_many_siblings','path'=>self::path_string($path),'message'=>'A block level contains more than 500 blocks.');return;}
        foreach(array_values($blocks) as $i=>$block){$p=array_merge($path,array($i));$count++;$max_depth=max($max_depth,count($p));if($count>2000){$errors[]=array('code'=>'too_many_blocks','path'=>self::path_string($p),'message'=>'Document exceeds 2000 blocks.');return;}if(count($p)>50){$errors[]=array('code'=>'too_deep','path'=>self::path_string($p),'message'=>'Block nesting exceeds 50 levels.');continue;}
            $name=$block['blockName']??null;if(null===$name){if(trim((string)($block['innerHTML']??''))!=='')$warnings[]=array('code'=>'freeform_html','path'=>self::path_string($p),'message'=>'Freeform/classic HTML is not schema validated.');continue;}
            $type=class_exists('WP_Block_Type_Registry')?WP_Block_Type_Registry::get_instance()->get_registered($name):null;if(!$type){$errors[]=array('code'=>'unregistered_block','path'=>self::path_string($p),'block'=>$name,'message'=>'Block type is not registered on this site.');continue;}
            $parent=end($ancestors)?:null;if(!empty($type->parent)&&(!$parent||!in_array($parent,$type->parent,true)))$errors[]=array('code'=>'invalid_parent','path'=>self::path_string($p),'block'=>$name,'message'=>'Block is not allowed under its current direct parent.');
            if(!empty($type->ancestor)&&!array_intersect($ancestors,$type->ancestor))$errors[]=array('code'=>'invalid_ancestor','path'=>self::path_string($p),'block'=>$name,'message'=>'Block requires a registered ancestor that is not present.');
            if($parent){$pt=WP_Block_Type_Registry::get_instance()->get_registered($parent);if($pt&&!empty($pt->allowed_blocks)&&!in_array($name,$pt->allowed_blocks,true))$errors[]=array('code'=>'child_not_allowed','path'=>self::path_string($p),'block'=>$name,'message'=>'Parent block does not allow this child block type.');}
            $attrs=(array)($block['attrs']??array());$schema=method_exists($type,'get_attributes')?(array)$type->get_attributes():(array)$type->attributes;foreach($attrs as $key=>$value){if(isset($schema[$key])){self::validate_attribute_value($name,$key,$value,$schema[$key],self::path_string($p),$errors);}elseif(!str_starts_with((string)$key,'metadata')){$warnings[]=array('code'=>'unknown_attribute','path'=>self::path_string($p),'block'=>$name,'attribute'=>$key,'message'=>'Attribute is not declared by the server-side block schema; it may be editor-only or plugin-defined.');}}
            if ( 'core/block' === $name && ! empty( $attrs['ref'] ) ) { $ref=get_post(absint($attrs['ref'])); if(!$ref||'wp_block'!==$ref->post_type||'trash'===$ref->post_status)$errors[]=array('code'=>'missing_pattern_reference','path'=>self::path_string($p),'block'=>$name,'message'=>'Synced-pattern reference does not point to an active wp_block pattern.'); }
            if ( 'core/template-part' === $name && ! empty( $attrs['slug'] ) && function_exists('get_block_template') ) { $theme=sanitize_key((string)($attrs['theme']??get_stylesheet()));$part=get_block_template($theme.'//'.sanitize_title((string)$attrs['slug']),'wp_template_part');if(!$part)$errors[]=array('code'=>'missing_template_part','path'=>self::path_string($p),'block'=>$name,'message'=>'Template Part block references a template part that does not exist.'); }
            $children=(array)($block['innerBlocks']??array());self::validate_block_level($children,array_merge($ancestors,array($name)),$p,$errors,$warnings,$count,$max_depth);
        }
    }

    private static function validate_attribute_value(string $block,string $key,$value,array $schema,string $path,array &$errors):void{
        if(isset($schema['enum'])&&!in_array($value,(array)$schema['enum'],true)){$errors[]=array('code'=>'invalid_attribute_enum','path'=>$path,'block'=>$block,'attribute'=>$key,'message'=>'Attribute value is outside the registered enum.');return;}
        $type=$schema['type']??null;if(!$type)return;$types=is_array($type)?$type:array($type);$ok=false;foreach($types as $t){$ok=$ok||('string'===$t&&is_string($value))||('number'===$t&&(is_int($value)||is_float($value)))||('integer'===$t&&is_int($value))||('boolean'===$t&&is_bool($value))||('array'===$t&&is_array($value)&&self::is_list_array($value))||('object'===$t&&is_array($value))||('null'===$t&&null===$value);}if(!$ok)$errors[]=array('code'=>'invalid_attribute_type','path'=>$path,'block'=>$block,'attribute'=>$key,'expected'=>$types,'message'=>'Attribute value does not match the registered type.');
    }

    private static function normalize_pattern_payload(array $payload,$before){$title=sanitize_text_field((string)($payload['title']??($before['title']??'')));$slug=sanitize_title((string)($payload['slug']??($before['slug']??$title)));$content=(string)($payload['content']??($before['content']??''));$status=sanitize_key((string)($payload['status']??($before['status']??'publish')));if(!in_array($status,array('publish','draft','private','trash'),true))return new WP_Error('alify_ai_invalid_pattern_status','Pattern status must be publish, draft, private, or trash.',array('status'=>400));if(!$title||!$slug)return new WP_Error('alify_ai_pattern_title_required','Pattern title and slug are required.',array('status'=>400));$sync=sanitize_key((string)($payload['sync_status']??($before['sync_status']??'synced')));if(!in_array($sync,array('synced','unsynced'),true))return new WP_Error('alify_ai_invalid_sync_status','sync_status must be synced or unsynced.',array('status'=>400));$categories=array_values(array_unique(array_filter(array_map('sanitize_title',(array)($payload['categories']??($before['categories']??array()))))));return array('title'=>$title,'slug'=>$slug,'content'=>$content,'status'=>$status,'sync_status'=>$sync,'categories'=>$categories);}
    private static function pattern_snapshot($post):array{$cats=array();if(taxonomy_exists('wp_pattern_category')){$terms=wp_get_object_terms($post->ID,'wp_pattern_category',array('fields'=>'slugs'));if(!is_wp_error($terms))$cats=array_values($terms);}return array('source'=>'user','id'=>(int)$post->ID,'title'=>get_the_title($post),'slug'=>$post->post_name,'status'=>$post->post_status,'content'=>(string)$post->post_content,'sync_status'=>'unsynced'===get_post_meta($post->ID,'wp_pattern_sync_status',true)?'unsynced':'synced','categories'=>$cats,'content_hash'=>self::hash_content((string)$post->post_content));}
    private static function apply_pattern_meta(int $id,array $data){if('unsynced'===$data['sync_status'])update_post_meta($id,'wp_pattern_sync_status','unsynced');else delete_post_meta($id,'wp_pattern_sync_status');if(taxonomy_exists('wp_pattern_category')){foreach($data['categories'] as $slug){if(!term_exists($slug,'wp_pattern_category')){$created=wp_insert_term(ucwords(str_replace(array('-','_'),' ',$slug)),'wp_pattern_category',array('slug'=>$slug));if(is_wp_error($created))return $created;}}$assigned=wp_set_object_terms($id,$data['categories'],'wp_pattern_category',false);if(is_wp_error($assigned))return $assigned;}return true;}
    private static function restore_pattern_snapshot(array $snap,int $preferred_id=0){$existing=$preferred_id?get_post($preferred_id):null;$arr=array('post_type'=>'wp_block','post_status'=>$snap['status'],'post_title'=>$snap['title'],'post_name'=>$snap['slug'],'post_content'=>$snap['content']);if($existing&&'wp_block'===$existing->post_type)$arr['ID']=$preferred_id;$id=wp_insert_post($arr,true);if(is_wp_error($id)||!$id)return is_wp_error($id)?$id:new WP_Error('alify_ai_pattern_restore_failed','Could not restore pattern.',array('status'=>500));$meta=self::apply_pattern_meta($id,$snap);if(is_wp_error($meta))return $meta;return self::get_user_pattern($id);}

    private static function template_type($type){$type=sanitize_key((string)$type);if(!in_array($type,array('wp_template','wp_template_part'),true))return new WP_Error('alify_ai_invalid_template_type','Template type must be wp_template or wp_template_part.',array('status'=>400));return $type;}
    private static function template_snapshot($t,string $type,bool $include_content=false):array{$data=array('id'=>(string)$t->id,'wp_id'=>absint($t->wp_id??0),'type'=>$type,'theme'=>(string)($t->theme??get_stylesheet()),'slug'=>(string)$t->slug,'title'=>(string)($t->title??$t->slug),'description'=>(string)($t->description??''),'source'=>(string)($t->source??''),'origin'=>(string)($t->origin??''),'status'=>(string)($t->status??'publish'),'has_theme_file'=>!empty($t->has_theme_file),'is_custom'=>!empty($t->is_custom),'area'=>(string)($t->area??''),'content_hash'=>self::hash_content((string)($t->content??'')));if($include_content)$data['content']=(string)($t->content??'');return $data;}
    private static function normalize_template_payload(array $payload,$before,string $type,string $theme,string $slug){$slug=sanitize_title((string)($payload['slug']??($before['slug']??$slug)));$title=sanitize_text_field((string)($payload['title']??($before['title']??$slug)));$content=(string)($payload['content']??($before['content']??''));if(!$slug||!$title)return new WP_Error('alify_ai_template_fields_required','Template slug and title are required.',array('status'=>400));$area='';if('wp_template_part'===$type){$area=sanitize_key((string)($payload['area']??($before['area']??'uncategorized')));if(!$area)$area='uncategorized';}return array('type'=>$type,'theme'=>$theme,'slug'=>$slug,'title'=>$title,'content'=>$content,'area'=>$area);}
    private static function compensate_template_save( int $saved, $before ): void { if ( is_array( $before ) && absint( $before['wp_id'] ?? 0 ) ) { self::restore_template_snapshot( $before ); } else { wp_delete_post( $saved, true ); } }
    private static function restore_template_snapshot(array $snap){$type=self::template_type($snap['type']??'wp_template');if(is_wp_error($type))return $type;$wp_id=absint($snap['wp_id']??0);$existing=$wp_id?get_post($wp_id):null;$arr=array('post_type'=>$type,'post_status'=>'publish','post_title'=>$snap['title'],'post_name'=>$snap['slug'],'post_content'=>$snap['content']??'');if($existing&&$existing->post_type===$type)$arr['ID']=$wp_id;$id=wp_insert_post($arr,true);if(is_wp_error($id)||!$id)return is_wp_error($id)?$id:new WP_Error('alify_ai_template_restore_failed','Could not restore template.',array('status'=>500));wp_set_object_terms($id,$snap['theme']??get_stylesheet(),'wp_theme',false);if('wp_template_part'===$type)wp_set_object_terms($id,$snap['area']??'uncategorized','wp_template_part_area',false);clean_post_cache($id);$identifier=($snap['theme']??get_stylesheet()).'//'.$snap['slug'];$got=self::get_template($identifier,$type);return is_wp_error($got)?array_merge($snap,array('wp_id'=>$id,'id'=>$identifier)):$got;}

    private static function apply_operations_to_blocks( array $blocks, array $operations ) {
        $changes = array();
        foreach ( $operations as $index => $operation ) {
            if ( ! is_array( $operation ) ) {
                return new WP_Error( 'alify_ai_invalid_operation', 'Each Gutenberg operation must be an object.', array( 'status' => 400, 'operation_index' => $index ) );
            }
            $type = sanitize_key( (string) ( $operation['type'] ?? '' ) );
            if ( 'update_attributes' === $type ) {
                $path = self::parse_path( $operation['path'] ?? '' );
                if ( is_wp_error( $path ) ) { return $path; }
                $attributes = $operation['attributes'] ?? null;
                if ( ! is_array( $attributes ) ) {
                    return new WP_Error( 'alify_ai_invalid_attributes', 'update_attributes requires an attributes object.', array( 'status' => 400, 'operation_index' => $index ) );
                }
                $replace = ! empty( $operation['replace'] );
                $changed = self::mutate_block( $blocks, $path, static function ( array $block ) use ( $attributes, $replace ) {
                    $block['attrs'] = $replace ? $attributes : array_merge( (array) ( $block['attrs'] ?? array() ), $attributes );
                    return $block;
                } );
                if ( is_wp_error( $changed ) ) { return $changed; }
                $blocks = $changed;
                $changes[] = array( 'type' => $type, 'path' => self::path_string( $path ), 'attributes' => array_keys( $attributes ) );
            } elseif ( 'replace_inner_html' === $type ) {
                $path = self::parse_path( $operation['path'] ?? '' );
                if ( is_wp_error( $path ) ) { return $path; }
                $html = wp_kses_post( (string) ( $operation['html'] ?? '' ) );
                $changed = self::mutate_block( $blocks, $path, static function ( array $block ) use ( $html ) {
                    if ( ! empty( $block['innerBlocks'] ) ) {
                        return new WP_Error( 'alify_ai_container_html_forbidden', 'replace_inner_html is only allowed on leaf blocks. Use child block operations for containers.', array( 'status' => 400 ) );
                    }
                    $block['innerHTML'] = $html;
                    $block['innerContent'] = array( $html );
                    return $block;
                } );
                if ( is_wp_error( $changed ) ) { return $changed; }
                $blocks = $changed;
                $changes[] = array( 'type' => $type, 'path' => self::path_string( $path ) );
            } elseif ( 'replace_block' === $type ) {
                $path = self::parse_path( $operation['path'] ?? '' );
                if ( is_wp_error( $path ) ) { return $path; }
                $new_block = self::parse_single_block( (string) ( $operation['raw'] ?? '' ) );
                if ( is_wp_error( $new_block ) ) { return $new_block; }
                $changed = self::replace_block( $blocks, $path, $new_block );
                if ( is_wp_error( $changed ) ) { return $changed; }
                $blocks = $changed;
                $changes[] = array( 'type' => $type, 'path' => self::path_string( $path ), 'block_name' => (string) ( $new_block['blockName'] ?? 'freeform' ) );
            } elseif ( 'remove_block' === $type ) {
                $path = self::parse_path( $operation['path'] ?? '' );
                if ( is_wp_error( $path ) ) { return $path; }
                $changed = self::remove_block( $blocks, $path );
                if ( is_wp_error( $changed ) ) { return $changed; }
                $blocks = $changed;
                $changes[] = array( 'type' => $type, 'path' => self::path_string( $path ) );
            } elseif ( 'insert_block' === $type ) {
                $parent_path = self::parse_path( $operation['parent_path'] ?? '', true );
                if ( is_wp_error( $parent_path ) ) { return $parent_path; }
                $new_block = self::parse_single_block( (string) ( $operation['raw'] ?? '' ) );
                if ( is_wp_error( $new_block ) ) { return $new_block; }
                $position = isset( $operation['index'] ) ? max( 0, (int) $operation['index'] ) : PHP_INT_MAX;
                $changed = self::insert_block( $blocks, $parent_path, $position, $new_block );
                if ( is_wp_error( $changed ) ) { return $changed; }
                $blocks = $changed;
                $changes[] = array( 'type' => $type, 'parent_path' => self::path_string( $parent_path ), 'block_name' => (string) ( $new_block['blockName'] ?? 'freeform' ) );
            } elseif ( 'insert_blocks' === $type ) {
                $parent_path = self::parse_path( $operation['parent_path'] ?? '', true ); if ( is_wp_error($parent_path) ) return $parent_path;
                $new_blocks = self::parse_multiple_blocks( (string) ( $operation['raw'] ?? '' ) ); if ( is_wp_error($new_blocks) ) return $new_blocks;
                $position = isset($operation['index']) ? max(0,(int)$operation['index']) : PHP_INT_MAX;
                foreach($new_blocks as $offset=>$new_block){$changed=self::insert_block($blocks,$parent_path,$position===PHP_INT_MAX?PHP_INT_MAX:$position+$offset,$new_block);if(is_wp_error($changed))return $changed;$blocks=$changed;}
                $changes[]=array('type'=>$type,'parent_path'=>self::path_string($parent_path),'count'=>count($new_blocks));
            } elseif ( 'duplicate_block' === $type ) {
                $path=self::parse_path($operation['path']??'');if(is_wp_error($path))return $path;$source=self::get_block_at_path($blocks,$path);if(is_wp_error($source))return $source;$parent=$path;$index=array_pop($parent);$position=isset($operation['index'])?max(0,(int)$operation['index']):$index+1;$changed=self::insert_block($blocks,$parent,$position,$source);if(is_wp_error($changed))return $changed;$blocks=$changed;$changes[]=array('type'=>$type,'path'=>self::path_string($path),'inserted_at'=>$position);
            } elseif ( 'move_block' === $type ) {
                $path=self::parse_path($operation['path']??'');if(is_wp_error($path))return $path;$source=self::get_block_at_path($blocks,$path);if(is_wp_error($source))return $source;$dest=self::parse_path($operation['parent_path']??'',true);if(is_wp_error($dest))return $dest;if(count($dest)>=count($path)&&array_slice($dest,0,count($path))===$path)return new WP_Error('alify_ai_invalid_block_move','A block cannot be moved inside itself or one of its descendants.',array('status'=>400));$without=self::remove_block($blocks,$path);if(is_wp_error($without))return $without;$position=isset($operation['index'])?max(0,(int)$operation['index']):PHP_INT_MAX;$changed=self::insert_block($without,$dest,$position,$source);if(is_wp_error($changed))return $changed;$blocks=$changed;$changes[]=array('type'=>$type,'from'=>self::path_string($path),'to_parent'=>self::path_string($dest),'index'=>$position);
            } elseif ( 'replace_document' === $type ) {
                $new_blocks=self::parse_multiple_blocks((string)($operation['raw']??''));if(is_wp_error($new_blocks))return $new_blocks;$blocks=$new_blocks;$changes[]=array('type'=>$type,'count'=>count($new_blocks));
            } else {
                return new WP_Error( 'alify_ai_unknown_gutenberg_operation', 'Unsupported Gutenberg operation type.', array( 'status' => 400, 'operation_index' => $index, 'type' => $type ) );
            }
        }

        $validation = self::validate_blocks( $blocks );
        if ( ! $validation['valid'] ) {
            return new WP_Error( 'alify_ai_invalid_gutenberg_tree', 'The resulting block tree violates registered Gutenberg block constraints.', array( 'status' => 400, 'validation' => $validation ) );
        }
        return array( 'blocks' => $blocks, 'changes' => $changes, 'validation' => $validation );
    }

    private static function parse_multiple_blocks( string $raw ) {
        $raw = trim( $raw );
        if ( '' === $raw ) return new WP_Error( 'alify_ai_blocks_raw_required', 'Serialized Gutenberg blocks are required.', array( 'status' => 400 ) );
        if ( self::contains_unsafe_payload( $raw ) ) return new WP_Error( 'alify_ai_unsafe_block_markup', 'Unsafe executable markup is not accepted.', array( 'status' => 400 ) );
        $parsed = array_values( array_filter( parse_blocks( $raw ), static function( $block ){ return is_array($block) && ( null !== ($block['blockName']??null) || '' !== trim((string)($block['innerHTML']??'')) ); } ) );
        if ( empty( $parsed ) ) return new WP_Error( 'alify_ai_blocks_required', 'No Gutenberg blocks were found.', array( 'status' => 400 ) );
        $validation=self::validate_blocks($parsed);if(!$validation['valid'])return new WP_Error('alify_ai_invalid_gutenberg_tree','Serialized blocks violate registered block constraints.',array('status'=>400,'validation'=>$validation));
        return $parsed;
    }

    private static function get_block_at_path( array $blocks, array $path ) {
        foreach($path as $index){if(!array_key_exists($index,$blocks))return new WP_Error('alify_ai_block_not_found','No Gutenberg block exists at the requested path.',array('status'=>404));$block=$blocks[$index];$blocks=(array)($block['innerBlocks']??array());}
        return $block??new WP_Error('alify_ai_block_not_found','No Gutenberg block exists at the requested path.',array('status'=>404));
    }

    private static function parse_single_block( string $raw ) {
        $raw = trim( $raw );
        if ( '' === $raw ) {
            return new WP_Error( 'alify_ai_block_raw_required', 'A serialized Gutenberg block is required.', array( 'status' => 400 ) );
        }
        if ( preg_match( '/<(script|iframe|object|embed)\b|javascript\s*:|on(?:error|load|click|mouseover|focus)\s*=/i', $raw ) ) {
            return new WP_Error( 'alify_ai_unsafe_block_markup', 'Script, embedded executable markup, inline event handlers and javascript: URLs are not accepted by this endpoint.', array( 'status' => 400 ) );
        }
        $parsed = parse_blocks( $raw );
        $parsed = array_values( array_filter( $parsed, static function ( $block ) {
            return is_array( $block ) && ( null !== ( $block['blockName'] ?? null ) || '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) );
        } ) );
        if ( 1 !== count( $parsed ) ) {
            return new WP_Error( 'alify_ai_single_block_required', 'raw must contain exactly one Gutenberg block.', array( 'status' => 400 ) );
        }
        return $parsed[0];
    }

    private static function parse_path( $value, bool $allow_root = false ) {
        if ( is_array( $value ) ) {
            $parts = $value;
        } else {
            $value = trim( (string) $value );
            if ( '' === $value ) {
                return $allow_root ? array() : new WP_Error( 'alify_ai_block_path_required', 'A block path is required.', array( 'status' => 400 ) );
            }
            $parts = preg_split( '/[.\/]+/', $value );
        }
        $path = array();
        foreach ( $parts as $part ) {
            if ( '' === (string) $part || ! ctype_digit( (string) $part ) ) {
                return new WP_Error( 'alify_ai_invalid_block_path', 'Block paths must contain zero-based numeric indexes such as 0.2.1.', array( 'status' => 400 ) );
            }
            $path[] = (int) $part;
        }
        if ( empty( $path ) && ! $allow_root ) {
            return new WP_Error( 'alify_ai_block_path_required', 'A block path is required.', array( 'status' => 400 ) );
        }
        return $path;
    }

    private static function mutate_block( array $blocks, array $path, callable $callback ) {
        $index = array_shift( $path );
        if ( ! array_key_exists( $index, $blocks ) ) {
            return new WP_Error( 'alify_ai_block_not_found', 'No Gutenberg block exists at the requested path.', array( 'status' => 404 ) );
        }
        if ( empty( $path ) ) {
            $new = $callback( $blocks[ $index ] );
            if ( is_wp_error( $new ) ) { return $new; }
            $blocks[ $index ] = $new;
            return $blocks;
        }
        $children = (array) ( $blocks[ $index ]['innerBlocks'] ?? array() );
        $new_children = self::mutate_block( $children, $path, $callback );
        if ( is_wp_error( $new_children ) ) { return $new_children; }
        $blocks[ $index ]['innerBlocks'] = $new_children;
        return $blocks;
    }

    private static function replace_block( array $blocks, array $path, array $new_block ) {
        $index = array_shift( $path );
        if ( ! array_key_exists( $index, $blocks ) ) {
            return new WP_Error( 'alify_ai_block_not_found', 'No Gutenberg block exists at the requested path.', array( 'status' => 404 ) );
        }
        if ( empty( $path ) ) {
            $blocks[ $index ] = $new_block;
            return $blocks;
        }
        $children = (array) ( $blocks[ $index ]['innerBlocks'] ?? array() );
        $children = self::replace_block( $children, $path, $new_block );
        if ( is_wp_error( $children ) ) { return $children; }
        $blocks[ $index ]['innerBlocks'] = $children;
        return $blocks;
    }

    private static function remove_block( array $blocks, array $path ) {
        $index = array_shift( $path );
        if ( ! array_key_exists( $index, $blocks ) ) {
            return new WP_Error( 'alify_ai_block_not_found', 'No Gutenberg block exists at the requested path.', array( 'status' => 404 ) );
        }
        if ( empty( $path ) ) {
            array_splice( $blocks, $index, 1 );
            return $blocks;
        }
        $children = (array) ( $blocks[ $index ]['innerBlocks'] ?? array() );
        $child_index = $path[0];
        $direct_child = 1 === count( $path );
        $children = self::remove_block( $children, $path );
        if ( is_wp_error( $children ) ) { return $children; }
        $blocks[ $index ]['innerBlocks'] = $children;
        if ( $direct_child ) {
            $blocks[ $index ]['innerContent'] = self::remove_inner_placeholder( (array) ( $blocks[ $index ]['innerContent'] ?? array() ), $child_index );
        }
        return $blocks;
    }

    private static function insert_block( array $blocks, array $parent_path, int $position, array $new_block ) {
        if ( empty( $parent_path ) ) {
            $position = min( $position, count( $blocks ) );
            array_splice( $blocks, $position, 0, array( $new_block ) );
            return $blocks;
        }
        $index = array_shift( $parent_path );
        if ( ! array_key_exists( $index, $blocks ) ) {
            return new WP_Error( 'alify_ai_block_not_found', 'No Gutenberg parent block exists at the requested path.', array( 'status' => 404 ) );
        }
        if ( empty( $parent_path ) ) {
            $children = (array) ( $blocks[ $index ]['innerBlocks'] ?? array() );
            $position = min( $position, count( $children ) );
            array_splice( $children, $position, 0, array( $new_block ) );
            $blocks[ $index ]['innerBlocks'] = $children;
            $blocks[ $index ]['innerContent'] = self::insert_inner_placeholder( (array) ( $blocks[ $index ]['innerContent'] ?? array() ), $position );
            return $blocks;
        }
        $children = (array) ( $blocks[ $index ]['innerBlocks'] ?? array() );
        $children = self::insert_block( $children, $parent_path, $position, $new_block );
        if ( is_wp_error( $children ) ) { return $children; }
        $blocks[ $index ]['innerBlocks'] = $children;
        return $blocks;
    }

    private static function insert_inner_placeholder( array $inner_content, int $child_index ): array {
        $null_positions = array();
        foreach ( $inner_content as $position => $part ) {
            if ( null === $part ) { $null_positions[] = $position; }
        }
        if ( empty( $inner_content ) ) {
            return array( null );
        }
        if ( isset( $null_positions[ $child_index ] ) ) {
            array_splice( $inner_content, $null_positions[ $child_index ], 0, array( null ) );
            return $inner_content;
        }
        if ( ! empty( $null_positions ) ) {
            array_splice( $inner_content, end( $null_positions ) + 1, 0, array( null ) );
            return $inner_content;
        }
        if ( count( $inner_content ) >= 2 ) {
            array_splice( $inner_content, count( $inner_content ) - 1, 0, array( null ) );
        } else {
            $inner_content[] = null;
        }
        return $inner_content;
    }

    private static function remove_inner_placeholder( array $inner_content, int $child_index ): array {
        $seen = 0;
        foreach ( $inner_content as $position => $part ) {
            if ( null === $part ) {
                if ( $seen === $child_index ) {
                    array_splice( $inner_content, $position, 1 );
                    break;
                }
                ++$seen;
            }
        }
        return $inner_content;
    }

    private static function summarize_blocks( array $blocks, string $prefix = '' ): array {
        $result = array();
        foreach ( array_values( $blocks ) as $index => $block ) {
            $path = '' === $prefix ? (string) $index : $prefix . '.' . $index;
            $children = (array) ( $block['innerBlocks'] ?? array() );
            $entry = array(
                'path'       => $path,
                'name'       => $block['blockName'] ?? 'freeform',
                'attributes' => (array) ( $block['attrs'] ?? array() ),
                'html'       => self::preview_html( (string) ( $block['innerHTML'] ?? '' ) ),
                'children'   => self::summarize_blocks( $children, $path ),
            );
            $result[] = $entry;
        }
        return $result;
    }

    private static function preview_html( string $html ): string {
        $html = trim( preg_replace( '/\s+/', ' ', $html ) );
        return strlen( $html ) > 500 ? substr( $html, 0, 500 ) . '…' : $html;
    }

    private static function count_blocks( array $blocks ): int {
        $count = 0;
        foreach ( $blocks as $block ) {
            ++$count;
            $count += self::count_blocks( (array) ( $block['innerBlocks'] ?? array() ) );
        }
        return $count;
    }

    private static function path_string( array $path ): string {
        return empty( $path ) ? 'root' : implode( '.', $path );
    }

    private static function contains_unsafe_payload( $value ): bool {
        if ( is_array( $value ) ) {
            foreach ( $value as $child ) {
                if ( self::contains_unsafe_payload( $child ) ) {
                    return true;
                }
            }
            return false;
        }
        if ( ! is_string( $value ) ) {
            return false;
        }
        return (bool) preg_match( '/<\s*(script|iframe|object|embed)\b|javascript\s*:|on(?:error|load|click|mouseover|focus)\s*=/i', $value );
    }

    private static function is_list_array( array $value ): bool { $expected = 0; foreach ( $value as $key => $_ ) { if ( $key !== $expected++ ) return false; } return true; }

    private static function hash_content( string $content ): string {
        return hash( 'sha256', $content );
    }
}
