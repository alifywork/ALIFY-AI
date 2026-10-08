<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ALIFY_AI_Elementor {
    private const MAX_ELEMENTS = 5000;
    private const MAX_DEPTH = 60;
    private const TEMPLATE_CPT = 'elementor_library';
    private const TEMPLATE_TYPE_META = '_elementor_template_type';
    private const PAGE_SETTINGS_META = '_elementor_page_settings';

    public static function status(): array {
        $active = defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' );
        $plugin = self::plugin();
        $kit_id = 0;
        $pro = defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\\ElementorPro\\Plugin' );
        if ( $plugin && isset( $plugin->kits_manager ) && method_exists( $plugin->kits_manager, 'get_active_id' ) ) {
            try { $kit_id = absint( $plugin->kits_manager->get_active_id() ); } catch ( Throwable $e ) { ALIFY_AI_Diagnostics::log_throwable( $e, 'elementor_status_active_kit' ); $kit_id = 0; }
        }
        return array(
            'active'                => $active,
            'version'               => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
            'pro_active'            => $pro,
            'pro_version'           => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
            'document_api'          => (bool) ( $plugin && isset( $plugin->documents ) ),
            'widget_registry'       => (bool) ( $plugin && isset( $plugin->widgets_manager ) ),
            'breakpoints_manager'   => (bool) ( $plugin && isset( $plugin->breakpoints ) ),
            'kits_manager'          => (bool) ( $plugin && isset( $plugin->kits_manager ) ),
            'active_kit_id'         => $kit_id ?: null,
            'template_library_cpt'  => post_type_exists( self::TEMPLATE_CPT ),
            'safe_write_mode'       => 'document_api_when_available_with_verified_meta_fallback',
            'approval_required'     => true,
        );
    }

    public static function breakpoints(): array {
        $result = array(
            'desktop' => array( 'name' => 'desktop', 'label' => 'Desktop', 'active' => true, 'value' => null, 'direction' => null ),
            'tablet'  => array( 'name' => 'tablet', 'label' => 'Tablet', 'active' => true, 'value' => null, 'direction' => null ),
            'mobile'  => array( 'name' => 'mobile', 'label' => 'Mobile', 'active' => true, 'value' => null, 'direction' => null ),
        );
        $plugin = self::plugin();
        if ( ! $plugin || ! isset( $plugin->breakpoints ) ) { return array_values( $result ); }
        try {
            $all = method_exists( $plugin->breakpoints, 'get_breakpoints' ) ? (array) $plugin->breakpoints->get_breakpoints() : array();
            $active = method_exists( $plugin->breakpoints, 'get_active_breakpoints' ) ? (array) $plugin->breakpoints->get_active_breakpoints() : $all;
            foreach ( $all as $name => $bp ) {
                $key = sanitize_key( (string) $name );
                if ( '' === $key ) { continue; }
                $label = ucfirst( str_replace( '_', ' ', $key ) );
                $value = null;
                $direction = null;
                try {
                    if ( is_object( $bp ) ) {
                        if ( method_exists( $bp, 'get_label' ) ) { $label = (string) $bp->get_label(); }
                        if ( method_exists( $bp, 'get_value' ) ) { $value = $bp->get_value(); }
                        if ( method_exists( $bp, 'get_direction' ) ) { $direction = $bp->get_direction(); }
                    }
                } catch ( Throwable $e ) { ALIFY_AI_Diagnostics::log_throwable( $e, 'elementor_breakpoint_details', array( 'breakpoint' => $key ) ); }
                $result[ $key ] = array(
                    'name'      => $key,
                    'label'     => $label,
                    'active'    => isset( $active[ $name ] ) || isset( $active[ $key ] ),
                    'value'     => is_numeric( $value ) ? (int) $value : $value,
                    'direction' => $direction,
                );
            }
        } catch ( Throwable $e ) { ALIFY_AI_Diagnostics::log_throwable( $e, 'elementor_breakpoints' ); }
        return array_values( $result );
    }

    public static function widgets( array $args = array() ): array {
        $search = strtolower( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
        $category = sanitize_key( (string) ( $args['category'] ?? '' ) );
        $types = self::registered_widgets();
        $out = array();
        foreach ( $types as $name => $widget ) {
            $data = self::widget_summary( (string) $name, $widget );
            if ( '' !== $search && false === strpos( strtolower( $data['name'] . ' ' . $data['title'] ), $search ) ) { continue; }
            if ( '' !== $category && ! in_array( $category, (array) $data['categories'], true ) ) { continue; }
            $out[] = $data;
        }
        usort( $out, static fn( $a, $b ) => strcasecmp( (string) $a['title'], (string) $b['title'] ) );
        return $out;
    }

    public static function widget( string $name ) {
        $name = sanitize_key( $name );
        $types = self::registered_widgets();
        if ( '' === $name || ! isset( $types[ $name ] ) ) {
            return new WP_Error( 'alify_ai_widget_type_unknown', 'The requested Elementor widget type is not registered on this site.', array( 'status' => 404, 'widget_type' => $name ) );
        }
        $widget = $types[ $name ];
        $data = self::widget_summary( $name, $widget );
        $controls = self::controls_from_stack( $widget );
        $data['controls'] = self::serialize_controls( $controls );
        return $data;
    }

    public static function document( int $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) { return new WP_Error( 'alify_ai_not_found', 'Content not found.', array( 'status' => 404 ) ); }
        $data = self::read_data( $post_id );
        if ( is_wp_error( $data ) ) { return $data; }
        $settings = self::read_page_settings( $post_id );
        $validation = self::validate_element_tree( $data, true );
        return array(
            'post_id'       => $post_id,
            'post_type'     => $post->post_type,
            'title'         => get_the_title( $post ),
            'editor'        => 'elementor',
            'built_with_elementor' => self::is_built_with_elementor( $post_id ),
            'document_type' => (string) get_post_meta( $post_id, self::TEMPLATE_TYPE_META, true ),
            'element_count' => self::count_elements( $data ),
            'content_hash'  => self::hash_document( $data, $settings ),
            'elements'      => self::summarize_elements( $data ),
            'page_settings' => self::summarize_settings( $settings, 80 ),
            'validation'    => is_wp_error( $validation ) ? array( 'valid' => false, 'error' => $validation->get_error_message(), 'data' => $validation->get_error_data() ) : $validation,
            'plugin'        => self::status(),
        );
    }

    public static function validate_document( int $post_id ) {
        $data = self::read_data( $post_id );
        if ( is_wp_error( $data ) ) { return $data; }
        $tree = self::validate_element_tree( $data, true );
        if ( is_wp_error( $tree ) ) { return $tree; }
        $settings = self::read_page_settings( $post_id );
        return array(
            'valid' => true,
            'post_id' => $post_id,
            'element_count' => self::count_elements( $data ),
            'content_hash' => self::hash_document( $data, $settings ),
            'breakpoints' => self::breakpoints(),
            'warnings' => $tree['warnings'] ?? array(),
        );
    }

    public static function preview( int $post_id, array $payload ) {
        if ( ! self::status()['active'] ) { return new WP_Error( 'alify_ai_elementor_unavailable', 'Elementor is not active.', array( 'status' => 409 ) ); }
        $post = get_post( $post_id );
        if ( ! $post ) { return new WP_Error( 'alify_ai_not_found', 'Content not found.', array( 'status' => 404 ) ); }
        $before = self::read_data( $post_id );
        if ( is_wp_error( $before ) ) { return $before; }
        $before_settings = self::read_page_settings( $post_id );
        $operations = isset( $payload['operations'] ) && is_array( $payload['operations'] ) ? array_values( $payload['operations'] ) : array();
        if ( empty( $operations ) ) { return new WP_Error( 'alify_ai_operations_required', 'At least one Elementor operation is required.', array( 'status' => 400 ) ); }
        if ( count( $operations ) > 75 ) { return new WP_Error( 'alify_ai_too_many_operations', 'A proposal can contain at most 75 Elementor operations.', array( 'status' => 400 ) ); }
        if ( self::contains_unsafe_payload( $operations ) ) { return new WP_Error( 'alify_ai_unsafe_elementor_payload', 'Script tags, inline event handlers and javascript: URLs are not accepted by Elementor design operations.', array( 'status' => 400 ) ); }
        $page_settings_ok = self::validate_page_setting_operations( $post_id, $operations );
        if ( is_wp_error( $page_settings_ok ) ) { return $page_settings_ok; }

        $result = self::apply_operations( $before, $before_settings, $operations );
        if ( is_wp_error( $result ) ) { return $result; }
        $tree_ok = self::validate_element_tree( $result['elements'], true );
        if ( is_wp_error( $tree_ok ) ) { return $tree_ok; }
        $hash_before = self::hash_document( $before, $before_settings );
        $hash_after = self::hash_document( $result['elements'], $result['page_settings'] );
        if ( hash_equals( $hash_before, $hash_after ) ) { return new WP_Error( 'alify_ai_no_changes', 'The supplied operations do not change the Elementor document.', array( 'status' => 400 ) ); }

        $request = array( 'mode' => 'document', 'base_hash' => $hash_before, 'operations' => $operations );
        $preview = array(
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'title' => get_the_title( $post ),
            'operation_count' => count( $operations ),
            'changes' => $result['changes'],
            'before_hash' => $hash_before,
            'after_hash' => $hash_after,
            'before_elements' => self::count_elements( $before ),
            'after_elements' => self::count_elements( $result['elements'] ),
            'after_preview' => self::summarize_elements( $result['elements'] ),
            'page_settings' => self::summarize_settings( $result['page_settings'], 80 ),
            'warnings' => $tree_ok['warnings'] ?? array(),
        );
        return ALIFY_AI_Approvals::create( 'elementor', $post_id, $request, $preview );
    }

    public static function list_templates( array $args = array() ) {
        if ( ! post_type_exists( self::TEMPLATE_CPT ) ) { return new WP_Error( 'alify_ai_elementor_library_unavailable', 'Elementor template library is not registered.', array( 'status' => 409 ) ); }
        $type = sanitize_key( (string) ( $args['type'] ?? '' ) );
        $search = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
        $page = max( 1, absint( $args['page'] ?? 1 ) );
        $per_page = max( 1, min( 100, absint( $args['per_page'] ?? 50 ) ) );
        $status = sanitize_key( (string) ( $args['status'] ?? '' ) );
        $allowed_statuses = array( 'publish','draft','pending','private' );
        $query = array( 'post_type' => self::TEMPLATE_CPT, 'post_status' => ( '' !== $status && in_array( $status, $allowed_statuses, true ) ) ? $status : $allowed_statuses, 'posts_per_page' => $per_page, 'paged' => $page, 'orderby' => 'modified', 'order' => 'DESC', 's' => $search );
        if ( '' !== $type ) { $query['meta_query'] = array( array( 'key' => self::TEMPLATE_TYPE_META, 'value' => $type ) ); }
        $q = new WP_Query( $query );
        $items = array();
        foreach ( (array) $q->posts as $post ) { $items[] = self::template_summary( $post ); }
        return array( 'items' => $items, 'page' => $page, 'per_page' => $per_page, 'total' => (int) $q->found_posts, 'total_pages' => (int) $q->max_num_pages );
    }

    public static function get_template( int $id ) {
        $post = get_post( $id );
        if ( ! $post || self::TEMPLATE_CPT !== $post->post_type ) { return new WP_Error( 'alify_ai_elementor_template_not_found', 'Elementor template not found.', array( 'status' => 404 ) ); }
        $elements = self::read_data( $id );
        if ( is_wp_error( $elements ) ) { return $elements; }
        $settings = self::read_page_settings( $id );
        $data = self::template_summary( $post );
        $data['element_count'] = self::count_elements( $elements );
        $data['content_hash'] = self::hash_document( $elements, $settings );
        $data['elements'] = self::summarize_elements( $elements );
        $data['page_settings'] = self::summarize_settings( $settings, 80 );
        return $data;
    }

    public static function preview_template( int $id, array $payload ) {
        if ( ! self::status()['active'] ) { return new WP_Error( 'alify_ai_elementor_unavailable', 'Elementor is not active.', array( 'status' => 409 ) ); }
        $action = sanitize_key( (string) ( $payload['action'] ?? ( $id ? 'update' : 'create' ) ) );
        if ( ! in_array( $action, array( 'create','update','delete' ), true ) ) { return new WP_Error( 'alify_ai_invalid_template_action', 'Template action must be create, update or delete.', array( 'status' => 400 ) ); }
        $before = null;
        $base_hash = '';
        if ( $id ) {
            $post = get_post( $id );
            if ( ! $post || self::TEMPLATE_CPT !== $post->post_type ) { return new WP_Error( 'alify_ai_elementor_template_not_found', 'Elementor template not found.', array( 'status' => 404 ) ); }
            $elements = self::read_data( $id ); if ( is_wp_error( $elements ) ) { return $elements; }
            $settings = self::read_page_settings( $id );
            $before = self::template_snapshot( $id, $elements, $settings );
            $base_hash = self::hash_template_snapshot( $before );
        }
        if ( 'delete' === $action ) {
            if ( ! $id || empty( $payload['confirm_delete'] ) ) { return new WP_Error( 'alify_ai_confirm_delete_required', 'Deleting an Elementor template requires confirm_delete=true.', array( 'status' => 400 ) ); }
            return ALIFY_AI_Approvals::create( 'elementor', $id, array( 'mode'=>'template','action'=>'delete','base_hash'=>$base_hash,'confirm_delete'=>true ), array( 'action'=>'delete','template'=>$before ) );
        }
        $title = sanitize_text_field( (string) ( $payload['title'] ?? ( $before['title'] ?? '' ) ) );
        $type = sanitize_key( (string) ( $payload['type'] ?? ( $before['type'] ?? 'page' ) ) );
        $status = sanitize_key( (string) ( $payload['status'] ?? ( $before['status'] ?? 'publish' ) ) );
        if ( '' === $title ) { return new WP_Error( 'alify_ai_template_title_required', 'Elementor template title is required.', array( 'status' => 400 ) ); }
        if ( ! in_array( $status, array( 'publish','draft','pending','private' ), true ) ) { return new WP_Error( 'alify_ai_invalid_status', 'Unsupported template status.', array( 'status' => 400 ) ); }
        if ( ! self::template_type_exists( $type ) ) { return new WP_Error( 'alify_ai_invalid_template_type', 'This Elementor document/template type is not registered.', array( 'status' => 400, 'type' => $type ) ); }
        $elements = isset( $payload['elements'] ) && is_array( $payload['elements'] ) ? array_values( $payload['elements'] ) : ( $before['elements'] ?? array() );
        $settings = isset( $payload['page_settings'] ) && is_array( $payload['page_settings'] ) ? $payload['page_settings'] : ( $before['page_settings'] ?? array() );
        $operations = isset( $payload['operations'] ) && is_array( $payload['operations'] ) ? array_values( $payload['operations'] ) : array();
        if ( $operations ) {
            if ( count( $operations ) > 75 ) { return new WP_Error( 'alify_ai_too_many_operations', 'A template proposal can contain at most 75 Elementor operations.', array( 'status' => 400 ) ); }
            $op_result = self::apply_operations( $elements, $settings, $operations );
            if ( is_wp_error( $op_result ) ) { return $op_result; }
            $elements = $op_result['elements'];
            $settings = $op_result['page_settings'];
        }
        if ( self::contains_unsafe_payload( array( $elements, $settings, $operations ) ) ) { return new WP_Error( 'alify_ai_unsafe_elementor_payload', 'Unsafe script/event content is not accepted in Elementor templates.', array( 'status' => 400 ) ); }
        $valid = self::validate_element_tree( $elements, true ); if ( is_wp_error( $valid ) ) { return $valid; }
        $request = array( 'mode'=>'template','action'=>$action,'base_hash'=>$base_hash,'title'=>$title,'type'=>$type,'status'=>$status,'elements'=>$elements,'page_settings'=>$settings,'operations'=>$operations );
        $preview = array( 'action'=>$action,'template_id'=>$id ?: null,'title'=>$title,'type'=>$type,'status'=>$status,'element_count'=>self::count_elements($elements),'content_hash'=>self::hash_document($elements,$settings),'warnings'=>$valid['warnings'] ?? array(),'operation_count'=>count($operations) );
        return ALIFY_AI_Approvals::create( 'elementor', $id, $request, $preview );
    }

    public static function globals() {
        $kit = self::active_kit();
        if ( is_wp_error( $kit ) ) { return $kit; }
        $id = self::kit_id( $kit );
        $settings = self::kit_settings( $kit, $id );
        return array(
            'kit_id' => $id,
            'content_hash' => self::hash_settings( $settings ),
            'system_colors' => array_values( (array) ( $settings['system_colors'] ?? array() ) ),
            'custom_colors' => array_values( (array) ( $settings['custom_colors'] ?? array() ) ),
            'system_typography' => array_values( (array) ( $settings['system_typography'] ?? array() ) ),
            'custom_typography' => array_values( (array) ( $settings['custom_typography'] ?? array() ) ),
            'breakpoints' => self::breakpoints(),
            'settings' => self::summarize_settings( $settings, 120 ),
        );
    }

    public static function preview_globals( array $payload ) {
        $kit = self::active_kit();
        if ( is_wp_error( $kit ) ) { return $kit; }
        $id = self::kit_id( $kit );
        $before = self::kit_settings( $kit, $id );
        $changes = isset( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : array();
        if ( empty( $changes ) ) { return new WP_Error( 'alify_ai_settings_required', 'Provide Elementor Kit settings to change.', array( 'status' => 400 ) ); }
        if ( self::contains_unsafe_payload( $changes ) ) { return new WP_Error( 'alify_ai_unsafe_elementor_payload', 'Unsafe script/event content is not accepted in Elementor global styles.', array( 'status' => 400 ) ); }
        $after = array_replace_recursive( $before, $changes );
        $valid = self::validate_kit_globals( $after ); if ( is_wp_error( $valid ) ) { return $valid; }
        if ( hash_equals( self::hash_settings( $before ), self::hash_settings( $after ) ) ) { return new WP_Error( 'alify_ai_no_changes', 'The supplied global settings do not change the active Elementor Kit.', array( 'status' => 400 ) ); }
        $request = array( 'mode'=>'globals','kit_id'=>$id,'base_hash'=>self::hash_settings($before),'settings'=>$changes );
        $preview = array( 'kit_id'=>$id,'before_hash'=>self::hash_settings($before),'after_hash'=>self::hash_settings($after),'changed_keys'=>array_keys($changes),'system_colors'=>array_values((array)($after['system_colors']??array())),'custom_colors'=>array_values((array)($after['custom_colors']??array())),'system_typography'=>array_values((array)($after['system_typography']??array())),'custom_typography'=>array_values((array)($after['custom_typography']??array())) );
        return ALIFY_AI_Approvals::create( 'elementor', $id, $request, $preview );
    }

    public static function apply_approval( array $approval ) {
        $request = is_array( $approval['request'] ?? null ) ? $approval['request'] : array();
        $mode = sanitize_key( (string) ( $request['mode'] ?? 'document' ) );
        if ( 'template' === $mode ) { return self::apply_template_approval( $approval, $request ); }
        if ( 'globals' === $mode ) { return self::apply_globals_approval( $approval, $request ); }
        return self::apply_document_approval( $approval, $request );
    }

    private static function apply_document_approval( array $approval, array $request ) {
        if ( ! self::status()['active'] ) { return new WP_Error( 'alify_ai_elementor_unavailable', 'Elementor is not active.', array( 'status' => 409 ) ); }
        $post_id = absint( $approval['object_id'] ?? 0 );
        $post = get_post( $post_id ); if ( ! $post ) { return new WP_Error( 'alify_ai_not_found', 'Content no longer exists.', array( 'status' => 404 ) ); }
        $before = self::read_data( $post_id ); if ( is_wp_error( $before ) ) { return $before; }
        $before_settings = self::read_page_settings( $post_id );
        $current_hash = self::hash_document( $before, $before_settings );
        if ( ! hash_equals( (string) ( $request['base_hash'] ?? '' ), $current_hash ) ) { return new WP_Error( 'alify_ai_stale_proposal', 'The Elementor document changed after this preview was created. Create a fresh proposal before applying.', array( 'status' => 409, 'current_hash' => $current_hash ) ); }
        $operations = isset( $request['operations'] ) && is_array( $request['operations'] ) ? $request['operations'] : array();
        $page_settings_ok = self::validate_page_setting_operations( $post_id, $operations ); if ( is_wp_error( $page_settings_ok ) ) { return $page_settings_ok; }
        $result = self::apply_operations( $before, $before_settings, $operations ); if ( is_wp_error( $result ) ) { return $result; }
        $tree_ok = self::validate_element_tree( $result['elements'], true ); if ( is_wp_error( $tree_ok ) ) { return $tree_ok; }
        $before_snapshot = array( 'elements'=>$before, 'page_settings'=>$before_settings, 'hash'=>$current_hash );
        $after_snapshot = array( 'elements'=>$result['elements'], 'page_settings'=>$result['page_settings'], 'hash'=>self::hash_document($result['elements'],$result['page_settings']), 'changes'=>$result['changes'] );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before_snapshot, $after_snapshot ); if ( is_wp_error( $snapshot_ok ) ) { return $snapshot_ok; }
        self::save_revision_safe( $post );
        $write = self::write_document( $post_id, $result['elements'], $result['page_settings'] ); if ( is_wp_error( $write ) ) { return $write; }
        $activity_id = ALIFY_AI_Audit::log( 'update_elementor', 'elementor', $post_id, $before_snapshot, $after_snapshot );
        if ( ! $activity_id ) { self::write_document( $post_id, $before, $before_settings ); return new WP_Error( 'alify_ai_audit_failed', 'Elementor change was reverted because the activity log could not be stored.', array( 'status' => 500 ) ); }
        return array( 'message'=>'Elementor proposal applied.','activity_id'=>$activity_id,'document'=>self::document($post_id) );
    }

    private static function apply_template_approval( array $approval, array $request ) {
        $action = sanitize_key( (string) ( $request['action'] ?? '' ) );
        $id = absint( $approval['object_id'] ?? 0 );
        $before = null;
        if ( $id ) {
            $post = get_post( $id );
            if ( ! $post || self::TEMPLATE_CPT !== $post->post_type ) { return new WP_Error( 'alify_ai_elementor_template_not_found', 'Elementor template no longer exists.', array( 'status' => 404 ) ); }
            $elements = self::read_data( $id ); if ( is_wp_error( $elements ) ) { return $elements; }
            $settings = self::read_page_settings( $id );
            $before = self::template_snapshot( $id, $elements, $settings );
            if ( ! hash_equals( (string) ( $request['base_hash'] ?? '' ), self::hash_template_snapshot( $before ) ) ) { return new WP_Error( 'alify_ai_stale_proposal', 'The Elementor template changed after preview. Create a fresh proposal.', array( 'status'=>409 ) ); }
        }
        if ( 'create' === $action ) {
            $new_id = self::create_template( $request ); if ( is_wp_error( $new_id ) ) { return $new_id; }
            $after = self::get_template( $new_id );
            $activity_id = ALIFY_AI_Audit::log( 'create_elementor_template', 'elementor_template', $new_id, null, self::template_snapshot_from_public($new_id) );
            if ( ! $activity_id ) { wp_delete_post( $new_id, true ); return new WP_Error( 'alify_ai_audit_failed', 'Template creation was reverted because the activity log could not be stored.', array( 'status'=>500 ) ); }
            return array( 'message'=>'Elementor template created.','activity_id'=>$activity_id,'template'=>$after );
        }
        if ( 'update' === $action ) {
            $write = self::update_template( $id, $request ); if ( is_wp_error( $write ) ) { return $write; }
            $after_snapshot = self::template_snapshot_from_public( $id );
            $activity_id = ALIFY_AI_Audit::log( 'update_elementor_template', 'elementor_template', $id, $before, $after_snapshot );
            if ( ! $activity_id ) { self::restore_template_snapshot( $before ); return new WP_Error( 'alify_ai_audit_failed', 'Template update was reverted because the activity log could not be stored.', array( 'status'=>500 ) ); }
            return array( 'message'=>'Elementor template updated.','activity_id'=>$activity_id,'template'=>self::get_template($id) );
        }
        if ( 'delete' === $action ) {
            $activity_id = ALIFY_AI_Audit::log( 'delete_elementor_template', 'elementor_template', $id, $before, array( 'id'=>$id,'deleted'=>true ) );
            if ( ! $activity_id ) { return new WP_Error( 'alify_ai_audit_failed', 'Could not store deletion rollback snapshot, so the template was not deleted.', array( 'status'=>500 ) ); }
            $deleted = wp_delete_post( $id, true );
            if ( ! $deleted ) { ALIFY_AI_Audit::delete_entry( $activity_id ); return new WP_Error( 'alify_ai_delete_failed', 'Elementor template could not be deleted.', array( 'status'=>500 ) ); }
            return array( 'message'=>'Elementor template deleted.','activity_id'=>$activity_id,'deleted_id'=>$id );
        }
        return new WP_Error( 'alify_ai_invalid_template_action', 'Unsupported Elementor template action.', array( 'status'=>400 ) );
    }

    private static function apply_globals_approval( array $approval, array $request ) {
        $kit = self::active_kit(); if ( is_wp_error( $kit ) ) { return $kit; }
        $id = self::kit_id( $kit );
        if ( $id !== absint( $request['kit_id'] ?? 0 ) ) { return new WP_Error( 'alify_ai_stale_proposal', 'The active Elementor Kit changed after preview.', array( 'status'=>409 ) ); }
        $before = self::kit_settings( $kit, $id );
        if ( ! hash_equals( (string) ( $request['base_hash'] ?? '' ), self::hash_settings( $before ) ) ) { return new WP_Error( 'alify_ai_stale_proposal', 'Elementor global styles changed after preview.', array( 'status'=>409 ) ); }
        $after = array_replace_recursive( $before, (array) ( $request['settings'] ?? array() ) );
        $valid = self::validate_kit_globals( $after ); if ( is_wp_error( $valid ) ) { return $valid; }
        $snap_ok = ALIFY_AI_Audit::validate_snapshot( array('kit_id'=>$id,'settings'=>$before), array('kit_id'=>$id,'settings'=>$after) ); if ( is_wp_error($snap_ok) ) { return $snap_ok; }
        $write = self::write_kit_settings( $kit, $id, $after ); if ( is_wp_error( $write ) ) { return $write; }
        $activity_id = ALIFY_AI_Audit::log( 'update_elementor_globals', 'elementor_kit', $id, array('kit_id'=>$id,'settings'=>$before), array('kit_id'=>$id,'settings'=>$after) );
        if ( ! $activity_id ) { self::write_kit_settings( $kit, $id, $before ); return new WP_Error( 'alify_ai_audit_failed', 'Global style change was reverted because the activity log could not be stored.', array( 'status'=>500 ) ); }
        return array( 'message'=>'Elementor global styles updated.','activity_id'=>$activity_id,'globals'=>self::globals() );
    }

    public static function rollback( int $post_id, array $before ) {
        $post = get_post( $post_id ); if ( ! $post ) { return new WP_Error( 'alify_ai_not_found', 'Content no longer exists.', array( 'status'=>404 ) ); }
        if ( ! isset( $before['elements'] ) || ! is_array( $before['elements'] ) ) { return new WP_Error( 'alify_ai_invalid_snapshot', 'Elementor rollback snapshot is incomplete.', array( 'status'=>400 ) ); }
        $settings = isset( $before['page_settings'] ) && is_array( $before['page_settings'] ) ? $before['page_settings'] : self::read_page_settings( $post_id );
        $current = self::read_data( $post_id ); if ( is_wp_error($current) ) { return $current; }
        $current_settings = self::read_page_settings( $post_id );
        $tree = self::validate_element_tree( $before['elements'], true ); if ( is_wp_error($tree) ) { return $tree; }
        $snap_ok = ALIFY_AI_Audit::validate_snapshot( array('elements'=>$current,'page_settings'=>$current_settings), array('elements'=>$before['elements'],'page_settings'=>$settings) ); if ( is_wp_error($snap_ok) ) { return $snap_ok; }
        self::save_revision_safe( $post );
        $write = self::write_document( $post_id, $before['elements'], $settings ); if ( is_wp_error($write) ) { return $write; }
        $after = self::read_data($post_id); if ( is_wp_error($after) ) { self::write_document($post_id,$current,$current_settings); return $after; }
        $after_settings = self::read_page_settings($post_id);
        $activity_id = ALIFY_AI_Audit::log( 'rollback_elementor', 'elementor', $post_id, array('elements'=>$current,'page_settings'=>$current_settings), array('elements'=>$after,'page_settings'=>$after_settings) );
        if ( ! $activity_id ) { self::write_document($post_id,$current,$current_settings); return new WP_Error('alify_ai_audit_failed','Elementor rollback was reverted because the rollback log could not be stored.',array('status'=>500)); }
        return array('message'=>'Elementor change rolled back.','activity_id'=>$activity_id,'document'=>self::document($post_id));
    }

    public static function rollback_activity( array $entry ) {
        $action = (string) ( $entry['action'] ?? '' );
        if ( 'update_elementor' === $action ) { return self::rollback( absint($entry['object_id']), (array) $entry['before_json'] ); }
        if ( 'create_elementor_template' === $action ) {
            $id = absint( $entry['object_id'] );
            if ( ! get_post($id) ) { return new WP_Error('alify_ai_not_found','Created Elementor template no longer exists.',array('status'=>404)); }
            $current = self::template_snapshot_from_public($id);
            $log = ALIFY_AI_Audit::log('rollback_create_elementor_template','elementor_template',$id,$current,array('id'=>$id,'deleted'=>true));
            if ( ! $log ) { return new WP_Error('alify_ai_audit_failed','Could not store rollback activity.',array('status'=>500)); }
            if ( ! wp_delete_post($id,true) ) { ALIFY_AI_Audit::delete_entry($log); return new WP_Error('alify_ai_rollback_failed','Could not remove created Elementor template.',array('status'=>500)); }
            return array('message'=>'Created Elementor template removed.','activity_id'=>$log);
        }
        if ( 'update_elementor_template' === $action && is_array($entry['before_json']) ) {
            $id = absint($entry['object_id']); $current=self::template_snapshot_from_public($id);
            $restored=self::restore_template_snapshot($entry['before_json']); if ( is_wp_error($restored) ) { return $restored; }
            $after=self::template_snapshot_from_public($id);
            $log=ALIFY_AI_Audit::log('rollback_update_elementor_template','elementor_template',$id,$current,$after);
            if(!$log){self::restore_template_snapshot($current);return new WP_Error('alify_ai_audit_failed','Template rollback was reverted because its activity log could not be stored.',array('status'=>500));}
            return array('message'=>'Elementor template update rolled back.','activity_id'=>$log,'template'=>self::get_template($id));
        }
        if ( 'delete_elementor_template' === $action && is_array($entry['before_json']) ) {
            $new_id=self::restore_deleted_template_snapshot($entry['before_json']); if(is_wp_error($new_id)){return $new_id;}
            $after=self::template_snapshot_from_public($new_id);
            $log=ALIFY_AI_Audit::log('rollback_delete_elementor_template','elementor_template',$new_id,null,$after);
            if(!$log){wp_delete_post($new_id,true);return new WP_Error('alify_ai_audit_failed','Deleted template was recreated but rollback logging failed, so recreation was removed.',array('status'=>500));}
            return array('message'=>'Deleted Elementor template recreated.','activity_id'=>$log,'restored_template_id'=>$new_id,'template'=>self::get_template($new_id));
        }
        if ( 'update_elementor_globals' === $action && is_array($entry['before_json']) ) {
            $kit=self::active_kit(); if(is_wp_error($kit)){return $kit;} $id=self::kit_id($kit);
            if($id!==absint($entry['before_json']['kit_id']??0)){return new WP_Error('alify_ai_rollback_conflict','The active Elementor Kit is different from the one in the rollback snapshot.',array('status'=>409));}
            $current=self::kit_settings($kit,$id); $target=(array)($entry['before_json']['settings']??array());
            $write=self::write_kit_settings($kit,$id,$target); if(is_wp_error($write)){return $write;}
            $log=ALIFY_AI_Audit::log('rollback_update_elementor_globals','elementor_kit',$id,array('kit_id'=>$id,'settings'=>$current),array('kit_id'=>$id,'settings'=>$target));
            if(!$log){self::write_kit_settings($kit,$id,$current);return new WP_Error('alify_ai_audit_failed','Global-style rollback was reverted because logging failed.',array('status'=>500));}
            return array('message'=>'Elementor global styles rolled back.','activity_id'=>$log,'globals'=>self::globals());
        }
        return new WP_Error('alify_ai_rollback_unsupported','Rollback is not supported for this Elementor activity.',array('status'=>400));
    }

    private static function apply_operations( array $elements, array $page_settings, array $operations ) {
        $changes = array();
        foreach ( $operations as $index => $op ) {
            if ( ! is_array($op) ) { return new WP_Error('alify_ai_invalid_operation','Each Elementor operation must be an object.',array('status'=>400,'operation_index'=>$index)); }
            $type=sanitize_key((string)($op['type']??''));
            if('update_page_settings'===$type){$settings=$op['settings']??null;if(!is_array($settings)){return new WP_Error('alify_ai_invalid_elementor_operation','update_page_settings requires settings.',array('status'=>400,'operation_index'=>$index));}$page_settings=!empty($op['replace'])?$settings:array_replace_recursive($page_settings,$settings);$changes[]=array('type'=>$type,'settings'=>array_keys($settings));continue;}
            if('replace_document'===$type){$new=$op['elements']??null;if(!is_array($new)){return new WP_Error('alify_ai_invalid_elementor_operation','replace_document requires elements array.',array('status'=>400,'operation_index'=>$index));}$elements=array_values($new);$changes[]=array('type'=>$type,'element_count'=>self::count_elements($elements));continue;}
            if('update_settings'===$type||'set_responsive_setting'===$type||'set_global_style'===$type){
                $id=sanitize_text_field((string)($op['element_id']??'')); if(''===$id){return new WP_Error('alify_ai_invalid_elementor_operation',$type.' requires element_id.',array('status'=>400,'operation_index'=>$index));}
                $found=false;$error=null;
                $elements=self::map_element($elements,$id,function(array $element)use($op,$type,&$error){
                    $settings=(array)($element['settings']??array());
                    if('update_settings'===$type){$incoming=$op['settings']??null;if(!is_array($incoming)){$error=new WP_Error('alify_ai_invalid_elementor_operation','update_settings requires settings.',array('status'=>400));return $element;}$valid=self::validate_touched_settings($element,$incoming);if(is_wp_error($valid)){$error=$valid;return $element;}$settings=!empty($op['replace'])?$incoming:array_replace_recursive($settings,$incoming);}
                    elseif('set_responsive_setting'===$type){$control=sanitize_key((string)($op['control']??''));$device=sanitize_key((string)($op['device']??'desktop'));if(''===$control||!array_key_exists('value',$op)){$error=new WP_Error('alify_ai_invalid_elementor_operation','set_responsive_setting requires control, device and value.',array('status'=>400));return $element;}$devices=self::breakpoint_names();if(!in_array($device,$devices,true)){$error=new WP_Error('alify_ai_invalid_breakpoint','Requested Elementor breakpoint is not active/registered.',array('status'=>400,'device'=>$device));return $element;}$key='desktop'===$device?$control:$control.'_'.$device;$valid=self::validate_touched_settings($element,array($key=>$op['value']));if(is_wp_error($valid)){$error=$valid;return $element;}$settings[$key]=$op['value'];}
                    else{$control=sanitize_key((string)($op['control']??''));$style_type=sanitize_key((string)($op['style_type']??''));$style_id=sanitize_text_field((string)($op['style_id']??''));if(''===$control||!in_array($style_type,array('colors','typography'),true)||''===$style_id){$error=new WP_Error('alify_ai_invalid_elementor_operation','set_global_style requires control, style_type(colors|typography), and style_id.',array('status'=>400));return $element;}$controls=self::controls_for_element($element);if(is_array($controls)&&!isset($controls[$control])){$error=new WP_Error('alify_ai_unknown_elementor_control','Global style references an unknown control for this Elementor element.',array('status'=>400,'control'=>$control,'element_id'=>$element['id']??''));return $element;}if(!self::global_style_exists($style_type,$style_id)){$error=new WP_Error('alify_ai_global_style_not_found','The requested Elementor global style ID does not exist in the active Kit.',array('status'=>400,'style_type'=>$style_type,'style_id'=>$style_id));return $element;}$globals=is_array($settings['__globals__']??null)?$settings['__globals__']:array();$globals[$control]='globals/'.$style_type.'?id='.$style_id;$settings['__globals__']=$globals;}
                    $element['settings']=$settings;return $element;
                },$found);
                if(is_wp_error($error)){return $error;} if(!$found){return new WP_Error('alify_ai_element_not_found','Elementor element was not found.',array('status'=>404,'element_id'=>$id));}
                $changes[]=array('type'=>$type,'element_id'=>$id);continue;
            }
            if('add_element'===$type){$parent=sanitize_text_field((string)($op['parent_id']??'root'));$position=isset($op['index'])?max(0,(int)$op['index']):PHP_INT_MAX;$element=$op['element']??null;if(!is_array($element)){return new WP_Error('alify_ai_invalid_elementor_operation','add_element requires element object.',array('status'=>400,'operation_index'=>$index));}$element=self::normalize_new_element($element);if(is_wp_error($element)){return $element;}$insert=self::insert_element($elements,$parent,$position,$element);if(is_wp_error($insert)){return $insert;}$elements=$insert;$changes[]=array('type'=>$type,'parent_id'=>$parent,'element_id'=>$element['id']);continue;}
            if('remove_element'===$type){$id=sanitize_text_field((string)($op['element_id']??''));$found=false;$elements=self::remove_element($elements,$id,$found);if(!$found){return new WP_Error('alify_ai_element_not_found','Elementor element was not found.',array('status'=>404,'element_id'=>$id));}$changes[]=array('type'=>$type,'element_id'=>$id);continue;}
            if('duplicate_element'===$type){$id=sanitize_text_field((string)($op['element_id']??''));$found=self::find_element($elements,$id);if(!$found){return new WP_Error('alify_ai_element_not_found','Elementor element was not found.',array('status'=>404,'element_id'=>$id));}$copy=self::regenerate_ids($found['element']);$parent=isset($op['parent_id'])?sanitize_text_field((string)$op['parent_id']):$found['parent_id'];$position=isset($op['index'])?max(0,(int)$op['index']):($found['index']+1);$insert=self::insert_element($elements,$parent?:'root',$position,$copy);if(is_wp_error($insert)){return $insert;}$elements=$insert;$changes[]=array('type'=>$type,'source_id'=>$id,'element_id'=>$copy['id']);continue;}
            if('move_element'===$type){$id=sanitize_text_field((string)($op['element_id']??''));$target=sanitize_text_field((string)($op['parent_id']??'root'));$position=isset($op['index'])?max(0,(int)$op['index']):PHP_INT_MAX;if($id===$target){return new WP_Error('alify_ai_elementor_cycle','An element cannot be moved into itself.',array('status'=>400));}$found=self::find_element($elements,$id);if(!$found){return new WP_Error('alify_ai_element_not_found','Elementor element was not found.',array('status'=>404,'element_id'=>$id));}if('root'!==$target&&self::element_contains_id($found['element'],$target)){return new WP_Error('alify_ai_elementor_cycle','An element cannot be moved into its descendant.',array('status'=>400));}$removed=false;$elements=self::remove_element($elements,$id,$removed);$insert=self::insert_element($elements,$target,$position,$found['element']);if(is_wp_error($insert)){return $insert;}$elements=$insert;$changes[]=array('type'=>$type,'element_id'=>$id,'parent_id'=>$target);continue;}
            if('replace_element'===$type){$id=sanitize_text_field((string)($op['element_id']??''));$replacement=$op['element']??null;if(!is_array($replacement)){return new WP_Error('alify_ai_invalid_elementor_operation','replace_element requires element.',array('status'=>400));}$replacement=self::normalize_new_element($replacement);if(is_wp_error($replacement)){return $replacement;}$found=false;$elements=self::map_element($elements,$id,static fn($old)=>$replacement,$found);if(!$found){return new WP_Error('alify_ai_element_not_found','Elementor element was not found.',array('status'=>404,'element_id'=>$id));}$changes[]=array('type'=>$type,'element_id'=>$id,'replacement_id'=>$replacement['id']);continue;}
            return new WP_Error('alify_ai_unknown_elementor_operation','Unsupported Elementor operation type.',array('status'=>400,'operation_index'=>$index,'type'=>$type));
        }
        return array('elements'=>array_values($elements),'page_settings'=>$page_settings,'changes'=>$changes);
    }

    private static function validate_page_setting_operations( int $post_id, array $operations ) {
        $doc = self::document_instance( $post_id );
        $controls = self::controls_from_stack( $doc );
        if ( empty( $controls ) ) { return true; }
        foreach ( $operations as $op ) {
            if ( ! is_array( $op ) || 'update_page_settings' !== sanitize_key( (string) ( $op['type'] ?? '' ) ) ) { continue; }
            $incoming = $op['settings'] ?? null;
            if ( ! is_array( $incoming ) ) { continue; }
            foreach ( $incoming as $key => $value ) {
                $base = self::control_base_key( (string) $key, $controls );
                if ( null === $base ) { return new WP_Error( 'alify_ai_unknown_elementor_page_control', 'The requested page setting is not a registered control for this Elementor document type.', array( 'status'=>400, 'control'=>$key, 'post_id'=>$post_id ) ); }
                if ( (string) $key !== $base && empty( $controls[$base]['responsive'] ) ) { return new WP_Error( 'alify_ai_page_control_not_responsive', 'This Elementor page setting does not support responsive overrides.', array( 'status'=>400, 'control'=>$base, 'requested_key'=>$key ) ); }
                $valid = self::validate_control_value( (array) $controls[$base], $value );
                if ( is_wp_error( $valid ) ) { return $valid; }
            }
        }
        return true;
    }

    private static function validate_touched_settings( array $element, array $incoming ) {
        if ( isset($incoming['__globals__']) && !is_array($incoming['__globals__']) ) { return new WP_Error('alify_ai_invalid_global_styles','__globals__ must be an object.',array('status'=>400)); }
        $controls=self::controls_for_element($element);
        if(null===$controls){return true;}
        foreach($incoming as $key=>$value){
            if('__globals__'===$key){foreach((array)$value as $control=>$ref){if(!isset($controls[$control])){return new WP_Error('alify_ai_unknown_elementor_control','Global style references an unknown control.',array('status'=>400,'control'=>$control));}if(!is_string($ref)||!preg_match('#^globals/(colors|typography)\?id=[A-Za-z0-9_-]+$#',$ref)){return new WP_Error('alify_ai_invalid_global_reference','Invalid Elementor global-style reference.',array('status'=>400,'control'=>$control));}}continue;}
            $base=self::control_base_key((string)$key,$controls);if(null===$base){return new WP_Error('alify_ai_unknown_elementor_control','The requested setting is not a registered control for this Elementor element.',array('status'=>400,'control'=>$key,'element_id'=>$element['id']??''));}
            if ( (string) $key !== $base && empty( $controls[$base]['responsive'] ) ) { return new WP_Error('alify_ai_control_not_responsive','This Elementor control does not support responsive device overrides.',array('status'=>400,'control'=>$base,'requested_key'=>$key,'element_id'=>$element['id']??'')); }
            $valid=self::validate_control_value((array)$controls[$base],$value);if(is_wp_error($valid)){return $valid;}
        }
        return true;
    }

    private static function control_base_key( string $key, array $controls ): ?string {
        if(isset($controls[$key])){return $key;}
        foreach(self::breakpoint_names() as $device){if('desktop'===$device){continue;}$suffix='_'.$device;if(strlen($key)>strlen($suffix)&&substr($key,-strlen($suffix))===$suffix){$base=substr($key,0,-strlen($suffix));if(isset($controls[$base])){return $base;}}}
        return null;
    }

    private static function validate_control_value( array $control, $value ) {
        $type=sanitize_key((string)($control['type']??''));
        if(isset($control['options'])&&is_array($control['options'])&&is_scalar($value)&&''!==(string)$value&&!array_key_exists((string)$value,$control['options'])){return new WP_Error('alify_ai_invalid_control_option','Value is not an allowed option for this Elementor control.',array('status'=>400,'control_type'=>$type,'value'=>$value));}
        if('number'===$type&&''!==$value&&!is_numeric($value)){return new WP_Error('alify_ai_invalid_control_value','Elementor number control requires a numeric value.',array('status'=>400));}
        if(in_array($type,array('slider'),true)&&!is_array($value)){return new WP_Error('alify_ai_invalid_control_value','Elementor slider control requires an object value.',array('status'=>400));}
        if(in_array($type,array('dimensions','gaps'),true)&&!is_array($value)){return new WP_Error('alify_ai_invalid_control_value','Elementor dimensions/gaps control requires an object value.',array('status'=>400));}
        if('media'===$type&&!is_array($value)&&''!==$value){return new WP_Error('alify_ai_invalid_control_value','Elementor media control requires an object value.',array('status'=>400));}
        if('gallery'===$type&&!is_array($value)){return new WP_Error('alify_ai_invalid_control_value','Elementor gallery control requires an array.',array('status'=>400));}
        if('repeater'===$type&&!is_array($value)){return new WP_Error('alify_ai_invalid_control_value','Elementor repeater control requires an array.',array('status'=>400));}
        return true;
    }

    private static function validate_element_tree( array $elements, bool $validate_registry = false ) {
        $seen=array();$count=0;$warnings=array();
        $walk=function(array $nodes,int $depth=0,string $parent_type='root')use(&$walk,&$seen,&$count,&$warnings,$validate_registry){
            if($depth>self::MAX_DEPTH){return new WP_Error('alify_ai_elementor_tree_too_deep','Elementor document exceeds the safe nesting depth.',array('status'=>413));}
            foreach($nodes as $node){
                if(!is_array($node)){return new WP_Error('alify_ai_invalid_elementor_tree','Elementor tree contains an invalid element.',array('status'=>400));}
                if(++$count>self::MAX_ELEMENTS){return new WP_Error('alify_ai_elementor_tree_too_large','Elementor document exceeds the safe element limit for connector editing.',array('status'=>413));}
                $id=(string)($node['id']??'');if(''===$id||isset($seen[$id])){return new WP_Error('alify_ai_duplicate_element_id','Elementor element IDs must be non-empty and unique.',array('status'=>400,'element_id'=>$id));}$seen[$id]=true;
                $type=(string)($node['elType']??'');if(!in_array($type,array('container','section','column','widget'),true)){return new WP_Error('alify_ai_invalid_element_type','Elementor document contains an unsupported element type.',array('status'=>400,'element_id'=>$id,'el_type'=>$type));}
                if(!isset($node['settings'])||!is_array($node['settings'])){return new WP_Error('alify_ai_invalid_elementor_settings','Elementor element settings must be an object/array.',array('status'=>400,'element_id'=>$id));}
                $children=$node['elements']??array();if(!is_array($children)){return new WP_Error('alify_ai_invalid_elementor_tree','Elementor element children must be an array.',array('status'=>400,'element_id'=>$id));}
                if('widget'===$type){$wt=sanitize_key((string)($node['widgetType']??''));if(''===$wt){return new WP_Error('alify_ai_widget_type_required','Widget elements require widgetType.',array('status'=>400,'element_id'=>$id));}if($validate_registry&&!self::widget_type_exists($wt)){return new WP_Error('alify_ai_widget_type_unknown','Elementor document references a widget type that is not registered on this site.',array('status'=>400,'element_id'=>$id,'widget_type'=>$wt));}}
                if($validate_registry){$instance=self::element_instance($node);if(is_wp_error($instance)){return $instance;}if(!$instance){$warnings[]=array('element_id'=>$id,'warning'=>'Element registry could not instantiate this element; structural validation was used as fallback.');}elseif('widget'===$type&&!empty($children)&&!self::widget_supports_nested($instance)){return new WP_Error('alify_ai_widget_children_not_supported','This Elementor widget does not support nested child elements.',array('status'=>400,'element_id'=>$id,'widget_type'=>$node['widgetType']??''));}}
                $child=$walk($children,$depth+1,$type);if(is_wp_error($child)){return $child;}
            }
            return true;
        };
        $ok=$walk($elements,0,'root');if(is_wp_error($ok)){return $ok;}return array('valid'=>true,'element_count'=>$count,'warnings'=>$warnings);
    }

    private static function normalize_new_element( array $element ) {
        $type=sanitize_key((string)($element['elType']??$element['el_type']??''));if(!in_array($type,array('container','section','column','widget'),true)){return new WP_Error('alify_ai_invalid_element_type','Element elType must be container, section, column or widget.',array('status'=>400));}
        $out=array('id'=>self::clean_element_id((string)($element['id']??'')),'elType'=>$type,'isInner'=>!empty($element['isInner'])||!empty($element['is_inner']),'settings'=>is_array($element['settings']??null)?$element['settings']:array(),'elements'=>array());
        if('widget'===$type){$wt=sanitize_key((string)($element['widgetType']??$element['widget_type']??''));if(''===$wt){return new WP_Error('alify_ai_widget_type_required','Widget elements require widgetType.',array('status'=>400));}if(!self::widget_type_exists($wt)){return new WP_Error('alify_ai_widget_type_unknown','The requested Elementor widget type is not registered on this site.',array('status'=>400,'widget_type'=>$wt));}$out['widgetType']=$wt;}
        $valid=self::validate_touched_settings($out,$out['settings']);if(is_wp_error($valid)){return $valid;}
        foreach((array)($element['elements']??array()) as $child){if(!is_array($child)){continue;}$child=self::normalize_new_element($child);if(is_wp_error($child)){return $child;}$out['elements'][]=$child;}
        return $out;
    }

    private static function insert_element( array $elements, string $parent_id, int $position, array $element ) {
        if(''===$parent_id||'root'===$parent_id){$at=min($position,count($elements));array_splice($elements,$at,0,array($element));return array_values($elements);}
        $found=false;$elements=self::map_element($elements,$parent_id,static function(array $parent)use($element,$position){$children=(array)($parent['elements']??array());$at=min($position,count($children));array_splice($children,$at,0,array($element));$parent['elements']=array_values($children);return $parent;},$found);if(!$found){return new WP_Error('alify_ai_element_not_found','Elementor parent element was not found.',array('status'=>404,'element_id'=>$parent_id));}return $elements;
    }

    private static function map_element( array $elements,string $target,callable $cb,bool &$found ):array{foreach($elements as $i=>$el){if(!is_array($el)){continue;}if((string)($el['id']??'')===$target){$elements[$i]=$cb($el);$found=true;continue;}if(!empty($el['elements'])&&is_array($el['elements'])){$el['elements']=self::map_element($el['elements'],$target,$cb,$found);$elements[$i]=$el;}}return $elements;}
    private static function remove_element(array $elements,string $target,bool &$found):array{foreach($elements as $i=>$el){if(!is_array($el)){continue;}if((string)($el['id']??'')===$target){array_splice($elements,$i,1);$found=true;return array_values($elements);}if(!empty($el['elements'])&&is_array($el['elements'])){$el['elements']=self::remove_element($el['elements'],$target,$found);$elements[$i]=$el;if($found){return $elements;}}}return $elements;}
    private static function find_element(array $elements,string $target,string $parent='root'){foreach($elements as $i=>$el){if(!is_array($el)){continue;}if((string)($el['id']??'')===$target){return array('element'=>$el,'parent_id'=>$parent,'index'=>$i);}if(!empty($el['elements'])&&is_array($el['elements'])){$r=self::find_element($el['elements'],$target,(string)($el['id']??''));if($r){return $r;}}}return null;}
    private static function element_contains_id(array $element,string $target):bool{foreach((array)($element['elements']??array()) as $child){if(!is_array($child)){continue;}if((string)($child['id']??'')===$target||self::element_contains_id($child,$target)){return true;}}return false;}
    private static function regenerate_ids(array $el):array{$el['id']=self::clean_element_id('');foreach((array)($el['elements']??array()) as $i=>$child){if(is_array($child)){$el['elements'][$i]=self::regenerate_ids($child);}}return $el;}

    private static function read_data( int $post_id ) {
        $doc=self::document_instance($post_id);
        if($doc&&method_exists($doc,'get_elements_data')){try{$data=$doc->get_elements_data();if(is_array($data)){return array_values($data);}}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'elementor_compatibility_fallback');}}
        $raw=get_post_meta($post_id,'_elementor_data',true);if(''===$raw||null===$raw){return new WP_Error('alify_ai_not_elementor_document','This content item does not contain Elementor document data.',array('status'=>409));}if(is_array($raw)){return array_values($raw);} $data=json_decode((string)$raw,true);if(JSON_ERROR_NONE!==json_last_error()||!is_array($data)){return new WP_Error('alify_ai_invalid_elementor_data','Elementor document data is not valid JSON.',array('status'=>500));}return array_values($data);
    }
    private static function read_page_settings(int $post_id):array{$raw=get_post_meta($post_id,self::PAGE_SETTINGS_META,true);return is_array($raw)?$raw:array();}

    private static function write_document(int $post_id,array $elements,array $settings){
        $encoded=wp_json_encode(array_values($elements),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(false===$encoded){return new WP_Error('alify_ai_elementor_encode_failed','Could not serialize Elementor document data.',array('status'=>500));}
        // Capture the exact persisted Elementor metadata before writing. Elementor's public
        // save API can partially mutate a document before throwing or before our verification
        // detects an incompatible save shape. Restore this snapshot on every failed write.
        $meta_before=self::elementor_meta_snapshot($post_id);
        $doc=self::document_instance($post_id);$saved_via_api=false;
        if($doc&&method_exists($doc,'save')){try{$result=$doc->save(array('elements'=>array_values($elements),'settings'=>$settings));if(false!==$result){$saved_via_api=true;}}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'elementor_document_api_save',array('post_id'=>$post_id));$saved_via_api=false;}}
        if(!$saved_via_api){try{update_post_meta($post_id,'_elementor_data',wp_slash($encoded));update_post_meta($post_id,self::PAGE_SETTINGS_META,$settings);update_post_meta($post_id,'_elementor_edit_mode','builder');if(defined('ELEMENTOR_VERSION')){update_post_meta($post_id,'_elementor_version',ELEMENTOR_VERSION);}self::after_save_fallback($post_id,$elements,$settings);}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'elementor_document_fallback_save',array('post_id'=>$post_id));self::restore_elementor_meta_snapshot($post_id,$meta_before);return new WP_Error('alify_ai_elementor_write_failed','Elementor document could not be saved; the previous document metadata was restored.',array('status'=>500));}}
        clean_post_cache($post_id);self::clear_cache($post_id);
        $stored=self::read_data($post_id);if(is_wp_error($stored)||!hash_equals(self::hash_data($elements),self::hash_data($stored))){self::restore_elementor_meta_snapshot($post_id,$meta_before);ALIFY_AI_Diagnostics::log('elementor_document_verification_failed',array('post_id'=>$post_id,'part'=>'elements'));return new WP_Error('alify_ai_elementor_write_failed','Elementor document data could not be verified after saving; the previous document metadata was restored.',array('status'=>500));}
        $stored_settings=self::read_page_settings($post_id);if(!hash_equals(self::hash_settings($settings),self::hash_settings($stored_settings))){self::restore_elementor_meta_snapshot($post_id,$meta_before);ALIFY_AI_Diagnostics::log('elementor_document_verification_failed',array('post_id'=>$post_id,'part'=>'settings'));return new WP_Error('alify_ai_elementor_settings_write_failed','Elementor page settings could not be verified after saving; the previous document metadata was restored.',array('status'=>500));}
        return true;
    }
    private static function elementor_meta_snapshot(int $post_id):array{$keys=array('_elementor_data',self::PAGE_SETTINGS_META,'_elementor_edit_mode','_elementor_version');$snapshot=array();foreach($keys as $key){$snapshot[$key]=array('exists'=>metadata_exists('post',$post_id,$key),'value'=>get_post_meta($post_id,$key,true));}return $snapshot;}
    private static function restore_elementor_meta_snapshot(int $post_id,array $snapshot):void{foreach($snapshot as $key=>$item){try{if(empty($item['exists'])){delete_post_meta($post_id,$key);}else{$value=$item['value']??'';if('_elementor_data'===$key&&is_string($value)){$value=wp_slash($value);}update_post_meta($post_id,$key,$value);}}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'elementor_document_restore',array('post_id'=>$post_id,'meta_key'=>$key));}}clean_post_cache($post_id);self::clear_cache($post_id);}
    private static function after_save_fallback(int $post_id,array $elements,array $settings):void{do_action('elementor/editor/after_save',$post_id,$elements);$doc=self::document_instance($post_id);if($doc){do_action('elementor/document/after_save',$doc,array('elements'=>$elements,'settings'=>$settings));}self::clear_cache($post_id);}

    private static function document_instance(int $post_id){$p=self::plugin();if(!$p||!isset($p->documents)||!method_exists($p->documents,'get')){return null;}try{return $p->documents->get($post_id);}catch(Throwable $e){return null;}}
    private static function plugin(){try{if(class_exists('\\Elementor\\Plugin')&&isset(\Elementor\Plugin::$instance)){return \Elementor\Plugin::$instance;}if(class_exists('\\Elementor\\Plugin')&&method_exists('\\Elementor\\Plugin','instance')){return \Elementor\Plugin::instance();}}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'elementor_compatibility_fallback');}return null;}
    private static function registered_widgets():array{$p=self::plugin();if(!$p||!isset($p->widgets_manager)||!method_exists($p->widgets_manager,'get_widget_types')){return array();}try{$types=$p->widgets_manager->get_widget_types();return is_array($types)?$types:array();}catch(Throwable $e){return array();}}
    private static function widget_type_exists(string $name):bool{$types=self::registered_widgets();return empty($types)?true:isset($types[$name]);}
    private static function widget_summary(string $name,$widget):array{$title=$name;$categories=array();$icon=null;try{if(is_object($widget)&&method_exists($widget,'get_title')){$title=(string)$widget->get_title();}if(is_object($widget)&&method_exists($widget,'get_categories')){$categories=array_values((array)$widget->get_categories());}if(is_object($widget)&&method_exists($widget,'get_icon')){$icon=(string)$widget->get_icon();}}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'elementor_compatibility_fallback');}$controls=self::controls_from_stack($widget);return array('name'=>$name,'title'=>$title,'icon'=>$icon,'categories'=>$categories,'control_count'=>count($controls),'supports_nested_elements'=>self::widget_supports_nested($widget));}
    private static function widget_supports_nested($widget):bool{try{if(class_exists('\Elementor\Modules\NestedElements\Base\Widget_Nested_Base')&&$widget instanceof \Elementor\Modules\NestedElements\Base\Widget_Nested_Base){return true;}if(is_object($widget)&&method_exists($widget,'get_default_children_elements')){return true;}if(is_object($widget)&&method_exists($widget,'get_config')){$c=$widget->get_config();if(is_array($c)&&(!empty($c['is_container'])||!empty($c['is_nested']))){return true;}}}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'elementor_compatibility_fallback');}return false;}
    private static function controls_from_stack($stack):array{try{if(is_object($stack)&&method_exists($stack,'get_controls')){$c=$stack->get_controls();return is_array($c)?$c:array();}}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'elementor_compatibility_fallback');}return array();}
    private static function controls_for_element(array $element):?array{$instance=self::element_instance($element);if(is_wp_error($instance)||!$instance){return null;}$c=self::controls_from_stack($instance);return empty($c)?null:$c;}
    private static function element_instance(array $element){$p=self::plugin();if(!$p){return null;}try{if('widget'===($element['elType']??'')&&isset($p->widgets_manager)&&method_exists($p->widgets_manager,'get_widget_types')){$types=$p->widgets_manager->get_widget_types();$wt=(string)($element['widgetType']??'');if(is_array($types)&&isset($types[$wt])){return $types[$wt];}}if(isset($p->elements_manager)&&method_exists($p->elements_manager,'create_element_instance')){$instance=$p->elements_manager->create_element_instance($element);if(!$instance){return new WP_Error('alify_ai_elementor_instance_failed','Elementor could not instantiate an element from this data.',array('status'=>400,'element_id'=>$element['id']??''));}return $instance;}}catch(Throwable $e){return new WP_Error('alify_ai_elementor_instance_failed','Elementor rejected the element definition.',array('status'=>400,'element_id'=>$element['id']??''));}return null;}

    private static function serialize_controls(array $controls):array{$out=array();foreach($controls as $name=>$c){if(!is_array($c)){continue;}$item=array('name'=>(string)$name,'type'=>(string)($c['type']??''),'label'=>isset($c['label'])?wp_strip_all_tags((string)$c['label']):null,'responsive'=>!empty($c['responsive']),'default'=>$c['default']??null,'options'=>isset($c['options'])&&is_array($c['options'])?array_keys($c['options']):null,'multiple'=>!empty($c['multiple']),'condition'=>$c['condition']??null,'min'=>$c['min']??null,'max'=>$c['max']??null,'step'=>$c['step']??null,'size_units'=>$c['size_units']??null);$out[]=$item;}return $out;}
    private static function breakpoint_names():array{$names=array('desktop');foreach(self::breakpoints() as $bp){$n=sanitize_key((string)($bp['name']??''));if($n&&!in_array($n,$names,true)){$names[]=$n;}}return $names;}

    private static function global_style_exists( string $style_type, string $style_id ): bool {
        $kit = self::active_kit();
        if ( is_wp_error( $kit ) ) { return false; }
        $settings = self::kit_settings( $kit, self::kit_id( $kit ) );
        $keys = 'colors' === $style_type ? array( 'system_colors','custom_colors' ) : array( 'system_typography','custom_typography' );
        foreach ( $keys as $key ) {
            foreach ( (array) ( $settings[$key] ?? array() ) as $item ) {
                if ( is_array( $item ) && (string) ( $item['_id'] ?? '' ) === $style_id ) { return true; }
            }
        }
        return false;
    }

    private static function active_kit(){if(!self::status()['active']){return new WP_Error('alify_ai_elementor_unavailable','Elementor is not active.',array('status'=>409));}$p=self::plugin();if(!$p||!isset($p->kits_manager)||!method_exists($p->kits_manager,'get_active_kit')){return new WP_Error('alify_ai_elementor_kit_unavailable','Elementor active Kit manager is unavailable.',array('status'=>409));}try{$kit=$p->kits_manager->get_active_kit();return $kit?:new WP_Error('alify_ai_elementor_kit_unavailable','No active Elementor Kit is available.',array('status'=>409));}catch(Throwable $e){return new WP_Error('alify_ai_elementor_kit_unavailable','Could not load active Elementor Kit.',array('status'=>500));}}
    private static function kit_id($kit):int{try{if(is_object($kit)&&method_exists($kit,'get_main_id')){return absint($kit->get_main_id());}if(is_object($kit)&&method_exists($kit,'get_id')){return absint($kit->get_id());}}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'elementor_compatibility_fallback');}return 0;}
    private static function kit_settings($kit,int $id):array{try{if(is_object($kit)&&method_exists($kit,'get_settings')){$s=$kit->get_settings();if(is_array($s)){return $s;}}}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'elementor_compatibility_fallback');}$raw=$id?get_post_meta($id,self::PAGE_SETTINGS_META,true):array();return is_array($raw)?$raw:array();}
    private static function write_kit_settings($kit,int $id,array $settings){try{if(is_object($kit)&&method_exists($kit,'save')){$r=$kit->save(array('settings'=>$settings));if(false!==$r){self::clear_cache($id);$stored=self::kit_settings($kit,$id);if(hash_equals(self::hash_settings($settings),self::hash_settings($stored))){return true;}}}if(is_object($kit)&&method_exists($kit,'update_settings')){$kit->update_settings($settings);self::clear_cache($id);$stored=self::kit_settings($kit,$id);if(hash_equals(self::hash_settings($settings),self::hash_settings($stored))){return true;}}update_post_meta($id,self::PAGE_SETTINGS_META,$settings);self::clear_cache($id);$stored=get_post_meta($id,self::PAGE_SETTINGS_META,true);if(is_array($stored)&&hash_equals(self::hash_settings($settings),self::hash_settings($stored))){return true;}}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'elementor_kit_write_settings',array('kit_id'=>$id));}return new WP_Error('alify_ai_elementor_kit_write_failed','Elementor global Kit settings could not be saved and verified.',array('status'=>500));}
    private static function validate_kit_globals(array $settings){foreach(array('system_colors','custom_colors') as $key){if(isset($settings[$key])&&!is_array($settings[$key])){return new WP_Error('alify_ai_invalid_global_colors',$key.' must be an array.',array('status'=>400));}foreach((array)($settings[$key]??array()) as $item){if(!is_array($item)||empty($item['_id'])||!isset($item['color'])){return new WP_Error('alify_ai_invalid_global_colors','Each Elementor global color requires _id and color.',array('status'=>400));}}}foreach(array('system_typography','custom_typography') as $key){if(isset($settings[$key])&&!is_array($settings[$key])){return new WP_Error('alify_ai_invalid_global_typography',$key.' must be an array.',array('status'=>400));}foreach((array)($settings[$key]??array()) as $item){if(!is_array($item)||empty($item['_id'])){return new WP_Error('alify_ai_invalid_global_typography','Each Elementor global typography item requires _id.',array('status'=>400));}}}return true;}

    private static function template_summary($post):array{$id=absint($post->ID);return array('id'=>$id,'title'=>$post->post_title,'status'=>$post->post_status,'type'=>(string)get_post_meta($id,self::TEMPLATE_TYPE_META,true),'modified_gmt'=>$post->post_modified_gmt,'author_id'=>absint($post->post_author));}
    private static function template_snapshot(int $id,array $elements,array $settings):array{$post=get_post($id);return array('id'=>$id,'title'=>$post?$post->post_title:'','status'=>$post?$post->post_status:'','type'=>(string)get_post_meta($id,self::TEMPLATE_TYPE_META,true),'elements'=>$elements,'page_settings'=>$settings);}
    private static function template_snapshot_from_public(int $id){$elements=self::read_data($id);if(is_wp_error($elements)){return array('id'=>$id);}$settings=self::read_page_settings($id);return self::template_snapshot($id,$elements,$settings);}
    private static function hash_template_snapshot(array $s):string{return hash('sha256',wp_json_encode(array('id'=>absint($s['id']??0),'title'=>(string)($s['title']??''),'status'=>(string)($s['status']??''),'type'=>(string)($s['type']??''),'elements'=>(array)($s['elements']??array()),'page_settings'=>(array)($s['page_settings']??array()))));}
    private static function template_type_exists(string $type):bool{$p=self::plugin();if(!$p||!isset($p->documents)||!method_exists($p->documents,'get_document_type')){return in_array($type,array('page','section','widget','container','header','footer','single','archive','popup'),true);}try{return(bool)$p->documents->get_document_type($type,false);}catch(Throwable $e){return false;}}
    private static function create_template(array $r){$p=self::plugin();if($p&&isset($p->templates_manager)&&method_exists($p->templates_manager,'get_source')){try{$source=$p->templates_manager->get_source('local');if($source&&method_exists($source,'save_item')){$id=$source->save_item(array('title'=>$r['title'],'type'=>$r['type'],'content'=>$r['elements'],'page_settings'=>$r['page_settings']));if(!is_wp_error($id)&&$id){if(($r['status']??'publish')!=='publish'){wp_update_post(array('ID'=>$id,'post_status'=>$r['status']));}return absint($id);}}}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'elementor_compatibility_fallback');}}
        $id=wp_insert_post(array('post_type'=>self::TEMPLATE_CPT,'post_title'=>$r['title'],'post_status'=>$r['status'],'meta_input'=>array('_elementor_edit_mode'=>'builder',self::TEMPLATE_TYPE_META=>$r['type'])),true);if(is_wp_error($id)||!$id){return is_wp_error($id)?$id:new WP_Error('alify_ai_template_create_failed','Could not create Elementor template.',array('status'=>500));}update_post_meta($id,self::TEMPLATE_TYPE_META,$r['type']);if(taxonomy_exists('elementor_library_type')){wp_set_object_terms($id,$r['type'],'elementor_library_type');}$w=self::write_document($id,(array)$r['elements'],(array)$r['page_settings']);if(is_wp_error($w)){wp_delete_post($id,true);return $w;}return absint($id);
    }
    private static function update_template(int $id,array $r){$postarr=array('ID'=>$id,'post_title'=>$r['title'],'post_status'=>$r['status']);$res=wp_update_post($postarr,true);if(is_wp_error($res)||!$res){return is_wp_error($res)?$res:new WP_Error('alify_ai_template_update_failed','Could not update Elementor template post.',array('status'=>500));}update_post_meta($id,self::TEMPLATE_TYPE_META,$r['type']);if(taxonomy_exists('elementor_library_type')){wp_set_object_terms($id,$r['type'],'elementor_library_type');}return self::write_document($id,(array)$r['elements'],(array)$r['page_settings']);}
    private static function restore_template_snapshot(array $s){$id=absint($s['id']??0);if(!$id||!get_post($id)){return new WP_Error('alify_ai_template_not_found','Elementor template no longer exists.',array('status'=>404));}$r=array('title'=>(string)$s['title'],'status'=>(string)$s['status'],'type'=>(string)$s['type'],'elements'=>(array)$s['elements'],'page_settings'=>(array)$s['page_settings']);$w=self::update_template($id,$r);return is_wp_error($w)?$w:true;}
    private static function restore_deleted_template_snapshot(array $s){$r=array('title'=>(string)$s['title'],'status'=>(string)$s['status'],'type'=>(string)$s['type'],'elements'=>(array)$s['elements'],'page_settings'=>(array)$s['page_settings']);return self::create_template($r);}

    private static function is_built_with_elementor(int $id):bool{$doc=self::document_instance($id);try{if($doc&&method_exists($doc,'is_built_with_elementor')){return(bool)$doc->is_built_with_elementor();}}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'elementor_compatibility_fallback');}return(bool)get_post_meta($id,'_elementor_edit_mode',true);}
    private static function summarize_elements(array $elements):array{$out=array();foreach($elements as $el){if(!is_array($el)){continue;}$out[]=array('id'=>(string)($el['id']??''),'el_type'=>(string)($el['elType']??''),'widget_type'=>isset($el['widgetType'])?(string)$el['widgetType']:null,'settings'=>self::summarize_settings((array)($el['settings']??array()),50),'children'=>self::summarize_elements((array)($el['elements']??array())));}return $out;}
    private static function summarize_settings(array $settings,int $limit=40):array{$out=array();$n=0;foreach($settings as $k=>$v){if($n++>=$limit){$out['_truncated']=true;break;}if(is_scalar($v)||null===$v){$text=(string)$v;$out[$k]=strlen($text)>500?substr($text,0,500).'…':$v;}elseif(is_array($v)&&count($v)<=20){$out[$k]=$v;}else{$out[$k]='[complex value]';}}return $out;}
    private static function count_elements(array $elements):int{$c=0;foreach($elements as $el){if(!is_array($el)){continue;}$c++;$c+=self::count_elements((array)($el['elements']??array()));}return $c;}
    private static function clean_element_id(string $id):string{$id=preg_replace('/[^a-zA-Z0-9_-]/','',$id);return''!==$id?substr($id,0,40):substr(md5(wp_generate_uuid4()),0,8);}
    private static function contains_unsafe_payload($v):bool{if(is_array($v)){foreach($v as $x){if(self::contains_unsafe_payload($x)){return true;}}return false;}if(!is_string($v)){return false;}return(bool)preg_match('/<\s*script\b|javascript\s*:|on(?:error|load|click|mouseover|focus|mouseenter|mouseleave|submit)\s*=/i',$v);}
    private static function hash_data(array $d):string{return hash('sha256',wp_json_encode(array_values($d)));}
    private static function hash_settings(array $d):string{return hash('sha256',wp_json_encode($d));}
    private static function hash_document(array $e,array $s):string{return hash('sha256',wp_json_encode(array('elements'=>array_values($e),'settings'=>$s)));}
    private static function save_revision_safe($post):void{try{if($post&&function_exists('wp_revisions_enabled')&&wp_revisions_enabled($post)){wp_save_post_revision($post->ID);}}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'elementor_compatibility_fallback');}}
    private static function clear_cache(int $post_id):void{$p=self::plugin();try{if($p&&isset($p->files_manager)&&method_exists($p->files_manager,'clear_cache')){$p->files_manager->clear_cache();}if(class_exists('\\Elementor\\Core\\Files\\CSS\\Post')&&$post_id){$css=\Elementor\Core\Files\CSS\Post::create($post_id);if($css&&method_exists($css,'delete')){$css->delete();}}}catch(Throwable $e){ALIFY_AI_Diagnostics::log_throwable($e,'elementor_compatibility_fallback');}}
}
