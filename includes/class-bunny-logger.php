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
        
        return $wpdb->get_results($wpdb->prepare($query, $params));
    }
    
    /**
     * Count logs
     */
    public function count_logs($level = null) {
        global $wpdb;
        
        $where = '';
        $params = array();
        
        if (!empty($level)) {
            $where = 'WHERE log_level = %s';
            $params[] = $level;
        }
        
        $query = "SELECT COUNT(*) FROM {$this->table_name} {$where}";
        
        if (!empty($params)) {
            return $wpdb->get_var($wpdb->prepare($query, $params));
        } else {
            return $wpdb->get_var($query);
        }
    }
    
    /**
     * Clear all logs
     */
    public function clear_logs() {
        global $wpdb;
        return $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }
    
    /**
     * Clear logs by level
     */
    public function clear_logs_by_level($level) {
        global $wpdb;
        return $wpdb->delete($this->table_name, array('log_level' => $level));
    }
    
    /**
     * Maybe clean old logs (keep only last 1000 entries)
     */
    private function maybe_clean_old_logs() {
        // Only clean logs occasionally to avoid performance impact
        if (rand(1, 100) > 5) {
            return;
        }
        
        global $wpdb;
        
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        
        if ($count > 1000) {
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