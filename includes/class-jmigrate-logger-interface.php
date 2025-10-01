<?php
/**
 * Defines the logger contract used by JMigrate exporters.
 */

interface JMigrate_Logger_Interface {
    /**
     * Records an informational message.
     *
     * @param string $message Message text.
     */
    public function info( $message );

    /**
     * Records a success message.
     *
     * @param string $message Message text.
     */
    public function success( $message );

    /**
     * Records an error message.
     *
     * @param string $message Message text.
     */
    public function error( $message );
}
