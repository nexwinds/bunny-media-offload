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
     * Start migration process
     */
    public function ajax_start_migration() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        // Automatically include both AVIF and WebP file types
        $file_types = array('avif', 'webp');
        $post_types = isset($_POST['post_types']) ? array_map('sanitize_text_field', wp_unslash($_POST['post_types'])) : array();
        
        // Get total files to migrate
        $total_files = $this->get_files_to_migrate_count($file_types, $post_types);
        
        if ($total_files === 0) {
            wp_send_json_error(array('message' => __('No files found to migrate.', 'bunny-media-offload')));
        }
        
        // Initialize migration session
        $migration_id = $this->init_migration_session($total_files, $file_types, $post_types);
        
        // Get concurrent limit setting
        $concurrent_limit = $this->settings->get('migration_concurrent_limit', 4);
        
        $this->logger->info('Migration started', array(
            'migration_id' => $migration_id,
            'total_files' => $total_files,
            'file_types' => $file_types,
            'post_types' => $post_types,
            'concurrent_limit' => $concurrent_limit
        ));
        
        wp_send_json_success(array(
            'migration_id' => $migration_id,
            'total_files' => $total_files,
            'concurrent_limit' => $concurrent_limit,
            // translators: %1$d is the total number of files, %2$d is the number of concurrent threads
            'message' => sprintf(__('Migration started. %1$d files to process with %2$d concurrent threads.', 'bunny-media-offload'), $total_files, $concurrent_limit)
        ));
    }
    
    /**
     * Process migration batch
     */
    public function ajax_migration_batch() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $migration_id = isset($_POST['migration_id']) ? sanitize_text_field(wp_unslash($_POST['migration_id'])) : '';
        $session = $this->get_migration_session($migration_id);
        
        if (!$session) {
            wp_send_json_error(array('message' => __('Migration session not found.', 'bunny-media-offload')));
        }
        
        if ($session['status'] === 'cancelled') {
            wp_send_json_error(array('message' => __('Migration was cancelled.', 'bunny-media-offload')));
        }
        
        $batch_size = $this->settings->get('batch_size', 90);
        $result = $this->process_batch($session, $batch_size);
        
        // Update session
        $this->update_migration_session($migration_id, array(
            'processed' => $result['processed'],
            'successful' => $result['successful'],
            'failed' => $result['failed'],
            'errors' => array_merge($session['errors'], $result['errors']),
            'status' => $result['completed'] ? 'completed' : 'running'
        ));
        
        $response = array(
            'processed' => $result['processed'],
            'successful' => $result['successful'],
            'failed' => $result['failed'],
            'total' => $session['total_files'],
            'completed' => $result['completed'],
            'progress' => Bunny_Utils::get_progress_percentage($result['processed'], $session['total_files']),
            'errors' => $result['errors']
        );
        
        if ($result['completed']) {
            $this->logger->info('Migration completed', array(
                'migration_id' => $migration_id,
                'total_processed' => $result['processed'],
                'successful' => $result['successful'],
                'failed' => $result['failed']
            ));
            
            $response['message'] = sprintf(
                // translators: %1$d is the number of files processed, %2$d is the number of successful uploads, %3$d is the number of failed uploads
                __('Migration completed. %1$d files processed, %2$d successful, %3$d failed.', 'bunny-media-offload'),
                $result['processed'],
                $result['successful'],
                $result['failed']
            );
        }
        
        wp_send_json_success($response);
    }
    
    /**
     * Get migration status
     */
    public function ajax_get_migration_status() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
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
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $migration_id = isset($_POST['migration_id']) ? sanitize_text_field(wp_unslash($_POST['migration_id'])) : '';
        
        $this->update_migration_session($migration_id, array('status' => 'cancelled'));
        
        $this->logger->info('Migration cancelled', array('migration_id' => $migration_id));
        
        wp_send_json_success(array('message' => __('Migration cancelled.', 'bunny-media-offload')));
    }
    
    /**
     * Get files to migrate
     */
    private function get_files_to_migrate($file_types = array(), $post_types = array(), $limit = null, $offset = 0) {
        global $wpdb;
        
        $where_conditions = array("posts.post_type = 'attachment'");
        $params = array();
        
        // Filter by file types (only WebP and AVIF supported)
        if (!empty($file_types)) {
            $mime_types = array();
            foreach ($file_types as $file_type) {
                switch ($file_type) {
                    case 'webp':
                        $mime_types[] = 'image/webp';
                        break;
                    case 'avif':
                        $mime_types[] = 'image/avif';
                        break;
                }
            }
            
            if (!empty($mime_types)) {
                $placeholders = implode(',', array_fill(0, count($mime_types), '%s'));
                $where_conditions[] = "posts.post_mime_type IN ($placeholders)";
                $params = array_merge($params, $mime_types);
            }
        }
        
        // Exclude already migrated files
        $bunny_table = $wpdb->prefix . 'bunny_offloaded_files';
        $where_conditions[] = "posts.ID NOT IN (SELECT attachment_id FROM $bunny_table WHERE is_synced = 1)";
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $limit_clause = '';
        if ($limit !== null) {
            $limit_clause = $wpdb->prepare(' LIMIT %d OFFSET %d', $limit, $offset);
        }
        
        // Apply WPML filter to query if needed
        $query = apply_filters('bunny_get_attachments_query', "
            SELECT posts.ID, posts.post_title, meta.meta_value as file_path
            FROM {$wpdb->posts} posts
            LEFT JOIN {$wpdb->postmeta} meta ON posts.ID = meta.post_id AND meta.meta_key = '_wp_attached_file'
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
        
        // Apply WPML filter to results
        return apply_filters('bunny_migration_attachments', $results, array('file_types' => $file_types));
    }
    
    /**
     * Get count of files to migrate
     */
    private function get_files_to_migrate_count($file_types = array(), $post_types = array()) {
        $files = $this->get_files_to_migrate($file_types, $post_types);
        return count($files);
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
            'completed' => $completed
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
    private function init_migration_session($total_files, $file_types, $post_types) {
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
     * Get count of supported attachments for migration
     */
    private function get_supported_attachments_count() {
        global $wpdb;
        
        // Get max file size setting (convert to bytes)
        $max_size_setting = $this->settings->get('optimization_max_size', '50kb');
        $max_size_bytes = (int) filter_var($max_size_setting, FILTER_SANITIZE_NUMBER_INT) * 1024;
        
        // Count AVIF and WebP images that are below the size limit
        $query = "
            SELECT COUNT(DISTINCT posts.ID) 
            FROM {$wpdb->posts} posts
            LEFT JOIN {$wpdb->postmeta} meta ON posts.ID = meta.post_id AND meta.meta_key = '_wp_attached_file'
            WHERE posts.post_type = 'attachment' 
            AND posts.post_mime_type IN ('image/avif', 'image/webp')
        ";
        
        // If we can check file sizes, add that condition
        if (function_exists('filesize')) {
            // We'll do a basic count without file size check for now, as checking actual file sizes would be too expensive
            // The size check will be done during actual migration
        }
        
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query uses safe table names and conditions, custom migration query, caching not appropriate for real-time stats
        return (int) $wpdb->get_var($query);
    }
    
    /**
     * Get detailed migration statistics by file type
     */
    public function get_detailed_migration_stats() {
        global $wpdb;
        
        $bunny_table = $wpdb->prefix . 'bunny_offloaded_files';
        
        // Get counts by file type
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting files by type for migration stats, caching not needed for one-time calculation
        $avif_total = $wpdb->get_var("
            SELECT COUNT(DISTINCT posts.ID) 
            FROM {$wpdb->posts} posts
            WHERE posts.post_type = 'attachment' 
            AND posts.post_mime_type = 'image/avif'
        ");
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting files by type for migration stats, caching not needed for one-time calculation
        $webp_total = $wpdb->get_var("
            SELECT COUNT(DISTINCT posts.ID) 
            FROM {$wpdb->posts} posts
            WHERE posts.post_type = 'attachment' 
            AND posts.post_mime_type = 'image/webp'
        ");
        
        // Get migrated counts by file type
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting migrated files by type for migration stats, caching not needed for one-time calculation
        $avif_migrated = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}bunny_offloaded_files 
            WHERE is_synced = 1 
            AND file_type = 'image/avif'
        ");
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting migrated files by type for migration stats, caching not needed for one-time calculation
        $webp_migrated = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}bunny_offloaded_files 
            WHERE is_synced = 1 
            AND file_type = 'image/webp'
        ");
        
        $total_supported = (int) $avif_total + (int) $webp_total;
        $total_migrated = (int) $avif_migrated + (int) $webp_migrated;
        $total_remaining = max(0, $total_supported - $total_migrated);
        
        $batch_size = $this->settings->get('batch_size', 100);
        
        return array(
            'total_images_to_migrate' => $total_supported,
            'total_migrated' => $total_migrated,
            'total_remaining' => $total_remaining,
            'avif_total' => (int) $avif_total,
            'avif_migrated' => (int) $avif_migrated,
            'avif_remaining' => max(0, (int) $avif_total - (int) $avif_migrated),
            'webp_total' => (int) $webp_total,
            'webp_migrated' => (int) $webp_migrated,
            'webp_remaining' => max(0, (int) $webp_total - (int) $webp_migrated),
            'batch_size' => $batch_size,
            'has_files_to_migrate' => $total_remaining > 0,
            'migration_percentage' => $total_supported > 0 ? round(($total_migrated / $total_supported) * 100, 2) : 0
        );
    }
    
    /**
     * Get migration statistics
     */
    public function get_migration_stats() {
        global $wpdb;
        
        $bunny_table = $wpdb->prefix . 'bunny_offloaded_files';
        
        // Count supported attachments (AVIF and WebP images) instead of all attachments
        $total_supported_attachments = $this->get_supported_attachments_count();
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting migrated files for migration stats, caching not needed for one-time calculation
        $migrated_files = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$wpdb->prefix}bunny_offloaded_files` WHERE is_synced = %d", 1));
        $pending_files = $total_supported_attachments - $migrated_files;
        
        return array(
            'total_attachments' => (int) $total_supported_attachments,
            'migrated_files' => (int) $migrated_files,
            'pending_files' => max(0, (int) $pending_files),
            'migration_percentage' => $total_supported_attachments > 0 ? round(($migrated_files / $total_supported_attachments) * 100, 2) : 0
        );
    }
} 