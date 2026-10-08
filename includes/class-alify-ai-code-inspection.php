<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read-only, sandboxed source-code and frontend inspection helpers.
 *
 * Security invariants:
 * - Source reads are limited to the active theme (including parent theme) and
 *   currently active plugins.
 * - Paths are resolved with realpath() and must stay inside an approved root.
 * - Secret/config/archive/binary files and dependency/cache directories are blocked.
 * - No arbitrary URL fetches: frontend inspection is restricted to this site's host.
 */
final class ALIFY_AI_Code_Inspection {
    private const MAX_FILE_BYTES   = 262144; // 256 KiB per source file.
    private const MAX_LIST_FILES   = 1200;
    private const MAX_SEARCH_FILES = 400;
    private const MAX_SEARCH_BYTES = 8388608; // 8 MiB total scan budget.
    private const MAX_MATCHES      = 100;
    private const MAX_HTML_BYTES   = 1048576; // 1 MiB returned HTML.

    private const ALLOWED_EXTENSIONS = array(
        'php', 'css', 'scss', 'sass', 'less', 'js', 'mjs', 'cjs', 'json',
        'html', 'htm', 'svg', 'xml', 'txt', 'md', 'yml', 'yaml',
    );

    private const BLOCKED_DIR_NAMES = array(
        '.git', '.svn', '.hg', 'node_modules', 'vendor', 'cache', '.cache',
    );

    private const BLOCKED_BASENAMES = array(
        '.env', '.env.local', '.env.production', '.env.development',
        'wp-config.php', 'wp-config-sample.php', 'auth.json', 'composer-auth.json',
        'id_rsa', 'id_dsa', 'id_ed25519',
    );

    private const BLOCKED_EXTENSIONS = array(
        'pem', 'key', 'p12', 'pfx', 'crt', 'cer', 'der', 'sql', 'sqlite', 'db',
        'log', 'bak', 'backup', 'zip', 'tar', 'gz', '7z', 'rar', 'phar',
    );

    public static function active_theme(): array {
        $theme = wp_get_theme();
        $roots = self::theme_roots();
        return array(
            'name'       => $theme->get( 'Name' ),
            'stylesheet' => $theme->get_stylesheet(),
            'template'   => $theme->get_template(),
            'version'    => $theme->get( 'Version' ),
            'child_theme'=> $theme->parent() ? true : false,
            'roots'      => array_keys( $roots ),
        );
    }

    public static function active_plugins(): array {
        $plugins = self::active_plugin_roots();
        $items   = array();
        foreach ( $plugins as $slug => $data ) {
            $items[] = array(
                'slug'     => $slug,
                'basename' => $data['basename'],
                'name'     => $data['name'],
                'version'  => $data['version'],
            );
        }
        return $items;
    }

    public static function list_files( string $target, string $plugin = '', string $path = '' ) {
        $root = self::resolve_root( $target, $plugin );
        if ( is_wp_error( $root ) ) {
            return $root;
        }
        $base = self::resolve_relative_path( $root['path'], $path, true );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        if ( ! is_dir( $base ) ) {
            return new WP_Error( 'alify_ai_code_not_directory', 'Requested source path is not a directory.', array( 'status' => 400 ) );
        }

        $files = array();
        $root_len = strlen( rtrim( $root['path'], DIRECTORY_SEPARATOR ) ) + 1;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ( $iterator as $file ) {
                if ( count( $files ) >= self::MAX_LIST_FILES ) {
                    break;
                }
                if ( ! $file->isFile() ) {
                    continue;
                }
                $absolute = $file->getRealPath();
                if ( ! is_string( $absolute ) || ! self::path_inside( $absolute, $root['path'] ) || self::path_blocked( $absolute, $root['path'] ) || ! self::extension_allowed( $absolute ) ) {
                    continue;
                }
                $files[] = array(
                    'path'  => str_replace( DIRECTORY_SEPARATOR, '/', substr( $absolute, $root_len ) ),
                    'bytes' => (int) $file->getSize(),
                    'mtime' => gmdate( 'c', (int) $file->getMTime() ),
                );
            }
        } catch ( Throwable $e ) {
            ALIFY_AI_Diagnostics::log( 'Code inspection file listing failed.', array( 'message' => $e->getMessage(), 'target' => $target ) );
            return new WP_Error( 'alify_ai_code_list_failed', 'Source file listing failed.', array( 'status' => 500 ) );
        }

        usort( $files, static fn( array $a, array $b ): int => strcmp( $a['path'], $b['path'] ) );
        return array(
            'target'    => $root['label'],
            'path'      => trim( str_replace( '\\', '/', $path ), '/' ),
            'files'     => $files,
            'count'     => count( $files ),
            'truncated' => count( $files ) >= self::MAX_LIST_FILES,
        );
    }

    public static function read_file( string $target, string $plugin, string $path ) {
        $root = self::resolve_root( $target, $plugin );
        if ( is_wp_error( $root ) ) {
            return $root;
        }
        $absolute = self::resolve_relative_path( $root['path'], $path, false );
        if ( is_wp_error( $absolute ) ) {
            return $absolute;
        }
        if ( ! is_file( $absolute ) ) {
            return new WP_Error( 'alify_ai_code_file_not_found', 'Source file not found.', array( 'status' => 404 ) );
        }
        if ( self::path_blocked( $absolute, $root['path'] ) || ! self::extension_allowed( $absolute ) ) {
            return new WP_Error( 'alify_ai_code_file_blocked', 'This file is outside the safe source-inspection allowlist.', array( 'status' => 403 ) );
        }
        $size = filesize( $absolute );
        if ( false === $size || $size > self::MAX_FILE_BYTES ) {
            return new WP_Error( 'alify_ai_code_file_too_large', 'Source file exceeds the 256 KiB read limit.', array( 'status' => 413, 'bytes' => (int) $size ) );
        }
        $contents = file_get_contents( $absolute );
        if ( false === $contents ) {
            return new WP_Error( 'alify_ai_code_read_failed', 'Source file could not be read.', array( 'status' => 500 ) );
        }
        if ( false !== strpos( $contents, "\0" ) ) {
            return new WP_Error( 'alify_ai_code_binary_blocked', 'Binary files are not exposed through source inspection.', array( 'status' => 415 ) );
        }
        return array(
            'target'   => $root['label'],
            'path'     => self::relative_to_root( $absolute, $root['path'] ),
            'bytes'    => strlen( $contents ),
            'sha256'   => hash( 'sha256', $contents ),
            'modified' => gmdate( 'c', (int) filemtime( $absolute ) ),
            'content'  => self::redact_source_secrets( $contents ),
        );
    }

    public static function search_code( string $target, string $plugin, string $query, string $path = '', int $max_matches = 50 ) {
        $query = trim( $query );
        if ( '' === $query || strlen( $query ) > 200 ) {
            return new WP_Error( 'alify_ai_code_invalid_query', 'Search query must be between 1 and 200 characters.', array( 'status' => 400 ) );
        }
        $max_matches = max( 1, min( self::MAX_MATCHES, $max_matches ) );
        $listed = self::list_files( $target, $plugin, $path );
        if ( is_wp_error( $listed ) ) {
            return $listed;
        }
        $root = self::resolve_root( $target, $plugin );
        if ( is_wp_error( $root ) ) {
            return $root;
        }

        $matches = array();
        $scanned_files = 0;
        $scanned_bytes = 0;
        foreach ( $listed['files'] as $item ) {
            if ( $scanned_files >= self::MAX_SEARCH_FILES || $scanned_bytes >= self::MAX_SEARCH_BYTES || count( $matches ) >= $max_matches ) {
                break;
            }
            $absolute = self::resolve_relative_path( $root['path'], (string) $item['path'], false );
            if ( is_wp_error( $absolute ) || ! is_file( $absolute ) ) {
                continue;
            }
            $size = (int) filesize( $absolute );
            if ( $size < 0 || $size > self::MAX_FILE_BYTES || $scanned_bytes + $size > self::MAX_SEARCH_BYTES ) {
                continue;
            }
            $content = file_get_contents( $absolute );
            if ( false === $content || false !== strpos( $content, "\0" ) ) {
                continue;
            }
            $scanned_files++;
            $scanned_bytes += strlen( $content );
            $lines = preg_split( '/\R/', $content );
            if ( ! is_array( $lines ) ) {
                continue;
            }
            foreach ( $lines as $line_number => $line ) {
                $position = stripos( $line, $query );
                if ( false === $position ) {
                    continue;
                }
                $matches[] = array(
                    'path'    => (string) $item['path'],
                    'line'    => $line_number + 1,
                    'excerpt' => self::redact_source_secrets( self::safe_excerpt( $line, $position, strlen( $query ) ) ),
                );
                if ( count( $matches ) >= $max_matches ) {
                    break 2;
                }
            }
        }
        return array(
            'target'        => $root['label'],
            'query'         => $query,
            'matches'       => $matches,
            'match_count'   => count( $matches ),
            'scanned_files' => $scanned_files,
            'scanned_bytes' => $scanned_bytes,
            'truncated'     => count( $matches ) >= $max_matches || $scanned_files >= self::MAX_SEARCH_FILES || $scanned_bytes >= self::MAX_SEARCH_BYTES,
        );
    }

    public static function inspect_frontend( string $path = '/', bool $include_html = true ) {
        $path = trim( $path );
        if ( '' === $path ) {
            $path = '/';
        }
        if ( ! str_starts_with( $path, '/' ) || str_starts_with( $path, '//' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $path ) ) {
            return new WP_Error( 'alify_ai_frontend_invalid_path', 'Frontend inspection accepts only a site-relative path beginning with /.', array( 'status' => 400 ) );
        }
        $path_only = (string) wp_parse_url( $path, PHP_URL_PATH );
        foreach ( array( '/wp-admin', '/wp-login.php', '/wp-json', '/xmlrpc.php' ) as $blocked_prefix ) {
            if ( str_starts_with( $path_only, $blocked_prefix ) ) {
                return new WP_Error( 'alify_ai_frontend_path_blocked', 'Frontend inspection does not fetch WordPress admin, login, REST API, or XML-RPC endpoints.', array( 'status' => 403 ) );
            }
        }

        $url = home_url( $path );
        $home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        $url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( '' === $home_host || ! hash_equals( $home_host, $url_host ) ) {
            return new WP_Error( 'alify_ai_frontend_host_blocked', 'Frontend inspection is restricted to this WordPress site.', array( 'status' => 403 ) );
        }

        $start = microtime( true );
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'     => 12,
                'redirection' => 3,
                'user-agent'  => 'ALIFY-AI-Connector/' . ALIFY_AI_VERSION . ' FrontendAudit',
                'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
            )
        );
        $elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
        if ( is_wp_error( $response ) ) {
            ALIFY_AI_Diagnostics::log( 'Frontend inspection request failed.', array( 'path' => $path, 'message' => $response->get_error_message() ) );
            return new WP_Error( 'alify_ai_frontend_fetch_failed', 'WordPress could not fetch its own frontend URL: ' . $response->get_error_message(), array( 'status' => 502 ) );
        }

        $status  = (int) wp_remote_retrieve_response_code( $response );
        $body    = (string) wp_remote_retrieve_body( $response );
        $headers = wp_remote_retrieve_headers( $response );
        $header_data = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
        $content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
        $truncated = strlen( $body ) > self::MAX_HTML_BYTES;
        if ( $truncated ) {
            $body = substr( $body, 0, self::MAX_HTML_BYTES );
        }

        $result = array(
            'url'            => esc_url_raw( $url ),
            'status'         => $status,
            'elapsed_ms'     => $elapsed_ms,
            'content_type'   => $content_type,
            'body_bytes'     => strlen( (string) wp_remote_retrieve_body( $response ) ),
            'html_truncated' => $truncated,
            'headers'        => self::safe_headers( $header_data ),
            'assets'         => self::extract_assets( $body, $url ),
            'document'       => self::document_summary( $body ),
        );
        if ( $include_html ) {
            $result['html'] = $body;
        }
        return $result;
    }

    /**
     * Build an approval-gated update for one existing allowlisted source file.
     */
    public static function preview_update( string $target, string $plugin, string $path, string $content, string $expected_sha256 = '' ) {
        $resolved = self::resolve_writable_file( $target, $plugin, $path );
        if ( is_wp_error( $resolved ) ) {
            return $resolved;
        }
        $absolute = $resolved['absolute'];
        $before = file_get_contents( $absolute );
        if ( false === $before ) {
            return new WP_Error( 'alify_ai_code_read_failed', 'Source file could not be read before preview.', array( 'status' => 500 ) );
        }
        $current_sha = hash( 'sha256', $before );
        $expected_sha256 = strtolower( trim( $expected_sha256 ) );
        if ( '' !== $expected_sha256 && ! hash_equals( $current_sha, $expected_sha256 ) ) {
            return new WP_Error( 'alify_ai_code_stale', 'The source file changed since it was inspected. Read it again before preparing an edit.', array( 'status' => 409, 'current_sha256' => $current_sha ) );
        }
        $validation = self::validate_source_content( $absolute, $content );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }
        if ( strlen( $content ) > self::MAX_FILE_BYTES ) {
            return new WP_Error( 'alify_ai_code_file_too_large', 'Updated source exceeds the 256 KiB write limit.', array( 'status' => 413 ) );
        }
        if ( hash_equals( $current_sha, hash( 'sha256', $content ) ) ) {
            return new WP_Error( 'alify_ai_code_no_change', 'The proposed source is identical to the current file.', array( 'status' => 400 ) );
        }
        $relative = self::relative_to_root( $absolute, $resolved['root']['path'] );
        $request = array(
            'target' => $target,
            'plugin' => $plugin,
            'path' => $relative,
            'content' => $content,
            'source_sha256' => $current_sha,
        );
        $preview = array(
            'target' => $resolved['root']['label'],
            'path' => $relative,
            'before_sha256' => $current_sha,
            'after_sha256' => hash( 'sha256', $content ),
            'before_bytes' => strlen( $before ),
            'after_bytes' => strlen( $content ),
            'php_syntax_checked' => 'php' === strtolower( pathinfo( $absolute, PATHINFO_EXTENSION ) ),
            'approval_required' => true,
        );
        return ALIFY_AI_Approvals::create( 'code', 0, $request, $preview );
    }

    public static function apply_approval( array $approval ) {
        $request = isset( $approval['request'] ) && is_array( $approval['request'] ) ? $approval['request'] : array();
        $target = sanitize_key( (string) ( $request['target'] ?? '' ) );
        $plugin = sanitize_key( (string) ( $request['plugin'] ?? '' ) );
        $path = (string) ( $request['path'] ?? '' );
        $content = (string) ( $request['content'] ?? '' );
        $source_sha = strtolower( (string) ( $request['source_sha256'] ?? '' ) );
        $resolved = self::resolve_writable_file( $target, $plugin, $path );
        if ( is_wp_error( $resolved ) ) return $resolved;
        $absolute = $resolved['absolute'];
        $before = file_get_contents( $absolute );
        if ( false === $before ) return new WP_Error( 'alify_ai_code_read_failed', 'Source file could not be read before apply.', array( 'status' => 500 ) );
        $current_sha = hash( 'sha256', $before );
        if ( '' === $source_sha || ! hash_equals( $source_sha, $current_sha ) ) {
            return new WP_Error( 'alify_ai_code_stale', 'The source file changed after preview. Create a fresh proposal.', array( 'status' => 409, 'current_sha256' => $current_sha ) );
        }
        $validation = self::validate_source_content( $absolute, $content );
        if ( is_wp_error( $validation ) ) return $validation;
        $written = self::atomic_write( $absolute, $content );
        if ( is_wp_error( $written ) ) return $written;
        $verify = file_get_contents( $absolute );
        if ( false === $verify || ! hash_equals( hash( 'sha256', $content ), hash( 'sha256', $verify ) ) ) {
            self::atomic_write( $absolute, $before );
            return new WP_Error( 'alify_ai_code_write_verify_failed', 'Source write could not be verified; the previous file was restored.', array( 'status' => 500 ) );
        }
        $relative = self::relative_to_root( $absolute, $resolved['root']['path'] );
        $before_snapshot = array( 'target'=>$target, 'plugin'=>$plugin, 'path'=>$relative, 'sha256'=>$current_sha, 'content'=>$before );
        $after_snapshot = array( 'target'=>$target, 'plugin'=>$plugin, 'path'=>$relative, 'sha256'=>hash( 'sha256', $content ), 'content'=>$content );
        $activity_id = ALIFY_AI_Audit::log( 'update_source_file', 'code_file', 0, $before_snapshot, $after_snapshot );
        if ( ! $activity_id ) {
            $restored = self::atomic_write( $absolute, $before );
            if ( is_wp_error( $restored ) ) {
                ALIFY_AI_Diagnostics::log( 'code_write_compensation_failed', array( 'path'=>$relative, 'error'=>$restored->get_error_message() ) );
                return new WP_Error( 'alify_ai_code_unknown_state', 'The source file changed, activity logging failed, and automatic restore also failed. Site state requires review.', array( 'status'=>500 ) );
            }
            return new WP_Error( 'alify_ai_audit_failed', 'The source edit was reverted because its activity log could not be stored.', array( 'status'=>500 ) );
        }
        if ( function_exists( 'wp_clean_themes_cache' ) ) wp_clean_themes_cache( true );
        if ( function_exists( 'wp_clean_plugins_cache' ) ) wp_clean_plugins_cache( true );
        return array( 'message'=>'Source file updated.', 'activity_id'=>$activity_id, 'target'=>$resolved['root']['label'], 'path'=>$relative, 'sha256'=>hash( 'sha256', $content ) );
    }

    public static function rollback_activity( array $entry ) {
        if ( 'update_source_file' !== (string) ( $entry['action'] ?? '' ) || ! is_array( $entry['before_json'] ?? null ) || ! is_array( $entry['after_json'] ?? null ) ) {
            return new WP_Error( 'alify_ai_code_rollback_unsupported', 'This code activity cannot be rolled back.', array( 'status'=>400 ) );
        }
        $before = $entry['before_json'];
        $after = $entry['after_json'];
        $target = sanitize_key( (string) ( $before['target'] ?? '' ) );
        $plugin = sanitize_key( (string) ( $before['plugin'] ?? '' ) );
        $path = (string) ( $before['path'] ?? '' );
        $resolved = self::resolve_writable_file( $target, $plugin, $path );
        if ( is_wp_error( $resolved ) ) return $resolved;
        $absolute = $resolved['absolute'];
        $current = file_get_contents( $absolute );
        if ( false === $current ) return new WP_Error( 'alify_ai_code_read_failed', 'Source file could not be read before rollback.', array( 'status'=>500 ) );
        $after_sha = strtolower( (string) ( $after['sha256'] ?? '' ) );
        if ( '' === $after_sha || ! hash_equals( $after_sha, hash( 'sha256', $current ) ) ) {
            return new WP_Error( 'alify_ai_code_rollback_conflict', 'The source file changed after this activity; rollback would overwrite newer work.', array( 'status'=>409 ) );
        }
        $restore = (string) ( $before['content'] ?? '' );
        $validation = self::validate_source_content( $absolute, $restore );
        if ( is_wp_error( $validation ) ) return $validation;
        $written = self::atomic_write( $absolute, $restore );
        if ( is_wp_error( $written ) ) return $written;
        $activity_id = ALIFY_AI_Audit::log( 'rollback_update_source_file', 'code_file', 0, $after, $before );
        if ( ! $activity_id ) {
            $comp = self::atomic_write( $absolute, $current );
            if ( is_wp_error( $comp ) ) return new WP_Error( 'alify_ai_code_unknown_state', 'Code rollback logging failed and the pre-rollback file could not be restored.', array( 'status'=>500 ) );
            return new WP_Error( 'alify_ai_audit_failed', 'Code rollback was reverted because its activity log could not be stored.', array( 'status'=>500 ) );
        }
        return array( 'message'=>'Source edit rolled back.', 'activity_id'=>$activity_id, 'path'=>$path, 'sha256'=>hash( 'sha256', $restore ) );
    }

    private static function resolve_writable_file( string $target, string $plugin, string $path ) {
        $root = self::resolve_root( $target, $plugin );
        if ( is_wp_error( $root ) ) return $root;
        $absolute = self::resolve_relative_path( $root['path'], $path, false );
        if ( is_wp_error( $absolute ) ) return $absolute;
        if ( ! is_file( $absolute ) || self::path_blocked( $absolute, $root['path'] ) || ! self::extension_allowed( $absolute ) ) {
            return new WP_Error( 'alify_ai_code_file_blocked', 'This file is outside the safe source-edit allowlist.', array( 'status'=>403 ) );
        }
        if ( ! is_writable( $absolute ) || ! is_writable( dirname( $absolute ) ) ) {
            return new WP_Error( 'alify_ai_code_not_writable', 'The source file or its directory is not writable by WordPress.', array( 'status'=>409 ) );
        }
        return array( 'root'=>$root, 'absolute'=>$absolute );
    }

    private static function validate_source_content( string $absolute, string $content ) {
        if ( false !== strpos( $content, "\0" ) ) return new WP_Error( 'alify_ai_code_binary_blocked', 'Binary source content is not allowed.', array( 'status'=>415 ) );
        if ( strlen( $content ) > self::MAX_FILE_BYTES ) return new WP_Error( 'alify_ai_code_file_too_large', 'Updated source exceeds the 256 KiB write limit.', array( 'status'=>413 ) );
        $ext = strtolower( pathinfo( $absolute, PATHINFO_EXTENSION ) );
        if ( 'php' === $ext ) {
            try {
                token_get_all( $content, defined( 'TOKEN_PARSE' ) ? TOKEN_PARSE : 0 );
            } catch ( ParseError $e ) {
                return new WP_Error( 'alify_ai_code_php_syntax_error', 'PHP syntax validation failed: ' . $e->getMessage(), array( 'status'=>400 ) );
            } catch ( Throwable $e ) {
                return new WP_Error( 'alify_ai_code_php_validation_failed', 'PHP syntax validation could not complete.', array( 'status'=>500 ) );
            }
        }
        return true;
    }

    private static function atomic_write( string $absolute, string $content ) {
        $dir = dirname( $absolute );
        $tmp = tempnam( $dir, '.alify-ai-' );
        if ( false === $tmp ) return new WP_Error( 'alify_ai_code_temp_failed', 'Could not create a temporary file beside the source file.', array( 'status'=>500 ) );
        $mode = @fileperms( $absolute );
        $bytes = @file_put_contents( $tmp, $content, LOCK_EX );
        if ( false === $bytes || $bytes !== strlen( $content ) ) { @unlink( $tmp ); return new WP_Error( 'alify_ai_code_write_failed', 'Could not write the complete temporary source file.', array( 'status'=>500 ) ); }
        if ( $mode ) @chmod( $tmp, $mode & 0777 );
        $renamed = @rename( $tmp, $absolute );
        if ( ! $renamed ) {
            $copied = @file_put_contents( $absolute, $content, LOCK_EX );
            @unlink( $tmp );
            if ( false === $copied || $copied !== strlen( $content ) ) return new WP_Error( 'alify_ai_code_write_failed', 'Could not replace the source file.', array( 'status'=>500 ) );
        }
        clearstatcache( true, $absolute );
        return true;
    }

    private static function resolve_root( string $target, string $plugin ) {
        $target = sanitize_key( $target );
        if ( 'theme' === $target ) {
            $roots = self::theme_roots();
            // Prefer child/active stylesheet root. Parent can be addressed using target=parent_theme.
            $first = reset( $roots );
            return $first ?: new WP_Error( 'alify_ai_code_theme_missing', 'Active theme root is unavailable.', array( 'status' => 404 ) );
        }
        if ( 'parent_theme' === $target ) {
            $roots = self::theme_roots();
            foreach ( $roots as $key => $root ) {
                if ( 'parent_theme' === $key ) {
                    return $root;
                }
            }
            return new WP_Error( 'alify_ai_code_parent_theme_missing', 'The active theme does not have a separate parent theme.', array( 'status' => 404 ) );
        }
        if ( 'plugin' === $target ) {
            $plugins = self::active_plugin_roots();
            $plugin = sanitize_key( $plugin );
            if ( '' === $plugin || ! isset( $plugins[ $plugin ] ) ) {
                return new WP_Error( 'alify_ai_code_plugin_not_allowed', 'Choose a currently active plugin from list_active_plugins.', array( 'status' => 403 ) );
            }
            return array(
                'path'  => $plugins[ $plugin ]['path'],
                'label' => 'plugin:' . $plugin,
            );
        }
        return new WP_Error( 'alify_ai_code_invalid_target', 'target must be theme, parent_theme, or plugin.', array( 'status' => 400 ) );
    }

    private static function theme_roots(): array {
        $theme = wp_get_theme();
        $roots = array();
        $stylesheet_dir = realpath( get_stylesheet_directory() );
        if ( is_string( $stylesheet_dir ) ) {
            $roots['theme'] = array( 'path' => $stylesheet_dir, 'label' => 'theme:' . $theme->get_stylesheet() );
        }
        $template_dir = realpath( get_template_directory() );
        if ( is_string( $template_dir ) && $template_dir !== $stylesheet_dir ) {
            $roots['parent_theme'] = array( 'path' => $template_dir, 'label' => 'parent_theme:' . $theme->get_template() );
        }
        return $roots;
    }

    private static function active_plugin_roots(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = function_exists( 'get_plugins' ) ? get_plugins() : array();
        $active = (array) get_option( 'active_plugins', array() );
        if ( is_multisite() ) {
            $active = array_values( array_unique( array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) ) );
        }
        $roots = array();
        foreach ( $active as $basename ) {
            $basename = plugin_basename( (string) $basename );
            if ( ! isset( $all[ $basename ] ) ) {
                continue;
            }
            // A plugin installed as one PHP file directly in wp-content/plugins
            // does not get the entire shared plugins directory as a readable root.
            // Skip it rather than widening the sandbox to sibling plugins.
            if ( '.' === dirname( $basename ) ) {
                continue;
            }
            $absolute_plugin_file = realpath( WP_PLUGIN_DIR . '/' . $basename );
            if ( ! is_string( $absolute_plugin_file ) ) {
                continue;
            }
            $dir = realpath( dirname( $absolute_plugin_file ) );
            if ( ! is_string( $dir ) || ! self::path_inside( $dir, realpath( WP_PLUGIN_DIR ) ?: WP_PLUGIN_DIR ) ) {
                continue;
            }
            $slug = sanitize_key( dirname( $basename ) === '.' ? pathinfo( $basename, PATHINFO_FILENAME ) : dirname( $basename ) );
            $roots[ $slug ] = array(
                'path'     => $dir,
                'basename' => $basename,
                'name'     => (string) ( $all[ $basename ]['Name'] ?? $slug ),
                'version'  => (string) ( $all[ $basename ]['Version'] ?? '' ),
            );
        }
        ksort( $roots );
        return $roots;
    }

    private static function resolve_relative_path( string $root, string $relative, bool $allow_directory ) {
        $relative = ltrim( str_replace( '\\', '/', trim( $relative ) ), '/' );
        if ( str_contains( $relative, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $relative ) ) {
            return new WP_Error( 'alify_ai_code_path_traversal', 'Path traversal is not allowed.', array( 'status' => 400 ) );
        }
        $candidate = '' === $relative ? $root : $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
        $real = realpath( $candidate );
        if ( false === $real ) {
            return new WP_Error( 'alify_ai_code_path_not_found', 'Requested source path does not exist.', array( 'status' => 404 ) );
        }
        if ( ! self::path_inside( $real, $root ) ) {
            return new WP_Error( 'alify_ai_code_path_blocked', 'Requested source path escapes the approved source root.', array( 'status' => 403 ) );
        }
        if ( ! $allow_directory && is_dir( $real ) ) {
            return new WP_Error( 'alify_ai_code_expected_file', 'A source file path is required.', array( 'status' => 400 ) );
        }
        return $real;
    }

    private static function path_inside( string $path, string $root ): bool {
        $path = rtrim( str_replace( '\\', '/', $path ), '/' );
        $root = rtrim( str_replace( '\\', '/', $root ), '/' );
        return $path === $root || str_starts_with( $path . '/', $root . '/' );
    }

    private static function path_blocked( string $absolute, string $root ): bool {
        $relative = self::relative_to_root( $absolute, $root );
        $parts = array_filter( explode( '/', strtolower( $relative ) ), static fn( $v ) => '' !== $v );
        foreach ( $parts as $part ) {
            if ( in_array( $part, self::BLOCKED_DIR_NAMES, true ) ) {
                return true;
            }
        }
        $base = strtolower( basename( $absolute ) );
        if ( str_starts_with( $base, '.env' ) || in_array( $base, self::BLOCKED_BASENAMES, true ) ) {
            return true;
        }
        $ext = strtolower( pathinfo( $base, PATHINFO_EXTENSION ) );
        return in_array( $ext, self::BLOCKED_EXTENSIONS, true );
    }

    private static function extension_allowed( string $absolute ): bool {
        $ext = strtolower( pathinfo( $absolute, PATHINFO_EXTENSION ) );
        return in_array( $ext, self::ALLOWED_EXTENSIONS, true );
    }

    private static function relative_to_root( string $absolute, string $root ): string {
        $absolute = str_replace( '\\', '/', $absolute );
        $root = rtrim( str_replace( '\\', '/', $root ), '/' );
        return ltrim( substr( $absolute, strlen( $root ) ), '/' );
    }

    /**
     * Redact common hard-coded credential values without pretending to be a
     * complete secret scanner. The source path itself remains available for a
     * developer to review locally when a value is redacted.
     */
    private static function redact_source_secrets( string $content ): string {
        $patterns = array(
            '/\b(authorization\s*[:=]\s*["\']?bearer\s+)[A-Za-z0-9._~+\/-]{12,}/i' => '$1[REDACTED]',
            '/\b(api[_-]?key|api[_-]?secret|client[_-]?secret|access[_-]?token|refresh[_-]?token|password|passwd)\b(\s*[=:>]\s*["\'])([^"\'\r\n]{6,})(["\'])/i' => '$1$2[REDACTED]$4',
            '#(https?://[^\s:/]+:)[^@\s/]+@#i' => '$1[REDACTED]@',
            '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----.*?-----END (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/s' => '[REDACTED PRIVATE KEY]',
        );
        foreach ( $patterns as $pattern => $replacement ) {
            $next = preg_replace( $pattern, $replacement, $content );
            if ( is_string( $next ) ) {
                $content = $next;
            }
        }
        return $content;
    }

    private static function safe_excerpt( string $line, int $position, int $needle_length ): string {
        $start = max( 0, $position - 120 );
        $length = min( strlen( $line ) - $start, $needle_length + 240 );
        $excerpt = substr( $line, $start, $length );
        return trim( preg_replace( '/\s+/', ' ', $excerpt ) ?: '' );
    }

    private static function safe_headers( array $headers ): array {
        $safe_names = array( 'content-type', 'content-length', 'cache-control', 'etag', 'last-modified', 'location', 'content-encoding', 'x-robots-tag', 'link', 'server-timing' );
        $safe = array();
        foreach ( $headers as $name => $value ) {
            $key = strtolower( (string) $name );
            if ( in_array( $key, $safe_names, true ) ) {
                $safe[ $key ] = is_array( $value ) ? array_map( 'strval', $value ) : (string) $value;
            }
        }
        return $safe;
    }

    private static function extract_assets( string $html, string $base_url ): array {
        $assets = array( 'stylesheets' => array(), 'scripts' => array(), 'images' => array() );
        if ( preg_match_all( '/<link\b[^>]*\brel=["\'][^"\']*stylesheet[^"\']*["\'][^>]*\bhref=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $assets['stylesheets'] = self::unique_asset_urls( $m[1], $base_url, 100 );
        }
        if ( preg_match_all( '/<script\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $assets['scripts'] = self::unique_asset_urls( $m[1], $base_url, 100 );
        }
        if ( preg_match_all( '/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $assets['images'] = self::unique_asset_urls( $m[1], $base_url, 100 );
        }
        return $assets;
    }

    private static function unique_asset_urls( array $values, string $base_url, int $limit ): array {
        $out = array();
        foreach ( $values as $value ) {
            $value = html_entity_decode( trim( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            if ( '' === $value || str_starts_with( $value, 'data:' ) ) {
                continue;
            }
            if ( str_starts_with( $value, '//' ) ) {
                $scheme = wp_parse_url( $base_url, PHP_URL_SCHEME ) ?: 'https';
                $value = $scheme . ':' . $value;
            } elseif ( str_starts_with( $value, '/' ) ) {
                $parts = wp_parse_url( $base_url );
                $value = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . $value;
            } elseif ( ! preg_match( '#^https?://#i', $value ) ) {
                $value = trailingslashit( dirname( $base_url ) ) . ltrim( $value, '/' );
            }
            $out[] = esc_url_raw( $value );
            if ( count( $out ) >= $limit ) {
                break;
            }
        }
        return array_values( array_unique( array_filter( $out ) ) );
    }

    private static function document_summary( string $html ): array {
        $title = '';
        if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
            $title = trim( wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
        }
        $h1_count = preg_match_all( '/<h1\b/i', $html ) ?: 0;
        $canonical = '';
        if ( preg_match( '/<link\b[^>]*\brel=["\']canonical["\'][^>]*\bhref=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $canonical = esc_url_raw( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        }
        return array(
            'title'         => $title,
            'h1_count'      => (int) $h1_count,
            'canonical'     => $canonical,
            'has_viewport'  => (bool) preg_match( '/<meta\b[^>]*\bname=["\']viewport["\']/i', $html ),
            'has_lang_attr' => (bool) preg_match( '/<html\b[^>]*\blang=["\'][^"\']+["\']/i', $html ),
        );
    }
}
