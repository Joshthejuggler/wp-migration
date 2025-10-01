<?php
/**
 * Provides an administrative interface for running JMigrate exports/imports.
 */
if ( class_exists( 'JMigrate_Admin_Page' ) ) {
    return;
}

class JMigrate_Admin_Page {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_jmigrate_start_import', [ $this, 'ajax_start_import' ] );
        add_action( 'wp_ajax_jmigrate_get_import_status', [ $this, 'ajax_get_import_status' ] );
        add_action( 'wp_ajax_jmigrate_run_import_job', [ __CLASS__, 'ajax_run_import_job' ] );
        add_action( 'wp_ajax_nopriv_jmigrate_run_import_job', [ __CLASS__, 'ajax_run_import_job' ] );
        add_action( 'wp_ajax_jmigrate_delete_archive', [ $this, 'ajax_delete_archive' ] );
        add_action( 'wp_ajax_jmigrate_refresh_archives', [ $this, 'ajax_refresh_archives' ] );
    }

    /**
     * Adds the JMigrate page under the Tools menu.
     */
    public function register_page() {
        add_management_page(
            __( 'JMigrate', 'jmigrate' ),
            __( 'JMigrate', 'jmigrate' ),
            'manage_options',
            'jmigrate',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Enqueues admin assets for the JMigrate tools screen.
     */
    public function enqueue_assets( $hook ) {
        if ( 'tools_page_jmigrate' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'jmigrate-admin',
            JMIGRATE_PLUGIN_URL . 'assets/css/jmigrate-admin.css',
            [],
            JMIGRATE_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'jmigrate-admin',
            JMIGRATE_PLUGIN_URL . 'assets/js/jmigrate-admin.js',
            [ 'jquery' ],
            JMIGRATE_PLUGIN_VERSION,
            true
        );

        wp_localize_script(
            'jmigrate-admin',
            'jmigrateAdmin',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'jmigrate_admin' ),
                'runNonce' => wp_create_nonce( 'jmigrate_run_import_job' ),
                'strings' => [
                    'starting'     => __( 'Starting import…', 'jmigrate' ),
                    'running'      => __( 'Import in progress…', 'jmigrate' ),
                    'completed'    => __( 'Import completed.', 'jmigrate' ),
                    'failed'       => __( 'Import failed.', 'jmigrate' ),
                    'genericError' => __( 'Unable to process the request.', 'jmigrate' ),
                ],
            ]
        );
    }

    /**
     * Handles AJAX requests to start an import job.
     */
    public function ajax_start_import() {
        check_ajax_referer( 'jmigrate_admin' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'jmigrate' ) );
        }

        $archive_path = isset( $_POST['jmigrate_import_path'] ) ? sanitize_text_field( wp_unslash( $_POST['jmigrate_import_path'] ) ) : '';
        $selected     = isset( $_POST['jmigrate_archive_select'] ) ? sanitize_text_field( wp_unslash( $_POST['jmigrate_archive_select'] ) ) : '';
        $cleanup_archive = isset( $_POST['jmigrate_cleanup_archive'] ) ? true : false;
        $files_strategy = isset( $_POST['jmigrate_files_strategy'] ) ? sanitize_text_field( wp_unslash( $_POST['jmigrate_files_strategy'] ) ) : 'content';
        if ( ! in_array( $files_strategy, [ 'all', 'content', 'skip' ], true ) ) {
            $files_strategy = 'all';
        }

        $import_errors = [];

        if ( ! empty( $_FILES['jmigrate_import_file']['name'] ) ) {
            if ( ! function_exists( 'wp_handle_upload' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            $handled = wp_handle_upload(
                $_FILES['jmigrate_import_file'],
                [
                    'test_form' => false,
                    'mimes'     => [
                        'zip'  => 'application/zip',
                        'gz'   => 'application/x-gzip',
                        'gzip' => 'application/gzip',
                        'x-zip-compressed' => 'application/x-zip-compressed',
                    ],
                ]
            );

            if ( isset( $handled['error'] ) && $handled['error'] ) {
                $import_errors[] = $handled['error'];
            } elseif ( isset( $handled['file'] ) ) {
                $uploaded_path = wp_normalize_path( $handled['file'] );
                $target_dir    = jmigrate_ensure_archive_directory();

                if ( '' === $target_dir ) {
                    $import_errors[] = __( 'Unable to prepare the JMigrate uploads directory.', 'jmigrate' );
                } else {
                    $filename    = basename( $uploaded_path );
                    $unique_name = wp_unique_filename( $target_dir, $filename );
                    $target_path = trailingslashit( $target_dir ) . $unique_name;

                    if ( ! wp_mkdir_p( $target_dir ) ) {
                        $import_errors[] = __( 'Unable to prepare the JMigrate uploads directory.', 'jmigrate' );
                    } elseif ( ! @rename( $uploaded_path, $target_path ) ) {
                        if ( ! @copy( $uploaded_path, $target_path ) || ! @unlink( $uploaded_path ) ) {
                            $import_errors[] = __( 'Failed to move the uploaded archive into the JMigrate directory.', 'jmigrate' );
                        } else {
                            $archive_path = $target_path;
                        }
                    } else {
                        $archive_path = $target_path;
                    }
                }
            }
        }

        if ( empty( $archive_path ) && ! empty( $selected ) ) {
            $archive_path = $selected;
        }

        if ( empty( $archive_path ) ) {
            $import_errors[] = __( 'Please upload an archive or choose one from the list.', 'jmigrate' );
        }

        $archive_path = wp_normalize_path( $archive_path );

        if ( ! empty( $import_errors ) ) {
            wp_send_json_error( implode( '\n', $import_errors ) );
        }

        $job_id = JMigrate_Job_Manager::create_job(
            [
                'archive'  => $archive_path,
                'files'    => $files_strategy,
                'cleanup'  => $cleanup_archive,
                'messages' => [],
            ]
        );

        JMigrate_Job_Manager::append_message( $job_id, 'info', __( 'Import queued…', 'jmigrate' ) );

        wp_send_json_success(
            [
                'job_id' => $job_id,
            ]
        );
    }

    /**
     * Returns the status of an import job.
     */
    public function ajax_get_import_status() {
        check_ajax_referer( 'jmigrate_admin' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'jmigrate' ) );
        }

        $job_id = isset( $_REQUEST['job_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['job_id'] ) ) : '';
        if ( ! $job_id ) {
            wp_send_json_error( __( 'Job not found.', 'jmigrate' ) );
        }

        $job = JMigrate_Job_Manager::get_job( $job_id );
        if ( ! $job ) {
            wp_send_json_error( __( 'Job not found.', 'jmigrate' ) );
        }

        $messages = [];
        if ( ! empty( $job['messages'] ) && is_array( $job['messages'] ) ) {
            foreach ( $job['messages'] as $entry ) {
                $messages[] = [
                    'type'    => isset( $entry['type'] ) ? sanitize_key( $entry['type'] ) : 'info',
                    'message' => isset( $entry['message'] ) ? wp_strip_all_tags( (string) $entry['message'] ) : '',
                ];
            }
        }

        $status = isset( $job['status'] ) ? $job['status'] : 'unknown';
        $status_label = '';
        switch ( $status ) {
            case 'running':
                $status_label = __( 'Import in progress…', 'jmigrate' );
                break;
            case 'success':
                $status_label = __( 'Import completed.', 'jmigrate' );
                break;
            case 'error':
                $status_label = __( 'Import failed.', 'jmigrate' );
                break;
        }

        wp_send_json_success(
            [
                'status'       => $status,
                'status_label' => $status_label,
                'progress'     => isset( $job['progress'] ) ? (int) $job['progress'] : 0,
                'messages'     => $messages,
                'error'        => isset( $job['error'] ) ? wp_strip_all_tags( (string) $job['error'] ) : '',
            ]
        );
    }

    /**
     * Executes a queued import job in the background.
     */
    public static function ajax_run_import_job() {
        check_ajax_referer( 'jmigrate_run_import_job' );

        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        if ( ! $job_id ) {
            wp_send_json_error( __( 'Job not found.', 'jmigrate' ) );
        }

        $job = JMigrate_Job_Manager::get_job( $job_id );
        if ( ! $job ) {
            wp_send_json_error( __( 'Job not found.', 'jmigrate' ) );
        }

        $logger   = new JMigrate_Job_Logger( $job_id );
        $importer = new JMigrate_Importer();

        JMigrate_Job_Manager::append_message( $job_id, 'info', __( 'Beginning import…', 'jmigrate' ) );

        try {
            if ( empty( $job['archive'] ) || ! file_exists( $job['archive'] ) ) {
                throw new JMigrate_Import_Exception( __( 'Archive could not be found on the server.', 'jmigrate' ) );
            }

            $importer->import( $job['archive'], $logger, false, $job['files'], $job_id );
        } catch ( JMigrate_Import_Exception $exception ) {
            JMigrate_Job_Manager::append_message( $job_id, 'error', $exception->getMessage() );
            JMigrate_Job_Manager::update_job(
                $job_id,
                [
                    'status' => 'error',
                    'error'  => $exception->getMessage(),
                    'progress' => 100,
                ]
            );
            wp_send_json_error( $exception->getMessage() );
        } catch ( \Throwable $throwable ) {
            JMigrate_Job_Manager::append_message( $job_id, 'error', $throwable->getMessage() );
            JMigrate_Job_Manager::update_job(
                $job_id,
                [
                    'status' => 'error',
                    'error'  => $throwable->getMessage(),
                    'progress' => 100,
                ]
            );
            wp_send_json_error( $throwable->getMessage() );
        }

        JMigrate_Job_Manager::append_message( $job_id, 'success', __( 'Import completed successfully.', 'jmigrate' ) );
        
        // Handle archive cleanup if requested
        if ( ! empty( $job['cleanup'] ) && $job['cleanup'] && file_exists( $job['archive'] ) ) {
            $jmigrate_dir = jmigrate_get_archive_directory();
            // Security check - only delete files in jmigrate directory
            if ( ! empty( $jmigrate_dir ) && 0 === strpos( $job['archive'], $jmigrate_dir ) ) {
                if ( @unlink( $job['archive'] ) ) {
                    JMigrate_Job_Manager::append_message( $job_id, 'info', __( 'Archive file deleted successfully.', 'jmigrate' ) );
                } else {
                    JMigrate_Job_Manager::append_message( $job_id, 'warning', __( 'Failed to delete archive file.', 'jmigrate' ) );
                }
            }
        }
        
        JMigrate_Job_Manager::update_job( $job_id, [ 'status' => 'success', 'progress' => 100 ] );

        wp_send_json_success();
    }

    /**
     * Handles AJAX requests to delete an archive file.
     */
    public function ajax_delete_archive() {
        check_ajax_referer( 'jmigrate_admin' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'jmigrate' ) );
        }

        $archive_path = isset( $_POST['archive_path'] ) ? sanitize_text_field( wp_unslash( $_POST['archive_path'] ) ) : '';
        if ( ! $archive_path ) {
            wp_send_json_error( __( 'No archive path provided.', 'jmigrate' ) );
        }

        $archive_path = wp_normalize_path( $archive_path );
        
        // Security check - ensure file is in jmigrate directory
        $jmigrate_dir = jmigrate_get_archive_directory();
        if ( empty( $jmigrate_dir ) || 0 !== strpos( $archive_path, $jmigrate_dir ) ) {
            wp_send_json_error( __( 'Invalid archive path.', 'jmigrate' ) );
        }

        if ( ! file_exists( $archive_path ) ) {
            wp_send_json_error( __( 'Archive file not found.', 'jmigrate' ) );
        }

        if ( ! @unlink( $archive_path ) ) {
            wp_send_json_error( __( 'Failed to delete archive file.', 'jmigrate' ) );
        }

        wp_send_json_success( [ 'message' => __( 'Archive deleted successfully.', 'jmigrate' ) ] );
    }

    /**
     * Handles AJAX requests to refresh the archive list.
     */
    public function ajax_refresh_archives() {
        check_ajax_referer( 'jmigrate_admin' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'jmigrate' ) );
        }

        $archives = $this->discover_archives();
        $archive_list = [];
        
        foreach ( $archives as $archive_path ) {
            $filename = basename( $archive_path );
            $filesize = file_exists( $archive_path ) ? size_format( filesize( $archive_path ) ) : 'Unknown';
            $modified = file_exists( $archive_path ) ? date( 'Y-m-d H:i:s', filemtime( $archive_path ) ) : 'Unknown';
            
            $archive_list[] = [
                'path' => $archive_path,
                'filename' => $filename,
                'size' => $filesize,
                'modified' => $modified,
                'download_url' => $this->get_download_url_for_path( $archive_path )
            ];
        }

        wp_send_json_success( [ 'archives' => $archive_list ] );
    }

    /**
     * Renders the admin page and handles form submissions.
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'jmigrate' ) );
        }

        $permanent_value = isset( $_POST['jmigrate_permanent_url'] ) ? wp_unslash( $_POST['jmigrate_permanent_url'] ) : '';
        $temporary_value = isset( $_POST['jmigrate_temporary_url'] ) ? wp_unslash( $_POST['jmigrate_temporary_url'] ) : '';
        $output_value    = isset( $_POST['jmigrate_output_path'] ) ? wp_unslash( $_POST['jmigrate_output_path'] ) : '';

        $import_path_value   = isset( $_POST['jmigrate_import_path'] ) ? wp_unslash( $_POST['jmigrate_import_path'] ) : '';
        $import_selected     = isset( $_POST['jmigrate_archive_select'] ) ? wp_unslash( $_POST['jmigrate_archive_select'] ) : '';

        $export_messages = [];
        $export_errors   = [];
        $archive_to      = '';

        $files_strategy = isset( $_POST['jmigrate_files_strategy'] ) ? sanitize_text_field( wp_unslash( $_POST['jmigrate_files_strategy'] ) ) : 'content';
        if ( ! in_array( $files_strategy, [ 'all', 'content', 'skip' ], true ) ) {
            $files_strategy = 'all';
        }

        $import_messages = [];
        $import_errors   = [];
        $import_success  = false;

        if ( isset( $_POST['jmigrate_run_export'] ) ) {
            check_admin_referer( 'jmigrate_run_export' );

            $exporter = new JMigrate_Exporter();
            $logger   = new JMigrate_Array_Logger();

            try {
                $archive_to = $exporter->export(
                    $permanent_value,
                    $temporary_value,
                    $output_value,
                    $logger,
                    false
                );
            } catch ( JMigrate_Export_Exception $exception ) {
                $export_errors[] = $exception->getMessage();
            } catch ( \Exception $exception ) {
                $export_errors[] = $exception->getMessage();
            }

            $export_messages = $logger->all();
        }

        if ( isset( $_POST['jmigrate_run_import'] ) && ! wp_doing_ajax() ) {
            check_admin_referer( 'jmigrate_run_import' );

            $archive_path = $import_path_value;
            $cleanup_archive = isset( $_POST['jmigrate_cleanup_archive'] ) ? true : false;
            $uploaded_path = '';

            if ( ! empty( $_FILES['jmigrate_import_file']['name'] ) ) {
                if ( ! function_exists( 'wp_handle_upload' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }

                $handled = wp_handle_upload(
                    $_FILES['jmigrate_import_file'],
                    [
                        'test_form' => false,
                        'mimes'     => [
                            'zip'  => 'application/zip',
                            'gz'   => 'application/x-gzip',
                            'gzip' => 'application/gzip',
                            'x-zip-compressed' => 'application/x-zip-compressed',
                        ],
                    ]
                );

                if ( isset( $handled['error'] ) && $handled['error'] ) {
                    $import_errors[] = $handled['error'];
                } elseif ( isset( $handled['file'] ) ) {
                    $uploaded_path = wp_normalize_path( $handled['file'] );
                    $target_dir    = jmigrate_ensure_archive_directory();

                    if ( '' === $target_dir ) {
                        $import_errors[] = __( 'Unable to prepare the JMigrate uploads directory.', 'jmigrate' );
                    } else {
                        $filename    = basename( $uploaded_path );
                        $unique_name = wp_unique_filename( $target_dir, $filename );
                        $target_path = trailingslashit( $target_dir ) . $unique_name;

                        if ( ! wp_mkdir_p( $target_dir ) ) {
                            $import_errors[] = __( 'Unable to prepare the JMigrate uploads directory.', 'jmigrate' );
                        } elseif ( ! @rename( $uploaded_path, $target_path ) ) {
                            if ( ! @copy( $uploaded_path, $target_path ) || ! @unlink( $uploaded_path ) ) {
                                $import_errors[] = __( 'Failed to move the uploaded archive into the JMigrate directory.', 'jmigrate' );
                            } else {
                                $uploaded_path = $target_path;
                            }
                        } else {
                            $uploaded_path = $target_path;
                        }

                        if ( empty( $import_errors ) && '' !== $uploaded_path ) {
                            $archive_path      = $uploaded_path;
                            $import_path_value = $archive_path;
                            $import_selected   = $archive_path;
                        }
                    }
                }
            }

            if ( empty( $archive_path ) && ! empty( $import_selected ) ) {
                $archive_path = $import_selected;
            }

            if ( empty( $archive_path ) ) {
                $import_errors[] = __( 'Please upload an archive or choose one from the list.', 'jmigrate' );
            }

            if ( empty( $import_errors ) && ! empty( $archive_path ) ) {
                $archive_path = wp_normalize_path( $archive_path );
                $importer     = new JMigrate_Importer();
                $logger       = new JMigrate_Array_Logger();

                try {
                    $importer->import( $archive_path, $logger, false, $files_strategy );
                    $import_success = true;
                    
                    // Handle archive cleanup if requested and import was successful
                    if ( $cleanup_archive && file_exists( $archive_path ) ) {
                        $jmigrate_dir = jmigrate_get_archive_directory();
                        // Security check - only delete files in jmigrate directory
                        if ( ! empty( $jmigrate_dir ) && 0 === strpos( $archive_path, $jmigrate_dir ) ) {
                            if ( @unlink( $archive_path ) ) {
                                $logger->info( __( 'Archive file deleted successfully.', 'jmigrate' ) );
                            } else {
                                $logger->warning( __( 'Failed to delete archive file.', 'jmigrate' ) );
                            }
                        }
                    }
                } catch ( JMigrate_Import_Exception $exception ) {
                    $import_errors[] = $exception->getMessage();
                } catch ( \Throwable $throwable ) {
                    $import_errors[] = $throwable->getMessage();
                }

                $import_messages = $logger->all();
            }
        }

        $available_archives = $this->discover_archives();

        $this->render_template(
            [
                'permanent_value'   => $permanent_value,
                'temporary_value'   => $temporary_value,
                'output_value'      => $output_value,
                'export_messages'   => $export_messages,
                'export_errors'     => $export_errors,
                'archive_to'        => $archive_to,
                'archive_download' => $this->get_download_url_for_path( $archive_to ),
                'import_path_value' => $import_path_value,
                'import_messages'   => $import_messages,
                'import_errors'     => $import_errors,
                'import_success'    => $import_success,
                'available_archives'=> $available_archives,
                'import_selected'   => $import_selected,
                'files_strategy'    => $files_strategy,
            ]
        );
    }

    /**
     * Outputs the page markup.
     *
     * @param array $context Display data.
     */
    private function render_template( array $context ) {
        ?>
        <div class="wrap jmigrate-admin">
            <h1><?php esc_html_e( 'JMigrate Tools', 'jmigrate' ); ?></h1>

            <div class="jmigrate-section">
                <h2><?php esc_html_e( 'Export', 'jmigrate' ); ?></h2>
                <p><?php esc_html_e( 'Create a migration archive that replaces your permanent URL with a temporary one.', 'jmigrate' ); ?></p>

                <?php $this->render_notices( $context['export_errors'], $context['export_messages'] ); ?>

                <?php if ( $context['archive_to'] ) : ?>
                    <div class="notice notice-success">
                        <p>
                            <?php
                            printf(
                                /* translators: %s: archive path */
                                esc_html__( 'Archive saved to: %s', 'jmigrate' ),
                                esc_html( $context['archive_to'] )
                            );
                            ?>
                        </p>
                        <?php if ( $context['archive_download'] ) : ?>
                            <p>
                                <a href="<?php echo esc_url( $context['archive_download'] ); ?>" class="button button-secondary" target="_blank" rel="noopener noreferrer">
                                    <?php esc_html_e( 'Download Archive', 'jmigrate' ); ?>
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" id="jmigrate-export-form">
                    <?php wp_nonce_field( 'jmigrate_run_export' ); ?>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="jmigrate_permanent_url"><?php esc_html_e( 'Permanent URL', 'jmigrate' ); ?></label>
                                </th>
                                <td>
                                    <input type="url" class="regular-text" id="jmigrate_permanent_url" name="jmigrate_permanent_url" value="<?php echo esc_attr( $context['permanent_value'] ); ?>" placeholder="https://example.com" required>
                                    <p class="description"><?php esc_html_e( 'The live/canonical URL that exists in the database.', 'jmigrate' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="jmigrate_temporary_url"><?php esc_html_e( 'Temporary URL', 'jmigrate' ); ?></label>
                                </th>
                                <td>
                                    <input type="url" class="regular-text" id="jmigrate_temporary_url" name="jmigrate_temporary_url" value="<?php echo esc_attr( $context['temporary_value'] ); ?>" placeholder="https://temporary.example.com" required>
                                    <p class="description"><?php esc_html_e( 'The URL that should replace the permanent URL inside the exported database.', 'jmigrate' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="jmigrate_output_path"><?php esc_html_e( 'Output Path (optional)', 'jmigrate' ); ?></label>
                                </th>
                                <td>
                                    <input type="text" class="regular-text code" id="jmigrate_output_path" name="jmigrate_output_path" value="<?php echo esc_attr( $context['output_value'] ); ?>" placeholder="/absolute/path/to/jmigrate.zip">
                                    <p class="description"><?php esc_html_e( 'Leave blank to save in wp-content/uploads/jmigrate/. Relative paths are resolved from the current working directory.', 'jmigrate' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php submit_button( __( 'Run Export', 'jmigrate' ), 'primary', 'jmigrate_run_export' ); ?>
                </form>
            </div>

            <hr style="margin: 40px 0;">

            <div class="jmigrate-section">
                <h2><?php esc_html_e( 'Import', 'jmigrate' ); ?></h2>
                <p><?php esc_html_e( 'Import a JMigrate archive into this site. Ensure the archive was created with JMigrate and that wp-config.php stays configured for this environment.', 'jmigrate' ); ?></p>

                <?php
                if ( $context['import_success'] ) {
                    echo '<div class="notice notice-success"><p>' . esc_html__( 'Import completed successfully.', 'jmigrate' ) . '</p></div>';
                }

                $this->render_notices( $context['import_errors'], $context['import_messages'] );
                ?>

                <div class="jmigrate-progress" id="jmigrate-progress" hidden>
                    <div class="jmigrate-progress__bar"><span id="jmigrate-progress-bar"></span></div>
                    <p class="jmigrate-progress__status" id="jmigrate-progress-status"></p>
                    <ul class="jmigrate-progress__log" id="jmigrate-progress-log"></ul>
                </div>

                <form method="post" enctype="multipart/form-data" id="jmigrate-import-form">
                    <?php wp_nonce_field( 'jmigrate_run_import' ); ?>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="jmigrate_import_path"><?php esc_html_e( 'Archive Path', 'jmigrate' ); ?></label>
                                </th>
                                <td>
                                    <input type="text" class="regular-text code" id="jmigrate_import_path" name="jmigrate_import_path" value="<?php echo esc_attr( $context['import_path_value'] ); ?>" placeholder="/absolute/path/to/jmigrate.zip">
                                    <p class="description"><?php esc_html_e( 'Provide the full path to the archive on the server. Supports ~ for the home directory.', 'jmigrate' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="jmigrate_import_file"><?php esc_html_e( 'Upload Archive', 'jmigrate' ); ?></label>
                                </th>
                                <td>
                                    <input type="file" id="jmigrate_import_file" name="jmigrate_import_file" accept=".zip">
                                    <p class="description"><?php esc_html_e( 'Upload a JMigrate zip archive directly from your computer.', 'jmigrate' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="jmigrate_files_strategy"><?php esc_html_e( 'Files To Restore', 'jmigrate' ); ?></label>
                                </th>
                                <td>
                                    <select id="jmigrate_files_strategy" name="jmigrate_files_strategy">
                                        <option value="all" <?php selected( $context['files_strategy'], 'all' ); ?>><?php esc_html_e( 'Database and all files', 'jmigrate' ); ?></option>
                                        <option value="content" <?php selected( $context['files_strategy'], 'content' ); ?>><?php esc_html_e( 'Database and wp-content only', 'jmigrate' ); ?></option>
                                        <option value="skip" <?php selected( $context['files_strategy'], 'skip' ); ?>><?php esc_html_e( 'Database only (skip files)', 'jmigrate' ); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Choose whether to copy all files, wp-content only, or skip file synchronization entirely.', 'jmigrate' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="jmigrate_cleanup_archive"><?php esc_html_e( 'After Import', 'jmigrate' ); ?></label>
                                </th>
                                <td>
                                    <label for="jmigrate_cleanup_archive">
                                        <input type="checkbox" id="jmigrate_cleanup_archive" name="jmigrate_cleanup_archive" value="1">
                                        <?php esc_html_e( 'Delete archive file after successful import', 'jmigrate' ); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'Automatically remove the archive file once the import completes successfully to save disk space.', 'jmigrate' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Archive Management', 'jmigrate' ); ?></th>
                                <td>
                                    <div id="jmigrate-archive-management">
                                        <div style="margin-bottom: 10px;">
                                            <select name="jmigrate_archive_select" id="jmigrate_archive_select" style="width: 300px;">
                                                <option value=""><?php esc_html_e( 'Select an archive found in uploads/jmigrate', 'jmigrate' ); ?></option>
                                                <?php foreach ( $context['available_archives'] as $archive ) : ?>
                                                    <option value="<?php echo esc_attr( $archive ); ?>" <?php selected( $context['import_selected'], $archive ); ?>><?php echo esc_html( basename( $archive ) ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" id="jmigrate-refresh-archives" class="button button-secondary"><?php esc_html_e( 'Refresh', 'jmigrate' ); ?></button>
                                        </div>
                                        
                                        <?php if ( ! empty( $context['available_archives'] ) ) : ?>
                                        <div id="jmigrate-archive-list">
                                            <h4><?php esc_html_e( 'Available Archives:', 'jmigrate' ); ?></h4>
                                            <table class="widefat striped" style="max-width: 600px;">
                                                <thead>
                                                    <tr>
                                                        <th><?php esc_html_e( 'Filename', 'jmigrate' ); ?></th>
                                                        <th><?php esc_html_e( 'Size', 'jmigrate' ); ?></th>
                                                        <th><?php esc_html_e( 'Modified', 'jmigrate' ); ?></th>
                                                        <th><?php esc_html_e( 'Actions', 'jmigrate' ); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ( $context['available_archives'] as $archive_path ) : 
                                                        $filename = basename( $archive_path );
                                                        $filesize = file_exists( $archive_path ) ? size_format( filesize( $archive_path ) ) : 'Unknown';
                                                        $modified = file_exists( $archive_path ) ? date( 'Y-m-d H:i:s', filemtime( $archive_path ) ) : 'Unknown';
                                                        $download_url = $this->get_download_url_for_path( $archive_path );
                                                    ?>
                                                    <tr data-archive="<?php echo esc_attr( $archive_path ); ?>">
                                                        <td><?php echo esc_html( $filename ); ?></td>
                                                        <td><?php echo esc_html( $filesize ); ?></td>
                                                        <td><?php echo esc_html( $modified ); ?></td>
                                                        <td>
                                                            <?php if ( $download_url ) : ?>
                                                                <a href="<?php echo esc_url( $download_url ); ?>" class="button button-small" target="_blank"><?php esc_html_e( 'Download', 'jmigrate' ); ?></a>
                                                            <?php endif; ?>
                                                            <button type="button" class="button button-small jmigrate-delete-archive" data-archive="<?php echo esc_attr( $archive_path ); ?>"><?php esc_html_e( 'Delete', 'jmigrate' ); ?></button>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php else : ?>
                                        <p><em><?php esc_html_e( 'No archives found in uploads/jmigrate/', 'jmigrate' ); ?></em></p>
                                        <?php endif; ?>
                                    </div>
                                    <p class="description"><?php esc_html_e( 'Choose a detected archive or leave blank if providing a custom path. You can download or delete existing archives.', 'jmigrate' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php submit_button( __( 'Run Import', 'jmigrate' ), 'primary', 'jmigrate_run_import' ); ?>
                </form>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            console.log('JMigrate admin loaded');
            
            // Archive management functionality
            $('.jmigrate-delete-archive').on('click', function(e) {
                e.preventDefault();
                var archivePath = $(this).data('archive');
                var filename = $(this).closest('tr').find('td:first').text();
                
                if (!confirm('Are you sure you want to delete "' + filename + '"? This action cannot be undone.')) {
                    return;
                }
                
                var button = $(this);
                button.prop('disabled', true).text('Deleting...');
                
                $.post(ajaxurl, {
                    action: 'jmigrate_delete_archive',
                    archive_path: archivePath,
                    _ajax_nonce: '<?php echo wp_create_nonce( 'jmigrate_admin' ); ?>'
                }, function(response) {
                    if (response.success) {
                        button.closest('tr').fadeOut(function() {
                            $(this).remove();
                            // Update select dropdown
                            $('#jmigrate_archive_select option[value="' + archivePath + '"]').remove();
                            // Check if no archives left
                            if ($('#jmigrate-archive-list tbody tr').length === 0) {
                                $('#jmigrate-archive-list').html('<p><em>No archives found in uploads/jmigrate/</em></p>');
                            }
                        });
                    } else {
                        alert('Error: ' + (response.data || 'Failed to delete archive'));
                        button.prop('disabled', false).text('Delete');
                    }
                }).fail(function() {
                    alert('Error: Failed to communicate with server');
                    button.prop('disabled', false).text('Delete');
                });
            });
            
            // Refresh archives functionality
            $('#jmigrate-refresh-archives').on('click', function(e) {
                e.preventDefault();
                var button = $(this);
                button.prop('disabled', true).text('Refreshing...');
                
                $.post(ajaxurl, {
                    action: 'jmigrate_refresh_archives',
                    _ajax_nonce: '<?php echo wp_create_nonce( 'jmigrate_admin' ); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload(); // Simple refresh for now
                    } else {
                        alert('Error: ' + (response.data || 'Failed to refresh archives'));
                    }
                    button.prop('disabled', false).text('Refresh');
                }).fail(function() {
                    alert('Error: Failed to communicate with server');
                    button.prop('disabled', false).text('Refresh');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Attempts to build a download URL for an archive path.
     *
     * @param string $path Archive filesystem path.
     * @return string Empty string when URL cannot be determined.
     */
    private function get_download_url_for_path( $path ) {
        $path = wp_normalize_path( (string) $path );
        if ( '' === $path ) {
            return '';
        }

        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return '';
        }

        $base_dir = wp_normalize_path( $uploads['basedir'] );
        if ( 0 !== strpos( $path, $base_dir ) ) {
            return '';
        }

        $relative = ltrim( substr( $path, strlen( $base_dir ) ), '/\\' );
        if ( '' === $relative ) {
            return '';
        }

        $base_url     = trailingslashit( $uploads['baseurl'] );
        $relative_url = str_replace( '\\', '/', $relative );

        return $base_url . $relative_url;
    }

    /**
     * Outputs notices for errors and logs.
     *
     * @param array $errors   Error messages.
     * @param array $messages Log entries.
     */
    private function render_notices( $errors, $messages ) {
        if ( ! empty( $errors ) ) {
            echo '<div class="notice notice-error"><ul>';
            foreach ( $errors as $error ) {
                echo '<li>' . esc_html( $error ) . '</li>';
            }
            echo '</ul></div>';
        }

        if ( ! empty( $messages ) ) {
            echo '<div class="jmigrate-log"><ul>';
            foreach ( $messages as $entry ) {
                $type = isset( $entry['type'] ) ? $entry['type'] : 'info';
                echo '<li class="jmigrate-log__item jmigrate-log__item--' . esc_attr( $type ) . '">' . esc_html( $entry['message'] ) . '</li>';
            }
            echo '</ul></div>';
        }
    }

    /**
     * Scans for available archives in the default JMigrate upload directory.
     *
     * @return string[] Absolute paths.
     */
    private function discover_archives() {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return [];
        }

        $directory = trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . 'jmigrate';

        if ( ! is_dir( $directory ) ) {
            return [];
        }

        $archives = glob( $directory . '/*.zip' );
        if ( empty( $archives ) ) {
            return [];
        }

        sort( $archives );
        return array_map( 'wp_normalize_path', $archives );
    }
}
