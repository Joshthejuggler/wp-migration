<?php
/**
 * Logger implementation that proxies messages to WP-CLI.
 */

if ( class_exists( 'JMigrate_CLI_Logger' ) ) {
    return;
}

class JMigrate_CLI_Logger implements JMigrate_Logger_Interface {
    /**
     * {@inheritDoc}
     */
    public function info( $message ) {
        \WP_CLI::log( (string) $message );
    }

    /**
     * {@inheritDoc}
     */
    public function success( $message ) {
        \WP_CLI::success( (string) $message );
    }

    /**
     * {@inheritDoc}
     */
    public function error( $message ) {
        \WP_CLI::error( (string) $message, false );
    }
}
