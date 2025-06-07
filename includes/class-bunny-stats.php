<?php
/**
 * Bunny statistics handler - Simplified and optimized
 */
class Bunny_Stats {
    
    private $api;
    private $settings;
    private $cache_duration = 300; // 5 minutes standard cache
    
    /**
     * Constructor
     */
    public function __construct($api, $settings) {
        $this->api = $api;
        $this->settings = $settings;
    }
    
    /**
     * Get comprehensive statistics
     */
    public function get_stats() {
        $cache_key = 'bunny_comprehensive_stats';
        $cached_stats = wp_cache_get($cache_key, 'bunny_media_offload');
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        $db_stats = $this->get_database_stats();
        $storage_stats = $this->get_storage_stats();
        $migration_stats = $this->get_migration_progress();
        
        $stats = array_merge($db_stats, $storage_stats, array(
            'migration_progress' => $migration_stats['progress_percentage'],
            'pending_files' => $migration_stats['pending_files']
        ));
        
        // Cache comprehensive stats
        wp_cache_set($cache_key, $stats, 'bunny_media_offload', $this->cache_duration);
        
        return $stats;
    }
    
    /**
     * Get core database statistics
     */
    public function get_database_stats() {
        global $wpdb;
        
        try {
            // Single optimized query for all core stats
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query with caching implemented
            $core_stats = $wpdb->get_row("
                SELECT 
                    COUNT(*) as total_files,
                    SUM(file_size) as total_size,
                    SUM(CASE WHEN date_offloaded >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as recent_uploads,
                    SUM(CASE WHEN file_type LIKE 'image/%' THEN 1 ELSE 0 END) as images_count,
                    SUM(CASE WHEN file_type LIKE 'image/%' THEN file_size ELSE 0 END) as images_size,
                    SUM(CASE WHEN file_type LIKE 'video/%' THEN 1 ELSE 0 END) as videos_count,
                    SUM(CASE WHEN file_type LIKE 'video/%' THEN file_size ELSE 0 END) as videos_size,
                    SUM(CASE WHEN file_type LIKE 'application/%' OR file_type LIKE '%pdf%' THEN 1 ELSE 0 END) as documents_count,
                    SUM(CASE WHEN file_type LIKE 'application/%' OR file_type LIKE '%pdf%' THEN file_size ELSE 0 END) as documents_size
                FROM {$wpdb->prefix}bunny_offloaded_files 
                WHERE is_synced = 1
            ");
            
            if (!$core_stats) {
                throw new Exception('Failed to retrieve core stats');
            }
            
            // Build file types array
            $other_count = max(0, $core_stats->total_files - $core_stats->images_count - $core_stats->videos_count - $core_stats->documents_count);
            $other_size = max(0, $core_stats->total_size - $core_stats->images_size - $core_stats->videos_size - $core_stats->documents_size);
            
            return array(
                'total_files_offloaded' => (int) $core_stats->total_files,
                'total_space_saved' => (int) $core_stats->total_size,
                'recent_uploads' => (int) $core_stats->recent_uploads,
                'file_types' => array(
                    'images' => array('count' => (int) $core_stats->images_count, 'size' => (int) $core_stats->images_size),
                    'videos' => array('count' => (int) $core_stats->videos_count, 'size' => (int) $core_stats->videos_size),
                    'documents' => array('count' => (int) $core_stats->documents_count, 'size' => (int) $core_stats->documents_size),
                    'other' => array('count' => $other_count, 'size' => $other_size)
                )
            );
            
        } catch (Exception $e) {
            // Return default stats on error
            return array(
                'total_files_offloaded' => 0,
                'total_space_saved' => 0,
                'recent_uploads' => 0,
                'file_types' => array(
                    'images' => array('count' => 0, 'size' => 0),
                    'videos' => array('count' => 0, 'size' => 0),
                    'documents' => array('count' => 0, 'size' => 0),
                    'other' => array('count' => 0, 'size' => 0)
                )
            );
        }
    }
    
    /**
     * Get storage statistics from API
     */
    public function get_storage_stats() {
        try {
            $stats = $this->api->get_storage_stats();
            
            return array(
                'bunny_storage_used' => $stats['total_size'],
                'bunny_files_count' => $stats['total_files']
            );
        } catch (Exception $e) {
            return array(
                'bunny_storage_used' => 0,
                'bunny_files_count' => 0
            );
        }
    }
    
    /**
     * Get migration progress statistics
     */
    public function get_migration_progress() {
        $cache_key = 'bunny_migration_progress';
        $cached_stats = wp_cache_get($cache_key, 'bunny_media_offload');
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        global $wpdb;
        
        try {
            // Count supported file types (SVG, AVIF, WebP)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom query with caching implemented
            $total_supported = $wpdb->get_var("
                SELECT COUNT(DISTINCT posts.ID) 
                FROM {$wpdb->posts} posts
                WHERE posts.post_type = 'attachment' 
                AND posts.post_mime_type IN ('image/svg+xml', 'image/avif', 'image/webp')
            ");
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query with caching implemented  
            $migrated_files = $wpdb->get_var("
                SELECT COUNT(*) 
                FROM {$wpdb->prefix}bunny_offloaded_files 
                WHERE is_synced = 1
            ");
            
            $total_supported = (int) $total_supported;
            $migrated_files = (int) $migrated_files;
            $progress_percentage = $total_supported > 0 ? ($migrated_files / $total_supported) * 100 : 0;
            
            $stats = array(
                'total_attachments' => $total_supported,
                'migrated_files' => $migrated_files,
                'pending_files' => max(0, $total_supported - $migrated_files),
                'progress_percentage' => round($progress_percentage, 2)
            );
            
            // Cache for 2 minutes (migration changes frequently)
            wp_cache_set($cache_key, $stats, 'bunny_media_offload', 120);
            
            return $stats;
            
        } catch (Exception $e) {
            return array(
                'total_attachments' => 0,
                'migrated_files' => 0,
                'pending_files' => 0,
                'progress_percentage' => 0
            );
        }
    }
    
    /**
     * Get performance insights (simplified)
     */
    public function get_performance_stats() {
        $cache_key = 'bunny_performance_stats';
        $cached_stats = wp_cache_get($cache_key, 'bunny_media_offload');
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        global $wpdb;
        
        try {
            // Single query for key performance metrics
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query with caching implemented
            $perf_stats = $wpdb->get_row("
                SELECT 
                    AVG(file_size) as avg_file_size,
                    MAX(file_size) as max_file_size,
                    COUNT(*) as total_files
                FROM {$wpdb->prefix}bunny_offloaded_files 
                WHERE is_synced = 1 AND file_size > 0
            ");
            
            $stats = array(
                'average_file_size' => (int) ($perf_stats->avg_file_size ?: 0),
                'largest_file_size' => (int) ($perf_stats->max_file_size ?: 0),
                'total_files' => (int) ($perf_stats->total_files ?: 0)
            );
            
            wp_cache_set($cache_key, $stats, 'bunny_media_offload', $this->cache_duration);
            
            return $stats;
            
        } catch (Exception $e) {
            return array(
                'average_file_size' => 0,
                'largest_file_size' => 0,
                'total_files' => 0
            );
        }
    }
    
    /**
     * Get dashboard-ready statistics (formatted)
     */
    public function get_dashboard_stats() {
        $stats = $this->get_stats();
        
        return array(
            'total_files' => $stats['total_files_offloaded'],
            'space_saved' => Bunny_Utils::format_file_size($stats['total_space_saved']),
            'migration_progress' => $stats['migration_progress'],
            'recent_uploads' => $stats['recent_uploads']
        );
    }
    
    /**
     * Clear all statistics caches
     */
    public function clear_cache() {
        $cache_keys = array(
            'bunny_comprehensive_stats',
            'bunny_migration_progress', 
            'bunny_performance_stats'
        );
        
        foreach ($cache_keys as $key) {
            wp_cache_delete($key, 'bunny_media_offload');
        }
        
        return true;
    }
} 