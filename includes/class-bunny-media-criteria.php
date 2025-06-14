<?php
/**
 * Class for handling media eligibility criteria.
 * This class centralizes the logic for determining if files meet eligibility criteria
 * for optimization or migration, ensuring consistency across the plugin.
 */
class Bunny_Media_Criteria {
    
    private $settings;
    
    /**
     * Constructor
     */
    public function __construct($settings) {
        $this->settings = $settings;
    }
    
    /**
     * Get maximum file size from settings in bytes
     */
    public function get_max_file_size_bytes() {
        $max_file_size_kb = isset($this->settings->get_all()['max_file_size']) 
            ? (int) $this->settings->get_all()['max_file_size'] 
            : 50; // Default to 50 KB
        
        return $max_file_size_kb * 1024; // Convert KB to bytes
    }
    
    /**
     * Get the 9MB upper limit in bytes
     */
    public function get_nine_mb_bytes() {
        return 9 * 1024 * 1024; // 9MB in bytes
    }
    
    /**
     * Get supported image types for optimization
     */
    public function get_optimization_supported_mime_types() {
        return array(
            'image/jpeg', 
            'image/png', 
            'image/webp', 
            'image/avif', 
            'image/heic', 
            'image/tiff'
        );
    }
    
    /**
     * Get supported mime types for migration
     */
    public function get_migration_supported_mime_types() {
        return array(
            'image/svg+xml',
            'image/webp',
            'image/avif'
        );
    }
    
    /**
     * Get file types array for migration
     */
    public function get_migration_file_types() {
        return array('svg', 'avif', 'webp');
    }
    
    /**
     * Check if a file is eligible for optimization
     * 
     * @param string $file_path Path to the file
     * @param int $file_size Size of the file in bytes (optional, will be calculated if not provided)
     * @param string $mime_type MIME type of the file (optional)
     * @return bool True if eligible, false otherwise
     */
    public function is_eligible_for_optimization($file_path, $file_size = null, $mime_type = null) {
        // Check file existence
        if (!file_exists($file_path)) {
            return false;
        }
        
        // Calculate file size if not provided
        if ($file_size === null) {
            $file_size = filesize($file_path);
        }
        
        // Get mime type if not provided
        if ($mime_type === null) {
            $mime_type = mime_content_type($file_path);
        }
        
        // Check if it's a supported file type
        $supported_types = $this->get_optimization_supported_mime_types();
        if (!in_array($mime_type, $supported_types)) {
            return false;
        }
        
        $max_file_size_bytes = $this->get_max_file_size_bytes();
        $nine_mb_bytes = $this->get_nine_mb_bytes();
        
        // Check if file size is within the allowed range
        // Files should be optimized if they are larger than min threshold and smaller than max threshold
        if ($file_size <= 0 || $file_size <= $max_file_size_bytes || $file_size > $nine_mb_bytes) {
            return false;
        }
        
        // All files meeting the criteria above are eligible
        return true;
    }
    
    /**
     * Check if a file is ready for migration
     * 
     * @param string $file_path Path to the file
     * @param int $file_size Size of the file in bytes (optional, will be calculated if not provided)
     * @param string $mime_type MIME type of the file (optional)
     * @return bool True if ready for migration, false otherwise
     */
    public function is_ready_for_migration($file_path, $file_size = null, $mime_type = null) {
        // Check file existence
        if (!file_exists($file_path)) {
            return false;
        }
        
        // Calculate file size if not provided
        if ($file_size === null) {
            $file_size = filesize($file_path);
        }
        
        // Get mime type if not provided
        if ($mime_type === null) {
            $mime_type = mime_content_type($file_path);
        }
        
        // Check if it's a supported file type for migration
        $supported_types = $this->get_migration_supported_mime_types();
        if (!in_array($mime_type, $supported_types)) {
            return false;
        }
        
        $max_file_size_bytes = $this->get_max_file_size_bytes();
        
        // Check if file size is less than or equal to the max file size limit
        if ($file_size <= 0 || $file_size > $max_file_size_bytes) {
            return false;
        }
        
        // All files meeting the criteria above are ready for migration
        return true;
    }
    
    /**
     * Get SQL for common media filtering (not CDN hosted)
     * 
     * @param string $posts_alias Alias for the posts table
     * @return string SQL WHERE condition
     */
    public function get_sql_not_offloaded_condition($posts_alias = 'p') {
        global $wpdb;
        return "NOT EXISTS (
            SELECT 1 FROM {$wpdb->prefix}bunny_offloaded_files bf 
            WHERE bf.attachment_id = {$posts_alias}.ID AND bf.is_synced = 1
        )";
    }
} 