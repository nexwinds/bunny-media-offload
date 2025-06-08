<?php
/**
 * Bunny Image Optimizer - Modular Architecture
 * Orchestrates optimization via modular components using internal APIs
 */
class Bunny_Optimizer {
    
    private $controller;
    private $session_manager;
    private $bmo_processor;
    
    /**
     * Constructor - Initializes modular components
     */
    public function __construct($api, $settings, $logger, $bmo_api = null) {
        // Initialize modular components
        $this->session_manager = new Bunny_Optimization_Session($logger);
        $this->bmo_processor = new Bunny_BMO_Processor($bmo_api, $settings, $logger);
        $this->controller = new Bunny_Optimization_Controller($api, $settings, $logger, $this->session_manager, $this->bmo_processor);
    }
    
    /**
     * Public API Methods - Delegate to appropriate components
     */
    
    // Session Management API
    public function create_session($images) {
        return $this->session_manager->create_session($images);
    }
    
    public function get_session($session_id) {
        return $this->session_manager->get_session($session_id);
    }
    
    public function cancel_session($session_id) {
        return $this->session_manager->cancel_session($session_id);
    }
    
    public function get_progress($session_id) {
        return $this->session_manager->get_progress($session_id);
    }
    
    // BMO Processing API
    public function is_api_available() {
        return $this->bmo_processor->is_available();
    }
    
    public function get_api_errors() {
        return $this->bmo_processor->get_configuration_errors();
    }
    
    public function process_batch($images) {
        return $this->bmo_processor->process_batch($images);
    }
    
    // Controller API (WordPress Integration)
    public function optimize_on_upload($file_path, $attachment_id) {
        return $this->controller->optimize_on_upload($file_path, $attachment_id);
    }
    
    // Legacy Support Methods - Delegate to controller
    public function add_to_optimization_queue($attachment_ids, $priority = 'normal') {
        return $this->controller->add_to_optimization_queue($attachment_ids, $priority);
    }
    
    public function handle_step_optimization() {
        return $this->controller->handle_step_optimization();
    }
    
    public function ajax_optimization_batch() {
        return $this->controller->ajax_optimization_batch();
    }
    
    public function ajax_cancel_optimization() {
        return $this->controller->ajax_cancel_optimization();
    }
    
    public function handle_bulk_optimization() {
        return $this->controller->handle_bulk_optimization();
    }
    
    public function get_optimization_status() {
        return $this->controller->get_optimization_status();
    }
    
    // Statistics API - Delegate to processor
    public function get_optimization_stats() {
        return $this->bmo_processor->get_optimization_stats();
    }
    
    public function get_detailed_optimization_stats() {
        return $this->bmo_processor->get_detailed_stats();
    }
    
    public function get_optimization_criteria() {
        return $this->controller->get_optimization_criteria();
    }
    
    /**
     * Component Access Methods - For advanced usage
     */
    public function get_session_manager() {
        return $this->session_manager;
    }
    
    public function get_bmo_processor() {
        return $this->bmo_processor;
    }
    
    public function get_controller() {
        return $this->controller;
    }
} 