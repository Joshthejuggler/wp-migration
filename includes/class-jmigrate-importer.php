<?php
/**
 * Handles importing JMigrate archives.
 */

if ( class_exists( 'JMigrate_Importer' ) ) {
    return;
}

class JMigrate_Importer {
    /**
     * Source table prefix detected from the archive.
     *
     * @var string|null
     */
    private $source_table_prefix = null;

    /**
     * Whether we've logged a prefix remapping notice.
     *
     * @var bool
     */
    private $logged_prefix_change = false;

    /**
     * Performs an import from the given archive.
     *
     * @param string                    $archive_path Path to the migration archive (zip).
     * @param JMigrate_Logger_Interface $logger       Logger instance.
     * @param bool                      $prefer_wp_cli When true prefer WP-CLI helpers when available.
     *
     * @return void
     *
     * @throws JMigrate_Import_Exception When validation or import fails.
     */
    public function import( $archive_path, JMigrate_Logger_Interface $logger, $prefer_wp_cli = false, $files_strategy = 'all', $job_id = null ) {
        if ( ! $logger instanceof JMigrate_Logger_Interface ) {
            throw new JMigrate_Import_Exception( 'Invalid logger provided.' );
        }

        $archive = $this->resolve_archive_path( $archive_path );

        if ( $job_id ) {
            JMigrate_Job_Manager::update_job(
                $job_id,
                [
                    'status'   => 'running',
                    'progress' => 5,
                ]
            );
        }

        if ( ! $prefer_wp_cli && $this->can_use_wp_cli_binary() && $this->should_delegate_to_cli( $archive ) ) {
            $logger->info( 'Delegating import to WP-CLI for large archive...' );

            try {
                $this->import_with_wp_cli_binary( $archive, $logger );
                if ( $job_id ) {
                    JMigrate_Job_Manager::set_progress( $job_id, 100 );
                    JMigrate_Job_Manager::update_job( $job_id, [ 'status' => 'success' ] );
                }
                return;
            } catch ( JMigrate_Import_Exception $cli_exception ) {
                $logger->info( sprintf( 'WP-CLI import fallback unavailable (%s). Continuing with PHP importer...', $cli_exception->getMessage() ) );
            }
        }

        if ( ! class_exists( '\ZipArchive' ) ) {
            throw new JMigrate_Import_Exception( 'The ZipArchive PHP extension is required to import archives.' );
        }

        $logger->info( sprintf( 'Opening archive: %s', $archive ) );

        $zip = new \ZipArchive();
        if ( true !== $zip->open( $archive ) ) {
            throw new JMigrate_Import_Exception( sprintf( 'Unable to open archive: %s', $archive ) );
        }

        if ( false === $zip->locateName( 'database.sql', \ZipArchive::FL_NOCASE | \ZipArchive::FL_NODIR ) ) {
            $zip->close();
            throw new JMigrate_Import_Exception( 'Archive does not contain database.sql.' );
        }

        $working_dir = $this->create_working_directory();

        if ( ! $zip->extractTo( $working_dir ) ) {
            $zip->close();
            $this->cleanup_directory( $working_dir );
            throw new JMigrate_Import_Exception( 'Failed to extract archive contents.' );
        }

        $zip->close();

        if ( $job_id ) {
            JMigrate_Job_Manager::set_progress( $job_id, 15 );
        }

        $database_file = trailingslashit( $working_dir ) . 'database.sql';
        if ( ! file_exists( $database_file ) ) {
            $this->cleanup_directory( $working_dir );
            throw new JMigrate_Import_Exception( 'Extracted archive is missing database.sql.' );
        }

        try {
            $logger->info( 'Importing database...' );
            if ( $prefer_wp_cli && class_exists( '\\WP_CLI' ) ) {
                $this->import_database_with_wp_cli( $database_file );
            } else {
                $this->import_database_with_php( $database_file, $logger, $job_id );
            }

            if ( $job_id ) {
                JMigrate_Job_Manager::set_progress( $job_id, 60 );
            }

            $files_strategy = in_array( $files_strategy, [ 'all', 'content', 'skip' ], true ) ? $files_strategy : 'all';

            if ( 'skip' === $files_strategy ) {
                $logger->info( 'Skipping file synchronization per import settings.' );
                if ( $job_id ) {
                    JMigrate_Job_Manager::set_progress( $job_id, 100 );
                }
            } else {
                if ( 'content' === $files_strategy ) {
                    $logger->info( 'Synchronizing wp-content only (core files left untouched).' );
                } else {
                    $logger->info( 'Synchronizing all files in the archive...' );
                }

                $this->synchronize_files( $working_dir, ABSPATH, $logger, $files_strategy, $job_id );
            }
        } catch ( \Throwable $throwable ) {
            $this->cleanup_directory( $working_dir );
            if ( $job_id ) {
                JMigrate_Job_Manager::update_job( $job_id, [
                    'status' => 'error',
                    'error'  => $throwable->getMessage(),
                ] );
            }
            throw new JMigrate_Import_Exception( $throwable->getMessage(), (int) $throwable->getCode(), $throwable );
        }

        $this->cleanup_directory( $working_dir );
        $logger->success( 'Import completed successfully.' );
        if ( $job_id ) {
            JMigrate_Job_Manager::set_progress( $job_id, 100 );
            JMigrate_Job_Manager::update_job( $job_id, [ 'status' => 'success' ] );
        }
    }

    /**
     * Ensures the provided archive path is valid and accessible.
     *
     * @param string $archive_path User-provided path.
     * @return string Absolute normalized path.
     */
    private function resolve_archive_path( $archive_path ) {
        $archive_path = trim( (string) $archive_path );
        if ( '' === $archive_path ) {
            throw new JMigrate_Import_Exception( 'Please provide the path to a JMigrate archive.' );
        }

        $path = $this->expand_user_path( $archive_path );

        if ( ! $this->is_path_absolute( $path ) ) {
            $cwd  = wp_normalize_path( getcwd() );
            $path = trailingslashit( $cwd ) . ltrim( $path, '/\\' );
        }

        $path = wp_normalize_path( $path );

        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            throw new JMigrate_Import_Exception( sprintf( 'Archive not found or unreadable: %s', $path ) );
        }

        if ( ! preg_match( '/\.zip$/i', $path ) ) {
            throw new JMigrate_Import_Exception( 'Archive must be a .zip file created by JMigrate.' );
        }

        return $path;
    }

    /**
     * Creates a temporary working directory for extraction.
     *
     * @return string Absolute path.
     */
    private function create_working_directory() {
        $base = wp_normalize_path( sys_get_temp_dir() );
        try {
            $random = bin2hex( random_bytes( 8 ) );
        } catch ( \Exception $exception ) {
            $random = dechex( wp_rand( 0, PHP_INT_MAX ) );
        }

        $path = trailingslashit( $base ) . 'jmigrate-import-' . $random;

        if ( ! wp_mkdir_p( $path ) ) {
            throw new JMigrate_Import_Exception( sprintf( 'Unable to create temporary directory: %s', $path ) );
        }

        return $path;
    }

    /**
     * Imports the database using WP-CLI.
     *
     * @param string $database_file Path to database.sql.
     */
    private function import_database_with_wp_cli( $database_file ) {
        if ( ! class_exists( '\\WP_CLI' ) ) {
            throw new JMigrate_Import_Exception( 'WP-CLI is not available in this environment.' );
        }

        if ( ! function_exists( '\\WP_CLI\\Utils\\esc_cmd' ) ) {
            throw new JMigrate_Import_Exception( 'WP-CLI utilities are not accessible.' );
        }

        $command = \WP_CLI\Utils::esc_cmd( 'db import %s', $database_file );
        \WP_CLI::runcommand(
            $command,
            [
                'exit_error' => true,
            ]
        );
    }

    /**
     * Imports the database using PHP/wpdb.
     *
     * @param string $database_file Path to database.sql.
     */
    private function import_database_with_php( $database_file, JMigrate_Logger_Interface $logger, $job_id = null ) {
        global $wpdb;

        if ( ! file_exists( $database_file ) || ! is_readable( $database_file ) ) {
            throw new JMigrate_Import_Exception( sprintf( 'Unable to open database export file: %s', $database_file ) );
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $handle = fopen( $database_file, 'r' );
        if ( ! $handle ) {
            throw new JMigrate_Import_Exception( sprintf( 'Unable to open database export file: %s', $database_file ) );
        }

        if ( $job_id ) {
            JMigrate_Job_Manager::set_progress( $job_id, 20 );
        }

        $buffer             = '';
        $chunk_size         = apply_filters( 'jmigrate_php_import_chunk_size', 1024 * 1024 );
        $statements_counter = 0;
        $next_tick          = 0;

        $wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );

        try {
            while ( ! feof( $handle ) ) {
                $chunk = fread( $handle, $chunk_size );
                if ( false === $chunk ) {
                    break;
                }

                if ( '' === $chunk ) {
                    continue;
                }

                $chunk  = str_replace( "\r\n", "\n", $chunk );
                $chunk  = str_replace( "\r", "\n", $chunk );
                $buffer .= $chunk;

                $buffer = $this->process_sql_buffer( $buffer, $wpdb, false, $logger, $job_id, $statements_counter, $next_tick );
            }

        $this->process_sql_buffer( $buffer, $wpdb, true, $logger, $job_id, $statements_counter, $next_tick );

        if ( $job_id ) {
            $job = JMigrate_Job_Manager::get_job( $job_id );
            $current_progress = isset( $job['progress'] ) ? (int) $job['progress'] : 60;
            JMigrate_Job_Manager::set_progress( $job_id, max( 60, $current_progress ) );
        }
        } finally {
            fclose( $handle );
            $wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
        }
    }

    /**
     * Synchronizes extracted files into the WordPress installation.
     *
     * @param string                    $source_root Extracted archive root.
     * @param string                    $target_root Destination root (ABSPATH).
     * @param JMigrate_Logger_Interface $logger      Logger instance.
     */
    private function synchronize_files( $source_root, $target_root, JMigrate_Logger_Interface $logger, $files_strategy = 'all', $job_id = null ) {
        $source_root = trailingslashit( wp_normalize_path( $source_root ) );
        $target_root = trailingslashit( wp_normalize_path( $target_root ) );

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $source_root, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $limit_to_content = 'content' === $files_strategy;
        $processed_files  = 0;

        if ( $job_id ) {
            $initial_file_progress = ( 'content' === $files_strategy ) ? 75 : 70;
            JMigrate_Job_Manager::set_progress( $job_id, $initial_file_progress );
        }

        foreach ( $iterator as $item ) {
            /** @var \SplFileInfo $item */
            $real_path = $item->getRealPath();
            if ( false === $real_path ) {
                continue;
            }

            $normalized_path = wp_normalize_path( $real_path );
            $relative_path   = ltrim( substr( $normalized_path, strlen( $source_root ) ), '/\\' );

            if ( '' === $relative_path ) {
                continue;
            }

            if ( 'database.sql' === $relative_path ) {
                continue;
            }

            if ( $limit_to_content && 0 !== strpos( $relative_path, 'wp-content' ) ) {
                continue;
            }

            if ( 'wp-config.php' === $relative_path ) {
                $logger->info( 'Skipping wp-config.php to preserve current configuration.' );
                continue;
            }

            $destination = $target_root . $relative_path;

            if ( $item->isDir() ) {
                if ( ! wp_mkdir_p( $destination ) ) {
                    throw new JMigrate_Import_Exception( sprintf( 'Unable to create directory: %s', $destination ) );
                }
                continue;
            }

            $destination_dir = dirname( $destination );
            if ( ! wp_mkdir_p( $destination_dir ) ) {
                throw new JMigrate_Import_Exception( sprintf( 'Unable to create directory: %s', $destination_dir ) );
            }

            if ( ! copy( $normalized_path, $destination ) ) {
                throw new JMigrate_Import_Exception( sprintf( 'Failed to copy file to %s', $destination ) );
            }

            $processed_files++;

            if ( $job_id && 0 === $processed_files % 25 ) {
                $target_progress = ( 'content' === $files_strategy ) ? 95 : 98;
                $current_job     = JMigrate_Job_Manager::get_job( $job_id );
                $current_progress = isset( $current_job['progress'] ) ? (int) $current_job['progress'] : 70;
                $new_progress     = min( $target_progress, $current_progress + 2 );
                JMigrate_Job_Manager::set_progress( $job_id, $new_progress );
            }
        }

        if ( $job_id ) {
            JMigrate_Job_Manager::set_progress( $job_id, 100 );
        }
    }

    /**
     * Recursively removes a directory.
     *
     * @param string $path Directory path.
     */
    private function cleanup_directory( $path ) {
        if ( empty( $path ) || ! is_dir( $path ) ) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $item ) {
            /** @var \SplFileInfo $item */
            $real_path = $item->getRealPath();
            if ( false === $real_path ) {
                continue;
            }

            if ( $item->isDir() ) {
                rmdir( $real_path );
            } else {
                unlink( $real_path );
            }
        }

        rmdir( $path );
    }

    /**
     * Processes the SQL buffer, executing complete statements and returning the remainder.
     *
     * @param string $buffer SQL buffer.
     * @param wpdb   $wpdb   WordPress database instance.
     * @param bool   $final  Whether this is the final pass (process remaining buffer).
     * @return string Remaining buffer after executing full statements.
     */
    private function process_sql_buffer( $buffer, $wpdb, $final, JMigrate_Logger_Interface $logger, $job_id = null, &$processed = 0, &$next_tick = 0 ) {
        $buffer          = (string) $buffer;
        $length          = strlen( $buffer );
        $statement_start = 0;
        $in_string       = false;
        $string_char     = '';
        $in_line_comment = false;
        $in_block_comment = false;

        for ( $i = 0; $i < $length; $i++ ) {
            $char      = $buffer[ $i ];
            $next_char = ( $i + 1 < $length ) ? $buffer[ $i + 1 ] : '';

            if ( $in_line_comment ) {
                if ( "\n" === $char ) {
                    $in_line_comment = false;
                    $statement_start = $i + 1;
                }
                continue;
            }

            if ( $in_block_comment ) {
                if ( '*' === $char && '/' === $next_char ) {
                    $in_block_comment = false;
                    $i++;
                }
                continue;
            }

            if ( $in_string ) {
                if ( '\\' === $char ) {
                    $i++;
                    continue;
                }

                if ( $char === $string_char ) {
                    $in_string   = false;
                    $string_char = '';
                }

                continue;
            }

            if ( '-' === $char && '-' === $next_char ) {
                $in_line_comment = true;
                $i++;
                continue;
            }

            if ( '#' === $char ) {
                $in_line_comment = true;
                continue;
            }

            if ( '/' === $char && '*' === $next_char ) {
                $in_block_comment = true;
                $i++;
                continue;
            }

            if ( '\'' === $char || '"' === $char ) {
                $in_string   = true;
                $string_char = $char;
                continue;
            }

           if ( ';' === $char ) {
                $statement = substr( $buffer, $statement_start, $i - $statement_start + 1 );
                $this->execute_sql_statement( $statement, $wpdb, $logger, $job_id );
                $processed++;

                if ( $job_id && $processed >= $next_tick ) {
                    $increment = 20 + min( 30, (int) floor( $processed / 100 ) * 5 );
                    JMigrate_Job_Manager::set_progress( $job_id, min( 55, $increment ) );
                    $next_tick = $processed + 100;
                }
                $statement_start = $i + 1;
            }
        }

        $remainder = ( $statement_start < $length ) ? substr( $buffer, $statement_start ) : '';

        if ( $final && '' !== trim( $remainder ) ) {
            $this->execute_sql_statement( $remainder, $wpdb, $logger, $job_id );
            $processed++;
            if ( $job_id ) {
                JMigrate_Job_Manager::set_progress( $job_id, 60 );
            }
            return '';
        }

        return $remainder;
    }

    /**
     * Executes a single SQL statement.
     *
     * @param string $statement SQL statement.
     * @param wpdb   $wpdb      WordPress database instance.
     */
    private function execute_sql_statement( $statement, $wpdb, JMigrate_Logger_Interface $logger, $job_id = null ) {
        $statement = trim( $statement );
        $statement = rtrim( $statement, ';' );
        $statement = trim( $statement );
        while ( isset( $statement[0], $statement[1] ) && '\\' === $statement[0] && in_array( $statement[1], [ 'n', 'r', 't' ], true ) ) {
            $statement = substr( $statement, 2 );
        }

        $statement = ltrim( $statement );

        if ( '' === $statement ) {
            return;
        }

        $statement = $this->maybe_replace_table_prefix( $statement, $logger );

        $result = $wpdb->query( $statement );
        if ( false === $result ) {
            $error   = $wpdb->last_error ? $wpdb->last_error : 'Unknown database error.';
            $preview = $this->get_statement_preview( $statement );
            $debug   = substr( $statement, 0, 200 );

            $logger->error( sprintf( 'Database import failure: %s', $preview ) );
            $logger->info( sprintf( 'Full statement (first 200 chars): %s', $debug ) );
            $logger->info( sprintf( 'Statement leading bytes (hex): %s', bin2hex( substr( $statement, 0, 32 ) ) ) );

            if ( $job_id ) {
                JMigrate_Job_Manager::update_job( $job_id, [
                    'status' => 'error',
                    'error'  => $preview,
                ] );
            }

            throw new JMigrate_Import_Exception( sprintf( 'Database import error: %s. Statement begins with: %s', $error, $preview ) );
        }
    }

    /**
     * Rewrites table prefixes to match the current installation when necessary.
     *
     * @param string                      $statement SQL statement.
     * @param JMigrate_Logger_Interface   $logger    Logger instance.
     * @return string
     */
    private function maybe_replace_table_prefix( $statement, JMigrate_Logger_Interface $logger = null ) {
        global $wpdb;

        $target_prefix = $wpdb->prefix;
        if ( '' === $target_prefix ) {
            return $statement;
        }

        if ( null === $this->source_table_prefix ) {
            if ( preg_match( '/`([a-z0-9_]+_)(?:options|posts|users|postmeta|terms|term_taxonomy|term_relationships|termmeta|comments|commentmeta|links)/i', $statement, $match ) ) {
                $this->source_table_prefix = $match[1];

                if ( $this->source_table_prefix !== $target_prefix && $logger && ! $this->logged_prefix_change ) {
                    $logger->info( sprintf( 'Remapping table prefix from %1$s to %2$s.', $this->source_table_prefix, $target_prefix ) );
                    $this->logged_prefix_change = true;
                }
            } else {
                return $statement;
            }
        }

        if ( ! $this->source_table_prefix || $this->source_table_prefix === $target_prefix ) {
            return $statement;
        }

        $pattern_backticked = '/`' . preg_quote( $this->source_table_prefix, '/' ) . '([a-zA-Z0-9_]+)`/';
        $replacement_backticked = '`' . $target_prefix . '$1`';
        $statement = preg_replace( $pattern_backticked, $replacement_backticked, $statement );

        $pattern_plain = '/\b' . preg_quote( $this->source_table_prefix, '/' ) . '([a-zA-Z0-9_]+)\b/';
        $replacement_plain = $target_prefix . '$1';
        $statement = preg_replace( $pattern_plain, $replacement_plain, $statement );

        return $statement;
    }

    /**
     * Returns the maximum database size (in bytes) that the PHP importer will attempt.
     *
     * @return int
     */
    private function get_max_php_import_bytes() {
        /**
         * Filter the maximum database size that the PHP importer will attempt.
         *
         * @param int $bytes Maximum bytes. Default 0 (no automatic size limit).
         */
        $default = 0;

        return (int) apply_filters( 'jmigrate_php_import_max_bytes', $default );
    }

    /**
     * Determines if the importer should delegate to the WP-CLI binary for the given archive.
     *
     * @param string $archive_path Absolute archive path.
     * @return bool
     */
    private function should_delegate_to_cli( $archive_path ) {
        $max_php_size = $this->get_max_php_import_bytes();
        if ( $max_php_size <= 0 ) {
            return false;
        }

        $size = filesize( $archive_path );
        if ( false === $size ) {
            return false;
        }

        return $size > $max_php_size;
    }

    /**
     * Determines whether the server can execute the WP-CLI binary.
     *
     * @return bool
     */
    private function can_use_wp_cli_binary() {
        return '' !== $this->locate_wp_cli_binary() && function_exists( 'proc_open' ) && function_exists( 'proc_close' );
    }

    /**
     * Locates the WP-CLI binary path.
     *
     * @return string
     */
    private function locate_wp_cli_binary() {
        static $cached = null;

        if ( null !== $cached ) {
            return $cached;
        }

        if ( defined( 'JMIGRATE_WP_CLI_BINARY' ) && file_exists( JMIGRATE_WP_CLI_BINARY ) ) {
            return $cached = wp_normalize_path( JMIGRATE_WP_CLI_BINARY );
        }

        $candidates = array_filter( array(
            getenv( 'WP_CLI_BIN' ),
            ABSPATH . 'wp',
            ABSPATH . 'wp-cli.phar',
            dirname( ABSPATH ) . '/wp',
            dirname( ABSPATH ) . '/wp-cli.phar',
            '/usr/local/bin/wp',
            '/usr/bin/wp',
        ) );

        /**
         * Filters the list of candidate WP-CLI binaries.
         *
         * @param string[] $candidates Candidate paths.
         */
        $candidates = apply_filters( 'jmigrate_wp_cli_binary_candidates', $candidates );

        foreach ( $candidates as $candidate ) {
            if ( empty( $candidate ) ) {
                continue;
            }

            $normalized = wp_normalize_path( $candidate );
            if ( file_exists( $normalized ) && is_executable( $normalized ) ) {
                return $cached = $normalized;
            }
        }

        if ( function_exists( 'shell_exec' ) ) {
            $which = shell_exec( 'command -v wp 2>/dev/null' );
            if ( $which ) {
                $normalized = wp_normalize_path( trim( $which ) );
                if ( file_exists( $normalized ) && is_executable( $normalized ) ) {
                    return $cached = $normalized;
                }
            }
        }

        return $cached = '';
    }

    /**
     * Runs the JMigrate import via the system WP-CLI binary.
     *
     * @param string                    $archive_path Archive path.
     * @param JMigrate_Logger_Interface $logger       Logger instance.
     */
    private function import_with_wp_cli_binary( $archive_path, JMigrate_Logger_Interface $logger ) {
        $binary = $this->locate_wp_cli_binary();
        if ( '' === $binary ) {
            throw new JMigrate_Import_Exception( 'WP-CLI binary could not be located on the server.' );
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $descriptor_spec = [
            0 => [ 'pipe', 'r' ],
            1 => [ 'pipe', 'w' ],
            2 => [ 'pipe', 'w' ],
        ];

        $command = escapeshellarg( $binary ) . ' jmigrate import --path=' . escapeshellarg( untrailingslashit( ABSPATH ) ) . ' --file=' . escapeshellarg( $archive_path );

        /**
         * Filters the WP-CLI command executed for imports.
         *
         * @param string $command      Command string.
         * @param string $archive_path Archive path.
         */
        $command = apply_filters( 'jmigrate_wp_cli_import_command', $command, $archive_path );

        $process = proc_open( $command, $descriptor_spec, $pipes, ABSPATH );
        if ( ! is_resource( $process ) ) {
            throw new JMigrate_Import_Exception( 'Failed to execute WP-CLI. The process could not be started.' );
        }

        fclose( $pipes[0] );
        $stdout = stream_get_contents( $pipes[1] );
        $stderr = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );

        $exit_code = proc_close( $process );

        $stdout_lines = preg_split( '/\r?\n/', (string) $stdout );
        foreach ( $stdout_lines as $line ) {
            $line = trim( $line );
            if ( '' !== $line ) {
                $logger->info( $line );
            }
        }

        if ( 0 !== $exit_code ) {
            $error_message = trim( (string) $stderr );
            if ( '' === $error_message ) {
                $error_message = trim( (string) $stdout );
            }

            if ( '' === $error_message ) {
                $error_message = sprintf( 'WP-CLI exited with code %d.', $exit_code );
            }

            throw new JMigrate_Import_Exception( $error_message );
        }
    }

    /**
     * Creates a short preview of a SQL statement for error messages.
     *
     * @param string $statement Raw SQL statement.
     * @return string
     */
    private function get_statement_preview( $statement ) {
        $statement = preg_replace( '/\s+/', ' ', trim( $statement ) );
        if ( strlen( $statement ) > 180 ) {
            $statement = substr( $statement, 0, 177 ) . '...';
        }

        return $statement;
    }

    /**
     * Expands a path that may include the home directory shortcut.
     *
     * @param string $path Raw path.
     * @return string
     */
    private function expand_user_path( $path ) {
        $path = (string) $path;
        if ( '' === $path ) {
            return $path;
        }

        if ( '~' === $path ) {
            $home = $this->get_home_directory();
            return $home ? $home : $path;
        }

        if ( 0 === strpos( $path, '~/' ) ) {
            $home = $this->get_home_directory();
            if ( $home ) {
                return trailingslashit( $home ) . ltrim( substr( $path, 2 ), '/\\' );
            }
        }

        return $path;
    }

    /**
     * Determines whether a path is absolute.
     *
     * @param string $path Path to evaluate.
     * @return bool
     */
    private function is_path_absolute( $path ) {
        if ( empty( $path ) ) {
            return false;
        }

        if ( '/' === $path[0] || '\\' === $path[0] ) {
            return true;
        }

        if ( strlen( $path ) > 1 && ':' === $path[1] ) {
            return true;
        }

        return false;
    }

    /**
     * Attempts to discover the current user's home directory.
     *
     * @return string
     */
    private function get_home_directory() {
        $home = getenv( 'HOME' );
        if ( $home ) {
            return rtrim( wp_normalize_path( $home ), '/\\' );
        }

        if ( function_exists( 'wp_get_home_path' ) ) {
            $path = wp_get_home_path();
            if ( $path ) {
                return rtrim( wp_normalize_path( $path ), '/\\' );
            }
        }

        return rtrim( wp_normalize_path( ABSPATH ), '/\\' );
    }
}
