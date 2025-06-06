<?php
/**
 * Bunny logger
 */
class Bunny_Logger {
    
    const LEVEL_ERROR = 'error';
    const LEVEL_WARNING = 'warning';
    const LEVEL_INFO = 'info';
    const LEVEL_DEBUG = 'debug';
    
    private $table_name;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'bunny_logs';
    }
    
    /**
     * Log error message
     */
    public function error($message, $context = array()) {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }
    
    /**
     * Log warning message
     */
    public function warning($message, $context = array()) {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }
    
    /**
     * Log info message
     */
    public function info($message, $context = array()) {
        $this->log(self::LEVEL_INFO, $message, $context);
    }
    
    /**
     * Log debug message
     */
    public function debug($message, $context = array()) {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }
    
    /**
     * Log message to database
     */
    public function log($level, $message, $context = array()) {
        // Check if logging is enabled
        $settings = get_option('bunny_media_offload_settings', array());
        if (empty($settings['enable_logs'])) {
            return;
        }
        
        // Check log level
        $allowed_levels = $this->get_allowed_levels($settings['log_level'] ?? 'info');
        if (!in_array($level, $allowed_levels)) {
            return;
        }
        
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct insert needed for logging, no caching required for log writes
        $wpdb->insert($this->table_name, array(
            'log_level' => $level,
            'message' => $message,
            'context' => !empty($context) ? wp_json_encode($context) : null,
            'date_created' => current_time('mysql')
        ));
        
        // Clean old logs periodically
        $this->maybe_clean_old_logs();
    }
    
    /**
     * Get allowed log levels based on minimum level
     */
    private function get_allowed_levels($min_level) {
        $levels = array(
            self::LEVEL_ERROR => 1,
            self::LEVEL_WARNING => 2,
            self::LEVEL_INFO => 3,
            self::LEVEL_DEBUG => 4
        );
        
        $min_level_value = $levels[$min_level] ?? 3;
        $allowed = array();
        
        foreach ($levels as $level => $value) {
            if ($value <= $min_level_value) {
                $allowed[] = $level;
            }
        }
        
        return $allowed;
    }
    
    /**
     * Get logs from database
     */
    public function get_logs($limit = 100, $offset = 0, $level = null) {
        // Create cache key based on parameters
        $cache_key = 'bunny_logs_' . md5($limit . '_' . $offset . '_' . ($level ?: 'all'));
        $cached_logs = wp_cache_get($cache_key, 'bunny_media_offload');
        
        if ($cached_logs !== false) {
            return $cached_logs;
        }
        
        global $wpdb;
        
        $where = '';
        $params = array();
        
        if (!empty($level)) {
            $where = 'WHERE log_level = %s';
            $params[] = $level;
        }
        
        $params[] = $limit;
        $params[] = $offset;
        
        $query = "SELECT * FROM {$this->table_name} {$where} ORDER BY date_created DESC LIMIT %d OFFSET %d";
        
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is safely constructed with placeholders, caching implemented above
        $logs = $wpdb->get_results($wpdb->prepare($query, $params));
        
        // Cache for 2 minutes
        wp_cache_set($cache_key, $logs, 'bunny_media_offload', 2 * MINUTE_IN_SECONDS);
        
        return $logs;
    }
    
    /**
     * Count logs
     */
    public function count_logs($level = null) {
        // Create cache key based on level
        $cache_key = 'bunny_logs_count_' . ($level ?: 'all');
        $cached_count = wp_cache_get($cache_key, 'bunny_media_offload');
        
        if ($cached_count !== false) {
            return $cached_count;
        }
        
        global $wpdb;
        
        $where = '';
        $params = array();
        
        if (!empty($level)) {
            $where = 'WHERE log_level = %s';
            $params[] = $level;
        }
        
        $query = "SELECT COUNT(*) FROM {$this->table_name} {$where}";
        
        if (!empty($params)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is safely constructed with placeholders, caching implemented above
            $count = $wpdb->get_var($wpdb->prepare($query, $params));
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query uses safe table name only, caching implemented above
            $count = $wpdb->get_var($query);
        }
        
        // Cache for 3 minutes
        wp_cache_set($cache_key, $count, 'bunny_media_offload', 3 * MINUTE_IN_SECONDS);
        
        return $count;
    }
    
    /**
     * Clear all logs
     */
    public function clear_logs() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct operation needed for clearing logs
        return $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}bunny_logs");
    }
    
    /**
     * Clear logs by level
     */
    public function clear_logs_by_level($level) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct delete needed for clearing logs by level
        return $wpdb->delete($this->table_name, array('log_level' => $level));
    }
    
    /**
     * Maybe clean old logs (keep only last 1000 entries)
     */
    private function maybe_clean_old_logs() {
        // Only clean logs occasionally to avoid performance impact
        if (wp_rand(1, 100) > 5) {
            return;
        }
        
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- No caching needed for maintenance operations
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bunny_logs");
        
        if ($count > 1000) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Using safe table name with wpdb prefix, no caching needed for maintenance operations
            $wpdb->query("
                DELETE FROM {$this->table_name} 
                WHERE id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM {$this->table_name} 
                        ORDER BY date_created DESC 
                        LIMIT 1000
                    ) as keep_logs
                )
            ");
        }
    }
    
    /**
     * Export logs as CSV
     */
    public function export_logs($level = null) {
        $logs = $this->get_logs(1000, 0, $level);
        
        $csv_data = "Date,Level,Message,Context\n";
        
        foreach ($logs as $log) {
            $context = !empty($log->context) ? str_replace('"', '""', $log->context) : '';
            $message = str_replace('"', '""', $log->message);
            
            $csv_data .= sprintf(
                '"%s","%s","%s","%s"' . "\n",
                $log->date_created,
                $log->log_level,
                $message,
                $context
            );
        }
        
        return $csv_data;
    }
} 