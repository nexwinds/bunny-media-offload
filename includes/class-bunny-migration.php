<?php
/**
 * Bunny migration handler
 */
class Bunny_Migration {
    
    private $api;
    private $settings;
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct($api, $settings, $logger) {
        $this->api = $api;
        $this->settings = $settings;
        $this->logger = $logger;
        
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('wp_ajax_bunny_start_migration', array($this, 'ajax_start_migration'));
        add_action('wp_ajax_bunny_migration_batch', array($this, 'ajax_migration_batch'));
        add_action('wp_ajax_bunny_get_migration_status', array($this, 'ajax_get_migration_status'));
        add_action('wp_ajax_bunny_cancel_migration', array($this, 'ajax_cancel_migration'));
    }
    
    /**
     * Validate AJAX request (security and permissions)
     */
    private function validate_ajax_request() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
    }
    
    /**
     * Start migration process
     */
    public function ajax_start_migration() {
        $this->validate_ajax_request();
        
        // Include SVG, AVIF and WebP file types for migration
        $file_types = array('svg', 'avif', 'webp');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by validate_ajax_request() called above
        $language_scope = isset($_POST['language_scope']) ? sanitize_text_field(wp_unslash($_POST['language_scope'])) : 'current';
        $post_types = array();
        
        // Get total files to migrate
        $total_files = $this->get_files_to_migrate_count($file_types, $post_types, $language_scope);
        
        if ($total_files === 0) {
            wp_send_json_error(array('message' => __('No files found to migrate.', 'bunny-media-offload')));
        }
        
        // Initialize migration session
        $migration_id = $this->init_migration_session($total_files, $file_types, $post_types, $language_scope);
        
        // Get concurrent limit setting but cap at 1 for the new implementation
        $concurrent_limit = 1; // Force single thread for better control
        
        // Set up a queue in the session for our batched processing
        update_option('bunny_migration_queue', array(
            'migration_id' => $migration_id,
            'batch_size' => $this->settings->get('batch_size', 20), // Limit batch size
            'delay' => 200, // Delay in ms between operations
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0,
            'status' => 'running',
            'offset' => 0
        ));
        
        $this->logger->info('Migration started', array(
            'migration_id' => $migration_id,
            'total_files' => $total_files,
            'file_types' => $file_types,
            'language_scope' => $language_scope,
            'concurrent_limit' => $concurrent_limit
        ));
        
        wp_send_json_success(array(
            'added' => $total_files,
            'queue_size' => $total_files,
            // translators: %1$d is the total number of files
            'message' => sprintf(__('Migration started. %1$d files added to queue.', 'bunny-media-offload'), $total_files)
        ));
    }
    
    /**
     * Process migration batch
     */
    public function ajax_migration_batch() {
        $this->validate_ajax_request();
        
        // Get the migration queue
        $queue = get_option('bunny_migration_queue', false);
        
        if (!$queue || empty($queue['migration_id'])) {
            wp_send_json_error(array('message' => __('Migration queue not found.', 'bunny-media-offload')));
        }
        
        // Check if migration was cancelled
        if ($queue['status'] === 'cancelled') {
            wp_send_json_error(array('message' => __('Migration was cancelled.', 'bunny-media-offload')));
        }
        
        // Clear stats cache for fresh data across all admin pages
        if (class_exists('Bunny_Stats')) {
            global $bunny_stats;
            if ($bunny_stats) {
                $bunny_stats->clear_cache();
            }
        }
        
        // Get session for reference
        $session = $this->get_migration_session($queue['migration_id']);
        if (!$session) {
            wp_send_json_error(array('message' => __('Migration session not found.', 'bunny-media-offload')));
        }
        
        // Process a small batch (1-5 files) with proper feedback
        $batch_size = min(5, $queue['batch_size']);
        $file_types = isset($session['file_types']) ? $session['file_types'] : array('svg', 'avif', 'webp');
        $post_types = isset($session['post_types']) ? $session['post_types'] : array();
        $language_scope = isset($session['language_scope']) ? $session['language_scope'] : 'current';
        
        // Get the next batch of files
        $files = $this->get_files_to_migrate($file_types, $post_types, $batch_size, $queue['offset'], $language_scope);
        
        $result = array(
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0,
            'completed' => false,
            'errors' => array(),
            'results' => array()
        );
        
        // Check if we have files to process
        if (empty($files)) {
            // No more files, we're done
            $result['completed'] = true;
            
            // Update the queue
            $queue['status'] = 'completed';
            update_option('bunny_migration_queue', $queue);
            
            // Update the session
            $this->update_migration_session($queue['migration_id'], array(
                'processed' => $queue['processed'],
                'successful' => $queue['successful'],
                'failed' => $queue['failed'],
                'status' => 'completed'
            ));
            
            // Send successful completion response
            wp_send_json_success(array(
                'processed' => $queue['processed'],
                'successful' => $queue['successful'],
                'failed' => $queue['failed'],
                'remaining' => 0,
                'results' => array(),
                'completed' => true
            ));
            return;
        }
        
        // Process each file in the batch
        foreach ($files as $file) {
            $attachment_id = $file->ID;
            $file_path = $file->file_path;
            
            $result['processed']++;
            $queue['processed']++;
            
            // Get attachment metadata
            $metadata = wp_get_attachment_metadata($attachment_id);
            
            // Process the file
            $process_result = array(
                'attachment_id' => $attachment_id,
                'success' => false,
                'skipped' => false,
                'message' => ''
            );
            
            try {
                // Check if already migrated
                $already_migrated = $this->is_file_already_migrated($attachment_id);
                if ($already_migrated) {
                    $process_result['skipped'] = true;
                    $process_result['message'] = __('File already migrated', 'bunny-media-offload');
                    $result['skipped']++;
                    $queue['skipped']++;
                } else {
                    // Get full local path
                    $upload_dir = wp_upload_dir();
                    $local_path = $upload_dir['basedir'] . '/' . $file_path;
                    
                    // Check file exists and is readable
                    if (!file_exists($local_path) || !is_readable($local_path)) {
                        throw new Exception(__('File not found or not readable', 'bunny-media-offload'));
                    }
                    
                    // Check file size is within limits
                    if (!$this->should_migrate_file($local_path)) {
                        $process_result['skipped'] = true;
                        $process_result['message'] = __('File size exceeds limit', 'bunny-media-offload');
                        $result['skipped']++;
                        $queue['skipped']++;
                    } else {
                        // Generate remote path
                        $remote_path = $this->generate_remote_path($file_path);
                        
                        // Upload file to Bunny CDN
                        $upload_result = $this->api->upload_file($local_path, $remote_path);
                        
                        if ($upload_result['success']) {
                            // Record the offloaded file in database
                            $bunny_url = $this->add_version_to_url($upload_result['url']);
                            $this->record_offloaded_file($attachment_id, $bunny_url, $remote_path, $file_path);
                            
                            // Upload thumbnails if available
                            if (!empty($metadata['sizes'])) {
                                $this->upload_image_thumbnails($metadata, $attachment_id, $local_path);
                            }
                            
                            // Delete local file if configured
                            if ($this->settings->get('delete_local', false)) {
                                $this->delete_local_file($local_path, $attachment_id, $metadata);
                            }
                            
                            // Log successful migration
                            $this->logger->info('File migrated to Bunny CDN', array(
                                'attachment_id' => $attachment_id,
                                'remote_path' => $remote_path,
                                'url' => $bunny_url
                            ));
                            
                            $process_result['success'] = true;
                            $result['successful']++;
                            $queue['successful']++;
                        } else {
                            throw new Exception(isset($upload_result['message']) ? $upload_result['message'] : __('Unknown upload error', 'bunny-media-offload'));
                        }
                    }
                }
            } catch (Exception $e) {
                $error_message = $e->getMessage();
                $process_result['message'] = $error_message;
                
                // Log error
                $this->logger->error('Migration failed', array(
                    'attachment_id' => $attachment_id,
                    'error' => $error_message
                ));
                
                $result['errors'][] = array(
                    'attachment_id' => $attachment_id,
                    'message' => $error_message
                );
                
                $result['failed']++;
                $queue['failed']++;
            }
            
            // Add to results array for client-side feedback
            $result['results'][] = $process_result;
        }
        
        // Update offset for next batch
        $queue['offset'] += count($files);
        
        // Check if we've processed all files
        $remaining = max(0, $session['total_files'] - $queue['offset']);
        $result['completed'] = ($remaining <= 0);
        
        if ($result['completed']) {
            $queue['status'] = 'completed';
            
            // Log completion
            $this->logger->info('Migration completed', array(
                'migration_id' => $queue['migration_id'],
                'total_processed' => $queue['processed'],
                'successful' => $queue['successful'],
                'failed' => $queue['failed'],
                'skipped' => $queue['skipped']
            ));
        }
        
        // Update the queue in database
        update_option('bunny_migration_queue', $queue);
        
        // Update session if completed
        if ($result['completed']) {
            $this->update_migration_session($queue['migration_id'], array(
                'processed' => $queue['processed'],
                'successful' => $queue['successful'],
                'failed' => $queue['failed'],
                'status' => 'completed'
            ));
        }
        
        // Prepare response
        $response = array(
            'processed' => count($files),
            'total_processed' => $queue['processed'],
            'successful' => $queue['successful'],
            'failed' => $queue['failed'],
            'skipped' => $queue['skipped'],
            'remaining' => $remaining,
            'completed' => $result['completed'],
            'results' => $result['results']
        );
        
        wp_send_json_success($response);
    }
    
    /**
     * Get migration status
     */
    public function ajax_get_migration_status() {
        $this->validate_ajax_request();
        
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by validate_ajax_request() called above
        $migration_id = isset($_POST['migration_id']) ? sanitize_text_field(wp_unslash($_POST['migration_id'])) : '';
        $session = $this->get_migration_session($migration_id);
        
        if (!$session) {
            wp_send_json_error(array('message' => __('Migration session not found.', 'bunny-media-offload')));
        }
        
        $response = array(
            'status' => $session['status'],
            'processed' => $session['processed'],
            'successful' => $session['successful'],
            'failed' => $session['failed'],
            'total' => $session['total_files'],
            'progress' => Bunny_Utils::get_progress_percentage($session['processed'], $session['total_files']),
            'errors' => $session['errors']
        );
        
        wp_send_json_success($response);
    }
    
    /**
     * Cancel migration
     */
    public function ajax_cancel_migration() {
        $this->validate_ajax_request();
        
        // Get the migration queue
        $queue = get_option('bunny_migration_queue', false);
        
        if ($queue && !empty($queue['migration_id'])) {
            $migration_id = $queue['migration_id'];
            
            // Update session
            $this->update_migration_session($migration_id, array('status' => 'cancelled'));
            
            // Update queue
            $queue['status'] = 'cancelled';
            update_option('bunny_migration_queue', $queue);
            
            $this->logger->info('Migration cancelled', array('migration_id' => $migration_id));
        }
        
        wp_send_json_success(array('message' => __('Migration cancelled.', 'bunny-media-offload')));
    }
    
    /**
     * Get files to migrate
     */
    private function get_files_to_migrate($file_types = array(), $post_types = array(), $limit = null, $offset = 0, $language_scope = 'current') {
        global $wpdb;
        
        $where_conditions = array("posts.post_type = 'attachment'");
        $params = array();
        
        // Filter by supported file types (SVG, WebP and AVIF)
        if (!empty($file_types)) {
            $mime_types = $this->get_supported_mime_types($file_types);
            
            if (!empty($mime_types)) {
                $placeholders = implode(',', array_fill(0, count($mime_types), '%s'));
                $where_conditions[] = "posts.post_mime_type IN ($placeholders)";
                $params = array_merge($params, $mime_types);
            }
        }
        
        // Exclude already migrated files
        $bunny_table = $wpdb->prefix . 'bunny_offloaded_files';
        $where_conditions[] = "posts.ID NOT IN (SELECT attachment_id FROM $bunny_table WHERE is_synced = 1)";
        
        // Only include files that have file paths (valid files)
        $where_conditions[] = "meta.meta_value IS NOT NULL AND meta.meta_value != ''";
        
        // Apply language filter if using WPML
        if ($this->wpml && $this->wpml->is_wpml_active() && $language_scope === 'current') {
            $current_language = $this->wpml->get_current_language();
            $where_conditions[] = $wpdb->prepare("(wpml.language_code = %s OR wpml.language_code IS NULL)", $current_language);
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $limit_clause = '';
        if ($limit !== null) {
            $limit_clause = $wpdb->prepare(' LIMIT %d OFFSET %d', $limit, $offset);
        }
        
        // Add WPML join if needed
        $wpml_join = '';
        if ($this->wpml && $this->wpml->is_wpml_active() && $language_scope === 'current') {
            $wpml_join = "LEFT JOIN {$wpdb->prefix}icl_translations wpml ON posts.ID = wpml.element_id AND wpml.element_type = 'post_attachment'";
        }
        
        // Apply WPML filter to query if needed
        $query = apply_filters('bunny_get_attachments_query', "
            SELECT posts.ID, posts.post_title, meta.meta_value as file_path
            FROM {$wpdb->posts} posts
            LEFT JOIN {$wpdb->postmeta} meta ON posts.ID = meta.post_id AND meta.meta_key = '_wp_attached_file'
            $wpml_join
            WHERE $where_clause
            ORDER BY posts.ID ASC
            $limit_clause
        ");
        
        if (!empty($params)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is safely constructed above with placeholders, custom migration query, caching not appropriate for one-time migration
            $results = $wpdb->get_results($wpdb->prepare($query, ...$params));
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query contains only safe table names and WHERE clauses, custom migration query, caching not appropriate for one-time migration
            $results = $wpdb->get_results($query);
        }
        
        // Filter results by file size before returning
        $filtered_results = array();
        $upload_dir = wp_upload_dir();
        
        foreach ($results as $file) {
            $local_path = $upload_dir['basedir'] . '/' . $file->file_path;
            if ($this->should_migrate_file($local_path)) {
                $filtered_results[] = $file;
            }
        }
        
        return $filtered_results;
    }
    
    /**
     * Get count of files to migrate with language scope support
     */
    private function get_files_to_migrate_count($file_types = array(), $post_types = array(), $language_scope = 'current') {
        global $wpdb;
        
        $where_conditions = array("posts.post_type = 'attachment'");
        $params = array();
        
        // Filter by supported file types (SVG, WebP and AVIF)
        if (!empty($file_types)) {
            $mime_types = $this->get_supported_mime_types($file_types);
            
            if (!empty($mime_types)) {
                $placeholders = implode(',', array_fill(0, count($mime_types), '%s'));
                $where_conditions[] = "posts.post_mime_type IN ($placeholders)";
                $params = array_merge($params, $mime_types);
            }
        }
        
        // Exclude already migrated files
        $bunny_table = $wpdb->prefix . 'bunny_offloaded_files';
        $where_conditions[] = "posts.ID NOT IN (SELECT attachment_id FROM $bunny_table WHERE is_synced = 1)";
        
        // Only include files that have file paths (valid files)
        $where_conditions[] = "meta.meta_value IS NOT NULL AND meta.meta_value != ''";
        
        // Apply language filter if using WPML
        if ($this->wpml && $this->wpml->is_wpml_active() && $language_scope === 'current') {
            $current_language = $this->wpml->get_current_language();
            $where_conditions[] = $wpdb->prepare("(wpml.language_code = %s OR wpml.language_code IS NULL)", $current_language);
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Add WPML join if needed
        $wpml_join = '';
        if ($this->wpml && $this->wpml->is_wpml_active() && $language_scope === 'current') {
            $wpml_join = "LEFT JOIN {$wpdb->prefix}icl_translations wpml ON posts.ID = wpml.element_id AND wpml.element_type = 'post_attachment'";
        }
        
        // Apply WPML filter to query if needed
        $query = apply_filters('bunny_get_attachments_count_query', "
            SELECT COUNT(DISTINCT posts.ID)
            FROM {$wpdb->posts} posts
            LEFT JOIN {$wpdb->postmeta} meta ON posts.ID = meta.post_id AND meta.meta_key = '_wp_attached_file'
            $wpml_join
            WHERE $where_clause
        ");
        
        if (!empty($params)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is safely constructed above with placeholders, custom migration query, caching not appropriate for one-time migration
            $count = $wpdb->get_var($wpdb->prepare($query, ...$params));
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query contains only safe table names and WHERE clauses, custom migration query, caching not appropriate for one-time migration
            $count = $wpdb->get_var($query);
        }
        
        return (int) $count;
    }
    
    /**
     * Process a batch of files
     */
    private function process_batch($session, $batch_size) {
        $concurrent_limit = $this->settings->get('migration_concurrent_limit', 4);
        
        $files = $this->get_files_to_migrate(
            $session['file_types'],
            $session['post_types'],
            $batch_size,
            $session['processed']
        );
        
        $successful = 0;
        $failed = 0;
        $errors = array();
        
        // Process files in concurrent chunks
        $file_chunks = array_chunk($files, $concurrent_limit);
        
        foreach ($file_chunks as $chunk) {
            $chunk_results = $this->process_concurrent_chunk($chunk);
            
            $successful += $chunk_results['successful'];
            $failed += $chunk_results['failed'];
            $errors = array_merge($errors, $chunk_results['errors']);
        }
        
        $processed = $session['processed'] + count($files);
        $completed = $processed >= $session['total_files'];
        
        return array(
            'processed' => $processed,
            'successful' => $session['successful'] + $successful,
            'failed' => $session['failed'] + $failed,
            'errors' => $errors,
            'completed' => $completed,
            'current_files' => array_slice($files, 0, $concurrent_limit) // Include current batch files for display
        );
    }
    
    /**
     * Process a concurrent chunk of files
     */
    private function process_concurrent_chunk($files) {
        $successful = 0;
        $failed = 0;
        $errors = array();
        
        foreach ($files as $file) {
            if (!$file->file_path) {
                $failed++;
                // translators: %d is the attachment ID
                $errors[] = sprintf(__('File path not found for attachment ID %d', 'bunny-media-offload'), $file->ID);
                continue;
            }
            
            $upload_dir = wp_upload_dir();
            $local_path = $upload_dir['basedir'] . '/' . $file->file_path;
            
            if (!file_exists($local_path)) {
                $failed++;
                // translators: %s is the local file path
                $errors[] = sprintf(__('Local file not found: %s', 'bunny-media-offload'), $local_path);
                continue;
            }
            
            // Check file size before processing
            if (!$this->should_migrate_file($local_path)) {
                $failed++;
                // translators: %s is the file title
                $errors[] = sprintf(__('File %s exceeds size limit', 'bunny-media-offload'), $file->post_title);
                continue;
            }
            
            // Generate remote path
            $remote_path = $file->file_path;
            
            // Upload to Bunny.net
            $bunny_url = $this->api->upload_file($local_path, $remote_path);
            
            if (is_wp_error($bunny_url)) {
                $failed++;
                $errors[] = sprintf(
                    // translators: %1$s is the file title, %2$s is the error message
                    __('Upload failed for %1$s: %2$s', 'bunny-media-offload'),
                    $file->post_title,
                    $bunny_url->get_error_message()
                );
                continue;
            }
            
            // Add versioning if enabled
            if ($this->settings->get('file_versioning')) {
                $bunny_url = $this->add_version_to_url($bunny_url);
            }
            
            // Record the offloaded file
            $this->record_offloaded_file($file->ID, $bunny_url, $remote_path, $local_path);
            
            // Upload thumbnails if this is an image
            if (wp_attachment_is_image($file->ID)) {
                $metadata = wp_get_attachment_metadata($file->ID);
                if ($metadata) {
                    $this->upload_image_thumbnails($metadata, $file->ID, $local_path);
                }
            }
            
            // Delete local file if enabled
            if ($this->settings->get('delete_local')) {
                $metadata = wp_get_attachment_metadata($file->ID);
                $this->delete_local_file($local_path, $file->ID, $metadata);
            }
            
            $successful++;
        }
        
        return array(
            'successful' => $successful,
            'failed' => $failed,
            'errors' => $errors
        );
    }
    
    /**
     * Upload thumbnails for migrated images
     */
    private function upload_image_thumbnails($metadata, $attachment_id, $main_file_path) {
        if (empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
            return;
        }
        
        $main_file_dir = dirname($main_file_path);
        
        foreach ($metadata['sizes'] as $size_name => $size_data) {
            if (empty($size_data['file'])) {
                continue;
            }
            
            $thumbnail_path = $main_file_dir . '/' . $size_data['file'];
            
            if (!file_exists($thumbnail_path)) {
                // Try to generate the thumbnail if it doesn't exist
                $image_editor = wp_get_image_editor($main_file_path);
                if (!is_wp_error($image_editor)) {
                    $resized = $image_editor->resize($size_data['width'], $size_data['height'], true);
                    if (!is_wp_error($resized)) {
                        $saved = $image_editor->save($thumbnail_path);
                        if (is_wp_error($saved)) {
                            $this->logger->warning('Failed to generate thumbnail during migration', array(
                                'attachment_id' => $attachment_id,
                                'size' => $size_name,
                                'error' => $saved->get_error_message()
                            ));
                            continue;
                        }
                    }
                } else {
                    continue;
                }
            }
            
            // Generate remote path for thumbnail
            $thumbnail_remote_path = $this->generate_remote_path($thumbnail_path);
            
            // Upload thumbnail to Bunny.net
            $result = $this->api->upload_file($thumbnail_path, $thumbnail_remote_path);
            
            if (is_wp_error($result)) {
                $this->logger->warning('Failed to upload thumbnail during migration', array(
                    'attachment_id' => $attachment_id,
                    'size' => $size_name,
                    'file' => $thumbnail_path,
                    'error' => $result->get_error_message()
                ));
            } else {
                // Add versioning if enabled
                if ($this->settings->get('file_versioning')) {
                    $result = $this->add_version_to_url($result);
                }
                
                // Store thumbnail CDN URL in metadata for faster access
                update_post_meta($attachment_id, '_bunny_thumbnail_' . $size_name, $result);
                
                $this->logger->debug('Thumbnail uploaded during migration', array(
                    'attachment_id' => $attachment_id,
                    'size' => $size_name,
                    'bunny_url' => $result
                ));
            }
        }
    }
    
    /**
     * Generate remote path from local file path
     */
    private function generate_remote_path($local_path) {
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $local_path);
        return $relative_path;
    }
    
    /**
     * Initialize migration session
     */
    private function init_migration_session($total_files, $file_types, $post_types, $language_scope = 'current') {
        $migration_id = 'migration_' . time() . '_' . wp_generate_password(8, false);
        
        $session_data = array(
            'id' => $migration_id,
            'status' => 'running',
            'total_files' => $total_files,
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'file_types' => $file_types,
            'post_types' => $post_types,
            'language_scope' => $language_scope,
            'start_time' => time(),
            'errors' => array()
        );
        
        set_transient('bunny_migration_' . $migration_id, $session_data, 24 * HOUR_IN_SECONDS);
        
        return $migration_id;
    }
    
    /**
     * Get migration session
     */
    private function get_migration_session($migration_id) {
        return get_transient('bunny_migration_' . $migration_id);
    }
    
    /**
     * Update migration session
     */
    private function update_migration_session($migration_id, $updates) {
        $session = $this->get_migration_session($migration_id);
        if ($session) {
            $session = array_merge($session, $updates);
            set_transient('bunny_migration_' . $migration_id, $session, 24 * HOUR_IN_SECONDS);
        }
    }
    
    /**
     * Add version to URL for cache busting
     */
    private function add_version_to_url($url) {
        // Generate a 3-character version string
        $version = substr(md5(time()), 0, 3);
        $separator = (strpos($url, '?') !== false) ? '&' : '?';
        return $url . $separator . 'v=' . $version;
    }
    
    /**
     * Record offloaded file in database
     */
    private function record_offloaded_file($attachment_id, $bunny_url, $remote_path, $file_path) {
        global $wpdb;
        
        $file_size = file_exists($file_path) ? filesize($file_path) : 0;
        $file_type = get_post_mime_type($attachment_id);
        
        $table_name = $wpdb->prefix . 'bunny_offloaded_files';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Inserting plugin-specific data not available via WordPress functions
        $wpdb->insert($table_name, array(
            'attachment_id' => $attachment_id,
            'bunny_url' => $bunny_url,
            'file_size' => $file_size,
            'file_type' => $file_type,
            'date_offloaded' => current_time('mysql'),
            'is_synced' => 1
        ));
        
        // Update stats
        $this->update_stats($file_size, 1);
    }
    
    /**
     * Delete local file and its thumbnails
     */
    private function delete_local_file($file_path, $attachment_id, $metadata) {
        if (file_exists($file_path)) {
            wp_delete_file($file_path);
        }
        
        // Delete thumbnails if they exist
        if (!empty($metadata['sizes'])) {
            $base_dir = dirname($file_path);
            
            foreach ($metadata['sizes'] as $size_data) {
                if (!empty($size_data['file'])) {
                    $thumb_path = $base_dir . '/' . $size_data['file'];
                    if (file_exists($thumb_path)) {
                        wp_delete_file($thumb_path);
                    }
                }
            }
        }
    }
    
    /**
     * Update plugin statistics
     */
    private function update_stats($size_change, $file_count_change) {
        $stats = get_option('bunny_media_offload_stats', array());
        
        $stats['total_files_offloaded'] = max(0, (int)($stats['total_files_offloaded'] ?? 0) + $file_count_change);
        $stats['total_space_saved'] = max(0, (int)($stats['total_space_saved'] ?? 0) + $size_change);
        $stats['total_bunny_storage'] = max(0, (int)($stats['total_bunny_storage'] ?? 0) + $size_change);
        $stats['last_sync'] = time();
        
        update_option('bunny_media_offload_stats', $stats);
    }
    
    /**
     * Get comprehensive migration statistics 
     */
    public function get_migration_stats($detailed = false) {
        global $wpdb;
        
        // Use the existing method to get accurate file counts
        $file_types = array('svg', 'avif', 'webp');
        $total_eligible_files = $this->get_files_to_migrate_count($file_types);
        
        // Get all files that match our criteria and check which are already migrated
        $eligible_files = $this->get_files_to_migrate($file_types, array(), null, 0);
        $eligible_ids = array_map(function($file) { return $file->ID; }, $eligible_files);
        
        // Count how many of the eligible files are already migrated
        $migrated_files = 0;
        if (!empty($eligible_ids)) {
            $ids_placeholder = implode(',', array_fill(0, count($eligible_ids), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Counting migrated files for migration stats, caching not needed for one-time calculation, safe placeholder interpolation for IN clause  
            $migrated_files = (int) $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) 
                FROM {$wpdb->prefix}bunny_offloaded_files 
                WHERE is_synced = 1 AND attachment_id IN ($ids_placeholder)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Safe placeholder interpolation for dynamic IN clause
            , ...$eligible_ids)); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholder count for IN clause
        }
        
        $pending_files = max(0, $total_eligible_files - $migrated_files);
        
        $batch_size = $this->settings->get('batch_size', 100);
        $migration_percentage = $total_eligible_files > 0 ? round(($migrated_files / $total_eligible_files) * 100, 2) : 0;
        
        $base_stats = array(
            'total_attachments' => (int) $total_eligible_files,
            'total_images_to_migrate' => (int) $total_eligible_files,
            'total_migrated' => (int) $migrated_files,
            'migrated_files' => (int) $migrated_files,
            'pending_files' => (int) $pending_files,
            'total_remaining' => (int) $pending_files,
            'batch_size' => $batch_size,
            'has_files_to_migrate' => $pending_files > 0,
            'migration_percentage' => $migration_percentage
        );
        
        if (!$detailed) {
            return $base_stats;
        }
        
        // Add detailed breakdown by file type for detailed view
        $detailed_stats = array();
        foreach ($file_types as $type) {
            $type_eligible_files = $this->get_files_to_migrate(array($type), array(), null, 0);
            $type_eligible_ids = array_map(function($file) { return $file->ID; }, $type_eligible_files);
            $type_total = count($type_eligible_files);
            
            $type_migrated = 0;
            if (!empty($type_eligible_ids)) {
                $type_ids_placeholder = implode(',', array_fill(0, count($type_eligible_ids), '%d'));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Counting migrated files by type for migration stats, caching not needed for one-time calculation, safe placeholder interpolation for IN clause
                $type_migrated = (int) $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*) 
                    FROM {$wpdb->prefix}bunny_offloaded_files 
                    WHERE is_synced = 1 AND attachment_id IN ($type_ids_placeholder)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Safe placeholder interpolation for dynamic IN clause
                , ...$type_eligible_ids)); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholder count for IN clause
            }
            
            $type_remaining = max(0, $type_total - $type_migrated);
            
            $detailed_stats[$type . '_total'] = (int) $type_total;
            $detailed_stats[$type . '_migrated'] = (int) $type_migrated;
            $detailed_stats[$type . '_remaining'] = (int) $type_remaining;
        }
        
        return array_merge($base_stats, $detailed_stats);
    }
    
    /**
     * Get supported MIME types for migration
     */
    private function get_supported_mime_types($file_types) {
        $mime_types = array();
        foreach ($file_types as $file_type) {
            switch ($file_type) {
                case 'svg':
                    $mime_types[] = 'image/svg+xml';
                    break;
                case 'webp':
                    $mime_types[] = 'image/webp';
                    break;
                case 'avif':
                    $mime_types[] = 'image/avif';
                    break;
            }
        }
        return $mime_types;
    }
    
    /**
     * Check if file should be migrated
     */
    private function should_migrate_file($local_path) {
        if (!file_exists($local_path)) {
            return false;
        }
        
        // Get maximum file size (KB) from settings
        $settings = $this->settings->get_all();
        $max_file_size_kb = isset($settings['max_file_size']) ? (int) $settings['max_file_size'] : 10240; // Default 10MB in KB
        $max_file_size_bytes = $max_file_size_kb * 1024; // Convert KB to bytes
        
        // Check if file size is within the limit
        $file_size = filesize($local_path);
        if ($file_size > $max_file_size_bytes) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if a file is already migrated
     */
    private function is_file_already_migrated($attachment_id) {
        global $wpdb;
        
        $bunny_table = $wpdb->prefix . 'bunny_offloaded_files';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Simple lookup query for one-time migration
        $result = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $bunny_table WHERE attachment_id = %d AND is_synced = 1", $attachment_id));
        
        return (int) $result > 0;
    }
} 