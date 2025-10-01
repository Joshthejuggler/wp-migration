<?php
/**
 * Logger implementation that stores messages in memory.
 */

if ( class_exists( 'JMigrate_Array_Logger' ) ) {
    return;
}

class JMigrate_Array_Logger implements JMigrate_Logger_Interface {
    /**
     * @var array[] Collected log entries.
     */
    private $entries = [];

    /**
     * {@inheritDoc}
     */
    public function info( $message ) {
        $this->entries[] = [
            'type'    => 'info',
            'message' => (string) $message,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function success( $message ) {
        $this->entries[] = [
            'type'    => 'success',
            'message' => (string) $message,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function error( $message ) {
        $this->entries[] = [
            'type'    => 'error',
            'message' => (string) $message,
        ];
    }

    /**
     * Returns captured entries.
     *
     * @return array[]
     */
    public function all() {
        return $this->entries;
    }
}
