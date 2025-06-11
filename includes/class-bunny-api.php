<?php
/**
 * Bunny.net API handler
 */
class Bunny_API {
    
    private $settings;
    private $logger;
    private $base_url = 'https://storage.bunnycdn.com';
    
    /**
     * Constructor
     */
    public function __construct($settings, $logger) {
        $this->settings = $settings;
        $this->logger = $logger;
    }
    
    /**
     * Test connection to Bunny.net
     */
    public function test_connection() {
        $api_key = $this->settings->get('api_key');
        $storage_zone = $this->settings->get('storage_zone');
        
        if (empty($api_key) || empty($storage_zone)) {
            return new WP_Error('missing_credentials', __('API key and storage zone are required.', 'bunny-media-offload'));
        }
        
        $url = $this->base_url . '/' . $storage_zone . '/';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'AccessKey' => $api_key,
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $this->logger->error('Connection test failed', array('error' => $response->get_error_message()));
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            $this->logger->info('Connection test successful');
            return true;
        }
        
        $this->logger->error('Connection test failed', array('response_code' => $response_code));
        // translators: %d is the HTTP response code
        return new WP_Error('connection_failed', sprintf(__('Connection failed with response code: %d', 'bunny-media-offload'), $response_code));
    }
    
    /**
     * Upload file to Bunny.net
     */
    public function upload_file($file_path, $remote_path) {
        error_log('[Bunny API Debug] Starting upload_file: ' . $file_path . ' to ' . $remote_path);
        
        $api_key = $this->settings->get('api_key');
        $storage_zone = $this->settings->get('storage_zone');
        
        error_log('[Bunny API Debug] Credentials: Storage Zone=' . $storage_zone . ', API Key=' . substr($api_key, 0, 3) . '...[redacted]');
        
        if (empty($api_key) || empty($storage_zone)) {
            error_log('[Bunny API Debug] Missing credentials');
            return array('success' => false, 'message' => __('API key and storage zone are required.', 'bunny-media-offload'));
        }
        
        if (!file_exists($file_path)) {
            error_log('[Bunny API Debug] File not found: ' . $file_path);
            return array('success' => false, 'message' => __('Local file not found.', 'bunny-media-offload'));
        }
        
        error_log('[Bunny API Debug] File exists, getting contents');
        $file_content = file_get_contents($file_path);
        if ($file_content === false) {
            error_log('[Bunny API Debug] Failed to read file: ' . $file_path);
            return array('success' => false, 'message' => __('Could not read local file.', 'bunny-media-offload'));
        }
        
        $url = $this->base_url . '/' . $storage_zone . '/' . ltrim($remote_path, '/');
        error_log('[Bunny API Debug] Upload URL: ' . $url);
        error_log('[Bunny API Debug] File size: ' . strlen($file_content) . ' bytes');
        
        try {
            error_log('[Bunny API Debug] Making wp_remote_request');
            $response = wp_remote_request($url, array(
                'method' => 'PUT',
                'headers' => array(
                    'AccessKey' => $api_key,
                    'Content-Type' => 'application/octet-stream',
                ),
                'body' => $file_content,
                'timeout' => 120
            ));
            error_log('[Bunny API Debug] wp_remote_request completed');
        } catch (Exception $e) {
            error_log('[Bunny API Debug] Exception in wp_remote_request: ' . $e->getMessage());
            return array('success' => false, 'message' => 'Request exception: ' . $e->getMessage());
        }
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('[Bunny API Debug] wp_error in response: ' . $error_message);
            $this->logger->error('File upload failed', array(
                'file' => $remote_path,
                'error' => $error_message
            ));
            return array('success' => false, 'message' => $error_message);
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        error_log('[Bunny API Debug] Response code: ' . $response_code);
        error_log('[Bunny API Debug] Response body: ' . $response_body);
        
        if ($response_code === 201) {
            $this->logger->info('File uploaded successfully', array('file' => $remote_path));
            $public_url = $this->get_public_url($remote_path);
            error_log('[Bunny API Debug] Upload successful, public URL: ' . print_r($public_url, true));
            return array('success' => true, 'url' => is_wp_error($public_url) ? '' : $public_url);
        }
        
        $error_message = sprintf(__('Upload failed with response code: %d', 'bunny-media-offload'), $response_code);
        error_log('[Bunny API Debug] Upload failed with response code: ' . $response_code);
        
        $this->logger->error('File upload failed', array(
            'file' => $remote_path,
            'response_code' => $response_code,
            'response_body' => $response_body
        ));
        
        // translators: %d is the HTTP response code
        return array('success' => false, 'message' => $error_message);
    }
    
    /**
     * Delete file from Bunny.net
     */
    public function delete_file($remote_path) {
        $api_key = $this->settings->get('api_key');
        $storage_zone = $this->settings->get('storage_zone');
        
        if (empty($api_key) || empty($storage_zone)) {
            return new WP_Error('missing_credentials', __('API key and storage zone are required.', 'bunny-media-offload'));
        }
        
        $url = $this->base_url . '/' . $storage_zone . '/' . ltrim($remote_path, '/');
        
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array(
                'AccessKey' => $api_key,
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $this->logger->error('File deletion failed', array(
                'file' => $remote_path,
                'error' => $response->get_error_message()
            ));
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200 || $response_code === 404) {
            $this->logger->info('File deleted successfully', array('file' => $remote_path));
            return true;
        }
        
        $this->logger->error('File deletion failed', array(
            'file' => $remote_path,
            'response_code' => $response_code
        ));
        
        // translators: %d is the HTTP response code
        return new WP_Error('delete_failed', sprintf(__('Deletion failed with response code: %d', 'bunny-media-offload'), $response_code));
    }
    
    /**
     * Download file from Bunny.net
     */
    public function download_file($remote_path, $local_path) {
        $public_url = $this->get_public_url($remote_path);
        
        $response = wp_remote_get($public_url, array(
            'timeout' => 120
        ));
        
        if (is_wp_error($response)) {
            $this->logger->error('File download failed', array(
                'file' => $remote_path,
                'error' => $response->get_error_message()
            ));
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            $this->logger->error('File download failed', array(
                'file' => $remote_path,
                'response_code' => $response_code
            ));
            // translators: %d is the HTTP response code
            return new WP_Error('download_failed', sprintf(__('Download failed with response code: %d', 'bunny-media-offload'), $response_code));
        }
        
        $file_content = wp_remote_retrieve_body($response);
        
        if (empty($file_content)) {
            return new WP_Error('empty_file', __('Downloaded file is empty.', 'bunny-media-offload'));
        }
        
        // Create directory if it doesn't exist
        $dir = dirname($local_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        
        $result = file_put_contents($local_path, $file_content);
        
        if ($result === false) {
            return new WP_Error('file_write_error', __('Could not write to local file.', 'bunny-media-offload'));
        }
        
        $this->logger->info('File downloaded successfully', array('file' => $remote_path));
        return true;
    }
    
    /**
     * Get public URL for a file
     */
    public function get_public_url($remote_path) {
        error_log('[Bunny API Debug] Getting public URL for: ' . $remote_path);
        
        $custom_hostname = $this->settings->get('custom_hostname');
        error_log('[Bunny API Debug] Custom hostname: ' . $custom_hostname);
        
        if (empty($custom_hostname)) {
            error_log('[Bunny API Debug] Missing custom hostname');
            return new WP_Error('missing_hostname', __('Custom hostname is required.', 'bunny-media-offload'));
        }
        
        $url = 'https://' . $custom_hostname . '/' . ltrim($remote_path, '/');
        error_log('[Bunny API Debug] Generated public URL: ' . $url);
        return $url;
    }
    
    /**
     * List files in storage zone
     */
    public function list_files($path = '') {
        $api_key = $this->settings->get('api_key');
        $storage_zone = $this->settings->get('storage_zone');
        
        if (empty($api_key) || empty($storage_zone)) {
            return new WP_Error('missing_credentials', __('API key and storage zone are required.', 'bunny-media-offload'));
        }
        
        $url = $this->base_url . '/' . $storage_zone . '/' . ltrim($path, '/');
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'AccessKey' => $api_key,
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            // translators: %d is the HTTP response code
            return new WP_Error('list_failed', sprintf(__('List failed with response code: %d', 'bunny-media-offload'), $response_code));
        }
        
        $body = wp_remote_retrieve_body($response);
        $files = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Invalid JSON response.', 'bunny-media-offload'));
        }
        
        return $files;
    }
    
    /**
     * Get storage statistics
     */
    public function get_storage_stats() {
        // Try to get cached stats first
        $stats_cache_key = 'bunny_storage_stats';
        $cached_stats = wp_cache_get($stats_cache_key, 'bunny_media_offload');
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        // This would require the Bunny.net management API
        // For now, we'll calculate based on our local tracking
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bunny_offloaded_files';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching implemented above
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_files,
                SUM(file_size) as total_size
            FROM {$wpdb->prefix}bunny_offloaded_files 
            WHERE is_synced = 1
        ");
        
        $result = array(
            'total_files' => (int) $stats->total_files,
            'total_size' => (int) $stats->total_size
        );
        
        // Cache for 5 minutes
        wp_cache_set($stats_cache_key, $result, 'bunny_media_offload', 5 * MINUTE_IN_SECONDS);
        
        return $result;
    }
    
    /**
     * Purge CDN cache for a file
     */
    public function purge_cache($url) {
        // This would require additional Bunny.net API implementation
        // For now, we'll just add a version parameter to the URL
        return true;
    }
} 