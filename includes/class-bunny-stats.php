<?php
/**
 * Bunny statistics handler - Consolidated and optimized
 */
class Bunny_Stats {
    
    private $api;
    private $settings;
    private $migration;
    private $criteria;
    private $stats_cache;
    private $cache_expiration = 300; // 5 minutes
    
    /**
     * Constructor
     */
    public function __construct($api, $settings, $migration, $criteria = null) {
        $this->api = $api;
        $this->settings = $settings;
        $this->migration = $migration;
        $this->criteria = $criteria;
        $this->stats_cache = array();
    }
    
    /**
     * Get unified image stats
     */
    public function get_unified_image_stats() {
        error_log('=== Starting get_unified_image_stats ===');
        
        if (isset($this->stats_cache['unified_image_stats']) && time() < $this->stats_cache['unified_image_stats']['expires']) {
            error_log('Returning cached stats');
            return $this->stats_cache['unified_image_stats']['data'];
        }
        
        global $wpdb;
        
        // Get total images in media library
        $total_query = "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'";
        $total_images = (int) $wpdb->get_var($total_query);
        error_log('Total images in media library: ' . $total_images);
        
        // Get images migrated to Bunny Storage
        $migrated_query = "SELECT COUNT(DISTINCT attachment_id) FROM {$wpdb->prefix}bunny_offloaded_files WHERE is_synced = 1";
        $images_migrated = (int) $wpdb->get_var($migrated_query);
        error_log('Images migrated to Bunny: ' . $images_migrated);
        
        // Get potential eligible images for optimization
        $potential_eligible_query = $wpdb->prepare(
            "SELECT p.ID, p.post_mime_type, pm.meta_value as file_path
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
             AND p.post_mime_type IN ('image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/heic', 'image/tiff')
             AND p.post_status = 'inherit'
             AND NOT EXISTS (
                 SELECT 1 FROM {$wpdb->prefix}bunny_offloaded_files bf 
                 WHERE bf.attachment_id = p.ID AND bf.is_synced = 1
             )"
        );
        
        $potential_eligible = $wpdb->get_results($potential_eligible_query);
        error_log('Found ' . count($potential_eligible) . ' potential eligible images');
        
        $max_file_size_bytes = $this->criteria ? $this->criteria->get_max_file_size_bytes() : 50 * 1024;
        $nine_mb_bytes = $this->criteria ? $this->criteria->get_nine_mb_bytes() : 9 * 1024 * 1024;
        
        // Filter by actual file size - matching the Optimization class criteria
        $eligible_for_optimization = 0;
        $ready_for_migration = 0;
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        
        foreach ($potential_eligible as $file) {
            if (!empty($file->file_path)) {
                $file_path = $base_dir . '/' . $file->file_path;
                
                if (file_exists($file_path)) {
                    $file_size = filesize($file_path);
                    $mime_type = $file->post_mime_type;
                    
                    // Use criteria class if available
                    if ($this->criteria) {
                        if ($this->criteria->is_eligible_for_optimization($file_path, $file_size, $mime_type)) {
                            $eligible_for_optimization++;
                        }
                        
                        if ($this->criteria->is_ready_for_migration($file_path, $file_size, $mime_type)) {
                            $ready_for_migration++;
                        }
                    } else {
                        // Fallback to original logic
                        // Eligible for optimization: > max_file_size_bytes but <= 9MB
                        if ($file_size > $max_file_size_bytes && $file_size <= $nine_mb_bytes) {
                            $eligible_for_optimization++;
                        }
                        
                        // Ready for migration: <= max_file_size_bytes
                        if ($file_size > 0 && $file_size <= $max_file_size_bytes) {
                            $ready_for_migration++;
                        }
                    }
                }
            }
        }
        
        error_log('Eligible for optimization after size check: ' . $eligible_for_optimization);
        error_log('Ready for migration after size check: ' . $ready_for_migration);
        
        // Get count of images that are NOT eligible for optimization or migration (wrong format or too large)
        $not_eligible = $total_images - $eligible_for_optimization - $ready_for_migration - $images_migrated;
        error_log('Not eligible (format or size): ' . $not_eligible);
        
        // Calculate percentages
        $not_eligible_percent = $total_images > 0 ? round(($not_eligible / $total_images) * 100, 1) : 0;
        $optimized_percent = $total_images > 0 ? round(($eligible_for_optimization / $total_images) * 100, 1) : 0;
        $migration_percent = $total_images > 0 ? round(($ready_for_migration / $total_images) * 100, 1) : 0;
        $cloud_percent = $total_images > 0 ? round(($images_migrated / $total_images) * 100, 1) : 0;
        
        $stats = array(
            'total_images' => $total_images,
            'local_eligible' => $eligible_for_optimization,
            'already_optimized' => $ready_for_migration, // Ready for migration
            'images_migrated' => $images_migrated, 
            'not_optimized' => $not_eligible, // These are NOT eligible for optimization
            'not_optimized_percent' => $not_eligible_percent,
            'optimized_percent' => $optimized_percent,
            'migration_percent' => $migration_percent,
            'cloud_percent' => $cloud_percent
        );
        
        // Cache the stats
        $this->stats_cache['unified_image_stats'] = array(
            'data' => $stats,
            'expires' => time() + $this->cache_expiration
        );
        
        error_log('Final stats: ' . print_r($stats, true));
        error_log('=== Ending get_unified_image_stats ===');
        
        return $stats;
    }
    
    /**
     * Count eligible local image files
     */
    private function count_eligible_local_files() {
        global $wpdb;
        
        $allowed_mime_types = array('image/jpeg', 'image/png', 'image/gif');
        $mime_type_placeholders = implode(',', array_fill(0, count($allowed_mime_types), '%s'));
        
        // Get count of local images that are Ready for Migration
        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND post_mime_type IN ($mime_type_placeholders)
            AND post_status = 'inherit'",
            $allowed_mime_types
        );
        
        return (int) $wpdb->get_var($query);
    }
    
    /**
     * Count images On Bunny SSD by checking their URLs
     */
    private function count_cdn_images_by_url() {
        $cache_key = 'bunny_cdn_images_count';
        $cached_count = wp_cache_get($cache_key, 'bunny_media_offload');
        
        if ($cached_count !== false) {
            return $cached_count;
        }
        
        global $wpdb;
        
        $custom_hostname = $this->settings->get('custom_hostname');
        if (empty($custom_hostname)) {
            wp_cache_set($cache_key, 0, 'bunny_media_offload', 300);
            return 0;
        }
        
        // Get all image attachments and check their URLs
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom query with caching implemented
        $attachments = $wpdb->get_results("
            SELECT ID, post_mime_type 
            FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND post_mime_type LIKE 'image/%'
        ");
        
        $cdn_count = 0;
        foreach ($attachments as $attachment) {
            $url = wp_get_attachment_url($attachment->ID);
            if ($url && strpos($url, $custom_hostname) !== false) {
                $cdn_count++;
            }
        }
        
        // Cache for 5 minutes
        wp_cache_set($cache_key, $cdn_count, 'bunny_media_offload', 300);
        
        return $cdn_count;
    }
    
    /**
     * Get migration progress statistics
     */
    public function get_migration_progress() {
        $unified_stats = $this->get_unified_image_stats();
        $total_relevant_images = $unified_stats['already_optimized'] + $unified_stats['images_migrated'];
        $migration_progress = $total_relevant_images > 0 ? round(($unified_stats['images_migrated'] / $total_relevant_images) * 100, 1) : 0;
        
        return array(
            'total_images' => $total_relevant_images,
            'images_migrated' => $unified_stats['images_migrated'],
            'images_pending' => $unified_stats['already_optimized'],
            'progress_percentage' => $migration_progress
        );
    }
    
    /**
     * Get core database statistics
     */
    public function get_database_stats() {
        $cache_key = 'bunny_database_stats';
        $cached_stats = wp_cache_get($cache_key, 'bunny_media_offload');
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        global $wpdb;
        
        try {
            // Single optimized query for all core stats
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query with caching implemented
            $core_stats = $wpdb->get_row("
                SELECT 
                    COUNT(*) as total_files,
                    SUM(file_size) as total_size,
                    SUM(CASE WHEN date_offloaded >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as recent_uploads
                FROM {$wpdb->prefix}bunny_offloaded_files 
                WHERE is_synced = 1
            ");
            
            if (!$core_stats) {
                throw new Exception('Failed to retrieve core stats');
            }
            
            $stats = array(
                'total_files_offloaded' => (int) $core_stats->total_files,
                'total_space_saved' => (int) $core_stats->total_size,
                'recent_uploads' => (int) $core_stats->recent_uploads
            );
            
            wp_cache_set($cache_key, $stats, 'bunny_media_offload', $this->cache_expiration);
            
            return $stats;
            
        } catch (Exception $e) {
            // Return default stats on error
            return array(
                'total_files_offloaded' => 0,
                'total_space_saved' => 0,
                'recent_uploads' => 0
            );
        }
    }
    
    /**
     * Get storage statistics from API
     */
    public function get_storage_stats() {
        $cache_key = 'bunny_storage_stats';
        $cached_stats = wp_cache_get($cache_key, 'bunny_media_offload');
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        try {
            $stats = $this->api->get_storage_stats();
            
            $result = array(
                'bunny_storage_used' => $stats['total_size'],
                'bunny_files_count' => $stats['total_files']
            );
            
            wp_cache_set($cache_key, $result, 'bunny_media_offload', $this->cache_expiration);
            
            return $result;
        } catch (Exception $e) {
            return array(
                'bunny_storage_used' => 0,
                'bunny_files_count' => 0
            );
        }
    }
    
    /**
     * Get dashboard-ready statistics (formatted)
     */
    public function get_dashboard_stats() {
        $db_stats = $this->get_database_stats();
        $migration_stats = $this->get_migration_progress();
        
        return array(
            'total_files' => $db_stats['total_files_offloaded'],
            'space_saved' => Bunny_Utils::format_file_size($db_stats['total_space_saved']),
            'migration_progress' => $migration_stats['progress_percentage'],
            'recent_uploads' => $db_stats['recent_uploads']
        );
    }
    
    /**
     * Clear all stats caches
     */
    public function clear_cache() {
        $this->stats_cache = array();
    }
} 