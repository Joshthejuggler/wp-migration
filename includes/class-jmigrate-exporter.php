<?php
/**
 * Core export logic for JMigrate.
 */

if ( class_exists( 'JMigrate_Exporter' ) ) {
    return;
}

class JMigrate_Exporter {
    /**
     * Number of records retrieved per query chunk.
     */
    const QUERY_CHUNK_SIZE = 500;

    /**
     * Performs the migration export.
     *
     * @param string                    $permanent_url    Canonical (live) URL.
     * @param string                    $temporary_url    Temporary URL replacement.
     * @param string                    $requested_output Optional output file path.
     * @param JMigrate_Logger_Interface $logger           Logger for status messages.
     * @param bool                      $prefer_wp_cli    When true rely on WP-CLI helpers if available.
     *
     * @return string Absolute path to the generated archive.
     *
     * @throws JMigrate_Export_Exception When validation or export fails.
     */
    public function export( $permanent_url, $temporary_url, $requested_output, JMigrate_Logger_Interface $logger, $prefer_wp_cli = false ) {
        if ( ! $logger instanceof JMigrate_Logger_Interface ) {
            throw new JMigrate_Export_Exception( 'Invalid logger provided.' );
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $permanent = $this->normalize_url( $permanent_url );
        if ( empty( $permanent ) ) {
            throw new JMigrate_Export_Exception( 'Please provide a valid permanent URL (including scheme).' );
        }

        $temporary = $this->normalize_url( $temporary_url );
        if ( empty( $temporary ) ) {
            throw new JMigrate_Export_Exception( 'Please provide a valid temporary URL (including scheme).' );
        }

        if ( $permanent === $temporary ) {
            throw new JMigrate_Export_Exception( 'Permanent and temporary URLs must differ.' );
        }

        $output_path = $this->resolve_output_path( $requested_output );
        $working_dir = $this->create_working_directory();
        $database_sql = trailingslashit( $working_dir ) . 'database.sql';

        try {
            if ( $prefer_wp_cli && class_exists( '\\WP_CLI' ) ) {
                $logger->info( 'Generating database export via WP-CLI search-replace...' );
                $this->export_database_with_wp_cli( $permanent, $temporary, $database_sql );
            } else {
                $logger->info( 'Generating database export with internal engine...' );
                $this->export_database_with_php( $permanent, $temporary, $database_sql, $logger );
            }

            if ( ! file_exists( $database_sql ) ) {
                throw new JMigrate_Export_Exception( 'Database export failed. Export file missing.' );
            }

            $logger->info( 'Creating site archive. This may take a while...' );
            $this->create_archive( ABSPATH, $database_sql, $output_path );
        } catch ( \Exception $exception ) {
            $this->cleanup_directory( $working_dir );
            throw new JMigrate_Export_Exception( $exception->getMessage(), (int) $exception->getCode(), $exception );
        }

        $this->cleanup_directory( $working_dir );

        $logger->success( sprintf( 'Migration archive created: %s', $output_path ) );
        return $output_path;
    }

    /**
     * Normalizes and validates a URL input.
     *
     * @param string $url Raw URL from CLI/UI inputs.
     * @return string Normalized URL or empty string on failure.
     */
    public function normalize_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return '';
        }

        $url = untrailingslashit( $url );
        $parsed = wp_parse_url( $url );

        if ( empty( $parsed['scheme'] ) || ! in_array( strtolower( $parsed['scheme'] ), [ 'http', 'https' ], true ) ) {
            return '';
        }

        if ( empty( $parsed['host'] ) ) {
            return '';
        }

        $validated = filter_var( $url, FILTER_VALIDATE_URL );
        return $validated ? $validated : '';
    }

    /**
     * Resolves the final archive output path and ensures its directory exists.
     *
     * @param string $requested_path Optional path provided via CLI/UI.
     * @return string Absolute path to the archive file.
     */
    public function resolve_output_path( $requested_path ) {
        if ( ! empty( $requested_path ) ) {
            $path = $this->expand_user_path( $requested_path );

            if ( ! $this->is_path_absolute( $path ) ) {
                $cwd  = wp_normalize_path( getcwd() );
                $path = trailingslashit( $cwd ) . ltrim( $path, '/\\' );
            }

            $path      = wp_normalize_path( $path );
            $directory = trailingslashit( dirname( $path ) );

            if ( ! wp_mkdir_p( $directory ) ) {
                throw new JMigrate_Export_Exception( sprintf( 'Unable to create output directory: %s', $directory ) );
            }

            return $path;
        }

        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            throw new JMigrate_Export_Exception( sprintf( 'Unable to determine uploads directory: %s', $uploads['error'] ) );
        }

        $archive_dir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . 'jmigrate';

        if ( ! wp_mkdir_p( $archive_dir ) ) {
            throw new JMigrate_Export_Exception( sprintf( 'Unable to create archive directory: %s', $archive_dir ) );
        }

        $filename = sprintf( 'jmigrate-%s.zip', gmdate( 'Ymd-His' ) );
        return trailingslashit( $archive_dir ) . $filename;
    }

    /**
     * Creates a temporary working directory.
     *
     * @return string Absolute path to the working directory.
     */
    private function create_working_directory() {
        $base = wp_normalize_path( sys_get_temp_dir() );
        try {
            $random = bin2hex( random_bytes( 8 ) );
        } catch ( \Exception $exception ) {
            $random = dechex( wp_rand( 0, PHP_INT_MAX ) );
        }

        $unique = 'jmigrate-' . $random;
        $path   = trailingslashit( $base ) . $unique;

        if ( ! wp_mkdir_p( $path ) ) {
            throw new JMigrate_Export_Exception( sprintf( 'Unable to create temporary directory: %s', $path ) );
        }

        return $path;
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
     * Creates a zip archive containing the WordPress installation and database export.
     *
     * @param string $root_path     WordPress root path (ABSPATH).
     * @param string $database_file Path to the SQL export.
     * @param string $output_path   Destination archive path.
     */
    private function create_archive( $root_path, $database_file, $output_path ) {
        if ( ! class_exists( '\\ZipArchive' ) ) {
            throw new JMigrate_Export_Exception( 'The ZipArchive PHP extension is required to build the archive.' );
        }

        $zip = new \ZipArchive();
        if ( true !== $zip->open( $output_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
            throw new JMigrate_Export_Exception( sprintf( 'Unable to create archive at %s', $output_path ) );
        }

        $root_path              = trailingslashit( wp_normalize_path( $root_path ) );
        $output_path_real       = realpath( $output_path );
        $output_path_normalized = $output_path_real ? wp_normalize_path( $output_path_real ) : wp_normalize_path( $output_path );

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $root_path, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            /** @var \SplFileInfo $item */
            $real_path = $item->getRealPath();
            if ( false === $real_path ) {
                continue;
            }

            $normalized_path = wp_normalize_path( $real_path );

            if ( 0 === strpos( $normalized_path, $output_path_normalized ) ) {
                continue;
            }

            $relative_path = ltrim( substr( $normalized_path, strlen( $root_path ) ), '/\\' );

            if ( $item->isDir() ) {
                if ( '' !== $relative_path ) {
                    $zip->addEmptyDir( $relative_path );
                }
            } else {
                $zip->addFile( $normalized_path, $relative_path );
            }
        }

        $zip->addFile( wp_normalize_path( $database_file ), 'database.sql' );

        if ( ! $zip->close() ) {
            throw new JMigrate_Export_Exception( 'Failed to finalize the migration archive.' );
        }
    }

    /**
     * Invokes WP-CLI to generate a database export with replacements.
     *
     * @param string $permanent_url Permanent URL.
     * @param string $temporary_url Temporary URL.
     * @param string $database_file Destination file path.
     */
    private function export_database_with_wp_cli( $permanent_url, $temporary_url, $database_file ) {
        if ( ! class_exists( '\\WP_CLI' ) ) {
            throw new JMigrate_Export_Exception( 'WP-CLI is not available in this environment.' );
        }

        if ( ! function_exists( '\\WP_CLI\\Utils::esc_cmd' ) ) {
            throw new JMigrate_Export_Exception( 'WP-CLI utilities are not accessible.' );
        }

        $command = \WP_CLI\Utils::esc_cmd(
            'search-replace %s %s --export=%s --skip-columns=guid',
            $permanent_url,
            $temporary_url,
            $database_file
        );

        \WP_CLI::runcommand(
            $command,
            [
                'exit_error' => true,
            ]
        );
    }

    /**
     * Uses PHP to export the database and apply replacements.
     *
     * @param string                    $permanent_url Permanent URL.
     * @param string                    $temporary_url Temporary URL.
     * @param string                    $database_file Destination file path.
     * @param JMigrate_Logger_Interface $logger       Logger instance.
     */
    private function export_database_with_php( $permanent_url, $temporary_url, $database_file, JMigrate_Logger_Interface $logger ) {
        global $wpdb;

        $handle = fopen( $database_file, 'w' );
        if ( ! $handle ) {
            throw new JMigrate_Export_Exception( sprintf( 'Unable to create database export file: %s', $database_file ) );
        }

        fwrite( $handle, $this->build_sql_header() );

        $tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( empty( $tables ) ) {
            fclose( $handle );
            return;
        }

        foreach ( $tables as $table ) {
            $table = (string) $table;
            $logger->info( sprintf( 'Exporting table %s...', $table ) );

            $this->write_table_structure( $handle, $table, $wpdb );
            $this->write_table_data( $handle, $table, $wpdb, $permanent_url, $temporary_url );
        }

        fclose( $handle );
    }

    /**
     * Builds the SQL header content.
     *
     * @return string
     */
    private function build_sql_header() {
        $lines = [
            'SET sql_mode = "NO_AUTO_VALUE_ON_ZERO";',
            'SET time_zone = "+00:00";',
            '',
        ];

        return implode( "\n", $lines ) . "\n";
    }

    /**
     * Writes table structure to the export file.
     *
     * @param resource $handle File handle.
     * @param string   $table  Table name.
     * @param wpdb     $wpdb   WordPress database instance.
     */
    private function write_table_structure( $handle, $table, $wpdb ) {
        $table_sql_name = str_replace( '`', '``', $table );

        fwrite( $handle, sprintf( "DROP TABLE IF EXISTS `%s`;\n", $table_sql_name ) );

        $create = $wpdb->get_row( sprintf( 'SHOW CREATE TABLE `%s`', $table_sql_name ), ARRAY_N );
        if ( isset( $create[1] ) ) {
            fwrite( $handle, $create[1] . ";\n\n" );
        }
    }

    /**
     * Writes table data to the export file.
     *
     * @param resource $handle         File handle.
     * @param string   $table          Table name.
     * @param wpdb     $wpdb           WordPress database instance.
     * @param string   $permanent_url  Permanent URL.
     * @param string   $temporary_url  Temporary URL.
     */
    private function write_table_data( $handle, $table, $wpdb, $permanent_url, $temporary_url ) {
        $table_sql_name = str_replace( '`', '``', $table );
        $offset         = 0;

        do {
            $query = sprintf( 'SELECT * FROM `%s` LIMIT %d, %d', $table_sql_name, $offset, self::QUERY_CHUNK_SIZE );
            $rows  = $wpdb->get_results( $query, ARRAY_A );

            if ( empty( $rows ) ) {
                break;
            }

            $insert_rows = [];
            foreach ( $rows as $row ) {
                $values = [];
                foreach ( $row as $value ) {
                    if ( null === $value ) {
                        $values[] = 'NULL';
                        continue;
                    }

                    if ( is_bool( $value ) ) {
                        $values[] = $value ? '1' : '0';
                        continue;
                    }

                    $processed = $this->apply_replacements( $value, $permanent_url, $temporary_url );
                    $escaped   = $this->escape_sql_value( (string) $processed );
                    $values[]  = "'{$escaped}'";
                }

                $insert_rows[] = '(' . implode( ',', $values ) . ')';
            }

            if ( ! empty( $insert_rows ) ) {
                $statement = sprintf( 'INSERT INTO `%s` VALUES %s;\n', $table_sql_name, implode( ",\n", $insert_rows ) );
                fwrite( $handle, $statement );
            }

            $offset += self::QUERY_CHUNK_SIZE;
        } while ( count( $rows ) === self::QUERY_CHUNK_SIZE );

        fwrite( $handle, "\n" );
    }

    /**
     * Applies URL replacements, being mindful of serialized data.
     *
     * @param mixed  $value          Original value.
     * @param string $permanent_url  Permanent URL.
     * @param string $temporary_url  Temporary URL.
     * @return mixed Modified value.
     */
    private function apply_replacements( $value, $permanent_url, $temporary_url ) {
        if ( ! is_string( $value ) ) {
            return $value;
        }

        if ( is_serialized( $value ) ) {
            $unserialized = maybe_unserialize( $value );
            $replaced     = $this->replace_in_data( $unserialized, $permanent_url, $temporary_url );
            return maybe_serialize( $replaced );
        }

        return str_replace( $permanent_url, $temporary_url, $value );
    }

    /**
     * Recursively replaces URLs inside arrays/objects.
     *
     * @param mixed  $data           Data to process.
     * @param string $permanent_url  Permanent URL.
     * @param string $temporary_url  Temporary URL.
     * @return mixed Processed data.
     */
    private function replace_in_data( $data, $permanent_url, $temporary_url ) {
        if ( is_array( $data ) ) {
            foreach ( $data as $key => $value ) {
                $data[ $key ] = $this->replace_in_data( $value, $permanent_url, $temporary_url );
            }
            return $data;
        }

        if ( is_object( $data ) ) {
            foreach ( $data as $property => $value ) {
                $data->{$property} = $this->replace_in_data( $value, $permanent_url, $temporary_url );
            }
            return $data;
        }

        if ( is_string( $data ) ) {
            return str_replace( $permanent_url, $temporary_url, $data );
        }

        return $data;
    }

    /**
     * Escapes a value for inclusion in SQL output (without surrounding quotes).
     *
     * @param string $value Raw value.
     * @return string
     */
    private function escape_sql_value( $value ) {
        $replacements = [
            '\\' => '\\\\',
            "\0" => '\\0',
            "\n" => '\\n',
            "\r" => '\\r',
            "\x1a" => '\\Z',
            "'" => "\\'",
        ];

        return strtr( $value, $replacements );
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
     * Determines whether a given path is absolute.
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
     * Returns the best guess for the current user's home directory.
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
