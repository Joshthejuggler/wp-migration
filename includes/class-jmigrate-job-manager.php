<?php
/**
 * Manages JMigrate background import jobs.
 */
if ( class_exists( 'JMigrate_Job_Manager' ) ) {
    return;
}

class JMigrate_Job_Manager {
    const TRANSIENT_PREFIX = 'jmigrate_job_';
    const LIFETIME         = 30 * MINUTE_IN_SECONDS;

    /**
     * Creates a new job record.
     *
     * @param array $data Job data.
     * @return string Job identifier.
     */
    public static function create_job( array $data ) {
        $job_id = wp_generate_uuid4();

        $defaults = [
            'status'      => 'pending',
            'progress'    => 0,
            'messages'    => [],
            'created_at'  => time(),
            'archive'     => '',
            'files'       => 'all',
            'error'       => '',
        ];

        $job = wp_parse_args( $data, $defaults );

        set_transient( self::get_key( $job_id ), $job, self::LIFETIME );

        return $job_id;
    }

    /**
     * Retrieves a job, if available.
     *
     * @param string $job_id Job identifier.
     * @return array|null
     */
    public static function get_job( $job_id ) {
        $job = get_transient( self::get_key( $job_id ) );
        return $job ? $job : null;
    }

    /**
     * Updates a job with new data.
     *
     * @param string $job_id Job identifier.
     * @param array  $updates Data to merge.
     * @return void
     */
    public static function update_job( $job_id, array $updates ) {
        $job = self::get_job( $job_id );
        if ( ! $job ) {
            return;
        }

        $job = array_merge( $job, $updates );
        set_transient( self::get_key( $job_id ), $job, self::LIFETIME );
    }

    /**
     * Appends a log message to the job.
     *
     * @param string $job_id Job identifier.
     * @param string $type   Message type (info|success|error).
     * @param string $message Message text.
     * @return void
     */
    public static function append_message( $job_id, $type, $message ) {
        $job = self::get_job( $job_id );
        if ( ! $job ) {
            return;
        }

        $job['messages'][] = [
            'type'    => $type,
            'message' => $message,
            'time'    => time(),
        ];

        set_transient( self::get_key( $job_id ), $job, self::LIFETIME );
    }

    /**
     * Sets the progress value for the job.
     *
     * @param string $job_id Job identifier.
     * @param int    $progress Progress percentage.
     * @return void
     */
    public static function set_progress( $job_id, $progress ) {
        $job = self::get_job( $job_id );
        if ( ! $job ) {
            return;
        }

        $job['progress'] = max( 0, min( 100, (int) $progress ) );
        set_transient( self::get_key( $job_id ), $job, self::LIFETIME );
    }

    /**
     * Deletes a job.
     *
     * @param string $job_id Job identifier.
     * @return void
     */
    public static function delete_job( $job_id ) {
        delete_transient( self::get_key( $job_id ) );
    }

    /**
     * Builds the transient key for a job id.
     *
     * @param string $job_id Job identifier.
     * @return string
     */
    private static function get_key( $job_id ) {
        return self::TRANSIENT_PREFIX . sanitize_key( $job_id );
    }
}
