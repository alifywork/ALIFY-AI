<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ALIFY_AI_Media {
    private const BACKUP_RETENTION_DAYS = 90;
    private const MCP_MAX_BINARY_BYTES   = 8388608; // 8 MiB raw file before base64 overhead.

    public static function list_media( int $per_page = 20, string $search = '', string $mime_type = '', int $page = 1, int $parent = -1 ): array {
        $args = array(
            'post_type'      => 'attachment',
            'post_status'    => array( 'inherit', 'private', 'trash' ),
            'posts_per_page' => max( 1, min( 100, $per_page ) ),
            'paged'          => max( 1, $page ),
            's'              => sanitize_text_field( $search ),
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => false,
        );
        if ( '' !== $mime_type ) {
            $args['post_mime_type'] = sanitize_mime_type( $mime_type );
        }
        if ( $parent >= 0 ) {
            $args['post_parent'] = $parent;
        }
        $query = new WP_Query( $args );
        return array(
            'items'       => array_map( array( __CLASS__, 'serialize' ), $query->posts ),
            'page'        => max( 1, $page ),
            'per_page'    => max( 1, min( 100, $per_page ) ),
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
        );
    }

    public static function get_media( int $id ) {
        $post = get_post( $id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return new WP_Error( 'alify_ai_media_not_found', 'Media item not found.', array( 'status' => 404 ) );
        }
        return self::serialize( $post, true );
    }

    public static function get_upload_limits(): array {
        return array(
            'wordpress_max_bytes' => (int) wp_max_upload_size(),
            'mcp_max_raw_bytes'   => self::MCP_MAX_BINARY_BYTES,
            'allowed_mime_types'  => array_values( array_unique( array_values( get_allowed_mime_types() ) ) ),
            'remote_fetch'        => false,
            'replace_requires_same_extension' => true,
        );
    }

    public static function usages( int $id ): array {
        global $wpdb;
        $post = get_post( $id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return array();
        }
        $featured = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s ORDER BY post_id ASC LIMIT 500",
                (string) $id
            )
        );
        $featured_items = array();
        foreach ( $featured ?: array() as $post_id ) {
            $owner = get_post( (int) $post_id );
            if ( ! $owner ) { continue; }
            $featured_items[] = array(
                'id' => (int) $owner->ID,
                'type' => (string) $owner->post_type,
                'status' => (string) $owner->post_status,
                'title' => get_the_title( $owner ),
            );
        }
        return array(
            'attachment_id' => $id,
            'parent_id'     => (int) $post->post_parent,
            'featured_in'   => $featured_items,
            'featured_count'=> count( $featured_items ),
        );
    }

    public static function upload( array $file, array $input = array() ) {
        $validated = self::validate_upload_file( $file );
        if ( is_wp_error( $validated ) ) { return $validated; }
        $file = $validated;

        $parent = absint( $input['parent'] ?? 0 );
        if ( $parent && ! get_post( $parent ) ) {
            return new WP_Error( 'alify_ai_parent_not_found', 'Media parent post not found.', array( 'status' => 404 ) );
        }

        $hash = hash_file( 'sha256', $file['tmp_name'] );
        if ( false === $hash ) {
            return new WP_Error( 'alify_ai_hash_failed', 'Could not hash the uploaded file.', array( 'status' => 500 ) );
        }
        if ( empty( $input['allow_duplicate'] ) ) {
            $existing = self::find_by_hash( $hash );
            if ( $existing ) {
                return new WP_Error( 'alify_ai_duplicate_media', 'An identical file already exists in the Media Library.', array( 'status' => 409, 'existing_ids' => $existing ) );
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $post_data = array();
        if ( array_key_exists( 'title', $input ) ) { $post_data['post_title'] = sanitize_text_field( (string) $input['title'] ); }
        if ( array_key_exists( 'caption', $input ) ) { $post_data['post_excerpt'] = wp_kses_post( (string) $input['caption'] ); }
        if ( array_key_exists( 'description', $input ) ) { $post_data['post_content'] = wp_kses_post( (string) $input['description'] ); }

        try {
            $attachment_id = media_handle_sideload( $file, $parent, $post_data['post_title'] ?? null, $post_data );
        } catch ( Throwable $error ) {
            return new WP_Error( 'alify_ai_upload_runtime_failure', 'WordPress failed while processing the uploaded media file.', array( 'status' => 500 ) );
        }
        if ( is_wp_error( $attachment_id ) ) { return $attachment_id; }
        if ( ! $attachment_id ) {
            return new WP_Error( 'alify_ai_upload_failed', 'WordPress did not create a media attachment.', array( 'status' => 500 ) );
        }

        if ( array_key_exists( 'alt_text', $input ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt_text'] ) );
        }
        update_post_meta( $attachment_id, '_alify_ai_sha256', $hash );

        $item = self::get_media( $attachment_id );
        if ( is_wp_error( $item ) ) {
            wp_delete_attachment( $attachment_id, true );
            return $item;
        }
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( null, $item );
        if ( is_wp_error( $snapshot_ok ) ) {
            wp_delete_attachment( $attachment_id, true );
            return $snapshot_ok;
        }
        $log_id = ALIFY_AI_Audit::log( 'upload_media', 'attachment', $attachment_id, null, $item );
        if ( ! $log_id ) {
            wp_delete_attachment( $attachment_id, true );
            return new WP_Error( 'alify_ai_audit_failed', 'Media upload was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'activity_id' => $log_id, 'item' => $item );
    }

    public static function upload_base64( array $input ) {
        $filename = sanitize_file_name( (string) ( $input['filename'] ?? '' ) );
        $encoded  = (string) ( $input['data_base64'] ?? '' );
        if ( '' === $filename || '' === $encoded ) {
            return new WP_Error( 'alify_ai_base64_required', 'filename and data_base64 are required.', array( 'status' => 400 ) );
        }
        if ( str_contains( $encoded, ',' ) && str_starts_with( strtolower( trim( $encoded ) ), 'data:' ) ) {
            $encoded = substr( $encoded, strpos( $encoded, ',' ) + 1 );
        }
        $estimated = (int) floor( strlen( $encoded ) * 3 / 4 );
        if ( $estimated > self::MCP_MAX_BINARY_BYTES ) {
            return new WP_Error( 'alify_ai_mcp_upload_too_large', 'MCP upload exceeds the raw-file size limit.', array( 'status' => 413, 'max_bytes' => self::MCP_MAX_BINARY_BYTES ) );
        }
        $binary = base64_decode( preg_replace( '/\s+/', '', $encoded ), true );
        if ( false === $binary || '' === $binary ) {
            return new WP_Error( 'alify_ai_invalid_base64', 'data_base64 is not valid base64 file content.', array( 'status' => 400 ) );
        }
        if ( strlen( $binary ) > self::MCP_MAX_BINARY_BYTES ) {
            return new WP_Error( 'alify_ai_mcp_upload_too_large', 'MCP upload exceeds the raw-file size limit.', array( 'status' => 413, 'max_bytes' => self::MCP_MAX_BINARY_BYTES ) );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = wp_tempnam( $filename );
        if ( ! $tmp || false === file_put_contents( $tmp, $binary ) ) {
            if ( $tmp && file_exists( $tmp ) ) { @unlink( $tmp ); }
            return new WP_Error( 'alify_ai_temp_file_failed', 'Could not create the temporary upload file.', array( 'status' => 500 ) );
        }
        $file = array(
            'name'     => $filename,
            'tmp_name' => $tmp,
            'size'     => strlen( $binary ),
            'error'    => 0,
            'type'     => sanitize_mime_type( (string) ( $input['mime_type'] ?? '' ) ),
        );
        unset( $input['filename'], $input['data_base64'], $input['mime_type'] );
        try {
            return self::upload( $file, $input );
        } finally {
            if ( file_exists( $tmp ) ) { @unlink( $tmp ); }
        }
    }

    public static function update_media( int $id, array $input ) {
        $post = get_post( $id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return new WP_Error( 'alify_ai_media_not_found', 'Media item not found.', array( 'status' => 404 ) );
        }
        $before = self::snapshot_attachment( $id );
        if ( is_wp_error( $before ) ) { return $before; }

        $payload = array( 'ID' => $id );
        if ( array_key_exists( 'title', $input ) ) { $payload['post_title'] = sanitize_text_field( (string) $input['title'] ); }
        if ( array_key_exists( 'caption', $input ) ) { $payload['post_excerpt'] = wp_kses_post( (string) $input['caption'] ); }
        if ( array_key_exists( 'description', $input ) ) { $payload['post_content'] = wp_kses_post( (string) $input['description'] ); }
        if ( array_key_exists( 'parent', $input ) ) {
            $parent = absint( $input['parent'] );
            if ( $parent && ! get_post( $parent ) ) {
                return new WP_Error( 'alify_ai_parent_not_found', 'Media parent post not found.', array( 'status' => 404 ) );
            }
            $payload['post_parent'] = $parent;
        }
        $new_alt = array_key_exists( 'alt_text', $input ) ? sanitize_text_field( (string) $input['alt_text'] ) : null;
        if ( 1 === count( $payload ) && null === $new_alt ) {
            return new WP_Error( 'alify_ai_no_changes', 'No supported media fields were supplied.', array( 'status' => 400 ) );
        }

        try {
            if ( count( $payload ) > 1 ) {
                $result = wp_update_post( $payload, true );
                if ( is_wp_error( $result ) || ! $result ) {
                    return is_wp_error( $result ) ? $result : new WP_Error( 'alify_ai_media_update_failed', 'WordPress did not update the attachment.', array( 'status' => 500 ) );
                }
            }
            if ( null !== $new_alt ) { update_post_meta( $id, '_wp_attachment_image_alt', $new_alt ); }
            clean_post_cache( $id );
            $after = self::snapshot_attachment( $id );
            if ( is_wp_error( $after ) ) {
                $restore = self::restore_metadata_snapshot( $before );
                if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'update_media', $id, $restore ); }
                return $after;
            }
            $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before, $after );
            if ( is_wp_error( $snapshot_ok ) ) {
                $restore = self::restore_metadata_snapshot( $before );
                if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'update_media', $id, $restore ); }
                return $snapshot_ok;
            }
            $log_id = ALIFY_AI_Audit::log( 'update_media', 'attachment', $id, $before, $after );
            if ( ! $log_id ) {
                $restore = self::restore_metadata_snapshot( $before );
                if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'update_media', $id, $restore ); }
                return new WP_Error( 'alify_ai_audit_failed', 'Media update was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
            }
            return array( 'activity_id' => $log_id, 'item' => self::serialize( get_post( $id ), true ) );
        } catch ( Throwable $error ) {
            $restore = self::restore_metadata_snapshot( $before );
            if ( is_wp_error( $restore ) ) { return self::compensation_failure( 'update_media', $id, $restore, $error ); }
            return new WP_Error( 'alify_ai_media_update_runtime_failure', 'WordPress failed while updating the attachment.', array( 'status' => 500 ) );
        }
    }

    public static function delete_media( int $id, array $input = array() ) {
        $post = get_post( $id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return new WP_Error( 'alify_ai_media_not_found', 'Media item not found.', array( 'status' => 404 ) );
        }
        $usage = self::usages( $id );
        if ( ! empty( $usage['featured_count'] ) && empty( $input['confirm_in_use'] ) ) {
            return new WP_Error( 'alify_ai_media_in_use', 'This attachment is used as a featured image. Set confirm_in_use=true to delete it.', array( 'status' => 409, 'usage' => $usage ) );
        }

        $force = ! empty( $input['force'] );
        if ( ! $force ) {
            if ( ! defined( 'MEDIA_TRASH' ) || ! MEDIA_TRASH || ! EMPTY_TRASH_DAYS ) {
                return new WP_Error( 'alify_ai_media_trash_disabled', 'Media Trash is not enabled on this site. Use force=true and confirm_permanent=true for a backed-up permanent delete.', array( 'status' => 409 ) );
            }
            $before = self::snapshot_attachment( $id );
            if ( is_wp_error( $before ) ) { return $before; }
            if ( ! wp_trash_post( $id ) ) {
                return new WP_Error( 'alify_ai_media_trash_failed', 'Could not move the attachment to Trash.', array( 'status' => 500 ) );
            }
            $after = self::snapshot_attachment( $id );
            $log_id = ALIFY_AI_Audit::log( 'trash_media', 'attachment', $id, $before, $after );
            if ( ! $log_id ) {
                $untrashed = wp_untrash_post( $id );
                $restore = self::restore_metadata_snapshot( $before );
                if ( ! $untrashed || is_wp_error( $restore ) ) {
                    $failure = is_wp_error( $restore ) ? $restore : new WP_Error( 'alify_ai_media_untrash_failed', 'Could not restore media from Trash during compensation.' );
                    return self::compensation_failure( 'trash_media', $id, $failure );
                }
                return new WP_Error( 'alify_ai_audit_failed', 'Media trash operation was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
            }
            return array( 'activity_id' => $log_id, 'trashed' => true, 'item' => self::serialize( get_post( $id ), true ) );
        }

        if ( empty( $input['confirm_permanent'] ) ) {
            return new WP_Error( 'alify_ai_confirm_permanent_required', 'Permanent media deletion requires confirm_permanent=true.', array( 'status' => 400 ) );
        }
        $before = self::snapshot_attachment( $id );
        if ( is_wp_error( $before ) ) { return $before; }
        $backup = self::create_file_backup( $id );
        if ( is_wp_error( $backup ) ) { return $backup; }
        $before['file_backup'] = $backup;
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before, array( 'deleted' => true ) );
        if ( is_wp_error( $snapshot_ok ) ) {
            self::delete_backup( (string) ( $backup['backup_id'] ?? '' ) );
            return $snapshot_ok;
        }
        $log_id = ALIFY_AI_Audit::log( 'delete_media', 'attachment', $id, $before, array( 'deleted' => true ) );
        if ( ! $log_id ) {
            self::delete_backup( (string) ( $backup['backup_id'] ?? '' ) );
            return new WP_Error( 'alify_ai_audit_failed', 'Could not store the deletion rollback record, so the attachment was not deleted.', array( 'status' => 500 ) );
        }
        try {
            $deleted = wp_delete_attachment( $id, true );
        } catch ( Throwable $error ) {
            ALIFY_AI_Audit::delete_entry( $log_id );
            self::delete_backup( (string) ( $backup['backup_id'] ?? '' ) );
            return new WP_Error( 'alify_ai_media_delete_runtime_failure', 'WordPress failed while deleting the attachment.', array( 'status' => 500 ) );
        }
        if ( ! $deleted ) {
            ALIFY_AI_Audit::delete_entry( $log_id );
            self::delete_backup( (string) ( $backup['backup_id'] ?? '' ) );
            return new WP_Error( 'alify_ai_media_delete_failed', 'WordPress did not delete the attachment.', array( 'status' => 500 ) );
        }
        return array( 'activity_id' => $log_id, 'deleted' => true, 'permanent' => true, 'original_id' => $id );
    }

    public static function restore_media( int $id ) {
        $post = get_post( $id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return new WP_Error( 'alify_ai_media_not_found', 'Media item not found.', array( 'status' => 404 ) );
        }
        if ( 'trash' !== $post->post_status ) {
            return new WP_Error( 'alify_ai_media_not_trashed', 'The media item is not in Trash.', array( 'status' => 409 ) );
        }
        $before = self::snapshot_attachment( $id );
        if ( is_wp_error( $before ) ) { return $before; }
        if ( ! wp_untrash_post( $id ) ) {
            return new WP_Error( 'alify_ai_media_restore_failed', 'Could not restore the attachment from Trash.', array( 'status' => 500 ) );
        }
        $after = self::snapshot_attachment( $id );
        $log_id = ALIFY_AI_Audit::log( 'restore_media', 'attachment', $id, $before, $after );
        if ( ! $log_id ) {
            $retrashed = wp_trash_post( $id );
            if ( ! $retrashed ) {
                return self::compensation_failure( 'restore_media', $id, new WP_Error( 'alify_ai_media_retrash_failed', 'Could not return media to Trash during compensation.' ) );
            }
            return new WP_Error( 'alify_ai_audit_failed', 'Media restore was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'activity_id' => $log_id, 'item' => self::serialize( get_post( $id ), true ) );
    }

    public static function replace_file( int $id, array $file, array $input = array() ) {
        $post = get_post( $id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return new WP_Error( 'alify_ai_media_not_found', 'Media item not found.', array( 'status' => 404 ) );
        }
        $validated = self::validate_upload_file( $file );
        if ( is_wp_error( $validated ) ) { return $validated; }
        $file = $validated;
        $current_file = get_attached_file( $id );
        if ( ! $current_file || ! file_exists( $current_file ) ) {
            return new WP_Error( 'alify_ai_media_file_missing', 'The current attachment file is not available locally.', array( 'status' => 409 ) );
        }
        $current_ext = strtolower( (string) pathinfo( $current_file, PATHINFO_EXTENSION ) );
        $new_ext     = strtolower( (string) pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( '' === $current_ext || $current_ext !== $new_ext ) {
            return new WP_Error( 'alify_ai_replace_extension_mismatch', 'File replacement requires the same file extension so existing attachment URLs remain stable.', array( 'status' => 400, 'current_extension' => $current_ext, 'new_extension' => $new_ext ) );
        }
        $backup = self::create_file_backup( $id );
        if ( is_wp_error( $backup ) ) { return $backup; }
        $before = self::snapshot_attachment( $id );
        if ( is_wp_error( $before ) ) { self::delete_backup( $backup['backup_id'] ); return $before; }
        $before['file_backup'] = $backup;

        $result = self::replace_main_file_and_regenerate( $id, $file['tmp_name'], $before, ! empty( $input['preserve_metadata'] ) );
        if ( is_wp_error( $result ) ) { self::delete_backup( $backup['backup_id'] ); return $result; }
        $after = self::snapshot_attachment( $id );
        if ( is_wp_error( $after ) ) {
            self::restore_file_backup_and_snapshot( $id, $before );
            self::delete_backup( $backup['backup_id'] );
            return $after;
        }
        $after_hash = hash_file( 'sha256', get_attached_file( $id ) );
        if ( $after_hash ) { update_post_meta( $id, '_alify_ai_sha256', $after_hash ); $after['sha256'] = $after_hash; }
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before, $after );
        if ( is_wp_error( $snapshot_ok ) ) {
            self::restore_file_backup_and_snapshot( $id, $before );
            self::delete_backup( $backup['backup_id'] );
            return $snapshot_ok;
        }
        $log_id = ALIFY_AI_Audit::log( 'replace_media_file', 'attachment', $id, $before, $after );
        if ( ! $log_id ) {
            self::restore_file_backup_and_snapshot( $id, $before );
            self::delete_backup( $backup['backup_id'] );
            return new WP_Error( 'alify_ai_audit_failed', 'File replacement was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'activity_id' => $log_id, 'item' => self::serialize( get_post( $id ), true ) );
    }

    public static function replace_file_base64( int $id, array $input ) {
        $filename = sanitize_file_name( (string) ( $input['filename'] ?? '' ) );
        $encoded  = (string) ( $input['data_base64'] ?? '' );
        if ( '' === $filename || '' === $encoded ) {
            return new WP_Error( 'alify_ai_base64_required', 'filename and data_base64 are required.', array( 'status' => 400 ) );
        }
        if ( str_contains( $encoded, ',' ) && str_starts_with( strtolower( trim( $encoded ) ), 'data:' ) ) {
            $encoded = substr( $encoded, strpos( $encoded, ',' ) + 1 );
        }
        $binary = base64_decode( preg_replace( '/\s+/', '', $encoded ), true );
        if ( false === $binary || '' === $binary ) {
            return new WP_Error( 'alify_ai_invalid_base64', 'data_base64 is not valid base64 file content.', array( 'status' => 400 ) );
        }
        if ( strlen( $binary ) > self::MCP_MAX_BINARY_BYTES ) {
            return new WP_Error( 'alify_ai_mcp_upload_too_large', 'MCP replacement exceeds the raw-file size limit.', array( 'status' => 413, 'max_bytes' => self::MCP_MAX_BINARY_BYTES ) );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = wp_tempnam( $filename );
        if ( ! $tmp || false === file_put_contents( $tmp, $binary ) ) {
            if ( $tmp && file_exists( $tmp ) ) { @unlink( $tmp ); }
            return new WP_Error( 'alify_ai_temp_file_failed', 'Could not create the temporary replacement file.', array( 'status' => 500 ) );
        }
        $file = array( 'name' => $filename, 'tmp_name' => $tmp, 'size' => strlen( $binary ), 'error' => 0, 'type' => sanitize_mime_type( (string) ( $input['mime_type'] ?? '' ) ) );
        try {
            return self::replace_file( $id, $file, $input );
        } finally {
            if ( file_exists( $tmp ) ) { @unlink( $tmp ); }
        }
    }

    public static function edit_image( int $id, array $input ) {
        $post = get_post( $id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return new WP_Error( 'alify_ai_media_not_found', 'Media item not found.', array( 'status' => 404 ) );
        }
        if ( ! wp_attachment_is_image( $id ) ) {
            return new WP_Error( 'alify_ai_not_image', 'Image processing is only available for image attachments.', array( 'status' => 400 ) );
        }
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) {
            return new WP_Error( 'alify_ai_media_file_missing', 'The image file is not available locally.', array( 'status' => 409 ) );
        }
        $operation = sanitize_key( (string) ( $input['operation'] ?? '' ) );
        if ( ! in_array( $operation, array( 'resize', 'crop', 'rotate', 'flip' ), true ) ) {
            return new WP_Error( 'alify_ai_image_operation_invalid', 'operation must be resize, crop, rotate, or flip.', array( 'status' => 400 ) );
        }
        $backup = self::create_file_backup( $id );
        if ( is_wp_error( $backup ) ) { return $backup; }
        $before = self::snapshot_attachment( $id );
        if ( is_wp_error( $before ) ) { self::delete_backup( $backup['backup_id'] ); return $before; }
        $before['file_backup'] = $backup;

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $editor = wp_get_image_editor( $file );
        if ( is_wp_error( $editor ) ) { self::delete_backup( $backup['backup_id'] ); return $editor; }
        if ( method_exists( $editor, 'maybe_exif_rotate' ) ) { $editor->maybe_exif_rotate(); }
        $edit = true;
        if ( 'resize' === $operation ) {
            $width = isset( $input['width'] ) ? absint( $input['width'] ) : null;
            $height = isset( $input['height'] ) ? absint( $input['height'] ) : null;
            if ( ! $width && ! $height ) { self::delete_backup( $backup['backup_id'] ); return new WP_Error( 'alify_ai_resize_dimensions_required', 'Resize requires width or height.', array( 'status' => 400 ) ); }
            $crop = false;
            if ( ! empty( $input['crop'] ) ) {
                $crop = true;
                if ( is_array( $input['crop'] ) && count( $input['crop'] ) >= 2 ) {
                    $x = in_array( $input['crop'][0], array( 'left','center','right' ), true ) ? $input['crop'][0] : 'center';
                    $y = in_array( $input['crop'][1], array( 'top','center','bottom' ), true ) ? $input['crop'][1] : 'center';
                    $crop = array( $x, $y );
                }
            }
            $edit = $editor->resize( $width ?: null, $height ?: null, $crop );
        } elseif ( 'crop' === $operation ) {
            foreach ( array( 'x','y','width','height' ) as $required ) {
                if ( ! isset( $input[ $required ] ) ) { self::delete_backup( $backup['backup_id'] ); return new WP_Error( 'alify_ai_crop_args_required', 'Crop requires x, y, width and height.', array( 'status' => 400 ) ); }
            }
            $edit = $editor->crop( absint( $input['x'] ), absint( $input['y'] ), absint( $input['width'] ), absint( $input['height'] ), isset( $input['target_width'] ) ? absint( $input['target_width'] ) : null, isset( $input['target_height'] ) ? absint( $input['target_height'] ) : null );
        } elseif ( 'rotate' === $operation ) {
            $angle = (float) ( $input['angle'] ?? 0 );
            if ( 0.0 === $angle ) { self::delete_backup( $backup['backup_id'] ); return new WP_Error( 'alify_ai_rotate_angle_required', 'Rotate requires a non-zero angle.', array( 'status' => 400 ) ); }
            $edit = $editor->rotate( $angle );
        } else {
            $horizontal = ! empty( $input['horizontal'] );
            $vertical   = ! empty( $input['vertical'] );
            if ( ! $horizontal && ! $vertical ) { self::delete_backup( $backup['backup_id'] ); return new WP_Error( 'alify_ai_flip_axis_required', 'Flip requires horizontal=true and/or vertical=true.', array( 'status' => 400 ) ); }
            // WP_Image_Editor arguments are horizontal-axis and vertical-axis flips.
            $edit = $editor->flip( $vertical, $horizontal );
        }
        if ( is_wp_error( $edit ) ) { self::delete_backup( $backup['backup_id'] ); return $edit; }
        $saved = $editor->save( $file );
        if ( is_wp_error( $saved ) ) { self::restore_file_backup_and_snapshot( $id, $before ); self::delete_backup( $backup['backup_id'] ); return $saved; }

        $regen = self::regenerate_attachment_metadata_internal( $id, false );
        if ( is_wp_error( $regen ) ) { self::restore_file_backup_and_snapshot( $id, $before ); self::delete_backup( $backup['backup_id'] ); return $regen; }
        $hash = hash_file( 'sha256', $file );
        if ( $hash ) { update_post_meta( $id, '_alify_ai_sha256', $hash ); }
        $after = self::snapshot_attachment( $id );
        if ( is_wp_error( $after ) ) { self::restore_file_backup_and_snapshot( $id, $before ); self::delete_backup( $backup['backup_id'] ); return $after; }
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before, $after );
        if ( is_wp_error( $snapshot_ok ) ) { self::restore_file_backup_and_snapshot( $id, $before ); self::delete_backup( $backup['backup_id'] ); return $snapshot_ok; }
        $log_id = ALIFY_AI_Audit::log( 'edit_media_image', 'attachment', $id, $before, $after );
        if ( ! $log_id ) { self::restore_file_backup_and_snapshot( $id, $before ); self::delete_backup( $backup['backup_id'] ); return new WP_Error( 'alify_ai_audit_failed', 'Image edit was reverted because the activity log could not be stored.', array( 'status' => 500 ) ); }
        return array( 'activity_id' => $log_id, 'operation' => $operation, 'item' => self::serialize( get_post( $id ), true ) );
    }

    public static function regenerate_metadata( int $id ) {
        $post = get_post( $id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return new WP_Error( 'alify_ai_media_not_found', 'Media item not found.', array( 'status' => 404 ) );
        }
        $backup = self::create_file_backup( $id );
        if ( is_wp_error( $backup ) ) { return $backup; }
        $before = self::snapshot_attachment( $id );
        if ( is_wp_error( $before ) ) { self::delete_backup( $backup['backup_id'] ); return $before; }
        $before['file_backup'] = $backup;
        $result = self::regenerate_attachment_metadata_internal( $id, true );
        if ( is_wp_error( $result ) ) { self::restore_file_backup_and_snapshot( $id, $before ); self::delete_backup( $backup['backup_id'] ); return $result; }
        $after = self::snapshot_attachment( $id );
        if ( is_wp_error( $after ) ) { self::restore_file_backup_and_snapshot( $id, $before ); self::delete_backup( $backup['backup_id'] ); return $after; }
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $before, $after );
        if ( is_wp_error( $snapshot_ok ) ) { self::restore_file_backup_and_snapshot( $id, $before ); self::delete_backup( $backup['backup_id'] ); return $snapshot_ok; }
        $log_id = ALIFY_AI_Audit::log( 'regenerate_media_metadata', 'attachment', $id, $before, $after );
        if ( ! $log_id ) { self::restore_file_backup_and_snapshot( $id, $before ); self::delete_backup( $backup['backup_id'] ); return new WP_Error( 'alify_ai_audit_failed', 'Metadata regeneration was reverted because the activity log could not be stored.', array( 'status' => 500 ) ); }
        return array( 'activity_id' => $log_id, 'item' => self::serialize( get_post( $id ), true ) );
    }

    public static function rollback_activity( array $entry ) {
        $action = (string) ( $entry['action'] ?? '' );
        $id = absint( $entry['object_id'] ?? 0 );
        if ( 'upload_media' === $action ) {
            $post = get_post( $id );
            if ( ! $post || 'attachment' !== $post->post_type ) {
                return new WP_Error( 'alify_ai_media_not_found', 'Uploaded media no longer exists.', array( 'status' => 404 ) );
            }
            $current = self::snapshot_attachment( $id );
            $log_id = ALIFY_AI_Audit::log( 'rollback_upload_media', 'attachment', $id, $current, array( 'deleted' => true ) );
            if ( ! $log_id ) { return new WP_Error( 'alify_ai_audit_failed', 'Could not store rollback activity; uploaded media was not deleted.', array( 'status' => 500 ) ); }
            if ( ! wp_delete_attachment( $id, true ) ) { ALIFY_AI_Audit::delete_entry( $log_id ); return new WP_Error( 'alify_ai_media_delete_failed', 'Could not delete the uploaded media.', array( 'status' => 500 ) ); }
            return array( 'message' => 'Uploaded media removed.', 'activity_id' => $log_id );
        }
        if ( 'update_media' === $action && is_array( $entry['before_json'] ?? null ) ) {
            $current = self::snapshot_attachment( $id );
            if ( is_wp_error( $current ) ) { return $current; }
            $restored = self::restore_metadata_snapshot( $entry['before_json'] );
            if ( is_wp_error( $restored ) ) { return $restored; }
            $after = self::snapshot_attachment( $id );
            $log_id = ALIFY_AI_Audit::log( 'rollback_update_media', 'attachment', $id, $current, $after );
            if ( ! $log_id ) { self::restore_metadata_snapshot( $current ); return new WP_Error( 'alify_ai_audit_failed', 'Media rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) ); }
            return array( 'message' => 'Media metadata rolled back.', 'activity_id' => $log_id, 'item' => self::serialize( get_post( $id ), true ) );
        }
        if ( 'trash_media' === $action ) {
            $post = get_post( $id );
            if ( ! $post || 'trash' !== $post->post_status ) { return new WP_Error( 'alify_ai_rollback_conflict', 'The media item is no longer in Trash.', array( 'status' => 409 ) ); }
            if ( ! wp_untrash_post( $id ) ) { return new WP_Error( 'alify_ai_media_restore_failed', 'Could not restore the media item.', array( 'status' => 500 ) ); }
            if ( is_array( $entry['before_json'] ?? null ) ) { self::restore_metadata_snapshot( $entry['before_json'] ); }
            $after = self::snapshot_attachment( $id );
            $log_id = ALIFY_AI_Audit::log( 'rollback_trash_media', 'attachment', $id, $entry['after_json'] ?? null, $after );
            if ( ! $log_id ) { wp_trash_post( $id ); return new WP_Error( 'alify_ai_audit_failed', 'Media rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) ); }
            return array( 'message' => 'Media trash operation rolled back.', 'activity_id' => $log_id, 'item' => self::serialize( get_post( $id ), true ) );
        }
        if ( 'restore_media' === $action ) {
            if ( ! defined( 'MEDIA_TRASH' ) || ! MEDIA_TRASH || ! EMPTY_TRASH_DAYS ) { return new WP_Error( 'alify_ai_rollback_conflict', 'Media Trash is not available, so this restore cannot be rolled back safely.', array( 'status' => 409 ) ); }
            if ( ! wp_trash_post( $id ) ) { return new WP_Error( 'alify_ai_media_trash_failed', 'Could not move the media item back to Trash.', array( 'status' => 500 ) ); }
            $after = self::snapshot_attachment( $id );
            $log_id = ALIFY_AI_Audit::log( 'rollback_restore_media', 'attachment', $id, $entry['after_json'] ?? null, $after );
            if ( ! $log_id ) { wp_untrash_post( $id ); return new WP_Error( 'alify_ai_audit_failed', 'Media rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) ); }
            return array( 'message' => 'Media restore rolled back.', 'activity_id' => $log_id );
        }
        if ( 'delete_media' === $action && is_array( $entry['before_json'] ?? null ) ) {
            $restored = self::recreate_deleted_attachment( $entry['before_json'] );
            if ( is_wp_error( $restored ) ) { return $restored; }
            $new_id = absint( $restored['id'] ?? 0 );
            $log_id = ALIFY_AI_Audit::log( 'rollback_delete_media', 'attachment', $new_id, null, $restored );
            if ( ! $log_id ) {
                $removed = $new_id ? wp_delete_attachment( $new_id, true ) : false;
                if ( $new_id && ( ! $removed || get_post( $new_id ) ) ) {
                    ALIFY_AI_Diagnostics::log( 'media_compensation_failed', array( 'attachment_id' => $new_id, 'stage' => 'rollback_delete_audit_failure' ) );
                    return new WP_Error( 'alify_ai_media_unknown_state', 'Deleted media was recreated, rollback logging failed, and the recreation could not be verified as removed; site state requires review.', array( 'status' => 500 ) );
                }
                return new WP_Error( 'alify_ai_audit_failed', 'Deleted media was recreated but rollback logging failed, so the recreation was removed.', array( 'status' => 500 ) );
            }
            return array( 'message' => 'Permanently deleted media recreated from backup.', 'activity_id' => $log_id, 'item' => $restored, 'original_id' => $id, 'new_id' => $new_id );
        }
        if ( in_array( $action, array( 'replace_media_file', 'edit_media_image', 'regenerate_media_metadata' ), true ) && is_array( $entry['before_json'] ?? null ) ) {
            $current = self::snapshot_attachment( $id );
            if ( is_wp_error( $current ) ) { return $current; }
            $result = self::restore_file_backup_and_snapshot( $id, $entry['before_json'] );
            if ( is_wp_error( $result ) ) { return $result; }
            $after = self::snapshot_attachment( $id );
            $log_id = ALIFY_AI_Audit::log( 'rollback_' . $action, 'attachment', $id, $current, $after );
            if ( ! $log_id ) {
                ALIFY_AI_Diagnostics::log( 'media_rollback_log_failed_after_file_restore', array( 'attachment_id' => $id, 'action' => $action ) );
                return new WP_Error( 'alify_ai_media_unknown_state', 'Media file rollback succeeded but its rollback activity could not be logged; the file state changed without an audit record and requires review.', array( 'status' => 500 ) );
            }
            return array( 'message' => 'Media file change rolled back.', 'activity_id' => $log_id, 'item' => self::serialize( get_post( $id ), true ) );
        }
        return new WP_Error( 'alify_ai_media_rollback_unsupported', 'This media activity cannot be rolled back.', array( 'status' => 409 ) );
    }

    public static function cleanup_backups( int $days = self::BACKUP_RETENTION_DAYS ): int {
        $root = self::backup_root( false );
        if ( ! $root || ! is_dir( $root ) ) { return 0; }
        $days = max( 7, min( 3650, $days ) );
        $cutoff = time() - ( DAY_IN_SECONDS * $days );
        $deleted = 0;
        foreach ( glob( trailingslashit( $root ) . '*' ) ?: array() as $path ) {
            if ( ! is_dir( $path ) ) { continue; }
            $mtime = @filemtime( $path );
            if ( $mtime && $mtime < $cutoff ) { self::delete_directory( $path ); $deleted++; }
        }
        return $deleted;
    }

    public static function serialize( WP_Post $post, bool $full = false ): array {
        $meta = wp_get_attachment_metadata( $post->ID );
        $file = get_attached_file( $post->ID );
        $data = array(
            'id'         => (int) $post->ID,
            'title'      => get_the_title( $post ),
            'mime_type'  => (string) $post->post_mime_type,
            'status'     => (string) $post->post_status,
            'url'        => wp_get_attachment_url( $post->ID ),
            'alt_text'   => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
            'caption'    => (string) $post->post_excerpt,
            'parent'     => (int) $post->post_parent,
            'date_gmt'   => get_post_time( DATE_ATOM, true, $post ),
            'modified'   => get_post_modified_time( DATE_ATOM, true, $post ),
            'filesize'   => $file && file_exists( $file ) ? (int) filesize( $file ) : null,
            'sha256'     => (string) get_post_meta( $post->ID, '_alify_ai_sha256', true ),
        );
        if ( is_array( $meta ) ) {
            $data['width']  = isset( $meta['width'] ) ? (int) $meta['width'] : null;
            $data['height'] = isset( $meta['height'] ) ? (int) $meta['height'] : null;
        }
        if ( $full ) {
            $data['description'] = (string) $post->post_content;
            $data['filename']    = $file ? wp_basename( $file ) : null;
            $data['metadata']    = is_array( $meta ) ? $meta : array();
            $data['sizes']       = is_array( $meta ) && isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array();
            $data['usages']      = self::usages( $post->ID );
        }
        return $data;
    }

    private static function validate_upload_file( array $file ) {
        if ( empty( $file['tmp_name'] ) || empty( $file['name'] ) ) {
            return new WP_Error( 'alify_ai_file_required', 'A media file is required.', array( 'status' => 400 ) );
        }
        if ( ! empty( $file['error'] ) ) {
            return new WP_Error( 'alify_ai_upload_error', 'The uploaded file reported an error.', array( 'status' => 400, 'upload_error' => (int) $file['error'] ) );
        }
        if ( ! file_exists( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
            return new WP_Error( 'alify_ai_upload_temp_missing', 'The temporary uploaded file is unavailable.', array( 'status' => 400 ) );
        }
        $size = isset( $file['size'] ) ? (int) $file['size'] : (int) filesize( $file['tmp_name'] );
        if ( $size <= 0 ) { return new WP_Error( 'alify_ai_empty_upload', 'The uploaded file is empty.', array( 'status' => 400 ) ); }
        $max = (int) wp_max_upload_size();
        if ( $max > 0 && $size > $max ) { return new WP_Error( 'alify_ai_upload_too_large', 'The uploaded file exceeds the WordPress upload limit.', array( 'status' => 413, 'max_bytes' => $max ) ); }
        $file['name'] = sanitize_file_name( (string) $file['name'] );
        if ( '' === $file['name'] ) { return new WP_Error( 'alify_ai_invalid_filename', 'The upload filename is invalid.', array( 'status' => 400 ) ); }
        $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], get_allowed_mime_types() );
        if ( empty( $check['type'] ) || empty( $check['ext'] ) ) {
            return new WP_Error( 'alify_ai_file_type_not_allowed', 'WordPress does not allow this file type.', array( 'status' => 400 ) );
        }
        $file['type'] = $check['type'];
        $file['size'] = $size;
        $file['error'] = 0;
        return $file;
    }

    private static function find_by_hash( string $hash ): array {
        $query = new WP_Query( array( 'post_type' => 'attachment', 'post_status' => array( 'inherit','private','trash' ), 'posts_per_page' => 20, 'fields' => 'ids', 'meta_key' => '_alify_ai_sha256', 'meta_value' => $hash, 'no_found_rows' => true ) );
        return array_map( 'intval', $query->posts ?: array() );
    }

    private static function snapshot_attachment( int $id ) {
        $post = get_post( $id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return new WP_Error( 'alify_ai_media_not_found', 'Media item not found.', array( 'status' => 404 ) );
        }
        $all_meta = get_post_meta( $id );
        $safe_meta = array();
        foreach ( $all_meta as $key => $values ) {
            if ( str_starts_with( (string) $key, '_edit_lock' ) || str_starts_with( (string) $key, '_edit_last' ) ) { continue; }
            $safe_meta[ (string) $key ] = array_map( 'maybe_unserialize', (array) $values );
        }
        return array(
            'id' => $id,
            'post' => array(
                'post_title' => (string) $post->post_title,
                'post_content' => (string) $post->post_content,
                'post_excerpt' => (string) $post->post_excerpt,
                'post_status' => (string) $post->post_status,
                'post_parent' => (int) $post->post_parent,
                'post_mime_type' => (string) $post->post_mime_type,
                'post_date' => (string) $post->post_date,
                'post_date_gmt' => (string) $post->post_date_gmt,
                'menu_order' => (int) $post->menu_order,
            ),
            'attached_file' => (string) get_post_meta( $id, '_wp_attached_file', true ),
            'attachment_metadata' => wp_get_attachment_metadata( $id ),
            'meta' => $safe_meta,
            'sha256' => (string) get_post_meta( $id, '_alify_ai_sha256', true ),
            'featured_in_ids' => array_map( 'intval', array_column( (array) ( self::usages( $id )['featured_in'] ?? array() ), 'id' ) ),
        );
    }

    private static function restore_metadata_snapshot( array $snapshot ) {
        $id = absint( $snapshot['id'] ?? 0 );
        if ( ! $id || ! get_post( $id ) ) { return new WP_Error( 'alify_ai_media_not_found', 'Media item no longer exists.', array( 'status' => 404 ) ); }
        $post_data = is_array( $snapshot['post'] ?? null ) ? $snapshot['post'] : array();
        $update = array( 'ID' => $id );
        foreach ( array( 'post_title','post_content','post_excerpt','post_status','post_parent','post_mime_type','post_date','post_date_gmt','menu_order' ) as $field ) {
            if ( array_key_exists( $field, $post_data ) ) { $update[ $field ] = $post_data[ $field ]; }
        }
        $result = wp_update_post( $update, true );
        if ( is_wp_error( $result ) || ! $result ) { return is_wp_error( $result ) ? $result : new WP_Error( 'alify_ai_media_restore_failed', 'Could not restore media metadata.', array( 'status' => 500 ) ); }
        $existing = get_post_meta( $id );
        foreach ( array_keys( $existing ) as $key ) {
            if ( '_wp_attached_file' === $key || '_wp_attachment_metadata' === $key || str_starts_with( (string) $key, '_edit_' ) ) { continue; }
            delete_post_meta( $id, $key );
        }
        foreach ( (array) ( $snapshot['meta'] ?? array() ) as $key => $values ) {
            if ( '_wp_attached_file' === $key || '_wp_attachment_metadata' === $key ) { continue; }
            delete_post_meta( $id, $key );
            foreach ( (array) $values as $value ) { add_post_meta( $id, $key, $value ); }
        }
        if ( isset( $snapshot['attached_file'] ) ) { update_post_meta( $id, '_wp_attached_file', (string) $snapshot['attached_file'] ); }
        if ( array_key_exists( 'attachment_metadata', $snapshot ) ) { wp_update_attachment_metadata( $id, is_array( $snapshot['attachment_metadata'] ) ? $snapshot['attachment_metadata'] : array() ); }
        clean_post_cache( $id );
        return true;
    }

    private static function compensation_failure( string $operation, int $id, WP_Error $restore_error, ?Throwable $runtime_error = null ): WP_Error {
        $context = array(
            'operation' => $operation,
            'attachment_id' => $id,
            'restore_code' => $restore_error->get_error_code(),
            'restore_message' => $restore_error->get_error_message(),
        );
        if ( $runtime_error ) { $context['runtime_exception'] = get_class( $runtime_error ); }
        ALIFY_AI_Diagnostics::log( 'Media compensation failed; attachment state may be partially changed.', $context );
        return new WP_Error(
            'alify_ai_media_unknown_state',
            'The media operation failed and the previous state could not be fully restored. Verify the attachment before retrying.',
            array( 'status' => 500, 'operation' => $operation, 'attachment_id' => $id, 'restore_error' => $restore_error->get_error_code() )
        );
    }

    private static function backup_root( bool $create = true ): string {
        $root = trailingslashit( WP_CONTENT_DIR ) . 'alify-ai-private/media-backups';
        if ( $create && ! is_dir( $root ) ) {
            if ( ! wp_mkdir_p( $root ) ) { return ''; }
            @file_put_contents( trailingslashit( dirname( $root ) ) . 'index.php', "<?php\nhttp_response_code(404);\nexit;\n" );
            @file_put_contents( trailingslashit( dirname( $root ) ) . '.htaccess', "Deny from all\n" );
            @file_put_contents( trailingslashit( dirname( $root ) ) . 'web.config', '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
        }
        return $root;
    }

    private static function create_file_backup( int $id ) {
        $files = self::collect_attachment_files( $id );
        if ( is_wp_error( $files ) ) { return $files; }
        $root = self::backup_root();
        if ( '' === $root ) { return new WP_Error( 'alify_ai_backup_dir_failed', 'Could not create the protected media backup directory.', array( 'status' => 500 ) ); }
        $backup_id = gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 12, false, false ) );
        $dir = trailingslashit( $root ) . $backup_id;
        if ( ! wp_mkdir_p( trailingslashit( $dir ) . 'files' ) ) { return new WP_Error( 'alify_ai_backup_dir_failed', 'Could not create the media backup directory.', array( 'status' => 500 ) ); }
        $manifest = array();
        foreach ( $files as $entry ) {
            $target = trailingslashit( $dir ) . 'files/' . $entry['relative'];
            if ( ! wp_mkdir_p( dirname( $target ) ) || ! @copy( $entry['absolute'], $target ) ) {
                self::delete_directory( $dir );
                return new WP_Error( 'alify_ai_media_backup_failed', 'Could not back up attachment files before the destructive change.', array( 'status' => 500 ) );
            }
            $manifest[] = array( 'relative' => $entry['relative'], 'size' => (int) filesize( $entry['absolute'] ), 'sha256' => hash_file( 'sha256', $entry['absolute'] ) ?: '' );
        }
        @file_put_contents( trailingslashit( $dir ) . 'manifest.json', wp_json_encode( array( 'attachment_id' => $id, 'created_at' => gmdate( DATE_ATOM ), 'files' => $manifest ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        return array( 'backup_id' => $backup_id, 'files' => $manifest );
    }

    private static function collect_attachment_files( int $id ) {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) { return new WP_Error( 'alify_ai_upload_dir_error', (string) $uploads['error'], array( 'status' => 500 ) ); }
        $base = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
        $main = get_attached_file( $id );
        if ( ! $main || ! file_exists( $main ) ) { return new WP_Error( 'alify_ai_media_file_missing', 'Attachment file is not available locally; file-changing operations are disabled for this item.', array( 'status' => 409 ) ); }
        $candidates = array( $main );
        $meta = wp_get_attachment_metadata( $id );
        if ( is_array( $meta ) ) {
            $dir = dirname( $main );
            foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
                if ( ! empty( $size['file'] ) ) { $candidates[] = trailingslashit( $dir ) . wp_basename( (string) $size['file'] ); }
            }
            if ( ! empty( $meta['original_image'] ) ) { $candidates[] = trailingslashit( $dir ) . wp_basename( (string) $meta['original_image'] ); }
        }
        $result = array();
        foreach ( array_unique( array_map( 'wp_normalize_path', $candidates ) ) as $path ) {
            if ( ! file_exists( $path ) ) { continue; }
            if ( ! str_starts_with( $path, $base ) ) { return new WP_Error( 'alify_ai_media_outside_uploads', 'Attachment files outside the WordPress uploads directory cannot be modified safely.', array( 'status' => 409 ) ); }
            $relative = ltrim( substr( $path, strlen( $base ) ), '/' );
            if ( '' === $relative || str_contains( $relative, '..' ) ) { return new WP_Error( 'alify_ai_media_path_invalid', 'Attachment file path is unsafe.', array( 'status' => 409 ) ); }
            $result[] = array( 'absolute' => $path, 'relative' => $relative );
        }
        return $result;
    }

    private static function restore_backup_files( array $backup ) {
        $backup_id = sanitize_file_name( (string) ( $backup['backup_id'] ?? '' ) );
        if ( '' === $backup_id ) { return new WP_Error( 'alify_ai_backup_missing', 'Media backup reference is missing.', array( 'status' => 409 ) ); }
        $root = self::backup_root( false );
        $dir = trailingslashit( $root ) . $backup_id;
        if ( ! is_dir( $dir ) ) { return new WP_Error( 'alify_ai_backup_expired', 'The media file backup is no longer available.', array( 'status' => 410 ) ); }
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) { return new WP_Error( 'alify_ai_upload_dir_error', (string) $uploads['error'], array( 'status' => 500 ) ); }
        $base = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
        foreach ( (array) ( $backup['files'] ?? array() ) as $file ) {
            $relative = ltrim( wp_normalize_path( (string) ( $file['relative'] ?? '' ) ), '/' );
            if ( '' === $relative || str_contains( $relative, '..' ) ) { return new WP_Error( 'alify_ai_backup_path_invalid', 'Backup contains an unsafe file path.', array( 'status' => 500 ) ); }
            $source = trailingslashit( $dir ) . 'files/' . $relative;
            $target = $base . $relative;
            if ( ! file_exists( $source ) || ! wp_mkdir_p( dirname( $target ) ) || ! @copy( $source, $target ) ) {
                return new WP_Error( 'alify_ai_backup_restore_failed', 'Could not restore an attachment file from backup.', array( 'status' => 500 ) );
            }
        }
        return true;
    }

    private static function delete_backup( string $backup_id ): void {
        $backup_id = sanitize_file_name( $backup_id );
        if ( '' === $backup_id ) { return; }
        $root = self::backup_root( false );
        if ( $root ) { self::delete_directory( trailingslashit( $root ) . $backup_id ); }
    }

    private static function delete_directory( string $dir ): void {
        if ( ! is_dir( $dir ) ) { return; }
        $items = scandir( $dir );
        foreach ( $items ?: array() as $item ) {
            if ( '.' === $item || '..' === $item ) { continue; }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $path ) ) { self::delete_directory( $path ); } else { @unlink( $path ); }
        }
        @rmdir( $dir );
    }

    private static function remove_generated_sizes( int $id ): void {
        $main = get_attached_file( $id );
        $meta = wp_get_attachment_metadata( $id );
        if ( ! $main || ! is_array( $meta ) ) { return; }
        $dir = dirname( $main );
        foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
            if ( empty( $size['file'] ) ) { continue; }
            $path = trailingslashit( $dir ) . wp_basename( (string) $size['file'] );
            if ( file_exists( $path ) && wp_normalize_path( $path ) !== wp_normalize_path( $main ) ) { @unlink( $path ); }
        }
    }

    private static function regenerate_attachment_metadata_internal( int $id, bool $remove_old_sizes ) {
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) { return new WP_Error( 'alify_ai_media_file_missing', 'Attachment file is not available locally.', array( 'status' => 409 ) ); }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        if ( $remove_old_sizes ) { self::remove_generated_sizes( $id ); }
        try {
            $metadata = wp_generate_attachment_metadata( $id, $file );
            if ( ! is_array( $metadata ) ) { return new WP_Error( 'alify_ai_metadata_generation_failed', 'WordPress could not generate attachment metadata.', array( 'status' => 500 ) ); }
            if ( ! wp_update_attachment_metadata( $id, $metadata ) ) {
                // wp_update_attachment_metadata can return false when metadata is unchanged; verify actual stored data instead.
                $stored = wp_get_attachment_metadata( $id );
                if ( ! is_array( $stored ) ) { return new WP_Error( 'alify_ai_metadata_update_failed', 'WordPress could not store attachment metadata.', array( 'status' => 500 ) ); }
            }
        } catch ( Throwable $error ) {
            return new WP_Error( 'alify_ai_metadata_runtime_failure', 'WordPress failed while generating attachment metadata.', array( 'status' => 500 ) );
        }
        return true;
    }

    private static function replace_main_file_and_regenerate( int $id, string $source, array $before, bool $preserve_metadata ) {
        $target = get_attached_file( $id );
        if ( ! $target || ! file_exists( $target ) ) { return new WP_Error( 'alify_ai_media_file_missing', 'The current attachment file is not available locally.', array( 'status' => 409 ) ); }
        if ( ! @copy( $source, $target ) ) { return new WP_Error( 'alify_ai_media_replace_failed', 'Could not replace the attachment file.', array( 'status' => 500 ) ); }
        $check = wp_check_filetype_and_ext( $target, wp_basename( $target ), get_allowed_mime_types() );
        if ( empty( $check['type'] ) ) { self::restore_file_backup_and_snapshot( $id, $before ); return new WP_Error( 'alify_ai_media_replace_invalid', 'The replacement file does not match an allowed WordPress file type.', array( 'status' => 400 ) ); }
        $updated = wp_update_post( array( 'ID' => $id, 'post_mime_type' => $check['type'] ), true );
        if ( is_wp_error( $updated ) || ! $updated ) { self::restore_file_backup_and_snapshot( $id, $before ); return is_wp_error( $updated ) ? $updated : new WP_Error( 'alify_ai_media_replace_failed', 'Could not update attachment MIME type.', array( 'status' => 500 ) ); }
        $regen = self::regenerate_attachment_metadata_internal( $id, true );
        if ( is_wp_error( $regen ) ) { self::restore_file_backup_and_snapshot( $id, $before ); return $regen; }
        if ( $preserve_metadata ) { self::restore_descriptive_metadata_only( $before ); }
        return true;
    }

    private static function restore_descriptive_metadata_only( array $snapshot ): void {
        $id = absint( $snapshot['id'] ?? 0 );
        $post = is_array( $snapshot['post'] ?? null ) ? $snapshot['post'] : array();
        if ( ! $id || ! get_post( $id ) ) { return; }
        wp_update_post( array( 'ID' => $id, 'post_title' => (string) ( $post['post_title'] ?? '' ), 'post_excerpt' => (string) ( $post['post_excerpt'] ?? '' ), 'post_content' => (string) ( $post['post_content'] ?? '' ), 'post_parent' => absint( $post['post_parent'] ?? 0 ) ), true );
        $alt_values = (array) ( $snapshot['meta']['_wp_attachment_image_alt'] ?? array() );
        update_post_meta( $id, '_wp_attachment_image_alt', (string) ( $alt_values[0] ?? '' ) );
    }

    private static function restore_file_backup_and_snapshot( int $id, array $snapshot ) {
        if ( ! isset( $snapshot['file_backup'] ) || ! is_array( $snapshot['file_backup'] ) ) { return new WP_Error( 'alify_ai_backup_missing', 'Media rollback file backup is missing.', array( 'status' => 409 ) ); }
        if ( get_post( $id ) ) { self::remove_generated_sizes( $id ); }
        $files = self::restore_backup_files( $snapshot['file_backup'] );
        if ( is_wp_error( $files ) ) { return $files; }
        if ( get_post( $id ) ) { return self::restore_metadata_snapshot( $snapshot ); }
        return true;
    }

    private static function recreate_deleted_attachment( array $snapshot ) {
        $backup = $snapshot['file_backup'] ?? null;
        if ( ! is_array( $backup ) ) { return new WP_Error( 'alify_ai_backup_missing', 'Deleted media file backup is missing.', array( 'status' => 410 ) ); }
        $restored_files = self::restore_backup_files( $backup );
        if ( is_wp_error( $restored_files ) ) { return $restored_files; }
        $uploads = wp_upload_dir();
        $relative = (string) ( $snapshot['attached_file'] ?? '' );
        $absolute = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . ltrim( $relative, '/' ) );
        if ( '' === $relative || ! file_exists( $absolute ) ) { return new WP_Error( 'alify_ai_media_restore_file_missing', 'Restored attachment file could not be found.', array( 'status' => 500 ) ); }
        $post = is_array( $snapshot['post'] ?? null ) ? $snapshot['post'] : array();
        $attachment = array(
            'post_title' => (string) ( $post['post_title'] ?? wp_basename( $absolute ) ),
            'post_content' => (string) ( $post['post_content'] ?? '' ),
            'post_excerpt' => (string) ( $post['post_excerpt'] ?? '' ),
            'post_status' => 'inherit',
            'post_parent' => absint( $post['post_parent'] ?? 0 ),
            'post_mime_type' => (string) ( $post['post_mime_type'] ?? '' ),
            'menu_order' => (int) ( $post['menu_order'] ?? 0 ),
        );
        $new_id = wp_insert_attachment( $attachment, $absolute, $attachment['post_parent'], true );
        if ( is_wp_error( $new_id ) || ! $new_id ) { return is_wp_error( $new_id ) ? $new_id : new WP_Error( 'alify_ai_media_restore_failed', 'Could not recreate the deleted attachment.', array( 'status' => 500 ) ); }
        update_post_meta( $new_id, '_wp_attached_file', $relative );
        foreach ( (array) ( $snapshot['meta'] ?? array() ) as $key => $values ) {
            if ( '_wp_attached_file' === $key || '_wp_attachment_metadata' === $key ) { continue; }
            delete_post_meta( $new_id, $key );
            foreach ( (array) $values as $value ) { add_post_meta( $new_id, $key, $value ); }
        }
        if ( is_array( $snapshot['attachment_metadata'] ?? null ) ) { wp_update_attachment_metadata( $new_id, $snapshot['attachment_metadata'] ); }
        foreach ( (array) ( $snapshot['featured_in_ids'] ?? array() ) as $owner_id ) {
            $owner_id = absint( $owner_id );
            if ( $owner_id && get_post( $owner_id ) ) { set_post_thumbnail( $owner_id, $new_id ); }
        }
        $restored = self::get_media( $new_id );
        return is_wp_error( $restored ) ? $restored : $restored;
    }
}
