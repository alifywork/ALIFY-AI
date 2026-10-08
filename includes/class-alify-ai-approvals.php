<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ALIFY_AI_Approvals {
    private const OPTION_DB_VERSION = 'alify_ai_approvals_db_version';
    private const DB_VERSION = '1.3.0';
    private const OPTION_POLICY = 'alify_ai_design_policy';
    private const OPTION_COMMERCE_POLICY = 'alify_ai_commerce_policy';
    private const OPTION_TRANSACTION_POLICY = 'alify_ai_transaction_policy';
    private const DEFAULT_TTL = 86400;
    private const MAX_PAYLOAD_BYTES = 1048576; // 1 MiB combined request + preview.
    private const APPLY_LOCK_TIMEOUT = 600;

    private static string $table = '';

    public static function init(): void {
        global $wpdb;
        self::$table = $wpdb->prefix . 'alify_ai_approvals';
        if ( self::DB_VERSION !== (string) get_option( self::OPTION_DB_VERSION, '' ) ) {
            self::activate();
        }
    }

    public static function activate(): void {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'alify_ai_approvals';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            kind varchar(32) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            request_json longtext NOT NULL,
            preview_json longtext NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            applying_at datetime NULL,
            applied_at datetime NULL,
            activity_id bigint(20) unsigned NOT NULL DEFAULT 0,
            failure_code varchar(100) NULL,
            failure_message text NULL,
            PRIMARY KEY  (id),
            KEY status_lookup (status, expires_at),
            KEY object_lookup (kind, object_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );

        if ( false === get_option( self::OPTION_POLICY, false ) ) {
            update_option( self::OPTION_POLICY, 'approval_required', false );
        }
        if ( false === get_option( self::OPTION_COMMERCE_POLICY, false ) ) {
            update_option( self::OPTION_COMMERCE_POLICY, 'approval_required', false );
        }
        if ( false === get_option( self::OPTION_TRANSACTION_POLICY, false ) ) {
            update_option( self::OPTION_TRANSACTION_POLICY, 'approval_required', false );
        }
    }

    public static function get_policy(): string {
        $policy = sanitize_key( (string) get_option( self::OPTION_POLICY, 'approval_required' ) );
        return in_array( $policy, array( 'preview_only', 'approval_required' ), true ) ? $policy : 'approval_required';
    }

    public static function update_policy( string $policy ): void {
        $policy = sanitize_key( $policy );
        if ( in_array( $policy, array( 'preview_only', 'approval_required' ), true ) ) {
            update_option( self::OPTION_POLICY, $policy, false );
        }
    }

    public static function get_commerce_policy(): string {
        return self::read_policy_option( self::OPTION_COMMERCE_POLICY );
    }

    public static function update_commerce_policy( string $policy ): void {
        self::write_policy_option( self::OPTION_COMMERCE_POLICY, $policy );
    }

    public static function get_transaction_policy(): string {
        return self::read_policy_option( self::OPTION_TRANSACTION_POLICY );
    }

    public static function update_transaction_policy( string $policy ): void {
        self::write_policy_option( self::OPTION_TRANSACTION_POLICY, $policy );
    }

    private static function read_policy_option( string $option ): string {
        $policy = sanitize_key( (string) get_option( $option, 'approval_required' ) );
        return in_array( $policy, array( 'preview_only', 'approval_required' ), true ) ? $policy : 'approval_required';
    }

    private static function write_policy_option( string $option, string $policy ): void {
        $policy = sanitize_key( $policy );
        if ( in_array( $policy, array( 'preview_only', 'approval_required' ), true ) ) {
            update_option( $option, $policy, false );
        }
    }

    public static function create( string $kind, int $object_id, array $request, array $preview, int $ttl = self::DEFAULT_TTL ) {
        global $wpdb;
        self::ensure_table();

        $kind = sanitize_key( $kind );
        if ( ! in_array( $kind, array( 'gutenberg', 'elementor', 'seo', 'code', 'woocommerce_product_create', 'woocommerce_product_update', 'woocommerce_catalog', 'woocommerce_order', 'transaction' ), true ) ) {
            return new WP_Error( 'alify_ai_invalid_approval_kind', 'Unsupported approval kind.', array( 'status' => 400 ) );
        }

        $request_json = wp_json_encode( $request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $preview_json = wp_json_encode( $preview, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $request_json || false === $preview_json ) {
            return new WP_Error( 'alify_ai_approval_encode_failed', 'Could not serialize the approval proposal.', array( 'status' => 500 ) );
        }
        $payload_bytes = strlen( $request_json ) + strlen( $preview_json );
        if ( $payload_bytes > self::MAX_PAYLOAD_BYTES ) {
            return new WP_Error( 'alify_ai_approval_too_large', 'This proposal is too large to store safely. Split it into smaller changes.', array( 'status' => 413, 'bytes' => $payload_bytes, 'max_bytes' => self::MAX_PAYLOAD_BYTES ) );
        }

        $ttl = max( 300, min( 604800, $ttl ) );
        $now = time();
        $inserted = $wpdb->insert(
            self::$table,
            array(
                'kind'         => $kind,
                'object_id'    => absint( $object_id ),
                'status'       => 'pending',
                'request_json' => $request_json,
                'preview_json' => $preview_json,
                'created_at'   => gmdate( 'Y-m-d H:i:s', $now ),
                'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + $ttl ),
                'activity_id'  => 0,
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
        );

        if ( false === $inserted ) {
            return new WP_Error( 'alify_ai_approval_store_failed', 'Could not store approval proposal.', array( 'status' => 500 ) );
        }

        return self::get( (int) $wpdb->insert_id );
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        self::ensure_table();
        self::expire_pending();

        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::$table . ' WHERE id = %d', absint( $id ) ),
            ARRAY_A
        );
        return $row ? self::hydrate( $row ) : null;
    }

    public static function recent( int $limit = 30, string $status = '' ): array {
        global $wpdb;
        self::ensure_table();
        self::expire_pending();

        $limit = max( 1, min( 100, $limit ) );
        $status = sanitize_key( $status );
        $columns = 'id, kind, object_id, status, created_at, expires_at, applying_at, applied_at, activity_id, failure_code, failure_message';
        if ( in_array( $status, array( 'pending', 'applying', 'applied', 'cancelled', 'expired', 'failed', 'failed_unknown_state' ), true ) ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare( 'SELECT ' . $columns . ' FROM ' . self::$table . ' WHERE status = %s ORDER BY id DESC LIMIT %d', $status, $limit ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare( 'SELECT ' . $columns . ' FROM ' . self::$table . ' ORDER BY id DESC LIMIT %d', $limit ),
                ARRAY_A
            );
        }

        return array_map( array( __CLASS__, 'hydrate' ), $rows ?: array() );
    }

    public static function cancel( int $id ) {
        global $wpdb;
        self::ensure_table();
        self::expire_pending();

        $changed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . self::$table . " SET status = 'cancelled' WHERE id = %d AND status = 'pending'",
                absint( $id )
            )
        );
        if ( 1 === (int) $changed ) {
            return self::get( $id );
        }

        $approval = self::get( $id );
        if ( ! $approval ) {
            return new WP_Error( 'alify_ai_approval_not_found', 'Approval proposal not found.', array( 'status' => 404 ) );
        }
        return new WP_Error( 'alify_ai_approval_not_pending', 'Only pending proposals can be cancelled.', array( 'status' => 409, 'current_status' => $approval['status'] ) );
    }

    public static function apply( int $id ) {
        global $wpdb;
        self::ensure_table();
        self::expire_pending();

        $approval = self::get( $id );
        if ( ! $approval ) {
            return new WP_Error( 'alify_ai_approval_not_found', 'Approval proposal not found.', array( 'status' => 404 ) );
        }
        if ( 'expired' === $approval['status'] ) {
            return new WP_Error( 'alify_ai_approval_expired', 'This proposal has expired. Create a fresh preview.', array( 'status' => 410 ) );
        }
        if ( 'pending' !== $approval['status'] ) {
            return new WP_Error( 'alify_ai_approval_not_pending', 'Only pending proposals can be applied.', array( 'status' => 409, 'current_status' => $approval['status'] ) );
        }

        if ( in_array( $approval['kind'], array( 'gutenberg', 'elementor' ), true ) && 'preview_only' === self::get_policy() ) {
            return new WP_Error( 'alify_ai_preview_only', 'Design policy is Preview only. Change it in WordPress settings before applying proposals.', array( 'status' => 403 ) );
        }
        if ( str_starts_with( $approval['kind'], 'woocommerce_' ) && 'preview_only' === self::get_commerce_policy() ) {
            return new WP_Error( 'alify_ai_preview_only', 'Commerce policy is Preview only. Change it in WordPress settings before applying proposals.', array( 'status' => 403 ) );
        }
        if ( 'transaction' === $approval['kind'] && 'preview_only' === self::get_transaction_policy() ) {
            return new WP_Error( 'alify_ai_preview_only', 'Transaction policy is Preview only. Change it in WordPress settings before applying proposals.', array( 'status' => 403 ) );
        }

        // Atomic claim closes the double-apply race. Exactly one caller may
        // transition a proposal from pending to applying.
        $claimed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . self::$table . " SET status = 'applying', applying_at = %s WHERE id = %d AND status = 'pending' AND expires_at >= %s",
                current_time( 'mysql', true ),
                absint( $id ),
                current_time( 'mysql', true )
            )
        );
        if ( 1 !== (int) $claimed ) {
            $latest = self::get( $id );
            return new WP_Error(
                'alify_ai_approval_claim_failed',
                'This proposal is already being applied, has already been handled, or expired.',
                array( 'status' => 409, 'current_status' => $latest['status'] ?? 'unknown' )
            );
        }

        // Re-read after claiming so the dispatcher receives the authoritative row.
        $approval = self::get( $id );
        try {
            if ( 'gutenberg' === $approval['kind'] ) {
                $result = ALIFY_AI_Gutenberg::apply_approval( $approval );
            } elseif ( 'elementor' === $approval['kind'] ) {
                $result = ALIFY_AI_Elementor::apply_approval( $approval );
            } elseif ( in_array( $approval['kind'], array( 'woocommerce_product_create', 'woocommerce_product_update', 'woocommerce_catalog' ), true ) ) {
                $result = ALIFY_AI_WooCommerce::apply_approval( $approval );
            } elseif ( 'woocommerce_order' === $approval['kind'] ) {
                $result = ALIFY_AI_Orders::apply_approval( $approval );
            } elseif ( 'seo' === $approval['kind'] ) {
                $result = ALIFY_AI_SEO::apply_approval( $approval );
            } elseif ( 'transaction' === $approval['kind'] ) {
                $result = ALIFY_AI_Transactions::apply_approval( $approval );
            } elseif ( 'code' === $approval['kind'] ) {
                $result = ALIFY_AI_Code_Inspection::apply_approval( $approval );
            } else {
                $result = new WP_Error( 'alify_ai_invalid_approval_kind', 'Unsupported approval kind.', array( 'status' => 400 ) );
            }
        } catch ( Throwable $error ) {
            ALIFY_AI_Diagnostics::log_throwable( $error, 'approval_apply', array( 'approval_id' => $id, 'kind' => $approval['kind'] ?? '' ) );
            $wpdb->update(
                self::$table,
                array(
                    'status'          => 'failed_unknown_state',
                    'applying_at'     => null,
                    'failure_code'    => 'runtime_exception',
                    'failure_message' => 'A runtime exception interrupted the apply operation. The underlying WordPress state may have changed; inspect the activity log before retrying.',
                ),
                array( 'id' => $id, 'status' => 'applying' ),
                array( '%s', '%s', '%s', '%s' ),
                array( '%d', '%s' )
            );
            return new WP_Error(
                'alify_ai_approval_runtime_failure',
                'The proposal hit a runtime failure and its final WordPress state is unknown. Inspect Activity before making another change.',
                array( 'status' => 500, 'approval_status' => 'failed_unknown_state' )
            );
        }

        if ( is_wp_error( $result ) ) {
            $error_code = sanitize_key( (string) $result->get_error_code() );
            $failure_status = str_contains( $error_code, 'unknown_state' ) ? 'failed_unknown_state' : 'failed';
            $wpdb->update(
                self::$table,
                array(
                    'status'          => $failure_status,
                    'applying_at'     => null,
                    'failure_code'    => $error_code,
                    'failure_message' => sanitize_text_field( (string) $result->get_error_message() ),
                ),
                array( 'id' => $id, 'status' => 'applying' ),
                array( '%s', '%s', '%s', '%s' ),
                array( '%d', '%s' )
            );
            return $result;
        }

        $stored = $wpdb->update(
            self::$table,
            array(
                'status'      => 'applied',
                'applying_at' => null,
                'applied_at'  => current_time( 'mysql', true ),
                'activity_id' => absint( $result['activity_id'] ?? 0 ),
            ),
            array( 'id' => $id, 'status' => 'applying' ),
            array( '%s', '%s', '%s', '%d' ),
            array( '%d', '%s' )
        );

        if ( false === $stored ) {
            return new WP_Error( 'alify_ai_approval_state_store_failed', 'The change was applied but the approval status could not be finalized. Do not retry this proposal.', array( 'status' => 500, 'activity_id' => absint( $result['activity_id'] ?? 0 ) ) );
        }

        $result['approval'] = self::get( $id );
        return $result;
    }

    private static function ensure_table(): void {
        if ( '' === self::$table ) {
            global $wpdb;
            self::$table = $wpdb->prefix . 'alify_ai_approvals';
        }
        if ( self::DB_VERSION !== (string) get_option( self::OPTION_DB_VERSION, '' ) ) {
            self::activate();
        }
    }

    private static function expire_pending(): void {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::$table . " SET status = 'expired' WHERE status = 'pending' AND expires_at < %s",
                $now
            )
        );
        $stale_lock = gmdate( 'Y-m-d H:i:s', time() - self::APPLY_LOCK_TIMEOUT );
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::$table . " SET status = 'failed_unknown_state', applying_at = NULL, failure_code = 'stale_apply_lock', failure_message = 'The apply lock expired before completion. The final WordPress state may be unknown.' WHERE status = 'applying' AND applying_at IS NOT NULL AND applying_at < %s",
                $stale_lock
            )
        );
    }

    private static function hydrate( array $row ): array {
        return array(
            'id'          => (int) $row['id'],
            'kind'        => (string) $row['kind'],
            'object_id'   => (int) $row['object_id'],
            'status'      => (string) $row['status'],
            'request'     => ! empty( $row['request_json'] ) ? ( json_decode( $row['request_json'], true ) ?: array() ) : array(),
            'preview'     => ! empty( $row['preview_json'] ) ? ( json_decode( $row['preview_json'], true ) ?: array() ) : array(),
            'created_at'  => (string) $row['created_at'],
            'expires_at'  => (string) $row['expires_at'],
            'applying_at' => ! empty( $row['applying_at'] ) ? (string) $row['applying_at'] : null,
            'applied_at'  => ! empty( $row['applied_at'] ) ? (string) $row['applied_at'] : null,
            'activity_id'     => (int) $row['activity_id'],
            'failure_code'    => isset( $row['failure_code'] ) ? (string) $row['failure_code'] : '',
            'failure_message' => isset( $row['failure_message'] ) ? (string) $row['failure_message'] : '',
        );
    }
}
