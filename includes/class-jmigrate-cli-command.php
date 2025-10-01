<?php
/**
 * JMigrate WP-CLI command definitions.
 */
if ( class_exists( 'JMigrate_CLI_Command' ) ) {
    return;
}

class JMigrate_CLI_Command {
    /**
     * @var JMigrate_Exporter
     */
    private $exporter;

    /**
     * @var JMigrate_Importer
     */
    private $importer;

    public function __construct() {
        $this->exporter = new JMigrate_Exporter();
        $this->importer = new JMigrate_Importer();
    }

    /**
     * Outputs a confirmation message that the plugin is active.
     */
    public function hello() {
        \WP_CLI::log( 'JMigrate plugin is active!' );
    }

    /**
     * Creates a migration archive of the full WordPress installation.
     *
     * ## OPTIONS
     *
     * --permanent=<url>
     * : The canonical (production) URL to replace.
     *
     * --temporary=<url>
     * : The temporary URL that should replace the permanent URL inside the export.
     *
     * [--output=<file>]
     * : Optional absolute or relative path for the resulting zip archive. Defaults to wp-content/uploads/jmigrate.
     *
     * ## EXAMPLES
     *
     *     wp jmigrate export --permanent=https://example.com --temporary=https://staging.example.com
     *     wp jmigrate export --permanent=https://example.com --temporary=https://staging.example.com --output=/tmp/jmigrate.zip
     *
     * @subcommand export
     *
     * @param array $args Positionals (unused).
     * @param array $assoc_args Associative arguments.
     */
    public function export( $args, $assoc_args ) {
        $assoc_args = wp_parse_args(
            $assoc_args,
            [
                'permanent' => '',
                'temporary' => '',
                'output'    => '',
            ]
        );

        $logger = new JMigrate_CLI_Logger();

        try {
            $this->exporter->export(
                $assoc_args['permanent'],
                $assoc_args['temporary'],
                $assoc_args['output'],
                $logger,
                true
            );
        } catch ( JMigrate_Export_Exception $exception ) {
            \WP_CLI::error( $exception->getMessage() );
        } catch ( \Throwable $throwable ) {
            \WP_CLI::error( $throwable->getMessage() );
        }
    }

    /**
     * Imports a JMigrate archive into the current installation.
     *
     * ## OPTIONS
     *
     * --file=<path>
     * : Absolute or relative path to the JMigrate archive (.zip).
     *
     * [--files=<scope>]
     * : File handling strategy: all (default), content (wp-content only), skip (database only).
     *
     * ## EXAMPLES
     *
     *     wp jmigrate import --file=/tmp/jmigrate-20240101.zip
     *
     * @subcommand import
     *
     * @param array $args Positionals (unused).
     * @param array $assoc_args Associative arguments.
     */
    public function import( $args, $assoc_args ) {
        $assoc_args = wp_parse_args(
            $assoc_args,
            [
                'file'  => '',
                'files' => 'all',
            ]
        );

        if ( empty( $assoc_args['file'] ) ) {
            \WP_CLI::error( 'Please provide the path to the archive using --file.' );
        }

        $files_strategy = strtolower( $assoc_args['files'] );
        if ( ! in_array( $files_strategy, [ 'all', 'content', 'skip' ], true ) ) {
            \WP_CLI::error( 'Invalid --files option. Use all, content, or skip.' );
        }

        $logger = new JMigrate_CLI_Logger();

        try {
            $this->importer->import(
                $assoc_args['file'],
                $logger,
                true,
                $files_strategy
            );
        } catch ( JMigrate_Import_Exception $exception ) {
            \WP_CLI::error( $exception->getMessage() );
        } catch ( \Throwable $throwable ) {
            \WP_CLI::error( $throwable->getMessage() );
        }
    }
}
