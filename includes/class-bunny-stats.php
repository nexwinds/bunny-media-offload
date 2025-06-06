<?php
/**
 * Bunny statistics handler
 */
class Bunny_Stats {
    
    private $api;
    private $settings;
    
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
        $db_stats = $this->get_database_stats();
        $storage_stats = $this->get_storage_stats();
        $bandwidth_stats = $this->get_bandwidth_stats();
        
        return array_merge($db_stats, $storage_stats, $bandwidth_stats);
    }
    
    /**
     * Get statistics from database
     */
    public function get_database_stats() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bunny_offloaded_files';
        
        // Get total files and sizes
        $totals = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_files,
                SUM(file_size) as total_size
            FROM $table_name 
            WHERE is_synced = 1
        ");
        
        // Get files by type
        $file_types = $wpdb->get_results("
            SELECT 
                CASE 
                    WHEN file_type LIKE 'image/%' THEN 'images'
                    WHEN file_type LIKE 'video/%' THEN 'videos'
                    WHEN file_type LIKE 'application/pdf%' THEN 'documents'
                    WHEN file_type LIKE 'application/%' THEN 'documents'
                    ELSE 'other'
                END as type_category,
                COUNT(*) as count,
                SUM(file_size) as size
            FROM $table_name 
            WHERE is_synced = 1
            GROUP BY type_category
        ");
        
        // Get recent uploads (last 30 days)
        $recent_uploads = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM $table_name 
            WHERE is_synced = 1 
            AND date_offloaded >= %s
        ", gmdate('Y-m-d H:i:s', strtotime('-30 days'))));
        
        // Process file types
        $type_stats = array(
            'images' => array('count' => 0, 'size' => 0),
            'videos' => array('count' => 0, 'size' => 0),
            'documents' => array('count' => 0, 'size' => 0),
            'other' => array('count' => 0, 'size' => 0)
        );
        
        foreach ($file_types as $type) {
            $type_stats[$type->type_category] = array(
                'count' => (int) $type->count,
                'size' => (int) $type->size
            );
        }
        
        return array(
            'total_files_offloaded' => (int) $totals->total_files,
            'total_space_saved' => (int) $totals->total_size,
            'recent_uploads' => (int) $recent_uploads,
            'file_types' => $type_stats
        );
    }
    
    /**
     * Get storage statistics
     */
    public function get_storage_stats() {
        $stats = $this->api->get_storage_stats();
        
        return array(
            'bunny_storage_used' => $stats['total_size'],
            'bunny_files_count' => $stats['total_files']
        );
    }
    
    /**
     * Get bandwidth statistics (placeholder - would need Bunny.net management API)
     */
    public function get_bandwidth_stats() {
        // This would require Bunny.net management API access
        // For now, return placeholder data
        return array(
            'bandwidth_used_month' => 0,
            'bandwidth_limit' => 0,
            'requests_count' => 0
        );
    }
    
    /**
     * Get cost savings estimate
     */
    public function get_cost_savings() {
        $stats = $this->get_database_stats();
        $total_size_gb = $stats['total_space_saved'] / (1024 * 1024 * 1024);
        
        // Rough estimate based on typical hosting costs vs CDN costs
        $hosting_cost_per_gb = 0.10; // $0.10 per GB per month for hosting storage
        $cdn_cost_per_gb = 0.01; // $0.01 per GB per month for CDN storage
        
        $monthly_hosting_cost = $total_size_gb * $hosting_cost_per_gb;
        $monthly_cdn_cost = $total_size_gb * $cdn_cost_per_gb;
        $monthly_savings = $monthly_hosting_cost - $monthly_cdn_cost;
        
        return array(
            'monthly_savings' => max(0, $monthly_savings),
            'yearly_savings' => max(0, $monthly_savings * 12),
            'storage_gb' => $total_size_gb
        );
    }
    
    /**
     * Get performance statistics
     */
    public function get_performance_stats() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bunny_offloaded_files';
        
        // Get average file size
        $avg_file_size = $wpdb->get_var("
            SELECT AVG(file_size) 
            FROM $table_name 
            WHERE is_synced = 1 AND file_size > 0
        ");
        
        // Get largest files
        $largest_files = $wpdb->get_results("
            SELECT bf.attachment_id, bf.file_size, p.post_title
            FROM $table_name bf
            LEFT JOIN {$wpdb->posts} p ON bf.attachment_id = p.ID
            WHERE bf.is_synced = 1
            ORDER BY bf.file_size DESC
            LIMIT 5
        ");
        
        // Get upload trend (last 7 days)
        $upload_trend = array();
        for ($i = 6; $i >= 0; $i--) {
            $date = gmdate('Y-m-d', strtotime("-$i days"));
            $count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) 
                FROM $table_name 
                WHERE DATE(date_offloaded) = %s
            ", $date));
            
            $upload_trend[] = array(
                'date' => $date,
                'count' => (int) $count
            );
        }
        
        return array(
            'average_file_size' => (int) $avg_file_size,
            'largest_files' => $largest_files,
            'upload_trend' => $upload_trend
        );
    }
    
    /**
     * Get migration progress
     */
    public function get_migration_progress() {
        global $wpdb;
        
        $total_attachments = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_type = 'attachment'
        ");
        
        $migrated_files = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}bunny_offloaded_files 
            WHERE is_synced = 1
        ");
        
        $progress_percentage = $total_attachments > 0 ? ($migrated_files / $total_attachments) * 100 : 0;
        
        return array(
            'total_attachments' => (int) $total_attachments,
            'migrated_files' => (int) $migrated_files,
            'pending_files' => max(0, $total_attachments - $migrated_files),
            'progress_percentage' => round($progress_percentage, 2)
        );
    }
    
    /**
     * Get error statistics
     */
    public function get_error_stats() {
        global $wpdb;
        
        $log_table = $wpdb->prefix . 'bunny_logs';
        
        // Get error counts by level
        $error_counts = $wpdb->get_results("
            SELECT log_level, COUNT(*) as count
            FROM $log_table 
            WHERE date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY log_level
        ");
        
        // Get recent errors
        $recent_errors = $wpdb->get_results("
            SELECT message, date_created
            FROM $log_table 
            WHERE log_level = 'error'
            AND date_created >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY date_created DESC
            LIMIT 5
        ");
        
        $error_stats = array(
            'error' => 0,
            'warning' => 0,
            'info' => 0,
            'debug' => 0
        );
        
        foreach ($error_counts as $count) {
            $error_stats[$count->log_level] = (int) $count->count;
        }
        
        return array(
            'error_counts' => $error_stats,
            'recent_errors' => $recent_errors
        );
    }
    
    /**
     * Export statistics as CSV
     */
    public function export_stats_csv() {
        $stats = $this->get_stats();
        $performance = $this->get_performance_stats();
        $migration = $this->get_migration_progress();
        
        $csv_data = "Metric,Value\n";
        $csv_data .= "Total Files Offloaded," . $stats['total_files_offloaded'] . "\n";
        $csv_data .= "Total Space Saved (bytes)," . $stats['total_space_saved'] . "\n";
        $csv_data .= "Recent Uploads (30 days)," . $stats['recent_uploads'] . "\n";
        $csv_data .= "Average File Size (bytes)," . $performance['average_file_size'] . "\n";
        $csv_data .= "Migration Progress (%)," . $migration['progress_percentage'] . "\n";
        $csv_data .= "Images Count," . $stats['file_types']['images']['count'] . "\n";
        $csv_data .= "Videos Count," . $stats['file_types']['videos']['count'] . "\n";
        $csv_data .= "Documents Count," . $stats['file_types']['documents']['count'] . "\n";
        $csv_data .= "Other Files Count," . $stats['file_types']['other']['count'] . "\n";
        
        return $csv_data;
    }
    
    /**
     * Reset statistics
     */
    public function reset_stats() {
        update_option('bunny_media_offload_stats', array(
            'total_files_offloaded' => 0,
            'total_space_saved' => 0,
            'total_bunny_storage' => 0,
            'last_sync' => 0
        ));
        
        return true;
    }
    
    /**
     * Get real-time statistics for dashboard widgets
     */
    public function get_dashboard_stats() {
        $stats = $this->get_stats();
        $migration = $this->get_migration_progress();
        
        return array(
            'total_files' => $stats['total_files_offloaded'],
            'space_saved' => Bunny_Utils::format_file_size($stats['total_space_saved']),
            'migration_progress' => $migration['progress_percentage'],
            'recent_uploads' => $stats['recent_uploads']
        );
    }
} 