<?php
/**
 * Bunny settings manager
 */
class Bunny_Settings {
    
    private $config_file_path;
    private $settings;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Set the path for the JSON configuration file
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
        $wp_config_keys = array('api_key', 'storage_zone', 'custom_hostname');
        foreach ($wp_config_keys as $key) {
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
            'auto_offload' => true,
            'delete_local' => true,
            'file_versioning' => true,
            'allowed_file_types' => array('webp', 'avif'),
            'allowed_post_types' => array('attachment', 'product'),
            'batch_size' => 100,
            'enable_logs' => true,
            'log_level' => 'info',
            'optimize_on_upload' => false,
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
        
        // Only validate wp-config constants if they're being set (which they shouldn't be)
        if (isset($settings['api_key']) && !$this->is_constant_defined('api_key')) {
            if (empty($settings['api_key'])) {
                $errors['api_key'] = __('API key should be defined in wp-config.php as BUNNY_API_KEY.', 'bunny-media-offload');
            }
        }
        
        if (isset($settings['storage_zone']) && !$this->is_constant_defined('storage_zone')) {
            if (empty($settings['storage_zone'])) {
                $errors['storage_zone'] = __('Storage zone should be defined in wp-config.php as BUNNY_STORAGE_ZONE.', 'bunny-media-offload');
            }
        }
        
        // Validate custom hostname - now required
        if (!$this->is_constant_defined('custom_hostname')) {
            $errors['custom_hostname'] = __('Custom hostname is required and must be defined in wp-config.php as BUNNY_CUSTOM_HOSTNAME.', 'bunny-media-offload');
        } elseif (isset($settings['custom_hostname'])) {
            if (!empty($settings['custom_hostname'])) {
                if (!filter_var('https://' . $settings['custom_hostname'], FILTER_VALIDATE_URL)) {
                    $errors['custom_hostname'] = __('Custom hostname must be a valid hostname format.', 'bunny-media-offload');
                }
            }
        }
        
        // Validate boolean settings
        $boolean_settings = array('auto_offload', 'delete_local', 'file_versioning', 'enable_logs', 'optimization_enabled', 'optimize_on_upload');
        foreach ($boolean_settings as $setting) {
            if (isset($settings[$setting])) {
                $validated[$setting] = !empty($settings[$setting]);
            }
        }
        
        // Validate file types
        if (isset($settings['allowed_file_types'])) {
            if (!empty($settings['allowed_file_types']) && is_array($settings['allowed_file_types'])) {
                $validated['allowed_file_types'] = array_map('sanitize_text_field', $settings['allowed_file_types']);
            } else {
                $validated['allowed_file_types'] = $this->get_default_settings()['allowed_file_types'];
            }
        }
        
        // Validate post types
        if (isset($settings['allowed_post_types'])) {
            if (!empty($settings['allowed_post_types']) && is_array($settings['allowed_post_types'])) {
                $validated['allowed_post_types'] = array_map('sanitize_text_field', $settings['allowed_post_types']);
            } else {
                $validated['allowed_post_types'] = $this->get_default_settings()['allowed_post_types'];
            }
        }
        
        // Validate batch size
        if (isset($settings['batch_size'])) {
            $valid_batch_sizes = array(50, 100, 150, 250);
            $batch_size = intval($settings['batch_size']);
            if (!in_array($batch_size, $valid_batch_sizes)) {
                $validated['batch_size'] = 100; // Default
            } else {
                $validated['batch_size'] = $batch_size;
            }
        }
        
        // Validate log level
        if (isset($settings['log_level'])) {
            $valid_log_levels = array('error', 'warning', 'info', 'debug');
            if (!in_array($settings['log_level'], $valid_log_levels)) {
                $validated['log_level'] = 'info';
            } else {
                $validated['log_level'] = $settings['log_level'];
            }
        }
        
        // Validate optimization format
        if (isset($settings['optimization_format'])) {
            $valid_formats = array('avif', 'webp');
            if (!in_array($settings['optimization_format'], $valid_formats)) {
                $validated['optimization_format'] = 'avif';
            } else {
                $validated['optimization_format'] = $settings['optimization_format'];
            }
        }
        
        // Validate optimization max size
        if (isset($settings['optimization_max_size'])) {
            $valid_sizes = array('40kb', '45kb', '50kb', '55kb', '60kb');
            if (!in_array($settings['optimization_max_size'], $valid_sizes)) {
                $validated['optimization_max_size'] = '50kb';
            } else {
                $validated['optimization_max_size'] = $settings['optimization_max_size'];
            }
        }
        
        // Validate optimization batch size
        if (isset($settings['optimization_batch_size'])) {
            $valid_optimization_batch_sizes = array(30, 60, 90, 150);
            $optimization_batch_size = intval($settings['optimization_batch_size']);
            if (!in_array($optimization_batch_size, $valid_optimization_batch_sizes)) {
                $validated['optimization_batch_size'] = 60; // Default
            } else {
                $validated['optimization_batch_size'] = $optimization_batch_size;
            }
        }
        
        // Validate migration concurrent limit
        if (isset($settings['migration_concurrent_limit'])) {
            $valid_migration_concurrent = array(2, 4, 8);
            $migration_concurrent = intval($settings['migration_concurrent_limit']);
            if (!in_array($migration_concurrent, $valid_migration_concurrent)) {
                $validated['migration_concurrent_limit'] = 4; // Default
            } else {
                $validated['migration_concurrent_limit'] = $migration_concurrent;
            }
        }
        
        // Validate optimization concurrent limit
        if (isset($settings['optimization_concurrent_limit'])) {
            $valid_optimization_concurrent = array(2, 3, 5);
            $optimization_concurrent = intval($settings['optimization_concurrent_limit']);
            if (!in_array($optimization_concurrent, $valid_optimization_concurrent)) {
                $validated['optimization_concurrent_limit'] = 3; // Default
            } else {
                $validated['optimization_concurrent_limit'] = $optimization_concurrent;
            }
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
        if ($this->is_constant_defined($key)) {
            return 'wp-config.php';
        }
        return 'JSON file';
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
    
    /**
     * Get the path to the configuration file
     */
    public function get_config_file_path() {
        return $this->config_file_path;
    }
    
    /**
     * Check if the configuration file exists
     */
    public function config_file_exists() {
        return file_exists($this->config_file_path);
    }
    
    /**
     * Get configuration file info
     */
    public function get_config_file_info() {
        if (!$this->config_file_exists()) {
            return null;
        }
        
        global $wp_filesystem;
        
        // Initialize WP_Filesystem if not already initialized
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        // Use WP_Filesystem method for checking writability
        $is_writable = false;
        if ($wp_filesystem && $wp_filesystem->exists($this->config_file_path)) {
            $is_writable = $wp_filesystem->is_writable($this->config_file_path);
        } elseif ($wp_filesystem && $wp_filesystem->exists(dirname($this->config_file_path))) {
            // If file doesn't exist, check if parent directory is writable
            $is_writable = $wp_filesystem->is_writable(dirname($this->config_file_path));
        }
        
        return array(
            'path' => $this->config_file_path,
            'size' => filesize($this->config_file_path),
            'last_modified' => filemtime($this->config_file_path),
            'readable' => is_readable($this->config_file_path),
            'writable' => $is_writable
        );
    }
} 