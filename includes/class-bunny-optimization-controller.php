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
        
        if (empty($images)) {
            wp_send_json_error('No images found that need optimization.');
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
        
        // Get next batch of images
        $images = $this->session_manager->get_next_batch($session_id, 10); // BMO API batch size
        
        if (empty($images)) {
            // Mark session as completed
            $this->session_manager->update_session($session_id, array('status' => 'completed'));
            $progress = $this->session_manager->get_progress($session_id);
            
            wp_send_json_success(array_merge($progress, array(
                'message' => __('Optimization completed.', 'bunny-media-offload'),
                'recent_processed' => array()
            )));
            return;
        }
        
        // Process batch via BMO API
        $batch_result = $this->bmo_processor->process_batch($images);
        
        // Update session with results
        $session = $this->session_manager->get_session($session_id);
        $updates = array(
            'processed' => $session['processed'] + count($images),
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
        
        // Prepare response
        $response = array_merge($progress, array(
            'recent_processed' => $this->format_recent_processed($batch_result['processed_results'])
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
     * Format recent processed results for UI
     */
    private function format_recent_processed($processed_results) {
        $formatted = array();
        
        foreach ($processed_results as $result) {
            $formatted[] = array(
                'name' => $result['name'],
                'thumbnail' => $result['thumbnail'],
                'success' => $result['success'],
                'action' => $result['action'],
                'size_reduction' => $result['size_reduction']
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
} 