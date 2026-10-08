<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SEO adapter for generic WordPress output, Yoast SEO and Rank Math.
 *
 * Writes are intentionally limited to stable, documented/common metadata.
 * Generic JSON-LD is only emitted by ALIFY when neither Yoast nor Rank Math
 * owns the target, avoiding duplicate SEO output.
 */
final class ALIFY_AI_SEO {
    private const MAX_AUDIT_ITEMS = 250;
    private const MAX_TITLE = 1000;
    private const MAX_DESCRIPTION = 5000;
    private const MAX_KEYWORD = 500;
    private const MAX_SCHEMA_BYTES = 65536;

    private const GENERIC_KEYS = array(
        'title'               => '_alify_seo_title',
        'description'         => '_alify_seo_description',
        'canonical'           => '_alify_seo_canonical',
        'focus_keyword'       => '_alify_seo_focus_keyword',
        'robots'              => '_alify_seo_robots',
        'open_graph_title'    => '_alify_seo_og_title',
        'open_graph_description' => '_alify_seo_og_description',
        'open_graph_image'    => '_alify_seo_og_image',
        'open_graph_image_id' => '_alify_seo_og_image_id',
        'twitter_title'       => '_alify_seo_twitter_title',
        'twitter_description' => '_alify_seo_twitter_description',
        'twitter_image'       => '_alify_seo_twitter_image',
        'twitter_image_id'    => '_alify_seo_twitter_image_id',
        'twitter_card'        => '_alify_seo_twitter_card',
        'schema_json'         => '_alify_seo_schema_json',
        'schema_page_type'    => '_alify_seo_schema_page_type',
        'schema_article_type' => '_alify_seo_schema_article_type',
    );

    private const RANK_MATH_KEYS = array(
        'title'               => 'rank_math_title',
        'description'         => 'rank_math_description',
        'canonical'           => 'rank_math_canonical_url',
        'focus_keyword'       => 'rank_math_focus_keyword',
        'robots'              => 'rank_math_robots',
        'open_graph_title'    => 'rank_math_facebook_title',
        'open_graph_description' => 'rank_math_facebook_description',
        'open_graph_image'    => 'rank_math_facebook_image',
        'open_graph_image_id' => 'rank_math_facebook_image_id',
        'twitter_title'       => 'rank_math_twitter_title',
        'twitter_description' => 'rank_math_twitter_description',
        'twitter_image'       => 'rank_math_twitter_image',
        'twitter_image_id'    => 'rank_math_twitter_image_id',
        'twitter_card'        => 'rank_math_twitter_card_type',
        'schema_page_type'    => 'rank_math_schema_type',
    );

    private const YOAST_KEYS = array(
        'title'               => '_yoast_wpseo_title',
        'description'         => '_yoast_wpseo_metadesc',
        'canonical'           => '_yoast_wpseo_canonical',
        'focus_keyword'       => '_yoast_wpseo_focuskw',
        'open_graph_title'    => '_yoast_wpseo_opengraph-title',
        'open_graph_description' => '_yoast_wpseo_opengraph-description',
        'open_graph_image'    => '_yoast_wpseo_opengraph-image',
        'open_graph_image_id' => '_yoast_wpseo_opengraph-image-id',
        'twitter_title'       => '_yoast_wpseo_twitter-title',
        'twitter_description' => '_yoast_wpseo_twitter-description',
        'twitter_image'       => '_yoast_wpseo_twitter-image',
        'twitter_image_id'    => '_yoast_wpseo_twitter-image-id',
        'schema_page_type'    => '_yoast_wpseo_schema_page_type',
        'schema_article_type' => '_yoast_wpseo_schema_article_type',
    );

    public static function init(): void {
        add_filter( 'pre_get_document_title', array( __CLASS__, 'generic_document_title' ), 99 );
        add_filter( 'wp_robots', array( __CLASS__, 'generic_robots' ), 99 );
        add_action( 'wp_head', array( __CLASS__, 'generic_head' ), 1 );
    }

    public static function status(): array {
        $yoast = self::yoast_active();
        $rank  = self::rank_math_active();
        return array(
            'active'                => true,
            'yoast'                 => $yoast,
            'rank_math'             => $rank,
            'generic'               => true,
            'provider_conflict'     => $yoast && $rank,
            'preferred_provider'    => ( $yoast && $rank ) ? 'conflict' : self::auto_provider(),
            'post_meta'             => true,
            'canonical'             => true,
            'robots'                => true,
            'open_graph'            => true,
            'twitter_cards'         => true,
            'focus_keyword'         => true,
            'schema'                => array(
                'generic_json_ld' => true,
                'yoast_types'     => $yoast,
                'rank_math_type'  => $rank,
                'rank_math_custom_schema_write' => false,
            ),
            'bulk_audit'            => true,
            'writes'                => 'preview_then_approval',
        );
    }

    public static function get_post( int $post_id, string $provider = 'auto' ) {
        $post = get_post( $post_id );
        if ( ! $post || 'revision' === $post->post_type || 'attachment' === $post->post_type ) {
            return new WP_Error( 'alify_ai_seo_post_not_found', 'SEO target post was not found.', array( 'status' => 404 ) );
        }
        $provider = self::resolve_provider( $provider );
        if ( is_wp_error( $provider ) ) return $provider;
        $fields = self::read_fields( $post_id, $provider );
        return array(
            'id'         => $post_id,
            'post_type'  => $post->post_type,
            'post_status'=> $post->post_status,
            'post_title' => get_the_title( $post_id ),
            'permalink'  => get_permalink( $post_id ),
            'provider'   => $provider,
            'fields'     => $fields,
            'audit'      => self::audit_fields( $post, $fields ),
            'source_hash'=> self::hash_fields( $provider, $fields ),
        );
    }

    public static function preview_post_update( int $post_id, array $input ) {
        $provider = self::resolve_provider( (string) ( $input['provider'] ?? 'auto' ) );
        if ( is_wp_error( $provider ) ) return $provider;
        $current = self::get_post( $post_id, $provider );
        if ( is_wp_error( $current ) ) return $current;
        $changes = self::sanitize_fields( (array) ( $input['fields'] ?? $input ), $provider );
        if ( is_wp_error( $changes ) ) return $changes;
        if ( empty( $changes ) ) return new WP_Error( 'alify_ai_no_changes', 'No supported SEO fields were supplied.', array( 'status' => 400 ) );
        if ( isset( $changes['robots'] ) ) { $changes['robots'] = array_replace( (array) $current['fields']['robots'], (array) $changes['robots'] ); }
        $after = array_replace( $current['fields'], $changes );
        $preview = array(
            'action'      => 'update_post_seo',
            'provider'    => $provider,
            'before'      => $current['fields'],
            'proposed'    => $after,
            'changes'     => $changes,
            'source_hash' => $current['source_hash'],
            'audit_before'=> $current['audit'],
            'audit_after' => self::audit_fields( get_post( $post_id ), $after ),
        );
        $snapshot_ok = ALIFY_AI_Audit::validate_snapshot( $current['fields'], $after );
        if ( is_wp_error( $snapshot_ok ) ) return $snapshot_ok;
        return ALIFY_AI_Approvals::create(
            'seo',
            $post_id,
            array( 'action' => 'post.update', 'post_id' => $post_id, 'provider' => $provider, 'fields' => $changes, 'base_hash' => $current['source_hash'] ),
            $preview
        );
    }

    public static function apply_approval( array $approval ) {
        $request = (array) ( $approval['request'] ?? array() );
        if ( 'post.update' !== (string) ( $request['action'] ?? '' ) ) {
            return new WP_Error( 'alify_ai_invalid_seo_action', 'Unsupported SEO approval action.', array( 'status' => 400 ) );
        }
        $post_id  = absint( $request['post_id'] ?? $approval['object_id'] ?? 0 );
        $provider = self::resolve_provider( (string) ( $request['provider'] ?? 'auto' ) );
        if ( is_wp_error( $provider ) ) return $provider;
        $current = self::get_post( $post_id, $provider );
        if ( is_wp_error( $current ) ) return $current;
        if ( ! hash_equals( (string) ( $request['base_hash'] ?? '' ), (string) $current['source_hash'] ) ) {
            return new WP_Error( 'alify_ai_stale_proposal', 'SEO metadata changed after this preview was created. Create a fresh proposal.', array( 'status' => 409 ) );
        }
        $changes = self::sanitize_fields( (array) ( $request['fields'] ?? array() ), $provider );
        if ( is_wp_error( $changes ) ) return $changes;
        $before = $current['fields'];
        $write = self::write_fields( $post_id, $provider, $changes );
        if ( is_wp_error( $write ) ) return $write;
        $after = self::read_fields( $post_id, $provider );
        $expected = array_replace( $before, $changes );
        if ( ! self::fields_match( $expected, $after, array_keys( $changes ) ) ) {
            $restore = self::write_fields( $post_id, $provider, $before, true );
            $restored_fields = self::read_fields( $post_id, $provider );
            if ( is_wp_error( $restore ) || ! self::fields_match( $before, $restored_fields, array_keys( $before ) ) ) {
                ALIFY_AI_Diagnostics::log( 'seo_compensation_failed', array( 'post_id' => $post_id, 'provider' => $provider, 'stage' => 'write_verify' ) );
                return new WP_Error( 'alify_ai_seo_unknown_state', 'SEO metadata verification failed and the original snapshot could not be verified as restored; site state requires review.', array( 'status' => 500 ) );
            }
            return new WP_Error( 'alify_ai_seo_write_verify_failed', 'SEO metadata verification failed; the original snapshot was restored.', array( 'status' => 500 ) );
        }
        $activity_id = ALIFY_AI_Audit::log( 'update_seo', 'seo', $post_id, array( 'provider' => $provider, 'fields' => $before ), array( 'provider' => $provider, 'fields' => $after ) );
        if ( ! $activity_id ) {
            $restore = self::write_fields( $post_id, $provider, $before, true );
            $restored_fields = self::read_fields( $post_id, $provider );
            if ( is_wp_error( $restore ) || ! self::fields_match( $before, $restored_fields, array_keys( $before ) ) ) {
                ALIFY_AI_Diagnostics::log( 'seo_compensation_failed', array( 'post_id' => $post_id, 'provider' => $provider, 'stage' => 'audit_failure' ) );
                return new WP_Error( 'alify_ai_seo_unknown_state', 'SEO activity logging failed and the original metadata could not be verified as restored; site state requires review.', array( 'status' => 500 ) );
            }
            return new WP_Error( 'alify_ai_audit_failed', 'SEO change was reverted because the activity log could not be stored.', array( 'status' => 500 ) );
        }
        $result = self::get_post( $post_id, $provider );
        return array( 'message' => 'SEO metadata updated.', 'activity_id' => $activity_id, 'seo' => $result );
    }

    public static function rollback_activity( array $entry ) {
        if ( 'update_seo' !== (string) ( $entry['action'] ?? '' ) ) {
            return new WP_Error( 'alify_ai_seo_rollback_unsupported', 'This SEO activity cannot be rolled back.', array( 'status' => 400 ) );
        }
        $post_id = absint( $entry['object_id'] ?? 0 );
        $before  = (array) ( $entry['before_json'] ?? array() );
        $provider = self::resolve_provider( (string) ( $before['provider'] ?? 'auto' ) );
        if ( is_wp_error( $provider ) ) return $provider;
        $current = self::read_fields( $post_id, $provider );
        $target  = (array) ( $before['fields'] ?? array() );
        $write = self::write_fields( $post_id, $provider, $target, true );
        if ( is_wp_error( $write ) ) return $write;
        $after = self::read_fields( $post_id, $provider );
        if ( ! self::fields_match( $target, $after, array_keys( $target ) ) ) {
            $compensated = self::write_fields( $post_id, $provider, $current, true );
            $compensated_fields = self::read_fields( $post_id, $provider );
            if ( is_wp_error( $compensated ) || ! self::fields_match( $current, $compensated_fields, array_keys( $current ) ) ) {
                ALIFY_AI_Diagnostics::log( 'seo_rollback_compensation_failed', array( 'post_id' => $post_id, 'provider' => $provider, 'stage' => 'target_verify' ) );
                return new WP_Error( 'alify_ai_seo_unknown_state', 'SEO rollback verification failed and the current metadata could not be restored; site state requires review.', array( 'status' => 500 ) );
            }
            return new WP_Error( 'alify_ai_seo_rollback_verify_failed', 'SEO rollback verification failed; the pre-rollback metadata was restored.', array( 'status' => 500 ) );
        }
        $log_id = ALIFY_AI_Audit::log( 'rollback_update_seo', 'seo', $post_id, array( 'provider' => $provider, 'fields' => $current ), array( 'provider' => $provider, 'fields' => $after ) );
        if ( ! $log_id ) {
            $restore = self::write_fields( $post_id, $provider, $current, true );
            $restored_fields = self::read_fields( $post_id, $provider );
            if ( is_wp_error( $restore ) || ! self::fields_match( $current, $restored_fields, array_keys( $current ) ) ) {
                ALIFY_AI_Diagnostics::log( 'seo_rollback_compensation_failed', array( 'post_id' => $post_id, 'provider' => $provider, 'stage' => 'audit_failure' ) );
                return new WP_Error( 'alify_ai_seo_unknown_state', 'SEO rollback logging failed and the pre-rollback metadata could not be verified as restored; site state requires review.', array( 'status' => 500 ) );
            }
            return new WP_Error( 'alify_ai_audit_failed', 'SEO rollback was reverted because its activity log could not be stored.', array( 'status' => 500 ) );
        }
        return array( 'message' => 'SEO metadata rolled back.', 'activity_id' => $log_id, 'seo' => self::get_post( $post_id, $provider ) );
    }

    public static function audit( array $input = array() ) {
        $provider = self::resolve_provider( (string) ( $input['provider'] ?? 'auto' ) );
        if ( is_wp_error( $provider ) ) return $provider;
        $post_type = sanitize_key( (string) ( $input['post_type'] ?? '' ) );
        $limit = max( 1, min( self::MAX_AUDIT_ITEMS, absint( $input['limit'] ?? 100 ) ) );
        $types = $post_type ? array( $post_type ) : get_post_types( array( 'public' => true ), 'names' );
        $types = array_values( array_filter( (array) $types, static fn( $t ) => ! in_array( $t, array( 'attachment' ), true ) ) );
        $query = new WP_Query( array( 'post_type' => $types, 'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private' ), 'posts_per_page' => $limit, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'no_found_rows' => true ) );
        $items = array(); $summary = array( 'checked' => 0, 'with_issues' => 0, 'errors' => 0, 'warnings' => 0, 'info' => 0 );
        $title_seen = array(); $desc_seen = array();
        foreach ( (array) $query->posts as $post_id ) {
            $post = get_post( $post_id ); if ( ! $post ) continue;
            $fields = self::read_fields( (int) $post_id, $provider );
            $issues = self::audit_fields( $post, $fields );
            $title_key = strtolower( trim( (string) ( $fields['title'] ?? '' ) ) );
            $desc_key  = strtolower( trim( (string) ( $fields['description'] ?? '' ) ) );
            if ( '' !== $title_key ) {
                if ( isset( $title_seen[ $title_key ] ) ) $issues[] = self::issue( 'warning', 'duplicate_seo_title', 'SEO title duplicates post #' . $title_seen[ $title_key ] . '.' );
                else $title_seen[ $title_key ] = (int) $post_id;
            }
            if ( '' !== $desc_key ) {
                if ( isset( $desc_seen[ $desc_key ] ) ) $issues[] = self::issue( 'warning', 'duplicate_meta_description', 'Meta description duplicates post #' . $desc_seen[ $desc_key ] . '.' );
                else $desc_seen[ $desc_key ] = (int) $post_id;
            }
            $summary['checked']++;
            if ( $issues ) $summary['with_issues']++;
            foreach ( $issues as $issue ) {
                $sev = (string) ( $issue['severity'] ?? 'info' );
                $bucket = array( 'error'=>'errors', 'warning'=>'warnings', 'info'=>'info' )[ $sev ] ?? 'info';
                $summary[ $bucket ]++;
            }
            $items[] = array( 'id' => (int) $post_id, 'post_type' => $post->post_type, 'title' => get_the_title( $post_id ), 'status' => $post->post_status, 'permalink' => get_permalink( $post_id ), 'issues' => $issues );
        }
        return array( 'provider' => $provider, 'summary' => $summary, 'items' => $items, 'limit' => $limit );
    }

    public static function generic_document_title( $title ) {
        if ( is_admin() || ! is_singular() || self::auto_provider() !== 'generic' ) return $title;
        $id = get_queried_object_id(); if ( ! $id ) return $title;
        $custom = (string) get_post_meta( $id, self::GENERIC_KEYS['title'], true );
        return '' !== trim( $custom ) ? $custom : $title;
    }

    public static function generic_robots( array $robots ): array {
        if ( is_admin() || ! is_singular() || self::auto_provider() !== 'generic' ) return $robots;
        $id = get_queried_object_id(); if ( ! $id ) return $robots;
        $stored = get_post_meta( $id, self::GENERIC_KEYS['robots'], true );
        if ( ! is_array( $stored ) ) return $robots;
        if ( isset( $stored['index'] ) && null !== $stored['index'] ) { unset( $robots[ $stored['index'] ? 'noindex' : 'index' ] ); $robots[ $stored['index'] ? 'index' : 'noindex' ] = true; }
        if ( isset( $stored['follow'] ) && null !== $stored['follow'] ) { unset( $robots[ $stored['follow'] ? 'nofollow' : 'follow' ] ); $robots[ $stored['follow'] ? 'follow' : 'nofollow' ] = true; }
        foreach ( array( 'noarchive','nosnippet','noimageindex' ) as $key ) if ( ! empty( $stored[ $key ] ) ) $robots[ $key ] = true;
        return $robots;
    }

    public static function generic_head(): void {
        if ( is_admin() || ! is_singular() || self::auto_provider() !== 'generic' ) return;
        $id = get_queried_object_id(); if ( ! $id ) return;
        $f = self::read_fields( $id, 'generic' );
        if ( ! empty( $f['description'] ) ) echo '<meta name="description" content="' . esc_attr( $f['description'] ) . '" />' . "\n";
        if ( ! empty( $f['canonical'] ) ) { remove_action( 'wp_head', 'rel_canonical' ); echo '<link rel="canonical" href="' . esc_url( $f['canonical'] ) . '" />' . "\n"; }
        $map = array( 'open_graph_title'=>'og:title','open_graph_description'=>'og:description','open_graph_image'=>'og:image' );
        foreach ( $map as $key => $property ) if ( ! empty( $f[ $key ] ) ) echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $f[ $key ] ) . '" />' . "\n";
        $twitter = array( 'twitter_title'=>'twitter:title','twitter_description'=>'twitter:description','twitter_image'=>'twitter:image','twitter_card'=>'twitter:card' );
        foreach ( $twitter as $key => $name ) if ( ! empty( $f[ $key ] ) ) echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $f[ $key ] ) . '" />' . "\n";
        if ( ! empty( $f['schema_json'] ) && is_array( $f['schema_json'] ) ) echo '<script type="application/ld+json">' . wp_json_encode( $f['schema_json'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
    }

    private static function yoast_active(): bool { return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Meta' ); }
    private static function rank_math_active(): bool { return defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath\\Helper' ) || function_exists( 'rank_math' ); }

    private static function auto_provider(): string {
        if ( self::yoast_active() ) return 'yoast';
        if ( self::rank_math_active() ) return 'rank_math';
        return 'generic';
    }

    private static function resolve_provider( string $provider ) {
        $provider = sanitize_key( $provider ?: 'auto' );
        if ( 'auto' === $provider ) {
            if ( self::yoast_active() && self::rank_math_active() ) return new WP_Error( 'alify_ai_seo_provider_conflict', 'Yoast SEO and Rank Math are both active. Choose provider=yoast or provider=rank_math explicitly.', array( 'status' => 409 ) );
            return self::auto_provider();
        }
        if ( 'yoast' === $provider && ! self::yoast_active() ) return new WP_Error( 'alify_ai_yoast_unavailable', 'Yoast SEO is not active.', array( 'status' => 409 ) );
        if ( 'rank_math' === $provider && ! self::rank_math_active() ) return new WP_Error( 'alify_ai_rank_math_unavailable', 'Rank Math is not active.', array( 'status' => 409 ) );
        if ( ! in_array( $provider, array( 'generic','yoast','rank_math' ), true ) ) return new WP_Error( 'alify_ai_invalid_seo_provider', 'SEO provider must be auto, generic, yoast, or rank_math.', array( 'status' => 400 ) );
        return $provider;
    }

    private static function read_fields( int $post_id, string $provider ): array {
        if ( 'yoast' === $provider ) return self::read_yoast( $post_id );
        if ( 'rank_math' === $provider ) return self::read_rank_math( $post_id );
        return self::read_generic( $post_id );
    }

    private static function base_fields(): array {
        return array(
            'title'=>'','description'=>'','canonical'=>'','focus_keyword'=>'',
            'robots'=>array( 'index'=>null,'follow'=>null,'noarchive'=>false,'nosnippet'=>false,'noimageindex'=>false ),
            'open_graph_title'=>'','open_graph_description'=>'','open_graph_image'=>'','open_graph_image_id'=>0,
            'twitter_title'=>'','twitter_description'=>'','twitter_image'=>'','twitter_image_id'=>0,'twitter_card'=>'',
            'schema_page_type'=>'','schema_article_type'=>'','schema_json'=>null,
        );
    }

    private static function read_generic( int $post_id ): array {
        $out = self::base_fields();
        foreach ( self::GENERIC_KEYS as $field => $key ) {
            $value = get_post_meta( $post_id, $key, true );
            if ( 'robots' === $field ) $out[$field] = is_array($value) ? array_replace($out[$field],$value) : $out[$field];
            elseif ( 'schema_json' === $field ) $out[$field] = is_array($value) ? $value : null;
            elseif ( str_ends_with($field,'_id') ) $out[$field] = absint($value);
            else $out[$field] = is_scalar($value) ? (string)$value : '';
        }
        return $out;
    }

    private static function read_rank_math( int $post_id ): array {
        $out = self::base_fields();
        foreach ( self::RANK_MATH_KEYS as $field => $key ) {
            $value = get_post_meta( $post_id, $key, true );
            if ( 'robots' === $field ) {
                $vals = is_array($value) ? array_map('sanitize_key',$value) : array_map('sanitize_key',array_filter(array_map('trim',explode(',',(string)$value))));
                $out['robots'] = array('index'=>in_array('noindex',$vals,true)?false:(in_array('index',$vals,true)?true:null),'follow'=>in_array('nofollow',$vals,true)?false:(in_array('follow',$vals,true)?true:null),'noarchive'=>in_array('noarchive',$vals,true),'nosnippet'=>in_array('nosnippet',$vals,true),'noimageindex'=>in_array('noimageindex',$vals,true));
            } elseif ( str_ends_with($field,'_id') ) $out[$field]=absint($value);
            else $out[$field]=is_scalar($value)?(string)$value:'';
        }
        $advanced = get_post_meta( $post_id, 'rank_math_advanced_robots', true );
        if ( is_array($advanced) ) {
            foreach ( array('noarchive','nosnippet','noimageindex') as $k ) if ( in_array($k,array_keys($advanced),true) || in_array($k,$advanced,true) ) $out['robots'][$k]=true;
        }
        return $out;
    }

    private static function read_yoast( int $post_id ): array {
        $out=self::base_fields();
        foreach(self::YOAST_KEYS as $field=>$key){$v=get_post_meta($post_id,$key,true);if(str_ends_with($field,'_id'))$out[$field]=absint($v);else$out[$field]=is_scalar($v)?(string)$v:'';}
        $noindex=(string)get_post_meta($post_id,'_yoast_wpseo_meta-robots-noindex',true);
        $nofollow=(string)get_post_meta($post_id,'_yoast_wpseo_meta-robots-nofollow',true);
        $adv=array_map('sanitize_key',array_filter(array_map('trim',explode(',',(string)get_post_meta($post_id,'_yoast_wpseo_meta-robots-adv',true)))));
        $out['robots']=array('index'=>'1'===$noindex?false:('2'===$noindex?true:null),'follow'=>'1'===$nofollow?false:true,'noarchive'=>in_array('noarchive',$adv,true),'nosnippet'=>in_array('nosnippet',$adv,true),'noimageindex'=>in_array('noimageindex',$adv,true));
        return $out;
    }

    private static function sanitize_fields( array $input, string $provider ) {
        $out=array();
        $text_fields=array('title'=>self::MAX_TITLE,'description'=>self::MAX_DESCRIPTION,'focus_keyword'=>self::MAX_KEYWORD,'open_graph_title'=>self::MAX_TITLE,'open_graph_description'=>self::MAX_DESCRIPTION,'twitter_title'=>self::MAX_TITLE,'twitter_description'=>self::MAX_DESCRIPTION,'twitter_card'=>100,'schema_page_type'=>100,'schema_article_type'=>100);
        foreach($text_fields as $key=>$max){if(array_key_exists($key,$input)){$v=sanitize_textarea_field((string)$input[$key]);if(strlen($v)>$max)return new WP_Error('alify_ai_seo_field_too_long',$key.' exceeds the safe length limit.',array('status'=>400,'field'=>$key,'max'=>$max));$out[$key]=$v;}}
        foreach(array('canonical','open_graph_image','twitter_image') as $key){if(array_key_exists($key,$input)){$raw=trim((string)$input[$key]);if(''!==$raw&&!wp_http_validate_url($raw))return new WP_Error('alify_ai_invalid_seo_url',$key.' must be an absolute HTTP/HTTPS URL.',array('status'=>400,'field'=>$key));$out[$key]=$raw?esc_url_raw($raw):'';}}
        foreach(array('open_graph_image_id','twitter_image_id') as $key){if(array_key_exists($key,$input)){$id=absint($input[$key]);if($id){$p=get_post($id);if(!$p||'attachment'!==$p->post_type||!wp_attachment_is_image($id))return new WP_Error('alify_ai_invalid_seo_image',$key.' must reference an image attachment.',array('status'=>400));}$out[$key]=$id;}}
        if(array_key_exists('robots',$input)){$r=$input['robots'];if(!is_array($r))return new WP_Error('alify_ai_invalid_robots','robots must be an object.',array('status'=>400));$clean=array();foreach(array('index','follow') as $k){if(array_key_exists($k,$r))$clean[$k]=null===$r[$k]?null:(bool)$r[$k];}foreach(array('noarchive','nosnippet','noimageindex') as $k){if(array_key_exists($k,$r))$clean[$k]=(bool)$r[$k];}$out['robots']=$clean;}
        if(array_key_exists('schema_json',$input)){
            if('generic'!==$provider)return new WP_Error('alify_ai_schema_provider_managed','Custom schema_json writes are only supported by the generic provider. Yoast/Rank Math own their schema graphs.',array('status'=>409));
            $schema=$input['schema_json'];if(null!==$schema&&!is_array($schema))return new WP_Error('alify_ai_invalid_schema','schema_json must be an object/array or null.',array('status'=>400));
            $encoded=wp_json_encode($schema);if(false===$encoded||strlen($encoded)>self::MAX_SCHEMA_BYTES)return new WP_Error('alify_ai_schema_too_large','schema_json is invalid or exceeds the safe size limit.',array('status'=>413));$out['schema_json']=$schema;
        }
        if('rank_math'===$provider&&isset($out['schema_article_type']))unset($out['schema_article_type']);
        return $out;
    }

    private static function write_fields( int $post_id, string $provider, array $fields, bool $mirror = false ) {
        if ( ! get_post( $post_id ) ) return new WP_Error('alify_ai_seo_post_not_found','SEO target post was not found.',array('status'=>404));
        $all = self::base_fields();
        $write = $mirror ? array_intersect_key( array_replace($all,$fields), $all ) : $fields;
        if('generic'===$provider)return self::write_generic($post_id,$write,$mirror);
        if('rank_math'===$provider)return self::write_rank_math($post_id,$write,$mirror);
        return self::write_yoast($post_id,$write,$mirror);
    }

    private static function write_generic(int $id,array $fields,bool $mirror){foreach(self::GENERIC_KEYS as $field=>$key){if(!$mirror&&!array_key_exists($field,$fields))continue;$value=$fields[$field]??null;self::write_meta($id,$key,$value);}return true;}
    private static function write_rank_math(int $id,array $fields,bool $mirror){
        foreach(self::RANK_MATH_KEYS as $field=>$key){if('robots'===$field)continue;if(!$mirror&&!array_key_exists($field,$fields))continue;self::write_meta($id,$key,$fields[$field]??'');}
        if($mirror||array_key_exists('robots',$fields)){$r=array_replace(self::base_fields()['robots'],(array)($fields['robots']??array()));$vals=array();if(false===$r['index'])$vals[]='noindex';elseif(true===$r['index'])$vals[]='index';if(false===$r['follow'])$vals[]='nofollow';elseif(true===$r['follow'])$vals[]='follow';foreach(array('noarchive','nosnippet','noimageindex') as $k)if(!empty($r[$k]))$vals[]=$k;self::write_meta($id,'rank_math_robots',$vals);}
        return true;
    }
    private static function write_yoast(int $id,array $fields,bool $mirror){
        foreach(self::YOAST_KEYS as $field=>$key){if(!$mirror&&!array_key_exists($field,$fields))continue;self::write_meta($id,$key,$fields[$field]??'');}
        if($mirror||array_key_exists('robots',$fields)){$r=array_replace(self::base_fields()['robots'],(array)($fields['robots']??array()));self::write_meta($id,'_yoast_wpseo_meta-robots-noindex',false===$r['index']?'1':(true===$r['index']?'2':'0'));self::write_meta($id,'_yoast_wpseo_meta-robots-nofollow',false===$r['follow']?'1':'0');$adv=array();foreach(array('noarchive','nosnippet','noimageindex') as $k)if(!empty($r[$k]))$adv[]=$k;self::write_meta($id,'_yoast_wpseo_meta-robots-adv',implode(',',$adv));}
        clean_post_cache( $id );
        return true;
    }
    private static function write_meta(int $id,string $key,$value): void {if(null===$value||''===$value||array()===$value)delete_post_meta($id,$key);else update_post_meta($id,$key,$value);}

    private static function fields_match(array $expected,array $actual,array $keys): bool {foreach($keys as $key){$e=$expected[$key]??null;$a=$actual[$key]??null;if('robots'===$key){$e=array_replace(self::base_fields()['robots'],(array)$e);$a=array_replace(self::base_fields()['robots'],(array)$a);if($e!=$a)return false;}elseif(str_ends_with($key,'_id')){if(absint($e)!==absint($a))return false;}elseif($e!=$a)return false;}return true;}
    private static function hash_fields(string $provider,array $fields): string {ksort($fields);return hash('sha256',$provider.'|'.(wp_json_encode($fields,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:''));}

    private static function audit_fields( WP_Post $post, array $f ): array {
        $issues=array();$title=trim((string)($f['title']??''));$desc=trim((string)($f['description']??''));
        if(''===$title)$issues[]=self::issue('error','missing_seo_title','Custom SEO title is missing; the provider may fall back to a template/title.');
        elseif(self::text_length($title)<30)$issues[]=self::issue('info','short_seo_title','SEO title is shorter than 30 characters.');
        elseif(self::text_length($title)>65)$issues[]=self::issue('warning','long_seo_title','SEO title is longer than 65 characters.');
        if(''===$desc)$issues[]=self::issue('warning','missing_meta_description','Meta description is missing.');
        elseif(self::text_length($desc)<100)$issues[]=self::issue('info','short_meta_description','Meta description is shorter than 100 characters.');
        elseif(self::text_length($desc)>165)$issues[]=self::issue('warning','long_meta_description','Meta description is longer than 165 characters.');
        $robots=array_replace(self::base_fields()['robots'],(array)($f['robots']??array()));if(false===$robots['index'])$issues[]=self::issue('info','noindex','This content explicitly requests noindex.');
        $canonical=trim((string)($f['canonical']??''));if(''!==$canonical&&!wp_http_validate_url($canonical))$issues[]=self::issue('error','invalid_canonical','Canonical URL is invalid.');
        if('publish'===$post->post_status&&!has_post_thumbnail($post->ID)&&empty($f['open_graph_image'])&&empty($f['open_graph_image_id']))$issues[]=self::issue('info','missing_social_image','No custom OpenGraph image or featured image is set.');
        return $issues;
    }
    private static function text_length( string $value ): int { return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value ); }
    private static function issue(string $severity,string $code,string $message): array{return array('severity'=>$severity,'code'=>$code,'message'=>$message);}
}
