<?php
/**
 * Main plugin class
 */
class Bunny_Media_Offload {
    
    /**
     * Plugin instance
     * @var Bunny_Media_Offload
     */
    private static $instance = null;
    
    /**
     * Plugin components
     */
    public $api;
    public $uploader;
    public $admin;
    public $migration;
    public $settings;
    public $stats;
    public $logger;
    public $cli;
    public $wpml;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
        $this->init_components();
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-utils.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-logger.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-settings.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-api.php';
        
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-uploader.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-migration.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-stats.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-wpml.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-admin.php';
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'load_textdomain'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
    }
    
    /**
     * Initialize plugin components
     */
    private function init_components() {
        $this->logger = new Bunny_Logger();
        $this->settings = new Bunny_Settings();
        $this->api = new Bunny_API($this->settings, $this->logger);
        $this->uploader = new Bunny_Uploader($this->api, $this->settings, $this->logger);
        $this->migration = new Bunny_Migration($this->api, $this->settings, $this->logger);
        $this->stats = new Bunny_Stats($this->api, $this->settings, $this->migration);
        $this->wpml = new Bunny_WPML($this->settings, $this->logger);
        $this->admin = new Bunny_Admin($this->settings, $this->stats, $this->migration, $this->logger, null, $this->wpml);
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('bunny-media-offload', false, dirname(BMO_PLUGIN_BASENAME) . '/languages');
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'bunny-media-offload') !== false || strpos($hook, 'bunny-media-logs') !== false) {
            // Enqueue modular optimization and migration modules first
            wp_enqueue_script(
                'bunny-optimization-module',
                BMO_PLUGIN_URL . 'assets/js/bunny-optimization.js',
                array('jquery'),
                BMO_PLUGIN_VERSION,
                true
            );
            
            wp_enqueue_script(
                'bunny-migration-module',
                BMO_PLUGIN_URL . 'assets/js/bunny-migration.js',
                array('jquery'),
                BMO_PLUGIN_VERSION,
                true
            );
            
            wp_enqueue_script(
                'bunny-media-offload-admin',
                BMO_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery', 'bunny-optimization-module', 'bunny-migration-module'), // Make admin.js depend on both modules
                BMO_PLUGIN_VERSION,
                true
            );
            
            wp_enqueue_style(
                'bunny-media-offload-admin',
                BMO_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                BMO_PLUGIN_VERSION
            );
            
            wp_enqueue_style(
                'bunny-media-offload-pages',
                BMO_PLUGIN_URL . 'assets/css/pages.css',
                array(),
                BMO_PLUGIN_VERSION
            );
            
            wp_enqueue_style(
                'bunny-media-offload-core',
                BMO_PLUGIN_URL . 'assets/css/core.css',
                array(),
                BMO_PLUGIN_VERSION
            );

            // Enqueue dedicated logs CSS file for the logs page
            if (isset($_GET['page']) && $_GET['page'] === 'bunny-media-logs') {
                wp_enqueue_style(
                    'bunny-media-logs',
                    BMO_PLUGIN_URL . 'assets/css/bunny-media-logs.css',
                    array(),
                    BMO_PLUGIN_VERSION
                );
            }
            
            // Localize scripts for all modules and admin
            $ajax_data = array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('bunny_ajax_nonce'),
                'strings' => array(
                    'testing_connection' => __('Testing connection...', 'bunny-media-offload'),
                    'connection_success' => __('Connection successful!', 'bunny-media-offload'),
                    'connection_failed' => __('Connection failed!', 'bunny-media-offload'),
                    'migrating' => __('Migrating files...', 'bunny-media-offload'),
                    'migration_complete' => __('Migration completed!', 'bunny-media-offload'),
                    'optimizing' => __('Optimizing images...', 'bunny-media-offload'),
                    'optimization_complete' => __('Optimization completed!', 'bunny-media-offload'),
                    'optimization_failed' => __('Optimization failed!', 'bunny-media-offload'),
                ),
                'bmo_config' => array(
                    'batch_size' => 20,          // BMO API maximum batch size
                    'max_queue' => 100,          // Maximum internal queue size  
                    'processing_delay' => 1000,  // Delay between batches (ms)
                    'retry_attempts' => 3,       // Retry attempts for failed batches
                    'strategy' => 'FIFO'         // Processing strategy (First In, First Out)
                ),
                'migration_config' => array(
                    'batch_size' => 5,           // Migration batch size
                    'max_queue' => 100,          // Maximum internal queue size
                    'queue_delay' => 200,        // Delay between image processing (ms)
                    'retry_attempts' => 3        // Retry attempts for failed migrations
                )
            );
            
            wp_localize_script('bunny-optimization-module', 'bunnyAjax', $ajax_data);
            wp_localize_script('bunny-migration-module', 'bunnyAjax', $ajax_data);
            wp_localize_script('bunny-media-offload-admin', 'bunnyAjax', $ajax_data);
        }
    }
    
    /**
     * Enqueue frontend scripts
     */
    public function enqueue_frontend_scripts() {
        // Frontend scripts if needed
    }
    
    /**
     * Plugin activation
     */
    public static function activate() {
        // Create plugin tables
        self::create_tables();
        
        // Set default options
        self::set_default_options();
        
        // Clear rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled events
        
        // Clear rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin uninstallation
     */
    public static function uninstall() {
        // Remove plugin options
        delete_option('bunny_media_offload_stats');
        
        // Remove JSON configuration file
        $config_file = WP_CONTENT_DIR . '/bunny-config.json';
        if (file_exists($config_file)) {
            wp_delete_file($config_file);
        }
        
        // Drop plugin tables
        self::drop_tables();
    }
    
    /**
     * Create plugin database tables
     */
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Table for tracking offloaded files
        $table_name = $wpdb->prefix . 'bunny_offloaded_files';
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) NOT NULL,
            bunny_url varchar(500) NOT NULL,
            file_size bigint(20) DEFAULT 0,
            file_type varchar(100) DEFAULT '',
            date_offloaded datetime DEFAULT CURRENT_TIMESTAMP,
            is_synced tinyint(1) DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY attachment_id (attachment_id),
            KEY bunny_url (bunny_url(191))
        ) $charset_collate;";
        
        // Table for logs
        $log_table = $wpdb->prefix . 'bunny_logs';
        $log_sql = "CREATE TABLE $log_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            log_level varchar(20) NOT NULL,
            message text NOT NULL,
            context longtext DEFAULT NULL,
            date_created datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY log_level (log_level),
            KEY date_created (date_created)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Creating plugin tables during activation, schema changes required for plugin functionality
        dbDelta($sql);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Creating plugin tables during activation, schema changes required for plugin functionality
        dbDelta($log_sql);
        
        // Create optimization queue table
        self::create_optimization_table();
    }
    
    /**
     * Create optimization queue table
     */
    private static function create_optimization_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $table_name = $wpdb->prefix . 'bunny_optimization_queue';
        $sql = "CREATE TABLE $table_name (
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Creating optimization queue table during activation, schema changes required for plugin functionality
        dbDelta($sql);
    }
    
    /**
     * Public method to ensure tables exist
     */
    public static function ensure_tables_exist() {
        self::create_tables();
    }
    
    /**
     * Drop plugin tables
     */
    private static function drop_tables() {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping plugin tables during uninstallation, schema changes required for cleanup
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}bunny_offloaded_files");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping plugin tables during uninstallation, schema changes required for cleanup
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}bunny_logs");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping plugin tables during uninstallation, schema changes required for cleanup
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}bunny_optimization_queue");
    }
    
    /**
     * Set default plugin options
     */
    private static function set_default_options() {
        // Create default JSON configuration file
        self::create_default_json_config();
        
        $default_stats = array(
            'total_files_offloaded' => 0,
            'total_space_saved' => 0,
            'total_bunny_storage' => 0,
            'last_sync' => 0
        );
        
        add_option('bunny_media_offload_stats', $default_stats);
    }
    
    /**
     * Create default JSON configuration file
     */
    private static function create_default_json_config() {
        $config_file_path = WP_CONTENT_DIR . '/bunny-config.json';
        
        // Only create if it doesn't exist
        if (file_exists($config_file_path)) {
            return;
        }
        
        $default_settings = array(
            'delete_local' => true,
            'file_versioning' => true,
            'allowed_file_types' => array('webp', 'avif', 'svg'),
            'allowed_post_types' => array('attachment', 'product'),
            'batch_size' => 100,
            'enable_logs' => true,
            'log_level' => 'info',
            'optimization_format' => 'auto',
            'optimization_quality' => 85,
            'migration_concurrent_limit' => 4
            // Note: optimization_concurrent_limit removed - processing is now external via BMO API
            // Note: optimization_batch_size removed - fixed at 10 for BMO API
        );
        
        // Ensure directory exists
        $config_dir = dirname($config_file_path);
        if (!is_dir($config_dir)) {
            wp_mkdir_p($config_dir);
        }
        
        // Save to JSON file with pretty printing
        $json_content = json_encode($default_settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if ($json_content !== false) {
            file_put_contents($config_file_path, $json_content);
        }
    }
} 