<?php
/**
 * Logger implementation that streams messages to an async job store.
 */
if ( class_exists( 'JMigrate_Job_Logger' ) ) {
    return;
}

class JMigrate_Job_Logger implements JMigrate_Logger_Interface {
    /**
     * @var string
     */
    private $job_id;

    /**
     * @param string $job_id Job identifier.
     */
    public function __construct( $job_id ) {
        $this->job_id = $job_id;
    }

    /** @inheritDoc */
    public function info( $message ) {
        JMigrate_Job_Manager::append_message( $this->job_id, 'info', (string) $message );
    }

    /** @inheritDoc */
    public function success( $message ) {
        JMigrate_Job_Manager::append_message( $this->job_id, 'success', (string) $message );
    }

    /** @inheritDoc */
    public function error( $message ) {
        JMigrate_Job_Manager::append_message( $this->job_id, 'error', (string) $message );
    }
}
