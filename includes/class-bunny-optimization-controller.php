<?php
/**
 * Bunny Optimization Controller
 * Handles WordPress integration, AJAX handlers, and coordinates optimization flow
 */
class Bunny_Optimization_Controller {
    
    private $api;
    private $settings;
    private $logger;
    private $session_manager;
    private $bmo_processor;
    
    /**
     * Constructor
     */
    public function __construct($api, $settings, $logger, $session_manager, $bmo_processor) {
        $this->api = $api;
        $this->settings = $settings;
        $this->logger = $logger;
        $this->session_manager = $session_manager;
        $this->bmo_processor = $bmo_processor;
        
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // AJAX handlers for manual optimization
        add_action('wp_ajax_bunny_start_step_optimization', array($this, 'handle_step_optimization'));
        add_action('wp_ajax_bunny_optimization_batch', array($this, 'ajax_optimization_batch'));
        add_action('wp_ajax_bunny_cancel_optimization', array($this, 'ajax_cancel_optimization'));
        
        // New single image optimization handlers
        add_action('wp_ajax_bunny_get_images_to_optimize', array($this, 'ajax_get_images_to_optimize'));
        add_action('wp_ajax_bunny_optimize_single_image', array($this, 'ajax_optimize_single_image'));
        
        // Cleanup hook
        add_action('bunny_cleanup_sessions', array($this, 'cleanup_old_sessions'));
    }
    
    /**
     * Handle step-by-step optimization AJAX request
     */
    public function handle_step_optimization() {
        if (!$this->validate_optimization_request()) {
            return;
        }
        
        if (!$this->bmo_processor->is_available()) {
            $errors = $this->bmo_processor->get_configuration_errors();
            wp_send_json_error('BMO API configuration errors: ' . implode(', ', $errors));
            return;
        }
        
        $images = $this->session_manager->get_optimizable_attachments();
        
        $this->logger->log('info', 'Step optimization - found attachments', array(
            'count' => count($images),
            'sample_ids' => array_slice($images, 0, 5)
        ));
        
        if (empty($images)) {
            // Get detailed stats to provide more helpful error message
            $detailed_stats = $this->bmo_processor->get_detailed_stats();
            $skipped_count = isset($detailed_stats['local']['skipped_count']) ? $detailed_stats['local']['skipped_count'] : 0;
            
            if ($skipped_count > 0) {
                $error_message = sprintf(
                    'No images found that meet optimization criteria. %d images were skipped due to various reasons (file size too small, missing files, etc.). Use the "Run Diagnostics" button for detailed information.',
                    $skipped_count
                );
            } else {
                $error_message = 'No images found that need optimization. All your images may already be optimized or migrated to the CDN.';
            }
            
            wp_send_json_error($error_message);
            return;
        }
        
        $session_data = $this->session_manager->create_session($images);
        
        if (!$session_data) {
            wp_send_json_error('Failed to create optimization session.');
            return;
        }
        
        wp_send_json_success(array(
            'session_id' => $session_data['id'],
            'total_images' => count($images),
            'message' => sprintf('Starting BMO API optimization of %d images...', count($images))
        ));
    }
    
    /**
     * Handle optimization batch AJAX request
     */
    public function ajax_optimization_batch() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        
        if (!$session_id) {
            wp_send_json_error(array('message' => 'Session ID required.'));
            return;
        }
        
        // Check if session is cancelled
        if ($this->session_manager->is_cancelled($session_id)) {
            wp_send_json_error(array('message' => __('Optimization was cancelled.', 'bunny-media-offload')));
            return;
        }
        
        // Check if session is completed
        if ($this->session_manager->is_completed($session_id)) {
            $progress = $this->session_manager->get_progress($session_id);
            wp_send_json_success(array_merge($progress, array(
                'message' => __('Optimization completed.', 'bunny-media-offload'),
                'recent_processed' => array()
            )));
            return;
        }
        
        // Get next batch of images - reduce batch size to 3 to prevent timeouts
        $batch_size = 3; // Reduced from 20 to 3 to avoid timeouts
        $images = $this->session_manager->get_next_batch($session_id, $batch_size);
        
        $this->logger->log('info', 'Processing batch', array(
            'session_id' => $session_id,
            'batch_size' => count($images),
            'attachment_ids' => $images
        ));
        
        if (empty($images)) {
            // Mark session as completed
            $this->session_manager->update_session($session_id, array('status' => 'completed'));
            $progress = $this->session_manager->get_progress($session_id);
            
            wp_send_json_success(array_merge($progress, array(
                'message' => __('Optimization completed.', 'bunny-media-offload'),
                'recent_processed' => array(),
                'completed' => true
            )));
            return;
        }
        
        // Implement retry logic
        $max_retries = 2;
        $retry_count = 0;
        $batch_result = null;
        $last_error = null;
        
        while ($retry_count <= $max_retries) {
            try {
                // Process batch via BMO API
                $batch_result = $this->bmo_processor->process_batch($images);
                break; // Success, exit retry loop
            } catch (Exception $e) {
                $retry_count++;
                $last_error = $e->getMessage();
                $this->logger->log('warning', "Batch processing failed (attempt {$retry_count}/{$max_retries}): {$last_error}");
                
                if ($retry_count <= $max_retries) {
                    // Wait before retrying
                    sleep(2);
                }
            }
        }
        
        // If all retries failed
        if ($retry_count > $max_retries && $batch_result === null) {
            $error_message = "Failed to process batch after {$max_retries} attempts. Last error: {$last_error}";
            $this->logger->log('error', $error_message);
            
            // Create a basic error result
            $batch_result = array(
                'successful' => 0,
                'failed' => count($images),
                'errors' => array($error_message),
                'processed_results' => array()
            );
            
            // Add individual image errors
            foreach ($images as $attachment_id) {
                $image_data = $this->bmo_processor->get_image_data_for_ui($attachment_id);
                $batch_result['processed_results'][] = array(
                    'attachment_id' => $attachment_id,
                    'name' => $image_data['name'],
                    'thumbnail' => $image_data['thumbnail'],
                    'success' => false,
                    'action' => "Processing error: {$last_error}",
                    'size_reduction' => 0,
                    'result_data' => null
                );
            }
        }
        
        // Update session with results - only count images that were actually processed
        $session = $this->session_manager->get_session($session_id);
        
        $this->logger->log('info', 'Batch processing complete', array(
            'session_id' => $session_id,
            'requested_batch_size' => count($images),
            'actually_processed' => count($batch_result['processed_results']),
            'successful_optimizations' => $batch_result['successful'],
            'failed_optimizations' => $batch_result['failed'],
            'session_processed_before' => $session['processed'],
            'session_total' => $session['total_images']
        ));
        
        // Update session progress based on actual processed images, not requested batch size
        $actually_processed = count($batch_result['processed_results']);
        
        $updates = array(
            'processed' => $session['processed'] + $actually_processed,
            'successful' => $session['successful'] + $batch_result['successful'],
            'failed' => $session['failed'] + $batch_result['failed'],
            'errors' => array_merge($session['errors'], $batch_result['errors'])
        );
        
        // Update attachment metadata for successful optimizations
        foreach ($batch_result['processed_results'] as $result) {
            if ($result['success'] && isset($result['result_data'])) {
                $this->bmo_processor->update_attachment_meta($result['attachment_id'], $result['result_data']);
            }
        }
        
        $this->session_manager->update_session($session_id, $updates);
        
        // Get updated progress
        $progress = $this->session_manager->get_progress($session_id);
        
        // Check if there are more images that can be processed
        $remaining_images = $this->session_manager->get_next_batch($session_id, $batch_size);
        $has_more_processable = !empty($remaining_images);
        
        // Mark as completed if no more images can be processed
        if (!$has_more_processable) {
            $this->session_manager->update_session($session_id, array('status' => 'completed'));
            $progress['completed'] = true;
        }
        
        // Prepare response
        $response = array_merge($progress, array(
            'recent_processed' => $this->format_recent_processed($batch_result['processed_results']),
            'batch_info' => array(
                'current_batch' => ceil($progress['processed'] / $batch_size),
                'total_batches' => ceil($progress['total'] / $batch_size),
                'images_in_batch' => $actually_processed, // Use actual processed count
                'api_batch_size' => $batch_size,
                'requested_batch_size' => count($images),
                'validation_passed' => $actually_processed,
                'has_more_processable' => $has_more_processable
            )
        ));
        
        if ($progress['completed']) {
            $this->logger->log('info', 'BMO API optimization completed', array(
                'session_id' => $session_id,
                'total_processed' => $progress['processed'],
                'successful' => $progress['successful'],
                'failed' => $progress['failed']
            ));
            
            $response['message'] = sprintf(
                __('BMO API optimization completed. %1$d images processed, %2$d successful, %3$d failed.', 'bunny-media-offload'),
                $progress['processed'],
                $progress['successful'],
                $progress['failed']
            );
        } else {
            $response['message'] = sprintf(
                __('Batch %1$d/%2$d completed: %3$d images processed (%4$d passed validation)', 'bunny-media-offload'),
                ceil($progress['processed'] / $batch_size),
                ceil($progress['total'] / $batch_size),
                $actually_processed,
                $actually_processed
            );
        }
        
        wp_send_json_success($response);
    }
    
    /**
     * Handle cancel optimization AJAX request
     */
    public function ajax_cancel_optimization() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        
        if ($this->session_manager->cancel_session($session_id)) {
            wp_send_json_success(array('message' => __('Optimization cancelled.', 'bunny-media-offload')));
        } else {
            wp_send_json_error(array('message' => __('Failed to cancel optimization.', 'bunny-media-offload')));
        }
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
     * Format recent processed results for UI - with safe field access
     */
    private function format_recent_processed($processed_results) {
        $formatted = array();
        
        if (!is_array($processed_results)) {
            return $formatted;
        }
        
        foreach ($processed_results as $result) {
            // Skip invalid results
            if (!is_array($result)) {
                continue;
            }
            
            $formatted[] = array(
                'name' => isset($result['name']) ? $result['name'] : 'Unknown image',
                'thumbnail' => isset($result['thumbnail']) ? $result['thumbnail'] : '',
                'success' => isset($result['success']) ? $result['success'] : false,
                'action' => isset($result['action']) ? $result['action'] : 'Unknown status',
                'size_reduction' => isset($result['size_reduction']) ? $result['size_reduction'] : 0
            );
        }
        
        return $formatted;
    }
    
    /**
     * Add files to optimization queue (legacy support)
     */
    public function add_to_optimization_queue($attachment_ids, $priority = 'normal') {
        return $this->session_manager->add_to_queue($attachment_ids, $priority);
    }
    
    /**
     * Get optimization statistics for dashboard
     */
    public function get_optimization_stats() {
        return $this->bmo_processor->get_optimization_stats();
    }
    
    /**
     * Get detailed optimization statistics
     */
    public function get_detailed_optimization_stats() {
        return $this->bmo_processor->get_detailed_stats();
    }
    
    /**
     * Get optimization criteria analysis (simplified for BMO API)
     */
    public function get_optimization_criteria() {
        $detailed_stats = $this->get_detailed_optimization_stats();
        
        return array(
            'total_images' => $detailed_stats['local']['total_eligible'] + $detailed_stats['already_optimized'],
            'total_eligible' => $detailed_stats['local']['total_eligible'],
            'already_optimized_count' => $detailed_stats['already_optimized'],
            'api_type' => 'External BMO API'
        );
    }
    
    /**
     * Handle bulk optimization AJAX request (legacy support)
     */
    public function handle_bulk_optimization() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $file_types = isset($_POST['file_types']) ? array_map('sanitize_text_field', wp_unslash($_POST['file_types'])) : array('jpg', 'jpeg', 'png', 'gif');
        $priority = isset($_POST['priority']) ? sanitize_text_field(wp_unslash($_POST['priority'])) : 'normal';
        
        // Get optimizable images
        $attachment_ids = $this->session_manager->get_optimizable_attachments();
        
        if (empty($attachment_ids)) {
            wp_send_json_error(__('No images found that need optimization.', 'bunny-media-offload'));
        }
        
        // Add to optimization queue
        $added = $this->session_manager->add_to_queue($attachment_ids, $priority);
        
        wp_send_json_success(array(
            'message' => sprintf(__('Added %d images to optimization queue.', 'bunny-media-offload'), $added),
            'total' => $added
        ));
    }
    
    /**
     * Get optimization status (legacy support)
     */
    public function get_optimization_status() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        // For compatibility, return basic stats
        $stats = new stdClass();
        $stats->total = 0;
        $stats->pending = 0;
        $stats->processing = 0;
        $stats->completed = 0;
        $stats->failed = 0;
        $stats->skipped = 0;
        
        wp_send_json_success($stats);
    }
    
    /**
     * Clean up old sessions
     */
    public function cleanup_old_sessions() {
        $this->session_manager->cleanup_old_sessions();
    }
    
    /**
     * Utility method to check if file is an image
     */
    private function is_image($file_path) {
        $file_type = wp_check_filetype($file_path);
        return strpos($file_type['type'], 'image/') === 0;
    }
    
    /**
     * Get images that need optimization via AJAX
     */
    public function ajax_get_images_to_optimize() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 10;
        
        if (!$session_id) {
            wp_send_json_error(array('message' => 'Session ID required.'));
            return;
        }
        
        // Check if session is cancelled or completed
        if ($this->session_manager->is_cancelled($session_id) || $this->session_manager->is_completed($session_id)) {
            wp_send_json_success(array('images' => array()));
            return;
        }
        
        // Get next batch of images
        $images = $this->session_manager->get_next_batch($session_id, $limit);
        
        wp_send_json_success(array(
            'images' => $images,
            'count' => count($images),
            'session_status' => $this->session_manager->get_status($session_id)
        ));
    }
    
    /**
     * Process a single image via AJAX
     */
    public function ajax_optimize_single_image() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;
        
        if (!$session_id || !$image_id) {
            wp_send_json_error(array('message' => 'Session ID and image ID required.'));
            return;
        }
        
        // Check if session is cancelled or completed
        if ($this->session_manager->is_cancelled($session_id)) {
            wp_send_json_error(array('message' => 'Optimization was cancelled.'));
            return;
        }
        
        if ($this->session_manager->is_completed($session_id)) {
            wp_send_json_error(array('message' => 'Optimization already completed.'));
            return;
        }
        
        $this->logger->log('info', 'Processing single image', array(
            'session_id' => $session_id,
            'image_id' => $image_id
        ));
        
        try {
            // Process single image with BMO processor
            $result = $this->process_single_image($image_id);
            
            // Update session progress
            $session = $this->session_manager->get_session($session_id);
            $updates = array(
                'processed' => $session['processed'] + 1,
                'successful' => $session['successful'] + ($result['success'] ? 1 : 0),
                'failed' => $session['failed'] + ($result['success'] ? 0 : 1)
            );
            
            if (!$result['success'] && !empty($result['error'])) {
                $updates['errors'] = array_merge($session['errors'], array($result['error']));
            }
            
            $this->session_manager->update_session($session_id, $updates);
            
            // Return success with image result
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $this->logger->log('error', 'Single image optimization error', array(
                'session_id' => $session_id,
                'image_id' => $image_id,
                'error' => $error_message
            ));
            
            // Update session with error
            $session = $this->session_manager->get_session($session_id);
            $updates = array(
                'processed' => $session['processed'] + 1,
                'failed' => $session['failed'] + 1,
                'errors' => array_merge($session['errors'], array($error_message))
            );
            $this->session_manager->update_session($session_id, $updates);
            
            wp_send_json_error(array(
                'message' => $error_message,
                'image_id' => $image_id
            ));
        }
    }
    
    /**
     * Process a single image
     * 
     * @param int $image_id Image ID to process
     * @return array Processing result
     */
    private function process_single_image($image_id) {
        // Get image data
        $image_data = $this->bmo_processor->get_image_data_for_ui($image_id);
        
        try {
            // Validate the image
            $validation = $this->bmo_processor->validate_attachment_for_optimization($image_id);
            
            if (!$validation['valid']) {
                return array(
                    'success' => false,
                    'image_id' => $image_id,
                    'name' => $image_data['name'],
                    'thumbnail' => $image_data['thumbnail'],
                    'action' => $validation['reason'],
                    'error' => "Image {$image_data['name']} validation failed: {$validation['reason']}"
                );
            }
            
            // Get image URL
            $image_url = $this->bmo_processor->get_image_url($image_id);
            
            if (!$image_url) {
                return array(
                    'success' => false,
                    'image_id' => $image_id,
                    'name' => $image_data['name'],
                    'thumbnail' => $image_data['thumbnail'],
                    'action' => 'Image URL not available',
                    'error' => "Image {$image_data['name']} URL not available"
                );
            }
            
            // Prepare image data for BMO API
            $bmo_image_data = $this->bmo_api->prepare_image_data($image_id, $image_url);
            
            // Process with BMO API
            $api_result = $this->bmo_api->optimize_single_image($image_url, array(
                'format' => $this->settings->get('optimization_format', 'auto'),
                'quality' => $this->settings->get('optimization_quality', 85)
            ));
            
            // Check if optimized successfully
            if (!isset($api_result['success']) || !$api_result['success']) {
                $error = isset($api_result['error']) ? $api_result['error'] : 'Unknown API error';
                return array(
                    'success' => false,
                    'image_id' => $image_id,
                    'name' => $image_data['name'],
                    'thumbnail' => $image_data['thumbnail'],
                    'action' => "API error: {$error}",
                    'error' => "BMO API error for {$image_data['name']}: {$error}"
                );
            }
            
            // Get single result from API response
            $result_data = isset($api_result['results']) ? $api_result['results'] : $api_result;
            
            if (is_array($result_data)) {
                $result_data = $result_data[0];
            }
            
            // Check if image was skipped
            if (isset($result_data['skipped']) && $result_data['skipped']) {
                $reason = isset($result_data['reason']) ? $result_data['reason'] : 'Already optimized';
                return array(
                    'success' => true,
                    'image_id' => $image_id,
                    'name' => $image_data['name'],
                    'thumbnail' => $image_data['thumbnail'],
                    'action' => "Skipped: {$reason}",
                    'size_reduction' => 0,
                    'skipped' => true,
                    'reason' => $reason
                );
            }
            
            // Calculate size reduction
            $size_reduction = 0;
            if (isset($result_data['data']['compressionRatio'])) {
                $size_reduction = $result_data['data']['compressionRatio'];
            }
            
            // Update attachment metadata
            $this->bmo_processor->update_attachment_meta($image_id, $result_data);
            
            // Return success result
            return array(
                'success' => true,
                'image_id' => $image_id,
                'name' => $image_data['name'],
                'thumbnail' => $image_data['thumbnail'],
                'action' => 'Optimized via BMO API',
                'size_reduction' => $size_reduction,
                'skipped' => false
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'image_id' => $image_id,
                'name' => $image_data['name'],
                'thumbnail' => $image_data['thumbnail'],
                'action' => "Error: {$e->getMessage()}",
                'error' => "Error processing {$image_data['name']}: {$e->getMessage()}"
            );
        }
    }
} 