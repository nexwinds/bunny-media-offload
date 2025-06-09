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
        
        // Check if queue table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->queue_table}'") === $this->queue_table;
        
        if (!$table_exists) {
            $this->logger->log('warning', 'Optimization queue table does not exist, creating it now');
            
            // Try to create the table
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$this->queue_table} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                attachment_id bigint(20) NOT NULL,
                priority varchar(20) DEFAULT 'normal',
                status varchar(20) DEFAULT 'pending',
                date_added datetime DEFAULT CURRENT_TIMESTAMP,
                date_started datetime DEFAULT NULL,
                date_completed datetime DEFAULT NULL,
                error_message text DEFAULT NULL,
                PRIMARY KEY (id),
                KEY attachment_id (attachment_id),
                KEY status (status),
                KEY priority (priority),
                KEY date_added (date_added)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            // Verify creation
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->queue_table}'") === $this->queue_table;
            if (!$table_exists) {
                $this->logger->log('error', 'Failed to create optimization queue table');
            }
        }
        
        // Build the query with conditional queue table check
        // Only include legacy formats that need optimization, exclude migrated images
        if ($table_exists) {
            $sql = "
                SELECT p.ID 
                FROM {$wpdb->posts} p
                WHERE p.post_type = 'attachment'
                AND p.post_mime_type IN ('image/jpeg', 'image/jpg', 'image/png', 'image/gif')
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
                AND p.ID NOT IN (
                    SELECT DISTINCT attachment_id 
                    FROM {$wpdb->prefix}bunny_offloaded_files 
                    WHERE is_synced = 1
                )
                ORDER BY p.post_date DESC
                LIMIT %d
            ";
        } else {
            // Fallback query without queue table check
            $sql = "
                SELECT p.ID 
                FROM {$wpdb->posts} p
                WHERE p.post_type = 'attachment'
                AND p.post_mime_type IN ('image/jpeg', 'image/jpg', 'image/png', 'image/gif')
                AND p.ID NOT IN (
                    SELECT post_id 
                    FROM {$wpdb->postmeta} 
                    WHERE meta_key = '_bunny_last_optimized' 
                    AND meta_value > DATE_SUB(NOW(), INTERVAL 1 DAY)
                )
                AND p.ID NOT IN (
                    SELECT DISTINCT attachment_id 
                    FROM {$wpdb->prefix}bunny_offloaded_files 
                    WHERE is_synced = 1
                )
                ORDER BY p.post_date DESC
                LIMIT %d
            ";
        }
        
        $results = $wpdb->get_col($wpdb->prepare($sql, $limit));
        
        // Filter out attachments that don't have valid file paths or URLs
        $valid_attachments = array();
        $skipped_attachments = array();
        
        foreach ($results as $attachment_id) {
            $validation_result = $this->validate_attachment_for_optimization($attachment_id);
            
            if ($validation_result['valid']) {
                $valid_attachments[] = $attachment_id;
            } else {
                $skipped_attachments[] = array(
                    'id' => $attachment_id,
                    'reason' => $validation_result['reason']
                );
            }
        }
        
        $this->logger->log('info', 'Found optimizable attachments', array(
            'total_found' => count($results),
            'valid_attachments' => count($valid_attachments),
            'skipped_attachments' => count($skipped_attachments),
            'sample_valid_ids' => array_slice($valid_attachments, 0, 10),
            'sample_skipped' => array_slice($skipped_attachments, 0, 5),
            'queue_table_exists' => $table_exists
        ));
        
        // Apply WPML filter
        $filtered_results = apply_filters('bunny_optimization_attachments', $valid_attachments);
        
        if (count($filtered_results) !== count($valid_attachments)) {
            $this->logger->log('info', 'WPML filter modified attachment list', array(
                'original_count' => count($valid_attachments),
                'filtered_count' => count($filtered_results)
            ));
        }
        
        return $filtered_results;
    }
    
    /**
     * Validate attachment for optimization eligibility
     */
    private function validate_attachment_for_optimization($attachment_id) {
        // Check if post exists
        $post = get_post($attachment_id);
        if (!$post) {
            return array(
                'valid' => false,
                'reason' => 'Post not found'
            );
        }
        
        // Check if it's an image
        if (!wp_attachment_is_image($attachment_id)) {
            return array(
                'valid' => false,
                'reason' => 'Not an image attachment'
            );
        }
        
        // Check if image is already migrated
        global $wpdb;
        $is_migrated = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}bunny_offloaded_files 
            WHERE attachment_id = %d AND is_synced = 1
        ", $attachment_id));
        
        if ($is_migrated > 0) {
            return array(
                'valid' => false,
                'reason' => 'Image already migrated to CDN'
            );
        }
        
        // Check if it's already in modern format (shouldn't be optimized, should be migrated)
        $mime_type = get_post_mime_type($attachment_id);
        $modern_formats = array('image/webp', 'image/avif', 'image/svg+xml');
        if (in_array($mime_type, $modern_formats)) {
            return array(
                'valid' => false,
                'reason' => 'Already in optimal format (should be migrated instead)'
            );
        }
        
        // Check if file exists
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return array(
                'valid' => false,
                'reason' => 'File path not found or file does not exist'
            );
        }
        
        // Check file size (must be at least 35KB for BMO API)
        $file_size = filesize($file_path);
        if ($file_size < 35840) { // 35KB = 35 * 1024 bytes
            return array(
                'valid' => false,
                'reason' => 'File size below 35KB minimum requirement'
            );
        }
        
        // Check if we can get a valid URL
        $image_url = wp_get_attachment_url($attachment_id);
        if (!$image_url) {
            // Try alternative methods
            $upload_dir = wp_upload_dir();
            $meta_file = get_post_meta($attachment_id, '_wp_attached_file', true);
            
            if ($meta_file) {
                $image_url = $upload_dir['baseurl'] . '/' . $meta_file;
                $image_url = str_replace('\\', '/', $image_url);
            }
            
            if (!$image_url || !filter_var($image_url, FILTER_VALIDATE_URL)) {
                return array(
                    'valid' => false,
                    'reason' => 'Cannot generate valid URL for attachment'
                );
            }
        }
        
        return array(
            'valid' => true,
            'reason' => 'Passed all validation checks'
        );
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