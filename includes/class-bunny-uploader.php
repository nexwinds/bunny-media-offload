<?php
/**
 * Bunny media uploader - Simplified and optimized
 */
class Bunny_Uploader {
    
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
     * Initialize WordPress hooks - Simplified approach
     */
    private function init_hooks() {
        // Hook into attachment deletion
        add_action('delete_attachment', array($this, 'handle_delete'));
        
        // Core URL filtering - unified approach
        add_filter('wp_get_attachment_url', array($this, 'filter_url'), 10, 2);
        add_filter('wp_get_attachment_image_src', array($this, 'filter_image_src'), 10, 4);
        add_filter('wp_get_attachment_image_url', array($this, 'filter_url'), 10, 2);
        add_filter('wp_calculate_image_srcset', array($this, 'filter_srcset'), 10, 5);
        
        // Media library and admin
        add_filter('wp_prepare_attachment_for_js', array($this, 'filter_attachment_js'), 10, 3);
        
        // Content filtering as fallback
        add_filter('the_content', array($this, 'filter_content'), 999);
    }
    
    /**
     * Handle attachment deletion
     */
    public function handle_delete($attachment_id) {
        $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        
        if (!$bunny_file) {
            return;
        }
        
        // Delete from Bunny.net
        $remote_path = $this->extract_remote_path_from_url($bunny_file->bunny_url);
        $result = $this->api->delete_file($remote_path);
        
        if (is_wp_error($result)) {
            $this->logger->error('Failed to delete file from Bunny.net', array(
                'attachment_id' => $attachment_id,
                'error' => $result->get_error_message()
            ));
        }
        
        // Remove from tracking table
        $this->remove_offloaded_file($attachment_id);
    }
    
    /**
     * Unified URL filtering for all attachment URLs
     */
    public function filter_url($url, $attachment_id) {
        $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        
        if ($bunny_file && $bunny_file->is_synced) {
            return $this->add_version_to_url($bunny_file->bunny_url);
        }
        
        return $url;
    }
    
    /**
     * Filter image src array for different sizes
     */
    public function filter_image_src($image, $attachment_id, $size, $icon) {
        if (!is_array($image) || empty($image[0]) || !$attachment_id) {
            return $image;
        }
        
        $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        
        if ($bunny_file && $bunny_file->is_synced) {
            $image[0] = $this->get_bunny_url_for_size($bunny_file->bunny_url, $size, $attachment_id);
        }
        
        return $image;
    }
    
    /**
     * Filter srcset to use CDN URLs
     */
    public function filter_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (!$sources || !$attachment_id || !is_array($sources)) {
            return $sources;
        }
        
        $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        
        if (!$bunny_file || !$bunny_file->is_synced) {
            return $sources;
        }
        
        foreach ($sources as $width => $source) {
            if (isset($source['url'])) {
                $size_name = $this->get_size_name_from_width($width, $image_meta);
                $sources[$width]['url'] = $this->get_bunny_url_for_size($bunny_file->bunny_url, $size_name, $attachment_id);
            }
        }
        
        return $sources;
    }
    
    /**
     * Filter attachment data for Media Library JavaScript
     */
    public function filter_attachment_js($response, $attachment, $meta) {
        if (!isset($response['id'])) {
            return $response;
        }
        
        $bunny_file = $this->get_bunny_file_by_attachment($response['id']);
        
        if ($bunny_file && $bunny_file->is_synced) {
            $response['url'] = $this->add_version_to_url($bunny_file->bunny_url);
            
            if (isset($response['sizes']) && is_array($response['sizes'])) {
                foreach ($response['sizes'] as $size => $size_data) {
                    $response['sizes'][$size]['url'] = $this->get_bunny_url_for_size($bunny_file->bunny_url, $size, $response['id']);
                }
            }
        }
        
        return $response;
    }
    
    /**
     * Filter content URLs as fallback
     */
    public function filter_content($content) {
        if (empty($content)) {
            return $content;
        }
        
        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];
        
        if (strpos($content, $upload_url) === false) {
            return $content;
        }
        
        $pattern = '/' . preg_quote($upload_url, '/') . '\/([^"\'>\s]+\.(jpg|jpeg|png|gif|webp|avif|svg))/i';
        
        return preg_replace_callback($pattern, function($matches) {
            $full_url = $matches[0];
            $attachment_id = $this->get_attachment_id_by_url($full_url);
            
            if ($attachment_id) {
                $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
                if ($bunny_file && $bunny_file->is_synced) {
                    $size = $this->extract_size_from_url($full_url, $attachment_id);
                    return $this->get_bunny_url_for_size($bunny_file->bunny_url, $size, $attachment_id);
                }
            }
            
            return $full_url;
        }, $content);
    }
    
    /**
     * Upload file manually
     */
    public function upload_file_manually($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return new WP_Error('file_not_found', __('File not found.', 'bunny-media-offload'));
        }
        
        // Check if already uploaded
        $existing_bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        if ($existing_bunny_file) {
            return new WP_Error('already_uploaded', __('File already uploaded to Bunny.net.', 'bunny-media-offload'));
        }
        
        // Upload main file
        $remote_path = $this->generate_remote_path($file_path);
        $bunny_url = $this->api->upload_file($file_path, $remote_path);
        
        if (is_wp_error($bunny_url)) {
            return $bunny_url;
        }
        
        // Record in database
        $this->record_offloaded_file($attachment_id, $bunny_url, $remote_path, $file_path);
        
        // Upload thumbnails
        $metadata = wp_get_attachment_metadata($attachment_id);
        if ($metadata && wp_attachment_is_image($attachment_id)) {
            $this->upload_thumbnails($metadata, $attachment_id, $file_path);
        }
        
        // Delete local files if enabled
        if ($this->settings->get('delete_local')) {
            $this->delete_local_file($file_path, $attachment_id, $metadata);
        }
        
        return $bunny_url;
    }
    
    /**
     * Upload thumbnails to CDN
     */
    private function upload_thumbnails($metadata, $attachment_id, $main_file_path) {
        if (empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
            return;
        }
        
        $main_file_dir = dirname($main_file_path);
        
        foreach ($metadata['sizes'] as $size_name => $size_data) {
            if (empty($size_data['file'])) {
                continue;
            }
            
            $thumbnail_path = $main_file_dir . '/' . $size_data['file'];
            
            // Generate thumbnail if missing
            if (!file_exists($thumbnail_path)) {
                $this->generate_thumbnail($main_file_path, $thumbnail_path, $size_data);
            }
            
            if (file_exists($thumbnail_path)) {
                $thumbnail_remote_path = $this->generate_remote_path($thumbnail_path);
                $result = $this->api->upload_file($thumbnail_path, $thumbnail_remote_path);
                
                if (!is_wp_error($result)) {
                    update_post_meta($attachment_id, '_bunny_thumbnail_' . $size_name, $result);
                }
            }
        }
    }
    
    /**
     * Generate missing thumbnail
     */
    private function generate_thumbnail($main_file_path, $thumbnail_path, $size_data) {
        $image_editor = wp_get_image_editor($main_file_path);
        if (is_wp_error($image_editor)) {
            return false;
        }
        
        $resized = $image_editor->resize($size_data['width'], $size_data['height'], true);
        if (is_wp_error($resized)) {
            return false;
        }
        
        $saved = $image_editor->save($thumbnail_path);
        return !is_wp_error($saved);
    }
    
    /**
     * Get Bunny URL for specific size
     */
    private function get_bunny_url_for_size($base_bunny_url, $size, $attachment_id) {
        if ($size === 'full' || empty($size)) {
            return $this->add_version_to_url($base_bunny_url);
        }
        
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta || empty($meta['sizes'])) {
            return $this->add_version_to_url($base_bunny_url);
        }
        
        // Handle named sizes
        if (is_string($size) && isset($meta['sizes'][$size]['file'])) {
            $sized_url = $this->build_sized_bunny_url($base_bunny_url, $meta['sizes'][$size]['file']);
            return $this->add_version_to_url($sized_url);
        }
        
        // Handle array sizes - find best match
        if (is_array($size) && count($size) >= 2) {
            $target_width = (int) $size[0];
            $best_match = null;
            $best_diff = PHP_INT_MAX;
            
            foreach ($meta['sizes'] as $size_data) {
                if (!isset($size_data['width']) || !isset($size_data['file'])) {
                    continue;
                }
                
                $diff = abs($size_data['width'] - $target_width);
                if ($diff < $best_diff) {
                    $best_diff = $diff;
                    $best_match = $size_data;
                }
            }
            
            if ($best_match) {
                $sized_url = $this->build_sized_bunny_url($base_bunny_url, $best_match['file']);
                return $this->add_version_to_url($sized_url);
            }
        }
        
        return $this->add_version_to_url($base_bunny_url);
    }
    
    /**
     * Build sized URL from base URL and filename
     */
    private function build_sized_bunny_url($base_bunny_url, $sized_filename) {
        $url_parts = wp_parse_url($base_bunny_url);
        $path_info = pathinfo($url_parts['path']);
        $new_path = $path_info['dirname'] . '/' . $sized_filename;
        
        return $url_parts['scheme'] . '://' . $url_parts['host'] . $new_path;
    }
    
    /**
     * Get size name from width for srcset
     */
    private function get_size_name_from_width($width, $image_meta) {
        if (!$image_meta || empty($image_meta['sizes'])) {
            return 'full';
        }
        
        foreach ($image_meta['sizes'] as $size_name => $size_data) {
            if (isset($size_data['width']) && (int) $size_data['width'] == (int) $width) {
                return $size_name;
            }
        }
        
        return 'full';
    }
    
    /**
     * Extract size from URL
     */
    private function extract_size_from_url($url, $attachment_id) {
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta || empty($meta['sizes'])) {
            return 'full';
        }
        
        $filename = basename(wp_parse_url($url, PHP_URL_PATH));
        
        foreach ($meta['sizes'] as $size_name => $size_data) {
            if (isset($size_data['file']) && $size_data['file'] === $filename) {
                return $size_name;
            }
        }
        
        return 'full';
    }
    
    /**
     * Generate remote path for file
     */
    private function generate_remote_path($file_path) {
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'], '', $file_path);
        return ltrim(str_replace('\\', '/', $relative_path), '/');
    }
    
    /**
     * Add version to URL for cache busting
     */
    private function add_version_to_url($url) {
        if (!$this->settings->get('file_versioning')) {
            return $url;
        }
        
        $version = substr(md5(time()), 0, 3);
        $separator = (strpos($url, '?') !== false) ? '&' : '?';
        return $url . $separator . 'v=' . $version;
    }
    
    /**
     * Record offloaded file in database
     */
    private function record_offloaded_file($attachment_id, $bunny_url, $remote_path, $file_path) {
        global $wpdb;
        
        $file_size = file_exists($file_path) ? filesize($file_path) : 0;
        $file_type = get_post_mime_type($attachment_id);
        
        $table_name = $wpdb->prefix . 'bunny_offloaded_files';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert($table_name, array(
            'attachment_id' => $attachment_id,
            'bunny_url' => $bunny_url,
            'file_size' => $file_size,
            'file_type' => $file_type,
            'date_offloaded' => current_time('mysql'),
            'is_synced' => 1
        ));
        
        update_post_meta($attachment_id, '_bunny_url', $bunny_url);
        $this->update_stats($file_size, 1);
    }
    
    /**
     * Remove offloaded file from database
     */
    private function remove_offloaded_file($attachment_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bunny_offloaded_files';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $file_info = $wpdb->get_row($wpdb->prepare(
            "SELECT file_size FROM {$table_name} WHERE attachment_id = %d",
            $attachment_id
        ));
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete($table_name, array('attachment_id' => $attachment_id));
        
        if ($file_info) {
            $this->update_stats(-$file_info->file_size, -1);
        }
        
        delete_post_meta($attachment_id, '_bunny_url');
    }
    
    /**
     * Get Bunny file by attachment ID
     */
    private function get_bunny_file_by_attachment($attachment_id) {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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
        return preg_replace('/\?v=\w+$/', '', $remote_path);
    }
    
    /**
     * Delete local file and thumbnails
     */
    private function delete_local_file($file_path, $attachment_id, $metadata) {
        if (file_exists($file_path)) {
            wp_delete_file($file_path);
        }
        
        if (!empty($metadata['sizes'])) {
            $base_dir = dirname($file_path);
            foreach ($metadata['sizes'] as $size_data) {
                if (!empty($size_data['file'])) {
                    $thumb_path = $base_dir . '/' . $size_data['file'];
                    if (file_exists($thumb_path)) {
                        wp_delete_file($thumb_path);
                    }
                }
            }
        }
    }
    
    /**
     * Update plugin statistics
     */
    private function update_stats($size_change, $file_count_change) {
        $stats = get_option('bunny_media_offload_stats', array());
        
        $stats['total_files_offloaded'] = max(0, (int)($stats['total_files_offloaded'] ?? 0) + $file_count_change);
        $stats['total_space_saved'] = max(0, (int)($stats['total_space_saved'] ?? 0) + $size_change);
        $stats['total_bunny_storage'] = max(0, (int)($stats['total_bunny_storage'] ?? 0) + $size_change);
        $stats['last_sync'] = time();
        
        update_option('bunny_media_offload_stats', $stats);
    }
    
    /**
     * Get attachment ID by URL
     */
    private function get_attachment_id_by_url($url) {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE guid = %s AND post_type = 'attachment'",
            $url
        ));
        
        if (!$attachment_id) {
            $filename = basename($url);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
                '%' . $filename
            ));
        }
        
        return $attachment_id ? (int) $attachment_id : null;
    }
    
    /**
     * Regenerate missing thumbnails for migrated images
     */
    public function regenerate_missing_thumbnails($attachment_id = null) {
        global $wpdb;
        
        if ($attachment_id) {
            $attachment_ids = array($attachment_id);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $attachment_ids = $wpdb->get_col(
                "SELECT DISTINCT attachment_id FROM {$wpdb->prefix}bunny_offloaded_files WHERE is_synced = 1"
            );
        }
        
        $processed = 0;
        $errors = 0;
        
        foreach ($attachment_ids as $id) {
            if (!wp_attachment_is_image($id)) {
                continue;
            }
            
            $file_path = get_attached_file($id);
            $metadata = wp_get_attachment_metadata($id);
            
            if (!$file_path || !file_exists($file_path) || !$metadata || empty($metadata['sizes'])) {
                continue;
            }
            
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                $cached_url = get_post_meta($id, '_bunny_thumbnail_' . $size_name, true);
                
                if (!empty($cached_url)) {
                    continue;
                }
                
                $main_file_dir = dirname($file_path);
                $thumbnail_path = $main_file_dir . '/' . $size_data['file'];
                
                if (!file_exists($thumbnail_path)) {
                    if (!$this->generate_thumbnail($file_path, $thumbnail_path, $size_data)) {
                        $errors++;
                        continue;
                    }
                }
                
                $thumbnail_remote_path = $this->generate_remote_path($thumbnail_path);
                $result = $this->api->upload_file($thumbnail_path, $thumbnail_remote_path);
                
                if (!is_wp_error($result)) {
                    update_post_meta($id, '_bunny_thumbnail_' . $size_name, $result);
                    $processed++;
                } else {
                    $errors++;
                }
            }
        }
        
        return array('processed' => $processed, 'errors' => $errors);
    }
}
