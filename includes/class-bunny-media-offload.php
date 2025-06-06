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
    public $sync;
    public $settings;
    public $stats;
    public $logger;
    public $cli;
    public $optimizer;
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
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-api.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-uploader.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-admin.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-migration.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-sync.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-settings.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-stats.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-logger.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-utils.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-optimizer.php';
        require_once BMO_PLUGIN_DIR . 'includes/class-bunny-wpml.php';
        
        // Load CLI commands if WP CLI is available
        if (defined('WP_CLI') && WP_CLI) {
            require_once BMO_PLUGIN_DIR . 'includes/class-bunny-cli.php';
        }
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
        $this->optimizer = new Bunny_Optimizer($this->api, $this->settings, $this->logger);
        $this->uploader = new Bunny_Uploader($this->api, $this->settings, $this->logger);
        $this->migration = new Bunny_Migration($this->api, $this->settings, $this->logger);
        $this->sync = new Bunny_Sync($this->api, $this->settings, $this->logger);
        $this->stats = new Bunny_Stats($this->api, $this->settings);
        $this->wpml = new Bunny_WPML($this->settings, $this->logger);
        $this->admin = new Bunny_Admin($this->settings, $this->stats, $this->migration, $this->sync, $this->logger, $this->optimizer, $this->wpml);
        
        // Initialize CLI commands
        if (defined('WP_CLI') && WP_CLI) {
            $this->cli = new Bunny_CLI($this->uploader, $this->sync, $this->migration, $this->optimizer);
        }
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
        if (strpos($hook, 'bunny-media-offload') !== false) {
            wp_enqueue_script(
                'bunny-media-offload-admin',
                BMO_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                BMO_PLUGIN_VERSION,
                true
            );
            
            wp_enqueue_style(
                'bunny-media-offload-admin',
                BMO_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                BMO_PLUGIN_VERSION
            );
            
            wp_localize_script('bunny-media-offload-admin', 'bunnyAjax', array(
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
                )
            ));
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
        wp_clear_scheduled_hook('bunny_sync_check');
        
        // Clear rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin uninstallation
     */
    public static function uninstall() {
        // Remove plugin options
        delete_option('bunny_media_offload_settings');
        delete_option('bunny_media_offload_stats');
        
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
        dbDelta($sql);
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
        dbDelta($sql);
    }
    
    /**
     * Drop plugin tables
     */
    private static function drop_tables() {
        global $wpdb;
        
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}bunny_offloaded_files");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}bunny_logs");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}bunny_optimization_queue");
    }
    
    /**
     * Set default plugin options
     */
    private static function set_default_options() {
        $default_settings = array(
            'api_key' => '',
            'storage_zone' => '',
            'custom_hostname' => '',
            'auto_offload' => true,
            'delete_local' => true,
            'file_versioning' => true,
            'allowed_file_types' => array('jpg', 'jpeg', 'png', 'gif', 'pdf', 'mp4', 'webp'),
            'allowed_post_types' => array('attachment', 'product'),
            'batch_size' => 90,
            'enable_logs' => true,
            'log_level' => 'info'
        );
        
        add_option('bunny_media_offload_settings', $default_settings);
        
        $default_stats = array(
            'total_files_offloaded' => 0,
            'total_space_saved' => 0,
            'total_bunny_storage' => 0,
            'last_sync' => 0
        );
        
        add_option('bunny_media_offload_stats', $default_stats);
    }
} 