<?php
/**
 * Bunny Media Optimizer
 * 
 * Handles the image optimization functionality using the BMO API
 */
class Bunny_Optimization {
    
    /**
     * Settings instance
     */
    private $settings;
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * API instance
     */
    private $api;
    
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
        // Check database tables
        $this->check_database_tables();
        
        // Add optimization hooks
        add_action('wp_ajax_bunny_get_optimization_stats', array($this, 'ajax_get_optimization_stats'));
        add_action('wp_ajax_bunny_start_optimization', array($this, 'ajax_start_optimization'));
        add_action('wp_ajax_bunny_optimization_batch', array($this, 'ajax_optimization_batch'));
        add_action('wp_ajax_bunny_cancel_optimization', array($this, 'ajax_cancel_optimization'));
        add_action('wp_ajax_bunny_run_optimization_diagnostics', array($this, 'ajax_run_diagnostics'));
        
        // Hook into upload for auto-optimization if enabled
        add_filter('wp_handle_upload', array($this, 'handle_upload'), 10, 2);
    }
    
    /**
     * Check and create required database tables
     */
    private function check_database_tables() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bunny_optimization_queue';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            // Table doesn't exist, create it
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                attachment_id bigint(20) unsigned NOT NULL,
                status varchar(50) NOT NULL DEFAULT 'pending',
                error_message text,
                date_created datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                date_updated datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY attachment_id (attachment_id),
                KEY status (status)
            ) $charset_collate;";
            
            dbDelta($sql);
            
            // Log the table creation
            $this->logger->log('debug', 'Created optimization queue table');
        }
    }
    
    /**
     * Get optimization statistics
     */
    public function get_optimization_stats() {
        global $wpdb;
        
        // Check if stats class is available through global
        global $bunny_stats;
        if ($bunny_stats) {
            // Use unified stats for consistency across all plugin pages
            $unified_stats = $bunny_stats->get_unified_image_stats();
            
            // Get eligible images count from direct query with optimization criteria
            $eligible_count = $this->get_eligible_images_count();
            
            $stats = array(
                'total_images' => $unified_stats['total_images'],
                'optimized' => $unified_stats['already_optimized'] + $unified_stats['images_migrated'],
                'not_optimized' => $unified_stats['not_optimized'], // Use stats for non-eligible images
                'in_progress' => $this->get_queue_count('pending') + $this->get_queue_count('processing'),
                'optimization_percent' => $unified_stats['optimized_percent'] + $unified_stats['cloud_percent'],
                'eligible_for_optimization' => $eligible_count, // Use the correct count based on size criteria
            );
            
            // Log for debugging
            error_log('Optimization stats - total: ' . $stats['total_images'] . ', eligible: ' . $stats['eligible_for_optimization'] . ', not_optimized: ' . $stats['not_optimized']);
            
            // Get space saved and average reduction data from optimization metadata
            $meta_values = $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = '_bunny_optimization_data'");
            $total_saved = 0;
            $total_reduction = 0;
            $count = 0;
            
            foreach ($meta_values as $meta_value) {
                $data = maybe_unserialize($meta_value);
                if (isset($data['bytes_saved']) && $data['bytes_saved'] > 0) {
                    $total_saved += $data['bytes_saved'];
                    
                    if (isset($data['compression_ratio']) && $data['compression_ratio'] > 0) {
                        $total_reduction += $data['compression_ratio'];
                        $count++;
                    }
                }
            }
            
            $stats['space_saved'] = $this->format_bytes($total_saved);
            
            if ($count > 0) {
                $stats['average_reduction'] = round($total_reduction / $count, 1);
            } else {
                $stats['average_reduction'] = 0;
            }
            
            return $stats;
        }
        
        // Fallback to direct calculation if stats class is not available
        $stats = array(
            'total_images' => 0,
            'optimized' => 0,
            'not_optimized' => 0,
            'in_progress' => 0,
            'optimization_percent' => 0,
            'eligible_for_optimization' => 0,
            'space_saved' => 0,
            'average_reduction' => 0,
        );
        
        // Get total images
        $total_query = "SELECT COUNT(ID) FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'";
        $stats['total_images'] = (int) $wpdb->get_var($total_query);
        
        // Get optimized images
        $optimized_query = "SELECT COUNT(post_id) FROM $wpdb->postmeta WHERE meta_key = '_bunny_optimization_data'";
        $stats['optimized'] = (int) $wpdb->get_var($optimized_query);
        
        // Get in progress images
        $in_progress_query = "SELECT COUNT(id) FROM {$wpdb->prefix}bunny_optimization_queue WHERE status IN ('pending', 'processing')";
        $stats['in_progress'] = (int) $wpdb->get_var($in_progress_query);
        
        // Get eligible images directly with a SQL query
        $eligible_count = $this->get_eligible_images_count();
        $stats['eligible_for_optimization'] = $eligible_count;
        $stats['not_optimized'] = $eligible_count; // Use the same count
        
        // Calculate optimization percentage
        if ($stats['total_images'] > 0) {
            $stats['optimization_percent'] = round(($stats['optimized'] / $stats['total_images']) * 100, 1);
        }
        
        // Get space saved and average reduction
        $meta_values = $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = '_bunny_optimization_data'");
        $total_saved = 0;
        $total_reduction = 0;
        $count = 0;
        
        foreach ($meta_values as $meta_value) {
            $data = maybe_unserialize($meta_value);
            if (isset($data['bytes_saved']) && $data['bytes_saved'] > 0) {
                $total_saved += $data['bytes_saved'];
                
                if (isset($data['compression_ratio']) && $data['compression_ratio'] > 0) {
                    $total_reduction += $data['compression_ratio'];
                    $count++;
                }
            }
        }
        
        $stats['space_saved'] = $this->format_bytes($total_saved);
        
        if ($count > 0) {
            $stats['average_reduction'] = round($total_reduction / $count, 1);
        }
        
        return $stats;
    }
    
    /**
     * Get count of images eligible for optimization
     */
    public function get_eligible_images_count() {
        global $wpdb;
        
        // Get the max file size from settings
        $settings = $this->settings->get_all();
        $max_file_size_kb = isset($settings['max_file_size']) ? (int) $settings['max_file_size'] : 50; // Default to 50 KB
        $max_file_size_bytes = $max_file_size_kb * 1024;
        
        // Define 9MB in bytes for upper limit
        $nine_mb_bytes = 9 * 1024 * 1024; // 9MB in bytes
        
        // Get supported image types
        $supported_types = array(
            'image/jpeg', 
            'image/png', 
            'image/webp', 
            'image/avif', 
            'image/heic', 
            'image/tiff'
        );
        $supported_types_placeholders = implode(',', array_fill(0, count($supported_types), '%s'));
        
        // Find all potential eligible images (not yet optimized, not in queue, not On Bunny SSD)
        $query_args = $supported_types;
        $eligible_query = $wpdb->prepare(
            "SELECT p.ID, pm.meta_value as file_path
             FROM $wpdb->posts p
             LEFT JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
             AND p.post_mime_type IN ($supported_types_placeholders)
             AND p.post_status = 'inherit'
             AND NOT EXISTS (
                 SELECT 1 FROM $wpdb->postmeta pm2 
                 WHERE pm2.post_id = p.ID AND pm2.meta_key = '_bunny_optimization_data'
             )
             AND NOT EXISTS (
                 SELECT 1 FROM {$wpdb->prefix}bunny_optimization_queue q
                 WHERE q.attachment_id = p.ID
             )
             AND NOT EXISTS (
                 SELECT 1 FROM {$wpdb->prefix}bunny_offloaded_files bf 
                 WHERE bf.attachment_id = p.ID AND bf.is_synced = 1
             )",
            $query_args
        );
        
        $potential_images = $wpdb->get_results($eligible_query);
        
        // Count images that meet the file size criteria
        $eligible_count = 0;
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        
        foreach ($potential_images as $image) {
            if (!empty($image->file_path)) {
                $file_path = $base_dir . '/' . $image->file_path;
                if (file_exists($file_path)) {
                    try {
                        $file_size = filesize($file_path);
                        if ($file_size > 0 && $file_size > $max_file_size_bytes && $file_size <= $nine_mb_bytes) {
                            $eligible_count++;
                        }
                    } catch (Exception $e) {
                        // Skip if file can't be read
                        continue;
                    }
                }
            }
        }
        
        // Log for debugging
        error_log('get_eligible_images_count: ' . $eligible_count . ' out of ' . count($potential_images) . ' potential images');
        
        return $eligible_count;
    }
    
    /**
     * Check if a file is eligible for optimization
     */
    public function is_eligible_for_optimization($file_path, $file_size, $mime_type, $max_file_size_bytes) {
        // Check file existence
        if (!file_exists($file_path)) {
            return false;
        }
        
        // Check if it's a supported file type
        $supported_types = array('image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/heic', 'image/tiff');
        if (!in_array($mime_type, $supported_types)) {
            return false;
        }
        
        // Define 9MB in bytes
        $nine_mb_bytes = 9 * 1024 * 1024; // 9MB in bytes
        
        // Check if file size is within the allowed range
        // Must be greater than max_file_size_bytes and not greater than 9MB
        if ($file_size <= 0 || $file_size <= $max_file_size_bytes || $file_size > $nine_mb_bytes) {
            return false;
        }
        
        // All files meeting the criteria above are eligible
        return true;
    }
    
    /**
     * Get images for optimization
     */
    public function get_images_for_optimization($limit = 100) {
        global $wpdb;
        
        // Get max file size from settings
        $settings = $this->settings->get_all();
        $max_file_size_kb = isset($settings['max_file_size']) ? (int) $settings['max_file_size'] : 50; // Default to 50 KB
        $max_file_size_bytes = $max_file_size_kb * 1024;
        
        // Get supported image types
        $supported_types = array(
            'image/jpeg', 
            'image/png', 
            'image/webp', 
            'image/avif', 
            'image/heic', 
            'image/tiff'
        );
        $supported_types_placeholders = implode(',', array_fill(0, count($supported_types), '%s'));
        
        // Prepare arguments for the query
        $query_args = array_merge($supported_types, array($limit * 2));
        
        // Get all non-optimized, non-queued, non-CDN images
        $attachments_query = $wpdb->prepare(
            "SELECT p.ID, p.post_mime_type FROM $wpdb->posts p
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type IN ($supported_types_placeholders)
            AND p.post_status = 'inherit'
            AND NOT EXISTS (
                SELECT 1 FROM $wpdb->postmeta pm 
                WHERE pm.post_id = p.ID AND pm.meta_key = '_bunny_optimization_data'
            )
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->prefix}bunny_optimization_queue q
                WHERE q.attachment_id = p.ID
            )
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->prefix}bunny_offloaded_files bf 
                WHERE bf.attachment_id = p.ID AND bf.is_synced = 1
            )
            LIMIT %d",
            $query_args
        );
        
        $attachments = $wpdb->get_results($attachments_query);
        $eligible_attachments = array();
        
        // Log count of attachments found
        error_log('Found ' . count($attachments) . ' potential images for optimization');
        
        // Process each attachment
        foreach ($attachments as $attachment) {
            // If we already have enough attachments, break
            if (count($eligible_attachments) >= $limit) {
                break;
            }
            
            $file_path = get_attached_file($attachment->ID);
            if (!$file_path || !file_exists($file_path)) {
                continue;
            }
            
            // Track all successful file reads
            try {
                $file_size = filesize($file_path);
                $mime_type = $attachment->post_mime_type;
                
                // Check eligibility based on new criteria
                if (!$this->is_eligible_for_optimization($file_path, $file_size, $mime_type, $max_file_size_bytes)) {
                    continue;
                }
                
                // Get the file's data
                $file_data = file_get_contents($file_path);
                if (!$file_data) {
                    continue;
                }
                
                $eligible_attachments[] = array(
                    'id' => $attachment->ID,
                    'attachment_id' => $attachment->ID, // Include for consistency
                    'imageData' => base64_encode($file_data),
                    'mimeType' => $mime_type,
                    'file_path' => $file_path,
                    'file_size' => $file_size
                );
            } catch (Exception $e) {
                error_log('Error processing file ' . $file_path . ': ' . $e->getMessage());
                continue;
            }
        }
        
        error_log('Returning ' . count($eligible_attachments) . ' images for optimization');
        return $eligible_attachments;
    }
    
    /**
     * Add images to optimization queue
     */
    public function add_to_optimization_queue($attachment_ids) {
        global $wpdb;
        
        if (empty($attachment_ids)) {
            return 0;
        }
        
        $count = 0;
        $queue_table = $wpdb->prefix . 'bunny_optimization_queue';
        
        foreach ($attachment_ids as $attachment_id) {
            // Check if already in queue
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM $queue_table WHERE attachment_id = %d AND status IN ('pending', 'processing')",
                    $attachment_id
                )
            );
            
            if (!$exists) {
                $result = $wpdb->insert(
                    $queue_table,
                    array(
                        'attachment_id' => $attachment_id,
                        'status' => 'pending',
                        'date_added' => current_time('mysql')
                    ),
                    array('%d', '%s', '%s')
                );
                
                if ($result) {
                    $count++;
                }
            }
        }
        
        $this->logger->log('info', sprintf('Added %d images to optimization queue', $count));
        
        return $count;
    }
    
    /**
     * Process optimization batch
     */
    public function process_optimization_batch($batch_size = 5) {
        global $wpdb;
        
        // Get pending items from queue
        $queue_table = $wpdb->prefix . 'bunny_optimization_queue';
        $pending_items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, attachment_id FROM $queue_table WHERE status = 'pending' ORDER BY priority DESC, date_added ASC LIMIT %d",
                $batch_size
            )
        );
        
        if (empty($pending_items)) {
            return array(
                'success' => true,
                'message' => 'No pending items in queue',
                'processed' => 0,
                'remaining' => 0
            );
        }
        
        // Mark items as processing
        $ids = wp_list_pluck($pending_items, 'id');
        $id_placeholders = implode(',', array_fill(0, count($ids), '%d'));
        
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $queue_table SET status = 'processing', date_started = %s WHERE id IN ($id_placeholders)",
                array_merge(array(current_time('mysql')), $ids)
            )
        );
        
        // Prepare batch for API
        $batch = array();
        $attachment_ids = array();
        
        foreach ($pending_items as $item) {
            $attachment_id = $item->attachment_id;
            $attachment_ids[] = $attachment_id;
            
            $file_path = get_attached_file($attachment_id);
            if (!$file_path || !file_exists($file_path)) {
                $this->update_queue_item($item->id, 'failed', 'File not found');
                continue;
            }
            
            // Get file as base64
            $file_content = file_get_contents($file_path);
            if (!$file_content) {
                $this->update_queue_item($item->id, 'failed', 'Could not read file');
                continue;
            }
            
            $base64_data = base64_encode($file_content);
            $mime_type = get_post_mime_type($attachment_id);
            
            $batch[] = array(
                'queue_id' => $item->id,
                'attachment_id' => $attachment_id,
                'file_path' => $file_path,
                'imageData' => 'data:' . $mime_type . ';base64,' . $base64_data
            );
        }
        
        if (empty($batch)) {
            return array(
                'success' => true,
                'message' => 'No valid items to process',
                'processed' => 0,
                'remaining' => $this->get_queue_count('pending')
            );
        }
        
        // Call the BMO API to optimize the batch
        $optimization_result = $this->optimize_images($batch);
        
        // Get remaining count
        $remaining = $this->get_queue_count('pending');
        
        return array(
            'success' => $optimization_result['success'],
            'message' => $optimization_result['message'],
            'processed' => count($batch),
            'remaining' => $remaining,
            'results' => $optimization_result['results'] ?? array()
        );
    }
    
    /**
     * Optimize images using the BMO API
     */
    private function optimize_images($batch) {
        $settings = $this->settings->get_all();
        
        // Get API settings
        $api_key = isset($settings['bmo_api_key']) ? $settings['bmo_api_key'] : '';
        $api_region = isset($settings['bmo_api_region']) ? $settings['bmo_api_region'] : 'us';
        $max_file_size_kb = isset($settings['max_file_size']) ? (int) $settings['max_file_size'] : 50; // Default to 50 KB
        
        if (empty($api_key)) {
            $this->logger->log('error', 'BMO API key is not set');
            return array(
                'success' => false,
                'message' => 'BMO API key is not set',
                'results' => array()
            );
        }
        
        // Prepare API request
        $api_base_url = 'us' === $api_region ? 'https://api.bunny.net' : "https://api.{$api_region}.bunny.net";
        $api_endpoint = '/optimizer';
        $api_url = $api_base_url . $api_endpoint;
        
        // Format data for API
        $api_data = array(
            'images' => array()
        );
        
        foreach ($batch as $item) {
            $api_data['images'][] = array(
                'imageData' => $item['imageData']
            );
        }
        
        // Make API request
        $response = wp_remote_post(
            $api_url,
            array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'AccessKey' => $api_key
                ),
                'body' => json_encode($api_data),
                'timeout' => 60,
                'sslverify' => true
            )
        );
        
        // Process API response
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->log('error', 'BMO API error: ' . $error_message);
            
            // Mark all items as failed
            foreach ($batch as $item) {
                $this->update_queue_item($item['queue_id'], 'failed', $error_message);
            }
            
            return array(
                'success' => false,
                'message' => 'API error: ' . $error_message,
                'results' => array()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        
        if ($response_code !== 200) {
            $error_message = isset($response_data['message']) ? $response_data['message'] : 'Unknown API error';
            $this->logger->log('error', 'BMO API error: ' . $error_message);
            
            // Mark all items as failed
            foreach ($batch as $item) {
                $this->update_queue_item($item['queue_id'], 'failed', $error_message);
            }
            
            return array(
                'success' => false,
                'message' => 'API error: ' . $error_message,
                'results' => array()
            );
        }
        
        // Process successful response
        $results = array();
        
        if (isset($response_data['images']) && is_array($response_data['images'])) {
            foreach ($response_data['images'] as $index => $image_result) {
                if (!isset($batch[$index])) {
                    continue;
                }
                
                $item = $batch[$index];
                $queue_id = $item['queue_id'];
                $attachment_id = $item['attachment_id'];
                $file_path = $item['file_path'];
                
                if (isset($image_result['error'])) {
                    // Image processing failed
                    $this->update_queue_item($queue_id, 'failed', $image_result['error']);
                    $results[] = array(
                        'attachment_id' => $attachment_id,
                        'status' => 'failed',
                        'message' => $image_result['error']
                    );
                    continue;
                }
                
                if (isset($image_result['skipped']) && $image_result['skipped']) {
                    // Image was skipped (e.g. already optimized or below threshold)
                    $this->update_queue_item($queue_id, 'completed', 'Image skipped: ' . ($image_result['message'] ?? 'Already optimized or below threshold'));
                    
                    // Add basic optimization data
                    update_post_meta($attachment_id, '_bunny_optimization_data', array(
                        'status' => 'skipped',
                        'date_optimized' => current_time('mysql'),
                        'message' => $image_result['message'] ?? 'Already optimized or below threshold'
                    ));
                    
                    $results[] = array(
                        'attachment_id' => $attachment_id,
                        'status' => 'skipped',
                        'message' => $image_result['message'] ?? 'Already optimized or below threshold'
                    );
                    continue;
                }
                
                if (isset($image_result['optimizedImageData'])) {
                    // Process successful optimization
                    $original_size = filesize($file_path);
                    
                    // Decode base64 data
                    $optimized_data = $image_result['optimizedImageData'];
                    $base64_data = preg_replace('/^data:[^;]+;base64,/', '', $optimized_data);
                    $decoded_data = base64_decode($base64_data);
                    
                    if ($decoded_data) {
                        // Save optimized image
                        file_put_contents($file_path, $decoded_data);
                        $new_size = filesize($file_path);
                        $bytes_saved = $original_size - $new_size;
                        $compression_ratio = round(($bytes_saved / $original_size) * 100, 1);
                        
                        // Update metadata
                        $optimization_data = array(
                            'status' => 'optimized',
                            'date_optimized' => current_time('mysql'),
                            'original_size' => $original_size,
                            'optimized_size' => $new_size,
                            'bytes_saved' => $bytes_saved,
                            'compression_ratio' => $compression_ratio,
                            'format' => $image_result['format'] ?? 'avif'
                        );
                        
                        update_post_meta($attachment_id, '_bunny_optimization_data', $optimization_data);
                        
                        // Update attachment metadata
                        $attachment_metadata = wp_get_attachment_metadata($attachment_id);
                        if ($attachment_metadata) {
                            // Update mime type if format changed
                            if (isset($image_result['format']) && $image_result['format'] === 'avif') {
                                update_post_meta($attachment_id, '_wp_attachment_metadata', $attachment_metadata);
                                wp_update_post(array(
                                    'ID' => $attachment_id,
                                    'post_mime_type' => 'image/avif'
                                ));
                            }
                        }
                        
                        // Mark as completed
                        $this->update_queue_item($queue_id, 'completed');
                        
                        $results[] = array(
                            'attachment_id' => $attachment_id,
                            'status' => 'optimized',
                            'bytes_saved' => $bytes_saved,
                            'compression_ratio' => $compression_ratio,
                            'format' => $image_result['format'] ?? 'avif'
                        );
                        
                        $this->logger->log('info', sprintf(
                            'Successfully optimized image #%d - Reduced by %s (%.1f%%)',
                            $attachment_id,
                            $this->format_bytes($bytes_saved),
                            $compression_ratio
                        ));
                    } else {
                        // Failed to decode base64 data
                        $this->update_queue_item($queue_id, 'failed', 'Failed to decode optimized image data');
                        $results[] = array(
                            'attachment_id' => $attachment_id,
                            'status' => 'failed',
                            'message' => 'Failed to decode optimized image data'
                        );
                    }
                } else {
                    // No optimized image data returned
                    $this->update_queue_item($queue_id, 'failed', 'No optimized image data returned from API');
                    $results[] = array(
                        'attachment_id' => $attachment_id,
                        'status' => 'failed',
                        'message' => 'No optimized image data returned from API'
                    );
                }
            }
        }
        
        return array(
            'success' => true,
            'message' => 'Batch processed successfully',
            'results' => $results
        );
    }
    
    /**
     * Update queue item status
     */
    private function update_queue_item($queue_id, $status, $error_message = '') {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'bunny_optimization_queue';
        $data = array('status' => $status);
        $format = array('%s');
        
        if ($status === 'completed') {
            $data['date_completed'] = current_time('mysql');
            $format[] = '%s';
        }
        
        if (!empty($error_message)) {
            $data['error_message'] = $error_message;
            $format[] = '%s';
        }
        
        $wpdb->update(
            $queue_table,
            $data,
            array('id' => $queue_id),
            $format,
            array('%d')
        );
    }
    
    /**
     * Get queue count by status
     */
    public function get_queue_count($status = 'all') {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'bunny_optimization_queue';
        
        if ($status === 'all') {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM $queue_table");
        }
        
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $queue_table WHERE status = %s",
                $status
            )
        );
    }
    
    /**
     * Clear optimization queue
     */
    public function clear_optimization_queue() {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'bunny_optimization_queue';
        $result = $wpdb->query("TRUNCATE TABLE $queue_table");
        
        if ($result !== false) {
            $this->logger->log('info', 'Optimization queue cleared');
            return true;
        }
        
        $this->logger->log('error', 'Failed to clear optimization queue');
        return false;
    }
    
    /**
     * Format bytes to human-readable format
     */
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    /**
     * AJAX: Get optimization statistics
     */
    public function ajax_get_optimization_stats() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // Clear stats cache for fresh data
        global $bunny_stats;
        if ($bunny_stats) {
            $bunny_stats->clear_cache();
        }
        
        $stats = $this->get_optimization_stats();
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX: Start optimization
     */
    public function ajax_start_optimization() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // Get eligible images
        $eligible_images = $this->get_images_for_optimization(100);
        
        if (empty($eligible_images)) {
            wp_send_json_error('No eligible images found for optimization');
        }
        
        // Add to queue
        $attachment_ids = array_column($eligible_images, 'id');
        $added = $this->add_to_optimization_queue($attachment_ids);
        
        wp_send_json_success(array(
            'message' => sprintf('Added %d images to optimization queue', $added),
            'added' => $added,
            'queue_size' => $this->get_queue_count('pending')
        ));
    }
    
    /**
     * AJAX: Process optimization batch
     */
    public function ajax_optimization_batch() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 5;
        $batch_size = min($batch_size, 5); // Max 5 images per batch (BMO API limitation)
        
        $result = $this->process_optimization_batch($batch_size);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Cancel optimization
     */
    public function ajax_cancel_optimization() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $result = $this->clear_optimization_queue();
        
        if ($result) {
            wp_send_json_success('Optimization canceled and queue cleared');
        } else {
            wp_send_json_error('Failed to cancel optimization');
        }
    }
    
    /**
     * AJAX: Run optimization diagnostics
     */
    public function ajax_run_diagnostics() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $diagnostics = array(
            'api_key_set' => false,
            'api_connection' => false,
            'credits_available' => 0,
            'https_enabled' => is_ssl(),
            'queue_table_exists' => false,
            'eligible_images' => $this->get_eligible_images_count(),
            'pending_in_queue' => $this->get_queue_count('pending'),
            'failed_in_queue' => $this->get_queue_count('failed'),
            'php_version_ok' => version_compare(PHP_VERSION, '7.4', '>='),
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit' => ini_get('memory_limit'),
            'recommendations' => array()
        );
        
        // Check API key
        $settings = $this->settings->get_all();
        $api_key = isset($settings['bmo_api_key']) ? $settings['bmo_api_key'] : '';
        $diagnostics['api_key_set'] = !empty($api_key);
        
        // Check queue table
        global $wpdb;
        $table_name = $wpdb->prefix . 'bunny_optimization_queue';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        $diagnostics['queue_table_exists'] = $table_exists;
        
        // Check API connection and credits
        if ($diagnostics['api_key_set']) {
            $api_region = isset($settings['bmo_api_region']) ? $settings['bmo_api_region'] : 'us';
            $api_base_url = 'us' === $api_region ? 'https://api.bunny.net' : "https://api.{$api_region}.bunny.net";
            
            $response = wp_remote_get(
                $api_base_url . '/account',
                array(
                    'headers' => array(
                        'AccessKey' => $api_key,
                        'Accept' => 'application/json',
                    ),
                    'timeout' => 15
                )
            );
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $diagnostics['api_connection'] = true;
                
                $response_body = wp_remote_retrieve_body($response);
                $response_data = json_decode($response_body, true);
                
                if (isset($response_data['BillingInfo']['Balance'])) {
                    $diagnostics['credits_available'] = number_format($response_data['BillingInfo']['Balance']);
                }
            }
        }
        
        // Add recommendations
        if (!$diagnostics['api_key_set']) {
            $diagnostics['recommendations'][] = 'Set your BMO API key in the plugin settings';
        }
        
        if (!$diagnostics['https_enabled']) {
            $diagnostics['recommendations'][] = 'Enable HTTPS on your site (required for BMO API)';
        }
        
        if (!$diagnostics['queue_table_exists']) {
            $diagnostics['recommendations'][] = 'Reactivate the plugin to create the queue table';
        }
        
        if ($diagnostics['api_key_set'] && !$diagnostics['api_connection']) {
            $diagnostics['recommendations'][] = 'Check your API key or try a different region';
        }
        
        if ($diagnostics['credits_available'] < 10 && $diagnostics['api_connection']) {
            $diagnostics['recommendations'][] = 'Add more credits to your BMO account';
        }
        
        if (intval($diagnostics['max_execution_time']) < 30) {
            $diagnostics['recommendations'][] = 'Increase max_execution_time to at least 30 seconds';
        }
        
        wp_send_json_success($diagnostics);
    }
    
    /**
     * Handle upload for auto-optimization
     */
    public function handle_upload($file, $context = 'upload') {
        // Only process uploads, not other contexts
        if ($context !== 'upload') {
            return $file;
        }
        
        // Check if auto-optimization is enabled
        $settings = $this->settings->get_all();
        $auto_optimize = isset($settings['auto_optimize']) ? (bool) $settings['auto_optimize'] : false;
        
        if (!$auto_optimize) {
            return $file;
        }
        
        // Get the attachment ID
        $attachment = $this->get_attachment_by_file($file['file']);
        
        if (!$attachment) {
            return $file;
        }
        
        // Add to optimization queue
        $this->add_to_optimization_queue(array($attachment->ID));
        
        // Return the file unmodified
        return $file;
    }
    
    /**
     * Get attachment by file path
     */
    private function get_attachment_by_file($file_path) {
        $uploads = wp_get_upload_dir();
        $relative_path = str_replace($uploads['basedir'] . '/', '', $file_path);
        
        $args = array(
            'post_type' => 'attachment',
            'posts_per_page' => 1,
            'meta_query' => array(
                array(
                    'key' => '_wp_attached_file',
                    'value' => $relative_path,
                    'compare' => '='
                )
            )
        );
        
        $attachments = get_posts($args);
        
        if (!empty($attachments)) {
            return $attachments[0];
        }
        
        return null;
    }
    
    /**
     * Test the BMO API connection
     */
    public function test_connection() {
        // Get API credentials
        $settings = $this->settings->get_all();
        $api_key = isset($settings['bmo_api_key']) ? $settings['bmo_api_key'] : '';
        $region = isset($settings['bmo_api_region']) ? $settings['bmo_api_region'] : 'us';
        
        // Check for API key
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('BMO API key is not configured.', 'bunny-media-offload'));
        }
        
        // Check if HTTPS is enabled
        if (!is_ssl()) {
            return new WP_Error('not_https', __('HTTPS is required for the BMO API.', 'bunny-media-offload'));
        }
        
        // Set up the request URL - using account API endpoint
        $api_base_url = 'us' === $region ? 'https://api.bunny.net' : "https://api.{$region}.bunny.net";
        $url = "{$api_base_url}/account";
        
        // Set up the request arguments
        $args = array(
            'headers' => array(
                'AccessKey' => $api_key,
                'Accept' => 'application/json',
            ),
            'timeout' => 15,
        );
        
        // Make the request
        $response = wp_remote_get($url, $args);
        
        // Check for errors
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Check the response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $error_message = wp_remote_retrieve_response_message($response);
            if (empty($error_message)) {
                $error_message = __('Unknown error', 'bunny-media-offload');
            }
            
            return new WP_Error(
                'api_error',
                sprintf(
                    // translators: %1$s is the error code, %2$s is the error message
                    __('BMO API error: %1$s %2$s', 'bunny-media-offload'),
                    $response_code,
                    $error_message
                )
            );
        }
        
        // Parse the response
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data)) {
            return new WP_Error('invalid_response', __('Invalid response from BMO API.', 'bunny-media-offload'));
        }
        
        // Return success with credits information if available
        $result = array(
            'success' => true,
        );
        
        if (isset($data['BillingInfo']['Balance'])) {
            $result['credits'] = number_format($data['BillingInfo']['Balance']);
        }
        
        return $result;
    }
} 