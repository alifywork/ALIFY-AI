<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ALIFY_AI_Menus {
    public static function list_menus(): array {
        $locations = get_nav_menu_locations();
        $out = array();
        foreach ( wp_get_nav_menus() as $menu ) {
            $assigned = array();
            foreach ( $locations as $location => $menu_id ) {
                if ( (int) $menu_id === (int) $menu->term_id ) {
                    $assigned[] = $location;
                }
            }
            $out[] = array(
                'id'          => (int) $menu->term_id,
                'name'        => (string) $menu->name,
                'slug'        => (string) $menu->slug,
                'description' => (string) $menu->description,
                'count'       => (int) $menu->count,
                'auto_add'    => self::menu_auto_add( (int) $menu->term_id ),
                'locations'   => array_values( $assigned ),
            );
        }
        return $out;
    }

    public static function get_menu( int $menu_id ) {
        $menu = wp_get_nav_menu_object( $menu_id );
        if ( ! $menu ) {
            return new WP_Error( 'alify_ai_menu_not_found', 'Menu not found.', array( 'status' => 404 ) );
        }
        return self::snapshot_menu( (int) $menu->term_id );
    }

    public static function create_menu( array $input ) {
        $name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
        if ( '' === $name ) {
            return new WP_Error( 'alify_ai_menu_name_required', 'Menu name is required.', array( 'status' => 400 ) );
        }
        $existing = wp_get_nav_menu_object( $name );
        if ( $existing ) {
            return new WP_Error( 'alify_ai_menu_exists', 'A menu with this name already exists.', array( 'status' => 409, 'menu_id' => (int) $existing->term_id ) );
        }

        $id = wp_create_nav_menu( $name );
        if ( is_wp_error( $id ) || ! $id ) {
            return is_wp_error( $id ) ? $id : new WP_Error( 'alify_ai_menu_create_failed', 'Menu could not be created.', array( 'status' => 500 ) );
        }
        $id = (int) $id;

        if ( array_key_exists( 'description', $input ) ) {
            $menu_data = array(
                'menu-name'   => $name,
                'description' => sanitize_textarea_field( (string) $input['description'] ),
            );
            $result = wp_update_nav_menu_object( $id, function_exists( 'wp_slash' ) ? wp_slash( $menu_data ) : $menu_data );
            if ( is_wp_error( $result ) ) {
                wp_delete_nav_menu( $id );
                return $result;
            }
        }
        if ( array_key_exists( 'slug', $input ) ) {
            $slug = sanitize_title( (string) $input['slug'] );
            if ( '' === $slug ) { wp_delete_nav_menu( $id ); return new WP_Error( 'alify_ai_invalid_menu_slug', 'Menu slug cannot be empty.', array( 'status' => 400 ) ); }
            $term_result = wp_update_term( $id, 'nav_menu', array( 'slug' => $slug ) );
            if ( is_wp_error( $term_result ) ) { wp_delete_nav_menu( $id ); return $term_result; }
        }
        if ( array_key_exists( 'locations', $input ) ) {
            $result = self::replace_menu_locations( $id, $input['locations'] );
            if ( is_wp_error( $result ) ) {
                wp_delete_nav_menu( $id );
                return $result;
            }
        }
        if ( array_key_exists( 'auto_add', $input ) ) {
            self::set_menu_auto_add( $id, (bool) $input['auto_add'] );
        }

        $after = self::snapshot_menu( $id );
        if ( is_wp_error( $after ) ) {
            wp_delete_nav_menu( $id );
            return $after;
        }
        $log_id = ALIFY_AI_Audit::log( 'create_menu', 'nav_menu', $id, null, $after );
        if ( ! $log_id ) {
            wp_delete_nav_menu( $id );
            self::set_menu_auto_add( $id, false );
            return new WP_Error( 'alify_ai_audit_failed', 'Menu creation was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'activity_id' => $log_id, 'menu' => $after );
    }

    public static function update_menu( int $menu_id, array $input ) {
        $menu = wp_get_nav_menu_object( $menu_id );
        if ( ! $menu ) {
            return new WP_Error( 'alify_ai_menu_not_found', 'Menu not found.', array( 'status' => 404 ) );
        }
        $before = self::snapshot_menu( $menu_id );
        if ( is_wp_error( $before ) ) return $before;

        $name = array_key_exists( 'name', $input ) ? sanitize_text_field( (string) $input['name'] ) : (string) $menu->name;
        if ( '' === $name ) {
            return new WP_Error( 'alify_ai_menu_name_required', 'Menu name cannot be empty.', array( 'status' => 400 ) );
        }
        $description = array_key_exists( 'description', $input ) ? sanitize_textarea_field( (string) $input['description'] ) : (string) $menu->description;
        if ( array_key_exists( 'name', $input ) || array_key_exists( 'description', $input ) ) {
            $menu_data = array( 'menu-name' => $name, 'description' => $description );
            $result = wp_update_nav_menu_object( $menu_id, function_exists( 'wp_slash' ) ? wp_slash( $menu_data ) : $menu_data );
            if ( is_wp_error( $result ) ) return $result;
        }

        if ( array_key_exists( 'slug', $input ) ) {
            $slug = sanitize_title( (string) $input['slug'] );
            if ( '' === $slug ) { $restore = self::restore_menu_metadata( $menu_id, $before ); if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'update_menu', $menu_id, 0, $restore ); } return new WP_Error( 'alify_ai_invalid_menu_slug', 'Menu slug cannot be empty.', array( 'status' => 400 ) ); }
            $term_result = wp_update_term( $menu_id, 'nav_menu', array( 'slug' => $slug ) );
            if ( is_wp_error( $term_result ) ) { $restore = self::restore_menu_metadata( $menu_id, $before ); if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'update_menu', $menu_id, 0, $restore ); } return $term_result; }
        }
        if ( array_key_exists( 'auto_add', $input ) ) {
            self::set_menu_auto_add( $menu_id, (bool) $input['auto_add'] );
        }
        if ( array_key_exists( 'locations', $input ) ) {
            $result = self::replace_menu_locations( $menu_id, $input['locations'] );
            if ( is_wp_error( $result ) ) {
                $restore = self::restore_menu_metadata( $menu_id, $before );
                if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'update_menu', $menu_id, 0, $restore ); }
                return $result;
            }
        }

        $after = self::snapshot_menu( $menu_id );
        if ( is_wp_error( $after ) ) {
            $restore = self::restore_menu_metadata( $menu_id, $before );
            if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'update_menu', $menu_id, 0, $restore ); }
            return $after;
        }
        $log_id = ALIFY_AI_Audit::log( 'update_menu', 'nav_menu', $menu_id, $before, $after );
        if ( ! $log_id ) {
            $restore = self::restore_menu_metadata( $menu_id, $before );
            if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'update_menu', $menu_id, 0, $restore ); }
            return new WP_Error( 'alify_ai_audit_failed', 'Menu update was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'activity_id' => $log_id, 'menu' => $after );
    }

    public static function delete_menu( int $menu_id, array $input ) {
        $menu = wp_get_nav_menu_object( $menu_id );
        if ( ! $menu ) {
            return new WP_Error( 'alify_ai_menu_not_found', 'Menu not found.', array( 'status' => 404 ) );
        }
        if ( empty( $input['confirm_delete'] ) ) {
            return new WP_Error( 'alify_ai_menu_delete_confirmation_required', 'Deleting a menu permanently removes its menu items. Set confirm_delete=true to continue.', array( 'status' => 409 ) );
        }
        $before = self::snapshot_menu( $menu_id );
        if ( is_wp_error( $before ) ) return $before;
        $valid = ALIFY_AI_Audit::validate_snapshot( $before, array( 'deleted' => true, 'id' => $menu_id ) );
        if ( is_wp_error( $valid ) ) return $valid;

        // Store rollback data before the destructive operation. Remove it if deletion fails.
        $log_id = ALIFY_AI_Audit::log( 'delete_menu', 'nav_menu', $menu_id, $before, array( 'deleted' => true, 'id' => $menu_id ) );
        if ( ! $log_id ) {
            return new WP_Error( 'alify_ai_audit_failed', 'Menu was not deleted because its rollback snapshot could not be stored.', array( 'status' => 500 ) );
        }
        $deleted = wp_delete_nav_menu( $menu_id );
        if ( is_wp_error( $deleted ) || ! $deleted ) {
            ALIFY_AI_Audit::delete_entry( $log_id );
            return is_wp_error( $deleted ) ? $deleted : new WP_Error( 'alify_ai_menu_delete_failed', 'Menu could not be deleted.', array( 'status' => 500 ) );
        }
        self::set_menu_auto_add( $menu_id, false );
        return array( 'activity_id' => $log_id, 'deleted' => true, 'menu_id' => $menu_id, 'item_count' => count( $before['items'] ?? array() ) );
    }

    public static function list_items( int $menu_id ): array {
        if ( ! wp_get_nav_menu_object( $menu_id ) ) return array();
        $items = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
        if ( ! is_array( $items ) ) return array();
        usort( $items, static fn( $a, $b ) => (int) $a->menu_order <=> (int) $b->menu_order );
        return array_map( array( __CLASS__, 'serialize_item' ), $items );
    }

    public static function create_item( int $menu_id, array $input ) {
        if ( ! wp_get_nav_menu_object( $menu_id ) ) {
            return new WP_Error( 'alify_ai_menu_not_found', 'Menu not found.', array( 'status' => 404 ) );
        }
        $args = self::item_args( $menu_id, $input, true, null );
        if ( is_wp_error( $args ) ) return $args;

        $id = wp_update_nav_menu_item( $menu_id, 0, $args );
        if ( is_wp_error( $id ) || ! $id ) {
            return is_wp_error( $id ) ? $id : new WP_Error( 'alify_ai_menu_item_save_failed', 'Menu item could not be created.', array( 'status' => 500 ) );
        }
        $id = (int) $id;
        $item = get_post( $id );
        if ( ! $item instanceof WP_Post ) {
            wp_delete_post( $id, true );
            return new WP_Error( 'alify_ai_menu_item_save_failed', 'Menu item could not be reloaded after creation.', array( 'status' => 500 ) );
        }
        $after = self::serialize_item( $item );
        $log_id = ALIFY_AI_Audit::log( 'create_menu_item', 'nav_menu_item', $id, null, array( 'menu_id' => $menu_id, 'item' => $after ) );
        if ( ! $log_id ) {
            wp_delete_post( $id, true );
            return new WP_Error( 'alify_ai_audit_failed', 'Menu item creation was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'activity_id' => $log_id, 'item' => $after );
    }

    public static function update_item( int $menu_id, int $item_id, array $input ) {
        $before = self::get_item_snapshot( $menu_id, $item_id );
        if ( is_wp_error( $before ) ) return $before;
        $args = self::item_args( $menu_id, $input, false, $before );
        if ( is_wp_error( $args ) ) return $args;

        $id = wp_update_nav_menu_item( $menu_id, $item_id, $args );
        if ( is_wp_error( $id ) || ! $id ) {
            return is_wp_error( $id ) ? $id : new WP_Error( 'alify_ai_menu_item_save_failed', 'Menu item could not be updated.', array( 'status' => 500 ) );
        }
        $saved = self::get_item_snapshot( $menu_id, $item_id );
        if ( is_wp_error( $saved ) ) {
            $restore = self::restore_item( $menu_id, $item_id, $before );
            if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'update_menu_item', $menu_id, $item_id, $restore ); }
            return $saved;
        }
        $log_id = ALIFY_AI_Audit::log( 'update_menu_item', 'nav_menu_item', $item_id, array( 'menu_id' => $menu_id, 'item' => $before ), array( 'menu_id' => $menu_id, 'item' => $saved ) );
        if ( ! $log_id ) {
            $restore = self::restore_item( $menu_id, $item_id, $before );
            if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'update_menu_item', $menu_id, $item_id, $restore ); }
            return new WP_Error( 'alify_ai_audit_failed', 'Menu item update was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'activity_id' => $log_id, 'item' => $saved );
    }

    public static function delete_item( int $menu_id, int $item_id, array $input ) {
        $before = self::get_item_snapshot( $menu_id, $item_id );
        if ( is_wp_error( $before ) ) return $before;
        if ( empty( $input['confirm_delete'] ) ) {
            return new WP_Error( 'alify_ai_menu_item_delete_confirmation_required', 'Set confirm_delete=true to permanently delete this menu item.', array( 'status' => 409 ) );
        }

        $children = self::child_item_ids( $menu_id, $item_id );
        $reparent_to = array_key_exists( 'reparent_children_to', $input ) ? absint( $input['reparent_children_to'] ) : null;
        if ( $children && null === $reparent_to ) {
            return new WP_Error( 'alify_ai_menu_item_has_children', 'This menu item has child items. Provide reparent_children_to (0 for top level) before deleting it.', array( 'status' => 409, 'child_item_ids' => $children ) );
        }
        if ( null !== $reparent_to ) {
            $valid_parent = self::validate_parent( $menu_id, $item_id, $reparent_to );
            if ( is_wp_error( $valid_parent ) ) return $valid_parent;
        }

        $child_snapshots = array();
        foreach ( $children as $child_id ) {
            $child = self::get_item_snapshot( $menu_id, $child_id );
            if ( is_wp_error( $child ) ) return $child;
            $child_snapshots[] = $child;
        }
        $snapshot = array( 'menu_id' => $menu_id, 'item' => $before, 'children' => $child_snapshots );
        $valid = ALIFY_AI_Audit::validate_snapshot( $snapshot, array( 'deleted' => true, 'item_id' => $item_id ) );
        if ( is_wp_error( $valid ) ) return $valid;

        // Reparent children first. Restore them if any later step fails.
        if ( $children ) {
            foreach ( $children as $child_id ) {
                $child = self::get_item_snapshot( $menu_id, $child_id );
                $args = self::snapshot_to_args( $child, $reparent_to );
                $result = wp_update_nav_menu_item( $menu_id, $child_id, $args );
                if ( is_wp_error( $result ) || ! $result ) {
                    $restore = self::restore_item_snapshots( $menu_id, $child_snapshots );
                    if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'delete_menu_item_reparent', $menu_id, $item_id, $restore ); }
                    return is_wp_error( $result ) ? $result : new WP_Error( 'alify_ai_menu_reparent_failed', 'Child menu items could not be reparented.', array( 'status' => 500 ) );
                }
            }
        }

        $log_id = ALIFY_AI_Audit::log( 'delete_menu_item', 'nav_menu_item', $item_id, $snapshot, array( 'deleted' => true, 'item_id' => $item_id, 'reparent_children_to' => $reparent_to ) );
        if ( ! $log_id ) {
            $restore = self::restore_item_snapshots( $menu_id, $child_snapshots );
            if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'delete_menu_item', $menu_id, $item_id, $restore ); }
            return new WP_Error( 'alify_ai_audit_failed', 'Menu item was not deleted because its rollback snapshot could not be stored.', array( 'status' => 500 ) );
        }
        $deleted = wp_delete_post( $item_id, true );
        if ( ! $deleted ) {
            ALIFY_AI_Audit::delete_entry( $log_id );
            $restore = self::restore_item_snapshots( $menu_id, $child_snapshots );
            if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'delete_menu_item', $menu_id, $item_id, $restore ); }
            return new WP_Error( 'alify_ai_menu_item_delete_failed', 'Menu item could not be deleted.', array( 'status' => 500 ) );
        }
        return array( 'activity_id' => $log_id, 'deleted' => true, 'item_id' => $item_id, 'reparented_children' => $children );
    }

    public static function reorder_items( int $menu_id, array $input ) {
        if ( ! wp_get_nav_menu_object( $menu_id ) ) {
            return new WP_Error( 'alify_ai_menu_not_found', 'Menu not found.', array( 'status' => 404 ) );
        }
        $changes = isset( $input['items'] ) && is_array( $input['items'] ) ? array_values( $input['items'] ) : array();
        if ( ! $changes ) {
            return new WP_Error( 'alify_ai_menu_reorder_items_required', 'items must contain at least one menu item.', array( 'status' => 400 ) );
        }
        $before_items = self::list_items( $menu_id );
        $by_id = array();
        foreach ( $before_items as $item ) $by_id[ (int) $item['id'] ] = $item;

        $seen = array();
        $proposed_parent = array();
        $proposed_position = array();
        foreach ( $by_id as $id => $item ) {
            $proposed_parent[ $id ] = (int) $item['parent_id'];
            $proposed_position[ $id ] = (int) $item['position'];
        }
        foreach ( $changes as $index => $change ) {
            if ( ! is_array( $change ) ) return new WP_Error( 'alify_ai_invalid_menu_reorder_item', 'Each reorder entry must be an object.', array( 'status' => 400 ) );
            $id = absint( $change['item_id'] ?? 0 );
            if ( ! $id || ! isset( $by_id[ $id ] ) ) return new WP_Error( 'alify_ai_menu_item_not_found', 'A reorder item does not belong to this menu.', array( 'status' => 404, 'item_id' => $id ) );
            if ( isset( $seen[ $id ] ) ) return new WP_Error( 'alify_ai_duplicate_menu_reorder_item', 'Each menu item may appear only once in a reorder request.', array( 'status' => 400, 'item_id' => $id ) );
            $seen[ $id ] = true;
            $proposed_position[ $id ] = array_key_exists( 'position', $change ) ? max( 1, absint( $change['position'] ) ) : ( $index + 1 );
            if ( array_key_exists( 'parent_id', $change ) ) $proposed_parent[ $id ] = absint( $change['parent_id'] );
        }
        if ( ! empty( $input['complete'] ) && count( $seen ) !== count( $by_id ) ) {
            return new WP_Error( 'alify_ai_incomplete_menu_reorder', 'complete=true requires every menu item exactly once.', array( 'status' => 400 ) );
        }
        if ( count( array_unique( array_values( $proposed_position ) ) ) !== count( $proposed_position ) ) {
            return new WP_Error( 'alify_ai_duplicate_menu_positions', 'Final menu-item positions must be unique.', array( 'status' => 400 ) );
        }
        $hierarchy = self::validate_hierarchy_map( $proposed_parent, array_keys( $by_id ) );
        if ( is_wp_error( $hierarchy ) ) return $hierarchy;

        foreach ( $changes as $change ) {
            $id = absint( $change['item_id'] ?? 0 );
            $snapshot = $by_id[ $id ];
            $args = self::snapshot_to_args( $snapshot, $proposed_parent[ $id ], $proposed_position[ $id ] );
            $result = wp_update_nav_menu_item( $menu_id, $id, $args );
            if ( is_wp_error( $result ) || ! $result ) {
                $restore = self::restore_item_snapshots( $menu_id, $before_items );
                if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'reorder_menu_items', $menu_id, $id, $restore ); }
                return is_wp_error( $result ) ? $result : new WP_Error( 'alify_ai_menu_reorder_failed', 'Menu reorder failed and was reverted.', array( 'status' => 500 ) );
            }
        }
        $after_items = self::list_items( $menu_id );
        $log_id = ALIFY_AI_Audit::log( 'reorder_menu_items', 'nav_menu', $menu_id, array( 'menu_id' => $menu_id, 'items' => $before_items ), array( 'menu_id' => $menu_id, 'items' => $after_items ) );
        if ( ! $log_id ) {
            $restore = self::restore_item_snapshots( $menu_id, $before_items );
            if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'reorder_menu_items', $menu_id, 0, $restore ); }
            return new WP_Error( 'alify_ai_audit_failed', 'Menu reorder was reverted because its activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'activity_id' => $log_id, 'items' => $after_items );
    }

    public static function locations(): array {
        $registered = get_registered_nav_menus();
        $assigned = get_nav_menu_locations();
        $out = array();
        foreach ( $registered as $key => $label ) {
            $out[] = array( 'key' => $key, 'label' => $label, 'menu_id' => (int) ( $assigned[ $key ] ?? 0 ) );
        }
        return $out;
    }

    public static function assign_location( string $location, int $menu_id ) {
        $location = sanitize_key( $location );
        $registered = get_registered_nav_menus();
        if ( ! isset( $registered[ $location ] ) ) return new WP_Error( 'alify_ai_invalid_menu_location', 'Theme menu location is not registered.', array( 'status' => 400 ) );
        if ( ! wp_get_nav_menu_object( $menu_id ) ) return new WP_Error( 'alify_ai_menu_not_found', 'Menu not found.', array( 'status' => 404 ) );
        $before = get_nav_menu_locations();
        $locations = $before;
        $locations[ $location ] = $menu_id;
        set_theme_mod( 'nav_menu_locations', $locations );
        $after = get_nav_menu_locations();
        if ( (int) ( $after[ $location ] ?? 0 ) !== $menu_id ) {
            set_theme_mod( 'nav_menu_locations', $before );
            return new WP_Error( 'alify_ai_menu_location_assign_failed', 'Menu location assignment could not be verified.', array( 'status' => 500 ) );
        }
        $log_id = ALIFY_AI_Audit::log( 'assign_menu_location', 'nav_menu', $menu_id, array( 'locations' => $before ), array( 'locations' => $after, 'location' => $location ) );
        if ( ! $log_id ) {
            set_theme_mod( 'nav_menu_locations', $before );
            return new WP_Error( 'alify_ai_audit_failed', 'Menu location assignment was reverted because its activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'activity_id' => $log_id, 'location' => $location, 'menu_id' => $menu_id );
    }

    public static function unassign_location( string $location ) {
        $location = sanitize_key( $location );
        $registered = get_registered_nav_menus();
        if ( ! isset( $registered[ $location ] ) ) return new WP_Error( 'alify_ai_invalid_menu_location', 'Theme menu location is not registered.', array( 'status' => 400 ) );
        $before = get_nav_menu_locations();
        $previous_menu_id = (int) ( $before[ $location ] ?? 0 );
        $locations = $before;
        unset( $locations[ $location ] );
        set_theme_mod( 'nav_menu_locations', $locations );
        $after = get_nav_menu_locations();
        if ( ! empty( $after[ $location ] ) ) {
            set_theme_mod( 'nav_menu_locations', $before );
            return new WP_Error( 'alify_ai_menu_location_unassign_failed', 'Menu location could not be unassigned.', array( 'status' => 500 ) );
        }
        $log_id = ALIFY_AI_Audit::log( 'unassign_menu_location', 'nav_menu', $previous_menu_id, array( 'locations' => $before ), array( 'locations' => $after, 'location' => $location ) );
        if ( ! $log_id ) {
            set_theme_mod( 'nav_menu_locations', $before );
            return new WP_Error( 'alify_ai_audit_failed', 'Menu location unassignment was reverted because its activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'activity_id' => $log_id, 'location' => $location, 'menu_id' => 0, 'previous_menu_id' => $previous_menu_id );
    }

    public static function rollback_activity( array $entry ) {
        $action = (string) ( $entry['action'] ?? '' );
        $before = is_array( $entry['before_json'] ?? null ) ? $entry['before_json'] : null;
        $after = is_array( $entry['after_json'] ?? null ) ? $entry['after_json'] : null;
        $object_id = absint( $entry['object_id'] ?? 0 );

        if ( 'create_menu' === $action ) {
            $menu = wp_get_nav_menu_object( $object_id );
            if ( ! $menu ) return new WP_Error( 'alify_ai_menu_not_found', 'Created menu no longer exists.', array( 'status' => 404 ) );
            $items = self::list_items( $object_id );
            if ( $items ) return new WP_Error( 'alify_ai_rollback_conflict', 'Cannot rollback menu creation while the menu contains items.', array( 'status' => 409 ) );
            $current = self::snapshot_menu( $object_id );
            $log_id = ALIFY_AI_Audit::log( 'rollback_create_menu', 'nav_menu', $object_id, $current, array( 'deleted' => true ) );
            if ( ! $log_id ) return new WP_Error( 'alify_ai_audit_failed', 'Rollback was not applied because its activity log could not be stored.', array( 'status' => 500 ) );
            $deleted = wp_delete_nav_menu( $object_id );
            if ( is_wp_error( $deleted ) || ! $deleted ) {
                ALIFY_AI_Audit::delete_entry( $log_id );
                return is_wp_error( $deleted ) ? $deleted : new WP_Error( 'alify_ai_rollback_failed', 'Created menu could not be removed.', array( 'status' => 500 ) );
            }
            self::set_menu_auto_add( $object_id, false );
            return array( 'message' => 'Created menu removed.', 'activity_id' => $log_id );
        }

        if ( 'update_menu' === $action && $before ) {
            if ( ! wp_get_nav_menu_object( $object_id ) ) return new WP_Error( 'alify_ai_menu_not_found', 'Menu no longer exists.', array( 'status' => 404 ) );
            $current = self::snapshot_menu( $object_id );
            $result = self::restore_menu_metadata( $object_id, $before );
            if ( is_wp_error( $result ) ) return $result;
            $restored = self::snapshot_menu( $object_id );
            $log_id = ALIFY_AI_Audit::log( 'rollback_update_menu', 'nav_menu', $object_id, $current, $restored );
            if ( ! $log_id ) {
                self::restore_menu_metadata( $object_id, $current );
                return new WP_Error( 'alify_ai_audit_failed', 'Menu rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) );
            }
            return array( 'message' => 'Menu update rolled back.', 'activity_id' => $log_id, 'menu' => $restored );
        }

        if ( 'delete_menu' === $action && $before ) {
            $restored = self::restore_deleted_menu_snapshot( $before );
            if ( is_wp_error( $restored ) ) return $restored;
            $new_id = absint( $restored['id'] ?? 0 );
            $log_id = ALIFY_AI_Audit::log( 'rollback_delete_menu', 'nav_menu', $new_id, null, $restored );
            if ( ! $log_id ) {
                if ( $new_id ) { wp_delete_nav_menu( $new_id ); self::set_menu_auto_add( $new_id, false ); }
                return new WP_Error( 'alify_ai_audit_failed', 'Deleted menu was recreated but rollback logging failed, so the recreation was removed.', array( 'status' => 500 ) );
            }
            return array( 'message' => 'Deleted menu recreated. WordPress assigned a new menu/item ID set.', 'activity_id' => $log_id, 'menu' => $restored );
        }

        if ( 'create_menu_item' === $action ) {
            $payload = is_array( $after ) ? $after : array();
            $menu_id = absint( $payload['menu_id'] ?? 0 );
            $item_id = $object_id;
            $current = self::get_item_snapshot( $menu_id, $item_id );
            if ( is_wp_error( $current ) ) return $current;
            $children = self::child_item_ids( $menu_id, $item_id );
            if ( $children ) return new WP_Error( 'alify_ai_rollback_conflict', 'Cannot rollback item creation while child items depend on it.', array( 'status' => 409, 'child_item_ids' => $children ) );
            $log_id = ALIFY_AI_Audit::log( 'rollback_create_menu_item', 'nav_menu_item', $item_id, array( 'menu_id' => $menu_id, 'item' => $current ), array( 'deleted' => true ) );
            if ( ! $log_id ) return new WP_Error( 'alify_ai_audit_failed', 'Rollback was not applied because its activity log could not be stored.', array( 'status' => 500 ) );
            if ( ! wp_delete_post( $item_id, true ) ) {
                ALIFY_AI_Audit::delete_entry( $log_id );
                return new WP_Error( 'alify_ai_rollback_failed', 'Created menu item could not be removed.', array( 'status' => 500 ) );
            }
            return array( 'message' => 'Created menu item removed.', 'activity_id' => $log_id );
        }

        if ( 'update_menu_item' === $action && $before ) {
            $menu_id = absint( $before['menu_id'] ?? 0 );
            $snapshot = is_array( $before['item'] ?? null ) ? $before['item'] : array();
            $current = self::get_item_snapshot( $menu_id, $object_id );
            if ( is_wp_error( $current ) ) return $current;
            $result = self::restore_item( $menu_id, $object_id, $snapshot );
            if ( is_wp_error( $result ) ) return $result;
            $restored = self::get_item_snapshot( $menu_id, $object_id );
            $log_id = ALIFY_AI_Audit::log( 'rollback_update_menu_item', 'nav_menu_item', $object_id, array( 'menu_id' => $menu_id, 'item' => $current ), array( 'menu_id' => $menu_id, 'item' => $restored ) );
            if ( ! $log_id ) {
                self::restore_item( $menu_id, $object_id, $current );
                return new WP_Error( 'alify_ai_audit_failed', 'Menu item rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) );
            }
            return array( 'message' => 'Menu item update rolled back.', 'activity_id' => $log_id, 'item' => $restored );
        }

        if ( 'delete_menu_item' === $action && $before ) {
            $restored = self::restore_deleted_item_snapshot( $before );
            if ( is_wp_error( $restored ) ) return $restored;
            $new_id = absint( $restored['item']['id'] ?? 0 );
            $log_id = ALIFY_AI_Audit::log( 'rollback_delete_menu_item', 'nav_menu_item', $new_id, null, $restored );
            if ( ! $log_id ) {
                if ( $new_id ) wp_delete_post( $new_id, true );
                return new WP_Error( 'alify_ai_audit_failed', 'Deleted menu item was recreated but rollback logging failed.', array( 'status' => 500 ) );
            }
            return array( 'message' => 'Deleted menu item recreated and child relationships restored.', 'activity_id' => $log_id, 'result' => $restored );
        }

        if ( 'reorder_menu_items' === $action && $before ) {
            $menu_id = absint( $before['menu_id'] ?? $object_id );
            $items = isset( $before['items'] ) && is_array( $before['items'] ) ? $before['items'] : array();
            $current = self::list_items( $menu_id );
            $result = self::restore_item_snapshots( $menu_id, $items );
            if ( is_wp_error( $result ) ) return $result;
            $restored = self::list_items( $menu_id );
            $log_id = ALIFY_AI_Audit::log( 'rollback_reorder_menu_items', 'nav_menu', $menu_id, array( 'items' => $current ), array( 'items' => $restored ) );
            if ( ! $log_id ) {
                self::restore_item_snapshots( $menu_id, $current );
                return new WP_Error( 'alify_ai_audit_failed', 'Menu reorder rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) );
            }
            return array( 'message' => 'Menu order/hierarchy rolled back.', 'activity_id' => $log_id, 'items' => $restored );
        }

        if ( in_array( $action, array( 'assign_menu_location', 'unassign_menu_location' ), true ) && $before ) {
            $current = get_nav_menu_locations();
            $target = is_array( $before['locations'] ?? null ) ? $before['locations'] : array();
            set_theme_mod( 'nav_menu_locations', $target );
            $restored = get_nav_menu_locations();
            $log_id = ALIFY_AI_Audit::log( 'rollback_' . $action, 'nav_menu', $object_id, array( 'locations' => $current ), array( 'locations' => $restored ) );
            if ( ! $log_id ) {
                set_theme_mod( 'nav_menu_locations', $current );
                return new WP_Error( 'alify_ai_audit_failed', 'Menu-location rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) );
            }
            return array( 'message' => 'Menu location assignment rolled back.', 'activity_id' => $log_id, 'locations' => $restored );
        }

        return new WP_Error( 'alify_ai_rollback_unsupported', 'Rollback is not supported for this menu activity.', array( 'status' => 400 ) );
    }

    private static function item_args( int $menu_id, array $input, bool $creating, ?array $existing ) {
        $state = is_array( $existing ) ? $existing : array();
        $title = array_key_exists( 'title', $input ) ? sanitize_text_field( (string) $input['title'] ) : (string) ( $state['title'] ?? '' );
        $parent_id = array_key_exists( 'parent_id', $input ) ? absint( $input['parent_id'] ) : absint( $state['parent_id'] ?? 0 );
        $position = array_key_exists( 'position', $input ) ? max( 1, absint( $input['position'] ) ) : absint( $state['position'] ?? 0 );
        $description = array_key_exists( 'description', $input ) ? sanitize_textarea_field( (string) $input['description'] ) : (string) ( $state['description'] ?? '' );
        $attr_title = array_key_exists( 'attr_title', $input ) ? sanitize_text_field( (string) $input['attr_title'] ) : (string) ( $state['attr_title'] ?? '' );
        $target = array_key_exists( 'target', $input ) ? (string) $input['target'] : (string) ( $state['target'] ?? '' );
        if ( ! in_array( $target, array( '', '_blank' ), true ) ) return new WP_Error( 'alify_ai_invalid_menu_target', 'target must be empty or _blank.', array( 'status' => 400 ) );
        $status = array_key_exists( 'status', $input ) ? sanitize_key( (string) $input['status'] ) : sanitize_key( (string) ( $state['status'] ?? 'publish' ) );
        if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) return new WP_Error( 'alify_ai_invalid_menu_item_status', 'status must be publish or draft.', array( 'status' => 400 ) );
        $xfn = array_key_exists( 'xfn', $input ) ? sanitize_text_field( (string) $input['xfn'] ) : (string) ( $state['xfn'] ?? '' );
        $classes_input = array_key_exists( 'classes', $input ) ? $input['classes'] : ( $state['classes'] ?? array() );
        $classes = self::sanitize_classes( $classes_input );

        $type = array_key_exists( 'type', $input ) ? sanitize_key( (string) $input['type'] ) : sanitize_key( (string) ( $state['type'] ?? '' ) );
        $object = array_key_exists( 'object', $input ) ? sanitize_key( (string) $input['object'] ) : sanitize_key( (string) ( $state['object'] ?? '' ) );
        $object_id = array_key_exists( 'object_id', $input ) ? absint( $input['object_id'] ) : absint( $state['object_id'] ?? 0 );
        $url = array_key_exists( 'url', $input ) ? esc_url_raw( (string) $input['url'] ) : esc_url_raw( (string) ( $state['url'] ?? '' ) );
        if ( $creating && '' === $type ) $type = '' !== $url ? 'custom' : 'post_type';

        $validation = self::validate_item_target( $type, $object, $object_id, $url );
        if ( is_wp_error( $validation ) ) return $validation;
        if ( 'custom' === $type && '' === $title ) return new WP_Error( 'alify_ai_menu_item_title_required', 'Title is required for custom menu items.', array( 'status' => 400 ) );

        $self_id = absint( $state['id'] ?? 0 );
        $parent_valid = self::validate_parent( $menu_id, $self_id, $parent_id );
        if ( is_wp_error( $parent_valid ) ) return $parent_valid;

        $slash = static fn( $value ) => function_exists( 'wp_slash' ) ? wp_slash( $value ) : $value;
        $args = array(
            'menu-item-title'       => $slash( $title ),
            'menu-item-parent-id'   => $parent_id,
            'menu-item-position'    => $position,
            'menu-item-description' => $slash( $description ),
            'menu-item-attr-title'  => $slash( $attr_title ),
            'menu-item-target'      => $target,
            'menu-item-classes'     => $classes,
            'menu-item-xfn'         => $xfn,
            'menu-item-status'      => $status,
            'menu-item-type'        => $type,
            'menu-item-object'      => $object,
            'menu-item-object-id'   => $object_id,
        );
        if ( 'custom' === $type ) $args['menu-item-url'] = $url;
        return $args;
    }

    private static function validate_item_target( string $type, string $object, int $object_id, string $url ) {
        if ( 'custom' === $type ) {
            if ( '' === $url ) return new WP_Error( 'alify_ai_invalid_menu_item', 'Custom menu items require a valid URL.', array( 'status' => 400 ) );
            return true;
        }
        if ( 'post_type' === $type ) {
            if ( ! $object || ! post_type_exists( $object ) ) return new WP_Error( 'alify_ai_invalid_menu_item_object', 'The post type does not exist.', array( 'status' => 400 ) );
            $post = get_post( $object_id );
            if ( ! $post || $post->post_type !== $object ) return new WP_Error( 'alify_ai_invalid_menu_item_object', 'object_id does not identify a post of the requested post type.', array( 'status' => 400 ) );
            return true;
        }
        if ( 'taxonomy' === $type ) {
            if ( ! $object || ! taxonomy_exists( $object ) ) return new WP_Error( 'alify_ai_invalid_menu_item_object', 'The taxonomy does not exist.', array( 'status' => 400 ) );
            $term = get_term( $object_id, $object );
            if ( ! $term || is_wp_error( $term ) ) return new WP_Error( 'alify_ai_invalid_menu_item_object', 'object_id does not identify a term in the requested taxonomy.', array( 'status' => 400 ) );
            return true;
        }
        if ( 'post_type_archive' === $type ) {
            if ( ! $object || ! post_type_exists( $object ) ) return new WP_Error( 'alify_ai_invalid_menu_item_object', 'The archive post type does not exist.', array( 'status' => 400 ) );
            $pto = get_post_type_object( $object );
            if ( ! $pto || empty( $pto->has_archive ) ) return new WP_Error( 'alify_ai_post_type_has_no_archive', 'This post type does not expose an archive.', array( 'status' => 400 ) );
            return true;
        }
        return new WP_Error( 'alify_ai_invalid_menu_item_type', 'type must be custom, post_type, taxonomy, or post_type_archive.', array( 'status' => 400 ) );
    }

    private static function validate_parent( int $menu_id, int $item_id, int $parent_id ) {
        if ( 0 === $parent_id ) return true;
        if ( $item_id && $parent_id === $item_id ) return new WP_Error( 'alify_ai_menu_parent_cycle', 'A menu item cannot be its own parent.', array( 'status' => 400 ) );
        $parent = self::get_item_snapshot( $menu_id, $parent_id );
        if ( is_wp_error( $parent ) ) return new WP_Error( 'alify_ai_invalid_menu_parent', 'Parent menu item must belong to the same menu.', array( 'status' => 400 ) );
        if ( $item_id ) {
            $seen = array( $item_id => true );
            $cursor = $parent_id;
            while ( $cursor ) {
                if ( isset( $seen[ $cursor ] ) ) return new WP_Error( 'alify_ai_menu_parent_cycle', 'This parent relationship would create a menu hierarchy cycle.', array( 'status' => 400 ) );
                $seen[ $cursor ] = true;
                $node = self::get_item_snapshot( $menu_id, $cursor );
                if ( is_wp_error( $node ) ) break;
                $cursor = absint( $node['parent_id'] ?? 0 );
            }
        }
        return true;
    }

    private static function validate_hierarchy_map( array $parents, array $valid_ids ) {
        $valid = array_fill_keys( array_map( 'intval', $valid_ids ), true );
        foreach ( $parents as $id => $parent ) {
            $id = (int) $id; $parent = (int) $parent;
            if ( $parent && ! isset( $valid[ $parent ] ) ) return new WP_Error( 'alify_ai_invalid_menu_parent', 'A proposed parent does not belong to this menu.', array( 'status' => 400, 'item_id' => $id, 'parent_id' => $parent ) );
            if ( $parent === $id ) return new WP_Error( 'alify_ai_menu_parent_cycle', 'A menu item cannot be its own parent.', array( 'status' => 400, 'item_id' => $id ) );
            $seen = array(); $cursor = $id;
            while ( ! empty( $parents[ $cursor ] ) ) {
                if ( isset( $seen[ $cursor ] ) ) return new WP_Error( 'alify_ai_menu_parent_cycle', 'Proposed menu hierarchy contains a cycle.', array( 'status' => 400, 'item_id' => $id ) );
                $seen[ $cursor ] = true;
                $cursor = (int) $parents[ $cursor ];
                if ( isset( $seen[ $cursor ] ) ) return new WP_Error( 'alify_ai_menu_parent_cycle', 'Proposed menu hierarchy contains a cycle.', array( 'status' => 400, 'item_id' => $id ) );
            }
        }
        return true;
    }

    private static function sanitize_classes( $value ): array {
        if ( is_string( $value ) ) $value = preg_split( '/\s+/', trim( $value ) );
        if ( ! is_array( $value ) ) return array();
        $out = array();
        foreach ( $value as $class ) {
            $class = sanitize_html_class( (string) $class );
            if ( '' !== $class ) $out[] = $class;
        }
        return array_values( array_unique( $out ) );
    }

    private static function compensation_failure( string $operation, int $menu_id, int $item_id, WP_Error $restore_error ): WP_Error {
        ALIFY_AI_Diagnostics::log(
            'Menu compensation failed; navigation state may be partially changed.',
            array(
                'operation' => $operation,
                'menu_id' => $menu_id,
                'item_id' => $item_id,
                'restore_code' => $restore_error->get_error_code(),
                'restore_message' => $restore_error->get_error_message(),
            )
        );
        return new WP_Error(
            'alify_ai_menu_unknown_state',
            'The menu operation failed and the previous state could not be fully restored. Verify the menu before retrying.',
            array( 'status' => 500, 'operation' => $operation, 'menu_id' => $menu_id, 'item_id' => $item_id, 'restore_error' => $restore_error->get_error_code() )
        );
    }

    private static function get_item_snapshot( int $menu_id, int $item_id ) {
        $item = get_post( $item_id );
        if ( ! $item instanceof WP_Post || 'nav_menu_item' !== $item->post_type ) return new WP_Error( 'alify_ai_menu_item_not_found', 'Menu item not found.', array( 'status' => 404 ) );
        $menu_terms = wp_get_post_terms( $item_id, 'nav_menu', array( 'fields' => 'ids' ) );
        if ( is_wp_error( $menu_terms ) || ! in_array( $menu_id, array_map( 'intval', (array) $menu_terms ), true ) ) return new WP_Error( 'alify_ai_menu_item_not_found', 'Menu item not found in this menu.', array( 'status' => 404 ) );
        return self::serialize_item( $item );
    }

    private static function child_item_ids( int $menu_id, int $parent_id ): array {
        $out = array();
        foreach ( self::list_items( $menu_id ) as $item ) if ( (int) $item['parent_id'] === $parent_id ) $out[] = (int) $item['id'];
        return $out;
    }

    private static function snapshot_menu( int $menu_id ) {
        $menu = wp_get_nav_menu_object( $menu_id );
        if ( ! $menu ) return new WP_Error( 'alify_ai_menu_not_found', 'Menu not found.', array( 'status' => 404 ) );
        $locations = array();
        foreach ( get_nav_menu_locations() as $location => $assigned ) if ( (int) $assigned === $menu_id ) $locations[] = $location;
        return array(
            'id'          => $menu_id,
            'name'        => (string) $menu->name,
            'slug'        => (string) $menu->slug,
            'description' => (string) $menu->description,
            'auto_add'    => self::menu_auto_add( $menu_id ),
            'locations'   => array_values( $locations ),
            'items'       => self::list_items( $menu_id ),
        );
    }

    private static function restore_menu_metadata( int $menu_id, array $snapshot ) {
        $menu_data = array(
            'menu-name'   => sanitize_text_field( (string) ( $snapshot['name'] ?? '' ) ),
            'description' => sanitize_textarea_field( (string) ( $snapshot['description'] ?? '' ) ),
        );
        $result = wp_update_nav_menu_object( $menu_id, function_exists( 'wp_slash' ) ? wp_slash( $menu_data ) : $menu_data );
        if ( is_wp_error( $result ) ) return $result;
        $slug = sanitize_title( (string) ( $snapshot['slug'] ?? '' ) );
        if ( '' !== $slug ) {
            $term_result = wp_update_term( $menu_id, 'nav_menu', array( 'slug' => $slug ) );
            if ( is_wp_error( $term_result ) ) return $term_result;
        }
        self::set_menu_auto_add( $menu_id, ! empty( $snapshot['auto_add'] ) );
        return self::replace_menu_locations( $menu_id, (array) ( $snapshot['locations'] ?? array() ) );
    }

    private static function replace_menu_locations( int $menu_id, $requested ) {
        if ( ! is_array( $requested ) ) return new WP_Error( 'alify_ai_invalid_menu_locations', 'locations must be an array.', array( 'status' => 400 ) );
        $registered = get_registered_nav_menus();
        $clean = array();
        foreach ( $requested as $location ) {
            $location = sanitize_key( (string) $location );
            if ( ! isset( $registered[ $location ] ) ) return new WP_Error( 'alify_ai_invalid_menu_location', 'One or more requested menu locations are not registered by the active theme.', array( 'status' => 400, 'location' => $location ) );
            $clean[] = $location;
        }
        $before = get_nav_menu_locations();
        $locations = $before;
        foreach ( $locations as $location => $assigned ) if ( (int) $assigned === $menu_id ) unset( $locations[ $location ] );
        $clean = array_values( array_unique( $clean ) );
        foreach ( $clean as $location ) $locations[ $location ] = $menu_id;
        set_theme_mod( 'nav_menu_locations', $locations );
        $after = get_nav_menu_locations();
        foreach ( $clean as $location ) {
            if ( (int) ( $after[ $location ] ?? 0 ) !== $menu_id ) {
                set_theme_mod( 'nav_menu_locations', $before );
                return new WP_Error( 'alify_ai_menu_location_assign_failed', 'One or more menu-location assignments could not be verified.', array( 'status' => 500, 'location' => $location ) );
            }
        }
        foreach ( $after as $location => $assigned ) {
            if ( (int) $assigned === $menu_id && ! in_array( $location, $clean, true ) ) {
                set_theme_mod( 'nav_menu_locations', $before );
                return new WP_Error( 'alify_ai_menu_location_replace_failed', 'Existing menu-location assignments could not be fully replaced.', array( 'status' => 500, 'location' => $location ) );
            }
        }
        return true;
    }

    private static function menu_auto_add( int $menu_id ): bool {
        $options = (array) get_option( 'nav_menu_options', array() );
        $ids = isset( $options['auto_add'] ) && is_array( $options['auto_add'] ) ? array_map( 'intval', $options['auto_add'] ) : array();
        return in_array( $menu_id, $ids, true );
    }

    private static function set_menu_auto_add( int $menu_id, bool $enabled ): void {
        $options = (array) get_option( 'nav_menu_options', array() );
        $ids = isset( $options['auto_add'] ) && is_array( $options['auto_add'] ) ? array_values( array_unique( array_map( 'intval', $options['auto_add'] ) ) ) : array();
        $ids = array_values( array_filter( $ids, static fn( $id ) => (int) $id !== $menu_id ) );
        if ( $enabled ) $ids[] = $menu_id;
        $options['auto_add'] = array_values( array_unique( array_map( 'intval', $ids ) ) );
        update_option( 'nav_menu_options', $options );
    }

    private static function restore_deleted_menu_snapshot( array $snapshot ) {
        $name = sanitize_text_field( (string) ( $snapshot['name'] ?? 'Restored Menu' ) );
        $base = $name; $suffix = 2;
        while ( wp_get_nav_menu_object( $name ) ) { $name = $base . ' (Restored ' . $suffix . ')'; $suffix++; }
        $new_id = wp_create_nav_menu( $name );
        if ( is_wp_error( $new_id ) || ! $new_id ) return is_wp_error( $new_id ) ? $new_id : new WP_Error( 'alify_ai_menu_restore_failed', 'Deleted menu could not be recreated.', array( 'status' => 500 ) );
        $new_id = (int) $new_id;
        $menu_data = array( 'menu-name' => $name, 'description' => (string) ( $snapshot['description'] ?? '' ) );
        wp_update_nav_menu_object( $new_id, function_exists( 'wp_slash' ) ? wp_slash( $menu_data ) : $menu_data );
        $restore_slug = sanitize_title( (string) ( $snapshot['slug'] ?? '' ) );
        if ( '' !== $restore_slug ) {
            $slug_result = wp_update_term( $new_id, 'nav_menu', array( 'slug' => $restore_slug ) );
            if ( is_wp_error( $slug_result ) ) { wp_delete_nav_menu( $new_id ); return $slug_result; }
        }
        self::set_menu_auto_add( $new_id, ! empty( $snapshot['auto_add'] ) );
        $map = array();
        $items = isset( $snapshot['items'] ) && is_array( $snapshot['items'] ) ? $snapshot['items'] : array();
        usort( $items, static fn( $a, $b ) => (int) ( $a['position'] ?? 0 ) <=> (int) ( $b['position'] ?? 0 ) );
        foreach ( $items as $item ) {
            $args = self::snapshot_to_args( $item, 0 );
            $id = wp_update_nav_menu_item( $new_id, 0, $args );
            if ( is_wp_error( $id ) || ! $id ) { wp_delete_nav_menu( $new_id ); self::set_menu_auto_add( $new_id, false ); return is_wp_error( $id ) ? $id : new WP_Error( 'alify_ai_menu_restore_failed', 'A menu item could not be recreated.', array( 'status' => 500 ) ); }
            $map[ (int) $item['id'] ] = (int) $id;
        }
        foreach ( $items as $item ) {
            $old_id = (int) $item['id']; $new_item_id = $map[ $old_id ];
            $old_parent = (int) ( $item['parent_id'] ?? 0 ); $new_parent = $old_parent && isset( $map[ $old_parent ] ) ? $map[ $old_parent ] : 0;
            $args = self::snapshot_to_args( $item, $new_parent );
            $result = wp_update_nav_menu_item( $new_id, $new_item_id, $args );
            if ( is_wp_error( $result ) || ! $result ) { wp_delete_nav_menu( $new_id ); self::set_menu_auto_add( $new_id, false ); return is_wp_error( $result ) ? $result : new WP_Error( 'alify_ai_menu_restore_failed', 'Menu hierarchy could not be restored.', array( 'status' => 500 ) ); }
        }
        $location_result = self::replace_menu_locations( $new_id, (array) ( $snapshot['locations'] ?? array() ) );
        if ( is_wp_error( $location_result ) ) { wp_delete_nav_menu( $new_id ); self::set_menu_auto_add( $new_id, false ); return $location_result; }
        return self::snapshot_menu( $new_id );
    }

    private static function restore_deleted_item_snapshot( array $snapshot ) {
        $menu_id = absint( $snapshot['menu_id'] ?? 0 );
        if ( ! wp_get_nav_menu_object( $menu_id ) ) return new WP_Error( 'alify_ai_menu_not_found', 'Original menu no longer exists.', array( 'status' => 404 ) );
        $item = is_array( $snapshot['item'] ?? null ) ? $snapshot['item'] : array();
        if ( ! $item ) return new WP_Error( 'alify_ai_rollback_failed', 'Deleted menu item snapshot is missing.', array( 'status' => 500 ) );
        $old_parent = absint( $item['parent_id'] ?? 0 );
        if ( $old_parent && is_wp_error( self::get_item_snapshot( $menu_id, $old_parent ) ) ) $old_parent = 0;
        $args = self::snapshot_to_args( $item, $old_parent );
        $new_id = wp_update_nav_menu_item( $menu_id, 0, $args );
        if ( is_wp_error( $new_id ) || ! $new_id ) return is_wp_error( $new_id ) ? $new_id : new WP_Error( 'alify_ai_rollback_failed', 'Deleted menu item could not be recreated.', array( 'status' => 500 ) );
        $new_id = (int) $new_id;
        $children = isset( $snapshot['children'] ) && is_array( $snapshot['children'] ) ? $snapshot['children'] : array();
        $current_children = array();
        foreach ( $children as $child ) {
            $child_id = absint( $child['id'] ?? 0 );
            if ( ! $child_id ) continue;
            $current = self::get_item_snapshot( $menu_id, $child_id );
            if ( is_wp_error( $current ) ) continue;
            $current_children[] = $current;
            $result = wp_update_nav_menu_item( $menu_id, $child_id, self::snapshot_to_args( $current, $new_id ) );
            if ( is_wp_error( $result ) || ! $result ) {
                self::restore_item_snapshots( $menu_id, $current_children );
                wp_delete_post( $new_id, true );
                return is_wp_error( $result ) ? $result : new WP_Error( 'alify_ai_rollback_failed', 'Child menu relationships could not be restored.', array( 'status' => 500 ) );
            }
        }
        $restored = self::get_item_snapshot( $menu_id, $new_id );
        return array( 'menu_id' => $menu_id, 'item' => $restored, 'old_item_id' => absint( $item['id'] ?? 0 ), 'new_item_id' => $new_id );
    }

    private static function restore_item( int $menu_id, int $item_id, array $snapshot ) {
        $result = wp_update_nav_menu_item( $menu_id, $item_id, self::snapshot_to_args( $snapshot ) );
        if ( is_wp_error( $result ) || ! $result ) return is_wp_error( $result ) ? $result : new WP_Error( 'alify_ai_menu_item_restore_failed', 'Menu item could not be restored.', array( 'status' => 500 ) );
        return true;
    }

    private static function restore_item_snapshots( int $menu_id, array $snapshots ) {
        foreach ( $snapshots as $snapshot ) {
            if ( ! is_array( $snapshot ) || empty( $snapshot['id'] ) ) continue;
            $result = self::restore_item( $menu_id, (int) $snapshot['id'], $snapshot );
            if ( is_wp_error( $result ) ) return $result;
        }
        return true;
    }

    private static function snapshot_to_args( array $snapshot, ?int $parent_override = null, ?int $position_override = null ): array {
        $slash = static fn( $value ) => function_exists( 'wp_slash' ) ? wp_slash( $value ) : $value;
        $args = array(
            'menu-item-title'       => $slash( (string) ( $snapshot['title'] ?? '' ) ),
            'menu-item-parent-id'   => null === $parent_override ? absint( $snapshot['parent_id'] ?? 0 ) : $parent_override,
            'menu-item-position'    => null === $position_override ? absint( $snapshot['position'] ?? 0 ) : $position_override,
            'menu-item-description' => $slash( (string) ( $snapshot['description'] ?? '' ) ),
            'menu-item-attr-title'  => $slash( (string) ( $snapshot['attr_title'] ?? '' ) ),
            'menu-item-target'      => '_blank' === (string) ( $snapshot['target'] ?? '' ) ? '_blank' : '',
            'menu-item-classes'     => self::sanitize_classes( $snapshot['classes'] ?? array() ),
            'menu-item-xfn'         => sanitize_text_field( (string) ( $snapshot['xfn'] ?? '' ) ),
            'menu-item-status'      => in_array( (string) ( $snapshot['status'] ?? 'publish' ), array( 'publish','draft' ), true ) ? (string) $snapshot['status'] : 'publish',
            'menu-item-type'        => sanitize_key( (string) ( $snapshot['type'] ?? 'custom' ) ),
            'menu-item-object'      => sanitize_key( (string) ( $snapshot['object'] ?? 'custom' ) ),
            'menu-item-object-id'   => absint( $snapshot['object_id'] ?? 0 ),
        );
        if ( 'custom' === $args['menu-item-type'] ) $args['menu-item-url'] = esc_url_raw( (string) ( $snapshot['url'] ?? '' ) );
        return $args;
    }

    private static function serialize_item( WP_Post $item ): array {
        $type = (string) get_post_meta( $item->ID, '_menu_item_type', true );
        $object = (string) get_post_meta( $item->ID, '_menu_item_object', true );
        $object_id = (int) get_post_meta( $item->ID, '_menu_item_object_id', true );
        $url = '';
        if ( 'custom' === $type ) {
            $url = (string) get_post_meta( $item->ID, '_menu_item_url', true );
        } elseif ( 'post_type' === $type && $object_id ) {
            $url = (string) get_permalink( $object_id );
        } elseif ( 'taxonomy' === $type && $object_id ) {
            $link = get_term_link( $object_id, $object );
            $url = is_wp_error( $link ) ? '' : (string) $link;
        } elseif ( 'post_type_archive' === $type && $object ) {
            $link = get_post_type_archive_link( $object );
            $url = $link ? (string) $link : '';
        }
        $classes = get_post_meta( $item->ID, '_menu_item_classes', true );
        return array(
            'id'          => (int) $item->ID,
            'title'       => (string) $item->post_title,
            'type'        => $type,
            'object'      => $object,
            'object_id'   => $object_id,
            'url'         => $url,
            'parent_id'   => (int) get_post_meta( $item->ID, '_menu_item_menu_item_parent', true ),
            'position'    => (int) $item->menu_order,
            'target'      => (string) get_post_meta( $item->ID, '_menu_item_target', true ),
            'status'      => (string) $item->post_status,
            'description' => (string) $item->post_content,
            'attr_title'  => (string) get_post_meta( $item->ID, '_menu_item_attr_title', true ),
            'classes'     => self::sanitize_classes( is_array( $classes ) ? $classes : array() ),
            'xfn'         => (string) get_post_meta( $item->ID, '_menu_item_xfn', true ),
        );
    }
}
