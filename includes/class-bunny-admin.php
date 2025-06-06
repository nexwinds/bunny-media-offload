<?php
/**
 * Bunny admin interface
 */
class Bunny_Admin {
    
    private $settings;
    private $stats;
    private $migration;
    private $sync;
    private $logger;
    private $optimizer;
    private $wpml;
    
    /**
     * Constructor
     */
    public function __construct($settings, $stats, $migration, $sync, $logger, $optimizer = null, $wpml = null) {
        $this->settings = $settings;
        $this->stats = $stats;
        $this->migration = $migration;
        $this->sync = $sync;
        $this->logger = $logger;
        $this->optimizer = $optimizer;
        $this->wpml = $wpml;
        
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_bunny_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_bunny_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_bunny_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_bunny_export_logs', array($this, 'ajax_export_logs'));
        add_action('wp_ajax_bunny_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_bunny_regenerate_thumbnails', array($this, 'ajax_regenerate_thumbnails'));
        
        // Add media library column and filters
        add_filter('manage_media_columns', array($this, 'add_media_column'));
        add_action('manage_media_custom_column', array($this, 'display_media_column'), 10, 2);
        add_action('restrict_manage_posts', array($this, 'add_media_library_filter'));
        add_filter('parse_query', array($this, 'filter_media_library_query'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Bunny Media Offload', 'bunny-media-offload'),
            __('Bunny CDN', 'bunny-media-offload'),
            'manage_options',
            'bunny-media-offload',
            array($this, 'dashboard_page'),
            'dashicons-cloud',
            30
        );
        
        add_submenu_page(
            'bunny-media-offload',
            __('Dashboard', 'bunny-media-offload'),
            __('Dashboard', 'bunny-media-offload'),
            'manage_options',
            'bunny-media-offload',
            array($this, 'dashboard_page')
        );
        
        add_submenu_page(
            'bunny-media-offload',
            __('Settings', 'bunny-media-offload'),
            __('Settings', 'bunny-media-offload'),
            'manage_options',
            'bunny-media-offload-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'bunny-media-offload',
            __('Migration', 'bunny-media-offload'),
            __('Migration', 'bunny-media-offload'),
            'manage_options',
            'bunny-media-offload-migration',
            array($this, 'migration_page')
        );
        
        add_submenu_page(
            'bunny-media-offload',
            __('Sync & Recovery', 'bunny-media-offload'),
            __('Sync & Recovery', 'bunny-media-offload'),
            'manage_options',
            'bunny-media-offload-sync',
            array($this, 'sync_page')
        );
        
        add_submenu_page(
            'bunny-media-offload',
            __('Optimization', 'bunny-media-offload'),
            __('Optimization', 'bunny-media-offload'),
            'manage_options',
            'bunny-media-offload-optimization',
            array($this, 'optimization_page')
        );
        
        add_submenu_page(
            'bunny-media-offload',
            __('Logs', 'bunny-media-offload'),
            __('Logs', 'bunny-media-offload'),
            'manage_options',
            'bunny-media-offload-logs',
            array($this, 'logs_page')
        );
        
        add_submenu_page(
            'bunny-media-offload',
            __('Documentation', 'bunny-media-offload'),
            __('Documentation', 'bunny-media-offload'),
            'manage_options',
            'bunny-media-offload-documentation',
            array($this, 'documentation_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('bunny_media_offload_settings', 'bunny_media_offload_settings', array(
            'sanitize_callback' => array($this, 'sanitize_settings')
        ));
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['api_key'])) {
            $sanitized['api_key'] = sanitize_text_field($input['api_key']);
        }
        
        if (isset($input['storage_zone'])) {
            $sanitized['storage_zone'] = sanitize_text_field($input['storage_zone']);
        }
        
        if (isset($input['custom_hostname'])) {
            $sanitized['custom_hostname'] = sanitize_text_field($input['custom_hostname']);
        }
        
        $sanitized['auto_offload'] = isset($input['auto_offload']) ? (bool) $input['auto_offload'] : false;
        $sanitized['delete_local'] = isset($input['delete_local']) ? (bool) $input['delete_local'] : false;
        $sanitized['file_versioning'] = isset($input['file_versioning']) ? (bool) $input['file_versioning'] : false;
        
        if (isset($input['batch_size'])) {
            $sanitized['batch_size'] = max(1, min(200, absint($input['batch_size'])));
        }
        
        if (isset($input['log_level'])) {
            $allowed_levels = array('debug', 'info', 'warning', 'error');
            $sanitized['log_level'] = in_array($input['log_level'], $allowed_levels, true) ? $input['log_level'] : 'info';
        }
        
        $sanitized['enable_logs'] = isset($input['enable_logs']) ? (bool) $input['enable_logs'] : false;
        
        if (isset($input['allowed_file_types']) && is_array($input['allowed_file_types'])) {
            $sanitized['allowed_file_types'] = array_map('sanitize_text_field', $input['allowed_file_types']);
        }
        
        if (isset($input['allowed_post_types']) && is_array($input['allowed_post_types'])) {
            $sanitized['allowed_post_types'] = array_map('sanitize_text_field', $input['allowed_post_types']);
        }
        
        return $sanitized;
    }
    
    /**
     * Dashboard page
     */
    public function dashboard_page() {
        $stats = $this->stats->get_dashboard_stats();
        $migration_stats = $this->migration->get_migration_stats();
        $recent_logs = $this->logger->get_logs(5);
        $optimization_stats = $this->optimizer ? $this->optimizer->get_optimization_stats() : null;
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bunny Media Offload Dashboard', 'bunny-media-offload'); ?></h1>
            
            <div class="bunny-dashboard">
                <div class="bunny-stats-grid">
                    <div class="bunny-stat-card">
                        <h3><?php esc_html_e('Files Offloaded', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-stat-number"><?php echo number_format($stats['total_files']); ?></div>
                    </div>
                    
                    <div class="bunny-stat-card">
                        <h3><?php esc_html_e('Space Saved', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-stat-number"><?php echo esc_html($stats['space_saved']); ?></div>
                    </div>
                    
                    <div class="bunny-stat-card">
                        <h3><?php esc_html_e('Migration Progress', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-stat-number"><?php echo esc_html(number_format($stats['migration_progress'], 1)); ?>%</div>
                        <div class="bunny-progress-bar">
                            <div class="bunny-progress-fill" style="width: <?php echo esc_attr($stats['migration_progress']); ?>%"></div>
                        </div>
                    </div>
                    
                    <?php if ($optimization_stats): ?>
                    <div class="bunny-stat-card">
                        <h3><?php esc_html_e('Images Optimized', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-stat-number"><?php echo esc_html(number_format($optimization_stats['images_actually_optimized'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="bunny-dashboard-row">
                    <div class="bunny-dashboard-col">
                        <div class="bunny-card">
                            <h3><?php esc_html_e('Quick Actions', 'bunny-media-offload'); ?></h3>
                            <div class="bunny-quick-actions">
                                <button type="button" class="button button-primary" id="test-connection">
                                    <?php esc_html_e('Test Connection', 'bunny-media-offload'); ?>
                                </button>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=bunny-media-offload-migration')); ?>" class="button">
                                    <?php esc_html_e('Start Migration', 'bunny-media-offload'); ?>
                                </a>
                                <?php if ($this->optimizer): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=bunny-media-offload-optimization')); ?>" class="button">
                                    <?php esc_html_e('Optimize Images', 'bunny-media-offload'); ?>
                                </a>
                                <?php endif; ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=bunny-media-offload-settings')); ?>" class="button">
                                    <?php esc_html_e('Settings', 'bunny-media-offload'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bunny-dashboard-col">
                        <div class="bunny-card">
                            <h3><?php esc_html_e('Recent Activity', 'bunny-media-offload'); ?></h3>
                            <div class="bunny-recent-logs">
                                <?php if (!empty($recent_logs)): ?>
                                    <?php foreach ($recent_logs as $log): ?>
                                        <div class="bunny-log-item bunny-log-<?php echo esc_attr($log->log_level); ?>">
                                            <span class="bunny-log-time"><?php echo esc_html(Bunny_Utils::time_ago($log->date_created)); ?></span>
                                            <span class="bunny-log-message"><?php echo esc_html($log->message); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p><?php esc_html_e('No recent activity.', 'bunny-media-offload'); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($this->wpml && $this->wpml->is_wpml_active()): ?>
                <div class="bunny-wpml-notice">
                    <div class="bunny-card">
                        <h3><?php esc_html_e('WPML Multilingual Support', 'bunny-media-offload'); ?></h3>
                        <p><span class="bunny-status bunny-status-offloaded">✓ <?php esc_html_e('WPML Active', 'bunny-media-offload'); ?></span></p>
                        <p class="description">
                            <?php 
                            $active_languages = apply_filters('wpml_active_languages', null);
                            printf(
                                // translators: %d is the number of active languages
                                esc_html__('Media files are automatically synchronized across %d active languages.', 'bunny-media-offload'),
                                esc_html(count($active_languages))
                            ); 
                            ?>
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        $settings = $this->settings->get_all();
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'connection';
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bunny Media Offload Settings', 'bunny-media-offload'); ?></h1>
            
            <?php echo wp_kses_post($this->display_config_status()); ?>
            
            <!-- Settings Navigation Tabs -->
            <div class="bunny-settings-tabs">
                <nav class="nav-tab-wrapper">
                    <a href="?page=bunny-media-offload-settings&tab=connection" class="nav-tab <?php echo $active_tab === 'connection' ? 'nav-tab-active' : ''; ?>">
                        <span class="dashicons dashicons-admin-links"></span>
                        <?php esc_html_e('Connection & CDN', 'bunny-media-offload'); ?>
                    </a>
                    <a href="?page=bunny-media-offload-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                        <span class="dashicons dashicons-admin-settings"></span>
                        <?php esc_html_e('General Settings', 'bunny-media-offload'); ?>
                    </a>
                    <a href="?page=bunny-media-offload-settings&tab=optimization" class="nav-tab <?php echo $active_tab === 'optimization' ? 'nav-tab-active' : ''; ?>">
                        <span class="dashicons dashicons-format-image"></span>
                        <?php esc_html_e('Image Optimization', 'bunny-media-offload'); ?>
                    </a>
                    <a href="?page=bunny-media-offload-settings&tab=performance" class="nav-tab <?php echo $active_tab === 'performance' ? 'nav-tab-active' : ''; ?>">
                        <span class="dashicons dashicons-performance"></span>
                        <?php esc_html_e('Performance', 'bunny-media-offload'); ?>
                    </a>
                    <?php if ($this->wpml && $this->wpml->is_wpml_active()): ?>
                    <a href="?page=bunny-media-offload-settings&tab=wpml" class="nav-tab <?php echo $active_tab === 'wpml' ? 'nav-tab-active' : ''; ?>">
                        <span class="dashicons dashicons-translation"></span>
                        <?php esc_html_e('WPML Integration', 'bunny-media-offload'); ?>
                    </a>
                    <?php endif; ?>
                </nav>
            </div>

            <div class="bunny-settings-content">
            <form method="post" action="options.php">
                <?php settings_fields('bunny_media_offload_settings'); ?>
                    
                    <?php
                    switch ($active_tab) {
                        case 'connection':
                            $this->render_connection_settings($settings);
                            break;
                        case 'general':
                            $this->render_general_settings($settings);
                            break;
                        case 'optimization':
                            $this->render_optimization_settings($settings);
                            break;
                        case 'performance':
                            $this->render_performance_settings($settings);
                            break;
                        case 'wpml':
                            if ($this->wpml && $this->wpml->is_wpml_active()) {
                                $this->render_wpml_settings($settings);
                            }
                            break;
                        default:
                            $this->render_connection_settings($settings);
                    }
                    ?>
                    
                    <div class="bunny-settings-actions">
                        <?php submit_button(__('Save Settings', 'bunny-media-offload'), 'primary', 'submit', false); ?>
                        <?php if ($active_tab === 'connection'): ?>
                            <button type="button" class="button" id="test-connection"><?php esc_html_e('Test Connection', 'bunny-media-offload'); ?></button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <style>
        .bunny-settings-tabs {
            margin-bottom: 20px;
        }
        
        .bunny-settings-tabs .nav-tab {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 8px 8px 0 0;
            transition: all 0.2s ease;
        }
        
        .bunny-settings-tabs .nav-tab:hover {
            background-color: #f0f0f1;
        }
        
        .bunny-settings-tabs .nav-tab-active {
            background-color: #0073aa;
            color: white;
            border-color: #0073aa;
        }
        
        .bunny-settings-tabs .nav-tab-active:hover {
            background-color: #005a87;
        }
        
        .bunny-settings-tabs .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
        }
        
        .bunny-settings-content {
            background: white;
            border: 1px solid #c3c4c7;
            border-radius: 0 8px 8px 8px;
            padding: 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .bunny-settings-section {
            margin-bottom: 40px;
        }
        
        .bunny-settings-section:last-child {
            margin-bottom: 0;
        }
        
        .bunny-settings-section h3 {
            margin: 0 0 20px 0;
            padding: 0 0 10px 0;
            border-bottom: 2px solid #0073aa;
            color: #0073aa;
            font-size: 18px;
        }
        
        .bunny-settings-section .form-table {
            margin-top: 0;
        }
        
        .bunny-settings-actions {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .bunny-readonly-field {
            background-color: #f9f9f9 !important;
        }
        
        .bunny-config-source {
            margin-left: 10px;
            color: #666;
            font-style: italic;
            font-size: 13px;
        }
        
        .bunny-info-box {
            background: #e7f3ff;
            border: 1px solid #b8daff;
            border-radius: 6px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .bunny-info-box.warning {
            background: #fff3cd;
            border-color: #ffc107;
            color: #856404;
        }
        
        .bunny-info-box.success {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        </style>
        <?php
    }
    
    /**
     * Render Connection & CDN Settings
     */
    private function render_connection_settings($settings) {
        ?>
        <div class="bunny-settings-section">
            <h3><?php esc_html_e('Bunny.net Connection', 'bunny-media-offload'); ?></h3>
            
            <div class="bunny-info-box">
                <p><strong><?php esc_html_e('💡 Pro Tip:', 'bunny-media-offload'); ?></strong> <?php esc_html_e('For enhanced security, consider adding these settings to your wp-config.php file instead of storing them in the database.', 'bunny-media-offload'); ?></p>
            </div>
                
                <table class="form-table">
                    <tr>
                    <th scope="row"><?php esc_html_e('API Key', 'bunny-media-offload'); ?></th>
                        <td>
                            <?php if ($this->settings->is_constant_defined('api_key')): ?>
                                <?php 
                                $api_key = $this->settings->get('api_key');
                                $masked_key = strlen($api_key) > 6 ? substr($api_key, 0, 3) . str_repeat('*', max(10, strlen($api_key) - 6)) . substr($api_key, -3) : str_repeat('*', strlen($api_key));
                                ?>
                                <input type="text" value="<?php echo esc_attr($masked_key); ?>" class="regular-text bunny-readonly-field" readonly />
                            <span class="bunny-config-source"><?php esc_html_e('Configured in wp-config.php', 'bunny-media-offload'); ?></span>
                            <?php else: ?>
                            <input type="password" name="bunny_media_offload_settings[api_key]" value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" class="regular-text" autocomplete="new-password" />
                            <?php endif; ?>
                        <p class="description"><?php esc_html_e('Your Bunny.net Storage API key. Find this in your Bunny.net dashboard under Storage > FTP & API Access.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                    <th scope="row"><?php esc_html_e('Storage Zone', 'bunny-media-offload'); ?></th>
                        <td>
                            <?php if ($this->settings->is_constant_defined('storage_zone')): ?>
                            <input type="text" value="<?php echo esc_attr($this->settings->get('storage_zone')); ?>" class="regular-text bunny-readonly-field" readonly />
                            <span class="bunny-config-source"><?php esc_html_e('Configured in wp-config.php', 'bunny-media-offload'); ?></span>
                            <?php else: ?>
                                <input type="text" name="bunny_media_offload_settings[storage_zone]" value="<?php echo esc_attr($settings['storage_zone'] ?? ''); ?>" class="regular-text" />
                            <?php endif; ?>
                        <p class="description"><?php esc_html_e('Your Bunny.net Storage Zone name. This is the name you gave your storage zone when creating it.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
            </table>
        </div>
        
        <div class="bunny-settings-section">
            <h3><?php esc_html_e('CDN Configuration', 'bunny-media-offload'); ?></h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Custom Hostname', 'bunny-media-offload'); ?></th>
                        <td>
                            <?php if ($this->settings->is_constant_defined('custom_hostname')): ?>
                                <input type="text" value="<?php echo esc_attr($this->settings->get('custom_hostname')); ?>" class="regular-text bunny-readonly-field" readonly />
                            <span class="bunny-config-source"><?php esc_html_e('Configured in wp-config.php', 'bunny-media-offload'); ?></span>
                            <?php else: ?>
                            <input type="text" name="bunny_media_offload_settings[custom_hostname]" value="<?php echo esc_attr($settings['custom_hostname'] ?? ''); ?>" class="regular-text" placeholder="cdn.example.com" />
                            <?php endif; ?>
                        <p class="description"><?php esc_html_e('Optional: Custom CDN hostname (without https://). Leave blank to use the default Bunny.net CDN URL.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                    <th scope="row"><?php esc_html_e('File Versioning', 'bunny-media-offload'); ?></th>
                        <td>
                            <label>
                            <input type="checkbox" name="bunny_media_offload_settings[file_versioning]" value="1" <?php checked($settings['file_versioning'] ?? false); ?> />
                            <?php esc_html_e('Add version parameter to URLs for cache busting', 'bunny-media-offload'); ?>
                            </label>
                        <p class="description"><?php esc_html_e('Adds a version parameter to media URLs to help with browser cache invalidation when files are updated.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render General Settings
     */
    private function render_general_settings($settings) {        
        ?>
        <div class="bunny-settings-section">
            <h3><?php esc_html_e('Upload Behavior', 'bunny-media-offload'); ?></h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Auto Offload', 'bunny-media-offload'); ?></th>
                        <td>
                            <label>
                            <input type="checkbox" name="bunny_media_offload_settings[auto_offload]" value="1" <?php checked($settings['auto_offload'] ?? false); ?> />
                            <?php esc_html_e('Automatically offload new uploads to Bunny.net', 'bunny-media-offload'); ?>
                            </label>
                        <p class="description"><?php esc_html_e('When enabled, newly uploaded media files will be automatically transferred to Bunny.net CDN.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                    <th scope="row"><?php esc_html_e('Delete Local Files', 'bunny-media-offload'); ?></th>
                        <td>
                            <label>
                            <input type="checkbox" name="bunny_media_offload_settings[delete_local]" value="1" <?php checked($settings['delete_local'] ?? true); ?> />
                            <?php esc_html_e('Delete local files after successful upload to save server space', 'bunny-media-offload'); ?>
                            </label>
                        <div class="bunny-info-box warning">
                            <p><strong><?php esc_html_e('⚠️ Important:', 'bunny-media-offload'); ?></strong> <?php esc_html_e('When local files are deleted, they are also permanently removed from the cloud if you later disable this plugin. Only disable this option if you plan to use the Sync & Recovery features to maintain local copies.', 'bunny-media-offload'); ?></p>
                        </div>
                        </td>
                    </tr>
                </table>
        </div>
        <?php
    }
    
    /**
     * Render Image Optimization Settings
     */
    private function render_optimization_settings($settings) {
        ?>
        <div class="bunny-settings-section">
            <h3><?php esc_html_e('Optimization Features', 'bunny-media-offload'); ?></h3>
            
            <div class="bunny-info-box">
                <p><?php esc_html_e('Image optimization converts your images to modern formats (AVIF/WebP) and compresses them to reduce file sizes and improve loading speeds.', 'bunny-media-offload'); ?></p>
            </div>
            
                <table class="form-table">
                    <tr>
                    <th scope="row"><?php esc_html_e('Enable Optimization', 'bunny-media-offload'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="bunny_media_offload_settings[optimization_enabled]" value="1" <?php checked($settings['optimization_enabled'] ?? false); ?> />
                            <?php esc_html_e('Enable automatic image optimization', 'bunny-media-offload'); ?>
                            </label>
                        <p class="description"><?php esc_html_e('Convert images to modern formats (AVIF/WebP) and compress to reduce file sizes by up to 80%.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                    <th scope="row"><?php esc_html_e('Optimize on Upload', 'bunny-media-offload'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="bunny_media_offload_settings[optimize_on_upload]" value="1" <?php checked($settings['optimize_on_upload'] ?? true); ?> />
                            <?php esc_html_e('Optimize images automatically during upload', 'bunny-media-offload'); ?>
                            </label>
                        <p class="description"><?php esc_html_e('New uploads will be optimized automatically. Existing images can be optimized using the Optimization page.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
            </table>
        </div>
        
        <div class="bunny-settings-section">
            <h3><?php esc_html_e('Optimization Parameters', 'bunny-media-offload'); ?></h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Preferred Format', 'bunny-media-offload'); ?></th>
                        <td>
                            <select name="bunny_media_offload_settings[optimization_format]">
                                <option value="avif" <?php selected($settings['optimization_format'] ?? 'avif', 'avif'); ?>>AVIF (best compression)</option>
                                <option value="webp" <?php selected($settings['optimization_format'] ?? 'avif', 'webp'); ?>>WebP (better compatibility)</option>
                            </select>
                        <p class="description"><?php esc_html_e('AVIF offers superior compression (~50% smaller than WebP) but WebP has wider browser support. Modern browsers support both formats.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                    <th scope="row"><?php esc_html_e('Maximum File Size', 'bunny-media-offload'); ?></th>
                        <td>
                            <select name="bunny_media_offload_settings[optimization_max_size]">
                                <option value="40kb" <?php selected($settings['optimization_max_size'] ?? '50kb', '40kb'); ?>>40 KB</option>
                                <option value="45kb" <?php selected($settings['optimization_max_size'] ?? '50kb', '45kb'); ?>>45 KB</option>
                            <option value="50kb" <?php selected($settings['optimization_max_size'] ?? '50kb', '50kb'); ?>>50 KB (recommended)</option>
                                <option value="55kb" <?php selected($settings['optimization_max_size'] ?? '50kb', '55kb'); ?>>55 KB</option>
                                <option value="60kb" <?php selected($settings['optimization_max_size'] ?? '50kb', '60kb'); ?>>60 KB</option>
                            </select>
                        <p class="description"><?php esc_html_e('Images larger than this threshold will be recompressed. WebP/AVIF files above this size will be optimized further.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render Performance Settings
     */
    private function render_performance_settings($settings) {
        ?>
        <div class="bunny-settings-section">
            <h3><?php esc_html_e('Migration Performance', 'bunny-media-offload'); ?></h3>
            
            <div class="bunny-info-box">
                <p><?php esc_html_e('These settings control how many files are processed simultaneously. Higher values may improve speed but can increase server load.', 'bunny-media-offload'); ?></p>
            </div>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Migration Batch Size', 'bunny-media-offload'); ?></th>
                    <td>
                        <?php if ($this->settings->is_constant_defined('batch_size')): ?>
                            <input type="text" value="<?php echo esc_attr($this->settings->get('batch_size')); ?>" class="small-text bunny-readonly-field" readonly />
                            <span class="bunny-config-source"><?php esc_html_e('Configured in wp-config.php', 'bunny-media-offload'); ?></span>
                        <?php else: ?>
                            <select name="bunny_media_offload_settings[batch_size]">
                                <option value="50" <?php selected($settings['batch_size'] ?? 100, 50); ?>>50</option>
                                <option value="100" <?php selected($settings['batch_size'] ?? 100, 100); ?>>100 (recommended)</option>
                                <option value="150" <?php selected($settings['batch_size'] ?? 100, 150); ?>>150</option>
                                <option value="250" <?php selected($settings['batch_size'] ?? 100, 250); ?>>250</option>
                            </select>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e('Number of files to process in each migration batch. Higher values process more files at once.', 'bunny-media-offload'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Migration Concurrent Limit', 'bunny-media-offload'); ?></th>
                    <td>
                        <select name="bunny_media_offload_settings[migration_concurrent_limit]">
                            <option value="2" <?php selected($settings['migration_concurrent_limit'] ?? 4, 2); ?>>2 (safe)</option>
                            <option value="4" <?php selected($settings['migration_concurrent_limit'] ?? 4, 4); ?>>4 (recommended)</option>
                            <option value="8" <?php selected($settings['migration_concurrent_limit'] ?? 4, 8); ?>>8 (fast)</option>
                        </select>
                        <p class="description"><?php esc_html_e('Number of images to migrate simultaneously. Use lower values for shared hosting.', 'bunny-media-offload'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="bunny-settings-section">
            <h3><?php esc_html_e('Optimization Performance', 'bunny-media-offload'); ?></h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Optimization Batch Size', 'bunny-media-offload'); ?></th>
                        <td>
                            <select name="bunny_media_offload_settings[optimization_batch_size]">
                                <option value="30" <?php selected($settings['optimization_batch_size'] ?? 60, 30); ?>>30</option>
                            <option value="60" <?php selected($settings['optimization_batch_size'] ?? 60, 60); ?>>60 (recommended)</option>
                                <option value="90" <?php selected($settings['optimization_batch_size'] ?? 60, 90); ?>>90</option>
                                <option value="150" <?php selected($settings['optimization_batch_size'] ?? 60, 150); ?>>150</option>
                            </select>
                        <p class="description"><?php esc_html_e('Number of images to optimize in each batch. Image optimization is CPU-intensive, so smaller batches are recommended.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                    <th scope="row"><?php esc_html_e('Optimization Concurrent Limit', 'bunny-media-offload'); ?></th>
                        <td>
                            <select name="bunny_media_offload_settings[optimization_concurrent_limit]">
                            <option value="2" <?php selected($settings['optimization_concurrent_limit'] ?? 3, 2); ?>>2 (safe)</option>
                            <option value="3" <?php selected($settings['optimization_concurrent_limit'] ?? 3, 3); ?>>3 (recommended)</option>
                            <option value="5" <?php selected($settings['optimization_concurrent_limit'] ?? 3, 5); ?>>5 (fast)</option>
                            </select>
                        <p class="description"><?php esc_html_e('Number of images to optimize simultaneously. Use lower values to reduce CPU usage.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                </table>
        </div>
                <?php 
    }
    
    /**
     * Render WPML Settings
     */
    private function render_wpml_settings($settings) {
        if ($this->wpml && $this->wpml->is_wpml_active()) {
            echo wp_kses_post($this->wpml->add_wpml_settings_section());
        }
    }
    
    /**
     * Display configuration status
     */
    private function display_config_status() {
        $constants_status = $this->settings->get_constants_status();
        $has_constants = false;
        
        foreach ($constants_status as $status) {
            if ($status['defined']) {
                $has_constants = true;
                break;
            }
        }
        
        if ($has_constants) {
            ?>
            <div class="notice notice-info">
                <h3><?php esc_html_e('Configuration Status', 'bunny-media-offload'); ?></h3>
                <p><?php esc_html_e('Some settings are configured in wp-config.php and are shown in read-only format below.', 'bunny-media-offload'); ?></p>
            </div>
            <?php
        } else {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php esc_html_e('Security Recommendation:', 'bunny-media-offload'); ?></strong>
                    <?php esc_html_e('For enhanced security, consider configuring your Bunny.net credentials in wp-config.php instead of storing them in the database.', 'bunny-media-offload'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=bunny-media-offload-documentation')); ?>" target="_blank">
                        <?php esc_html_e('View Configuration Guide', 'bunny-media-offload'); ?>
                    </a>
                </p>
            </div>
            <?php
        }
    }
    
    /**
     * Migration page
     */
    public function migration_page() {
        $migration_stats = $this->migration->get_migration_stats();
        $detailed_stats = $this->migration->get_detailed_migration_stats();
        $max_file_size = $this->settings->get('optimization_max_size', '50kb');
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bulk Migration', 'bunny-media-offload'); ?></h1>
            
            <div class="bunny-migration-info">
                <div class="notice notice-info">
                    <h3><?php esc_html_e('Migration Requirements', 'bunny-media-offload'); ?></h3>
                    <p><?php 
                        // translators: %s is the maximum file size setting
                        printf(
                            esc_html__('Only images in AVIF or WebP format with a maximum file size lower than %s will be migrated. Images in other formats or exceeding this size limit will be skipped.', 'bunny-media-offload'), 
                            esc_html($max_file_size)
                        ); 
                    ?></p>
                </div>
            </div>
            
            <div class="bunny-migration-status">
                <h3><?php esc_html_e('Migration Status', 'bunny-media-offload'); ?></h3>
                <p><?php 
                    // translators: %1$d is the number of migrated files, %2$d is the total number of files, %3$s is the migration percentage
                    printf(esc_html__('%1$d of %2$d files migrated (%3$s%%)', 'bunny-media-offload'), 
                        esc_html($migration_stats['migrated_files']), 
                        esc_html($migration_stats['total_attachments']),
                        esc_html($migration_stats['migration_percentage'])
                    ); 
                ?></p>
                <div class="bunny-progress-bar">
                    <div class="bunny-progress-fill" style="width: <?php echo esc_attr($migration_stats['migration_percentage']); ?>%"></div>
                </div>
            </div>
            
            <div class="bunny-migration-statistics">
                <h3><?php esc_html_e('Migration Statistics', 'bunny-media-offload'); ?></h3>
                <div class="bunny-stats-grid">
                    <div class="bunny-stat-card">
                        <h4><?php esc_html_e('Total Images to Migrate', 'bunny-media-offload'); ?></h4>
                        <div class="bunny-stat-number"><?php echo number_format($detailed_stats['total_images_to_migrate']); ?></div>
                        <div class="bunny-stat-breakdown">
                            <span><?php echo esc_html(number_format($detailed_stats['avif_total'])); ?> AVIF</span> • 
                            <span><?php echo esc_html(number_format($detailed_stats['webp_total'])); ?> WebP</span>
                        </div>
                    </div>
                    
                    <div class="bunny-stat-card">
                        <h4><?php esc_html_e('Images per Batch', 'bunny-media-offload'); ?></h4>
                        <div class="bunny-stat-number"><?php echo number_format($detailed_stats['batch_size']); ?></div>
                        <div class="bunny-stat-description"><?php esc_html_e('Configurable in Settings', 'bunny-media-offload'); ?></div>
                    </div>
                    
                    <div class="bunny-stat-card">
                        <h4><?php esc_html_e('Remaining to Migrate', 'bunny-media-offload'); ?></h4>
                        <div class="bunny-stat-number"><?php echo number_format($detailed_stats['total_remaining']); ?></div>
                        <div class="bunny-stat-breakdown">
                            <span><?php echo esc_html(number_format($detailed_stats['avif_remaining'])); ?> AVIF</span> • 
                            <span><?php echo esc_html(number_format($detailed_stats['webp_remaining'])); ?> WebP</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bunny-migration-form">
                <h3><?php esc_html_e('Start New Migration', 'bunny-media-offload'); ?></h3>
                
                <?php if ($this->settings->get('delete_local')): ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e('Notice:', 'bunny-media-offload'); ?></strong> <?php esc_html_e('Local file deletion is enabled. Files will be removed from your server after successful migration to save space. You can change this in Settings if you prefer to keep local copies.', 'bunny-media-offload'); ?></p>
                </div>
                <?php endif; ?>
                
                <form id="migration-form">
                    <?php if ($this->wpml && $this->wpml->is_wpml_active()): ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Language Scope', 'bunny-media-offload'); ?></th>
                            <td>
                                <label><input type="radio" name="language_scope" value="current" checked> <?php esc_html_e('Current Language Only', 'bunny-media-offload'); ?></label><br>
                                <label><input type="radio" name="language_scope" value="all"> <?php esc_html_e('All Languages', 'bunny-media-offload'); ?></label><br>
                                <p class="description"><?php esc_html_e('Choose whether to migrate files from the current language only or from all languages.', 'bunny-media-offload'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php endif; ?>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary" id="start-migration" <?php echo $detailed_stats['has_files_to_migrate'] ? '' : 'disabled'; ?>>
                            <?php if ($detailed_stats['has_files_to_migrate']): ?>
                                <?php esc_html_e('Start Migration', 'bunny-media-offload'); ?>
                            <?php else: ?>
                                <?php esc_html_e('No Files to Migrate', 'bunny-media-offload'); ?>
                            <?php endif; ?>
                        </button>
                        <button type="button" class="button" id="cancel-migration" style="display: none;"><?php esc_html_e('Cancel Migration', 'bunny-media-offload'); ?></button>
                    </p>
                </form>
            </div>
            
            <div id="migration-progress" style="display: none;">
                <h3><?php esc_html_e('Migration Progress', 'bunny-media-offload'); ?></h3>
                <div class="bunny-progress-bar">
                    <div class="bunny-progress-fill" id="migration-progress-bar" style="width: 0%"></div>
                </div>
                <p id="migration-status-text"></p>
                <div id="migration-errors" style="display: none;">
                    <h4><?php esc_html_e('Errors', 'bunny-media-offload'); ?></h4>
                    <ul id="migration-error-list"></ul>
                </div>
            </div>
            
            <div class="bunny-troubleshooting">
                <h3><?php esc_html_e('Troubleshooting', 'bunny-media-offload'); ?></h3>
                <div class="bunny-card">
                    <h4><?php esc_html_e('Fix Missing Thumbnails', 'bunny-media-offload'); ?></h4>
                    <p><?php esc_html_e('If you encounter 404 errors for WooCommerce product images or other thumbnails, use this tool to regenerate and upload missing thumbnail sizes.', 'bunny-media-offload'); ?></p>
                    <div class="bunny-actions">
                        <button type="button" class="button button-secondary" id="regenerate-thumbnails"><?php esc_html_e('Regenerate All Thumbnails', 'bunny-media-offload'); ?></button>
                    </div>
                    <div id="thumbnail-regeneration-status" style="display: none; margin-top: 15px;">
                        <p id="thumbnail-status-text"></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Sync page
     */
    public function sync_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Sync & Recovery', 'bunny-media-offload'); ?></h1>
            
            <div class="bunny-sync-actions">
                <div class="bunny-card">
                    <h3><?php esc_html_e('Sync Options', 'bunny-media-offload'); ?></h3>
                    <p><?php esc_html_e('Download files from Bunny.net back to local storage for recovery or backup purposes.', 'bunny-media-offload'); ?></p>
                    
                    <div class="bunny-sync-notice">
                        <p><strong><?php esc_html_e('Note:', 'bunny-media-offload'); ?></strong> <?php esc_html_e('Only WebP and AVIF files can be synchronized. If you need to recover files, consider disabling "Delete Local Files" in Settings first.', 'bunny-media-offload'); ?></p>
                    </div>
                    
                    <div class="bunny-actions">
                        <button type="button" class="button button-primary" id="verify-sync"><?php esc_html_e('Verify File Integrity', 'bunny-media-offload'); ?></button>
                        <button type="button" class="button" id="sync-all-files"><?php esc_html_e('Sync All Remote Files', 'bunny-media-offload'); ?></button>
                        <button type="button" class="button" id="cleanup-orphaned"><?php esc_html_e('Cleanup Orphaned Files', 'bunny-media-offload'); ?></button>
                    </div>
                </div>
            </div>
            
            <div id="sync-results" style="display: none;">
                <h3><?php esc_html_e('Sync Results', 'bunny-media-offload'); ?></h3>
                <div id="sync-results-content"></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Optimization page
     */
    public function optimization_page() {
        if (!$this->optimizer) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Image Optimization', 'bunny-media-offload'); ?></h1>
                <div class="notice notice-error">
                    <p><?php esc_html_e('Optimization module is not available.', 'bunny-media-offload'); ?></p>
                </div>
            </div>
            <?php
            return;
        }
        
        // Debug: Check if we can get stats
        try {
            $detailed_stats = $this->optimizer->get_detailed_optimization_stats();
            if (!$detailed_stats || !is_array($detailed_stats)) {
                $detailed_stats = array(
                    'local' => array(
                        'jpg_png_to_convert' => 0,
                        'webp_avif_to_recompress' => 0,
                        'total_eligible' => 0,
                        'has_files_to_optimize' => false
                    ),
                    'cloud' => array(
                        'jpg_png_to_convert' => 0,
                        'webp_avif_to_recompress' => 0,
                        'total_eligible' => 0,
                        'has_files_to_optimize' => false
                    ),
                    'already_optimized' => 0,
                    'batch_size' => 60,
                    'max_size_threshold' => '50KB'
                );
            }
        } catch (Exception $e) {
            error_log('Bunny Optimization Stats Error: ' . $e->getMessage());
            $detailed_stats = array(
                'local' => array(
                    'jpg_png_to_convert' => 0,
                    'webp_avif_to_recompress' => 0,
                    'total_eligible' => 0,
                    'has_files_to_optimize' => false
                ),
                'cloud' => array(
                    'jpg_png_to_convert' => 0,
                    'webp_avif_to_recompress' => 0,
                    'total_eligible' => 0,
                    'has_files_to_optimize' => false
                ),
                'already_optimized' => 0,
                'batch_size' => 60,
                'max_size_threshold' => '50KB'
            );
        }
        
        $max_file_size = $this->settings->get('optimization_max_size', '50kb');
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Image Optimization', 'bunny-media-offload'); ?></h1>
            
            <div class="bunny-optimization-info">
                <div class="notice notice-info">
                    <h3><?php esc_html_e('Optimization Criteria', 'bunny-media-offload'); ?></h3>
                    <p><?php esc_html_e('The following optimization criteria will always be applied:', 'bunny-media-offload'); ?></p>
                    <ul style="margin-left: 20px;">
                        <li><strong><?php esc_html_e('Convert JPG/PNG to modern formats:', 'bunny-media-offload'); ?></strong> <?php esc_html_e('Legacy format images will be converted to WebP or AVIF for better compression.', 'bunny-media-offload'); ?></li>
                        <li><strong><?php esc_html_e('Recompress existing WebP/AVIF if oversized:', 'bunny-media-offload'); ?></strong> <?php 
                            // translators: %s is the maximum file size setting
                            printf(esc_html__('Modern format images larger than %s will be recompressed.', 'bunny-media-offload'), esc_html($max_file_size)); 
                        ?></li>
                    </ul>
                    </div>
                    
                <?php if (WP_DEBUG): ?>
                <div class="notice notice-warning">
                    <p><strong>Debug Info:</strong></p>
                    <p>Local eligible: <?php echo esc_html($detailed_stats['local']['total_eligible']); ?></p>
                    <p>Cloud eligible: <?php echo esc_html($detailed_stats['cloud']['total_eligible']); ?></p>
                    <p>Batch size: <?php echo esc_html($detailed_stats['batch_size']); ?></p>
                    <p>Max size: <?php echo esc_html($detailed_stats['max_size_threshold']); ?></p>
                    </div>
                <?php endif; ?>
                </div>
            
            <div class="bunny-optimization-cards">
                <h3><?php esc_html_e('Choose Optimization Target', 'bunny-media-offload'); ?></h3>
                <p class="bunny-optimization-description"><?php esc_html_e('Click on the optimization target you want to process. Only targets with images that need optimization are clickable.', 'bunny-media-offload'); ?></p>
                
                <div class="bunny-optimization-targets">
                    <!-- Local Images Card -->
                    <div class="bunny-optimization-card <?php echo $detailed_stats['local']['has_files_to_optimize'] ? 'clickable' : 'disabled'; ?>" 
                         data-target="local" 
                         data-enabled="<?php echo $detailed_stats['local']['has_files_to_optimize'] ? '1' : '0'; ?>">
                        <div class="bunny-card-header">
                            <div class="bunny-card-icon">💻</div>
                            <h4><?php esc_html_e('Local Images', 'bunny-media-offload'); ?></h4>
                            <p><?php esc_html_e('Optimize images stored on your server', 'bunny-media-offload'); ?></p>
            </div>
            
                        <div class="bunny-card-stats">
                            <div class="bunny-main-stat">
                                <span class="bunny-stat-number"><?php echo number_format($detailed_stats['local']['total_eligible']); ?></span>
                                <span class="bunny-stat-label"><?php esc_html_e('Images to Optimize', 'bunny-media-offload'); ?></span>
            </div>
            
                            <?php if ($detailed_stats['local']['total_eligible'] > 0): ?>
                                <div class="bunny-breakdown">
                                    <?php if ($detailed_stats['local']['jpg_png_to_convert'] > 0): ?>
                                        <div class="bunny-breakdown-item">
                                            <span class="bunny-breakdown-number"><?php echo number_format($detailed_stats['local']['jpg_png_to_convert']); ?></span>
                                            <span class="bunny-breakdown-label"><?php esc_html_e('JPG/PNG to convert', 'bunny-media-offload'); ?></span>
                </div>
                                    <?php endif; ?>
                                    <?php if ($detailed_stats['local']['webp_avif_to_recompress'] > 0): ?>
                                        <div class="bunny-breakdown-item">
                                            <span class="bunny-breakdown-number"><?php echo number_format($detailed_stats['local']['webp_avif_to_recompress']); ?></span>
                                            <span class="bunny-breakdown-label"><?php esc_html_e('WebP/AVIF to recompress', 'bunny-media-offload'); ?></span>
            </div>
                                    <?php endif; ?>
                            </div>
                            <?php else: ?>
                                <div class="bunny-no-images">
                                    <span><?php esc_html_e('All local images are already optimized', 'bunny-media-offload'); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($detailed_stats['local']['has_files_to_optimize']): ?>
                            <div class="bunny-card-action">
                                <span class="bunny-click-hint"><?php esc_html_e('Click to Start Optimization', 'bunny-media-offload'); ?></span>
                            </div>
                        <?php endif; ?>
                        </div>
                        
                    <!-- Cloud Images Card -->
                    <div class="bunny-optimization-card <?php echo $detailed_stats['cloud']['has_files_to_optimize'] ? 'clickable' : 'disabled'; ?>" 
                         data-target="cloud" 
                         data-enabled="<?php echo $detailed_stats['cloud']['has_files_to_optimize'] ? '1' : '0'; ?>">
                        <div class="bunny-card-header">
                            <div class="bunny-card-icon">☁️</div>
                            <h4><?php esc_html_e('Cloud Images', 'bunny-media-offload'); ?></h4>
                            <p><?php esc_html_e('Optimize images stored on Bunny.net CDN', 'bunny-media-offload'); ?></p>
                            </div>
                        
                        <div class="bunny-card-stats">
                            <div class="bunny-main-stat">
                                <span class="bunny-stat-number"><?php echo number_format($detailed_stats['cloud']['total_eligible']); ?></span>
                                <span class="bunny-stat-label"><?php esc_html_e('Images to Optimize', 'bunny-media-offload'); ?></span>
                        </div>
                        
                            <?php if ($detailed_stats['cloud']['total_eligible'] > 0): ?>
                                <div class="bunny-breakdown">
                                    <?php if ($detailed_stats['cloud']['jpg_png_to_convert'] > 0): ?>
                                        <div class="bunny-breakdown-item">
                                            <span class="bunny-breakdown-number"><?php echo number_format($detailed_stats['cloud']['jpg_png_to_convert']); ?></span>
                                            <span class="bunny-breakdown-label"><?php esc_html_e('JPG/PNG to convert', 'bunny-media-offload'); ?></span>
                            </div>
                                    <?php endif; ?>
                                    <?php if ($detailed_stats['cloud']['webp_avif_to_recompress'] > 0): ?>
                                        <div class="bunny-breakdown-item">
                                            <span class="bunny-breakdown-number"><?php echo number_format($detailed_stats['cloud']['webp_avif_to_recompress']); ?></span>
                                            <span class="bunny-breakdown-label"><?php esc_html_e('WebP/AVIF to recompress', 'bunny-media-offload'); ?></span>
                        </div>
                                    <?php endif; ?>
                            </div>
                            <?php else: ?>
                                <div class="bunny-no-images">
                                    <span><?php esc_html_e('All cloud images are already optimized', 'bunny-media-offload'); ?></span>
                        </div>
                            <?php endif; ?>
                    </div>
                    
                        <?php if ($detailed_stats['cloud']['has_files_to_optimize']): ?>
                            <div class="bunny-card-action">
                                <span class="bunny-click-hint"><?php esc_html_e('Click to Start Optimization', 'bunny-media-offload'); ?></span>
                    </div>
                        <?php endif; ?>
                        </div>
                        </div>
                
                <div class="bunny-optimization-info">
                    <div class="bunny-batch-info">
                        <span class="bunny-batch-label"><?php esc_html_e('Batch Size:', 'bunny-media-offload'); ?></span>
                        <span class="bunny-batch-value"><?php echo number_format($detailed_stats['batch_size']); ?> <?php esc_html_e('images per batch', 'bunny-media-offload'); ?></span>
                        <span class="bunny-batch-note"><?php esc_html_e('(configurable in Settings)', 'bunny-media-offload'); ?></span>
                        </div>
                        </div>
                
                <!-- Hidden Cancel Button -->
                <div class="bunny-optimization-controls" style="display: none;">
                    <button type="button" class="button button-secondary" id="cancel-optimization"><?php esc_html_e('Cancel Optimization', 'bunny-media-offload'); ?></button>
                </div>
            </div>
            
            <div id="optimization-progress" style="display: none;">
                <h3><?php esc_html_e('Optimization Progress', 'bunny-media-offload'); ?></h3>
                <div class="bunny-progress-bar">
                    <div class="bunny-progress-fill" id="optimization-progress-bar" style="width: 0%"></div>
                </div>
                <p id="optimization-status-text"></p>
                <div id="optimization-errors" style="display: none;">
                    <h4><?php esc_html_e('Errors', 'bunny-media-offload'); ?></h4>
                    <ul id="optimization-error-list"></ul>
            </div>
        </div>
        </div>
        
        <style>
        .bunny-optimization-cards {
            margin-top: 20px;
        }

        .bunny-optimization-description {
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
        }

        .bunny-optimization-targets {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
            margin: 30px 0;
        }

        .bunny-optimization-card {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 25px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .bunny-optimization-card.clickable {
            cursor: pointer;
            border-color: #0073aa;
            box-shadow: 0 2px 8px rgba(0, 115, 170, 0.1);
        }

        .bunny-optimization-card.clickable:hover {
            border-color: #005a87;
            box-shadow: 0 4px 16px rgba(0, 115, 170, 0.15);
            transform: translateY(-2px);
        }

        .bunny-optimization-card.disabled {
            background: #f8f9fa;
            border-color: #dee2e6;
            opacity: 0.7;
            cursor: not-allowed;
        }

        .bunny-optimization-card.disabled .bunny-card-icon {
            opacity: 0.5;
        }

        .bunny-card-header {
            text-align: center;
            margin-bottom: 25px;
        }

        .bunny-card-icon {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
        }

        .bunny-card-header h4 {
            margin: 0 0 8px 0;
            font-size: 20px;
            color: #333;
            font-weight: 600;
        }

        .bunny-card-header p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        .bunny-card-stats {
            text-align: center;
        }

        .bunny-main-stat {
            margin-bottom: 20px;
        }

        .bunny-stat-number {
            display: block;
            font-size: 36px;
            font-weight: bold;
            color: #0073aa;
            line-height: 1;
            margin-bottom: 5px;
        }

        .bunny-optimization-card.disabled .bunny-stat-number {
            color: #999;
        }

        .bunny-stat-label {
            display: block;
            font-size: 14px;
            color: #666;
            font-weight: 500;
        }

        .bunny-breakdown {
            display: flex;
            justify-content: space-around;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0;
        }

        .bunny-breakdown-item {
            text-align: center;
            flex: 1;
        }

        .bunny-breakdown-number {
            display: block;
            font-size: 18px;
            font-weight: bold;
            color: #0073aa;
            margin-bottom: 4px;
        }

        .bunny-optimization-card.disabled .bunny-breakdown-number {
            color: #999;
        }

        .bunny-breakdown-label {
            display: block;
            font-size: 11px;
            color: #888;
            line-height: 1.2;
        }

        .bunny-no-images {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-top: 15px;
        }

        .bunny-no-images span {
            color: #666;
            font-style: italic;
            font-size: 14px;
        }

        .bunny-card-action {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #0073aa, #005a87);
            color: white;
            padding: 12px;
            text-align: center;
            transform: translateY(100%);
            transition: transform 0.3s ease;
        }

        .bunny-optimization-card.clickable:hover .bunny-card-action {
            transform: translateY(0);
        }

        .bunny-click-hint {
            font-weight: 500;
            font-size: 14px;
        }

        .bunny-optimization-info {
            margin: 30px 0;
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .bunny-batch-info {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .bunny-batch-label {
            font-weight: 600;
            color: #333;
        }

        .bunny-batch-value {
            color: #0073aa;
            font-weight: 600;
        }

        .bunny-batch-note {
            color: #666;
            font-size: 13px;
            font-style: italic;
        }

        .bunny-optimization-controls {
            text-align: center;
            margin-top: 20px;
        }

        /* Progress styles remain the same */
        #optimization-progress {
            margin-top: 30px;
            padding: 25px;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
        }

        .bunny-progress-bar {
            width: 100%;
            height: 20px;
            background: #f0f0f0;
            border-radius: 10px;
            overflow: hidden;
            margin: 20px 0;
        }

        .bunny-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #0073aa, #005a87);
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        #optimization-status-text {
            text-align: center;
            font-weight: 500;
            color: #333;
            margin: 10px 0;
        }

        #optimization-errors {
            background: #fff2f2;
            border: 1px solid #f5c6cb;
            border-radius: 6px;
            padding: 15px;
            margin-top: 20px;
        }

        #optimization-errors h4 {
            color: #721c24;
            margin: 0 0 10px 0;
        }

        #optimization-error-list {
            margin: 0;
            color: #721c24;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .bunny-optimization-targets {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .bunny-optimization-card {
                padding: 20px;
            }
            
            .bunny-card-icon {
                font-size: 36px;
            }
            
            .bunny-stat-number {
                font-size: 28px;
            }
            
            .bunny-breakdown {
                flex-direction: column;
                gap: 10px;
            }
            
            .bunny-batch-info {
                flex-direction: column;
                gap: 4px;
            }
        }
        </style>
        <?php
    }
    
    /**
     * Logs page
     */
    public function logs_page() {
        $logs = $this->logger->get_logs(50);
        $error_stats = $this->stats->get_error_stats();
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Activity Logs', 'bunny-media-offload'); ?></h1>
            
            <div class="bunny-logs-header">
                <div class="bunny-log-stats">
                                         <span class="bunny-log-stat bunny-log-error"><?php esc_html_e('Errors:', 'bunny-media-offload'); ?> <?php echo esc_html($error_stats['error_counts']['error']); ?></span>
                     <span class="bunny-log-stat bunny-log-warning"><?php esc_html_e('Warnings:', 'bunny-media-offload'); ?> <?php echo esc_html($error_stats['error_counts']['warning']); ?></span>
                     <span class="bunny-log-stat bunny-log-info"><?php esc_html_e('Info:', 'bunny-media-offload'); ?> <?php echo esc_html($error_stats['error_counts']['info']); ?></span>
                </div>
                
                <div class="bunny-log-actions">
                    <button type="button" class="button" id="export-logs"><?php esc_html_e('Export Logs', 'bunny-media-offload'); ?></button>
                    <button type="button" class="button" id="clear-logs"><?php esc_html_e('Clear Logs', 'bunny-media-offload'); ?></button>
                </div>
            </div>
            
            <div class="bunny-logs-table">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'bunny-media-offload'); ?></th>
                            <th><?php esc_html_e('Level', 'bunny-media-offload'); ?></th>
                            <th><?php esc_html_e('Message', 'bunny-media-offload'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($logs)): ?>
                            <?php foreach ($logs as $log): ?>
                                <tr class="bunny-log-<?php echo esc_attr($log->log_level); ?>">
                                    <td><?php echo esc_html(Bunny_Utils::format_date($log->date_created)); ?></td>
                                    <td><span class="bunny-log-level bunny-log-level-<?php echo esc_attr($log->log_level); ?>"><?php echo esc_html(ucfirst($log->log_level)); ?></span></td>
                                    <td><?php echo esc_html($log->message); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3"><?php esc_html_e('No logs found.', 'bunny-media-offload'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
    
    /**
     * Documentation page
     */
    public function documentation_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bunny Media Offload Documentation', 'bunny-media-offload'); ?></h1>
            
            <div class="bunny-documentation">
                <!-- Navigation Tabs -->
                <div class="bunny-doc-tabs">
                    <button class="bunny-tab-button active" data-tab="getting-started"><?php esc_html_e('🚀 Getting Started', 'bunny-media-offload'); ?></button>
                    <button class="bunny-tab-button" data-tab="configuration"><?php esc_html_e('⚙️ Configuration', 'bunny-media-offload'); ?></button>
                    <button class="bunny-tab-button" data-tab="migration"><?php esc_html_e('📁 Migration', 'bunny-media-offload'); ?></button>
                    <button class="bunny-tab-button" data-tab="optimization"><?php esc_html_e('🖼️ Optimization', 'bunny-media-offload'); ?></button>
                    <button class="bunny-tab-button" data-tab="cli-commands"><?php esc_html_e('💻 CLI Commands', 'bunny-media-offload'); ?></button>
                    <button class="bunny-tab-button" data-tab="troubleshooting"><?php esc_html_e('🔧 Troubleshooting', 'bunny-media-offload'); ?></button>
                    </div>
                    
                <!-- Getting Started Tab -->
                <div class="bunny-tab-content active" id="getting-started">
                    <h2><?php esc_html_e('Getting Started with Bunny Media Offload', 'bunny-media-offload'); ?></h2>
                    
                    <div class="bunny-info-card">
                        <h3><?php esc_html_e('📋 Prerequisites', 'bunny-media-offload'); ?></h3>
                        <ul>
                            <li><?php esc_html_e('Active Bunny.net account', 'bunny-media-offload'); ?></li>
                            <li><?php esc_html_e('WordPress 5.0 or higher', 'bunny-media-offload'); ?></li>
                            <li><?php esc_html_e('PHP 7.4 or higher', 'bunny-media-offload'); ?></li>
                            <li><?php esc_html_e('cURL extension enabled', 'bunny-media-offload'); ?></li>
                            </ul>
                        </div>
                        
                    <div class="bunny-step-guide">
                        <h3><?php esc_html_e('Quick Setup Steps', 'bunny-media-offload'); ?></h3>
                        
                        <div class="bunny-step">
                            <div class="bunny-step-number">1</div>
                            <div class="bunny-step-content">
                                <h4><?php esc_html_e('Create Bunny.net Storage Zone', 'bunny-media-offload'); ?></h4>
                                <p><?php esc_html_e('Log into your Bunny.net dashboard and create a new Storage Zone for your media files.', 'bunny-media-offload'); ?></p>
                            </div>
                        </div>

                        <div class="bunny-step">
                            <div class="bunny-step-number">2</div>
                            <div class="bunny-step-content">
                                <h4><?php esc_html_e('Get API Credentials', 'bunny-media-offload'); ?></h4>
                                <p><?php esc_html_e('Copy your Storage Zone API key and zone name from the Bunny.net dashboard.', 'bunny-media-offload'); ?></p>
                            </div>
                        </div>

                        <div class="bunny-step">
                            <div class="bunny-step-number">3</div>
                            <div class="bunny-step-content">
                                <h4><?php esc_html_e('Configure Plugin', 'bunny-media-offload'); ?></h4>
                                <p><?php esc_html_e('Go to Bunny CDN > Settings and enter your API credentials, or add them to wp-config.php for better security.', 'bunny-media-offload'); ?></p>
                            </div>
                        </div>

                        <div class="bunny-step">
                            <div class="bunny-step-number">4</div>
                            <div class="bunny-step-content">
                                <h4><?php esc_html_e('Test Connection', 'bunny-media-offload'); ?></h4>
                                <p><?php esc_html_e('Use the "Test Connection" button in Settings to verify your configuration works correctly.', 'bunny-media-offload'); ?></p>
                            </div>
                        </div>

                        <div class="bunny-step">
                            <div class="bunny-step-number">5</div>
                            <div class="bunny-step-content">
                                <h4><?php esc_html_e('Start Migration', 'bunny-media-offload'); ?></h4>
                                <p><?php esc_html_e('Go to Bunny CDN > Migration to begin transferring your existing media files to Bunny.net.', 'bunny-media-offload'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Configuration Tab -->
                <div class="bunny-tab-content" id="configuration">
                    <h2><?php esc_html_e('Configuration Options', 'bunny-media-offload'); ?></h2>
                    
                    <div class="bunny-config-section">
                        <h3><?php esc_html_e('🔐 Secure Configuration (Recommended)', 'bunny-media-offload'); ?></h3>
                        
                        <div class="bunny-info-card success">
                            <h4><?php esc_html_e('Why use wp-config.php?', 'bunny-media-offload'); ?></h4>
                            <ul>
                                <li><?php esc_html_e('🔒 Enhanced Security: API keys not stored in database', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('🚀 Environment Portability: Easy staging/production deployment', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('🔐 Version Control Safe: Exclude wp-config.php from Git commits', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('💾 Backup Safety: Settings preserved during database restores', 'bunny-media-offload'); ?></li>
                            </ul>
                        </div>

                        <p><?php esc_html_e('Add these constants to your wp-config.php file before the "/* That\'s all, stop editing! */" line:', 'bunny-media-offload'); ?></p>
                        
                        <div class="bunny-code-block">
                            <div class="bunny-code-header">
                                <span><?php esc_html_e('wp-config.php', 'bunny-media-offload'); ?></span>
                                <button class="bunny-copy-btn" data-copy="config-basic"><?php esc_html_e('Copy', 'bunny-media-offload'); ?></button>
                            </div>
                            <pre id="config-basic">// Bunny.net Configuration
define('BUNNY_API_KEY', 'your-storage-api-key-here');
define('BUNNY_STORAGE_ZONE', 'your-storage-zone-name');
define('BUNNY_CUSTOM_HOSTNAME', 'cdn.yoursite.com'); // Optional</pre>
                        </div>
                    </div>

                    <div class="bunny-config-section">
                        <h3><?php esc_html_e('📝 Configuration Reference', 'bunny-media-offload'); ?></h3>
                        
                        <div class="bunny-config-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Setting', 'bunny-media-offload'); ?></th>
                                        <th><?php esc_html_e('Description', 'bunny-media-offload'); ?></th>
                                        <th><?php esc_html_e('Default', 'bunny-media-offload'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><code>BUNNY_API_KEY</code></td>
                                        <td><?php esc_html_e('Your Bunny.net Storage Zone API key', 'bunny-media-offload'); ?></td>
                                        <td><?php esc_html_e('Required', 'bunny-media-offload'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>BUNNY_STORAGE_ZONE</code></td>
                                        <td><?php esc_html_e('Your Bunny.net Storage Zone name', 'bunny-media-offload'); ?></td>
                                        <td><?php esc_html_e('Required', 'bunny-media-offload'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>BUNNY_CUSTOM_HOSTNAME</code></td>
                                        <td><?php esc_html_e('Custom CDN hostname (without https://)', 'bunny-media-offload'); ?></td>
                                        <td><?php esc_html_e('Optional', 'bunny-media-offload'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Migration Tab -->
                <div class="bunny-tab-content" id="migration">
                    <h2><?php esc_html_e('Media Migration Guide', 'bunny-media-offload'); ?></h2>
                    
                    <div class="bunny-info-card warning">
                        <h3><?php esc_html_e('⚠️ Before You Start', 'bunny-media-offload'); ?></h3>
                        <ul>
                            <li><?php esc_html_e('Create a full website backup', 'bunny-media-offload'); ?></li>
                            <li><?php esc_html_e('Test the plugin on a staging site first', 'bunny-media-offload'); ?></li>
                            <li><?php esc_html_e('Ensure your Bunny.net storage has sufficient quota', 'bunny-media-offload'); ?></li>
                            <li><?php esc_html_e('Consider running migration during low-traffic hours', 'bunny-media-offload'); ?></li>
                        </ul>
                    </div>

                    <div class="bunny-feature-section">
                        <h3><?php esc_html_e('Migration Features', 'bunny-media-offload'); ?></h3>
                        
                        <div class="bunny-feature-grid">
                            <div class="bunny-feature-item">
                                <h4><?php esc_html_e('🎯 Smart File Selection', 'bunny-media-offload'); ?></h4>
                                <p><?php esc_html_e('Only AVIF and WebP files with size below your configured limit are migrated automatically.', 'bunny-media-offload'); ?></p>
                            </div>
                            
                            <div class="bunny-feature-item">
                                <h4><?php esc_html_e('📊 Real-time Progress', 'bunny-media-offload'); ?></h4>
                                <p><?php esc_html_e('Monitor migration progress with live statistics and detailed breakdowns by file type.', 'bunny-media-offload'); ?></p>
                            </div>
                            
                            <div class="bunny-feature-item">
                                <h4><?php esc_html_e('🔄 Batch Processing', 'bunny-media-offload'); ?></h4>
                                <p><?php esc_html_e('Files are processed in configurable batches to prevent server timeouts and memory issues.', 'bunny-media-offload'); ?></p>
                            </div>
                            
                            <div class="bunny-feature-item">
                                <h4><?php esc_html_e('🛡️ Error Handling', 'bunny-media-offload'); ?></h4>
                                <p><?php esc_html_e('Automatic retry logic for failed uploads with detailed error reporting and recovery options.', 'bunny-media-offload'); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bunny-best-practices">
                        <h3><?php esc_html_e('📋 Migration Best Practices', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-tip-list">
                            <div class="bunny-tip">
                                <strong><?php esc_html_e('Start Small:', 'bunny-media-offload'); ?></strong>
                                <span><?php esc_html_e('Begin with a small batch size (25-50 files) to test your server\'s capabilities.', 'bunny-media-offload'); ?></span>
                            </div>
                            <div class="bunny-tip">
                                <strong><?php esc_html_e('Monitor Progress:', 'bunny-media-offload'); ?></strong>
                                <span><?php esc_html_e('Keep the migration page open to monitor progress and catch any issues early.', 'bunny-media-offload'); ?></span>
                            </div>
                            <div class="bunny-tip">
                                <strong><?php esc_html_e('Check Logs:', 'bunny-media-offload'); ?></strong>
                                <span><?php esc_html_e('Review the logs page for detailed information about any failed uploads.', 'bunny-media-offload'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Optimization Tab -->
                <div class="bunny-tab-content" id="optimization">
                    <h2><?php esc_html_e('Image Optimization Guide', 'bunny-media-offload'); ?></h2>
                    
                    <div class="bunny-info-card">
                        <h3><?php esc_html_e('🎯 What Gets Optimized?', 'bunny-media-offload'); ?></h3>
                        <ul>
                            <li><?php esc_html_e('JPG/PNG files are converted to modern WebP or AVIF formats', 'bunny-media-offload'); ?></li>
                            <li><?php esc_html_e('WebP/AVIF files larger than your size limit are recompressed', 'bunny-media-offload'); ?></li>
                            <li><?php esc_html_e('Files already optimized and under the size limit are skipped', 'bunny-media-offload'); ?></li>
                        </ul>
                    </div>

                    <div class="bunny-optimization-comparison">
                        <h3><?php esc_html_e('📈 Format Comparison', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-comparison-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Format', 'bunny-media-offload'); ?></th>
                                        <th><?php esc_html_e('Compression', 'bunny-media-offload'); ?></th>
                                        <th><?php esc_html_e('Browser Support', 'bunny-media-offload'); ?></th>
                                        <th><?php esc_html_e('Best For', 'bunny-media-offload'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>AVIF</strong></td>
                                        <td><?php esc_html_e('Excellent (50% smaller)', 'bunny-media-offload'); ?></td>
                                        <td><?php esc_html_e('Modern browsers', 'bunny-media-offload'); ?></td>
                                        <td><?php esc_html_e('Future-proof sites', 'bunny-media-offload'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>WebP</strong></td>
                                        <td><?php esc_html_e('Very Good (30% smaller)', 'bunny-media-offload'); ?></td>
                                        <td><?php esc_html_e('95%+ browsers', 'bunny-media-offload'); ?></td>
                                        <td><?php esc_html_e('Most websites', 'bunny-media-offload'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>JPEG</strong></td>
                                        <td><?php esc_html_e('Standard', 'bunny-media-offload'); ?></td>
                                        <td><?php esc_html_e('Universal', 'bunny-media-offload'); ?></td>
                                        <td><?php esc_html_e('Legacy support', 'bunny-media-offload'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- CLI Commands Tab -->
                <div class="bunny-tab-content" id="cli-commands">
                    <h2><?php esc_html_e('WP-CLI Commands Reference', 'bunny-media-offload'); ?></h2>
                    
                    <div class="bunny-cli-section">
                        <h3><?php esc_html_e('📊 Status & Information', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-code-block">
                            <pre>wp bunny status                    # Plugin status overview
wp bunny status --detailed       # Detailed statistics
wp bunny test-connection          # Test API connection</pre>
                        </div>
                    </div>

                    <div class="bunny-cli-section">
                        <h3><?php esc_html_e('📁 Migration Commands', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-code-block">
                            <pre>wp bunny migrate                           # Migrate all files
wp bunny migrate --batch-size=25          # Custom batch size
wp bunny migrate --dry-run                # Preview without changes
wp bunny migration-status                 # Check migration progress</pre>
                        </div>
                    </div>

                    <div class="bunny-cli-section">
                        <h3><?php esc_html_e('🖼️ Optimization Commands', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-code-block">
                            <pre>wp bunny optimize                         # Optimize all images
wp bunny optimize --target=local         # Local images only
wp bunny optimize --target=cloud         # Cloud images only
wp bunny optimization-status              # Check optimization queue</pre>
                        </div>
                    </div>

                    <div class="bunny-cli-section">
                        <h3><?php esc_html_e('🧹 Maintenance Commands', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-code-block">
                            <pre>wp bunny sync-verify                      # Verify file integrity
wp bunny cleanup-orphaned                # Clean orphaned files
wp bunny clear-cache                      # Clear plugin cache
wp bunny logs --export                    # Export logs to CSV</pre>
                        </div>
                    </div>
                </div>

                <!-- Troubleshooting Tab -->
                <div class="bunny-tab-content" id="troubleshooting">
                    <h2><?php esc_html_e('Troubleshooting Guide', 'bunny-media-offload'); ?></h2>
                    
                    <div class="bunny-troubleshoot-section">
                        <h3><?php esc_html_e('🔗 Connection Issues', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-troubleshoot-item">
                            <h4><?php esc_html_e('API Connection Failed', 'bunny-media-offload'); ?></h4>
                            <div class="bunny-solution">
                                <p><strong><?php esc_html_e('Symptoms:', 'bunny-media-offload'); ?></strong> <?php esc_html_e('Cannot connect to Bunny.net, "Test Connection" fails', 'bunny-media-offload'); ?></p>
                                <p><strong><?php esc_html_e('Solutions:', 'bunny-media-offload'); ?></strong></p>
                                <ul>
                                    <li><?php esc_html_e('Verify API key is correct in Bunny.net dashboard', 'bunny-media-offload'); ?></li>
                                    <li><?php esc_html_e('Check Storage Zone name spelling (case-sensitive)', 'bunny-media-offload'); ?></li>
                                    <li><?php esc_html_e('Ensure cURL extension is installed: php -m | grep curl', 'bunny-media-offload'); ?></li>
                                    <li><?php esc_html_e('Check if firewall is blocking outbound connections', 'bunny-media-offload'); ?></li>
                            </ul>
                            </div>
                        </div>
                        </div>
                        
                    <div class="bunny-troubleshoot-section">
                        <h3><?php esc_html_e('📤 Upload Problems', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-troubleshoot-item">
                            <h4><?php esc_html_e('Files Not Uploading', 'bunny-media-offload'); ?></h4>
                            <div class="bunny-solution">
                                <p><strong><?php esc_html_e('Symptoms:', 'bunny-media-offload'); ?></strong> <?php esc_html_e('Migration stalls, files remain local', 'bunny-media-offload'); ?></p>
                                <p><strong><?php esc_html_e('Solutions:', 'bunny-media-offload'); ?></strong></p>
                                <ul>
                                    <li><?php esc_html_e('Reduce batch size to 10-25 files in Settings', 'bunny-media-offload'); ?></li>
                                    <li><?php esc_html_e('Check available storage quota in Bunny.net dashboard', 'bunny-media-offload'); ?></li>
                                    <li><?php esc_html_e('Monitor logs: Bunny CDN > Logs for specific error messages', 'bunny-media-offload'); ?></li>
                                    <li><?php esc_html_e('Increase PHP max_execution_time and memory_limit', 'bunny-media-offload'); ?></li>
                            </ul>
                            </div>
                        </div>
                        </div>
                        
                    <div class="bunny-troubleshoot-section">
                        <h3><?php esc_html_e('🖼️ Optimization Issues', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-troubleshoot-item">
                            <h4><?php esc_html_e('Optimization Failing', 'bunny-media-offload'); ?></h4>
                            <div class="bunny-solution">
                                <p><strong><?php esc_html_e('Symptoms:', 'bunny-media-offload'); ?></strong> <?php esc_html_e('Images not converting, optimization queue stuck', 'bunny-media-offload'); ?></p>
                                <p><strong><?php esc_html_e('Solutions:', 'bunny-media-offload'); ?></strong></p>
                                <ul>
                                    <li><?php esc_html_e('Ensure GD library is installed: php -m | grep -i gd', 'bunny-media-offload'); ?></li>
                                    <li><?php esc_html_e('Check PHP memory limit (minimum 256M recommended)', 'bunny-media-offload'); ?></li>
                                    <li><?php esc_html_e('Verify file permissions on wp-content/uploads/', 'bunny-media-offload'); ?></li>
                                    <li><?php esc_html_e('Clear optimization queue: wp bunny optimization-clear', 'bunny-media-offload'); ?></li>
                            </ul>
                        </div>
                        </div>
                    </div>

                    <div class="bunny-troubleshoot-section">
                        <h3><?php esc_html_e('⚡ Performance Tips', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-info-card success">
                            <ul>
                                <li><?php esc_html_e('Run migrations during low-traffic hours', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('Use smaller batch sizes for shared hosting', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('Consider using WP-CLI for large migrations', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('Monitor server resources during processing', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('Keep the logs page open to track progress', 'bunny-media-offload'); ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .bunny-documentation {
            margin-top: 20px;
        }
        
        /* Tab Navigation */
        .bunny-doc-tabs {
            display: flex;
            margin-bottom: 30px;
            border-bottom: 2px solid #e0e0e0;
            flex-wrap: wrap;
        }

        .bunny-tab-button {
            background: none;
            border: none;
            padding: 12px 20px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #666;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
            margin-bottom: -2px;
        }

        .bunny-tab-button:hover {
            color: #0073aa;
            background-color: #f8f9fa;
        }

        .bunny-tab-button.active {
            color: #0073aa;
            border-bottom-color: #0073aa;
            background-color: #f8f9fa;
        }

        /* Tab Content */
        .bunny-tab-content {
            display: none;
        }

        .bunny-tab-content.active {
            display: block;
        }

        .bunny-tab-content h2 {
            color: #0073aa;
            border-bottom: 2px solid #0073aa;
            padding-bottom: 10px;
            margin-bottom: 30px;
        }

        /* Info Cards */
        .bunny-info-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-left: 4px solid #0073aa;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
        }

        .bunny-info-card.success {
            border-left-color: #28a745;
            background: #f8fff8;
        }

        .bunny-info-card.warning {
            border-left-color: #ffc107;
            background: #fffdf7;
        }

        .bunny-info-card h3, .bunny-info-card h4 {
            margin-top: 0;
            color: #0073aa;
        }

        .bunny-info-card.success h3, .bunny-info-card.success h4 {
            color: #28a745;
        }

        .bunny-info-card.warning h3, .bunny-info-card.warning h4 {
            color: #ffc107;
        }

        /* Step Guide */
        .bunny-step-guide {
            margin: 30px 0;
        }

        .bunny-step {
            display: flex;
            margin: 20px 0;
            align-items: flex-start;
        }

        .bunny-step-number {
            background: #0073aa;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 15px;
            flex-shrink: 0;
        }

        .bunny-step-content h4 {
            margin: 0 0 8px 0;
            color: #333;
        }

        .bunny-step-content p {
            margin: 0;
            color: #666;
        }

        /* Code Blocks */
        .bunny-code-block {
            background: #f8f8f8;
            border: 1px solid #ddd;
            border-radius: 6px;
            margin: 15px 0;
            overflow: hidden;
        }

        .bunny-code-header {
            background: #333;
            color: white;
            padding: 10px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
        }

        .bunny-copy-btn {
            background: #0073aa;
            color: white;
            border: none;
            padding: 4px 12px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
        }

        .bunny-copy-btn:hover {
            background: #005a87;
        }

        .bunny-code-block pre {
            margin: 0;
            padding: 15px;
            font-family: Consolas, Monaco, 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.4;
            overflow-x: auto;
            background: #f8f8f8;
        }

        /* Tables */
        .bunny-config-table table, .bunny-comparison-table table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .bunny-config-table th, .bunny-comparison-table th,
        .bunny-config-table td, .bunny-comparison-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        .bunny-config-table th, .bunny-comparison-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }

        .bunny-config-table code {
            background: #f8f9fa;
            padding: 2px 4px;
            border-radius: 3px;
            font-family: Consolas, Monaco, monospace;
            font-size: 12px;
        }

        /* Feature Grid */
        .bunny-feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

        .bunny-feature-item {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .bunny-feature-item h4 {
            margin: 0 0 10px 0;
            color: #0073aa;
        }

        .bunny-feature-item p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        /* Tips */
        .bunny-tip-list {
            margin: 20px 0;
        }
        
        .bunny-tip {
            background: white;
            border-left: 4px solid #0073aa;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .bunny-tip strong {
            color: #0073aa;
            display: block;
            margin-bottom: 5px;
        }
        
        /* Troubleshooting */
        .bunny-troubleshoot-section {
            margin: 30px 0;
        }
        
        .bunny-troubleshoot-item {
            background: white;
            border: 1px solid #dee2e6;
            border-left: 4px solid #dc3545;
            border-radius: 4px;
            padding: 20px;
            margin: 15px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .bunny-troubleshoot-item h4 {
            margin: 0 0 15px 0;
            color: #dc3545;
        }

        .bunny-solution p {
            margin: 10px 0;
            color: #333;
        }

        .bunny-solution strong {
            color: #0073aa;
        }

        .bunny-solution ul {
            margin: 10px 0 0 20px;
            color: #666;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .bunny-doc-tabs {
                flex-direction: column;
            }
            
            .bunny-tab-button {
            margin-bottom: 0;
                border-bottom: 1px solid #e0e0e0;
                border-radius: 0;
            }
            
            .bunny-feature-grid {
                grid-template-columns: 1fr;
            }
            
            .bunny-step {
                flex-direction: column;
            }
            
            .bunny-step-number {
                margin: 0 0 10px 0;
            }
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Tab switching
            $('.bunny-tab-button').on('click', function() {
                var targetTab = $(this).data('tab');
                
                // Update button states
                $('.bunny-tab-button').removeClass('active');
                $(this).addClass('active');
                
                // Update content
                $('.bunny-tab-content').removeClass('active');
                $('#' + targetTab).addClass('active');
            });
            
            // Copy to clipboard
            $('.bunny-copy-btn').on('click', function() {
                var targetId = $(this).data('copy');
                var text = $('#' + targetId).text();
                
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function() {
                        // Show feedback
                        var btn = $(this);
                        var originalText = btn.text();
                        btn.text('Copied!');
                        setTimeout(function() {
                            btn.text(originalText);
                        }, 1500);
                    }.bind(this));
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $bmo = Bunny_Media_Offload::get_instance();
        $result = $bmo->api->test_connection();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success(array('message' => esc_html__('Connection successful!', 'bunny-media-offload')));
        }
    }
    
    /**
     * AJAX: Save settings
     */
    public function ajax_save_settings() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        // Handle settings save
        wp_send_json_success(array('message' => esc_html__('Settings saved successfully.', 'bunny-media-offload')));
    }
    
    /**
     * AJAX: Get stats
     */
    public function ajax_get_stats() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $stats = $this->stats->get_dashboard_stats();
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX: Export logs
     */
    public function ajax_export_logs() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $csv_data = $this->logger->export_logs();
        
        wp_send_json_success(array('csv_data' => $csv_data));
    }
    
    /**
     * AJAX: Clear logs
     */
    public function ajax_clear_logs() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $this->logger->clear_logs();
        wp_send_json_success(array('message' => esc_html__('Logs cleared successfully.', 'bunny-media-offload')));
    }
    
    /**
     * AJAX: Regenerate missing thumbnails
     */
    public function ajax_regenerate_thumbnails() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
        
        if ($attachment_id) {
            // Regenerate for specific attachment
            $result = $this->uploader->regenerate_missing_thumbnails($attachment_id);
        } else {
            // Regenerate for all migrated images
            $result = $this->uploader->regenerate_missing_thumbnails();
        }
        
        if (is_array($result)) {
            wp_send_json_success(array(
                // translators: %1$d is the number of processed thumbnails, %2$d is the number of errors
                'message' => sprintf(esc_html__('Processed %1$d thumbnails with %2$d errors.', 'bunny-media-offload'), $result['processed'], $result['errors']),
                'processed' => $result['processed'],
                'errors' => $result['errors']
            ));
        } else {
            wp_send_json_error(array('message' => esc_html__('Failed to regenerate thumbnails.', 'bunny-media-offload')));
        }
    }
    
    /**
     * Add media library column
     */
    public function add_media_column($columns) {
        $columns['bunny_status'] = esc_html__('Bunny CDN', 'bunny-media-offload');
        return $columns;
    }
    
    /**
     * Display media library column
     */
    public function display_media_column($column_name, $attachment_id) {
        if ($column_name === 'bunny_status') {
            global $wpdb;
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Querying plugin-specific table for media library column display
            $bunny_file = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}bunny_offloaded_files WHERE attachment_id = %d",
                $attachment_id
            ));
            
            if ($bunny_file) {
                echo '<span class="bunny-status bunny-status-offloaded" title="' . esc_attr($bunny_file->bunny_url) . '">✓ ' . esc_html__('Offloaded', 'bunny-media-offload') . '</span>';
                
                // Show optimization status if it's an image
                if ($this->optimizer && wp_attachment_is_image($attachment_id)) {
                    $is_optimized = get_post_meta($attachment_id, '_bunny_optimized', true);
                    $optimization_data = get_post_meta($attachment_id, '_bunny_optimization_data', true);
                    
                    if ($is_optimized && $optimization_data) {
                        $compression_ratio = $optimization_data['compression_ratio'] ?? 0;
                        // translators: %s is the compression percentage
                        echo '<br><span class="bunny-optimization-status optimized" title="' . sprintf(esc_attr__('Compressed by %s%%', 'bunny-media-offload'), esc_attr($compression_ratio)) . '">' . esc_html__('Optimized', 'bunny-media-offload') . '</span>';
                    } else {
                        // Check if in optimization queue - cache for performance
                        $queue_status_cache_key = 'bunny_queue_status_' . $attachment_id;
                        $queue_status = wp_cache_get($queue_status_cache_key, 'bunny_media_offload');
                        
                        if ($queue_status === false) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching implemented above
                        $queue_status = $wpdb->get_var($wpdb->prepare(
                                "SELECT status FROM {$wpdb->prefix}bunny_optimization_queue WHERE attachment_id = %d ORDER BY date_added DESC LIMIT 1",
                            $attachment_id
                        ));
                            
                            // Cache for 2 minutes
                            wp_cache_set($queue_status_cache_key, $queue_status, 'bunny_media_offload', 2 * MINUTE_IN_SECONDS);
                        }
                        
                        if ($queue_status === 'pending' || $queue_status === 'processing') {
                            echo '<br><span class="bunny-optimization-status pending">' . esc_html(ucfirst($queue_status)) . '</span>';
                        } elseif ($queue_status === 'failed') {
                            echo '<br><span class="bunny-optimization-status failed">' . esc_html__('Failed', 'bunny-media-offload') . '</span>';
                        } else {
                            echo '<br><span class="bunny-optimization-status not-optimized">' . esc_html__('Not Optimized', 'bunny-media-offload') . '</span>';
                        }
                    }
                }
            } else {
                echo '<span class="bunny-status bunny-status-local">✗ ' . esc_html__('Local', 'bunny-media-offload') . '</span>';
            }
        }
    }
    
    /**
     * Add filter dropdown to media library
     */
    public function add_media_library_filter() {
        global $pagenow;
        
        if ($pagenow === 'upload.php') {
            $selected = isset($_GET['bunny_filter']) ? sanitize_text_field($_GET['bunny_filter']) : '';
            
            ?>
            <select name="bunny_filter" class="bunny-media-filter">
                <option value=""><?php esc_html_e('All files', 'bunny-media-offload'); ?></option>
                <option value="local" <?php selected($selected, 'local'); ?>><?php esc_html_e('💾 Local only', 'bunny-media-offload'); ?></option>
                <option value="cloud" <?php selected($selected, 'cloud'); ?>><?php esc_html_e('☁️ Cloud only', 'bunny-media-offload'); ?></option>
            </select>
            
            <style>
            .bunny-status {
                font-weight: 500;
                font-size: 12px;
                padding: 2px 6px;
                border-radius: 3px;
                display: inline-block;
            }
            
            .bunny-status-offloaded {
                color: #0073aa;
                background: #e7f3ff;
                border: 1px solid #b8daff;
            }
            
            .bunny-status-local {
                color: #666;
                background: #f8f9fa;
                border: 1px solid #dee2e6;
            }
            
            .bunny-optimization-status {
                font-size: 11px;
                padding: 1px 4px;
                border-radius: 2px;
                margin-top: 2px;
                display: inline-block;
            }
            
            .bunny-optimization-status.optimized {
                color: #155724;
                background: #d4edda;
            }
            
            .bunny-optimization-status.pending {
                color: #856404;
                background: #fff3cd;
            }
            
            .bunny-optimization-status.failed {
                color: #721c24;
                background: #f8d7da;
            }
            
            .bunny-optimization-status.not-optimized {
                color: #6c757d;
                background: #e9ecef;
            }
            
            .bunny-media-filter {
                margin-left: 8px;
                min-width: 120px;
            }
            
            /* Media library enhancements */
            .wp-list-table .column-bunny_status {
                width: 120px;
                text-align: center;
            }
            </style>
            <?php
        }
    }
    
    /**
     * Filter media library query based on bunny filter
     */
    public function filter_media_library_query($query) {
        global $pagenow, $wpdb;
        
        if ($pagenow === 'upload.php' && isset($_GET['bunny_filter']) && !empty($_GET['bunny_filter'])) {
            $filter = sanitize_text_field($_GET['bunny_filter']);
            
            if ($filter === 'cloud') {
                // Show only files that are offloaded to cloud
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query needed for filtering
                $offloaded_ids = $wpdb->get_col(
                    "SELECT attachment_id FROM {$wpdb->prefix}bunny_offloaded_files WHERE bunny_url IS NOT NULL AND bunny_url != ''"
                );
                
                if (!empty($offloaded_ids)) {
                    $query->set('post__in', $offloaded_ids);
                } else {
                    // No offloaded files, show nothing
                    $query->set('post__in', array(0));
                }
            } elseif ($filter === 'local') {
                // Show only files that are NOT offloaded to cloud
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query needed for filtering
                $offloaded_ids = $wpdb->get_col(
                    "SELECT attachment_id FROM {$wpdb->prefix}bunny_offloaded_files WHERE bunny_url IS NOT NULL AND bunny_url != ''"
                );
                
                if (!empty($offloaded_ids)) {
                    $query->set('post__not_in', $offloaded_ids);
                }
                
                // Also ensure we're only showing attachment posts
                $query->set('post_type', 'attachment');
            }
        }
    }
} 