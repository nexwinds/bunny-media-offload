<?php
/**
 * Bunny WP-CLI commands
 */
class Bunny_CLI {
    
    private $uploader;
    private $sync;
    private $migration;
    private $optimizer;
    
    /**
     * Constructor
     */
    public function __construct($uploader, $sync, $migration, $optimizer = null) {
        $this->uploader = $uploader;
        $this->sync = $sync;
        $this->migration = $migration;
        $this->optimizer = $optimizer;
        
        $this->register_commands();
    }
    
    /**
     * Register WP-CLI commands
     */
    private function register_commands() {
        WP_CLI::add_command('bunny offload', array($this, 'offload'));
        WP_CLI::add_command('bunny sync', array($this, 'sync'));
        WP_CLI::add_command('bunny migrate', array($this, 'migrate'));
        WP_CLI::add_command('bunny status', array($this, 'status'));
        WP_CLI::add_command('bunny verify', array($this, 'verify'));
        WP_CLI::add_command('bunny cleanup', array($this, 'cleanup'));
        
        if ($this->optimizer) {
            WP_CLI::add_command('bunny optimize', array($this, 'optimize'));
            WP_CLI::add_command('bunny optimization-status', array($this, 'optimization_status'));
        }
    }
    
    /**
     * Offload a single file or all files
     */
    public function offload($args, $assoc_args) {
        $dry_run = isset($assoc_args['dry-run']);
        $file_types = isset($assoc_args['file-types']) ? explode(',', $assoc_args['file-types']) : array();
        
        if (!empty($args[0])) {
            $attachment_id = intval($args[0]);
            $this->offload_single_file($attachment_id, $dry_run);
        } else {
            $this->offload_all_files($file_types, $dry_run);
        }
    }
    
    /**
     * Sync files from Bunny.net back to local storage
     */
    public function sync($args, $assoc_args) {
        $dry_run = isset($assoc_args['dry-run']);
        
        if (!empty($args[0])) {
            $attachment_id = intval($args[0]);
            $this->sync_single_file($attachment_id, $dry_run);
        } else {
            $this->sync_all_files($dry_run);
        }
    }
    
    /**
     * Show plugin status and statistics
     */
    public function status($args, $assoc_args) {
        $cache_key = 'bunny_cli_status';
        $cached_stats = wp_cache_get($cache_key, 'bunny_media_offload');
        
        if ($cached_stats !== false) {
            $total_attachments = $cached_stats['total_attachments'];
            $offloaded_files = $cached_stats['offloaded_files'];
            $total_size = $cached_stats['total_size'];
        } else {
            global $wpdb;
            
            $table_name = $wpdb->prefix . 'bunny_offloaded_files';
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Using WP core table with caching implemented
            $total_attachments = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table query with caching implemented
            $offloaded_files = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE is_synced = 1");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table query with caching implemented
            $total_size = $wpdb->get_var("SELECT SUM(file_size) FROM $table_name WHERE is_synced = 1");
            
            $cached_stats = array(
                'total_attachments' => $total_attachments,
                'offloaded_files' => $offloaded_files,
                'total_size' => $total_size
            );
            
            // Cache for 2 minutes
            wp_cache_set($cache_key, $cached_stats, 'bunny_media_offload', 120);
        }
        
        $progress = $total_attachments > 0 ? round(($offloaded_files / $total_attachments) * 100, 2) : 0;
        
        WP_CLI::line('=== Bunny Media Offload Status ===');
        WP_CLI::line('Total Attachments: ' . number_format($total_attachments));
        WP_CLI::line('Offloaded Files: ' . number_format($offloaded_files));
        WP_CLI::line('Migration Progress: ' . $progress . '%');
        WP_CLI::line('Total Space Saved: ' . Bunny_Utils::format_file_size($total_size));
    }
    
    /**
     * Offload single file
     */
    private function offload_single_file($attachment_id, $dry_run = false) {
        if ($dry_run) {
            WP_CLI::line("DRY RUN: Would offload attachment $attachment_id");
            return;
        }
        
        $result = $this->uploader->upload_file_manually($attachment_id);
        
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        } else {
            WP_CLI::success("Offloaded attachment $attachment_id to: $result");
        }
    }
    
    /**
     * Offload all files
     */
    private function offload_all_files($file_types = array(), $dry_run = false) {
        global $wpdb;
        
        $where_conditions = array("posts.post_type = 'attachment'");
        $bunny_table = $wpdb->prefix . 'bunny_offloaded_files';
        $where_conditions[] = "posts.ID NOT IN (SELECT attachment_id FROM $bunny_table WHERE is_synced = 1)";
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $query = "
            SELECT posts.ID, posts.post_title
            FROM {$wpdb->posts} posts
            WHERE $where_clause
            ORDER BY posts.ID ASC
        ";
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Using WP core and custom tables for CLI command
        $files = $wpdb->get_results($query);
        $total_files = count($files);
        
        if ($total_files === 0) {
            WP_CLI::line('No files found to offload.');
            return;
        }
        
        if ($dry_run) {
            WP_CLI::line("DRY RUN: Would offload $total_files files");
            return;
        }
        
        WP_CLI::line("Starting offload of $total_files files...");
        
        $successful = 0;
        $failed = 0;
        
        foreach ($files as $file) {
            $result = $this->uploader->upload_file_manually($file->ID);
            
            if (is_wp_error($result)) {
                $failed++;
            } else {
                $successful++;
            }
        }
        
        WP_CLI::success("Offload completed. $successful successful, $failed failed.");
    }
    
    /**
     * Sync single file
     */
    private function sync_single_file($attachment_id, $dry_run = false) {
        if ($dry_run) {
            WP_CLI::line("DRY RUN: Would sync attachment $attachment_id from Bunny.net");
            return;
        }
        
        $result = $this->sync->sync_file_to_local($attachment_id);
        
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        } else {
            WP_CLI::success("Synced attachment $attachment_id from Bunny.net");
        }
    }
    
    /**
     * Sync all files
     */
    private function sync_all_files($dry_run = false) {
        $remote_only_files = $this->sync->get_remote_only_files();
        
        $total_files = count($remote_only_files);
        
        if ($total_files === 0) {
            WP_CLI::line('No files found that need syncing.');
            return;
        }
        
        if ($dry_run) {
            WP_CLI::line("DRY RUN: Would sync $total_files files from Bunny.net");
            return;
        }
        
        WP_CLI::line("Starting sync of $total_files files from Bunny.net...");
        
        $successful = 0;
        $failed = 0;
        
        foreach ($remote_only_files as $file) {
            $result = $this->sync->sync_file_to_local($file->attachment_id);
            
            if (is_wp_error($result)) {
                $failed++;
            } else {
                $successful++;
            }
        }
        
        WP_CLI::success("Sync completed. $successful successful, $failed failed.");
    }
    
    /**
     * Optimize images
     */
    public function optimize($args, $assoc_args) {
        if (!$this->optimizer) {
            WP_CLI::error('Optimization module is not available.');
            return;
        }
        
        $dry_run = isset($assoc_args['dry-run']);
        $file_types = isset($assoc_args['file-types']) ? explode(',', $assoc_args['file-types']) : array('jpg', 'jpeg', 'png', 'gif');
        $priority = isset($assoc_args['priority']) ? $assoc_args['priority'] : 'normal';
        
        if (!empty($args[0])) {
            $attachment_id = intval($args[0]);
            $this->optimize_single_file($attachment_id, $dry_run);
        } else {
            $this->optimize_all_files($file_types, $priority, $dry_run);
        }
    }
    
    /**
     * Show optimization status
     */
    public function optimization_status($args, $assoc_args) {
        if (!$this->optimizer) {
            WP_CLI::error('Optimization module is not available.');
            return;
        }
        
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'bunny_optimization_queue';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query for CLI command
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped
            FROM {$wpdb->prefix}bunny_optimization_queue
        ");
        
        $optimization_stats = $this->optimizer->get_optimization_stats();
        
        WP_CLI::line('=== Bunny Image Optimization Status ===');
        WP_CLI::line('Queue Status:');
        WP_CLI::line('  Total in Queue: ' . number_format($stats->total ?? 0));
        WP_CLI::line('  Pending: ' . number_format($stats->pending ?? 0));
        WP_CLI::line('  Processing: ' . number_format($stats->processing ?? 0));
        WP_CLI::line('  Completed: ' . number_format($stats->completed ?? 0));
        WP_CLI::line('  Failed: ' . number_format($stats->failed ?? 0));
        WP_CLI::line('  Skipped: ' . number_format($stats->skipped ?? 0));
        WP_CLI::line('');
        WP_CLI::line('Overall Statistics:');
        WP_CLI::line('  Total Optimized: ' . number_format($optimization_stats['total_optimized']));
        WP_CLI::line('  Space Saved: ' . $optimization_stats['total_savings_formatted']);
        WP_CLI::line('  Compression Ratio: ' . $optimization_stats['compression_ratio'] . '%');
    }
    
    /**
     * Optimize single file
     */
    private function optimize_single_file($attachment_id, $dry_run = false) {
        if (!wp_attachment_is_image($attachment_id)) {
            WP_CLI::error("Attachment $attachment_id is not an image.");
            return;
        }
        
        if ($dry_run) {
            WP_CLI::line("DRY RUN: Would optimize attachment $attachment_id");
            return;
        }
        
        $file_path = get_attached_file($attachment_id);
        if (!$file_path) {
            WP_CLI::error("File not found for attachment $attachment_id");
            return;
        }
        
        $result = $this->optimizer->optimize_image($file_path, $attachment_id);
        
        if ($result) {
            WP_CLI::success("Optimized attachment $attachment_id");
        } else {
            WP_CLI::warning("Failed to optimize attachment $attachment_id (may not need optimization)");
        }
    }
    
    /**
     * Optimize all files
     */
    private function optimize_all_files($file_types = array(), $priority = 'normal', $dry_run = false) {
        $attachment_ids = $this->optimizer->get_optimizable_attachments($file_types);
        
        $total_files = count($attachment_ids);
        
        if ($total_files === 0) {
            WP_CLI::line('No images found that need optimization.');
            return;
        }
        
        if ($dry_run) {
            WP_CLI::line("DRY RUN: Would add $total_files images to optimization queue");
            return;
        }
        
        WP_CLI::line("Adding $total_files images to optimization queue...");
        
        $added = $this->optimizer->add_to_optimization_queue($attachment_ids, $priority);
        
        if ($added) {
            WP_CLI::success("Added $added images to optimization queue with $priority priority.");
            WP_CLI::line("Use 'wp bunny optimization-status' to monitor progress.");
        } else {
            WP_CLI::error("Failed to add images to optimization queue.");
        }
    }
} 