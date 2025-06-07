<?php
/**
 * Bunny Image Optimizer
 * Handles image format conversion and size optimization
 */
class Bunny_Optimizer {
    
    private $api;
    private $settings;
    private $logger;
    private $queue_table;
    
    /**
     * Supported optimization formats
     */
    const SUPPORTED_FORMATS = array('avif', 'webp');
    
    /**
     * Size thresholds in bytes
     */
    const SIZE_THRESHOLDS = array(
        '40kb' => 40960,   // 40 KB
        '45kb' => 46080,   // 45 KB  
        '50kb' => 51200,   // 50 KB
        '55kb' => 56320,   // 55 KB
        '60kb' => 61440    // 60 KB
    );
    
    /**
     * Constructor
     */
    public function __construct($api, $settings, $logger) {
        $this->api = $api;
        $this->settings = $settings;
        $this->logger = $logger;
        
        global $wpdb;
        $this->queue_table = $wpdb->prefix . 'bunny_optimization_queue';
        
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Hook into upload process if optimization on upload is enabled
        add_filter('bunny_before_upload', array($this, 'optimize_on_upload'), 10, 2);
        
        // AJAX handlers for manual optimization
        add_action('wp_ajax_bunny_start_step_optimization', array($this, 'handle_step_optimization'));
        add_action('wp_ajax_bunny_optimization_batch', array($this, 'ajax_optimization_batch'));
        add_action('wp_ajax_bunny_cancel_optimization', array($this, 'ajax_cancel_optimization'));
    }
    
    /**
     * Optimize image during upload process
     */
    public function optimize_on_upload($file_path, $attachment_id) {
        if (!$this->settings->get('optimization_enabled', false)) {
            return $file_path;
        }
        
        if (!$this->settings->get('optimize_on_upload', true)) {
            return $file_path;
        }
        
        if (!$this->is_image($file_path)) {
            return $file_path;
        }
        
        $this->logger->log('info', "Starting optimization for upload: {$file_path}", array(
            'attachment_id' => $attachment_id
        ));
        
        $optimized_path = $this->optimize_image($file_path, $attachment_id);
        
        if ($optimized_path && $optimized_path !== $file_path) {
            $this->logger->log('info', "Image optimized during upload", array(
                'original' => $file_path,
                'optimized' => $optimized_path,
                'attachment_id' => $attachment_id
            ));
            return $optimized_path;
        }
        
        return $file_path;
    }
    
    /**
     * Optimize a single image
     */
    public function optimize_image($file_path, $attachment_id = null) {
        if (!$this->is_image($file_path)) {
            $this->logger->log('warning', "File is not an image: {$file_path}");
            return false;
        }
        
        $original_size = filesize($file_path);
        $max_size = $this->get_max_file_size();
        $preferred_format = 'avif'; // Always use AVIF for best compression
        
        // Check if optimization is needed
        if (!$this->needs_optimization($file_path, $original_size, $max_size)) {
            $this->logger->log('info', "Image does not need optimization: {$file_path}");
            return $file_path;
        }
        
        $optimization_result = $this->perform_optimization($file_path, $preferred_format, $max_size);
        
        if ($optimization_result && is_array($optimization_result)) {
            // Update attachment metadata if available
            if ($attachment_id) {
                $this->update_attachment_optimization_meta($attachment_id, $optimization_result);
            }
            
            // Log optimization results
            $this->log_optimization_result($file_path, $optimization_result);
            
            return $optimization_result['optimized_path'];
        }
        
        return false;
    }
    
    /**
     * Check if image needs optimization
     * Images need optimization if:
     * 1. Not in modern format (JPG/PNG to WebP/AVIF)
     * 2. Exceeds maximum file size (even if already WebP/AVIF)
     */
    private function needs_optimization($file_path, $file_size, $max_size) {
        $file_info = pathinfo($file_path);
        $current_format = strtolower($file_info['extension']);
        
        // Always optimize if not in preferred modern format
        if (!in_array($current_format, self::SUPPORTED_FORMATS)) {
            return true;
        }
        
        // Optimize if file exceeds size threshold (even if already WebP/AVIF)
        if ($file_size > $max_size) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Perform the actual optimization
     */
    private function perform_optimization($file_path, $target_format, $max_size) {
        $file_info = pathinfo($file_path);
        $temp_dir = wp_upload_dir()['basedir'] . '/bunny-temp/';
        
        // Create temp directory if it doesn't exist
        if (!wp_mkdir_p($temp_dir)) {
            $this->logger->log('error', "Failed to create temp directory: {$temp_dir}");
            return false;
        }
        
        $temp_file = $temp_dir . $file_info['filename'] . '_optimized.' . $target_format;
        
        try {
            // Load image
            $image = $this->load_image($file_path);
            if (!$image) {
                return false;
            }
            
            // Convert format and compress
            $result = $this->convert_and_compress($image, $file_path, $temp_file, $target_format, $max_size);
            
            if ($result) {
                // Replace original file
                if (copy($temp_file, $file_path)) {
                    wp_delete_file($temp_file);
                    
                    return array(
                        'optimized_path' => $file_path,
                        'original_size' => filesize($file_path),
                        'optimized_size' => $result['final_size'],
                        'original_format' => $file_info['extension'],
                        'optimized_format' => $target_format,
                        'compression_ratio' => round((1 - ($result['final_size'] / $result['original_size'])) * 100, 2),
                        'optimization_date' => current_time('mysql')
                    );
                }
            }
            
            // Clean up temp file
            if (file_exists($temp_file)) {
                wp_delete_file($temp_file);
            }
            
        } catch (Exception $e) {
            $this->logger->log('error', "Optimization failed: " . $e->getMessage(), array(
                'file' => $file_path
            ));
        }
        
        return false;
    }
    
    /**
     * Load image using appropriate method
     */
    private function load_image($file_path) {
        $image_info = getimagesize($file_path);
        if (!$image_info) {
            return false;
        }
        
        switch ($image_info[2]) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($file_path);
            case IMAGETYPE_PNG:
                return imagecreatefrompng($file_path);
            case IMAGETYPE_GIF:
                return imagecreatefromgif($file_path);
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    return imagecreatefromwebp($file_path);
                }
                break;
        }
        
        return false;
    }
    
    /**
     * Convert and compress image
     */
    private function convert_and_compress($image, $original_path, $output_path, $target_format, $max_size) {
        $original_size = filesize($original_path);
        $quality = 90; // Start with high quality
        $min_quality = 20; // Minimum acceptable quality
        
        do {
            $success = false;
            
            // Convert to target format with current quality
            switch ($target_format) {
                case 'avif':
                    if (function_exists('imageavif')) {
                        $success = imageavif($image, $output_path, $quality);
                    } else {
                        // Fallback to WebP if AVIF not supported
                        $target_format = 'webp';
                        $output_path = str_replace('.avif', '.webp', $output_path);
                        $success = imagewebp($image, $output_path, $quality);
                    }
                    break;
                case 'webp':
                    if (function_exists('imagewebp')) {
                        $success = imagewebp($image, $output_path, $quality);
                    }
                    break;
            }
            
            if (!$success) {
                return false;
            }
            
            $current_size = filesize($output_path);
            
            // If size is acceptable, we're done
            if ($current_size <= $max_size) {
                return array(
                    'original_size' => $original_size,
                    'final_size' => $current_size,
                    'quality_used' => $quality,
                    'format' => $target_format
                );
            }
            
            // Reduce quality for next iteration
            $quality -= 10;
            
        } while ($quality >= $min_quality);
        
        // If we couldn't meet size requirements, return the best we got
        return array(
            'original_size' => $original_size,
            'final_size' => filesize($output_path),
            'quality_used' => $quality + 10, // Last successful quality
            'format' => $target_format
        );
    }
    
    /**
     * Add files to optimization queue
     */
    public function add_to_optimization_queue($attachment_ids, $priority = 'normal') {
        global $wpdb;
        
        $values = array();
        $placeholders = array();
        
        foreach ($attachment_ids as $attachment_id) {
            $values[] = $attachment_id;
            $values[] = $priority;
            $values[] = 'pending';
            $values[] = current_time('mysql');
            $placeholders[] = '(%d, %s, %s, %s)';
        }
        
        if (!empty($values)) {
            $sql = "INSERT IGNORE INTO {$this->queue_table} 
                    (attachment_id, priority, status, date_added) 
                    VALUES " . implode(', ', $placeholders);
            
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is safely constructed with placeholders above, no caching needed for queue insertion
            $result = $wpdb->query($wpdb->prepare($sql, $values));
            
            $this->logger->log('info', "Added {$result} files to optimization queue");
            
            return $result;
        }
        
        return false;
    }
    
    /**
     * Process optimization queue
     */
    public function process_optimization_queue() {
        global $wpdb;
        
        $concurrent_limit = $this->settings->get('optimization_concurrent_limit', 3);
        
        // Get pending items from queue
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- No caching needed for real-time queue processing
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bunny_optimization_queue 
             WHERE status = 'pending' 
             ORDER BY 
                CASE priority 
                    WHEN 'high' THEN 1 
                    WHEN 'normal' THEN 2 
                    WHEN 'low' THEN 3 
                END,
                date_added ASC 
             LIMIT %d",
            $this->settings->get('optimization_batch_size', 10)
        ));
        
        if (empty($items)) {
            return;
        }
        
        // Process items in concurrent chunks
        $item_chunks = array_chunk($items, $concurrent_limit);
        
        foreach ($item_chunks as $chunk) {
            $this->process_concurrent_optimization_chunk($chunk);
        }
    }
    
    /**
     * Process a concurrent chunk of optimization items
     */
    private function process_concurrent_optimization_chunk($items) {
        foreach ($items as $item) {
            $this->process_queue_item($item);
        }
    }
    
    /**
     * Process single queue item
     */
    private function process_queue_item($item) {
        global $wpdb;
        
        // Update status to processing
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update needed for queue processing, no caching required
        $wpdb->update(
            $this->queue_table,
            array('status' => 'processing', 'date_started' => current_time('mysql')),
            array('id' => $item->id),
            array('%s', '%s'),
            array('%d')
        );
        
        try {
            $attachment_id = $item->attachment_id;
            
            // Check if attachment exists and is an image
            $file_path = get_attached_file($attachment_id);
            if (!$file_path || !$this->is_image($file_path)) {
                $this->update_queue_item_status($item->id, 'skipped', 'Not an image or file not found');
                return;
            }
            
            // Check if already optimized recently
            $last_optimized = get_post_meta($attachment_id, '_bunny_last_optimized', true);
            if ($last_optimized && (time() - strtotime($last_optimized)) < DAY_IN_SECONDS) {
                $this->update_queue_item_status($item->id, 'skipped', 'Recently optimized');
                return;
            }
            
            // Perform optimization
            $result = $this->optimize_image($file_path, $attachment_id);
            
            if ($result) {
                $this->update_queue_item_status($item->id, 'completed', 'Optimization successful');
                
                // Update optimization stats
                $this->update_optimization_stats($result);
            } else {
                $this->update_queue_item_status($item->id, 'failed', 'Optimization failed');
            }
            
        } catch (Exception $e) {
            $this->update_queue_item_status($item->id, 'failed', $e->getMessage());
            $this->logger->log('error', "Queue processing error: " . $e->getMessage(), array(
                'item_id' => $item->id,
                'attachment_id' => $item->attachment_id
            ));
        }
    }
    
    /**
     * Update queue item status
     */
    private function update_queue_item_status($item_id, $status, $message = '') {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update needed for queue status management, no caching required
        $wpdb->update(
            $this->queue_table,
            array(
                'status' => $status,
                'date_completed' => current_time('mysql'),
                'error_message' => $message
            ),
            array('id' => $item_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }
    
    /**
     * Handle bulk optimization AJAX request
     */
    public function handle_bulk_optimization() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $file_types = isset($_POST['file_types']) ? array_map('sanitize_text_field', wp_unslash($_POST['file_types'])) : array('jpg', 'jpeg', 'png', 'gif');
        $priority = isset($_POST['priority']) ? sanitize_text_field(wp_unslash($_POST['priority'])) : 'normal';
        
        // Get offloaded images that need optimization
        $attachment_ids = $this->get_optimizable_attachments($file_types);
        
        if (empty($attachment_ids)) {
            wp_send_json_error(__('No images found that need optimization.', 'bunny-media-offload'));
        }
        
        // Add to optimization queue
        $added = $this->add_to_optimization_queue($attachment_ids, $priority);
        
        wp_send_json_success(array(
            // translators: %d is the number of images added to the queue
            'message' => sprintf(__('Added %d images to optimization queue.', 'bunny-media-offload'), $added),
            'total' => $added
        ));
    }
    
    /**
     * Get optimization status
     */
    public function get_optimization_status() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');

        // Try to get cached stats first
        $stats_cache_key = 'bunny_optimization_queue_stats';
        $stats = wp_cache_get($stats_cache_key, 'bunny_media_offload');
        
        if ($stats === false) {
            global $wpdb;
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching implemented above
            $stats = $wpdb->get_row("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped
                FROM {$wpdb->prefix}bunny_optimization_queue
            ");
            
            // Cache for 1 minute
            wp_cache_set($stats_cache_key, $stats, 'bunny_media_offload', MINUTE_IN_SECONDS);
        }
        
        wp_send_json_success($stats);
    }
    
    /**
     * Get attachments that can be optimized
     */
    private function get_optimizable_attachments($file_types = array()) {
        global $wpdb;
        
        $file_types_sql = "'" . implode("','", array_map('esc_sql', $file_types)) . "'";
        
        $sql = "
            SELECT p.ID 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->prefix}bunny_offloaded_files bof ON p.ID = bof.attachment_id
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type LIKE 'image/%'
            AND LOWER(SUBSTRING_INDEX(p.post_mime_type, '/', -1)) IN ({$file_types_sql})
            AND p.ID NOT IN (
                SELECT attachment_id 
                -- phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Using safe table name with wpdb prefix
                FROM {$this->queue_table} 
                WHERE status IN ('pending', 'processing')
            )
        ";
        
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is safely constructed with escaped values above, caching not suitable for dynamic query filtering
        $results = $wpdb->get_col($sql);
        
        // Apply WPML filter to avoid optimizing duplicate translations
        return apply_filters('bunny_optimization_attachments', $results);
    }
    
    /**
     * Utility methods
     */
    private function is_image($file_path) {
        $file_type = wp_check_filetype($file_path);
        return strpos($file_type['type'], 'image/') === 0;
    }
    
    private function get_max_file_size() {
        $threshold = $this->settings->get('optimization_max_size', '50kb');
        return self::SIZE_THRESHOLDS[$threshold] ?? self::SIZE_THRESHOLDS['50kb'];
    }
    
    private function update_attachment_optimization_meta($attachment_id, $result) {
        update_post_meta($attachment_id, '_bunny_optimized', true);
        update_post_meta($attachment_id, '_bunny_optimization_data', $result);
        update_post_meta($attachment_id, '_bunny_last_optimized', current_time('mysql'));
    }
    
    private function log_optimization_result($file_path, $result) {
        $this->logger->log('info', "Image optimization completed", array(
            'file' => $file_path,
            'original_size' => $result['original_size'],
            'optimized_size' => $result['optimized_size'],
            'compression_ratio' => $result['compression_ratio'] . '%',
            'format_change' => $result['original_format'] . ' → ' . $result['optimized_format']
        ));
    }
    
    private function update_optimization_stats($result) {
        $stats = get_option('bunny_optimization_stats', array(
            'total_optimized' => 0,
            'total_savings' => 0,
            'total_original_size' => 0,
            'total_optimized_size' => 0
        ));
        
        $stats['total_optimized']++;
        $stats['total_savings'] += ($result['original_size'] - $result['optimized_size']);
        $stats['total_original_size'] += $result['original_size'];
        $stats['total_optimized_size'] += $result['optimized_size'];
        
        update_option('bunny_optimization_stats', $stats);
    }
    
    /**
     * Get optimization statistics for dashboard
     */
    public function get_optimization_stats() {
        // Try to get cached stats first
        $stats_cache_key = 'bunny_optimization_dashboard_stats';
        $cached_stats = wp_cache_get($stats_cache_key, 'bunny_media_offload');
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        global $wpdb;
        
        // Get stats from the options table (for backward compatibility)
        $stats = get_option('bunny_optimization_stats', array(
            'total_optimized' => 0,
            'total_savings' => 0,
            'total_original_size' => 0,
            'total_optimized_size' => 0
        ));
        
        // Get the actual count of images that have been optimized by checking postmeta
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching implemented above
        $actually_optimized_count = $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_bunny_optimization_data'
        ");
        
        $stats['images_actually_optimized'] = (int) $actually_optimized_count;
        
        // If we have optimized images but no stats from options table, calculate from metadata
        if ($stats['images_actually_optimized'] > 0 && $stats['total_savings'] == 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching implemented above
            $optimization_metadata = $wpdb->get_results("
                SELECT meta_value 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_bunny_optimization_data'
            ");
            
            $calculated_savings = 0;
            $calculated_original_size = 0;
            $calculated_optimized_size = 0;
            
            foreach ($optimization_metadata as $meta) {
                $data = maybe_unserialize($meta->meta_value);
                if (is_array($data) && isset($data['original_size']) && isset($data['optimized_size'])) {
                    $calculated_original_size += $data['original_size'];
                    $calculated_optimized_size += $data['optimized_size'];
                    $calculated_savings += ($data['original_size'] - $data['optimized_size']);
                }
            }
            
            $stats['total_savings'] = max(0, $calculated_savings);
            $stats['total_original_size'] = $calculated_original_size;
            $stats['total_optimized_size'] = $calculated_optimized_size;
        }
        
        // Calculate compression ratio
        if ($stats['total_original_size'] > 0) {
            $stats['compression_ratio'] = round(($stats['total_savings'] / $stats['total_original_size']) * 100, 2);
        } else {
            $stats['compression_ratio'] = 0;
        }
        
        // Format file sizes
        $stats['total_savings_formatted'] = Bunny_Utils::format_file_size($stats['total_savings']);
        $stats['total_original_size_formatted'] = Bunny_Utils::format_file_size($stats['total_original_size']);
        $stats['total_optimized_size_formatted'] = Bunny_Utils::format_file_size($stats['total_optimized_size']);
        
        // Cache for 5 minutes
        wp_cache_set($stats_cache_key, $stats, 'bunny_media_offload', 5 * MINUTE_IN_SECONDS);
        
        return $stats;
    }
    
    /**
     * Get optimization criteria analysis
     */
    public function get_optimization_criteria() {
        // Try to get cached criteria first
        $criteria_cache_key = 'bunny_optimization_criteria';
        $cached_criteria = wp_cache_get($criteria_cache_key, 'bunny_media_offload');
        
        if ($cached_criteria !== false) {
            return $cached_criteria;
        }
        
        global $wpdb;
        
        $max_size = $this->get_max_file_size();
        
        // Get all image attachments
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching implemented above
        $all_images = $wpdb->get_results("
            SELECT p.ID, p.post_mime_type, m.meta_value as file_path
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_wp_attached_file'
            WHERE p.post_type = 'attachment' 
            AND p.post_mime_type LIKE 'image/%'
        ");
        
        $oversized_count = 0;
        $format_conversion_count = 0;
        $already_optimized_count = 0;
        $total_eligible = 0;
        
        $upload_dir = wp_upload_dir();
        
        foreach ($all_images as $image) {
            if (!$image->file_path) continue;
            
            $file_path = $upload_dir['basedir'] . '/' . $image->file_path;
            
            if (!file_exists($file_path)) continue;
            
            $file_size = filesize($file_path);
            $file_info = pathinfo($file_path);
            $current_format = strtolower($file_info['extension']);
            
            $needs_optimization = false;
            
            // Check if oversized (including WebP/AVIF that exceed size limit)
            if ($file_size > $max_size) {
                $oversized_count++;
                $needs_optimization = true;
            }
            
            // Check if needs format conversion (JPG/PNG to WebP/AVIF)
            if (!in_array($current_format, array('webp', 'avif'))) {
                $format_conversion_count++;
                $needs_optimization = true;
            }
            
            // Check if already optimized (WebP/AVIF under size limit)
            if (in_array($current_format, array('webp', 'avif')) && $file_size <= $max_size) {
                $already_optimized_count++;
            }
            
            if ($needs_optimization) {
                $total_eligible++;
            }
        }
        
        $criteria = array(
            'total_images' => count($all_images),
            'total_eligible' => $total_eligible,
            'oversized_count' => $oversized_count,
            'format_conversion_count' => $format_conversion_count,
            'already_optimized_count' => $already_optimized_count,
            'max_size_threshold' => round($max_size / 1024) . 'KB'
        );
        
        // Cache for 10 minutes
        wp_cache_set($criteria_cache_key, $criteria, 'bunny_media_offload', 10 * MINUTE_IN_SECONDS);
        
        return $criteria;
    }
    
    /**
     * Handle step-by-step optimization AJAX request
     */
    public function handle_step_optimization() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        

        
        $target = isset($_POST['optimization_target']) ? sanitize_text_field(wp_unslash($_POST['optimization_target'])) : 'local';
        
        // Always apply both optimization criteria (format conversion and recompression)
        $criteria = array('format_conversion', 'recompress_modern');
        
        // Get images that meet the criteria
        $images = $this->get_images_for_optimization($target, $criteria);
        
        if (empty($images)) {
            wp_send_json_error(__('No images found that meet the optimization criteria.', 'bunny-media-offload'));
        }
        
        // Start optimization process
        $session_id = 'opt_' . time() . '_' . wp_generate_password(8, false);
        
        $session_data = array(
            'id' => $session_id,
            'target' => $target,
            'criteria' => $criteria,
            'total_images' => count($images),
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'status' => 'running',
            'start_time' => time(),
            'errors' => array(),
            'images' => array_slice($images, 0, 100) // Process in chunks
        );
        
        set_transient('bunny_optimization_' . $session_id, $session_data, 2 * HOUR_IN_SECONDS);
        
        wp_send_json_success(array(
            'session_id' => $session_id,
            'total_images' => count($images),
            // translators: %d is the number of images to be optimized
            'message' => sprintf(__('Starting optimization of %d images...', 'bunny-media-offload'), count($images))
        ));
    }
    
    /**
     * AJAX handler for getting optimization criteria
     */
    public function ajax_get_optimization_criteria() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        $criteria = $this->get_optimization_criteria();
        wp_send_json_success($criteria);
    }
    
    /**
     * Get images for optimization based on target and criteria
     */
    private function get_images_for_optimization($target, $criteria) {
        // Create cache key based on target and criteria
        $cache_key = 'bunny_images_for_optimization_' . md5($target . serialize($criteria));
        $cached_images = wp_cache_get($cache_key, 'bunny_media_offload');
        
        if ($cached_images !== false) {
            return $cached_images;
        }
        
        global $wpdb;
        
        $max_size = $this->get_max_file_size();
        $upload_dir = wp_upload_dir();
        
        // Base query for all images
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching implemented above
        $images = $wpdb->get_results("
            SELECT p.ID, p.post_mime_type, m.meta_value as file_path,
                   bof.bunny_url, bof.id as bunny_id
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_wp_attached_file'
            LEFT JOIN {$wpdb->prefix}bunny_offloaded_files bof ON p.ID = bof.attachment_id
            WHERE p.post_type = 'attachment' 
            AND p.post_mime_type LIKE 'image/%'
        ");
        
        $eligible_images = array();
        
        foreach ($images as $image) {
            if (!$image->file_path) continue;
            
            $file_path = $upload_dir['basedir'] . '/' . $image->file_path;
            $is_local = file_exists($file_path);
            $is_cloud = !empty($image->bunny_url);
            
            // Filter by target location
            if ($target === 'local' && !$is_local) continue;
            if ($target === 'cloud' && !$is_cloud) continue;
            if ($target === 'all' && !$is_local && !$is_cloud) continue;
            
            // Get file size and format
            $file_size = $is_local ? filesize($file_path) : 0;
            $file_info = pathinfo($file_path);
            $current_format = strtolower($file_info['extension']);
            
            $meets_criteria = false;
            
            // Check against selected criteria
            if (in_array('size_threshold', $criteria) && $file_size > $max_size) {
                $meets_criteria = true;
            }
            
            if (in_array('format_conversion', $criteria) && !in_array($current_format, array('webp', 'avif'))) {
                $meets_criteria = true;
            }
            
            if (in_array('recompress_modern', $criteria) && 
                in_array($current_format, array('webp', 'avif')) && 
                $file_size > $max_size) {
                $meets_criteria = true;
            }
            
            if ($meets_criteria) {
                $eligible_images[] = array(
                    'id' => $image->ID,
                    'file_path' => $file_path,
                    'file_size' => $file_size,
                    'current_format' => $current_format,
                    'is_local' => $is_local,
                    'is_cloud' => $is_cloud,
                    'bunny_url' => $image->bunny_url
                );
            }
        }
        
        // Cache for 5 minutes
        wp_cache_set($cache_key, $eligible_images, 'bunny_media_offload', 5 * MINUTE_IN_SECONDS);
        
        return $eligible_images;
    }
    
    /**
     * Get detailed optimization statistics for the optimization page
     */
    public function get_detailed_optimization_stats() {
        $cache_key = 'bunny_detailed_optimization_stats';
        $cached_stats = wp_cache_get($cache_key, 'bunny_media_offload');
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        global $wpdb;
        
        $max_size = $this->get_max_file_size();
        $upload_dir = wp_upload_dir();
        $batch_size = $this->settings->get('optimization_batch_size', 60);
        
        // Get all image attachments with file paths and cloud status
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching implemented above
        $all_images = $wpdb->get_results("
            SELECT p.ID, p.post_mime_type, m.meta_value as file_path, bof.bunny_url
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_wp_attached_file'
            LEFT JOIN {$wpdb->prefix}bunny_offloaded_files bof ON p.ID = bof.attachment_id
            WHERE p.post_type = 'attachment' 
            AND p.post_mime_type LIKE 'image/%'
        ");
        
        // Get already optimized images count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching implemented above
        $optimized_count = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_bunny_optimized' 
            AND meta_value = '1'
        ");
        
        // Initialize counters for local and cloud
        $stats = array(
            'local' => array(
                'jpg_png_to_convert' => 0,
                'webp_avif_to_recompress' => 0,
                'total_eligible' => 0,
                'has_files_to_optimize' => false
            ),
            'cloud' => array(
                'jpg_png_to_convert' => 0,
                'webp_avif_to_recompress' => 0,
                'total_eligible' => 0,
                'has_files_to_optimize' => false
            ),
            'already_optimized' => (int) $optimized_count,
            'batch_size' => $batch_size,
            'max_size_threshold' => round($max_size / 1024) . 'KB'
        );
        
        foreach ($all_images as $image) {
            if (!$image->file_path) continue;
            
            $file_path = $upload_dir['basedir'] . '/' . $image->file_path;
            $is_local = file_exists($file_path);
            $is_cloud = !empty($image->bunny_url);
            
            // Skip if file doesn't exist anywhere
            if (!$is_local && !$is_cloud) continue;
            
            // Check if already optimized
            $is_optimized = get_post_meta($image->ID, '_bunny_optimized', true);
            if ($is_optimized) continue;
            
            $file_size = $is_local ? filesize($file_path) : 0;
            $file_info = pathinfo($file_path);
            $current_format = strtolower($file_info['extension']);
            
            $needs_optimization = false;
            $jpg_png_format = in_array($current_format, array('jpg', 'jpeg', 'png'));
            $webp_avif_oversized = in_array($current_format, array('webp', 'avif')) && $file_size > $max_size;
            
            if ($jpg_png_format || $webp_avif_oversized) {
                $needs_optimization = true;
            }
            
            if (!$needs_optimization) continue;
            
            // Count for local images
            if ($is_local) {
                if ($jpg_png_format) {
                    $stats['local']['jpg_png_to_convert']++;
                }
                if ($webp_avif_oversized) {
                    $stats['local']['webp_avif_to_recompress']++;
                }
                $stats['local']['total_eligible']++;
            }
            
            // Count for cloud images
            if ($is_cloud) {
                if ($jpg_png_format) {
                    $stats['cloud']['jpg_png_to_convert']++;
                }
                if ($webp_avif_oversized) {
                    $stats['cloud']['webp_avif_to_recompress']++;
                }
                $stats['cloud']['total_eligible']++;
            }
        }
        
        // Show as optimizable if there are files to optimize
        $stats['local']['has_files_to_optimize'] = $stats['local']['total_eligible'] > 0;
        $stats['cloud']['has_files_to_optimize'] = $stats['cloud']['total_eligible'] > 0;
        
        // Cache for 5 minutes
        wp_cache_set($cache_key, $stats, 'bunny_media_offload', 5 * MINUTE_IN_SECONDS);
        
        return $stats;
    }
    
    /**
     * Process optimization batch
     */
    public function ajax_optimization_batch() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        $session = get_transient('bunny_optimization_' . $session_id);
        
        if (!$session) {
            wp_send_json_error(array('message' => __('Optimization session not found.', 'bunny-media-offload')));
        }
        
        if ($session['status'] === 'cancelled') {
            wp_send_json_error(array('message' => __('Optimization was cancelled.', 'bunny-media-offload')));
        }
        
        $batch_size = $this->settings->get('optimization_batch_size', 60);
        $result = $this->process_optimization_batch($session, $batch_size);
        
        // Update session
        set_transient('bunny_optimization_' . $session_id, array_merge($session, array(
            'processed' => $result['processed'],
            'successful' => $result['successful'],
            'failed' => $result['failed'],
            'errors' => array_merge($session['errors'], $result['errors']),
            'status' => $result['completed'] ? 'completed' : 'running'
        )), 2 * HOUR_IN_SECONDS);
        
        $response = array(
            'processed' => $result['processed'],
            'successful' => $result['successful'],
            'failed' => $result['failed'],
            'total' => $session['total_images'],
            'completed' => $result['completed'],
            'progress' => $session['total_images'] > 0 ? round(($result['processed'] / $session['total_images']) * 100, 2) : 0,
            'errors' => $result['errors']
        );
        
        if ($result['completed']) {
            $this->logger->log('info', 'Optimization completed', array(
                'session_id' => $session_id,
                'total_processed' => $result['processed'],
                'successful' => $result['successful'],
                'failed' => $result['failed']
            ));
            
            $response['message'] = sprintf(
                // translators: %1$d is the number of images processed, %2$d is the number of successful optimizations, %3$d is the number of failed optimizations
                __('Optimization completed. %1$d images processed, %2$d successful, %3$d failed.', 'bunny-media-offload'),
                $result['processed'],
                $result['successful'],
                $result['failed']
            );
        }
        
        wp_send_json_success($response);
    }
    
    /**
     * Cancel optimization
     */
    public function ajax_cancel_optimization() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        $session = get_transient('bunny_optimization_' . $session_id);
        
        if ($session) {
            $session['status'] = 'cancelled';
            set_transient('bunny_optimization_' . $session_id, $session, 2 * HOUR_IN_SECONDS);
        }
        
        $this->logger->log('info', 'Optimization cancelled', array('session_id' => $session_id));
        
        wp_send_json_success(array('message' => __('Optimization cancelled.', 'bunny-media-offload')));
    }
    
    /**
     * Process a batch of optimization tasks
     */
    private function process_optimization_batch($session, $batch_size) {
        $images_to_process = array_slice($session['images'], $session['processed'], $batch_size);
        
        $successful = 0;
        $failed = 0;
        $errors = array();
        
        foreach ($images_to_process as $image) {
            if (!isset($image['ID'])) {
                $failed++;
                $errors[] = __('Invalid image data', 'bunny-media-offload');
                continue;
            }
            
            $result = $this->optimize_image_by_id($image['ID']);
            
            if ($result) {
                $successful++;
            } else {
                $failed++;
                // translators: %d is the attachment ID
                $errors[] = sprintf(__('Failed to optimize image ID %d', 'bunny-media-offload'), $image['ID']);
            }
        }
        
        $total_processed = $session['processed'] + count($images_to_process);
        $completed = $total_processed >= $session['total_images'];
        
        return array(
            'processed' => $total_processed,
            'successful' => $session['successful'] + $successful,
            'failed' => $session['failed'] + $failed,
            'errors' => $errors,
            'completed' => $completed
        );
    }
    
    /**
     * Optimize image by attachment ID
     */
    private function optimize_image_by_id($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }
        
        return $this->optimize_image($file_path, $attachment_id);
    }
} 