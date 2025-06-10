<?php
/**
 * Bunny settings manager - Simplified and optimized
 */
class Bunny_Settings {
    
    private $config_file_path;
    private $settings;
    private $constant_map = array(
        'api_key' => 'BUNNY_API_KEY',
        'storage_zone' => 'BUNNY_STORAGE_ZONE',
        'custom_hostname' => 'BUNNY_CUSTOM_HOSTNAME',
        'bmo_api_key' => 'BMO_API_KEY',
        'bmo_api_region' => 'BMO_API_REGION'
    );
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->config_file_path = WP_CONTENT_DIR . '/bunny-config.json';
        $this->load_settings();
    }
    
    /**
     * Load settings from JSON file
     */
    private function load_settings() {
        if (file_exists($this->config_file_path)) {
            $json_content = file_get_contents($this->config_file_path);
            $this->settings = json_decode($json_content, true);
            
            // If JSON is invalid, use defaults
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->settings = $this->get_default_settings();
                $this->save();
            }
        } else {
            // Create default configuration file
            $this->settings = $this->get_default_settings();
            $this->save();
        }
    }
    
    /**
     * Get setting value, checking wp-config.php constants first for API credentials
     */
    public function get($key, $default = null) {
        // Check for wp-config.php constants first (only for API credentials)
        $constant_value = $this->get_constant_value($key);
        if ($constant_value !== null) {
            return $constant_value;
        }
        
        // Fall back to JSON file settings
        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }
    
    /**
     * Get constant value from wp-config.php if defined
     */
    private function get_constant_value($key) {
        if (isset($this->constant_map[$key])) {
            $constant_name = $this->constant_map[$key];
            if (defined($constant_name)) {
                return constant($constant_name);
            }
        }
        return null;
    }
    
    /**
     * Set setting value in JSON file (cannot set wp-config constants)
     */
    public function set($key, $value) {
        // Don't allow setting values that should come from wp-config
        if ($this->is_constant_defined($key)) {
            return false;
        }
        
        $this->settings[$key] = $value;
        return $this->save();
    }
    
    /**
     * Update multiple settings in JSON file
     */
    public function update($settings) {
        foreach ($settings as $key => $value) {
            // Skip settings that should come from wp-config
            if (!$this->is_constant_defined($key)) {
                $this->settings[$key] = $value;
            }
        }
        return $this->save();
    }
    
    /**
     * Save settings to JSON file
     */
    public function save() {
        // Ensure directory exists
        $config_dir = dirname($this->config_file_path);
        if (!is_dir($config_dir)) {
            wp_mkdir_p($config_dir);
        }
        
        // Save to JSON file with pretty printing
        $json_content = json_encode($this->settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if ($json_content === false) {
            return false;
        }
        
        return file_put_contents($this->config_file_path, $json_content) !== false;
    }
    
    /**
     * Get all settings (combines JSON file settings with wp-config constants)
     */
    public function get_all() {
        $all_settings = $this->settings;
        
        // Override with wp-config constants if defined
        foreach (array_keys($this->constant_map) as $key) {
            $constant_value = $this->get_constant_value($key);
            if ($constant_value !== null) {
                $all_settings[$key] = $constant_value;
            }
        }
        
        return $all_settings;
    }
    
    /**
     * Reset settings to defaults (only affects JSON file settings)
     */
    public function reset() {
        $this->settings = $this->get_default_settings();
        return $this->save();
    }
    
    /**
     * Get default settings (excludes wp-config constants)
     */
    public function get_default_settings() {
        return array(
            // API credentials will come from wp-config.php constants
            'delete_local' => true,
            'file_versioning' => true,
            'allowed_file_types' => array('webp', 'avif', 'svg'),
            'allowed_post_types' => array('attachment', 'product'),
            'batch_size' => 100,
            'enable_logs' => true,
            'log_level' => 'info',
            'migration_concurrent_limit' => 4,
            
            // BMO API optimization settings
            'auto_optimize' => false,                  // Auto-optimize on upload
            'max_file_size' => 10240,                  // Maximum file size in KB (10240KB = 10MB is the API limit)
            'optimization_format' => 'auto'            // AVIF is enforced by the API
        );
    }
    
    /**
     * Validate settings (simplified with helper methods)
     */
    public function validate($settings) {
        $validated = array();
        $errors = array();
        
        // Validate wp-config constants (should not be set via form)
        $errors = array_merge($errors, $this->validate_constants($settings));
        
        // Validate boolean settings
        $validated = array_merge($validated, $this->validate_booleans($settings));
        
        // Validate array settings
        $validated = array_merge($validated, $this->validate_arrays($settings));
        
        // Validate numeric/choice settings
        $validated = array_merge($validated, $this->validate_choices($settings));
        
        return array(
            'validated' => $validated,
            'errors' => $errors
        );
    }
    
    /**
     * Validate wp-config constants
     */
    private function validate_constants($settings) {
        $errors = array();
        
        // Check if required constants are defined
        if (!$this->is_constant_defined('custom_hostname')) {
            $errors['custom_hostname'] = __('Custom hostname is required and must be defined in wp-config.php as BUNNY_CUSTOM_HOSTNAME.', 'bunny-media-offload');
        }
        
        // Warn if trying to set constants via form
        foreach (array_keys($this->constant_map) as $key) {
            if (isset($settings[$key]) && !$this->is_constant_defined($key)) {
                $constant_name = $this->constant_map[$key];
                // translators: %1$s is the setting name, %2$s is the constant name
                $errors[$key] = sprintf(__('%1$s should be defined in wp-config.php as %2$s.', 'bunny-media-offload'), ucfirst(str_replace('_', ' ', $key)), $constant_name);
            }
        }
        
        return $errors;
    }
    
    /**
     * Validate boolean settings
     */
    private function validate_booleans($settings) {
        $boolean_settings = array('delete_local', 'file_versioning', 'enable_logs');
        $validated = array();
        
        foreach ($boolean_settings as $setting) {
            // Unchecked checkboxes are not submitted, so default to false
            $validated[$setting] = !empty($settings[$setting]);
        }
        
        return $validated;
    }
    
    /**
     * Validate array settings
     */
    private function validate_arrays($settings) {
        $validated = array();
        $defaults = $this->get_default_settings();
        
        // Validate file types
        if (isset($settings['allowed_file_types'])) {
            $validated['allowed_file_types'] = !empty($settings['allowed_file_types']) && is_array($settings['allowed_file_types']) 
                ? array_map('sanitize_text_field', $settings['allowed_file_types'])
                : $defaults['allowed_file_types'];
        }
        
        // Validate post types
        if (isset($settings['allowed_post_types'])) {
            $validated['allowed_post_types'] = !empty($settings['allowed_post_types']) && is_array($settings['allowed_post_types']) 
                ? array_map('sanitize_text_field', $settings['allowed_post_types'])
                : $defaults['allowed_post_types'];
        }
        
        return $validated;
    }
    
    /**
     * Validate choice-based settings
     */
    private function validate_choices($settings) {
        $validated = array();
        
        // Define valid choices
        $choices = array(
            'batch_size' => array(50, 100, 150, 250),
            'log_level' => array('error', 'warning', 'info', 'debug'),
            'migration_concurrent_limit' => array(2, 4, 8)
        );
        
        $defaults = $this->get_default_settings();
        
        foreach ($choices as $setting => $valid_values) {
            if (isset($settings[$setting])) {
                $value = is_numeric($valid_values[0]) ? intval($settings[$setting]) : $settings[$setting];
                $validated[$setting] = in_array($value, $valid_values) ? $value : ($defaults[$setting] ?? $valid_values[0]);
            }
        }
        
        return $validated;
    }
    
    /**
     * Check if plugin is properly configured
     */
    public function is_configured() {
        $api_key = $this->get('api_key');
        $storage_zone = $this->get('storage_zone');
        $custom_hostname = $this->get('custom_hostname');
        
        return !empty($api_key) && !empty($storage_zone) && !empty($custom_hostname);
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
        return $this->is_constant_defined($key) ? 'wp-config.php' : 'JSON file';
    }
    
    /**
     * Get all constants configuration status
     */
    public function get_constants_status() {
        $status = array();
        foreach ($this->constant_map as $key => $constant_name) {
            $status[$key] = array(
                'constant_name' => $constant_name,
                'defined' => defined($constant_name),
                'value' => defined($constant_name) ? constant($constant_name) : null,
                'source' => $this->get_config_source($key)
            );
        }
        return $status;
    }
    
    /**
     * Get configuration file info (simplified)
     */
    public function get_config_file_info() {
        if (!file_exists($this->config_file_path)) {
            return null;
        }
        
        return array(
            'path' => $this->config_file_path,
            'size' => filesize($this->config_file_path),
            'last_modified' => filemtime($this->config_file_path),
            'readable' => is_readable($this->config_file_path),
            'writable' => wp_is_writable($this->config_file_path)
        );
    }
} 