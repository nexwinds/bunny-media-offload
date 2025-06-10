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
            
            $this->logger->log('info', 'Creating optimization session', array(
                'session_id' => $session_id,
                'total_images' => count($images),
                'first_10_images' => array_slice($images, 0, 10),
                'images_data_type' => gettype($images),
                'first_image_type' => count($images) > 0 ? gettype($images[0]) : 'none'
            ));
            
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
            
            $this->logger->log('info', "Created optimization session successfully", array(
                'session_id' => $session_id,
                'total_images' => count($images),
                'transient_saved' => $transient_set
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
            $this->logger->log('error', 'Session not found in get_next_batch', array(
                'session_id' => $session_id,
                'requested_batch_size' => $batch_size
            ));
            return array();
        }
        
        $start_index = $session['processed'];
        $batch = array_slice($session['images'], $start_index, $batch_size);
        
        $this->logger->log('info', 'get_next_batch called', array(
            'session_id' => $session_id,
            'requested_batch_size' => $batch_size,
            'session_total_images' => $session['total_images'],
            'session_processed' => $session['processed'],
            'start_index' => $start_index,
            'remaining_images' => $session['total_images'] - $session['processed'],
            'batch_returned_count' => count($batch),
            'batch_images' => $batch
        ));
        
        return $batch;
    }
    
    /**
     * Check if session is completed
     * A session is completed when all processable images have been handled
     */
    public function is_completed($session_id) {
        $session = $this->get_session($session_id);
        
        if (!$session) {
            return true;
        }
        
        // Check if explicitly marked as completed
        if ($session['status'] === 'completed') {
            return true;
        }
        
        // Check if we've processed all images in the session
        if ($session['processed'] >= $session['total_images']) {
            return true;
        }
        
        // Check if we've reached the end of available images to process
        // This handles cases where some images fail validation
        $remaining_batch = array_slice($session['images'], $session['processed'], 20);
        if (empty($remaining_batch)) {
            // No more images to process, mark as completed
            $this->update_session($session_id, array('status' => 'completed'));
            return true;
        }
        
        return false;
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
     * Uses same validation as BMO processor to ensure consistency
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
        
        $this->logger->log('info', 'Raw query results from database', array(
            'total_found' => count($results),
            'first_10_results' => array_slice($results, 0, 10),
            'limit' => $limit
        ));
        
        // Pre-filter using the same validation as BMO processor to ensure consistency
        $valid_attachments = array();
        $skipped_attachments = array();
        $validation_stats = array();
        
        foreach ($results as $attachment_id) {
            $validation_result = $this->validate_attachment_for_optimization($attachment_id);
            
            if ($validation_result['valid']) {
                $valid_attachments[] = $attachment_id;
                $validation_stats['valid'] = ($validation_stats['valid'] ?? 0) + 1;
                $this->logger->log('debug', "Session validation passed for attachment {$attachment_id}", array(
                    'attachment_id' => $attachment_id,
                    'reason' => 'Meets all optimization criteria'
                ));
            } else {
                $skipped_attachments[] = array(
                    'id' => $attachment_id,
                    'reason' => $validation_result['reason']
                );
                $reason = $validation_result['reason'];
                $validation_stats[$reason] = ($validation_stats[$reason] ?? 0) + 1;
                $this->logger->log('debug', "Session validation failed for attachment {$attachment_id}", array(
                    'attachment_id' => $attachment_id,
                    'reason' => $validation_result['reason']
                ));
            }
        }
        
        $this->logger->log('info', 'Session manager validation completed', array(
            'total_found' => count($results),
            'valid_attachments' => count($valid_attachments),
            'skipped_attachments' => count($skipped_attachments),
            'validation_stats' => $validation_stats,
            'valid_attachment_ids' => $valid_attachments,
            'detailed_skipped_reasons' => array_map(function($item) {
                return "ID {$item['id']}: {$item['reason']}";
            }, array_slice($skipped_attachments, 0, 5)) // Show first 5 for debugging
        ));
        
        return $valid_attachments;
    }
    
    /**
     * Validate attachment for optimization eligibility
     * This should align with the database query filtering logic
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
        
        // Check if it's an image attachment
        if ($post->post_type !== 'attachment' || !wp_attachment_is_image($attachment_id)) {
            return array(
                'valid' => false,
                'reason' => 'Not an image attachment'
            );
        }
        
        // Check if it's a supported format (basic check)
        $mime_type = $post->post_mime_type;
        $supported_types = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif');
        if (!in_array($mime_type, $supported_types)) {
            return array(
                'valid' => false,
                'reason' => 'Unsupported image format'
            );
        }
        
        // Check if recently optimized (align with database query)
        $last_optimized = get_post_meta($attachment_id, '_bunny_last_optimized', true);
        if ($last_optimized) {
            $one_day_ago = time() - DAY_IN_SECONDS;
            if (strtotime($last_optimized) > $one_day_ago) {
                return array(
                    'valid' => false,
                    'reason' => 'Recently optimized (within 24 hours)'
                );
            }
        }
        
        // Check if image is on CDN (align with database query)
        global $wpdb;
        $is_on_cdn = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}bunny_offloaded_files 
            WHERE attachment_id = %d AND is_synced = 1
        ", $attachment_id));
        
        if ($is_on_cdn > 0) {
            return array(
                'valid' => false,
                'reason' => 'Image already migrated to CDN'
            );
        }
        
        // Check basic file requirements (more lenient than BMO processor)
        $file_path = get_attached_file($attachment_id);
        if (!$file_path) {
            return array(
                'valid' => false,
                'reason' => 'No file path found'
            );
        }
        
        // Check file exists locally
        if (!file_exists($file_path)) {
            return array(
                'valid' => false,
                'reason' => 'File not found locally'
            );
        }
        
        // Check minimum file size (35KB for BMO API)
        $file_size = filesize($file_path);
        if ($file_size < 35840) { // 35KB = 35 * 1024 bytes
            return array(
                'valid' => false,
                'reason' => 'File size below 35KB minimum'
            );
        }
        
        // Image passes all session-level validation
        return array(
            'valid' => true,
            'reason' => 'Passed all session validation checks'
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
    
    /**
     * Get session status
     * 
     * @param string $session_id Session ID
     * @return string Session status
     */
    public function get_status($session_id) {
        $session = $this->get_session($session_id);
        
        if (!$session) {
            return 'not_found';
        }
        
        return $session['status'];
    }
} 