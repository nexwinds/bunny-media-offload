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
     * Supported input formats for conversion to AVIF
     */
    const CONVERTIBLE_FORMATS = array('jpg', 'jpeg', 'png', 'heic');
    
    /**
     * Modern formats that can be recompressed
     */
    const COMPRESSIBLE_FORMATS = array('webp', 'avif');
    
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
            $error_msg = "File is not a valid image format: " . basename($file_path);
            $this->logger->log('warning', $error_msg);
            return array('error' => $error_msg);
        }
        
        if (!file_exists($file_path)) {
            $error_msg = "Image file not found: " . basename($file_path);
            $this->logger->log('error', $error_msg);
            return array('error' => $error_msg);
        }
        
        $original_size = filesize($file_path);
        $max_size = $this->get_max_file_size();
        $target_format = $this->determine_target_format($file_path);
        
        // Check if optimization is needed
        if (!$this->needs_optimization($file_path, $original_size, $max_size)) {
            $file_info = pathinfo($file_path);
            $current_format = strtolower($file_info['extension']);
            $file_size_kb = round($original_size / 1024, 1);
            
            $skip_reason = $this->get_skip_reason($current_format, $original_size, $max_size);
            
            $this->logger->log('info', "Image skipped - {$skip_reason}: " . basename($file_path));
            return array(
                'optimized_path' => $file_path,
                'original_size' => $original_size,
                'optimized_size' => $original_size,
                'optimized_format' => $current_format,
                'compression_ratio' => 0,
                'optimization_date' => current_time('mysql'),
                'skipped' => true,
                'skip_reason' => $skip_reason
            );
        }
        
        $optimization_result = $this->perform_optimization($file_path, $target_format, $max_size);
        
        if ($optimization_result && is_array($optimization_result)) {
            // Update attachment metadata if available
            if ($attachment_id) {
                $this->update_attachment_optimization_meta($attachment_id, $optimization_result);
            }
            
            // Log optimization results
            $this->log_optimization_result($file_path, $optimization_result);
            
            return $optimization_result;
        }
        
        return false;
    }
    
    /**
     * Check if image needs optimization
     * Optimization criteria:
     * 1. For JPEG/JPG/PNG/HEIC: Convert to AVIF (only if exceeds max size)
     * 2. For WEBP/AVIF: Compress if exceeds max size
     * 3. Other formats: Ignore
     */
    private function needs_optimization($file_path, $file_size, $max_size) {
        $file_info = pathinfo($file_path);
        $current_format = strtolower($file_info['extension']);
        
        // Only process images that exceed the maximum file size
        if ($file_size <= $max_size) {
            return false;
        }
        
        // Check if format can be converted to AVIF
        if (in_array($current_format, self::CONVERTIBLE_FORMATS)) {
            return true;
        }
        
        // Check if modern format needs recompression
        if (in_array($current_format, self::COMPRESSIBLE_FORMATS)) {
            return true;
        }
        
        // Ignore other formats
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
            $error_msg = "Optimization failed for " . basename($file_path) . ": " . $e->getMessage();
            $this->logger->log('error', $error_msg, array(
                'file' => $file_path,
                'exception' => $e->getMessage()
            ));
            return array('error' => $error_msg);
        }
        
        $error_msg = "Failed to optimize " . basename($file_path) . ": Unknown error during processing";
        $this->logger->log('error', $error_msg);
        return array('error' => $error_msg);
    }
    
    /**
     * Load image using appropriate method
     */
    private function load_image($file_path) {
        $file_info = pathinfo($file_path);
        $extension = strtolower($file_info['extension']);
        
        // Handle HEIC files specially
        if ($extension === 'heic') {
            return $this->load_heic_image($file_path);
        }
        
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
            case IMAGETYPE_AVIF:
                if (function_exists('imagecreatefromavif')) {
                    return imagecreatefromavif($file_path);
                }
                break;
        }
        
        return false;
    }
    
    /**
     * Load HEIC image (requires ImageMagick or similar)
     */
    private function load_heic_image($file_path) {
        // Try ImageMagick first
        if (extension_loaded('imagick')) {
            try {
                $imagick = new Imagick($file_path);
                $imagick->setImageFormat('png');
                
                // Create GD resource from ImageMagick
                $temp_png = tempnam(sys_get_temp_dir(), 'heic_convert_');
                $imagick->writeImage($temp_png);
                $gd_image = imagecreatefrompng($temp_png);
                wp_delete_file($temp_png);
                
                return $gd_image;
            } catch (Exception $e) {
                $this->logger->log('warning', "Failed to load HEIC with ImageMagick: " . $e->getMessage());
            }
        }
        
        // Log that HEIC conversion is not available
        $this->logger->log('warning', "HEIC format not supported - ImageMagick extension required");
        return false;
    }
    
    /**
     * Determine the target format based on current format
     */
    private function determine_target_format($file_path) {
        $file_info = pathinfo($file_path);
        $current_format = strtolower($file_info['extension']);
        
        // Convert JPEG/PNG/HEIC to AVIF
        if (in_array($current_format, self::CONVERTIBLE_FORMATS)) {
            return 'avif';
        }
        
        // Keep WEBP/AVIF formats but recompress them
        if (in_array($current_format, self::COMPRESSIBLE_FORMATS)) {
            return $current_format;
        }
        
        // Fallback to AVIF for unknown formats
        return 'avif';
    }
    
    /**
     * Get skip reason for optimization
     */
    private function get_skip_reason($current_format, $file_size, $max_size) {
        $file_size_kb = round($file_size / 1024, 1);
        $max_size_kb = round($max_size / 1024, 1);
        
        if ($file_size <= $max_size) {
            return "File size ({$file_size_kb}KB) is under {$max_size_kb}KB threshold";
        }
        
        if (!in_array($current_format, array_merge(self::CONVERTIBLE_FORMATS, self::COMPRESSIBLE_FORMATS))) {
            return "Unsupported file format: {$current_format}";
        }
        
        return "Image already optimized";
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
        $size_setting = $this->settings->get('optimization_max_size', '50kb');
        
        // Parse size setting (e.g., "50kb", "1mb")
        if (preg_match('/^(\d+)(kb|mb)$/i', $size_setting, $matches)) {
            $value = (int) $matches[1];
            $unit = strtolower($matches[2]);
            
            if ($unit === 'kb') {
                return $value * 1024;
            } elseif ($unit === 'mb') {
                return $value * 1024 * 1024;
            }
        }
        
        // Default to 50KB if setting is invalid
        return 50 * 1024;
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
     * Get optimization criteria analysis (simplified version)
     */
    public function get_optimization_criteria() {
        $detailed_stats = $this->get_detailed_optimization_stats();
        
        return array(
            'total_images' => $detailed_stats['local']['total_eligible'] + $detailed_stats['cloud']['total_eligible'] + $detailed_stats['already_optimized'],
            'total_eligible' => $detailed_stats['local']['total_eligible'] + $detailed_stats['cloud']['total_eligible'],
            'convertible_formats_count' => $detailed_stats['local']['convertible_formats'] + $detailed_stats['cloud']['convertible_formats'],
            'compressible_formats_count' => $detailed_stats['local']['compressible_formats'] + $detailed_stats['cloud']['compressible_formats'],
            'already_optimized_count' => $detailed_stats['already_optimized'],
            'max_size_threshold' => $detailed_stats['max_size_threshold']
        );
    }
    
    /**
     * Handle step-by-step optimization AJAX request
     */
    public function handle_step_optimization() {
        if (!$this->validate_optimization_request()) {
            return;
        }
        
        $target = $this->get_optimization_target();
        $images = $this->gather_optimization_images($target);
        
        if (empty($images)) {
            wp_send_json_error('No images found that meet the optimization criteria.');
            return;
        }
        
        $session_data = $this->create_optimization_session($target, $images);
        
        if (!$session_data) {
            wp_send_json_error('Failed to create optimization session.');
            return;
        }
        
        wp_send_json_success(array(
            'session_id' => $session_data['id'],
            'total_images' => count($images),
            'message' => sprintf('Starting optimization of %d images...', count($images))
        ));
    }
    
    /**
     * Validate optimization request permissions and nonce
     */
    private function validate_optimization_request() {
        try {
            check_ajax_referer('bunny_ajax_nonce', 'nonce');
        } catch (Exception $e) {
            wp_send_json_error('Security check failed.');
            return false;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.');
            return false;
        }
        
        return true;
    }
    
    /**
     * Get and validate optimization target from request
     */
    private function get_optimization_target() {
        // Verify nonce for security
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'bunny_optimization_action')) {
            return 'local'; // Default fallback if nonce verification fails
        }
        
        if (isset($_POST['optimization_target'])) {
            return sanitize_text_field(wp_unslash($_POST['optimization_target']));
        }
        return 'local';
    }
    
    /**
     * Gather images for optimization based on target
     */
    private function gather_optimization_images($target) {
        try {
            // Always apply both optimization criteria (format conversion and recompression)
            $criteria = array('format_conversion', 'recompress_modern');
            
            $images = $this->get_images_for_optimization($target, $criteria);
            
            $this->logger->log('info', "Found " . count($images) . " images for optimization", array(
                'target' => $target,
                'criteria' => $criteria,
                'image_count' => count($images)
            ));
            
            return $images;
        } catch (Exception $e) {
            $this->logger->log('error', 'Exception in image gathering: ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Create optimization session with transient storage
     */
    private function create_optimization_session($target, $images) {
        try {
            $session_id = 'opt_' . time() . '_' . wp_generate_password(8, false);
            
            $session_data = array(
                'id' => $session_id,
                'target' => $target,
                'criteria' => array('format_conversion', 'recompress_modern'),
                'total_images' => count($images),
                'processed' => 0,
                'successful' => 0,
                'failed' => 0,
                'status' => 'running',
                'start_time' => time(),
                'errors' => array(),
                'images' => array_slice($images, 0, 100) // Process in chunks
            );
            
            // Save session and verify
            $transient_set = set_transient('bunny_optimization_' . $session_id, $session_data, 2 * HOUR_IN_SECONDS);
            $verification = get_transient('bunny_optimization_' . $session_id);
            
            if (!$transient_set || !$verification) {
                $this->logger->log('error', "Failed to save optimization session transient: " . $session_id);
                return false;
            }
            
            $this->logger->log('info', "Created optimization session: " . $session_id, array(
                'target' => $target,
                'total_images' => count($images)
            ));
            
            return $session_data;
            
        } catch (Exception $e) {
            $this->logger->log('error', 'Exception in session creation: ' . $e->getMessage());
            return false;
        }
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
            
            // Use the same optimization criteria as the main logic
            if ($this->needs_optimization($file_path, $file_size, $max_size)) {
                $eligible_images[] = array(
                    'ID' => $image->ID,
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
                'convertible_formats' => 0,  // JPEG/PNG/HEIC to AVIF
                'compressible_formats' => 0, // WEBP/AVIF recompression
                'total_eligible' => 0,
                'has_files_to_optimize' => false
            ),
            'cloud' => array(
                'convertible_formats' => 0,
                'compressible_formats' => 0,
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
            
            // Use the same optimization criteria as the main logic
            if (!$this->needs_optimization($file_path, $file_size, $max_size)) {
                continue;
            }
            
            $is_convertible = in_array($current_format, self::CONVERTIBLE_FORMATS);
            $is_compressible = in_array($current_format, self::COMPRESSIBLE_FORMATS);
            
            // Count for local images
            if ($is_local) {
                if ($is_convertible) {
                    $stats['local']['convertible_formats']++;
                }
                if ($is_compressible) {
                    $stats['local']['compressible_formats']++;
                }
                $stats['local']['total_eligible']++;
            }
            
            // Count for cloud images
            if ($is_cloud) {
                if ($is_convertible) {
                    $stats['cloud']['convertible_formats']++;
                }
                if ($is_compressible) {
                    $stats['cloud']['compressible_formats']++;
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
        
        // Log session retrieval attempt
        $this->logger->log('info', "Attempting to retrieve session: " . $session_id, array(
            'session_found' => !empty($session),
            'session_data' => $session
        ));
        
        if (!$session) {
            $this->logger->log('error', "Optimization session not found: " . $session_id);
            wp_send_json_error(array('message' => __('Optimization session not found.', 'bunny-media-offload')));
        }
        
        if ($session['status'] === 'cancelled') {
            wp_send_json_error(array('message' => __('Optimization was cancelled.', 'bunny-media-offload')));
        }
        
        $batch_size = $this->settings->get('optimization_batch_size', 60);
        $result = $this->process_optimization_batch($session, $batch_size, $session_id);
        
        // Update session
        set_transient('bunny_optimization_' . $session_id, array_merge($session, array(
            'processed' => $result['processed'],
            'successful' => $result['successful'],
            'failed' => $result['failed'],
            'errors' => array_merge($session['errors'], $result['errors']),
            'status' => $result['completed'] ? 'completed' : 'running',
            'current_step' => $session['current_step'] ?? null
        )), 2 * HOUR_IN_SECONDS);
        
        $response = array(
            'processed' => $result['processed'],
            'successful' => $result['successful'],
            'failed' => $result['failed'],
            'total' => $session['total_images'],
            'completed' => $result['completed'],
            'progress' => $session['total_images'] > 0 ? round(($result['processed'] / $session['total_images']) * 100, 2) : 0,
            'errors' => $result['errors'],
            'current_image' => $result['current_image'],
            'recent_processed' => $result['recent_processed'],
            'current_step' => $session['current_step'] ?? null
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
    private function process_optimization_batch($session, $batch_size, $session_id = null) {
        $concurrent_limit = $this->settings->get('optimization_concurrent_limit', 3);
        $start_index = $session['processed'];
        $images_to_process = array_slice($session['images'], $start_index, min($batch_size, $concurrent_limit));
        
        $successful = 0;
        $failed = 0;
        $errors = array();
        $recent_processed = array();
        $current_image = null;
        
        // If no images to process, mark as completed
        if (empty($images_to_process)) {
            $total_processed = $session['processed'];
            $completed = $total_processed >= $session['total_images'];
            
            return array(
                'processed' => $total_processed,
                'successful' => $session['successful'],
                'failed' => $session['failed'],
                'errors' => array(),
                'completed' => true,
                'current_image' => null,
                'recent_processed' => array()
            );
        }
        
        // Process images concurrently (simulate by processing in chunks)
        foreach ($images_to_process as $index => $image) {
            if (!isset($image['ID'])) {
                $failed++;
                $errors[] = __('Invalid image data', 'bunny-media-offload');
                $this->logger->log('error', 'Invalid image data in batch processing', array('image' => $image));
                continue;
            }
            
            $attachment_id = $image['ID'];
            
            // Get image data for UI
            $image_data = $this->get_image_data_for_ui($attachment_id, $session['target']);
            
            // Set current image (first one being processed)
            if ($index === 0) {
                $current_image = $image_data;
                $current_image['status'] = 'processing';
            }
            
            $result = $this->process_single_image($attachment_id, $image_data, $session);
            
            if ($result['success']) {
                $successful++;
                $current_image['status'] = 'completed';
            } else {
                $failed++;
                $current_image['status'] = 'error';
                // translators: %1$s is the image name, %2$s is the error message
                $errors[] = sprintf(__('%1$s: %2$s', 'bunny-media-offload'), $image_data['name'], $result['error_message']);
            }
            
            $recent_processed[] = $result['ui_data'];
        }
        
        $total_processed = $session['processed'] + count($images_to_process);
        $completed = $total_processed >= $session['total_images'];
        
        return array(
            'processed' => $total_processed,
            'successful' => $session['successful'] + $successful,
            'failed' => $session['failed'] + $failed,
            'errors' => $errors,
            'completed' => $completed,
            'current_image' => $current_image,
            'recent_processed' => $recent_processed
        );
    }
    
    /**
     * Process a single image for batch optimization
     */
    private function process_single_image($attachment_id, $image_data, $session) {
        try {
            // Process image based on target location
            if ($session['target'] === 'cloud') {
                $result = $this->process_cloud_image($attachment_id, $image_data, $session['id']);
            } else {
                $result = $this->process_local_image($attachment_id, $image_data, $session['id']);
            }
            
            if ($result && !isset($result['error'])) {
                $this->logger->log('info', "Successfully optimized image ID " . $attachment_id, array(
                    'result' => $result,
                    'target' => $session['target']
                ));
                
                return array(
                    'success' => true,
                    'ui_data' => array(
                        'name' => $image_data['name'],
                        'thumbnail' => $image_data['thumbnail'],
                        'success' => true,
                        'action' => $result['action'] ?? ($session['target'] === 'cloud' ? 'Downloaded, optimized & uploaded' : 'Converted to AVIF'),
                        'size_reduction' => $result['size_reduction'] ?? 0
                    )
                );
            } else {
                $error_message = isset($result['error']) ? $result['error'] : 'Optimization failed';
                $this->logger->log('warning', "Failed to optimize image ID " . $attachment_id . ": " . $error_message);
                
                return array(
                    'success' => false,
                    'error_message' => $error_message,
                    'ui_data' => array(
                        'name' => $image_data['name'],
                        'thumbnail' => $image_data['thumbnail'],
                        'success' => false,
                        'action' => $error_message,
                        'size_reduction' => 0
                    )
                );
            }
            
        } catch (Exception $e) {
            $exception_msg = "Processing exception: " . $e->getMessage();
            
            $this->logger->log('error', "Exception optimizing image ID " . $attachment_id . ": " . $e->getMessage(), array(
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            
            return array(
                'success' => false,
                'error_message' => $exception_msg,
                'ui_data' => array(
                    'name' => $image_data['name'],
                    'thumbnail' => $image_data['thumbnail'],
                    'success' => false,
                    'action' => $exception_msg,
                    'size_reduction' => 0
                )
            );
        }
    }
    
    /**
     * Get image data for UI display
     */
    private function get_image_data_for_ui($attachment_id, $target = 'local') {
        $file_path = get_attached_file($attachment_id);
        $file_name = basename($file_path);
        $post_title = get_the_title($attachment_id);
        
        // Get thumbnail URL
        $thumbnail_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
        if (!$thumbnail_url) {
            // Fallback to medium size or full size
            $thumbnail_url = wp_get_attachment_image_url($attachment_id, 'medium') ?: wp_get_attachment_url($attachment_id);
        }
        
        // Final fallback to avoid empty thumbnails
        if (!$thumbnail_url) {
            $thumbnail_url = includes_url('images/media/default.png');
        }
        
        return array(
            'name' => $post_title ?: $file_name,
            'thumbnail' => $thumbnail_url ?: '',
            'file_path' => $file_path,
            'file_size' => file_exists($file_path) ? filesize($file_path) : 0,
            'type' => $target
        );
    }
    
    /**
     * Update session with current step information
     */
    private function update_session_step($session_id, $step_data) {
        $session = get_transient('bunny_optimization_' . $session_id);
        if ($session) {
            $session['current_step'] = $step_data;
            set_transient('bunny_optimization_' . $session_id, $session, 2 * HOUR_IN_SECONDS);
        }
    }
    

    

    
    /**
     * Download cloud image for local processing
     */
    private function download_cloud_image($attachment_id) {
        // Get the cloud URL
        $cloud_url = get_post_meta($attachment_id, '_bunny_cdn_url', true);
        if (!$cloud_url) {
            return false;
        }
        
        // Create temporary file
        $temp_dir = wp_upload_dir()['basedir'] . '/bunny-temp/';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        
        $temp_file = $temp_dir . 'opt_' . $attachment_id . '_' . time() . '.tmp';
        
        // Download file
        $response = wp_remote_get($cloud_url, array(
            'timeout' => 60,
            'stream' => true,
            'filename' => $temp_file
        ));
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            if (file_exists($temp_file)) {
                wp_delete_file($temp_file);
            }
            return false;
        }
        
        return $temp_file;
    }
    
    /**
     * Upload optimized image back to cloud
     */
    private function upload_optimized_to_cloud($local_path, $attachment_id) {
        if (!$this->api) {
            return false;
        }
        
        try {
            // Get original file info
            $original_file = get_attached_file($attachment_id);
            $relative_path = str_replace(wp_upload_dir()['basedir'] . '/', '', $original_file);
            
            // Upload optimized version
            $upload_result = $this->api->upload_file($local_path, $relative_path);
            
            if ($upload_result) {
                // Update attachment metadata
                update_post_meta($attachment_id, '_bunny_last_optimized', current_time('mysql'));
                update_post_meta($attachment_id, '_bunny_optimized', true);
                
                return true;
            }
        } catch (Exception $e) {
            $this->logger->log('error', 'Failed to upload optimized image to cloud', array(
                'attachment_id' => $attachment_id,
                'error' => $e->getMessage()
            ));
        }
        
        return false;
    }
    
    /**
     * Optimize image by attachment ID
     */
    private function optimize_image_by_id($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }
        
        $result = $this->optimize_image($file_path, $attachment_id);
        
        // Ensure we return array data for the UI, not just file path
        if ($result && !is_array($result)) {
            // If optimize_image returned just a file path, create a basic result array
            $original_size = filesize($file_path);
            $optimized_size = file_exists($result) ? filesize($result) : $original_size;
            
            return array(
                'optimized_path' => $result,
                'original_size' => $original_size,
                'optimized_size' => $optimized_size,
                'optimized_format' => 'avif',
                'compression_ratio' => $original_size > 0 ? round((($original_size - $optimized_size) / $original_size) * 100, 2) : 0
            );
        }
        
        return $result;
    }
    

    
    /**
     * Process cloud image (download, optimize, upload)
     */
    private function process_cloud_image($attachment_id, $image_data, $session_id = null) {
        // Step 1: Download image from cloud
        if ($session_id) {
            $this->update_session_step($session_id, array(
                'attachment_id' => $attachment_id,
                'step' => 'downloading',
                'message' => 'Downloading from CDN...'
            ));
        }
        
        $local_path = $this->download_cloud_image($attachment_id);
        if (!$local_path) {
            return array('error' => 'Failed to download from CDN');
        }
        
        // Step 2: Optimize locally
        if ($session_id) {
            $this->update_session_step($session_id, array(
                'attachment_id' => $attachment_id,
                'step' => 'converting',
                'message' => 'Converting to AVIF...'
            ));
        }
        
        $result = $this->optimize_image($local_path, $attachment_id);
        if (!$result || isset($result['error'])) {
            // Clean up downloaded file
            if (file_exists($local_path)) {
                wp_delete_file($local_path);
            }
            $error_msg = isset($result['error']) ? $result['error'] : "Unknown optimization error";
            return array('error' => "Cloud optimization failed: " . $error_msg);
        }
        
        // Step 3: Upload optimized image back to cloud
        if ($session_id) {
            $this->update_session_step($session_id, array(
                'attachment_id' => $attachment_id,
                'step' => 'uploading',
                'message' => 'Uploading to CDN...'
            ));
        }
        
        $upload_result = $this->upload_optimized_to_cloud($local_path, $attachment_id);
        
        // Clean up local files
        if (file_exists($local_path)) {
            wp_delete_file($local_path);
        }
        
        if ($upload_result) {
            if ($session_id) {
                $this->update_session_step($session_id, array(
                    'attachment_id' => $attachment_id,
                    'step' => 'completed',
                    'message' => 'Upload completed'
                ));
            }
            
            return array(
                'action' => 'Downloaded, optimized & uploaded to CDN',
                'size_reduction' => $result['compression_ratio'] ?? 0,
                'original_size' => $result['original_size'] ?? 0,
                'new_size' => $result['optimized_size'] ?? 0
            );
        }
        
        return array('error' => 'Failed to upload to CDN');
    }
    
    /**
     * Process local image (optimize and replace)
     */
    private function process_local_image($attachment_id, $image_data, $session_id = null) {
        if ($session_id) {
            $this->update_session_step($session_id, array(
                'attachment_id' => $attachment_id,
                'step' => 'converting',
                'message' => 'Converting to AVIF...'
            ));
        }
        
        $result = $this->optimize_image_by_id($attachment_id);
        
        if ($result && is_array($result) && !isset($result['error'])) {
            if ($session_id) {
                $this->update_session_step($session_id, array(
                    'attachment_id' => $attachment_id,
                    'step' => 'completed',
                    'message' => 'Optimization completed'
                ));
            }
            
            // Calculate size reduction percentage
            $original_size = $result['original_size'] ?? 0;
            $optimized_size = $result['optimized_size'] ?? 0;
            $size_reduction = 0;
            
            if ($original_size > 0 && $optimized_size > 0) {
                $size_reduction = round((($original_size - $optimized_size) / $original_size) * 100, 1);
            }
            
            return array(
                'action' => 'Converted to ' . strtoupper($result['optimized_format'] ?? 'AVIF'),
                'size_reduction' => $size_reduction,
                'original_size' => $original_size,
                'new_size' => $optimized_size,
                'compression_ratio' => $result['compression_ratio'] ?? $size_reduction
            );
        }
        
        // Return error if optimization failed
        if ($result && isset($result['error'])) {
            return array('error' => $result['error']);
        }
        
        return array('error' => 'Local optimization failed: Unknown error');
    }
} 