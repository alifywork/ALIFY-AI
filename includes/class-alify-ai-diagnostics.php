<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ALIFY_AI_Diagnostics {
    private const OPTION_ENABLED = 'alify_ai_diagnostics_enabled';
    private const MAX_CONTEXT_BYTES = 8192;

    public static function enabled(): bool {
        $configured = (bool) get_option( self::OPTION_ENABLED, false );
        return $configured || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
    }

    public static function set_enabled( bool $enabled ): void {
        update_option( self::OPTION_ENABLED, $enabled ? 1 : 0, false );
    }

    public static function log( string $message, array $context = array() ): void {
        if ( ! self::enabled() ) {
            return;
        }

        $message = trim( wp_strip_all_tags( $message ) );
        if ( '' === $message ) {
            $message = 'Unspecified connector diagnostic event.';
        }

        $safe_context = self::sanitize_context( $context );
        $suffix = '';
        if ( $safe_context ) {
            $encoded = wp_json_encode( $safe_context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            if ( is_string( $encoded ) ) {
                $suffix = ' ' . substr( $encoded, 0, self::MAX_CONTEXT_BYTES );
            }
        }

        // error_log is intentionally used only when diagnostics are enabled. Never log secrets.
        error_log( '[ALIFY AI] ' . $message . $suffix ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }

    public static function log_throwable( Throwable $error, string $area, array $context = array() ): void {
        $context['area'] = sanitize_key( $area );
        $context['exception'] = get_class( $error );
        $context['code'] = (string) $error->getCode();
        $context['file'] = wp_basename( (string) $error->getFile() );
        $context['line'] = (int) $error->getLine();
        $context['message'] = self::redact( (string) $error->getMessage() );
        self::log( 'Runtime exception captured.', $context );
    }

    private static function sanitize_context( array $context ): array {
        $clean = array();
        foreach ( $context as $key => $value ) {
            $key = sanitize_key( (string) $key );
            if ( '' === $key || self::sensitive_key( $key ) ) {
                continue;
            }
            if ( is_scalar( $value ) || null === $value ) {
                $clean[ $key ] = is_string( $value ) ? self::redact( $value ) : $value;
            } elseif ( is_array( $value ) ) {
                $clean[ $key ] = self::sanitize_context( $value );
            } else {
                $clean[ $key ] = get_debug_type( $value );
            }
        }
        return $clean;
    }

    private static function sensitive_key( string $key ): bool {
        foreach ( array( 'token', 'secret', 'password', 'authorization', 'api_key', 'apikey', 'cookie', 'nonce' ) as $needle ) {
            if ( str_contains( $key, $needle ) ) {
                return true;
            }
        }
        return false;
    }

    private static function redact( string $value ): string {
        $value = preg_replace( '/alify_[A-Za-z0-9_-]{20,}/', '[redacted-alify-secret]', $value );
        $value = preg_replace( '/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [redacted]', (string) $value );
        return substr( (string) $value, 0, 2000 );
    }
}
