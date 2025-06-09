<?php
/**
 * Bunny BMO Processor
 * Handles BMO API interactions, batch processing, and optimization logic
 */
class Bunny_BMO_Processor {
    
    private $bmo_api;
    private $settings;
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct($bmo_api, $settings, $logger) {
        $this->bmo_api = $bmo_api;
        $this->settings = $settings;
        $this->logger = $logger;
    }
    
    /**
     * Check if BMO API is available and configured
     */
    public function is_available() {
        if (!$this->bmo_api) {
            return false;
        }
        
        $validation_errors = $this->bmo_api->validate_configuration();
        return empty($validation_errors);
    }
    
    /**
     * Get BMO API configuration errors
     */
    public function get_configuration_errors() {
        if (!$this->bmo_api) {
            return array('BMO API not initialized');
        }
        
        return $this->bmo_api->validate_configuration();
    }
    
    /**
     * Process a batch of images via BMO API
     */
    public function process_batch($images) {
        if (!$this->is_available()) {
            return $this->create_batch_error_result($images, 'BMO API not available');
        }
        
        if (empty($images)) {
            return $this->create_empty_batch_result();
        }
        
        $this->logger->log('info', 'Processing BMO API batch', array(
            'image_count' => count($images)
        ));
        
        try {
            // Prepare images for BMO API
            $bmo_images = array();
            $image_mapping = array();
            
            foreach ($images as $index => $attachment_id) {
                // Validate attachment ID
                if (!is_numeric($attachment_id) || $attachment_id <= 0) {
                    $this->logger->log('warning', "Invalid attachment ID: {$attachment_id}");
                    continue;
                }
                
                // Check if attachment exists
                $post = get_post($attachment_id);
                if (!$post || $post->post_type !== 'attachment') {
                    $this->logger->log('warning', "Attachment {$attachment_id} does not exist or is not an attachment");
                    continue;
                }
                
                // Check if it's an image
                if (!wp_attachment_is_image($attachment_id)) {
                    $this->logger->log('warning', "Attachment {$attachment_id} is not an image", array(
                        'post_mime_type' => $post->post_mime_type
                    ));
                    continue;
                }
                
                $image_url = $this->get_image_url($attachment_id);
                if ($image_url) {
                    try {
                        $image_data = $this->bmo_api->prepare_image_data($attachment_id, $image_url);
                        $bmo_images[] = $image_data;
                        $image_mapping[] = $attachment_id;
                    } catch (Exception $e) {
                        $this->logger->log('warning', "Failed to prepare image data for attachment {$attachment_id}: " . $e->getMessage());
                    }
                } else {
                    // Log which attachments are missing URLs
                    $file_path = get_attached_file($attachment_id);
                    $this->logger->log('warning', "No URL found for attachment {$attachment_id}", array(
                        'post_title' => $post->post_title ?: 'Unknown',
                        'post_mime_type' => $post->post_mime_type ?: 'Unknown',
                        'file_path' => $file_path,
                        'file_exists' => $file_path ? file_exists($file_path) : false
                    ));
                }
            }
            
            if (empty($bmo_images)) {
                $error_msg = sprintf('No valid image URLs found in batch of %d images. Check logs for details on missing URLs.', count($images));
                $this->logger->log('error', $error_msg);
                return $this->create_batch_error_result($images, $error_msg);
            }
            
            // Send to BMO API
            $result = $this->bmo_api->optimize_images($bmo_images, array(
                'format' => $this->settings->get('optimization_format', 'auto'),
                'quality' => $this->settings->get('optimization_quality', 85)
            ));
            
            if ($result && isset($result['success']) && $result['success']) {
                return $this->process_batch_results($result, $image_mapping);
            } else {
                $error_msg = isset($result['error']) ? $result['error'] : 'BMO API call failed';
                return $this->create_batch_error_result($images, $error_msg);
            }
            
        } catch (Exception $e) {
            $this->logger->log('error', 'BMO API batch processing exception: ' . $e->getMessage());
            return $this->create_batch_error_result($images, 'Exception: ' . $e->getMessage());
        }
    }
    
    /**
     * Process BMO API batch results
     */
    private function process_batch_results($result, $image_mapping) {
        $successful = 0;
        $failed = 0;
        $errors = array();
        $processed_results = array();
        
        // Handle batch results
        $results = isset($result['results']) ? $result['results'] : array($result);
        
        foreach ($results as $index => $image_result) {
            $attachment_id = $image_mapping[$index] ?? null;
            
            if (!$attachment_id) {
                continue;
            }
            
            $image_data = $this->get_image_data_for_ui($attachment_id);
            
            if (isset($image_result['success']) && $image_result['success']) {
                if (isset($image_result['skipped']) && $image_result['skipped']) {
                    // Handle skipped images
                    $successful++;
                    $processed_results[] = array(
                        'attachment_id' => $attachment_id,
                        'name' => $image_data['name'],
                        'thumbnail' => $image_data['thumbnail'],
                        'success' => true,
                        'action' => 'Skipped - ' . ($image_result['reason'] ?? 'Already optimized'),
                        'size_reduction' => 0,
                        'result_data' => $image_result
                    );
                } else {
                    // Handle successful optimization
                    $successful++;
                    
                    $compression_ratio = isset($image_result['data']['compressionRatio']) ? 
                        $image_result['data']['compressionRatio'] : 0;
                    
                    $processed_results[] = array(
                        'attachment_id' => $attachment_id,
                        'name' => $image_data['name'],
                        'thumbnail' => $image_data['thumbnail'],
                        'success' => true,
                        'action' => 'Optimized via BMO API',
                        'size_reduction' => $compression_ratio,
                        'result_data' => $image_result
                    );
                }
            } else {
                // Handle failed optimization
                $failed++;
                $error_msg = isset($image_result['error']) ? $image_result['error'] : 'BMO API optimization failed';
                $errors[] = sprintf('%s: %s', $image_data['name'], $error_msg);
                
                $processed_results[] = array(
                    'attachment_id' => $attachment_id,
                    'name' => $image_data['name'],
                    'thumbnail' => $image_data['thumbnail'],
                    'success' => false,
                    'action' => $error_msg,
                    'size_reduction' => 0,
                    'result_data' => $image_result
                );
            }
        }
        
        return array(
            'successful' => $successful,
            'failed' => $failed,
            'errors' => $errors,
            'processed_results' => $processed_results,
            'api_response' => $result
        );
    }
    
    /**
     * Create error result for entire batch
     */
    private function create_batch_error_result($images, $error_msg) {
        $failed = count($images);
        $errors = array('Batch failed: ' . $error_msg);
        $processed_results = array();
        
        foreach ($images as $attachment_id) {
            $image_data = $this->get_image_data_for_ui($attachment_id);
            $processed_results[] = array(
                'attachment_id' => $attachment_id,
                'name' => $image_data['name'],
                'thumbnail' => $image_data['thumbnail'],
                'success' => false,
                'action' => $error_msg,
                'size_reduction' => 0,
                'result_data' => null
            );
        }
        
        return array(
            'successful' => 0,
            'failed' => $failed,
            'errors' => $errors,
            'processed_results' => $processed_results,
            'api_response' => null
        );
    }
    
    /**
     * Create empty batch result
     */
    private function create_empty_batch_result() {
        return array(
            'successful' => 0,
            'failed' => 0,
            'errors' => array(),
            'processed_results' => array(),
            'api_response' => null
        );
    }
    
    /**
     * Get image data for UI display
     */
    private function get_image_data_for_ui($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        $file_name = basename($file_path);
        $post_title = get_the_title($attachment_id);
        
        // Get thumbnail URL
        $thumbnail_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
        if (!$thumbnail_url) {
            $thumbnail_url = wp_get_attachment_image_url($attachment_id, 'medium') ?: wp_get_attachment_url($attachment_id);
        }
        
        if (!$thumbnail_url) {
            $thumbnail_url = includes_url('images/media/default.png');
        }
        
        return array(
            'name' => $post_title ?: $file_name,
            'thumbnail' => $thumbnail_url ?: '',
            'file_path' => $file_path,
            'file_size' => file_exists($file_path) ? filesize($file_path) : 0
        );
    }
    
    /**
     * Update attachment optimization metadata
     */
    public function update_attachment_meta($attachment_id, $result) {
        update_post_meta($attachment_id, '_bunny_optimized', true);
        update_post_meta($attachment_id, '_bunny_optimization_data', $result);
        update_post_meta($attachment_id, '_bunny_last_optimized', current_time('mysql'));
        
        // Update optimization stats
        if (isset($result['data'])) {
            $this->update_optimization_stats($result['data']);
        }
    }
    
    /**
     * Update optimization statistics
     */
    private function update_optimization_stats($data) {
        $stats = get_option('bunny_optimization_stats', array(
            'total_optimized' => 0,
            'total_savings' => 0,
            'total_original_size' => 0,
            'total_optimized_size' => 0
        ));
        
        if (isset($data['originalSize']) && isset($data['compressedSize'])) {
            $stats['total_optimized']++;
            $stats['total_savings'] += ($data['originalSize'] - $data['compressedSize']);
            $stats['total_original_size'] += $data['originalSize'];
            $stats['total_optimized_size'] += $data['compressedSize'];
            
            update_option('bunny_optimization_stats', $stats);
        }
    }
    
    /**
     * Get optimization statistics
     */
    public function get_optimization_stats() {
        $stats_cache_key = 'bunny_optimization_dashboard_stats';
        $cached_stats = wp_cache_get($stats_cache_key, 'bunny_media_offload');
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        global $wpdb;
        
        // Get stats from the options table
        $stats = get_option('bunny_optimization_stats', array(
            'total_optimized' => 0,
            'total_savings' => 0,
            'total_original_size' => 0,
            'total_optimized_size' => 0
        ));
        
        // Get actual count of optimized images
        $actually_optimized_count = $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_bunny_optimization_data'
        ");
        
        $stats['images_actually_optimized'] = (int) $actually_optimized_count;
        
        // Calculate compression ratio
        if ($stats['total_original_size'] > 0) {
            $stats['compression_ratio'] = round(($stats['total_savings'] / $stats['total_original_size']) * 100, 2);
        } else {
            $stats['compression_ratio'] = 0;
        }
        
        // Format file sizes
        $stats['total_savings_formatted'] = $this->format_file_size($stats['total_savings']);
        $stats['total_original_size_formatted'] = $this->format_file_size($stats['total_original_size']);
        $stats['total_optimized_size_formatted'] = $this->format_file_size($stats['total_optimized_size']);
        
        // Cache for 5 minutes
        wp_cache_set($stats_cache_key, $stats, 'bunny_media_offload', 5 * MINUTE_IN_SECONDS);
        
        return $stats;
    }
    
    /**
     * Get detailed optimization statistics
     */
    public function get_detailed_stats() {
        $cache_key = 'bunny_detailed_optimization_stats';
        $cached_stats = wp_cache_get($cache_key, 'bunny_media_offload');
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        global $wpdb;
        
        // Get count of optimizable images
        $optimizable_count = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type LIKE 'image/%'
            AND p.ID NOT IN (
                SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_bunny_last_optimized' 
                AND meta_value > DATE_SUB(NOW(), INTERVAL 1 DAY)
            )
        ");
        
        $optimized_count = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_bunny_optimized' 
            AND meta_value = '1'
        ");
        
        $stats = array(
            'local' => array(
                'total_eligible' => (int) $optimizable_count,
                'has_files_to_optimize' => $optimizable_count > 0
            ),
            'cloud' => array(
                'total_eligible' => 0,
                'has_files_to_optimize' => false
            ),
            'already_optimized' => (int) $optimized_count,
            'batch_size' => 10, // Fixed BMO API batch size
            'api_type' => 'BMO API (External)'
        );
        
        // Cache for 5 minutes
        wp_cache_set($cache_key, $stats, 'bunny_media_offload', 5 * MINUTE_IN_SECONDS);
        
        return $stats;
    }
    
    /**
     * Get image URL with fallback methods
     */
    private function get_image_url($attachment_id) {
        // First try the standard WordPress function
        $image_url = wp_get_attachment_url($attachment_id);
        
        if ($image_url) {
            return $image_url;
        }
        
        // Try getting the file path and constructing URL manually
        $file_path = get_attached_file($attachment_id);
        if ($file_path && file_exists($file_path)) {
            $upload_dir = wp_upload_dir();
            
            // Check if file is in uploads directory
            if (strpos($file_path, $upload_dir['basedir']) === 0) {
                $relative_path = str_replace($upload_dir['basedir'], '', $file_path);
                $image_url = $upload_dir['baseurl'] . $relative_path;
                
                // Ensure we use the correct directory separator for URLs
                $image_url = str_replace('\\', '/', $image_url);
                
                return $image_url;
            }
        }
        
        // Last resort: try getting from post meta
        $meta_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if ($meta_file) {
            $upload_dir = wp_upload_dir();
            $image_url = $upload_dir['baseurl'] . '/' . $meta_file;
            
            // Ensure we use the correct directory separator for URLs
            $image_url = str_replace('\\', '/', $image_url);
            
            return $image_url;
        }
        
        return false;
    }
    
    /**
     * Utility method to format file sizes
     */
    private function format_file_size($bytes) {
        $units = array('B', 'KB', 'MB', 'GB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }
} 