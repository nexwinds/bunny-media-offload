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
     * Process a batch of images via BMO API - simplified version with proper filtering
     */
    public function process_batch($images) {
        $this->logger->log('info', 'BMO processor - simplified process_batch called', array(
            'initial_image_count' => count($images)
        ));
        
        if (!$this->is_available()) {
            return $this->create_batch_error_result($images, 'BMO API not available');
        }
        
        if (empty($images)) {
            return $this->create_empty_batch_result();
        }
        
        try {
            // First pass: filter and validate all images
            $valid_images = array();
            $skipped_images = array();
            $skipped_reasons = array();
            
            foreach ($images as $attachment_id) {
                // Basic validation
                if (!is_numeric($attachment_id) || !get_post($attachment_id)) {
                    $skipped_images[] = $attachment_id;
                    $skipped_reasons[] = 'Invalid attachment ID or post not found';
                    continue;
                }
                
                // Get URL
                $image_url = $this->get_image_url($attachment_id);
                if (!$image_url) {
                    $skipped_images[] = $attachment_id;
                    $skipped_reasons[] = 'Image URL not accessible (likely on CDN)';
                    continue;
                }
                
                $valid_images[] = $attachment_id;
            }
            
            $this->logger->log('info', 'Image filtering completed', array(
                'total_images' => count($images),
                'valid_images' => count($valid_images),
                'skipped_images' => count($skipped_images)
            ));
            
            // Create results for skipped images
            $processed_results = array();
            foreach ($skipped_images as $index => $attachment_id) {
                $image_data = $this->get_image_data_for_ui($attachment_id);
                $processed_results[] = array(
                    'attachment_id' => $attachment_id,
                    'name' => $image_data['name'],
                    'thumbnail' => $image_data['thumbnail'],
                    'success' => false,
                    'action' => $skipped_reasons[$index] ?? 'Skipped - validation failed',
                    'size_reduction' => 0,
                    'result_data' => null
                );
            }
            
            // If no valid images, return results for skipped images
            if (empty($valid_images)) {
                return array(
                    'successful' => 0,
                    'failed' => count($skipped_images),
                    'errors' => $skipped_reasons,
                    'processed_results' => $processed_results,
                    'api_response' => null
                );
            }
            
            // Limit batch size for BMO API (max 3 images per batch to prevent timeouts)
            if (count($valid_images) > 3) {
                $valid_images = array_slice($valid_images, 0, 3);
                $this->logger->log('info', 'Limiting BMO API batch to 3 images to prevent timeouts');
            }
            
            // Second pass: prepare valid images for BMO API
            $bmo_images = array();
            $image_mapping = array();
            
            foreach ($valid_images as $attachment_id) {
                $image_url = $this->get_image_url($attachment_id);
                try {
                    $image_data = $this->bmo_api->prepare_image_data($attachment_id, $image_url);
                    if ($image_data) {
                        $bmo_images[] = $image_data;
                        $image_mapping[] = $attachment_id;
                    }
                } catch (Exception $e) {
                    $this->logger->log('warning', "Failed to prepare image {$attachment_id}: " . $e->getMessage());
                    continue;
                }
            }
            
            if (empty($bmo_images)) {
                return array(
                    'successful' => 0,
                    'failed' => count($images),
                    'errors' => array('No images could be prepared for BMO API'),
                    'processed_results' => $processed_results,
                    'api_response' => null
                );
            }
            
            $this->logger->log('info', 'Sending to BMO API', array(
                'image_count' => count($bmo_images)
            ));
            
            // Send to BMO API with optimization options
            $result = $this->bmo_api->optimize_images($bmo_images, array(
                'format' => $this->settings->get('optimization_format', 'auto'),
                'quality' => $this->settings->get('optimization_quality', 85),
                'batch' => count($bmo_images) > 1
            ));
            
            if ($result && isset($result['success']) && $result['success']) {
                $this->logger->log('info', 'BMO API completed successfully');
                $api_results = $this->process_batch_results($result, $image_mapping);
                
                // Merge API results with skipped images
                $api_results['processed_results'] = array_merge($processed_results, $api_results['processed_results']);
                $api_results['failed'] += count($skipped_images);
                
                return $api_results;
            } else {
                $error_msg = isset($result['error']) ? $result['error'] : 'BMO API failed';
                return array(
                    'successful' => 0,
                    'failed' => count($images),
                    'errors' => array($error_msg),
                    'processed_results' => $processed_results,
                    'api_response' => $result
                );
            }
            
        } catch (Exception $e) {
            $this->logger->log('error', 'BMO processing exception: ' . $e->getMessage());
            return $this->create_batch_error_result($images, 'Processing error: ' . $e->getMessage());
        }
    }
    
    /**
     * Process BMO API batch results - simplified version
     */
    private function process_batch_results($result, $image_mapping) {
        $successful = 0;
        $failed = 0;
        $errors = array();
        $processed_results = array();
        
        // Handle batch results - simplified approach
        $results = isset($result['results']) ? $result['results'] : array($result);
        
        foreach ($results as $index => $image_result) {
            $attachment_id = $image_mapping[$index] ?? null;
            
            if (!$attachment_id) {
                continue;
            }
            
            $image_data = $this->get_image_data_for_ui($attachment_id);
            
            // Determine if successful
            $is_successful = isset($image_result['success']) && $image_result['success'];
            
            if ($is_successful) {
                $successful++;
                $action = 'Optimized via BMO API';
                $size_reduction = 0;
                
                // Handle skipped vs optimized
                if (isset($image_result['skipped']) && $image_result['skipped']) {
                    $action = 'Skipped - Already optimized';
                } else {
                    // Try to get compression ratio
                    if (isset($image_result['data']['compressionRatio'])) {
                        $size_reduction = $image_result['data']['compressionRatio'];
                    }
                }
            } else {
                $failed++;
                $action = 'Optimization failed';
                $size_reduction = 0;
                
                // Get error message if available
                if (isset($image_result['error'])) {
                    $action = $image_result['error'];
                    $errors[] = $image_data['name'] . ': ' . $image_result['error'];
                } else {
                    $errors[] = $image_data['name'] . ': Unknown error';
                }
            }
            
            $processed_results[] = array(
                'attachment_id' => $attachment_id,
                'name' => $image_data['name'],
                'thumbnail' => $image_data['thumbnail'],
                'success' => $is_successful,
                'action' => $action,
                'size_reduction' => $size_reduction,
                'result_data' => $image_result
            );
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
     * Get image data for UI display - simplified version
     */
    private function get_image_data_for_ui($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        $post_title = get_the_title($attachment_id);
        
        // Simple fallback for name
        $name = $post_title;
        if (empty($name) && $file_path) {
            $name = basename($file_path);
        }
        if (empty($name)) {
            $name = "Image #{$attachment_id}";
        }
        
        // Simple thumbnail - use WordPress default if unavailable
        $thumbnail_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
        if (empty($thumbnail_url)) {
            $thumbnail_url = includes_url('images/media/default.png');
        }
        
        return array(
            'name' => $name,
            'thumbnail' => $thumbnail_url,
            'file_path' => $file_path ?: '',
            'file_size' => ($file_path && file_exists($file_path)) ? filesize($file_path) : 0
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
        
        // Get all image attachments (not recently optimized to avoid double-processing)
        $all_images = $wpdb->get_results("
            SELECT p.ID, p.post_mime_type 
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type LIKE 'image/%'
            AND p.ID NOT IN (
                SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_bunny_last_optimized' 
                AND meta_value > DATE_SUB(NOW(), INTERVAL 1 DAY)
            )
            ORDER BY p.post_date DESC
            LIMIT 1000
        ");
        
        // Define modern/optimized formats
        $modern_formats = array('image/webp', 'image/avif', 'image/svg+xml');
        $legacy_formats = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif');
        
        // Get migrated images
        $migrated_attachment_ids = $wpdb->get_col("
            SELECT DISTINCT attachment_id 
            FROM {$wpdb->prefix}bunny_offloaded_files 
            WHERE is_synced = 1
        ");
        $migrated_ids_lookup = array_flip($migrated_attachment_ids);
        
        // Initialize counters
        $local_eligible_count = 0;        // Images that need optimization (legacy formats, local)
        $already_optimized_count = 0;     // Images in modern formats but still local
        $migrated_count = count($migrated_attachment_ids); // Images on CDN
        $skipped_count = 0;
        $skipped_reasons = array();
        
        foreach ($all_images as $image) {
            $attachment_id = $image->ID;
            $mime_type = $image->post_mime_type;
            
            // Check if this image is migrated (on CDN)
            if (isset($migrated_ids_lookup[$attachment_id])) {
                // Skip - already counted in migrated_count
                // CDN images are not processed for local optimization, so they don't count as "skipped"
                continue;
            }
            
            // For local images, validate they meet basic requirements
            $validation_result = $this->validate_attachment_for_optimization($attachment_id);
            
            if (!$validation_result['valid']) {
                $skipped_count++;
                $reason = $validation_result['reason'];
                if (!isset($skipped_reasons[$reason])) {
                    $skipped_reasons[$reason] = 0;
                }
                $skipped_reasons[$reason]++;
                continue;
            }
            
            // Categorize based on format
            if (in_array($mime_type, $modern_formats)) {
                // Modern format but still local = Already Optimized (pending migration)
                $already_optimized_count++;
            } elseif (in_array($mime_type, $legacy_formats)) {
                // Legacy format = Needs optimization
                $local_eligible_count++;
            } else {
                // Unknown format - skip
                $skipped_count++;
                $reason = 'Unsupported image format: ' . $mime_type;
                if (!isset($skipped_reasons[$reason])) {
                    $skipped_reasons[$reason] = 0;
                }
                $skipped_reasons[$reason]++;
            }
        }
        
        $this->logger->log('info', 'Detailed stats calculation completed', array(
            'total_images_checked' => count($all_images),
            'local_eligible' => $local_eligible_count,
            'already_optimized' => $already_optimized_count,
            'migrated_count' => $migrated_count,
            'skipped_count' => $skipped_count,
            'skipped_reasons' => $skipped_reasons
        ));
        
        // Get optimization criteria information
        $criteria_info = $this->get_optimization_criteria_info();
        
        $stats = array(
            'local' => array(
                'total_eligible' => $local_eligible_count,
                'has_files_to_optimize' => $local_eligible_count > 0,
                'skipped_count' => $skipped_count,
                'skipped_reasons' => $skipped_reasons
            ),
            'cloud' => array(
                'total_eligible' => 0,
                'has_files_to_optimize' => false
            ),
            'already_optimized' => $already_optimized_count,
            'images_migrated' => $migrated_count,
            'batch_size' => 10, // Fixed BMO API batch size
            'api_type' => 'BMO API (External)',
            'criteria_info' => $criteria_info
        );
        
        // Cache for 5 minutes
        wp_cache_set($cache_key, $stats, 'bunny_media_offload', 5 * MINUTE_IN_SECONDS);
        
        return $stats;
    }
    
    /**
     * Get optimization criteria information for display purposes
     */
    public function get_optimization_criteria_info() {
        return array(
            'supported_formats' => array(
                'conversion_targets' => array('JPEG', 'PNG', 'GIF'),
                'recompression_targets' => array('WebP', 'AVIF'),
                'excluded_formats' => array('SVG', 'ICO', 'BMP')
            ),
            'size_requirements' => array(
                'minimum_size' => '35KB',
                'minimum_bytes' => 35840,
                'reason' => 'BMO API requirement for efficient processing'
            ),
            'exclusion_criteria' => array(
                'already_on_cdn' => 'Images already migrated to CDN are not processed for local optimization'
            )
        );
    }
    
    /**
     * Validate attachment for optimization eligibility
     * This uses the same logic as the session manager to ensure consistency
     */
    private function validate_attachment_for_optimization($attachment_id) {
        // Check if post exists
        $post = get_post($attachment_id);
        if (!$post) {
            return array(
                'valid' => false,
                'reason' => 'Post not found'
            );
        }
        
        // Check if it's an image
        if (!wp_attachment_is_image($attachment_id)) {
            return array(
                'valid' => false,
                'reason' => 'Not an image attachment'
            );
        }
        
        // Check file existence and URL accessibility
        // These issues typically indicate the image is on CDN or has been moved
        $file_path = get_attached_file($attachment_id);
        $has_local_file = $file_path && file_exists($file_path);
        $image_url = wp_get_attachment_url($attachment_id);
        
        if (!$has_local_file) {
            return array(
                'valid' => false,
                'reason' => 'Image not available locally (likely on CDN)'
            );
        }
        
        // Check file size (must be at least 35KB for BMO API)
        $file_size = filesize($file_path);
        if ($file_size < 35840) { // 35KB = 35 * 1024 bytes
            return array(
                'valid' => false,
                'reason' => 'File size below 35KB minimum'
            );
        }
        
        // Try to get a valid URL for BMO API processing
        if (!$image_url) {
            // Try alternative methods
            $upload_dir = wp_upload_dir();
            $meta_file = get_post_meta($attachment_id, '_wp_attached_file', true);
            
            if ($meta_file) {
                $image_url = $upload_dir['baseurl'] . '/' . $meta_file;
                $image_url = str_replace('\\', '/', $image_url);
            }
            
            if (!$image_url || !filter_var($image_url, FILTER_VALIDATE_URL)) {
                return array(
                    'valid' => false,
                    'reason' => 'Image not available locally (likely on CDN)'
                );
            }
        }
        
        return array(
            'valid' => true,
            'reason' => 'Passed all validation checks'
        );
    }
    
    /**
     * Get original file URL - simplified approach
     * Since validation already filters out problematic images, use the most reliable method
     */
    private function get_image_url($attachment_id) {
        // Use standard WordPress function - validation already ensures this works for eligible images
        $image_url = wp_get_attachment_url($attachment_id);
        
        if ($image_url && filter_var($image_url, FILTER_VALIDATE_URL)) {
            return $image_url;
        }
        
        // Log failure - this should rarely happen since validation filters out problematic images
        $this->logger->log('warning', "No valid URL for attachment {$attachment_id} (likely on CDN)", array(
            'attachment_id' => $attachment_id
        ));
        
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