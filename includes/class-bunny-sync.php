<?php
/**
 * Bunny sync handler
 */
class Bunny_Sync {
    
    private $api;
    private $settings;
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct($api, $settings, $logger) {
        $this->api = $api;
        $this->settings = $settings;
        $this->logger = $logger;
        
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('wp_ajax_bunny_sync_file', array($this, 'ajax_sync_file'));
        add_action('wp_ajax_bunny_bulk_sync', array($this, 'ajax_bulk_sync'));
        add_action('wp_ajax_bunny_verify_sync', array($this, 'ajax_verify_sync'));
    }
    
    /**
     * Sync single file from Bunny.net to local
     */
    public function ajax_sync_file() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
        
        $result = $this->sync_file_to_local($attachment_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => __('File synced successfully.', 'bunny-media-offload')));
    }
    
    /**
     * Bulk sync files from Bunny.net
     */
    public function ajax_bulk_sync() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $attachment_ids = isset($_POST['attachment_ids']) ? array_map('intval', $_POST['attachment_ids']) : array();
        $results = array();
        
        foreach ($attachment_ids as $attachment_id) {
            $result = $this->sync_file_to_local($attachment_id);
            $results[$attachment_id] = is_wp_error($result) ? $result->get_error_message() : 'success';
        }
        
        wp_send_json_success(array('results' => $results));
    }
    
    /**
     * Verify sync status of files
     */
    public function ajax_verify_sync() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $results = $this->verify_all_files();
        
        wp_send_json_success(array(
            'total_files' => $results['total'],
            'synced_files' => $results['synced'],
            'unsynced_files' => $results['unsynced'],
            'missing_local' => $results['missing_local'],
            'missing_remote' => $results['missing_remote']
        ));
    }
    
    /**
     * Sync file from Bunny.net to local storage
     */
    public function sync_file_to_local($attachment_id) {
        $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        
        if (!$bunny_file) {
            return new WP_Error('not_offloaded', __('File is not offloaded to Bunny.net.', 'bunny-media-offload'));
        }
        
        $remote_path = $this->extract_remote_path_from_url($bunny_file->bunny_url);
        $local_path = get_attached_file($attachment_id);
        
        if (!$local_path) {
            return new WP_Error('no_local_path', __('Could not determine local file path.', 'bunny-media-offload'));
        }
        
        // Download file from Bunny.net
        $result = $this->api->download_file($remote_path, $local_path);
        
        if (is_wp_error($result)) {
            $this->logger->error('File sync failed', array(
                'attachment_id' => $attachment_id,
                'remote_path' => $remote_path,
                'local_path' => $local_path,
                'error' => $result->get_error_message()
            ));
            return $result;
        }
        
        // Regenerate thumbnails if this is an image
        if (wp_attachment_is_image($attachment_id)) {
            wp_generate_attachment_metadata($attachment_id, $local_path);
        }
        
        $this->logger->info('File synced to local', array(
            'attachment_id' => $attachment_id,
            'local_path' => $local_path
        ));
        
        return true;
    }
    
    /**
     * Sync all offloaded files back to local
     */
    public function sync_all_files() {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Querying plugin-specific table for sync operation, no caching needed for bulk sync
        $offloaded_files = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bunny_offloaded_files WHERE is_synced = 1");
        
        $results = array(
            'total' => count($offloaded_files),
            'successful' => 0,
            'failed' => 0,
            'errors' => array()
        );
        
        foreach ($offloaded_files as $file) {
            $result = $this->sync_file_to_local($file->attachment_id);
            
            if (is_wp_error($result)) {
                $results['failed']++;
                $results['errors'][$file->attachment_id] = $result->get_error_message();
            } else {
                $results['successful']++;
            }
        }
        
        return $results;
    }
    
    /**
     * Verify integrity of all files
     */
    public function verify_all_files() {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Querying plugin-specific table for file verification, no caching needed for verification process
        $offloaded_files = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bunny_offloaded_files WHERE is_synced = 1");
        
        $results = array(
            'total' => count($offloaded_files),
            'synced' => 0,
            'unsynced' => 0,
            'missing_local' => 0,
            'missing_remote' => 0,
            'details' => array()
        );
        
        foreach ($offloaded_files as $file) {
            $local_path = get_attached_file($file->attachment_id);
            $local_exists = $local_path && file_exists($local_path);
            
            // Check if remote file exists by trying to get its public URL
            $remote_path = $this->extract_remote_path_from_url($file->bunny_url);
            $remote_exists = $this->check_remote_file_exists($remote_path);
            
            if ($local_exists && $remote_exists) {
                $results['synced']++;
                $status = 'synced';
            } elseif (!$local_exists && $remote_exists) {
                $results['missing_local']++;
                $status = 'missing_local';
            } elseif ($local_exists && !$remote_exists) {
                $results['missing_remote']++;
                $status = 'missing_remote';
            } else {
                $results['unsynced']++;
                $status = 'unsynced';
            }
            
            $results['details'][$file->attachment_id] = array(
                'status' => $status,
                'local_exists' => $local_exists,
                'remote_exists' => $remote_exists,
                'bunny_url' => $file->bunny_url,
                'local_path' => $local_path
            );
        }
        
        return $results;
    }
    
    /**
     * Check if remote file exists
     */
    private function check_remote_file_exists($remote_path) {
        $public_url = $this->api->get_public_url($remote_path);
        
        $response = wp_remote_head($public_url, array('timeout' => 10));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        return $response_code === 200;
    }
    
    /**
     * Remove file from local storage only (keep on Bunny.net)
     */
    public function remove_local_file($attachment_id) {
        $local_path = get_attached_file($attachment_id);
        
        if (!$local_path || !file_exists($local_path)) {
            return new WP_Error('file_not_found', __('Local file not found.', 'bunny-media-offload'));
        }
        
        // Get metadata before deletion
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        // Delete main file
        if (!wp_delete_file($local_path)) {
            return new WP_Error('delete_failed', __('Could not delete local file.', 'bunny-media-offload'));
        }
        
        // Delete thumbnails
        if (!empty($metadata['sizes'])) {
            $upload_dir = wp_upload_dir();
            $base_dir = dirname($local_path);
            
            foreach ($metadata['sizes'] as $size_data) {
                if (!empty($size_data['file'])) {
                    $thumb_path = $base_dir . '/' . $size_data['file'];
                    if (file_exists($thumb_path)) {
                        wp_delete_file($thumb_path);
                    }
                }
            }
        }
        
        $this->logger->info('Local file removed', array(
            'attachment_id' => $attachment_id,
            'local_path' => $local_path
        ));
        
        return true;
    }
    
    /**
     * Cleanup orphaned files (files in Bunny.net but not in WordPress)
     */
    public function cleanup_orphaned_files() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bunny_offloaded_files';
        
        // Find records where the attachment no longer exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Querying plugin-specific table for orphaned file cleanup, no caching needed for cleanup operations
        $orphaned_files = $wpdb->get_results("
            SELECT bf.* FROM {$wpdb->prefix}bunny_offloaded_files bf
            LEFT JOIN {$wpdb->posts} p ON bf.attachment_id = p.ID
            WHERE p.ID IS NULL
        ");
        
        $results = array(
            'total' => count($orphaned_files),
            'deleted' => 0,
            'failed' => 0,
            'errors' => array()
        );
        
        foreach ($orphaned_files as $file) {
            $remote_path = $this->extract_remote_path_from_url($file->bunny_url);
            $delete_result = $this->api->delete_file($remote_path);
            
            if (is_wp_error($delete_result)) {
                $results['failed']++;
                $results['errors'][$file->attachment_id] = $delete_result->get_error_message();
            } else {
                $results['deleted']++;
                
                // Remove from tracking table
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct delete needed for orphaned file cleanup
                $wpdb->delete($wpdb->prefix . 'bunny_offloaded_files', array('id' => $file->id));
            }
        }
        
        return $results;
    }
    
    /**
     * Get files that are only on Bunny.net (no local copy)
     */
    public function get_remote_only_files() {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Querying plugin-specific table for remote-only file identification
        $files = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bunny_offloaded_files WHERE is_synced = 1");
        
        $remote_only = array();
        
        foreach ($files as $file) {
            $local_path = get_attached_file($file->attachment_id);
            
            if (!$local_path || !file_exists($local_path)) {
                $remote_only[] = $file;
            }
        }
        
        return $remote_only;
    }
    
    /**
     * Get Bunny file by attachment ID
     */
    private function get_bunny_file_by_attachment($attachment_id) {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Querying plugin-specific table for file lookup
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bunny_offloaded_files WHERE attachment_id = %d",
            $attachment_id
        ));
    }
    
    /**
     * Extract remote path from Bunny URL
     */
    private function extract_remote_path_from_url($bunny_url) {
        $custom_hostname = $this->settings->get('custom_hostname');
        
        if (empty($custom_hostname)) {
            $this->logger->error('Custom hostname not configured');
            return '';
        }
        
        $base_url = 'https://' . $custom_hostname . '/';
        $remote_path = str_replace($base_url, '', $bunny_url);
        
        // Remove version parameter if present
        $remote_path = preg_replace('/\?v=\d+$/', '', $remote_path);
        
        return $remote_path;
    }
    
    /**
     * Schedule automatic sync verification
     */
    public function schedule_sync_verification() {
        if (!wp_next_scheduled('bunny_verify_sync')) {
            wp_schedule_event(time(), 'daily', 'bunny_verify_sync');
        }
    }
    
    /**
     * Unschedule automatic sync verification
     */
    public function unschedule_sync_verification() {
        wp_clear_scheduled_hook('bunny_verify_sync');
    }
} 