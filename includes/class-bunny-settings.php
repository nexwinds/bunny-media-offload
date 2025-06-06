<?php
/**
 * Bunny settings manager
 */
class Bunny_Settings {
    
    private $option_name = 'bunny_media_offload_settings';
    private $settings;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->load_settings();
    }
    
    /**
     * Load settings from database
     */
    private function load_settings() {
        $this->settings = get_option($this->option_name, array());
    }
    
    /**
     * Get setting value, checking wp-config.php constants first
     */
    public function get($key, $default = null) {
        // Check for wp-config.php constants first
        $constant_value = $this->get_constant_value($key);
        if ($constant_value !== null) {
            return $constant_value;
        }
        
        // Fall back to database settings
        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }
    
    /**
     * Get constant value from wp-config.php if defined
     * Only API credentials should be configurable via wp-config.php
     */
    private function get_constant_value($key) {
        $constant_map = array(
            'api_key' => 'BUNNY_API_KEY',
            'storage_zone' => 'BUNNY_STORAGE_ZONE',
            'custom_hostname' => 'BUNNY_CUSTOM_HOSTNAME'
        );
        
        if (isset($constant_map[$key])) {
            $constant_name = $constant_map[$key];
            if (defined($constant_name)) {
                $value = constant($constant_name);
                

                
                return $value;
            }
        }
        
        return null;
    }
    
    /**
     * Set setting value
     */
    public function set($key, $value) {
        $this->settings[$key] = $value;
        return $this->save();
    }
    
    /**
     * Update multiple settings
     */
    public function update($settings) {
        $this->settings = array_merge($this->settings, $settings);
        return $this->save();
    }
    
    /**
     * Save settings to database
     */
    public function save() {
        return update_option($this->option_name, $this->settings);
    }
    
    /**
     * Get all settings
     */
    public function get_all() {
        return $this->settings;
    }
    
    /**
     * Reset settings to defaults
     */
    public function reset() {
        $this->settings = $this->get_default_settings();
        return $this->save();
    }
    
    /**
     * Get default settings
     */
    public function get_default_settings() {
        return array(
            'api_key' => '',
            'storage_zone' => '',
            'custom_hostname' => '',
            'auto_offload' => true,
            'delete_local' => true,
            'file_versioning' => true,
            'allowed_file_types' => array('webp', 'avif'),
            'allowed_post_types' => array('attachment', 'product'),
            'batch_size' => 100,
            'enable_logs' => true,
            'log_level' => 'info',
            'optimization_enabled' => false,
            'optimize_on_upload' => true,
            'optimization_format' => 'avif',
            'optimization_max_size' => '50kb',
            'optimization_batch_size' => 60,
            'migration_concurrent_limit' => 4,
            'optimization_concurrent_limit' => 3
        );
    }
    
    /**
     * Validate settings
     */
    public function validate($settings) {
        $validated = array();
        $errors = array();
        
        // Validate API key
        if (empty($settings['api_key'])) {
            $errors['api_key'] = __('API key is required.', 'bunny-media-offload');
        } else {
            $validated['api_key'] = sanitize_text_field($settings['api_key']);
        }
        
        // Validate storage zone
        if (empty($settings['storage_zone'])) {
            $errors['storage_zone'] = __('Storage zone is required.', 'bunny-media-offload');
        } else {
            $validated['storage_zone'] = sanitize_text_field($settings['storage_zone']);
        }
        
        // Validate custom hostname
        if (!empty($settings['custom_hostname'])) {
            if (!filter_var('https://' . $settings['custom_hostname'], FILTER_VALIDATE_URL)) {
                $errors['custom_hostname'] = __('Invalid custom hostname format.', 'bunny-media-offload');
            } else {
                $validated['custom_hostname'] = sanitize_text_field($settings['custom_hostname']);
            }
        } else {
            $validated['custom_hostname'] = '';
        }
        
        // Validate boolean settings
        $boolean_settings = array('auto_offload', 'delete_local', 'file_versioning', 'enable_logs', 'optimization_enabled', 'optimize_on_upload');
        foreach ($boolean_settings as $setting) {
            $validated[$setting] = !empty($settings[$setting]);
        }
        
        // Validate file types
        if (!empty($settings['allowed_file_types']) && is_array($settings['allowed_file_types'])) {
            $validated['allowed_file_types'] = array_map('sanitize_text_field', $settings['allowed_file_types']);
        } else {
            $validated['allowed_file_types'] = $this->get_default_settings()['allowed_file_types'];
        }
        
        // Validate post types
        if (!empty($settings['allowed_post_types']) && is_array($settings['allowed_post_types'])) {
            $validated['allowed_post_types'] = array_map('sanitize_text_field', $settings['allowed_post_types']);
        } else {
            $validated['allowed_post_types'] = $this->get_default_settings()['allowed_post_types'];
        }
        
        // Validate batch size
        $valid_batch_sizes = array(50, 100, 150, 250);
        $batch_size = intval($settings['batch_size']);
        if (!in_array($batch_size, $valid_batch_sizes)) {
            $validated['batch_size'] = 100; // Default
        } else {
            $validated['batch_size'] = $batch_size;
        }
        
        // Validate log level
        $valid_log_levels = array('error', 'warning', 'info', 'debug');
        if (!in_array($settings['log_level'], $valid_log_levels)) {
            $validated['log_level'] = 'info';
        } else {
            $validated['log_level'] = $settings['log_level'];
        }
        
        // Validate optimization format
        $valid_formats = array('avif', 'webp');
        if (!in_array($settings['optimization_format'], $valid_formats)) {
            $validated['optimization_format'] = 'avif';
        } else {
            $validated['optimization_format'] = $settings['optimization_format'];
        }
        
        // Validate optimization max size
        $valid_sizes = array('40kb', '45kb', '50kb', '55kb', '60kb');
        if (!in_array($settings['optimization_max_size'], $valid_sizes)) {
            $validated['optimization_max_size'] = '50kb';
        } else {
            $validated['optimization_max_size'] = $settings['optimization_max_size'];
        }
        
        // Validate optimization batch size
        $valid_optimization_batch_sizes = array(30, 60, 90, 150);
        $optimization_batch_size = intval($settings['optimization_batch_size']);
        if (!in_array($optimization_batch_size, $valid_optimization_batch_sizes)) {
            $validated['optimization_batch_size'] = 60; // Default
        } else {
            $validated['optimization_batch_size'] = $optimization_batch_size;
        }
        
        // Validate migration concurrent limit
        $valid_migration_concurrent = array(2, 4, 8);
        $migration_concurrent = intval($settings['migration_concurrent_limit']);
        if (!in_array($migration_concurrent, $valid_migration_concurrent)) {
            $validated['migration_concurrent_limit'] = 4; // Default
        } else {
            $validated['migration_concurrent_limit'] = $migration_concurrent;
        }
        
        // Validate optimization concurrent limit
        $valid_optimization_concurrent = array(2, 3, 5);
        $optimization_concurrent = intval($settings['optimization_concurrent_limit']);
        if (!in_array($optimization_concurrent, $valid_optimization_concurrent)) {
            $validated['optimization_concurrent_limit'] = 3; // Default
        } else {
            $validated['optimization_concurrent_limit'] = $optimization_concurrent;
        }
        
        return array(
            'validated' => $validated,
            'errors' => $errors
        );
    }
    
    /**
     * Check if plugin is properly configured
     */
    public function is_configured() {
        $api_key = $this->get('api_key');
        $storage_zone = $this->get('storage_zone');
        
        return !empty($api_key) && !empty($storage_zone);
    }
    
    /**
     * Check if a setting is defined in wp-config.php
     */
    public function is_constant_defined($key) {
        return $this->get_constant_value($key) !== null;
    }
    
    /**
     * Get configuration source for a setting
     */
    public function get_config_source($key) {
        if ($this->is_constant_defined($key)) {
            return 'wp-config.php';
        }
        return 'database';
    }
    
    /**
     * Get all constants configuration status
     */
    public function get_constants_status() {
        $constants = array(
            'api_key' => 'BUNNY_API_KEY',
            'storage_zone' => 'BUNNY_STORAGE_ZONE',
            'custom_hostname' => 'BUNNY_CUSTOM_HOSTNAME'
        );
        
        $status = array();
        foreach ($constants as $key => $constant_name) {
            $status[$key] = array(
                'constant_name' => $constant_name,
                'defined' => defined($constant_name),
                'value' => defined($constant_name) ? constant($constant_name) : null,
                'source' => $this->get_config_source($key)
            );
        }
        
        return $status;
    }
} 