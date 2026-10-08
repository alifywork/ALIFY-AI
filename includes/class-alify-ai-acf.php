<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ALIFY_AI_ACF {
    private const FIELD_TYPES = array(
        'text','textarea','number','range','email','url','password','image','file','wysiwyg',
        'select','checkbox','radio','button_group','true_false','date_picker','date_time_picker','time_picker',
        'color_picker','message','accordion','tab','group','repeater','post_object','page_link','relationship',
        'taxonomy','user','google_map','oembed','link','gallery','clone'
    );

    private const CONTAINER_TYPES = array( 'group', 'repeater' );

    public static function available(): bool {
        return function_exists( 'acf_get_field_groups' )
            && function_exists( 'acf_get_fields' )
            && function_exists( 'acf_get_field_group' )
            && function_exists( 'acf_get_field' )
            && function_exists( 'acf_update_field_group' )
            && function_exists( 'acf_update_field' )
            && function_exists( 'update_field' );
    }

    public static function status(): array {
        return array(
            'available'          => self::available(),
            'version'            => defined( 'ACF_VERSION' ) ? ACF_VERSION : null,
            'pro'                => defined( 'ACF_PRO' ) ? (bool) ACF_PRO : false,
            'group_fields'       => self::available(),
            'repeater_fields'    => defined( 'ACF_PRO' ) ? (bool) ACF_PRO : false,
            'conditional_logic'  => self::available(),
            'nested_sub_fields'  => self::available(),
            'supported_types'    => self::FIELD_TYPES,
        );
    }

    public static function list_groups(): array {
        if ( ! self::available() ) {
            return array();
        }
        try {
            $groups = acf_get_field_groups();
        } catch ( Throwable $error ) {
            return array();
        }
        return array_values( array_map( array( __CLASS__, 'serialize_group' ), $groups ?: array() ) );
    }

    public static function get_group( string $group_key ) {
        if ( ! self::available() ) {
            return new WP_Error( 'alify_ai_acf_unavailable', 'Advanced Custom Fields is not active.', array( 'status' => 409 ) );
        }
        $group_key = sanitize_text_field( $group_key );
        try {
            $group = acf_get_field_group( $group_key );
        } catch ( Throwable $error ) {
            return new WP_Error( 'alify_ai_acf_runtime_failure', 'ACF failed while loading the field group.', array( 'status' => 500 ) );
        }
        if ( ! is_array( $group ) ) {
            return new WP_Error( 'alify_ai_group_not_found', 'ACF field group not found.', array( 'status' => 404 ) );
        }
        $out = self::serialize_group( $group );
        $out['fields'] = self::list_fields( (string) ( $group['key'] ?? $group_key ) );
        return $out;
    }

    public static function list_fields( string $group_key ): array {
        if ( ! self::available() ) {
            return array();
        }
        try {
            $fields = acf_get_fields( $group_key );
        } catch ( Throwable $error ) {
            return array();
        }
        $out = array();
        foreach ( $fields ?: array() as $field ) {
            if ( is_array( $field ) ) {
                $out[] = self::serialize_field( $field, true );
            }
        }
        return $out;
    }

    public static function get_field( string $field_key ) {
        if ( ! self::available() ) {
            return new WP_Error( 'alify_ai_acf_unavailable', 'Advanced Custom Fields is not active.', array( 'status' => 409 ) );
        }
        $field_key = sanitize_text_field( $field_key );
        try {
            $field = acf_get_field( $field_key );
        } catch ( Throwable $error ) {
            return new WP_Error( 'alify_ai_acf_runtime_failure', 'ACF failed while loading the field.', array( 'status' => 500 ) );
        }
        if ( ! is_array( $field ) ) {
            return new WP_Error( 'alify_ai_acf_field_not_found', 'ACF field not found.', array( 'status' => 404 ) );
        }
        $out = self::serialize_field( $field, true );
        $out['group_key'] = self::field_root_group_key( $field );
        return $out;
    }

    public static function get_values( int $post_id ): array {
        if ( ! self::available() || ! function_exists( 'get_fields' ) ) {
            return array();
        }
        try {
            $values = get_fields( $post_id, false );
        } catch ( Throwable $error ) {
            return array();
        }
        return is_array( $values ) ? $values : array();
    }

    public static function update_values( int $post_id, array $values ) {
        if ( ! self::available() ) {
            return new WP_Error( 'alify_ai_acf_unavailable', 'Advanced Custom Fields is not active.', array( 'status' => 409 ) );
        }
        if ( ! get_post( $post_id ) ) {
            return new WP_Error( 'alify_ai_not_found', 'Post not found.', array( 'status' => 404 ) );
        }
        if ( empty( $values ) ) {
            return new WP_Error( 'alify_ai_no_changes', 'No ACF values were supplied.', array( 'status' => 400 ) );
        }

        $prepared = array();
        $before_selected = array();
        foreach ( $values as $selector => $value ) {
            $selector = sanitize_text_field( (string) $selector );
            if ( '' === $selector ) {
                continue;
            }
            if ( function_exists( 'get_field_object' ) ) {
                try {
                    $field_object = get_field_object( $selector, $post_id, false, false );
                } catch ( Throwable $error ) {
                    return new WP_Error( 'alify_ai_acf_runtime_failure', 'ACF failed while resolving a field.', array( 'status' => 500, 'field' => $selector ) );
                }
                if ( ! is_array( $field_object ) ) {
                    return new WP_Error( 'alify_ai_acf_field_not_found', 'An ACF field selector does not exist on this post.', array( 'status' => 400, 'field' => $selector ) );
                }
            }
            if ( function_exists( 'get_field' ) ) {
                try {
                    $before_selected[ $selector ] = get_field( $selector, $post_id, false );
                } catch ( Throwable $error ) {
                    return new WP_Error( 'alify_ai_acf_runtime_failure', 'ACF failed while reading an existing field value.', array( 'status' => 500, 'field' => $selector ) );
                }
            } else {
                $before_selected[ $selector ] = null;
            }
            $prepared[ $selector ] = self::sanitize_value( $value );
        }
        if ( empty( $prepared ) ) {
            return new WP_Error( 'alify_ai_no_changes', 'No valid ACF values were supplied.', array( 'status' => 400 ) );
        }

        $before = self::get_values( $post_id );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before, $prepared );
        if ( is_wp_error( $snapshot_ok ) ) {
            return $snapshot_ok;
        }

        $updated = array();
        try {
            foreach ( $prepared as $selector => $value ) {
                $updated[ $selector ] = (bool) update_field( $selector, $value, $post_id );
            }
        } catch ( Throwable $error ) {
            $restored = self::restore_values( $post_id, $before_selected );
            if ( is_wp_error( $restored ) ) {
                ALIFY_AI_Diagnostics::log( 'acf_update_compensation_failed', array( 'post_id' => $post_id, 'error' => $restored->get_error_message() ) );
                return new WP_Error( 'alify_ai_acf_update_unknown_state', 'ACF failed while updating values and the compensating restore could not be fully verified; site state requires review.', array( 'status' => 500 ) );
            }
            return new WP_Error( 'alify_ai_acf_runtime_failure', 'ACF failed while updating field values; previous values were restored.', array( 'status' => 500 ) );
        }

        $after = self::get_values( $post_id );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before, $after );
        if ( is_wp_error( $snapshot_ok ) ) {
            $restored = self::restore_values( $post_id, $before_selected );
            if ( is_wp_error( $restored ) ) {
                ALIFY_AI_Diagnostics::log( 'acf_snapshot_compensation_failed', array( 'post_id' => $post_id, 'error' => $restored->get_error_message() ) );
                return new WP_Error( 'alify_ai_acf_update_unknown_state', 'ACF snapshot validation failed and the compensating restore could not be fully verified; site state requires review.', array( 'status' => 500 ) );
            }
            return $snapshot_ok;
        }
        $log_id = ALIFY_AI_Audit::log( 'update_acf', 'post', $post_id, $before, $after );
        if ( ! $log_id ) {
            $restored = self::restore_values( $post_id, $before_selected );
            if ( is_wp_error( $restored ) ) {
                ALIFY_AI_Diagnostics::log( 'acf_audit_compensation_failed', array( 'post_id' => $post_id, 'error' => $restored->get_error_message() ) );
                return new WP_Error( 'alify_ai_acf_update_unknown_state', 'ACF activity logging failed and the compensating restore could not be fully verified; site state requires review.', array( 'status' => 500 ) );
            }
            return new WP_Error( 'alify_ai_audit_failed', 'ACF update was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }

        return array( 'activity_id' => $log_id, 'updated' => $updated, 'values' => $after );
    }


    public static function list_option_pages(): array {
        if ( ! self::available() ) return array();
        $pages = array();
        if ( function_exists( 'acf_get_options_pages' ) ) {
            try { $registered = acf_get_options_pages(); } catch ( Throwable $error ) { $registered = array(); }
            foreach ( (array) $registered as $key => $page ) {
                if ( ! is_array( $page ) ) continue;
                $slug = sanitize_key( (string) ( $page['menu_slug'] ?? $page['page_slug'] ?? $page['slug'] ?? ( is_string( $key ) ? $key : '' ) ) );
                if ( '' === $slug ) continue;
                $pages[ $slug ] = array(
                    'slug' => $slug,
                    'title' => sanitize_text_field( (string) ( $page['page_title'] ?? $page['menu_title'] ?? $page['title'] ?? $slug ) ),
                    'post_id' => sanitize_text_field( (string) ( $page['post_id'] ?? 'option' ) ) ?: 'option',
                    'parent_slug' => sanitize_text_field( (string) ( $page['parent_slug'] ?? '' ) ),
                );
            }
        }
        try { $groups = acf_get_field_groups(); } catch ( Throwable $error ) { $groups = array(); }
        foreach ( (array) $groups as $group ) {
            if ( ! is_array( $group ) ) continue;
            foreach ( (array) ( $group['location'] ?? array() ) as $rules ) {
                foreach ( (array) $rules as $rule ) {
                    if ( ! is_array( $rule ) || 'options_page' !== (string) ( $rule['param'] ?? '' ) || '==' !== (string) ( $rule['operator'] ?? '==' ) ) continue;
                    $slug = sanitize_key( (string) ( $rule['value'] ?? '' ) );
                    if ( '' === $slug ) continue;
                    if ( ! isset( $pages[ $slug ] ) ) $pages[ $slug ] = array( 'slug'=>$slug, 'title'=>$slug, 'post_id'=>'option', 'parent_slug'=>'' );
                }
            }
        }
        foreach ( $pages as $slug => &$page ) {
            $page['field_groups'] = self::option_group_keys( $slug );
            $page['field_count'] = count( self::option_fields( $slug ) );
        }
        unset( $page );
        return array_values( $pages );
    }

    public static function get_option_values( string $page_slug ) {
        $page = self::resolve_option_page( $page_slug );
        if ( is_wp_error( $page ) ) return $page;
        $fields = self::option_fields( $page['slug'] );
        $values = array(); $meta = array();
        foreach ( $fields as $field ) {
            $name = (string) ( $field['name'] ?? '' ); $key = (string) ( $field['key'] ?? '' );
            $selector = '' !== $name ? $name : $key;
            if ( '' === $selector ) continue;
            try { $values[ $selector ] = get_field( $selector, $page['post_id'], false ); } catch ( Throwable $error ) { $values[ $selector ] = null; }
            $meta[ $selector ] = array( 'key'=>$key, 'name'=>$name, 'label'=>(string)( $field['label'] ?? '' ), 'type'=>(string)( $field['type'] ?? '' ) );
        }
        return array( 'page'=>$page, 'values'=>$values, 'fields'=>$meta );
    }

    public static function update_option_values( string $page_slug, array $values ) {
        if ( ! self::available() ) return new WP_Error( 'alify_ai_acf_unavailable', 'Advanced Custom Fields is not active.', array( 'status'=>409 ) );
        if ( empty( $values ) ) return new WP_Error( 'alify_ai_no_changes', 'No ACF option values were supplied.', array( 'status'=>400 ) );
        $page = self::resolve_option_page( $page_slug );
        if ( is_wp_error( $page ) ) return $page;
        $allowed = array();
        foreach ( self::option_fields( $page['slug'] ) as $field ) {
            foreach ( array( (string)( $field['name'] ?? '' ), (string)( $field['key'] ?? '' ) ) as $selector ) if ( '' !== $selector ) $allowed[ $selector ] = true;
        }
        $prepared = array(); $before = array();
        foreach ( $values as $selector => $value ) {
            $selector = sanitize_text_field( (string) $selector );
            if ( '' === $selector || ! isset( $allowed[ $selector ] ) ) return new WP_Error( 'alify_ai_acf_option_field_not_found', 'An ACF option field is not assigned to this options page.', array( 'status'=>400, 'field'=>$selector, 'page'=>$page['slug'] ) );
            try { $before[ $selector ] = get_field( $selector, $page['post_id'], false ); } catch ( Throwable $error ) { return new WP_Error( 'alify_ai_acf_runtime_failure', 'ACF failed while reading an option value.', array( 'status'=>500, 'field'=>$selector ) ); }
            $prepared[ $selector ] = self::sanitize_value( $value );
        }
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before, $prepared ); if ( is_wp_error( $snapshot_ok ) ) return $snapshot_ok;
        try {
            foreach ( $prepared as $selector => $value ) update_field( $selector, $value, $page['post_id'] );
        } catch ( Throwable $error ) {
            $restored = self::restore_values_for_target( $page['post_id'], $before );
            if ( is_wp_error( $restored ) ) return new WP_Error( 'alify_ai_acf_options_unknown_state', 'ACF option update failed and the previous values could not be fully restored.', array( 'status'=>500 ) );
            return new WP_Error( 'alify_ai_acf_runtime_failure', 'ACF failed while updating option values; previous values were restored.', array( 'status'=>500 ) );
        }
        $after = array();
        foreach ( $prepared as $selector => $ignored ) { try { $after[ $selector ] = get_field( $selector, $page['post_id'], false ); } catch ( Throwable $error ) { $after[ $selector ] = null; } }
        foreach ( $prepared as $selector => $expected ) {
            if ( ! self::values_equivalent( $after[ $selector ] ?? null, $expected ) ) {
                $restored = self::restore_values_for_target( $page['post_id'], $before );
                if ( is_wp_error( $restored ) ) return new WP_Error( 'alify_ai_acf_options_unknown_state', 'ACF option write verification failed and compensation could not be verified.', array( 'status'=>500 ) );
                return new WP_Error( 'alify_ai_acf_option_verify_failed', 'ACF option write could not be verified; previous values were restored.', array( 'status'=>500, 'field'=>$selector ) );
            }
        }
        $before_snapshot = array( 'page_slug'=>$page['slug'], 'post_id'=>$page['post_id'], 'values'=>$before );
        $after_snapshot = array( 'page_slug'=>$page['slug'], 'post_id'=>$page['post_id'], 'values'=>$after );
        $log_id = ALIFY_AI_Audit::log( 'update_acf_options', 'acf_options', 0, $before_snapshot, $after_snapshot );
        if ( ! $log_id ) {
            $restored = self::restore_values_for_target( $page['post_id'], $before );
            if ( is_wp_error( $restored ) ) return new WP_Error( 'alify_ai_acf_options_unknown_state', 'ACF option values changed but audit logging and compensation failed; site state requires review.', array( 'status'=>500 ) );
            return new WP_Error( 'alify_ai_audit_failed', 'ACF option update was reverted because the activity log could not be stored.', array( 'status'=>500 ) );
        }
        return array( 'activity_id'=>$log_id, 'page'=>$page, 'values'=>$after );
    }

    private static function resolve_option_page( string $page_slug ) {
        $page_slug = sanitize_key( $page_slug );
        foreach ( self::list_option_pages() as $page ) if ( ( $page['slug'] ?? '' ) === $page_slug ) return $page;
        return new WP_Error( 'alify_ai_acf_options_page_not_found', 'ACF options page not found.', array( 'status'=>404, 'page'=>$page_slug ) );
    }

    private static function option_group_keys( string $slug ): array {
        $keys = array();
        try { $groups = acf_get_field_groups(); } catch ( Throwable $error ) { $groups = array(); }
        foreach ( (array) $groups as $group ) {
            if ( ! is_array( $group ) ) continue;
            $matched = false;
            foreach ( (array) ( $group['location'] ?? array() ) as $rules ) foreach ( (array) $rules as $rule ) {
                if ( is_array( $rule ) && 'options_page' === (string)( $rule['param'] ?? '' ) && '==' === (string)( $rule['operator'] ?? '==' ) && sanitize_key( (string)( $rule['value'] ?? '' ) ) === $slug ) { $matched = true; break 2; }
            }
            if ( $matched && ! empty( $group['key'] ) ) $keys[] = (string) $group['key'];
        }
        return array_values( array_unique( $keys ) );
    }

    private static function option_fields( string $slug ): array {
        $fields = array();
        foreach ( self::option_group_keys( $slug ) as $group_key ) {
            try { $group_fields = acf_get_fields( $group_key ); } catch ( Throwable $error ) { $group_fields = array(); }
            foreach ( (array) $group_fields as $field ) if ( is_array( $field ) ) $fields[] = $field;
        }
        return $fields;
    }

    public static function create_group( array $input ) {
        if ( ! self::available() ) {
            return new WP_Error( 'alify_ai_acf_unavailable', 'ACF field-group API is unavailable.', array( 'status' => 409 ) );
        }
        $prepared = self::prepare_group( $input, null, true );
        if ( is_wp_error( $prepared ) ) {
            return $prepared;
        }
        try {
            if ( acf_get_field_group( $prepared['key'] ) ) {
                return new WP_Error( 'alify_ai_acf_group_exists', 'An ACF field group with this key already exists.', array( 'status' => 409 ) );
            }
            $saved = acf_update_field_group( $prepared );
        } catch ( Throwable $error ) {
            return new WP_Error( 'alify_ai_acf_save_failed', 'ACF failed while creating the field group.', array( 'status' => 500 ) );
        }
        if ( ! is_array( $saved ) ) {
            return new WP_Error( 'alify_ai_acf_save_failed', 'Could not create ACF field group.', array( 'status' => 500 ) );
        }
        $after = self::snapshot_group( (string) ( $saved['key'] ?? $prepared['key'] ) );
        if ( is_wp_error( $after ) ) {
            self::delete_created_acf_object( $saved, 'group' );
            return $after;
        }
        $snapshot_check = ALIFY_AI_Audit::validate_snapshot( null, $after );
        if ( is_wp_error( $snapshot_check ) ) {
            self::delete_created_acf_object( $saved, 'group' );
            return $snapshot_check;
        }
        $log_id = ALIFY_AI_Audit::log( 'create_acf_group', 'acf_group', (int) ( $saved['ID'] ?? 0 ), null, $after );
        if ( ! $log_id ) {
            self::delete_created_acf_object( $saved, 'group' );
            return new WP_Error( 'alify_ai_audit_failed', 'Could not record the ACF field-group change; the new group was removed.', array( 'status' => 500 ) );
        }
        $result = self::get_group( (string) ( $saved['key'] ?? $prepared['key'] ) );
        if ( is_wp_error( $result ) ) return $result;
        $result['activity_id'] = $log_id;
        return $result;
    }

    public static function update_group( string $group_key, array $input ) {
        if ( ! self::available() ) {
            return new WP_Error( 'alify_ai_acf_unavailable', 'ACF field-group API is unavailable.', array( 'status' => 409 ) );
        }
        $group_key = sanitize_text_field( $group_key );
        try { $current = acf_get_field_group( $group_key ); } catch ( Throwable $error ) { $current = false; }
        if ( ! is_array( $current ) ) {
            return new WP_Error( 'alify_ai_group_not_found', 'ACF field group not found.', array( 'status' => 404 ) );
        }
        $before = self::snapshot_group( $group_key );
        if ( is_wp_error( $before ) ) return $before;
        $prepared = self::prepare_group( $input, $current, false );
        if ( is_wp_error( $prepared ) ) return $prepared;
        $prepared['key'] = $group_key;
        if ( isset( $current['ID'] ) ) $prepared['ID'] = $current['ID'];
        try { $saved = acf_update_field_group( $prepared ); } catch ( Throwable $error ) { $saved = false; }
        if ( ! is_array( $saved ) ) {
            return new WP_Error( 'alify_ai_acf_save_failed', 'Could not update ACF field group.', array( 'status' => 500 ) );
        }
        $after = self::snapshot_group( $group_key );
        if ( is_wp_error( $after ) ) {
            self::restore_group_snapshot( $before );
            return $after;
        }
        $check = ALIFY_AI_Audit::validate_snapshot( $before, $after );
        if ( is_wp_error( $check ) ) { self::restore_group_snapshot( $before ); return $check; }
        $log_id = ALIFY_AI_Audit::log( 'update_acf_group', 'acf_group', (int) ( $saved['ID'] ?? 0 ), $before, $after );
        if ( ! $log_id ) {
            self::restore_group_snapshot( $before );
            return new WP_Error( 'alify_ai_audit_failed', 'ACF field-group update was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        $result = self::get_group( $group_key );
        if ( is_wp_error( $result ) ) return $result;
        $result['activity_id'] = $log_id;
        return $result;
    }

    public static function delete_group( string $group_key, array $input = array() ) {
        if ( ! self::available() || ! function_exists( 'acf_delete_field_group' ) ) {
            return new WP_Error( 'alify_ai_acf_unavailable', 'ACF field-group delete API is unavailable.', array( 'status' => 409 ) );
        }
        if ( empty( $input['confirm_delete'] ) ) {
            return new WP_Error( 'alify_ai_confirmation_required', 'confirm_delete=true is required to delete an ACF field group.', array( 'status' => 409 ) );
        }
        $group_key = sanitize_text_field( $group_key );
        $before = self::snapshot_group( $group_key );
        if ( is_wp_error( $before ) ) return $before;
        $check = ALIFY_AI_Audit::validate_snapshot( $before, null );
        if ( is_wp_error( $check ) ) return $check;
        $raw = acf_get_field_group( $group_key );
        $id = is_array( $raw ) ? absint( $raw['ID'] ?? 0 ) : 0;
        try { $deleted = acf_delete_field_group( $id ?: $group_key ); } catch ( Throwable $error ) { $deleted = false; }
        if ( false === $deleted ) {
            return new WP_Error( 'alify_ai_acf_delete_failed', 'Could not delete ACF field group.', array( 'status' => 500 ) );
        }
        try { $still = acf_get_field_group( $group_key ); } catch ( Throwable $error ) { $still = false; }
        if ( is_array( $still ) ) {
            return new WP_Error( 'alify_ai_acf_delete_failed', 'ACF field group still exists after delete attempt.', array( 'status' => 500 ) );
        }
        $log_id = ALIFY_AI_Audit::log( 'delete_acf_group', 'acf_group', $id, $before, null );
        if ( ! $log_id ) {
            $restored = self::restore_group_snapshot( $before );
            return new WP_Error( 'alify_ai_audit_failed', 'The ACF field group was restored because its delete activity could not be stored.', array( 'status' => 500, 'restored' => ! is_wp_error( $restored ) ) );
        }
        return array( 'deleted' => true, 'group_key' => $group_key, 'activity_id' => $log_id );
    }

    public static function create_field( string $group_key, array $input ) {
        if ( ! self::available() ) {
            return new WP_Error( 'alify_ai_acf_unavailable', 'ACF field API is unavailable.', array( 'status' => 409 ) );
        }
        $group_key = sanitize_text_field( $group_key );
        try { $group = acf_get_field_group( $group_key ); } catch ( Throwable $error ) { $group = false; }
        if ( ! is_array( $group ) ) {
            return new WP_Error( 'alify_ai_group_not_found', 'ACF field group not found.', array( 'status' => 404 ) );
        }
        $parent = sanitize_text_field( (string) ( $input['parent_key'] ?? $group_key ) );
        $parent_check = self::validate_parent( $group_key, $parent );
        if ( is_wp_error( $parent_check ) ) return $parent_check;

        $prepared = self::prepare_field( $input, null, $parent, true );
        if ( is_wp_error( $prepared ) ) return $prepared;
        try {
            if ( acf_get_field( $prepared['key'] ) ) {
                return new WP_Error( 'alify_ai_acf_field_exists', 'An ACF field with this key already exists.', array( 'status' => 409 ) );
            }
            $saved = acf_update_field( $prepared );
        } catch ( Throwable $error ) {
            return new WP_Error( 'alify_ai_acf_save_failed', 'ACF failed while creating the field.', array( 'status' => 500 ) );
        }
        if ( ! is_array( $saved ) ) {
            return new WP_Error( 'alify_ai_acf_save_failed', 'Could not create ACF field.', array( 'status' => 500 ) );
        }

        $created_keys = array( (string) ( $saved['key'] ?? $prepared['key'] ) );
        if ( ! empty( $input['sub_fields'] ) && is_array( $input['sub_fields'] ) ) {
            if ( ! in_array( (string) ( $saved['type'] ?? $prepared['type'] ), self::CONTAINER_TYPES, true ) ) {
                self::delete_created_acf_object( $saved, 'field' );
                return new WP_Error( 'alify_ai_acf_subfields_not_allowed', 'sub_fields are only supported for group or repeater fields.', array( 'status' => 400 ) );
            }
            foreach ( $input['sub_fields'] as $sub_input ) {
                if ( ! is_array( $sub_input ) ) continue;
                $sub_input['parent_key'] = (string) ( $saved['key'] ?? $prepared['key'] );
                $sub = self::create_field_internal( $group_key, $sub_input );
                if ( is_wp_error( $sub ) ) {
                    self::delete_field_tree_by_key( (string) ( $saved['key'] ?? $prepared['key'] ) );
                    return $sub;
                }
                $created_keys = array_merge( $created_keys, $sub['created_keys'] );
            }
        }

        $after = self::snapshot_field( (string) ( $saved['key'] ?? $prepared['key'] ) );
        if ( is_wp_error( $after ) ) {
            self::delete_field_tree_by_key( (string) ( $saved['key'] ?? $prepared['key'] ) );
            return $after;
        }
        $check = ALIFY_AI_Audit::validate_snapshot( null, $after );
        if ( is_wp_error( $check ) ) {
            self::delete_field_tree_by_key( (string) ( $saved['key'] ?? $prepared['key'] ) );
            return $check;
        }
        $log_id = ALIFY_AI_Audit::log( 'create_acf_field', 'acf_field', (int) ( $saved['ID'] ?? 0 ), null, $after );
        if ( ! $log_id ) {
            self::delete_field_tree_by_key( (string) ( $saved['key'] ?? $prepared['key'] ) );
            return new WP_Error( 'alify_ai_audit_failed', 'Could not record the ACF field change; the new field tree was removed.', array( 'status' => 500 ) );
        }
        $result = self::get_field( (string) ( $saved['key'] ?? $prepared['key'] ) );
        if ( is_wp_error( $result ) ) return $result;
        $result['activity_id'] = $log_id;
        $result['created_keys'] = array_values( array_unique( $created_keys ) );
        return $result;
    }

    private static function create_field_internal( string $group_key, array $input ) {
        $parent = sanitize_text_field( (string) ( $input['parent_key'] ?? $group_key ) );
        $parent_check = self::validate_parent( $group_key, $parent );
        if ( is_wp_error( $parent_check ) ) return $parent_check;
        $prepared = self::prepare_field( $input, null, $parent, true );
        if ( is_wp_error( $prepared ) ) return $prepared;
        try {
            if ( acf_get_field( $prepared['key'] ) ) return new WP_Error( 'alify_ai_acf_field_exists', 'An ACF field with this key already exists.', array( 'status' => 409 ) );
            $saved = acf_update_field( $prepared );
        } catch ( Throwable $error ) { $saved = false; }
        if ( ! is_array( $saved ) ) return new WP_Error( 'alify_ai_acf_save_failed', 'Could not create nested ACF field.', array( 'status' => 500 ) );
        $keys = array( (string) ( $saved['key'] ?? $prepared['key'] ) );
        if ( ! empty( $input['sub_fields'] ) && is_array( $input['sub_fields'] ) ) {
            if ( ! in_array( (string) ( $saved['type'] ?? $prepared['type'] ), self::CONTAINER_TYPES, true ) ) {
                self::delete_field_tree_by_key( (string) ( $saved['key'] ?? $prepared['key'] ) );
                return new WP_Error( 'alify_ai_acf_subfields_not_allowed', 'sub_fields are only supported for group or repeater fields.', array( 'status' => 400 ) );
            }
            foreach ( $input['sub_fields'] as $child ) {
                if ( ! is_array( $child ) ) continue;
                $child['parent_key'] = (string) ( $saved['key'] ?? $prepared['key'] );
                $res = self::create_field_internal( $group_key, $child );
                if ( is_wp_error( $res ) ) {
                    self::delete_field_tree_by_key( (string) ( $saved['key'] ?? $prepared['key'] ) );
                    return $res;
                }
                $keys = array_merge( $keys, $res['created_keys'] );
            }
        }
        return array( 'field' => $saved, 'created_keys' => $keys );
    }

    public static function update_field_definition( string $field_key, array $input ) {
        if ( ! self::available() ) return new WP_Error( 'alify_ai_acf_unavailable', 'ACF field API is unavailable.', array( 'status' => 409 ) );
        $field_key = sanitize_text_field( $field_key );
        try { $current = acf_get_field( $field_key ); } catch ( Throwable $error ) { $current = false; }
        if ( ! is_array( $current ) ) return new WP_Error( 'alify_ai_acf_field_not_found', 'ACF field not found.', array( 'status' => 404 ) );
        $before = self::snapshot_field( $field_key );
        if ( is_wp_error( $before ) ) return $before;
        $group_key = self::field_root_group_key( $current );
        if ( '' === $group_key ) return new WP_Error( 'alify_ai_acf_parent_invalid', 'Could not resolve the field group for this ACF field.', array( 'status' => 409 ) );
        $parent = array_key_exists( 'parent_key', $input ) ? sanitize_text_field( (string) $input['parent_key'] ) : (string) ( $current['parent'] ?? $group_key );
        $parent_check = self::validate_parent( $group_key, $parent, $field_key );
        if ( is_wp_error( $parent_check ) ) return $parent_check;
        if ( isset( $input['type'] ) && sanitize_key( (string) $input['type'] ) !== (string) ( $current['type'] ?? '' ) ) {
            $children = self::field_children( $field_key );
            if ( $children && empty( $input['confirm_structure_change'] ) ) {
                return new WP_Error( 'alify_ai_confirmation_required', 'Changing the type of a field with subfields requires confirm_structure_change=true.', array( 'status' => 409 ) );
            }
        }
        $prepared = self::prepare_field( $input, $current, $parent, false );
        if ( is_wp_error( $prepared ) ) return $prepared;
        $prepared['key'] = $field_key;
        if ( isset( $current['ID'] ) ) $prepared['ID'] = $current['ID'];
        try { $saved = acf_update_field( $prepared ); } catch ( Throwable $error ) { $saved = false; }
        if ( ! is_array( $saved ) ) return new WP_Error( 'alify_ai_acf_save_failed', 'Could not update ACF field.', array( 'status' => 500 ) );
        $after = self::snapshot_field( $field_key );
        if ( is_wp_error( $after ) ) { self::restore_field_snapshot( $before ); return $after; }
        $check = ALIFY_AI_Audit::validate_snapshot( $before, $after );
        if ( is_wp_error( $check ) ) { self::restore_field_snapshot( $before ); return $check; }
        $log_id = ALIFY_AI_Audit::log( 'update_acf_field', 'acf_field', (int) ( $saved['ID'] ?? 0 ), $before, $after );
        if ( ! $log_id ) {
            self::restore_field_snapshot( $before );
            return new WP_Error( 'alify_ai_audit_failed', 'ACF field update was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        $result = self::get_field( $field_key );
        if ( is_wp_error( $result ) ) return $result;
        $result['activity_id'] = $log_id;
        return $result;
    }

    public static function delete_field_definition( string $field_key, array $input = array() ) {
        if ( ! self::available() || ! function_exists( 'acf_delete_field' ) ) return new WP_Error( 'alify_ai_acf_unavailable', 'ACF field delete API is unavailable.', array( 'status' => 409 ) );
        if ( empty( $input['confirm_delete'] ) ) return new WP_Error( 'alify_ai_confirmation_required', 'confirm_delete=true is required to delete an ACF field.', array( 'status' => 409 ) );
        $field_key = sanitize_text_field( $field_key );
        $before = self::snapshot_field( $field_key );
        if ( is_wp_error( $before ) ) return $before;
        $check = ALIFY_AI_Audit::validate_snapshot( $before, null ); if ( is_wp_error( $check ) ) return $check;
        $raw = acf_get_field( $field_key );
        $id = is_array( $raw ) ? absint( $raw['ID'] ?? 0 ) : 0;
        self::delete_field_tree_by_key( $field_key );
        try { $still = acf_get_field( $field_key ); } catch ( Throwable $error ) { $still = false; }
        if ( is_array( $still ) ) return new WP_Error( 'alify_ai_acf_delete_failed', 'ACF field still exists after delete attempt.', array( 'status' => 500 ) );
        $log_id = ALIFY_AI_Audit::log( 'delete_acf_field', 'acf_field', $id, $before, null );
        if ( ! $log_id ) {
            self::restore_field_snapshot( $before );
            return new WP_Error( 'alify_ai_audit_failed', 'The ACF field was restored because its delete activity could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'deleted' => true, 'field_key' => $field_key, 'activity_id' => $log_id );
    }

    public static function rollback_activity( array $entry ) {
        $action = (string) ( $entry['action'] ?? '' );
        if ( 'create_acf_group' === $action && is_array( $entry['after_json'] ) ) {
            $key = sanitize_text_field( (string) ( $entry['after_json']['group']['key'] ?? '' ) );
            if ( '' === $key ) return new WP_Error( 'alify_ai_rollback_failed', 'Missing ACF group key in rollback snapshot.', array( 'status' => 409 ) );
            $current = self::snapshot_group( $key );
            if ( is_wp_error( $current ) ) return $current;
            $raw = acf_get_field_group( $key );
            try { acf_delete_field_group( absint( $raw['ID'] ?? 0 ) ?: $key ); } catch ( Throwable $error ) { return new WP_Error( 'alify_ai_rollback_failed', 'Could not remove created ACF field group.', array( 'status' => 500 ) ); }
            $log_id = ALIFY_AI_Audit::log( 'rollback_create_acf_group', 'acf_group', 0, $current, null );
            if ( ! $log_id ) { self::restore_group_snapshot( $current ); return new WP_Error( 'alify_ai_audit_failed', 'Rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) ); }
            return array( 'message' => 'Created ACF field group removed.', 'activity_id' => $log_id );
        }
        if ( 'update_acf_group' === $action && is_array( $entry['before_json'] ) ) {
            $current = self::snapshot_group( (string) ( $entry['before_json']['group']['key'] ?? '' ) );
            $restored = self::restore_group_snapshot( $entry['before_json'] );
            if ( is_wp_error( $restored ) ) return $restored;
            $log_id = ALIFY_AI_Audit::log( 'rollback_update_acf_group', 'acf_group', 0, is_wp_error( $current ) ? null : $current, $restored );
            if ( ! $log_id ) {
                if ( is_array( $current ) ) self::restore_group_snapshot( $current );
                return new WP_Error( 'alify_ai_audit_failed', 'ACF field-group rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) );
            }
            return array( 'message' => 'ACF field group update rolled back.', 'activity_id' => $log_id, 'group' => $restored );
        }
        if ( 'delete_acf_group' === $action && is_array( $entry['before_json'] ) ) {
            $restored = self::restore_group_snapshot( $entry['before_json'] ); if ( is_wp_error( $restored ) ) return $restored;
            $log_id = ALIFY_AI_Audit::log( 'rollback_delete_acf_group', 'acf_group', 0, null, $restored );
            if ( ! $log_id ) {
                $raw = acf_get_field_group( (string) ( $entry['before_json']['group']['key'] ?? '' ) );
                if ( is_array( $raw ) && function_exists( 'acf_delete_field_group' ) ) {
                    try { acf_delete_field_group( absint( $raw['ID'] ?? 0 ) ?: (string) $raw['key'] ); } catch ( Throwable $error ) { /* best effort */ }
                }
                return new WP_Error( 'alify_ai_audit_failed', 'ACF field-group recreation was reverted because its rollback log could not be stored.', array( 'status' => 500 ) );
            }
            return array( 'message' => 'Deleted ACF field group restored.', 'activity_id' => $log_id, 'group' => $restored );
        }
        if ( 'create_acf_field' === $action && is_array( $entry['after_json'] ) ) {
            $key = sanitize_text_field( (string) ( $entry['after_json']['key'] ?? '' ) );
            if ( '' === $key ) return new WP_Error( 'alify_ai_rollback_failed', 'Missing ACF field key in rollback snapshot.', array( 'status' => 409 ) );
            $current = self::snapshot_field( $key ); if ( is_wp_error( $current ) ) return $current;
            self::delete_field_tree_by_key( $key );
            $log_id = ALIFY_AI_Audit::log( 'rollback_create_acf_field', 'acf_field', 0, $current, null );
            if ( ! $log_id ) { self::restore_field_snapshot( $current ); return new WP_Error( 'alify_ai_audit_failed', 'Rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) ); }
            return array( 'message' => 'Created ACF field tree removed.', 'activity_id' => $log_id );
        }
        if ( 'update_acf_field' === $action && is_array( $entry['before_json'] ) ) {
            $key = sanitize_text_field( (string) ( $entry['before_json']['key'] ?? '' ) );
            $current = '' !== $key ? self::snapshot_field( $key ) : null;
            $restored = self::restore_field_snapshot( $entry['before_json'] ); if ( is_wp_error( $restored ) ) return $restored;
            $log_id = ALIFY_AI_Audit::log( 'rollback_update_acf_field', 'acf_field', 0, is_wp_error( $current ) ? null : $current, $restored );
            if ( ! $log_id ) {
                if ( is_array( $current ) ) self::restore_field_snapshot( $current );
                return new WP_Error( 'alify_ai_audit_failed', 'ACF field rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) );
            }
            return array( 'message' => 'ACF field update rolled back.', 'activity_id' => $log_id, 'field' => $restored );
        }
        if ( 'delete_acf_field' === $action && is_array( $entry['before_json'] ) ) {
            $restored = self::restore_field_snapshot( $entry['before_json'] ); if ( is_wp_error( $restored ) ) return $restored;
            $log_id = ALIFY_AI_Audit::log( 'rollback_delete_acf_field', 'acf_field', 0, null, $restored );
            if ( ! $log_id ) {
                self::delete_field_tree_by_key( (string) ( $entry['before_json']['key'] ?? '' ) );
                return new WP_Error( 'alify_ai_audit_failed', 'ACF field recreation was reverted because its rollback log could not be stored.', array( 'status' => 500 ) );
            }
            return array( 'message' => 'Deleted ACF field restored.', 'activity_id' => $log_id, 'field' => $restored );
        }
        if ( 'update_acf' === $action && is_array( $entry['before_json'] ) && is_array( $entry['after_json'] ) ) {
            $post_id = absint( $entry['object_id'] ?? 0 );
            if ( ! $post_id || ! get_post( $post_id ) ) return new WP_Error( 'alify_ai_not_found', 'The post for this ACF rollback no longer exists.', array( 'status' => 404 ) );
            $current = self::get_values( $post_id );
            if ( ! self::values_equivalent( $current, $entry['after_json'] ) ) {
                return new WP_Error( 'alify_ai_rollback_conflict', 'ACF values changed after this activity. Create a fresh change instead of overwriting newer values.', array( 'status' => 409 ) );
            }
            $restored_ok = self::restore_values( $post_id, $entry['before_json'] );
            if ( is_wp_error( $restored_ok ) ) return $restored_ok;
            $restored = self::get_values( $post_id );
            if ( ! self::values_equivalent( $restored, $entry['before_json'] ) ) {
                self::restore_values( $post_id, $current );
                return new WP_Error( 'alify_ai_acf_restore_verify_failed', 'ACF rollback could not be verified; the previous current values were restored where possible.', array( 'status' => 500 ) );
            }
            $log_id = ALIFY_AI_Audit::log( 'rollback_update_acf', 'post', $post_id, $current, $restored );
            if ( ! $log_id ) {
                $compensated = self::restore_values( $post_id, $current );
                if ( is_wp_error( $compensated ) ) {
                    ALIFY_AI_Diagnostics::log( 'acf_rollback_compensation_failed', array( 'post_id' => $post_id, 'error' => $compensated->get_error_message() ) );
                    return new WP_Error( 'alify_ai_acf_rollback_unknown_state', 'ACF values were rolled back but audit logging and compensation both failed; site state requires review.', array( 'status' => 500 ) );
                }
                return new WP_Error( 'alify_ai_audit_failed', 'ACF rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) );
            }
            return array( 'message' => 'ACF values rolled back.', 'activity_id' => $log_id, 'values' => $restored );
        }
        if ( 'update_acf_options' === $action && is_array( $entry['before_json'] ) && is_array( $entry['after_json'] ) ) {
            $before = $entry['before_json']; $after = $entry['after_json'];
            $post_id = (string) ( $before['post_id'] ?? 'option' );
            $before_values = is_array( $before['values'] ?? null ) ? $before['values'] : array();
            $after_values = is_array( $after['values'] ?? null ) ? $after['values'] : array();
            $current = array();
            foreach ( $after_values as $selector => $ignored ) { try { $current[ $selector ] = get_field( $selector, $post_id, false ); } catch ( Throwable $error ) { $current[ $selector ] = null; } }
            if ( ! self::values_equivalent( $current, $after_values ) ) return new WP_Error( 'alify_ai_rollback_conflict', 'ACF option values changed after this activity. Create a fresh change instead of overwriting newer values.', array( 'status'=>409 ) );
            $restored = self::restore_values_for_target( $post_id, $before_values ); if ( is_wp_error( $restored ) ) return $restored;
            $log_id = ALIFY_AI_Audit::log( 'rollback_update_acf_options', 'acf_options', 0, $after, $before );
            if ( ! $log_id ) {
                $comp = self::restore_values_for_target( $post_id, $current );
                if ( is_wp_error( $comp ) ) return new WP_Error( 'alify_ai_acf_options_unknown_state', 'ACF options rollback succeeded but rollback logging and compensation failed; site state requires review.', array( 'status'=>500 ) );
                return new WP_Error( 'alify_ai_audit_failed', 'ACF options rollback was reverted because its activity log could not be stored.', array( 'status'=>500 ) );
            }
            return array( 'message'=>'ACF option values rolled back.', 'activity_id'=>$log_id, 'values'=>$before_values );
        }
        return new WP_Error( 'alify_ai_rollback_unsupported', 'Rollback is not supported for this ACF activity.', array( 'status' => 400 ) );
    }

    private static function prepare_group( array $input, ?array $current, bool $creating ) {
        $base = is_array( $current ) ? $current : array();
        $title = array_key_exists( 'title', $input ) ? sanitize_text_field( (string) $input['title'] ) : sanitize_text_field( (string) ( $base['title'] ?? '' ) );
        if ( '' === $title ) return new WP_Error( 'alify_ai_title_required', 'Field group title is required.', array( 'status' => 400 ) );
        $key = sanitize_key( (string) ( $base['key'] ?? ( $input['key'] ?? '' ) ) );
        if ( $creating && ( '' === $key || ! str_starts_with( $key, 'group_' ) ) ) $key = 'group_alify_' . substr( md5( $title . microtime( true ) ), 0, 12 );
        if ( '' === $key || ! str_starts_with( $key, 'group_' ) ) return new WP_Error( 'alify_ai_invalid_group_key', 'ACF field group keys must start with group_.', array( 'status' => 400 ) );
        $location_source = array_key_exists( 'location', $input ) ? $input['location'] : ( $base['location'] ?? array() );
        $location = self::sanitize_location( $location_source );
        if ( empty( $location ) ) return new WP_Error( 'alify_ai_location_required', 'A valid ACF location rule is required.', array( 'status' => 400 ) );
        $group = array(
            'key' => $key,
            'title' => $title,
            'location' => $location,
            'menu_order' => self::pick_int( $input, $base, 'menu_order', 0 ),
            'position' => self::pick_enum( $input, $base, 'position', array( 'normal','side','acf_after_title' ), 'normal' ),
            'style' => self::pick_enum( $input, $base, 'style', array( 'default','seamless' ), 'default' ),
            'label_placement' => self::pick_enum( $input, $base, 'label_placement', array( 'top','left' ), 'top' ),
            'instruction_placement' => self::pick_enum( $input, $base, 'instruction_placement', array( 'label','field' ), 'label' ),
            'hide_on_screen' => self::sanitize_string_list( array_key_exists( 'hide_on_screen', $input ) ? $input['hide_on_screen'] : ( $base['hide_on_screen'] ?? array() ) ),
            'active' => self::pick_bool( $input, $base, 'active', true ),
            'description' => sanitize_textarea_field( (string) ( array_key_exists( 'description', $input ) ? $input['description'] : ( $base['description'] ?? '' ) ) ),
            'show_in_rest' => self::pick_bool( $input, $base, 'show_in_rest', false ) ? 1 : 0,
        );
        return $group;
    }

    private static function prepare_field( array $input, ?array $current, string $parent, bool $creating ) {
        $base = is_array( $current ) ? $current : array();
        $label = array_key_exists( 'label', $input ) ? sanitize_text_field( (string) $input['label'] ) : sanitize_text_field( (string) ( $base['label'] ?? '' ) );
        $name  = array_key_exists( 'name', $input ) ? sanitize_key( (string) $input['name'] ) : sanitize_key( (string) ( $base['name'] ?? '' ) );
        $type  = array_key_exists( 'type', $input ) ? sanitize_key( (string) $input['type'] ) : sanitize_key( (string) ( $base['type'] ?? 'text' ) );
        if ( '' === $label || '' === $name || ! in_array( $type, self::FIELD_TYPES, true ) ) return new WP_Error( 'alify_ai_invalid_field', 'Field label/name are required and field type must be supported.', array( 'status' => 400 ) );
        if ( 'repeater' === $type && defined( 'ACF_PRO' ) && ! ACF_PRO ) return new WP_Error( 'alify_ai_acf_pro_required', 'Repeater fields require ACF PRO.', array( 'status' => 409 ) );
        $key = sanitize_key( (string) ( $base['key'] ?? ( $input['key'] ?? '' ) ) );
        if ( $creating && ( '' === $key || ! str_starts_with( $key, 'field_' ) ) ) $key = 'field_alify_' . substr( md5( $parent . $name . microtime( true ) ), 0, 12 );
        if ( '' === $key || ! str_starts_with( $key, 'field_' ) ) return new WP_Error( 'alify_ai_invalid_field_key', 'ACF field keys must start with field_.', array( 'status' => 400 ) );
        $name_check = self::validate_sibling_name( $parent, $name, $key );
        if ( is_wp_error( $name_check ) ) return $name_check;
        $root_group = str_starts_with( $parent, 'group_' ) ? $parent : '';
        if ( '' === $root_group ) {
            try { $parent_field = acf_get_field( $parent ); } catch ( Throwable $error ) { $parent_field = false; }
            if ( is_array( $parent_field ) ) $root_group = self::field_root_group_key( $parent_field );
        }
        $field = array(
            'key' => $key,
            'parent' => $parent,
            'label' => $label,
            'name' => $name,
            'type' => $type,
            'instructions' => sanitize_textarea_field( (string) ( array_key_exists( 'instructions', $input ) ? $input['instructions'] : ( $base['instructions'] ?? '' ) ) ),
            'required' => self::pick_bool( $input, $base, 'required', false ) ? 1 : 0,
            'conditional_logic' => self::sanitize_conditional_logic( array_key_exists( 'conditional_logic', $input ) ? $input['conditional_logic'] : ( $base['conditional_logic'] ?? 0 ), $key, $root_group ),
            'wrapper' => self::sanitize_wrapper( array_key_exists( 'wrapper', $input ) ? $input['wrapper'] : ( $base['wrapper'] ?? array() ) ),
        );
        if ( is_wp_error( $field['conditional_logic'] ) ) return $field['conditional_logic'];
        self::apply_field_settings( $field, $input, $base, $type );
        return $field;
    }

    private static function apply_field_settings( array &$field, array $input, array $base, string $type ): void {
        $string_keys = array( 'default_value','placeholder','prepend','append','message','ui_on_text','ui_off_text','display_format','return_format','first_day','layout','button_label','collapsed','preview_size','library','mime_types','tabs','toolbar','new_lines','field_type' );
        foreach ( $string_keys as $key ) {
            if ( array_key_exists( $key, $input ) || array_key_exists( $key, $base ) ) {
                $value = array_key_exists( $key, $input ) ? $input[ $key ] : $base[ $key ];
                $field[ $key ] = sanitize_text_field( (string) $value );
            }
        }
        $int_keys = array( 'maxlength','rows','min','max','step','min_width','min_height','max_width','max_height','min_size','max_size','rows_per_page' );
        foreach ( $int_keys as $key ) {
            if ( array_key_exists( $key, $input ) || array_key_exists( $key, $base ) ) {
                $value = array_key_exists( $key, $input ) ? $input[ $key ] : $base[ $key ];
                $field[ $key ] = is_numeric( $value ) ? 0 + $value : '';
            }
        }
        $bool_keys = array( 'readonly','disabled','allow_null','multiple','ui','ajax','toggle','allow_custom','save_custom','other_choice','save_other_choice','media_upload','delay','pagination','add_term','save_terms','load_terms' );
        foreach ( $bool_keys as $key ) {
            if ( array_key_exists( $key, $input ) || array_key_exists( $key, $base ) ) $field[ $key ] = self::pick_bool( $input, $base, $key, false ) ? 1 : 0;
        }
        if ( in_array( $type, array( 'select','checkbox','radio','button_group' ), true ) ) {
            $source = array_key_exists( 'choices', $input ) ? $input['choices'] : ( $base['choices'] ?? array() );
            $field['choices'] = self::sanitize_choices( $source );
        }
        foreach ( array( 'post_type','taxonomy','role','filters','elements' ) as $key ) {
            if ( array_key_exists( $key, $input ) || array_key_exists( $key, $base ) ) {
                $field[ $key ] = self::sanitize_string_list( array_key_exists( $key, $input ) ? $input[ $key ] : $base[ $key ] );
            }
        }
        if ( 'taxonomy' === $type && isset( $field['taxonomy'] ) && is_array( $field['taxonomy'] ) ) $field['taxonomy'] = (string) reset( $field['taxonomy'] );
        if ( 'repeater' === $type ) {
            $layout = (string) ( $field['layout'] ?? 'table' );
            $field['layout'] = in_array( $layout, array( 'table','block','row' ), true ) ? $layout : 'table';
            if ( ! isset( $field['button_label'] ) ) $field['button_label'] = 'Add Row';
        }
        if ( 'group' === $type ) {
            $layout = (string) ( $field['layout'] ?? 'block' );
            $field['layout'] = in_array( $layout, array( 'block','table','row' ), true ) ? $layout : 'block';
        }
    }

    private static function validate_sibling_name( string $parent, string $name, string $editing_key = '' ) {
        try { $siblings = acf_get_fields( $parent ); } catch ( Throwable $error ) { $siblings = array(); }
        foreach ( is_array( $siblings ) ? $siblings : array() as $sibling ) {
            if ( ! is_array( $sibling ) ) continue;
            if ( (string) ( $sibling['key'] ?? '' ) === $editing_key ) continue;
            if ( (string) ( $sibling['name'] ?? '' ) === $name ) return new WP_Error( 'alify_ai_acf_duplicate_field_name', 'Another ACF field with this name already exists under the same parent.', array( 'status' => 409, 'name' => $name ) );
        }
        return true;
    }

    private static function validate_parent( string $group_key, string $parent, string $editing_key = '' ) {
        if ( $parent === $group_key ) return true;
        if ( $parent === $editing_key ) return new WP_Error( 'alify_ai_acf_parent_invalid', 'A field cannot be its own parent.', array( 'status' => 400 ) );
        try { $parent_field = acf_get_field( $parent ); } catch ( Throwable $error ) { $parent_field = false; }
        if ( ! is_array( $parent_field ) ) return new WP_Error( 'alify_ai_acf_parent_not_found', 'Parent ACF field not found.', array( 'status' => 404 ) );
        if ( ! in_array( (string) ( $parent_field['type'] ?? '' ), self::CONTAINER_TYPES, true ) ) return new WP_Error( 'alify_ai_acf_parent_invalid', 'Nested ACF fields can only be added under group or repeater fields.', array( 'status' => 400 ) );
        if ( self::field_root_group_key( $parent_field ) !== $group_key ) return new WP_Error( 'alify_ai_acf_parent_invalid', 'Parent field belongs to a different field group.', array( 'status' => 400 ) );
        $seen = array(); $cursor = $parent_field;
        while ( is_array( $cursor ) && str_starts_with( (string) ( $cursor['parent'] ?? '' ), 'field_' ) ) {
            $pk = (string) $cursor['parent'];
            if ( $pk === $editing_key ) return new WP_Error( 'alify_ai_acf_parent_cycle', 'This parent change would create a field hierarchy cycle.', array( 'status' => 400 ) );
            if ( isset( $seen[ $pk ] ) ) break;
            $seen[ $pk ] = true;
            $cursor = acf_get_field( $pk );
        }
        return true;
    }

    private static function field_root_group_key( array $field ): string {
        $parent = (string) ( $field['parent'] ?? '' );
        $seen = array();
        while ( str_starts_with( $parent, 'field_' ) ) {
            if ( isset( $seen[ $parent ] ) ) return '';
            $seen[ $parent ] = true;
            try { $p = acf_get_field( $parent ); } catch ( Throwable $error ) { return ''; }
            if ( ! is_array( $p ) ) return '';
            $parent = (string) ( $p['parent'] ?? '' );
        }
        return str_starts_with( $parent, 'group_' ) ? $parent : '';
    }

    private static function field_children( string $field_key ): array {
        try { $children = acf_get_fields( $field_key ); } catch ( Throwable $error ) { $children = array(); }
        return is_array( $children ) ? $children : array();
    }

    private static function snapshot_group( string $group_key ) {
        try { $group = acf_get_field_group( $group_key ); } catch ( Throwable $error ) { $group = false; }
        if ( ! is_array( $group ) ) return new WP_Error( 'alify_ai_group_not_found', 'ACF field group not found.', array( 'status' => 404 ) );
        return array( 'group' => self::serialize_group( $group ), 'fields' => self::list_fields( (string) ( $group['key'] ?? $group_key ) ) );
    }

    private static function snapshot_field( string $field_key ) {
        try { $field = acf_get_field( $field_key ); } catch ( Throwable $error ) { $field = false; }
        if ( ! is_array( $field ) ) return new WP_Error( 'alify_ai_acf_field_not_found', 'ACF field not found.', array( 'status' => 404 ) );
        return self::serialize_field( $field, true );
    }

    private static function restore_group_snapshot( array $snapshot ) {
        if ( empty( $snapshot['group'] ) || ! is_array( $snapshot['group'] ) ) return new WP_Error( 'alify_ai_restore_invalid', 'Invalid ACF group snapshot.', array( 'status' => 500 ) );
        $group = self::group_restore_payload( $snapshot['group'] );
        try {
            $existing = acf_get_field_group( $group['key'] );
            if ( is_array( $existing ) && isset( $existing['ID'] ) ) $group['ID'] = $existing['ID'];
            $saved = acf_update_field_group( $group );
        } catch ( Throwable $error ) { $saved = false; }
        if ( ! is_array( $saved ) ) return new WP_Error( 'alify_ai_restore_failed', 'Could not restore ACF field group.', array( 'status' => 500 ) );
        foreach ( (array) ( $snapshot['fields'] ?? array() ) as $field_snapshot ) {
            $restored = self::restore_field_snapshot( $field_snapshot );
            if ( is_wp_error( $restored ) ) return $restored;
        }
        return self::get_group( (string) $group['key'] );
    }

    private static function restore_field_snapshot( array $snapshot ) {
        $payload = self::field_restore_payload( $snapshot );
        if ( is_wp_error( $payload ) ) return $payload;
        $children = is_array( $snapshot['sub_fields'] ?? null ) ? $snapshot['sub_fields'] : array();
        unset( $payload['sub_fields'] );
        try {
            $existing = acf_get_field( $payload['key'] );
            if ( is_array( $existing ) && isset( $existing['ID'] ) ) $payload['ID'] = $existing['ID'];
            $saved = acf_update_field( $payload );
        } catch ( Throwable $error ) { $saved = false; }
        if ( ! is_array( $saved ) ) return new WP_Error( 'alify_ai_restore_failed', 'Could not restore ACF field.', array( 'status' => 500 ) );
        foreach ( $children as $child ) {
            if ( ! is_array( $child ) ) continue;
            $child['parent'] = (string) $payload['key'];
            $restored = self::restore_field_snapshot( $child );
            if ( is_wp_error( $restored ) ) return $restored;
        }
        return self::get_field( (string) $payload['key'] );
    }

    private static function delete_field_tree_by_key( string $field_key ): void {
        try {
            $field = acf_get_field( $field_key );
            if ( ! is_array( $field ) ) return;
            foreach ( self::field_children( $field_key ) as $child ) {
                if ( is_array( $child ) && ! empty( $child['key'] ) ) self::delete_field_tree_by_key( (string) $child['key'] );
            }
            if ( function_exists( 'acf_delete_field' ) ) acf_delete_field( absint( $field['ID'] ?? 0 ) ?: $field_key );
        } catch ( Throwable $error ) {
            // Best-effort cleanup.
        }
    }

    private static function delete_created_acf_object( array $saved, string $kind ): void {
        $id = absint( $saved['ID'] ?? 0 );
        try {
            if ( 'group' === $kind && function_exists( 'acf_delete_field_group' ) ) { acf_delete_field_group( $id ?: ( $saved['key'] ?? 0 ) ); return; }
            if ( 'field' === $kind && function_exists( 'acf_delete_field' ) ) { acf_delete_field( $id ?: ( $saved['key'] ?? 0 ) ); return; }
            if ( $id > 0 ) wp_delete_post( $id, true );
        } catch ( Throwable $error ) {
            // Best-effort compensation after audit/storage failure.
        }
    }

    private static function serialize_group( array $group ): array {
        return array(
            'ID' => absint( $group['ID'] ?? 0 ), 'key' => (string) ( $group['key'] ?? '' ), 'title' => (string) ( $group['title'] ?? '' ),
            'active' => ! empty( $group['active'] ), 'location' => is_array( $group['location'] ?? null ) ? $group['location'] : array(),
            'menu_order' => (int) ( $group['menu_order'] ?? 0 ), 'position' => (string) ( $group['position'] ?? 'normal' ), 'style' => (string) ( $group['style'] ?? 'default' ),
            'label_placement' => (string) ( $group['label_placement'] ?? 'top' ), 'instruction_placement' => (string) ( $group['instruction_placement'] ?? 'label' ),
            'hide_on_screen' => is_array( $group['hide_on_screen'] ?? null ) ? array_values( $group['hide_on_screen'] ) : array(),
            'description' => (string) ( $group['description'] ?? '' ), 'show_in_rest' => ! empty( $group['show_in_rest'] ),
        );
    }

    private static function serialize_field( array $field, bool $recursive = false ): array {
        $keys = array( 'key','label','name','type','instructions','required','conditional_logic','wrapper','parent','default_value','placeholder','prepend','append','maxlength','rows','new_lines','min','max','step','readonly','disabled','choices','allow_null','multiple','ui','ajax','toggle','allow_custom','save_custom','layout','other_choice','save_other_choice','message','ui_on_text','ui_off_text','display_format','return_format','first_day','preview_size','library','mime_types','tabs','toolbar','media_upload','delay','button_label','collapsed','pagination','rows_per_page','post_type','taxonomy','role','filters','elements','field_type','add_term','save_terms','load_terms','min_width','min_height','max_width','max_height','min_size','max_size' );
        $out = array( 'ID' => absint( $field['ID'] ?? 0 ) );
        foreach ( $keys as $key ) if ( array_key_exists( $key, $field ) ) $out[ $key ] = self::snapshot_scalar( $field[ $key ] );
        if ( $recursive ) {
            $children = array();
            if ( is_array( $field['sub_fields'] ?? null ) ) $children = $field['sub_fields'];
            elseif ( ! empty( $field['key'] ) && in_array( (string) ( $field['type'] ?? '' ), self::CONTAINER_TYPES, true ) ) $children = self::field_children( (string) $field['key'] );
            if ( $children ) {
                $out['sub_fields'] = array();
                foreach ( $children as $child ) if ( is_array( $child ) ) $out['sub_fields'][] = self::serialize_field( $child, true );
            }
        }
        return $out;
    }

    private static function group_restore_payload( array $group ): array {
        $payload = $group; unset( $payload['ID'], $payload['fields'] );
        $payload['active'] = ! empty( $payload['active'] ) ? 1 : 0; $payload['show_in_rest'] = ! empty( $payload['show_in_rest'] ) ? 1 : 0;
        return $payload;
    }

    private static function field_restore_payload( array $field ) {
        if ( empty( $field['key'] ) || empty( $field['parent'] ) || empty( $field['type'] ) ) return new WP_Error( 'alify_ai_restore_invalid', 'Invalid ACF field snapshot.', array( 'status' => 500 ) );
        $payload = $field; unset( $payload['ID'] );
        return $payload;
    }

    private static function sanitize_location( $location ): array {
        if ( ! is_array( $location ) ) return array();
        $out = array();
        foreach ( $location as $group ) {
            if ( ! is_array( $group ) ) continue;
            $clean_group = array();
            foreach ( $group as $rule ) {
                if ( ! is_array( $rule ) ) continue;
                $param = sanitize_key( (string) ( $rule['param'] ?? '' ) );
                $operator = (string) ( $rule['operator'] ?? '==' );
                $value = sanitize_text_field( (string) ( $rule['value'] ?? '' ) );
                if ( '' !== $param && '' !== $value && in_array( $operator, array( '==','!=' ), true ) ) $clean_group[] = array( 'param' => $param, 'operator' => $operator, 'value' => $value );
            }
            if ( $clean_group ) $out[] = $clean_group;
        }
        return $out;
    }

    private static function sanitize_conditional_logic( $logic, string $self_key = '', string $root_group = '' ) {
        if ( empty( $logic ) ) return 0;
        if ( ! is_array( $logic ) ) return new WP_Error( 'alify_ai_acf_conditional_invalid', 'conditional_logic must be an array of OR groups.', array( 'status' => 400 ) );
        $allowed_ops = array( '==','!=','>','<','>=','<=','==contains','==pattern','==empty','!=empty' );
        $out = array();
        foreach ( $logic as $and_group ) {
            if ( ! is_array( $and_group ) ) continue;
            $clean_group = array();
            foreach ( $and_group as $rule ) {
                if ( ! is_array( $rule ) ) continue;
                $field_key = sanitize_key( (string) ( $rule['field'] ?? '' ) );
                $operator = sanitize_text_field( (string) ( $rule['operator'] ?? '==' ) );
                if ( '' === $field_key || $field_key === $self_key || ! str_starts_with( $field_key, 'field_' ) ) return new WP_Error( 'alify_ai_acf_conditional_invalid', 'Conditional logic must reference another ACF field key.', array( 'status' => 400 ) );
                try { $exists = acf_get_field( $field_key ); } catch ( Throwable $error ) { $exists = false; }
                if ( ! is_array( $exists ) ) return new WP_Error( 'alify_ai_acf_conditional_field_missing', 'Conditional logic references a field that does not exist.', array( 'status' => 400, 'field' => $field_key ) );
                if ( '' !== $root_group && self::field_root_group_key( $exists ) !== $root_group ) return new WP_Error( 'alify_ai_acf_conditional_group_mismatch', 'Conditional logic can only reference fields in the same ACF field group.', array( 'status' => 400, 'field' => $field_key ) );
                if ( ! in_array( $operator, $allowed_ops, true ) ) return new WP_Error( 'alify_ai_acf_conditional_invalid', 'Unsupported ACF conditional-logic operator.', array( 'status' => 400, 'operator' => $operator ) );
                $clean_group[] = array( 'field' => $field_key, 'operator' => $operator, 'value' => sanitize_text_field( (string) ( $rule['value'] ?? '' ) ) );
            }
            if ( $clean_group ) $out[] = $clean_group;
        }
        return $out ?: 0;
    }

    private static function sanitize_wrapper( $wrapper ): array {
        $wrapper = is_array( $wrapper ) ? $wrapper : array();
        $width = preg_replace( '/[^0-9.%]/', '', (string) ( $wrapper['width'] ?? '' ) );
        $classes = preg_split( '/\s+/', trim( (string) ( $wrapper['class'] ?? '' ) ) );
        $classes = array_values( array_filter( array_map( 'sanitize_html_class', is_array( $classes ) ? $classes : array() ) ) );
        return array( 'width' => $width, 'class' => implode( ' ', $classes ), 'id' => sanitize_html_class( (string) ( $wrapper['id'] ?? '' ) ) );
    }

    private static function sanitize_choices( $choices ): array {
        if ( ! is_array( $choices ) ) return array();
        $out = array();
        foreach ( $choices as $key => $label ) $out[ sanitize_text_field( (string) $key ) ] = sanitize_text_field( (string) $label );
        return $out;
    }

    private static function sanitize_string_list( $value ): array {
        if ( is_string( $value ) || is_numeric( $value ) ) $value = array( $value );
        if ( ! is_array( $value ) ) return array();
        $out = array(); foreach ( $value as $item ) { $item = sanitize_text_field( (string) $item ); if ( '' !== $item ) $out[] = $item; }
        return array_values( array_unique( $out ) );
    }

    private static function pick_bool( array $input, array $base, string $key, bool $default ): bool {
        return array_key_exists( $key, $input ) ? (bool) $input[ $key ] : ( array_key_exists( $key, $base ) ? (bool) $base[ $key ] : $default );
    }

    private static function pick_int( array $input, array $base, string $key, int $default ): int {
        return array_key_exists( $key, $input ) ? (int) $input[ $key ] : ( array_key_exists( $key, $base ) ? (int) $base[ $key ] : $default );
    }

    private static function pick_enum( array $input, array $base, string $key, array $allowed, string $default ): string {
        $value = array_key_exists( $key, $input ) ? (string) $input[ $key ] : (string) ( $base[ $key ] ?? $default );
        return in_array( $value, $allowed, true ) ? $value : $default;
    }

    private static function restore_values( int $post_id, array $values ) {
        return self::restore_values_for_target( $post_id, $values );
    }

    private static function restore_values_for_target( $post_id, array $values ) {
        $failed = array();
        foreach ( $values as $selector => $value ) {
            $selector = (string) $selector;
            if ( '' === $selector ) continue;
            try {
                update_field( $selector, $value, $post_id );
                if ( function_exists( 'get_field' ) ) {
                    $actual = get_field( $selector, $post_id, false );
                    if ( ! self::values_equivalent( $actual, $value ) ) $failed[] = $selector;
                }
            } catch ( Throwable $error ) {
                $failed[] = $selector;
                ALIFY_AI_Diagnostics::log( 'acf_restore_value_failed', array( 'post_id' => $post_id, 'field' => $selector, 'error' => $error->getMessage() ) );
            }
        }
        if ( $failed ) {
            return new WP_Error( 'alify_ai_acf_restore_failed', 'One or more ACF values could not be restored.', array( 'status' => 500, 'fields' => array_values( array_unique( $failed ) ) ) );
        }
        return true;
    }

    private static function values_equivalent( $left, $right ): bool {
        return self::normalize_value_for_compare( $left ) === self::normalize_value_for_compare( $right );
    }

    private static function normalize_value_for_compare( $value ) {
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $key => $item ) $out[ $key ] = self::normalize_value_for_compare( $item );
            if ( array_keys( $out ) !== range( 0, max( -1, count( $out ) - 1 ) ) ) ksort( $out );
            return $out;
        }
        if ( is_object( $value ) ) return self::normalize_value_for_compare( get_object_vars( $value ) );
        if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) return $value;
        return (string) $value;
    }

    private static function snapshot_scalar( $value ) {
        if ( is_array( $value ) ) { $out = array(); foreach ( $value as $k => $v ) $out[ $k ] = self::snapshot_scalar( $v ); return $out; }
        if ( is_scalar( $value ) || null === $value ) return $value;
        return null;
    }

    private static function sanitize_value( $value ) {
        if ( is_array( $value ) ) {
            $clean = array();
            foreach ( $value as $key => $item ) $clean[ is_string( $key ) ? sanitize_key( $key ) : $key ] = self::sanitize_value( $item );
            return $clean;
        }
        if ( is_string( $value ) ) return wp_kses_post( $value );
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) return $value;
        return sanitize_text_field( (string) $value );
    }
}
