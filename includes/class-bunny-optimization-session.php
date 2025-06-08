<?php
/**
 * Bunny Optimization Session Manager
 * Handles optimization sessions, queue operations, and progress tracking
 */
class Bunny_Optimization_Session {
    
    private $logger;
    private $queue_table;
    
    /**
     * Constructor
     */
    public function __construct($logger) {
        $this->logger = $logger;
        
        global $wpdb;
        $this->queue_table = $wpdb->prefix . 'bunny_optimization_queue';
    }
    
    /**
     * Create a new optimization session
     */
    public function create_session($images) {
        try {
            $session_id = 'opt_' . time() . '_' . wp_generate_password(8, false);
            
            $session_data = array(
                'id' => $session_id,
                'total_images' => count($images),
                'processed' => 0,
                'successful' => 0,
                'failed' => 0,
                'status' => 'running',
                'start_time' => time(),
                'errors' => array(),
                'images' => $images
            );
            
            // Save session
            $transient_set = set_transient('bunny_optimization_' . $session_id, $session_data, 2 * HOUR_IN_SECONDS);
            
            if (!$transient_set) {
                $this->logger->log('error', "Failed to save optimization session: " . $session_id);
                return false;
            }
            
            $this->logger->log('info', "Created optimization session: " . $session_id, array(
                'total_images' => count($images)
            ));
            
            return $session_data;
            
        } catch (Exception $e) {
            $this->logger->log('error', 'Exception in session creation: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get session data
     */
    public function get_session($session_id) {
        $session = get_transient('bunny_optimization_' . $session_id);
        
        if (!$session) {
            $this->logger->log('error', "Session not found: " . $session_id);
            return false;
        }
        
        return $session;
    }
    
    /**
     * Update session progress
     */
    public function update_session($session_id, $updates) {
        $session = $this->get_session($session_id);
        
        if (!$session) {
            return false;
        }
        
        $session = array_merge($session, $updates);
        
        return set_transient('bunny_optimization_' . $session_id, $session, 2 * HOUR_IN_SECONDS);
    }
    
    /**
     * Cancel session
     */
    public function cancel_session($session_id) {
        $session = $this->get_session($session_id);
        
        if ($session) {
            $session['status'] = 'cancelled';
            set_transient('bunny_optimization_' . $session_id, $session, 2 * HOUR_IN_SECONDS);
            
            $this->logger->log('info', 'Session cancelled', array('session_id' => $session_id));
            return true;
        }
        
        return false;
    }
    
    /**
     * Get next batch of images for processing
     */
    public function get_next_batch($session_id, $batch_size = 10) {
        $session = $this->get_session($session_id);
        
        if (!$session) {
            return array();
        }
        
        $start_index = $session['processed'];
        return array_slice($session['images'], $start_index, $batch_size);
    }
    
    /**
     * Check if session is completed
     */
    public function is_completed($session_id) {
        $session = $this->get_session($session_id);
        
        if (!$session) {
            return true;
        }
        
        return $session['processed'] >= $session['total_images'] || $session['status'] === 'completed';
    }
    
    /**
     * Check if session is cancelled
     */
    public function is_cancelled($session_id) {
        $session = $this->get_session($session_id);
        
        if (!$session) {
            return false;
        }
        
        return $session['status'] === 'cancelled';
    }
    
    /**
     * Get session progress data
     */
    public function get_progress($session_id) {
        $session = $this->get_session($session_id);
        
        if (!$session) {
            return array(
                'processed' => 0,
                'total' => 0,
                'progress' => 0,
                'successful' => 0,
                'failed' => 0,
                'completed' => true
            );
        }
        
        return array(
            'processed' => $session['processed'],
            'total' => $session['total_images'],
            'progress' => $session['total_images'] > 0 ? round(($session['processed'] / $session['total_images']) * 100, 2) : 0,
            'successful' => $session['successful'],
            'failed' => $session['failed'],
            'errors' => $session['errors'],
            'completed' => $this->is_completed($session_id)
        );
    }
    
    /**
     * Add files to optimization queue
     */
    public function add_to_queue($attachment_ids, $priority = 'normal') {
        global $wpdb;
        
        $values = array();
        $placeholders = array();
        
        foreach ($attachment_ids as $attachment_id) {
            $values[] = $attachment_id;
            $values[] = $priority;
            $values[] = 'pending';
            $values[] = current_time('mysql');
            $placeholders[] = '(%d, %s, %s, %s)';
        }
        
        if (!empty($values)) {
            $sql = "INSERT IGNORE INTO {$this->queue_table} 
                    (attachment_id, priority, status, date_added) 
                    VALUES " . implode(', ', $placeholders);
            
            $result = $wpdb->query($wpdb->prepare($sql, $values));
            
            $this->logger->log('info', "Added {$result} files to optimization queue");
            
            return $result;
        }
        
        return false;
    }
    
    /**
     * Get optimizable attachments
     */
    public function get_optimizable_attachments($limit = 100) {
        global $wpdb;
        
        $sql = "
            SELECT p.ID 
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type LIKE 'image/%'
            AND p.ID NOT IN (
                SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_bunny_last_optimized' 
                AND meta_value > DATE_SUB(NOW(), INTERVAL 1 DAY)
            )
            AND p.ID NOT IN (
                SELECT attachment_id 
                FROM {$this->queue_table} 
                WHERE status IN ('pending', 'processing')
            )
            ORDER BY p.post_date DESC
            LIMIT %d
        ";
        
        $results = $wpdb->get_col($wpdb->prepare($sql, $limit));
        
        // Apply WPML filter
        return apply_filters('bunny_optimization_attachments', $results);
    }
    
    /**
     * Clean up old sessions
     */
    public function cleanup_old_sessions() {
        global $wpdb;
        
        // Delete transients older than 2 hours
        $wpdb->query("
            DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_bunny_optimization_%' 
            AND option_value < " . (time() - 2 * HOUR_IN_SECONDS)
        );
        
        $this->logger->log('info', 'Cleaned up old optimization sessions');
    }
} 