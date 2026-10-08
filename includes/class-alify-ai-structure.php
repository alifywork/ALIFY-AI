<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ALIFY_AI_Structure {
    private const OPTION_POST_TYPES = 'alify_ai_managed_post_types';
    private const OPTION_TAXONOMIES = 'alify_ai_managed_taxonomies';

    public static function init(): void {
        add_action( 'init', array( __CLASS__, 'register_managed_structures' ), 5 );
    }

    public static function register_managed_structures(): void {
        foreach ( self::get_managed_post_types() as $key => $definition ) {
            if ( ! post_type_exists( $key ) ) {
                register_post_type( $key, self::post_type_args( $definition ) );
            }
        }

        foreach ( self::get_managed_taxonomies() as $key => $definition ) {
            if ( ! taxonomy_exists( $key ) ) {
                $registered = register_taxonomy( $key, $definition['object_types'] ?? array( 'post' ), self::taxonomy_args( $definition ) );
                if ( ! is_wp_error( $registered ) ) {
                    foreach ( (array) ( $definition['object_types'] ?? array( 'post' ) ) as $object_type ) {
                        register_taxonomy_for_object_type( $key, $object_type );
                    }
                }
            }
        }
    }

    public static function get_managed_post_types(): array {
        $value = get_option( self::OPTION_POST_TYPES, array() );
        return is_array( $value ) ? $value : array();
    }

    public static function get_managed_taxonomies(): array {
        $value = get_option( self::OPTION_TAXONOMIES, array() );
        return is_array( $value ) ? $value : array();
    }

    public static function list_post_types(): array {
        $objects = get_post_types( array(), 'objects' );
        $managed = self::get_managed_post_types();
        $items   = array();

        foreach ( $objects as $key => $object ) {
            if ( in_array( $key, array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' ), true ) ) {
                continue;
            }
            $items[] = self::serialize_post_type_object( $key, $object, $managed[ $key ] ?? null );
        }

        return $items;
    }

    public static function get_post_type( string $key ) {
        $key = sanitize_key( $key );
        $object = get_post_type_object( $key );
        if ( ! $object ) {
            return new WP_Error( 'alify_ai_post_type_not_found', 'Post type not found.', array( 'status' => 404 ) );
        }
        $managed = self::get_managed_post_types();
        return self::serialize_post_type_object( $key, $object, $managed[ $key ] ?? null );
    }

    public static function upsert_post_type( array $input, ?string $existing_key = null ) {
        $key = sanitize_key( (string) ( $existing_key ?: ( $input['key'] ?? '' ) ) );
        if ( '' === $key || strlen( $key ) > 20 ) {
            return new WP_Error( 'alify_ai_invalid_post_type', 'Post type key is required and must be 20 characters or fewer.', array( 'status' => 400 ) );
        }
        if ( in_array( $key, array( 'post', 'page', 'attachment' ), true ) || str_starts_with( $key, 'wp_' ) ) {
            return new WP_Error( 'alify_ai_reserved_post_type', 'Built-in/reserved post types cannot be managed here.', array( 'status' => 400 ) );
        }

        $all = self::get_managed_post_types();
        if ( $existing_key && ! isset( $all[ $key ] ) ) {
            return new WP_Error( 'alify_ai_not_managed', 'That post type is not managed by ALIFY AI Connector.', array( 'status' => 404 ) );
        }
        if ( ! $existing_key && post_type_exists( $key ) && ! isset( $all[ $key ] ) ) {
            return new WP_Error( 'alify_ai_post_type_exists', 'A post type with that key already exists and is not managed by this connector.', array( 'status' => 409 ) );
        }

        $before = $all[ $key ] ?? null;
        $definition = self::normalize_post_type_definition( $key, $input, is_array( $before ) ? $before : array() );
        if ( is_wp_error( $definition ) ) {
            return $definition;
        }

        $snapshot_check = ALIFY_AI_Audit::validate_snapshot( $before, $definition );
        if ( is_wp_error( $snapshot_check ) ) {
            return $snapshot_check;
        }

        $old_taxonomies = is_array( $before ) ? (array) ( $before['taxonomies'] ?? array() ) : array();
        if ( $before && post_type_exists( $key ) && function_exists( 'unregister_post_type' ) ) {
            $removed = unregister_post_type( $key );
            if ( is_wp_error( $removed ) ) {
                return $removed;
            }
        }

        $registered = register_post_type( $key, self::post_type_args( $definition ) );
        if ( is_wp_error( $registered ) ) {
            if ( $before ) {
                register_post_type( $key, self::post_type_args( $before ) );
                self::sync_post_type_taxonomies( $key, array(), $old_taxonomies );
            }
            return $registered;
        }
        self::sync_post_type_taxonomies( $key, $old_taxonomies, (array) ( $definition['taxonomies'] ?? array() ) );

        $all[ $key ] = $definition;
        if ( ! update_option( self::OPTION_POST_TYPES, $all, false ) && get_option( self::OPTION_POST_TYPES, array() ) !== $all ) {
            $restore = self::restore_post_type_definition( $key, $before );
            if ( is_wp_error( $restore ) ) {
                ALIFY_AI_Diagnostics::log( 'structure_compensation_failed', array( 'kind' => 'post_type', 'key' => $key, 'stage' => 'store_failure', 'error' => $restore->get_error_message() ) );
                return new WP_Error( 'alify_ai_structure_unknown_state', 'Custom post type storage failed and compensation also failed; site state requires review.', array( 'status' => 500 ) );
            }
            return new WP_Error( 'alify_ai_structure_store_failed', 'Could not store the custom post type definition.', array( 'status' => 500 ) );
        }

        flush_rewrite_rules( false );
        $log_id = ALIFY_AI_Audit::log( $before ? 'update_post_type' : 'create_post_type', 'post_type', 0, $before, $definition );
        if ( ! $log_id ) {
            $restore = self::restore_post_type_definition( $key, $before );
            if ( is_wp_error( $restore ) ) {
                ALIFY_AI_Diagnostics::log( 'structure_compensation_failed', array( 'kind' => 'post_type', 'key' => $key, 'stage' => 'audit_failure', 'error' => $restore->get_error_message() ) );
                return new WP_Error( 'alify_ai_structure_unknown_state', 'Custom post type activity logging failed and compensation also failed; site state requires review.', array( 'status' => 500 ) );
            }
            return new WP_Error( 'alify_ai_audit_failed', 'Custom post type change was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }

        return self::get_post_type( $key );
    }

    public static function delete_post_type( string $key, array $input = array() ) {
        $key = sanitize_key( $key );
        $all = self::get_managed_post_types();
        if ( ! isset( $all[ $key ] ) ) {
            return new WP_Error( 'alify_ai_not_managed', 'Only ALIFY-managed custom post types can be deleted.', array( 'status' => 404 ) );
        }

        $count = self::post_type_content_count( $key );
        $force = ! empty( $input['force'] );
        $confirm = ! empty( $input['confirm_orphan_content'] );
        if ( $count > 0 && ! ( $force && $confirm ) ) {
            return new WP_Error(
                'alify_ai_post_type_has_content',
                'This post type still contains content. Delete/trash the content first, or explicitly confirm orphaning existing database rows.',
                array( 'status' => 409, 'content_count' => $count, 'requires' => array( 'force' => true, 'confirm_orphan_content' => true ) )
            );
        }

        $before = $all[ $key ];
        $snapshot_check = ALIFY_AI_Audit::validate_snapshot( $before, null );
        if ( is_wp_error( $snapshot_check ) ) {
            return $snapshot_check;
        }

        if ( post_type_exists( $key ) && function_exists( 'unregister_post_type' ) ) {
            $removed = unregister_post_type( $key );
            if ( is_wp_error( $removed ) ) {
                return $removed;
            }
        }
        foreach ( (array) ( $before['taxonomies'] ?? array() ) as $taxonomy ) {
            if ( taxonomy_exists( $taxonomy ) && function_exists( 'unregister_taxonomy_for_object_type' ) ) {
                unregister_taxonomy_for_object_type( $taxonomy, $key );
            }
        }

        unset( $all[ $key ] );
        if ( ! update_option( self::OPTION_POST_TYPES, $all, false ) && get_option( self::OPTION_POST_TYPES, array() ) !== $all ) {
            $restore = self::restore_post_type_definition( $key, $before );
            if ( is_wp_error( $restore ) ) {
                ALIFY_AI_Diagnostics::log( 'structure_compensation_failed', array( 'kind' => 'post_type', 'key' => $key, 'stage' => 'delete_store_failure', 'error' => $restore->get_error_message() ) );
                return new WP_Error( 'alify_ai_structure_unknown_state', 'Custom post type deletion storage failed and compensation also failed; site state requires review.', array( 'status' => 500 ) );
            }
            return new WP_Error( 'alify_ai_structure_store_failed', 'Could not remove the custom post type definition.', array( 'status' => 500 ) );
        }
        flush_rewrite_rules( false );

        $after = array( 'key' => $key, 'deleted' => true, 'orphaned_content_count' => $count );
        $log_id = ALIFY_AI_Audit::log( 'delete_post_type', 'post_type', 0, $before, $after );
        if ( ! $log_id ) {
            $restore = self::restore_post_type_definition( $key, $before );
            if ( is_wp_error( $restore ) ) {
                ALIFY_AI_Diagnostics::log( 'structure_compensation_failed', array( 'kind' => 'post_type', 'key' => $key, 'stage' => 'delete_audit_failure', 'error' => $restore->get_error_message() ) );
                return new WP_Error( 'alify_ai_structure_unknown_state', 'Custom post type deletion logging failed and compensation also failed; site state requires review.', array( 'status' => 500 ) );
            }
            return new WP_Error( 'alify_ai_audit_failed', 'Custom post type deletion was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }

        return array(
            'message'                => 'Custom post type registration deleted.',
            'key'                    => $key,
            'orphaned_content_count' => $count,
            'activity_id'            => $log_id,
        );
    }

    public static function restore_post_type_definition( string $key, ?array $definition ) {
        $key = sanitize_key( $key );
        $all = self::get_managed_post_types();
        $current = $all[ $key ] ?? null;

        if ( post_type_exists( $key ) && function_exists( 'unregister_post_type' ) ) {
            $removed = unregister_post_type( $key );
            if ( is_wp_error( $removed ) ) {
                return $removed;
            }
        }
        if ( is_array( $current ) ) {
            foreach ( (array) ( $current['taxonomies'] ?? array() ) as $taxonomy ) {
                if ( taxonomy_exists( $taxonomy ) && function_exists( 'unregister_taxonomy_for_object_type' ) ) {
                    unregister_taxonomy_for_object_type( $taxonomy, $key );
                }
            }
        }

        if ( null === $definition ) {
            unset( $all[ $key ] );
        } else {
            $registered = register_post_type( $key, self::post_type_args( $definition ) );
            if ( is_wp_error( $registered ) ) {
                return $registered;
            }
            self::sync_post_type_taxonomies( $key, array(), (array) ( $definition['taxonomies'] ?? array() ) );
            $all[ $key ] = $definition;
        }

        if ( ! update_option( self::OPTION_POST_TYPES, $all, false ) && get_option( self::OPTION_POST_TYPES, array() ) !== $all ) {
            return new WP_Error( 'alify_ai_structure_store_failed', 'Could not restore the custom post type definition.', array( 'status' => 500 ) );
        }
        flush_rewrite_rules( false );
        return true;
    }

    public static function post_type_content_count( string $key ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s", sanitize_key( $key ) ) );
    }

    public static function list_taxonomies(): array {
        $objects = get_taxonomies( array(), 'objects' );
        $managed = self::get_managed_taxonomies();
        $items   = array();

        foreach ( $objects as $key => $object ) {
            if ( in_array( $key, array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' ), true ) ) {
                continue;
            }
            $items[] = self::serialize_taxonomy_object( $key, $object, $managed[ $key ] ?? null );
        }
        return $items;
    }

    public static function get_taxonomy( string $key ) {
        $key = sanitize_key( $key );
        $object = get_taxonomy( $key );
        if ( ! $object ) {
            return new WP_Error( 'alify_ai_taxonomy_not_found', 'Taxonomy not found.', array( 'status' => 404 ) );
        }
        $managed = self::get_managed_taxonomies();
        return self::serialize_taxonomy_object( $key, $object, $managed[ $key ] ?? null );
    }

    public static function upsert_taxonomy( array $input, ?string $existing_key = null ) {
        $key = sanitize_key( (string) ( $existing_key ?: ( $input['key'] ?? '' ) ) );
        if ( '' === $key || strlen( $key ) > 32 ) {
            return new WP_Error( 'alify_ai_invalid_taxonomy', 'Taxonomy key is required and must be 32 characters or fewer.', array( 'status' => 400 ) );
        }
        if ( in_array( $key, array( 'category', 'post_tag', 'nav_menu', 'link_category', 'post_format' ), true ) || str_starts_with( $key, 'wp_' ) ) {
            return new WP_Error( 'alify_ai_reserved_taxonomy', 'Built-in/reserved taxonomies cannot be managed here.', array( 'status' => 400 ) );
        }

        $all = self::get_managed_taxonomies();
        if ( $existing_key && ! isset( $all[ $key ] ) ) {
            return new WP_Error( 'alify_ai_not_managed', 'That taxonomy is not managed by ALIFY AI Connector.', array( 'status' => 404 ) );
        }
        if ( ! $existing_key && taxonomy_exists( $key ) && ! isset( $all[ $key ] ) ) {
            return new WP_Error( 'alify_ai_taxonomy_exists', 'A taxonomy with that key already exists and is not managed by this connector.', array( 'status' => 409 ) );
        }

        $before = $all[ $key ] ?? null;
        $definition = self::normalize_taxonomy_definition( $key, $input, is_array( $before ) ? $before : array() );
        if ( is_wp_error( $definition ) ) {
            return $definition;
        }

        $snapshot_check = ALIFY_AI_Audit::validate_snapshot( $before, $definition );
        if ( is_wp_error( $snapshot_check ) ) {
            return $snapshot_check;
        }

        $old_post_type_defs = self::get_managed_post_types();
        if ( $before && taxonomy_exists( $key ) && function_exists( 'unregister_taxonomy' ) ) {
            $removed = unregister_taxonomy( $key );
            if ( is_wp_error( $removed ) || false === $removed ) {
                return is_wp_error( $removed ) ? $removed : new WP_Error( 'alify_ai_taxonomy_unregister_failed', 'Could not temporarily unregister the taxonomy for update.', array( 'status' => 500 ) );
            }
        }

        $registered = register_taxonomy( $key, $definition['object_types'], self::taxonomy_args( $definition ) );
        if ( is_wp_error( $registered ) ) {
            if ( $before ) {
                register_taxonomy( $key, $before['object_types'] ?? array(), self::taxonomy_args( $before ) );
            }
            return $registered;
        }
        foreach ( $definition['object_types'] as $object_type ) {
            if ( ! register_taxonomy_for_object_type( $key, $object_type ) ) {
                self::restore_taxonomy_definition( $key, $before );
                return new WP_Error( 'alify_ai_taxonomy_attach_failed', sprintf( 'Could not attach taxonomy to post type "%s".', $object_type ), array( 'status' => 500 ) );
            }
        }

        $all[ $key ] = $definition;
        if ( ! update_option( self::OPTION_TAXONOMIES, $all, false ) && get_option( self::OPTION_TAXONOMIES, array() ) !== $all ) {
            self::restore_taxonomy_definition( $key, $before );
            return new WP_Error( 'alify_ai_structure_store_failed', 'Could not store the taxonomy definition.', array( 'status' => 500 ) );
        }
        if ( ! self::sync_managed_post_type_taxonomy_refs( $key, (array) ( $definition['object_types'] ?? array() ) ) ) {
            update_option( self::OPTION_POST_TYPES, $old_post_type_defs, false );
            self::restore_taxonomy_definition( $key, $before );
            return new WP_Error( 'alify_ai_structure_store_failed', 'Could not persist taxonomy associations to managed post types.', array( 'status' => 500 ) );
        }

        flush_rewrite_rules( false );
        $log_id = ALIFY_AI_Audit::log( $before ? 'update_taxonomy' : 'create_taxonomy', 'taxonomy', 0, $before, $definition );
        if ( ! $log_id ) {
            update_option( self::OPTION_POST_TYPES, $old_post_type_defs, false );
            $restore = self::restore_taxonomy_definition( $key, $before );
            if ( is_wp_error( $restore ) ) {
                ALIFY_AI_Diagnostics::log( 'structure_compensation_failed', array( 'kind' => 'taxonomy', 'key' => $key, 'stage' => 'audit_failure', 'error' => $restore->get_error_message() ) );
                return new WP_Error( 'alify_ai_structure_unknown_state', 'Taxonomy activity logging failed and compensation also failed; site state requires review.', array( 'status' => 500 ) );
            }
            return new WP_Error( 'alify_ai_audit_failed', 'Taxonomy change was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }

        return self::get_taxonomy( $key );
    }

    public static function attach_taxonomy_object_type( string $key, string $object_type ) {
        $key = sanitize_key( $key );
        $object_type = sanitize_key( $object_type );
        $all = self::get_managed_taxonomies();
        if ( ! isset( $all[ $key ] ) ) {
            return new WP_Error( 'alify_ai_not_managed', 'Durable association changes are supported only for ALIFY-managed taxonomies.', array( 'status' => 404 ) );
        }
        if ( ! post_type_exists( $object_type ) ) {
            return new WP_Error( 'alify_ai_post_type_not_found', 'Post type not found.', array( 'status' => 404 ) );
        }
        $types = array_values( array_unique( array_merge( (array) ( $all[ $key ]['object_types'] ?? array() ), array( $object_type ) ) ) );
        return self::upsert_taxonomy( array( 'object_types' => $types ), $key );
    }

    public static function detach_taxonomy_object_type( string $key, string $object_type ) {
        $key = sanitize_key( $key );
        $object_type = sanitize_key( $object_type );
        $all = self::get_managed_taxonomies();
        if ( ! isset( $all[ $key ] ) ) {
            return new WP_Error( 'alify_ai_not_managed', 'Durable association changes are supported only for ALIFY-managed taxonomies.', array( 'status' => 404 ) );
        }
        $types = array_values( array_diff( (array) ( $all[ $key ]['object_types'] ?? array() ), array( $object_type ) ) );
        return self::upsert_taxonomy( array( 'object_types' => $types ), $key );
    }

    public static function delete_taxonomy( string $key, array $input = array() ) {
        $key = sanitize_key( $key );
        $all = self::get_managed_taxonomies();
        if ( ! isset( $all[ $key ] ) ) {
            return new WP_Error( 'alify_ai_not_managed', 'Only ALIFY-managed taxonomies can be deleted.', array( 'status' => 404 ) );
        }

        $count = self::taxonomy_term_count( $key );
        if ( is_wp_error( $count ) ) return $count;
        if ( $count > 0 && ! ( ! empty( $input['force'] ) && ! empty( $input['confirm_orphan_terms'] ) ) ) {
            return new WP_Error(
                'alify_ai_taxonomy_has_terms',
                'This taxonomy still contains terms. Delete the terms first, or explicitly confirm orphaning the existing term rows.',
                array( 'status' => 409, 'term_count' => $count, 'requires' => array( 'force' => true, 'confirm_orphan_terms' => true ) )
            );
        }

        $before = $all[ $key ];
        $snapshot_check = ALIFY_AI_Audit::validate_snapshot( $before, array( 'key' => $key, 'deleted' => true, 'orphaned_term_count' => $count ) );
        if ( is_wp_error( $snapshot_check ) ) return $snapshot_check;

        $old_post_type_defs = self::get_managed_post_types();
        if ( taxonomy_exists( $key ) && function_exists( 'unregister_taxonomy' ) ) {
            $removed = unregister_taxonomy( $key );
            if ( is_wp_error( $removed ) || false === $removed ) {
                return is_wp_error( $removed ) ? $removed : new WP_Error( 'alify_ai_taxonomy_unregister_failed', 'Could not unregister the taxonomy.', array( 'status' => 500 ) );
            }
        }

        unset( $all[ $key ] );
        if ( ! update_option( self::OPTION_TAXONOMIES, $all, false ) && get_option( self::OPTION_TAXONOMIES, array() ) !== $all ) {
            self::restore_taxonomy_definition( $key, $before );
            return new WP_Error( 'alify_ai_structure_store_failed', 'Could not remove the taxonomy definition.', array( 'status' => 500 ) );
        }
        if ( ! self::sync_managed_post_type_taxonomy_refs( $key, array() ) ) {
            update_option( self::OPTION_POST_TYPES, $old_post_type_defs, false );
            self::restore_taxonomy_definition( $key, $before );
            return new WP_Error( 'alify_ai_structure_store_failed', 'Could not remove taxonomy associations from managed post types.', array( 'status' => 500 ) );
        }
        flush_rewrite_rules( false );

        $after = array( 'key' => $key, 'deleted' => true, 'orphaned_term_count' => $count );
        $log_id = ALIFY_AI_Audit::log( 'delete_taxonomy', 'taxonomy', 0, $before, $after );
        if ( ! $log_id ) {
            $refs_restored = update_option( self::OPTION_POST_TYPES, $old_post_type_defs, false ) || get_option( self::OPTION_POST_TYPES, array() ) === $old_post_type_defs;
            $restore = self::restore_taxonomy_definition( $key, $before );
            if ( ! $refs_restored || is_wp_error( $restore ) ) {
                ALIFY_AI_Diagnostics::log( 'structure_compensation_failed', array( 'kind' => 'taxonomy', 'key' => $key, 'stage' => 'delete_audit_failure', 'restore_error' => is_wp_error( $restore ) ? $restore->get_error_message() : '' ) );
                return new WP_Error( 'alify_ai_structure_unknown_state', 'Taxonomy deletion logging failed and compensation could not be fully verified; site state requires review.', array( 'status' => 500 ) );
            }
            return new WP_Error( 'alify_ai_audit_failed', 'Taxonomy deletion was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }

        return array( 'message' => 'Taxonomy registration deleted.', 'key' => $key, 'orphaned_term_count' => $count, 'activity_id' => $log_id );
    }

    public static function restore_taxonomy_definition( string $key, ?array $definition ) {
        $key = sanitize_key( $key );
        $all = self::get_managed_taxonomies();
        $current = $all[ $key ] ?? null;
        $old_post_type_defs = self::get_managed_post_types();

        if ( taxonomy_exists( $key ) && function_exists( 'unregister_taxonomy' ) ) {
            $removed = unregister_taxonomy( $key );
            if ( is_wp_error( $removed ) || false === $removed ) {
                return is_wp_error( $removed ) ? $removed : new WP_Error( 'alify_ai_taxonomy_unregister_failed', 'Could not unregister the current taxonomy definition.', array( 'status' => 500 ) );
            }
        }

        if ( null === $definition ) {
            unset( $all[ $key ] );
        } else {
            $registered = register_taxonomy( $key, (array) ( $definition['object_types'] ?? array() ), self::taxonomy_args( $definition ) );
            if ( is_wp_error( $registered ) ) return $registered;
            foreach ( (array) ( $definition['object_types'] ?? array() ) as $object_type ) {
                register_taxonomy_for_object_type( $key, $object_type );
            }
            $all[ $key ] = $definition;
        }

        if ( ! update_option( self::OPTION_TAXONOMIES, $all, false ) && get_option( self::OPTION_TAXONOMIES, array() ) !== $all ) {
            return new WP_Error( 'alify_ai_structure_store_failed', 'Could not restore the taxonomy definition.', array( 'status' => 500 ) );
        }
        if ( ! self::sync_managed_post_type_taxonomy_refs( $key, null === $definition ? array() : (array) ( $definition['object_types'] ?? array() ) ) ) {
            update_option( self::OPTION_POST_TYPES, $old_post_type_defs, false );
            if ( null !== $current ) update_option( self::OPTION_TAXONOMIES, array_merge( $all, array( $key => $current ) ), false );
            return new WP_Error( 'alify_ai_structure_store_failed', 'Could not restore taxonomy associations.', array( 'status' => 500 ) );
        }
        flush_rewrite_rules( false );
        return true;
    }

    public static function taxonomy_term_count( string $key ) {
        if ( ! taxonomy_exists( $key ) ) {
            return new WP_Error( 'alify_ai_taxonomy_not_found', 'Taxonomy not found.', array( 'status' => 404 ) );
        }
        $count = wp_count_terms( array( 'taxonomy' => $key, 'hide_empty' => false ) );
        return is_wp_error( $count ) ? $count : (int) $count;
    }

    public static function list_terms( string $taxonomy, int $per_page = 100, string $search = '', int $page = 1, ?int $parent = null ) {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return new WP_Error( 'alify_ai_taxonomy_not_found', 'Taxonomy not found.', array( 'status' => 404 ) );
        }
        $per_page = max( 1, min( 100, $per_page ) );
        $page = max( 1, $page );
        $args = array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => $per_page,
            'offset'     => ( $page - 1 ) * $per_page,
            'search'     => sanitize_text_field( $search ),
            'orderby'    => 'name',
            'order'      => 'ASC',
        );
        if ( null !== $parent ) $args['parent'] = max( 0, $parent );
        $terms = get_terms( $args );
        if ( is_wp_error( $terms ) ) return $terms;
        return array_map( array( __CLASS__, 'serialize_term' ), $terms );
    }

    public static function get_term( string $taxonomy, int $term_id ) {
        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'alify_ai_term_not_found', 'Term not found.', array( 'status' => 404 ) );
        }
        return self::serialize_term( $term );
    }

    public static function create_term( string $taxonomy, array $input ) {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return new WP_Error( 'alify_ai_taxonomy_not_found', 'Taxonomy not found.', array( 'status' => 404 ) );
        }
        $name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
        if ( '' === $name ) {
            return new WP_Error( 'alify_ai_term_name_required', 'Term name is required.', array( 'status' => 400 ) );
        }
        $args = array();
        if ( isset( $input['slug'] ) ) $args['slug'] = sanitize_title( (string) $input['slug'] );
        if ( isset( $input['description'] ) ) $args['description'] = sanitize_textarea_field( (string) $input['description'] );
        if ( isset( $input['parent'] ) ) {
            $parent_check = self::validate_term_parent( $taxonomy, absint( $input['parent'] ) );
            if ( is_wp_error( $parent_check ) ) return $parent_check;
            $args['parent'] = absint( $input['parent'] );
        }
        $result = wp_insert_term( $name, $taxonomy, $args );
        if ( is_wp_error( $result ) ) return $result;
        $term = get_term( (int) $result['term_id'], $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            wp_delete_term( (int) $result['term_id'], $taxonomy );
            return new WP_Error( 'alify_ai_term_read_failed', 'The term was created but could not be verified, so it was removed.', array( 'status' => 500 ) );
        }
        $after = self::serialize_term( $term );
        $snapshot_check = ALIFY_AI_Audit::validate_snapshot( null, $after );
        if ( is_wp_error( $snapshot_check ) ) {
            wp_delete_term( (int) $term->term_id, $taxonomy );
            return $snapshot_check;
        }
        $log_id = ALIFY_AI_Audit::log( 'create_term', 'term', (int) $term->term_id, null, $after );
        if ( ! $log_id ) {
            wp_delete_term( (int) $term->term_id, $taxonomy );
            return new WP_Error( 'alify_ai_audit_failed', 'Could not record the taxonomy change; the new term was removed.', array( 'status' => 500 ) );
        }
        return $after + array( 'activity_id' => $log_id );
    }

    public static function update_term( string $taxonomy, int $term_id, array $input ) {
        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'alify_ai_term_not_found', 'Term not found.', array( 'status' => 404 ) );
        }
        $before = self::serialize_term( $term );
        $args = array();
        foreach ( array( 'name', 'description' ) as $field ) {
            if ( array_key_exists( $field, $input ) ) {
                $args[ $field ] = 'name' === $field ? sanitize_text_field( (string) $input[ $field ] ) : sanitize_textarea_field( (string) $input[ $field ] );
            }
        }
        if ( array_key_exists( 'slug', $input ) ) $args['slug'] = sanitize_title( (string) $input['slug'] );
        if ( array_key_exists( 'parent', $input ) ) {
            $parent = absint( $input['parent'] );
            if ( $parent === $term_id ) return new WP_Error( 'alify_ai_invalid_term_parent', 'A term cannot be its own parent.', array( 'status' => 400 ) );
            $parent_check = self::validate_term_parent( $taxonomy, $parent, $term_id );
            if ( is_wp_error( $parent_check ) ) return $parent_check;
            $args['parent'] = $parent;
        }
        if ( empty( $args ) ) return new WP_Error( 'alify_ai_no_changes', 'No supported fields were supplied.', array( 'status' => 400 ) );

        $result = wp_update_term( $term_id, $taxonomy, $args );
        if ( is_wp_error( $result ) ) return $result;
        $updated_term = get_term( $term_id, $taxonomy );
        if ( ! $updated_term || is_wp_error( $updated_term ) ) {
            self::restore_term_snapshot( $taxonomy, $term_id, $before );
            return new WP_Error( 'alify_ai_term_read_failed', 'The updated term could not be verified; the original term was restored.', array( 'status' => 500 ) );
        }
        $after = self::serialize_term( $updated_term );
        $snapshot_check = ALIFY_AI_Audit::validate_snapshot( $before, $after );
        if ( is_wp_error( $snapshot_check ) ) {
            self::restore_term_snapshot( $taxonomy, $term_id, $before );
            return $snapshot_check;
        }
        $log_id = ALIFY_AI_Audit::log( 'update_term', 'term', $term_id, $before, $after );
        if ( ! $log_id ) {
            self::restore_term_snapshot( $taxonomy, $term_id, $before );
            return new WP_Error( 'alify_ai_audit_failed', 'Could not record the taxonomy change; the original term was restored.', array( 'status' => 500 ) );
        }
        return $after + array( 'activity_id' => $log_id );
    }

    public static function delete_term( string $taxonomy, int $term_id, array $input = array() ) {
        if ( empty( $input['confirm_delete'] ) ) {
            return new WP_Error( 'alify_ai_confirmation_required', 'Term deletion is destructive. Set confirm_delete=true to continue.', array( 'status' => 409 ) );
        }
        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) return new WP_Error( 'alify_ai_term_not_found', 'Term not found.', array( 'status' => 404 ) );

        $before = self::snapshot_term_for_delete( $term );
        if ( is_wp_error( $before ) ) return $before;
        $after = array( 'taxonomy' => $taxonomy, 'id' => $term_id, 'deleted' => true );
        $snapshot_check = ALIFY_AI_Audit::validate_snapshot( $before, $after );
        if ( is_wp_error( $snapshot_check ) ) return $snapshot_check;
        $log_id = ALIFY_AI_Audit::log( 'delete_term', 'term', $term_id, $before, $after );
        if ( ! $log_id ) return new WP_Error( 'alify_ai_audit_failed', 'Could not store a rollback snapshot, so the term was not deleted.', array( 'status' => 500 ) );

        $args = array();
        if ( ! empty( $input['default_term_id'] ) ) {
            $default_id = absint( $input['default_term_id'] );
            if ( $default_id === $term_id || ! term_exists( $default_id, $taxonomy ) ) {
                ALIFY_AI_Audit::delete_entry( $log_id );
                return new WP_Error( 'alify_ai_invalid_default_term', 'default_term_id must identify another existing term in the same taxonomy.', array( 'status' => 400 ) );
            }
            $args['default'] = $default_id;
            $args['force_default'] = ! empty( $input['force_default'] );
        }
        $deleted = wp_delete_term( $term_id, $taxonomy, $args );
        if ( is_wp_error( $deleted ) || false === $deleted || 0 === $deleted ) {
            ALIFY_AI_Audit::delete_entry( $log_id );
            if ( is_wp_error( $deleted ) ) return $deleted;
            return new WP_Error( 'alify_ai_term_delete_failed', 'WordPress refused to delete this term.', array( 'status' => 409 ) );
        }
        return array( 'message' => 'Taxonomy term deleted.', 'taxonomy' => $taxonomy, 'id' => $term_id, 'activity_id' => $log_id );
    }

    public static function restore_deleted_term_snapshot( array $snapshot ) {
        $term_data = is_array( $snapshot['term'] ?? null ) ? $snapshot['term'] : array();
        $taxonomy = sanitize_key( (string) ( $term_data['taxonomy'] ?? '' ) );
        if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
            return new WP_Error( 'alify_ai_taxonomy_not_found', 'The taxonomy required to restore this term is not registered.', array( 'status' => 409 ) );
        }
        $parent = absint( $term_data['parent'] ?? 0 );
        if ( $parent && ! term_exists( $parent, $taxonomy ) ) $parent = 0;
        $result = wp_insert_term(
            (string) ( $term_data['name'] ?? '' ),
            $taxonomy,
            array(
                'slug' => (string) ( $term_data['slug'] ?? '' ),
                'description' => (string) ( $term_data['description'] ?? '' ),
                'parent' => $parent,
            )
        );
        if ( is_wp_error( $result ) ) return $result;
        $new_id = (int) $result['term_id'];

        foreach ( (array) ( $snapshot['meta'] ?? array() ) as $meta_key => $values ) {
            foreach ( (array) $values as $value ) {
                if ( false === add_term_meta( $new_id, (string) $meta_key, $value, false ) ) {
                    wp_delete_term( $new_id, $taxonomy );
                    return new WP_Error( 'alify_ai_term_restore_failed', 'Could not restore term metadata.', array( 'status' => 500 ) );
                }
            }
        }
        $old_id = absint( $term_data['id'] ?? 0 );
        if ( ! empty( $snapshot['object_terms'] ) && is_array( $snapshot['object_terms'] ) ) {
            foreach ( $snapshot['object_terms'] as $object_id => $term_ids ) {
                $restored_ids = array_map(
                    static fn( $id ) => absint( $id ) === $old_id ? $new_id : absint( $id ),
                    (array) $term_ids
                );
                $set = wp_set_object_terms( absint( $object_id ), array_values( array_unique( array_filter( $restored_ids ) ) ), $taxonomy, false );
                if ( is_wp_error( $set ) ) {
                    wp_delete_term( $new_id, $taxonomy );
                    return $set;
                }
            }
        } else {
            foreach ( (array) ( $snapshot['object_ids'] ?? array() ) as $object_id ) {
                $set = wp_set_object_terms( absint( $object_id ), array( $new_id ), $taxonomy, true );
                if ( is_wp_error( $set ) ) {
                    wp_delete_term( $new_id, $taxonomy );
                    return $set;
                }
            }
        }
        foreach ( (array) ( $snapshot['child_ids'] ?? array() ) as $child_id ) {
            if ( term_exists( absint( $child_id ), $taxonomy ) ) {
                $child_update = wp_update_term( absint( $child_id ), $taxonomy, array( 'parent' => $new_id ) );
                if ( is_wp_error( $child_update ) ) {
                    wp_delete_term( $new_id, $taxonomy );
                    return $child_update;
                }
            }
        }
        return self::get_term( $taxonomy, $new_id );
    }

    public static function restore_term_snapshot( string $taxonomy, int $term_id, array $snapshot ) {
        $args = array(
            'name'        => (string) ( $snapshot['name'] ?? '' ),
            'slug'        => (string) ( $snapshot['slug'] ?? '' ),
            'description' => (string) ( $snapshot['description'] ?? '' ),
        );
        if ( isset( $snapshot['parent'] ) ) $args['parent'] = absint( $snapshot['parent'] );
        $result = wp_update_term( $term_id, $taxonomy, $args );
        return is_wp_error( $result ) ? $result : true;
    }

    private static function validate_term_parent( string $taxonomy, int $parent, int $term_id = 0 ) {
        if ( 0 === $parent ) return true;
        $tax = get_taxonomy( $taxonomy );
        if ( ! $tax || ! $tax->hierarchical ) {
            return new WP_Error( 'alify_ai_invalid_term_parent', 'Parent terms are supported only by hierarchical taxonomies.', array( 'status' => 400 ) );
        }
        $parent_term = get_term( $parent, $taxonomy );
        if ( ! $parent_term || is_wp_error( $parent_term ) ) {
            return new WP_Error( 'alify_ai_invalid_term_parent', 'Parent term was not found in this taxonomy.', array( 'status' => 400 ) );
        }
        if ( $term_id > 0 && function_exists( 'get_ancestors' ) ) {
            $ancestors = get_ancestors( $parent, $taxonomy, 'taxonomy' );
            if ( in_array( $term_id, array_map( 'absint', (array) $ancestors ), true ) ) {
                return new WP_Error( 'alify_ai_invalid_term_parent', 'The selected parent would create a circular term hierarchy.', array( 'status' => 400 ) );
            }
        }
        return true;
    }

    private static function snapshot_term_for_delete( WP_Term $term ) {
        $object_ids = get_objects_in_term( $term->term_id, $term->taxonomy );
        if ( is_wp_error( $object_ids ) ) return $object_ids;
        $child_ids = get_terms( array( 'taxonomy' => $term->taxonomy, 'hide_empty' => false, 'parent' => $term->term_id, 'fields' => 'ids' ) );
        if ( is_wp_error( $child_ids ) ) return $child_ids;
        $object_terms = array();
        foreach ( (array) $object_ids as $object_id ) {
            $assigned = wp_get_object_terms( absint( $object_id ), $term->taxonomy, array( 'fields' => 'ids' ) );
            if ( is_wp_error( $assigned ) ) return $assigned;
            $object_terms[ absint( $object_id ) ] = array_values( array_map( 'absint', (array) $assigned ) );
        }
        return array(
            'term'         => self::serialize_term( $term ),
            'object_ids'   => array_values( array_map( 'absint', (array) $object_ids ) ),
            'object_terms' => $object_terms,
            'child_ids'    => array_values( array_map( 'absint', (array) $child_ids ) ),
            'meta'         => get_term_meta( $term->term_id ),
        );
    }

    private static function post_type_args( array $definition ): array {
        $args = array(
            'labels'               => $definition['labels'] ?? array( 'name' => $definition['label'] ?? 'Items', 'singular_name' => $definition['singular'] ?? 'Item' ),
            'description'          => (string) ( $definition['description'] ?? '' ),
            'public'               => (bool) ( $definition['public'] ?? true ),
            'publicly_queryable'   => (bool) ( $definition['publicly_queryable'] ?? ( $definition['public'] ?? true ) ),
            'exclude_from_search'  => (bool) ( $definition['exclude_from_search'] ?? ! ( $definition['public'] ?? true ) ),
            'show_ui'              => (bool) ( $definition['show_ui'] ?? true ),
            'show_in_menu'         => $definition['show_in_menu'] ?? true,
            'show_in_nav_menus'    => (bool) ( $definition['show_in_nav_menus'] ?? ( $definition['public'] ?? true ) ),
            'show_in_admin_bar'    => (bool) ( $definition['show_in_admin_bar'] ?? true ),
            'show_in_rest'         => (bool) ( $definition['show_in_rest'] ?? true ),
            'rest_base'            => (string) ( $definition['rest_base'] ?? ( $definition['key'] ?? '' ) ),
            'rest_namespace'       => (string) ( $definition['rest_namespace'] ?? 'wp/v2' ),
            'late_route_registration' => (bool) ( $definition['late_route_registration'] ?? false ),
            'menu_position'        => $definition['menu_position'] ?? null,
            'menu_icon'            => $definition['menu_icon'] ?? null,
            'capability_type'      => $definition['capability_type'] ?? 'post',
            'capabilities'         => $definition['capabilities'] ?? array(),
            'map_meta_cap'         => (bool) ( $definition['map_meta_cap'] ?? false ),
            'hierarchical'         => (bool) ( $definition['hierarchical'] ?? false ),
            'supports'             => $definition['supports'] ?? array( 'title', 'editor', 'thumbnail' ),
            'taxonomies'           => $definition['taxonomies'] ?? array(),
            'has_archive'          => $definition['has_archive'] ?? true,
            'rewrite'              => $definition['rewrite'] ?? array( 'slug' => $definition['rewrite_slug'] ?? ( $definition['key'] ?? '' ) ),
            'query_var'            => $definition['query_var'] ?? true,
            'can_export'           => (bool) ( $definition['can_export'] ?? true ),
            'delete_with_user'     => $definition['delete_with_user'] ?? null,
            'template'             => $definition['template'] ?? array(),
            'template_lock'        => $definition['template_lock'] ?? false,
        );
        if ( null === $args['menu_position'] ) unset( $args['menu_position'] );
        if ( null === $args['menu_icon'] || '' === $args['menu_icon'] ) unset( $args['menu_icon'] );
        if ( null === $args['delete_with_user'] ) unset( $args['delete_with_user'] );
        if ( empty( $args['capabilities'] ) ) unset( $args['capabilities'] );
        return $args;
    }

    private static function normalize_post_type_definition( string $key, array $input, array $previous ) {
        foreach ( array( 'label', 'singular', 'description', 'rest_base', 'rest_namespace', 'rewrite_slug' ) as $scalar_field ) {
            if ( array_key_exists( $scalar_field, $input ) && null !== $input[ $scalar_field ] && ! is_scalar( $input[ $scalar_field ] ) ) {
                return new WP_Error( 'alify_ai_invalid_post_type_field', $scalar_field . ' must be a scalar value.', array( 'status' => 400 ) );
            }
        }
        if ( array_key_exists( 'menu_position', $input ) && null !== $input['menu_position'] && '' !== $input['menu_position'] && ! is_numeric( $input['menu_position'] ) ) {
            return new WP_Error( 'alify_ai_invalid_menu_position', 'menu_position must be an integer or null.', array( 'status' => 400 ) );
        }
        if ( array_key_exists( 'delete_with_user', $input ) && null !== $input['delete_with_user'] && ! is_bool( $input['delete_with_user'] ) ) {
            return new WP_Error( 'alify_ai_invalid_delete_with_user', 'delete_with_user must be boolean or null.', array( 'status' => 400 ) );
        }
        if ( isset( $input['labels'] ) && ! is_array( $input['labels'] ) ) {
            return new WP_Error( 'alify_ai_invalid_labels', 'labels must be an object.', array( 'status' => 400 ) );
        }
        $plural = sanitize_text_field( (string) ( $input['label'] ?? $input['labels']['name'] ?? $previous['label'] ?? $previous['labels']['name'] ?? ucfirst( $key ) ) );
        $singular = sanitize_text_field( (string) ( $input['singular'] ?? $input['labels']['singular_name'] ?? $previous['singular'] ?? $previous['labels']['singular_name'] ?? $plural ) );
        if ( '' === $plural || '' === $singular ) {
            return new WP_Error( 'alify_ai_invalid_post_type_labels', 'Plural and singular post type labels are required.', array( 'status' => 400 ) );
        }

        $labels = self::default_post_type_labels( $plural, $singular );
        if ( isset( $previous['labels'] ) && is_array( $previous['labels'] ) ) {
            $labels = array_merge( $labels, self::sanitize_post_type_labels( $previous['labels'] ) );
        }
        if ( isset( $input['labels'] ) ) {
            $labels = array_merge( $labels, self::sanitize_post_type_labels( $input['labels'] ) );
        }
        $labels['name'] = $plural;
        $labels['singular_name'] = $singular;

        $public = self::bool_value( $input, 'public', (bool) ( $previous['public'] ?? true ) );
        $show_ui = self::bool_value( $input, 'show_ui', (bool) ( $previous['show_ui'] ?? $public ) );
        $show_in_menu = $previous['show_in_menu'] ?? $show_ui;
        if ( array_key_exists( 'show_in_menu', $input ) ) {
            if ( is_bool( $input['show_in_menu'] ) ) {
                $show_in_menu = $input['show_in_menu'];
            } elseif ( is_string( $input['show_in_menu'] ) ) {
                $show_in_menu = sanitize_text_field( $input['show_in_menu'] );
                if ( '' === $show_in_menu ) $show_in_menu = false;
            } else {
                return new WP_Error( 'alify_ai_invalid_show_in_menu', 'show_in_menu must be boolean or a parent admin menu slug.', array( 'status' => 400 ) );
            }
        }

        $supports = $previous['supports'] ?? array( 'title', 'editor', 'thumbnail' );
        if ( array_key_exists( 'supports', $input ) ) {
            if ( false === $input['supports'] ) {
                $supports = false;
            } elseif ( is_array( $input['supports'] ) ) {
                $allowed_supports = array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'trackbacks', 'custom-fields', 'comments', 'revisions', 'page-attributes', 'post-formats', 'autosave' );
                $supports = array();
                foreach ( $input['supports'] as $support ) {
                    if ( is_string( $support ) ) {
                        $feature = sanitize_key( $support );
                        if ( in_array( $feature, $allowed_supports, true ) ) $supports[] = $feature;
                        continue;
                    }
                    if ( is_array( $support ) && isset( $support[0] ) && is_string( $support[0] ) ) {
                        $feature = sanitize_key( $support[0] );
                        if ( ! in_array( $feature, $allowed_supports, true ) ) continue;
                        $args = isset( $support[1] ) && is_array( $support[1] ) ? self::sanitize_template_attributes( $support[1] ) : array();
                        $supports[] = array( $feature, $args );
                    }
                }
            } else {
                return new WP_Error( 'alify_ai_invalid_supports', 'supports must be an array or false.', array( 'status' => 400 ) );
            }
        }

        $taxonomies = $previous['taxonomies'] ?? array();
        if ( array_key_exists( 'taxonomies', $input ) ) {
            if ( ! is_array( $input['taxonomies'] ) ) {
                return new WP_Error( 'alify_ai_invalid_taxonomies', 'taxonomies must be an array of registered taxonomy keys.', array( 'status' => 400 ) );
            }
            $taxonomies = array();
            foreach ( $input['taxonomies'] as $taxonomy_value ) {
                if ( ! is_string( $taxonomy_value ) ) {
                    return new WP_Error( 'alify_ai_invalid_taxonomies', 'Each taxonomy key must be a string.', array( 'status' => 400 ) );
                }
                $taxonomy_key = sanitize_key( $taxonomy_value );
                if ( '' !== $taxonomy_key ) $taxonomies[] = $taxonomy_key;
            }
            $taxonomies = array_values( array_unique( $taxonomies ) );
            foreach ( $taxonomies as $taxonomy ) {
                if ( ! taxonomy_exists( $taxonomy ) ) {
                    return new WP_Error( 'alify_ai_taxonomy_not_found', sprintf( 'Taxonomy "%s" is not registered.', $taxonomy ), array( 'status' => 400 ) );
                }
            }
        }

        $has_archive = $previous['has_archive'] ?? true;
        if ( array_key_exists( 'has_archive', $input ) ) {
            if ( is_bool( $input['has_archive'] ) ) $has_archive = $input['has_archive'];
            elseif ( is_string( $input['has_archive'] ) ) $has_archive = sanitize_title( $input['has_archive'] );
            else return new WP_Error( 'alify_ai_invalid_archive', 'has_archive must be boolean or an archive slug string.', array( 'status' => 400 ) );
        }

        $rewrite = $previous['rewrite'] ?? array( 'slug' => $previous['rewrite_slug'] ?? $key );
        if ( array_key_exists( 'rewrite_slug', $input ) && ! array_key_exists( 'rewrite', $input ) ) {
            $rewrite = is_array( $rewrite ) ? $rewrite : array();
            $rewrite['slug'] = sanitize_title( (string) $input['rewrite_slug'] );
        }
        if ( array_key_exists( 'rewrite', $input ) ) {
            $rewrite = self::sanitize_rewrite( $input['rewrite'], $key );
            if ( is_wp_error( $rewrite ) ) return $rewrite;
        }

        $query_var = $previous['query_var'] ?? true;
        if ( array_key_exists( 'query_var', $input ) ) {
            if ( is_bool( $input['query_var'] ) ) $query_var = $input['query_var'];
            elseif ( is_string( $input['query_var'] ) ) $query_var = sanitize_key( $input['query_var'] );
            else return new WP_Error( 'alify_ai_invalid_query_var', 'query_var must be boolean or a query-var string.', array( 'status' => 400 ) );
        }

        $capability_type = $previous['capability_type'] ?? 'post';
        if ( array_key_exists( 'capability_type', $input ) ) {
            if ( is_string( $input['capability_type'] ) ) {
                $capability_type = sanitize_key( $input['capability_type'] );
            } elseif ( is_array( $input['capability_type'] ) && 2 === count( $input['capability_type'] ) && is_string( $input['capability_type'][0] ?? null ) && is_string( $input['capability_type'][1] ?? null ) ) {
                $capability_type = array( sanitize_key( $input['capability_type'][0] ), sanitize_key( $input['capability_type'][1] ) );
                if ( '' === $capability_type[0] || '' === $capability_type[1] ) {
                    return new WP_Error( 'alify_ai_invalid_capability_type', 'capability_type values cannot be empty.', array( 'status' => 400 ) );
                }
            } else {
                return new WP_Error( 'alify_ai_invalid_capability_type', 'capability_type must be a string or [singular, plural].', array( 'status' => 400 ) );
            }
        }

        $capabilities = $previous['capabilities'] ?? array();
        if ( array_key_exists( 'capabilities', $input ) ) {
            if ( ! is_array( $input['capabilities'] ) ) return new WP_Error( 'alify_ai_invalid_capabilities', 'capabilities must be an object.', array( 'status' => 400 ) );
            $capabilities = self::sanitize_capabilities( $input['capabilities'] );
        }

        $menu_icon = $previous['menu_icon'] ?? null;
        if ( array_key_exists( 'menu_icon', $input ) ) {
            $menu_icon = self::sanitize_menu_icon( $input['menu_icon'] );
            if ( is_wp_error( $menu_icon ) ) return $menu_icon;
        }
        $menu_position = $previous['menu_position'] ?? null;
        if ( array_key_exists( 'menu_position', $input ) ) {
            $menu_position = null === $input['menu_position'] || '' === $input['menu_position'] ? null : max( 0, min( 999, (int) $input['menu_position'] ) );
        }

        $rest_base = sanitize_title( (string) ( $input['rest_base'] ?? $previous['rest_base'] ?? $key ) );
        if ( '' === $rest_base ) $rest_base = $key;
        $rest_namespace = (string) ( $input['rest_namespace'] ?? $previous['rest_namespace'] ?? 'wp/v2' );
        $rest_namespace = trim( preg_replace( '/[^A-Za-z0-9_.\/-]/', '', $rest_namespace ), '/' );
        if ( '' === $rest_namespace ) $rest_namespace = 'wp/v2';

        $template = $previous['template'] ?? array();
        if ( array_key_exists( 'template', $input ) ) {
            $template = self::sanitize_block_template( $input['template'] );
            if ( is_wp_error( $template ) ) return $template;
        }
        $template_lock = $previous['template_lock'] ?? false;
        if ( array_key_exists( 'template_lock', $input ) ) {
            $allowed_locks = array( 'all', 'insert', 'contentOnly' );
            if ( false === $input['template_lock'] || null === $input['template_lock'] || '' === $input['template_lock'] ) $template_lock = false;
            elseif ( is_string( $input['template_lock'] ) && in_array( $input['template_lock'], $allowed_locks, true ) ) $template_lock = $input['template_lock'];
            else return new WP_Error( 'alify_ai_invalid_template_lock', 'template_lock must be false, all, insert, or contentOnly.', array( 'status' => 400 ) );
        }

        $definition = array(
            'key'                     => $key,
            'label'                   => $plural,
            'singular'                => $singular,
            'labels'                  => $labels,
            'description'             => sanitize_textarea_field( (string) ( $input['description'] ?? $previous['description'] ?? '' ) ),
            'public'                  => $public,
            'publicly_queryable'      => self::bool_value( $input, 'publicly_queryable', (bool) ( $previous['publicly_queryable'] ?? $public ) ),
            'exclude_from_search'     => self::bool_value( $input, 'exclude_from_search', (bool) ( $previous['exclude_from_search'] ?? ! $public ) ),
            'show_ui'                 => $show_ui,
            'show_in_menu'            => $show_in_menu,
            'show_in_nav_menus'       => self::bool_value( $input, 'show_in_nav_menus', (bool) ( $previous['show_in_nav_menus'] ?? $public ) ),
            'show_in_admin_bar'       => self::bool_value( $input, 'show_in_admin_bar', (bool) ( $previous['show_in_admin_bar'] ?? $show_in_menu ) ),
            'show_in_rest'            => self::bool_value( $input, 'show_in_rest', (bool) ( $previous['show_in_rest'] ?? true ) ),
            'rest_base'               => $rest_base,
            'rest_namespace'          => $rest_namespace,
            'late_route_registration' => self::bool_value( $input, 'late_route_registration', (bool) ( $previous['late_route_registration'] ?? false ) ),
            'menu_position'           => $menu_position,
            'menu_icon'               => $menu_icon,
            'capability_type'         => $capability_type,
            'capabilities'            => $capabilities,
            'map_meta_cap'            => self::bool_value( $input, 'map_meta_cap', (bool) ( $previous['map_meta_cap'] ?? false ) ),
            'hierarchical'            => self::bool_value( $input, 'hierarchical', (bool) ( $previous['hierarchical'] ?? false ) ),
            'supports'                => $supports,
            'taxonomies'              => $taxonomies,
            'has_archive'             => $has_archive,
            'rewrite'                 => $rewrite,
            'rewrite_slug'            => is_array( $rewrite ) ? (string) ( $rewrite['slug'] ?? $key ) : '',
            'query_var'               => $query_var,
            'can_export'              => self::bool_value( $input, 'can_export', (bool) ( $previous['can_export'] ?? true ) ),
            'delete_with_user'        => array_key_exists( 'delete_with_user', $input ) ? ( null === $input['delete_with_user'] ? null : (bool) $input['delete_with_user'] ) : ( $previous['delete_with_user'] ?? null ),
            'template'                => $template,
            'template_lock'           => $template_lock,
        );
        return $definition;
    }

    private static function serialize_post_type_object( string $key, WP_Post_Type $object, ?array $definition ): array {
        $capabilities = array();
        if ( isset( $object->cap ) ) {
            foreach ( get_object_vars( $object->cap ) as $cap_key => $cap_value ) {
                if ( is_string( $cap_value ) ) $capabilities[ $cap_key ] = $cap_value;
            }
        }
        return array(
            'key'                     => $key,
            'label'                   => $object->label,
            'singular'                => $object->labels->singular_name ?? $object->label,
            'labels'                  => self::extract_labels( $object ),
            'description'             => (string) $object->description,
            'public'                  => (bool) $object->public,
            'publicly_queryable'      => (bool) $object->publicly_queryable,
            'exclude_from_search'     => (bool) $object->exclude_from_search,
            'show_ui'                 => (bool) $object->show_ui,
            'show_in_menu'            => $object->show_in_menu,
            'show_in_nav_menus'       => (bool) $object->show_in_nav_menus,
            'show_in_admin_bar'       => (bool) $object->show_in_admin_bar,
            'show_in_rest'            => (bool) $object->show_in_rest,
            'rest_base'               => $object->rest_base ?: $key,
            'rest_namespace'          => $object->rest_namespace ?? 'wp/v2',
            'menu_position'           => $object->menu_position,
            'menu_icon'               => $object->menu_icon,
            'has_archive'             => $object->has_archive,
            'hierarchical'            => (bool) $object->hierarchical,
            'query_var'               => $object->query_var,
            'can_export'              => (bool) $object->can_export,
            'delete_with_user'        => $object->delete_with_user,
            'capability_type'         => $object->capability_type,
            'capabilities'            => $capabilities,
            'map_meta_cap'            => (bool) $object->map_meta_cap,
            'supports'                => array_keys( get_all_post_type_supports( $key ) ),
            'taxonomies'              => get_object_taxonomies( $key ),
            'rewrite'                 => $object->rewrite,
            'template'                => $object->template ?? array(),
            'template_lock'           => $object->template_lock ?? false,
            'content_count'           => self::post_type_content_count( $key ),
            'managed'                 => is_array( $definition ),
            'managed_definition'      => $definition,
        );
    }

    private static function default_post_type_labels( string $plural, string $singular ): array {
        return array(
            'name' => $plural, 'singular_name' => $singular, 'menu_name' => $plural,
            'add_new' => 'Add New', 'add_new_item' => 'Add New ' . $singular, 'edit_item' => 'Edit ' . $singular,
            'new_item' => 'New ' . $singular, 'view_item' => 'View ' . $singular, 'view_items' => 'View ' . $plural,
            'search_items' => 'Search ' . $plural, 'not_found' => 'No ' . strtolower( $plural ) . ' found.',
            'not_found_in_trash' => 'No ' . strtolower( $plural ) . ' found in Trash.', 'all_items' => 'All ' . $plural,
            'archives' => $singular . ' Archives', 'attributes' => $singular . ' Attributes',
            'insert_into_item' => 'Insert into ' . strtolower( $singular ), 'uploaded_to_this_item' => 'Uploaded to this ' . strtolower( $singular ),
            'filter_items_list' => 'Filter ' . strtolower( $plural ) . ' list', 'items_list_navigation' => $plural . ' list navigation',
            'items_list' => $plural . ' list', 'item_published' => $singular . ' published.', 'item_updated' => $singular . ' updated.',
            'item_trashed' => $singular . ' trashed.', 'item_scheduled' => $singular . ' scheduled.',
        );
    }

    private static function sanitize_post_type_labels( array $labels ): array {
        $allowed = array( 'name','singular_name','add_new','add_new_item','edit_item','new_item','view_item','view_items','search_items','not_found','not_found_in_trash','parent_item_colon','all_items','archives','attributes','insert_into_item','uploaded_to_this_item','featured_image','set_featured_image','remove_featured_image','use_featured_image','menu_name','filter_items_list','filter_by_date','items_list_navigation','items_list','item_published','item_published_privately','item_reverted_to_draft','item_trashed','item_scheduled','item_updated','item_link','item_link_description' );
        $out = array();
        foreach ( $allowed as $key ) {
            if ( isset( $labels[ $key ] ) && is_scalar( $labels[ $key ] ) ) $out[ $key ] = sanitize_text_field( (string) $labels[ $key ] );
        }
        return $out;
    }

    private static function extract_labels( WP_Post_Type $object ): array {
        $out = array();
        foreach ( get_object_vars( $object->labels ) as $key => $value ) {
            if ( is_string( $value ) ) $out[ $key ] = $value;
        }
        return $out;
    }

    private static function sanitize_capabilities( array $capabilities ): array {
        $allowed = array( 'edit_post','read_post','delete_post','edit_posts','edit_others_posts','delete_posts','publish_posts','read_private_posts','read','delete_private_posts','delete_published_posts','delete_others_posts','edit_private_posts','edit_published_posts','create_posts' );
        $out = array();
        foreach ( $allowed as $key ) {
            if ( isset( $capabilities[ $key ] ) && is_string( $capabilities[ $key ] ) && '' !== $capabilities[ $key ] ) $out[ $key ] = sanitize_key( $capabilities[ $key ] );
        }
        return $out;
    }

    private static function sanitize_menu_icon( $value ) {
        if ( null === $value || '' === $value ) return null;
        if ( ! is_string( $value ) ) return new WP_Error( 'alify_ai_invalid_menu_icon', 'menu_icon must be a Dashicons class, none, HTTPS URL, SVG data URI, or empty.', array( 'status' => 400 ) );
        $value = trim( $value );
        if ( 'none' === $value || preg_match( '/^dashicons-[a-z0-9-]+$/', $value ) ) return sanitize_text_field( $value );
        if ( str_starts_with( $value, 'https://' ) ) {
            $url = esc_url_raw( $value, array( 'https' ) );
            return $url ?: new WP_Error( 'alify_ai_invalid_menu_icon', 'Invalid HTTPS menu icon URL.', array( 'status' => 400 ) );
        }
        $prefix = 'data:image/svg+xml;base64,';
        if ( str_starts_with( $value, $prefix ) ) {
            $encoded = substr( $value, strlen( $prefix ) );
            if ( strlen( $encoded ) > 131072 || ! preg_match( '/^[A-Za-z0-9+\/=]+$/', $encoded ) ) {
                return new WP_Error( 'alify_ai_invalid_menu_icon', 'Invalid or oversized SVG data URI.', array( 'status' => 400 ) );
            }
            $svg = base64_decode( $encoded, true );
            if ( false === $svg || ! preg_match( '/<svg/i', $svg ) || preg_match( '/<script|<foreignObject|javascript:|on[a-z]+\s*=|https?:\/\//i', $svg ) ) {
                return new WP_Error( 'alify_ai_invalid_menu_icon', 'SVG menu icon contains unsupported active/external content.', array( 'status' => 400 ) );
            }
            return $prefix . $encoded;
        }
        return new WP_Error( 'alify_ai_invalid_menu_icon', 'menu_icon must be dashicons-*, none, an HTTPS URL, or a safe base64 SVG data URI.', array( 'status' => 400 ) );
    }

    private static function sanitize_rewrite( $value, string $key ) {
        if ( is_bool( $value ) ) return $value;
        if ( ! is_array( $value ) ) return new WP_Error( 'alify_ai_invalid_rewrite', 'rewrite must be boolean or an object.', array( 'status' => 400 ) );
        $rewrite = array();
        $rewrite['slug'] = sanitize_title( (string) ( $value['slug'] ?? $key ) );
        if ( '' === $rewrite['slug'] ) $rewrite['slug'] = $key;
        foreach ( array( 'with_front', 'feeds', 'pages' ) as $field ) {
            if ( array_key_exists( $field, $value ) ) $rewrite[ $field ] = (bool) $value[ $field ];
        }
        if ( array_key_exists( 'ep_mask', $value ) ) $rewrite['ep_mask'] = absint( $value['ep_mask'] );
        return $rewrite;
    }

    private static function sanitize_block_template( $template ) {
        if ( null === $template || array() === $template ) return array();
        if ( ! is_array( $template ) ) return new WP_Error( 'alify_ai_invalid_template', 'template must be an array of block template entries.', array( 'status' => 400 ) );
        $out = array();
        foreach ( $template as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry[0] ) || ! is_string( $entry[0] ) || ! preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $entry[0] ) ) {
                return new WP_Error( 'alify_ai_invalid_template', 'Each template entry must begin with a valid namespace/block-name.', array( 'status' => 400 ) );
            }
            $clean = array( sanitize_text_field( $entry[0] ) );
            if ( isset( $entry[1] ) ) $clean[] = is_array( $entry[1] ) ? self::sanitize_template_attributes( $entry[1] ) : array();
            if ( isset( $entry[2] ) ) {
                $nested = self::sanitize_block_template( $entry[2] );
                if ( is_wp_error( $nested ) ) return $nested;
                $clean[] = $nested;
            }
            $out[] = $clean;
        }
        return $out;
    }

    private static function sanitize_template_attributes( array $attributes ): array {
        $out = array();
        foreach ( $attributes as $key => $value ) {
            $k = sanitize_key( (string) $key );
            if ( is_array( $value ) ) $out[ $k ] = self::sanitize_template_attributes( $value );
            elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) $out[ $k ] = $value;
            else $out[ $k ] = sanitize_text_field( (string) $value );
        }
        return $out;
    }

    private static function bool_value( array $input, string $key, bool $default ): bool {
        return array_key_exists( $key, $input ) ? (bool) $input[ $key ] : $default;
    }

    private static function sync_post_type_taxonomies( string $post_type, array $old, array $new ): void {
        $old = array_values( array_unique( array_map( 'sanitize_key', $old ) ) );
        $new = array_values( array_unique( array_map( 'sanitize_key', $new ) ) );
        foreach ( array_diff( $old, $new ) as $taxonomy ) {
            if ( taxonomy_exists( $taxonomy ) && function_exists( 'unregister_taxonomy_for_object_type' ) ) unregister_taxonomy_for_object_type( $taxonomy, $post_type );
        }
        foreach ( $new as $taxonomy ) {
            if ( taxonomy_exists( $taxonomy ) ) register_taxonomy_for_object_type( $taxonomy, $post_type );
        }
    }

    private static function normalize_taxonomy_definition( string $key, array $input, array $previous ) {
        $plural = sanitize_text_field( (string) ( $input['label'] ?? $previous['label'] ?? ucfirst( str_replace( array( '-', '_' ), ' ', $key ) ) ) );
        $singular = sanitize_text_field( (string) ( $input['singular'] ?? $previous['singular'] ?? $plural ) );
        if ( '' === $plural ) $plural = ucfirst( $key );
        if ( '' === $singular ) $singular = $plural;

        $labels = self::default_taxonomy_labels( $plural, $singular );
        if ( isset( $previous['labels'] ) && is_array( $previous['labels'] ) ) $labels = array_merge( $labels, self::sanitize_taxonomy_labels( $previous['labels'] ) );
        if ( isset( $input['labels'] ) ) {
            if ( ! is_array( $input['labels'] ) ) return new WP_Error( 'alify_ai_invalid_labels', 'labels must be an object.', array( 'status' => 400 ) );
            $labels = array_merge( $labels, self::sanitize_taxonomy_labels( $input['labels'] ) );
        }
        $labels['name'] = $labels['name'] ?? $plural;
        $labels['singular_name'] = $labels['singular_name'] ?? $singular;

        $object_types = array_key_exists( 'object_types', $input ) ? $input['object_types'] : ( $previous['object_types'] ?? array( 'post' ) );
        if ( ! is_array( $object_types ) ) return new WP_Error( 'alify_ai_invalid_object_types', 'object_types must be an array of registered post type keys.', array( 'status' => 400 ) );
        $clean_types = array();
        foreach ( $object_types as $type ) {
            if ( ! is_string( $type ) ) return new WP_Error( 'alify_ai_invalid_object_types', 'Each object type key must be a string.', array( 'status' => 400 ) );
            $type = sanitize_key( $type );
            if ( '' === $type ) continue;
            if ( ! post_type_exists( $type ) ) return new WP_Error( 'alify_ai_post_type_not_found', sprintf( 'Post type "%s" is not registered.', $type ), array( 'status' => 400 ) );
            $clean_types[] = $type;
        }
        $object_types = array_values( array_unique( $clean_types ) );

        $public = self::bool_value( $input, 'public', (bool) ( $previous['public'] ?? true ) );
        $hierarchical = self::bool_value( $input, 'hierarchical', (bool) ( $previous['hierarchical'] ?? false ) );
        $show_ui = self::bool_value( $input, 'show_ui', (bool) ( $previous['show_ui'] ?? $public ) );

        $capabilities = $previous['capabilities'] ?? array();
        if ( array_key_exists( 'capabilities', $input ) ) {
            if ( ! is_array( $input['capabilities'] ) ) return new WP_Error( 'alify_ai_invalid_capabilities', 'capabilities must be an object.', array( 'status' => 400 ) );
            $capabilities = self::sanitize_taxonomy_capabilities( $input['capabilities'] );
        }

        $rewrite = $previous['rewrite'] ?? array( 'slug' => $previous['rewrite_slug'] ?? $key );
        if ( array_key_exists( 'rewrite_slug', $input ) && ! array_key_exists( 'rewrite', $input ) ) {
            $rewrite = is_array( $rewrite ) ? $rewrite : array();
            $rewrite['slug'] = sanitize_title( (string) $input['rewrite_slug'] );
        }
        if ( array_key_exists( 'rewrite', $input ) ) {
            $rewrite = self::sanitize_taxonomy_rewrite( $input['rewrite'], $key );
            if ( is_wp_error( $rewrite ) ) return $rewrite;
        }

        $query_var = $previous['query_var'] ?? $key;
        if ( array_key_exists( 'query_var', $input ) ) {
            if ( is_bool( $input['query_var'] ) ) $query_var = $input['query_var'];
            elseif ( is_string( $input['query_var'] ) ) {
                $query_var = sanitize_key( $input['query_var'] );
                if ( '' === $query_var ) return new WP_Error( 'alify_ai_invalid_query_var', 'query_var string cannot be empty.', array( 'status' => 400 ) );
            } else return new WP_Error( 'alify_ai_invalid_query_var', 'query_var must be boolean or string.', array( 'status' => 400 ) );
        }

        $default_term = $previous['default_term'] ?? null;
        if ( array_key_exists( 'default_term', $input ) ) {
            if ( null === $input['default_term'] || false === $input['default_term'] ) {
                $default_term = null;
            } elseif ( is_array( $input['default_term'] ) ) {
                $name = sanitize_text_field( (string) ( $input['default_term']['name'] ?? '' ) );
                if ( '' === $name ) return new WP_Error( 'alify_ai_invalid_default_term', 'default_term.name is required.', array( 'status' => 400 ) );
                $default_term = array( 'name' => $name );
                if ( isset( $input['default_term']['slug'] ) ) $default_term['slug'] = sanitize_title( (string) $input['default_term']['slug'] );
                if ( isset( $input['default_term']['description'] ) ) $default_term['description'] = sanitize_textarea_field( (string) $input['default_term']['description'] );
            } else return new WP_Error( 'alify_ai_invalid_default_term', 'default_term must be an object or null.', array( 'status' => 400 ) );
        }

        $rest_base = sanitize_title( (string) ( $input['rest_base'] ?? $previous['rest_base'] ?? $key ) );
        if ( '' === $rest_base ) $rest_base = $key;
        $rest_namespace = trim( preg_replace( '/[^A-Za-z0-9_.\/-]/', '', (string) ( $input['rest_namespace'] ?? $previous['rest_namespace'] ?? 'wp/v2' ) ), '/' );
        if ( '' === $rest_namespace ) $rest_namespace = 'wp/v2';

        return array(
            'key'                  => $key,
            'label'                => $plural,
            'singular'             => $singular,
            'labels'               => $labels,
            'description'          => sanitize_textarea_field( (string) ( $input['description'] ?? $previous['description'] ?? '' ) ),
            'object_types'         => $object_types,
            'public'               => $public,
            'publicly_queryable'   => self::bool_value( $input, 'publicly_queryable', (bool) ( $previous['publicly_queryable'] ?? $public ) ),
            'hierarchical'         => $hierarchical,
            'show_ui'              => $show_ui,
            'show_in_menu'         => self::bool_value( $input, 'show_in_menu', (bool) ( $previous['show_in_menu'] ?? $show_ui ) ),
            'show_in_nav_menus'    => self::bool_value( $input, 'show_in_nav_menus', (bool) ( $previous['show_in_nav_menus'] ?? $public ) ),
            'show_tagcloud'        => self::bool_value( $input, 'show_tagcloud', (bool) ( $previous['show_tagcloud'] ?? $show_ui ) ),
            'show_in_quick_edit'   => self::bool_value( $input, 'show_in_quick_edit', (bool) ( $previous['show_in_quick_edit'] ?? $show_ui ) ),
            'show_admin_column'    => self::bool_value( $input, 'show_admin_column', (bool) ( $previous['show_admin_column'] ?? false ) ),
            'show_in_rest'         => self::bool_value( $input, 'show_in_rest', (bool) ( $previous['show_in_rest'] ?? true ) ),
            'rest_base'            => $rest_base,
            'rest_namespace'       => $rest_namespace,
            'capabilities'         => $capabilities,
            'rewrite'              => $rewrite,
            'rewrite_slug'         => is_array( $rewrite ) ? (string) ( $rewrite['slug'] ?? $key ) : $key,
            'query_var'            => $query_var,
            'sort'                 => self::bool_value( $input, 'sort', (bool) ( $previous['sort'] ?? false ) ),
            'default_term'         => $default_term,
        );
    }

    private static function taxonomy_args( array $definition ): array {
        $args = array(
            'labels'             => $definition['labels'] ?? array( 'name' => $definition['label'] ?? 'Terms', 'singular_name' => $definition['singular'] ?? 'Term' ),
            'description'        => (string) ( $definition['description'] ?? '' ),
            'public'             => (bool) ( $definition['public'] ?? true ),
            'publicly_queryable' => (bool) ( $definition['publicly_queryable'] ?? ( $definition['public'] ?? true ) ),
            'hierarchical'       => (bool) ( $definition['hierarchical'] ?? false ),
            'show_ui'            => (bool) ( $definition['show_ui'] ?? true ),
            'show_in_menu'       => (bool) ( $definition['show_in_menu'] ?? true ),
            'show_in_nav_menus'  => (bool) ( $definition['show_in_nav_menus'] ?? true ),
            'show_tagcloud'      => (bool) ( $definition['show_tagcloud'] ?? true ),
            'show_in_quick_edit' => (bool) ( $definition['show_in_quick_edit'] ?? true ),
            'show_admin_column'  => (bool) ( $definition['show_admin_column'] ?? false ),
            'show_in_rest'       => (bool) ( $definition['show_in_rest'] ?? true ),
            'rest_base'          => (string) ( $definition['rest_base'] ?? ( $definition['key'] ?? '' ) ),
            'rest_namespace'     => (string) ( $definition['rest_namespace'] ?? 'wp/v2' ),
            'rewrite'            => $definition['rewrite'] ?? array( 'slug' => $definition['rewrite_slug'] ?? ( $definition['key'] ?? '' ) ),
            'query_var'          => $definition['query_var'] ?? ( $definition['key'] ?? true ),
            'sort'               => (bool) ( $definition['sort'] ?? false ),
        );
        if ( ! empty( $definition['capabilities'] ) && is_array( $definition['capabilities'] ) ) $args['capabilities'] = $definition['capabilities'];
        if ( ! empty( $definition['default_term'] ) && is_array( $definition['default_term'] ) ) $args['default_term'] = $definition['default_term'];
        return $args;
    }

    private static function default_taxonomy_labels( string $plural, string $singular ): array {
        return array(
            'name' => $plural, 'singular_name' => $singular, 'menu_name' => $plural,
            'search_items' => 'Search ' . $plural, 'popular_items' => 'Popular ' . $plural, 'all_items' => 'All ' . $plural,
            'parent_item' => 'Parent ' . $singular, 'parent_item_colon' => 'Parent ' . $singular . ':',
            'edit_item' => 'Edit ' . $singular, 'view_item' => 'View ' . $singular, 'update_item' => 'Update ' . $singular,
            'add_new_item' => 'Add New ' . $singular, 'new_item_name' => 'New ' . $singular . ' Name',
            'separate_items_with_commas' => 'Separate ' . strtolower( $plural ) . ' with commas',
            'add_or_remove_items' => 'Add or remove ' . strtolower( $plural ), 'choose_from_most_used' => 'Choose from the most used ' . strtolower( $plural ),
            'not_found' => 'No ' . strtolower( $plural ) . ' found.', 'no_terms' => 'No ' . strtolower( $plural ),
            'items_list_navigation' => $plural . ' list navigation', 'items_list' => $plural . ' list',
            'most_used' => 'Most Used', 'back_to_items' => '← Back to ' . $plural,
            'item_link' => $singular . ' Link', 'item_link_description' => 'A link to a ' . strtolower( $singular ) . '.',
        );
    }

    private static function sanitize_taxonomy_labels( array $labels ): array {
        $allowed = array( 'name','singular_name','menu_name','search_items','popular_items','all_items','parent_item','parent_item_colon','name_field_description','slug_field_description','parent_field_description','desc_field_description','edit_item','view_item','update_item','add_new_item','new_item_name','separate_items_with_commas','add_or_remove_items','choose_from_most_used','not_found','no_terms','filter_by_item','items_list_navigation','items_list','most_used','back_to_items','item_link','item_link_description' );
        $out = array();
        foreach ( $allowed as $label ) if ( isset( $labels[ $label ] ) && is_scalar( $labels[ $label ] ) ) $out[ $label ] = sanitize_text_field( (string) $labels[ $label ] );
        return $out;
    }

    private static function sanitize_taxonomy_capabilities( array $capabilities ): array {
        $out = array();
        foreach ( array( 'manage_terms','edit_terms','delete_terms','assign_terms' ) as $key ) {
            if ( isset( $capabilities[ $key ] ) && is_string( $capabilities[ $key ] ) && '' !== $capabilities[ $key ] ) $out[ $key ] = sanitize_key( $capabilities[ $key ] );
        }
        return $out;
    }

    private static function sanitize_taxonomy_rewrite( $value, string $key ) {
        if ( is_bool( $value ) ) return $value;
        if ( ! is_array( $value ) ) return new WP_Error( 'alify_ai_invalid_rewrite', 'rewrite must be boolean or an object.', array( 'status' => 400 ) );
        $rewrite = array( 'slug' => sanitize_title( (string) ( $value['slug'] ?? $key ) ) );
        if ( '' === $rewrite['slug'] ) $rewrite['slug'] = $key;
        foreach ( array( 'with_front', 'hierarchical' ) as $field ) if ( array_key_exists( $field, $value ) ) $rewrite[ $field ] = (bool) $value[ $field ];
        if ( array_key_exists( 'ep_mask', $value ) ) $rewrite['ep_mask'] = absint( $value['ep_mask'] );
        return $rewrite;
    }

    private static function serialize_taxonomy_object( string $key, WP_Taxonomy $object, ?array $definition ): array {
        $labels = array();
        if ( isset( $object->labels ) && is_object( $object->labels ) ) {
            foreach ( get_object_vars( $object->labels ) as $label_key => $value ) if ( is_string( $value ) ) $labels[ $label_key ] = $value;
        }
        $term_count = wp_count_terms( array( 'taxonomy' => $key, 'hide_empty' => false ) );
        return array(
            'key' => $key, 'label' => $object->label, 'singular' => $object->labels->singular_name ?? $object->label,
            'labels' => $labels, 'description' => (string) ( $object->description ?? '' ),
            'public' => (bool) $object->public, 'publicly_queryable' => (bool) ( $object->publicly_queryable ?? $object->public ),
            'hierarchical' => (bool) $object->hierarchical, 'show_ui' => (bool) $object->show_ui,
            'show_in_menu' => (bool) $object->show_in_menu, 'show_in_nav_menus' => (bool) $object->show_in_nav_menus,
            'show_tagcloud' => (bool) $object->show_tagcloud, 'show_in_quick_edit' => (bool) $object->show_in_quick_edit,
            'show_admin_column' => (bool) $object->show_admin_column, 'show_in_rest' => (bool) $object->show_in_rest,
            'rest_base' => $object->rest_base ?: $key, 'rest_namespace' => $object->rest_namespace ?? 'wp/v2',
            'capabilities' => isset( $object->cap ) ? get_object_vars( $object->cap ) : array(),
            'object_types' => array_values( (array) $object->object_type ), 'rewrite' => $object->rewrite,
            'query_var' => $object->query_var, 'sort' => (bool) $object->sort,
            'default_term' => $definition['default_term'] ?? null,
            'term_count' => is_wp_error( $term_count ) ? null : (int) $term_count,
            'managed' => is_array( $definition ), 'managed_definition' => $definition,
        );
    }

    private static function sync_managed_post_type_taxonomy_refs( string $taxonomy, array $object_types ): bool {
        $all = self::get_managed_post_types();
        $changed = false;
        foreach ( $all as $key => $definition ) {
            $taxonomies = array_values( array_unique( array_map( 'sanitize_key', (array) ( $definition['taxonomies'] ?? array() ) ) ) );
            $has = in_array( $taxonomy, $taxonomies, true );
            $should = in_array( $key, $object_types, true );
            if ( $should && ! $has ) { $taxonomies[] = $taxonomy; $changed = true; }
            if ( ! $should && $has ) { $taxonomies = array_values( array_diff( $taxonomies, array( $taxonomy ) ) ); $changed = true; }
            $all[ $key ]['taxonomies'] = array_values( array_unique( $taxonomies ) );
        }
        if ( ! $changed ) return true;
        return update_option( self::OPTION_POST_TYPES, $all, false ) || get_option( self::OPTION_POST_TYPES, array() ) === $all;
    }

    private static function serialize_term( WP_Term $term ): array {
        return array(
            'id' => (int) $term->term_id, 'term_taxonomy_id' => (int) $term->term_taxonomy_id,
            'taxonomy' => $term->taxonomy, 'name' => $term->name, 'slug' => $term->slug,
            'description' => $term->description, 'parent' => (int) $term->parent, 'count' => (int) $term->count,
        );
    }

}
