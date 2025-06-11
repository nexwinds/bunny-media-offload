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
    public function __construct($settings, $stats, $migration, $logger, $optimizer = null, $wpml = null) {
        $this->settings = $settings;
        $this->stats = $stats;
        $this->migration = $migration;
        $this->logger = $logger;
        $this->optimizer = $optimizer;
        $this->wpml = $wpml;
        
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Add menu items
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Register scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'register_admin_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_bunny_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_bunny_test_bmo_connection', array($this, 'ajax_test_bmo_connection'));
        add_action('wp_ajax_bunny_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_bunny_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_bunny_refresh_stats', array($this, 'ajax_refresh_stats'));
        add_action('wp_ajax_bunny_refresh_all_stats', array($this, 'ajax_refresh_all_stats'));
        add_action('wp_ajax_bunny_export_logs', array($this, 'ajax_export_logs'));
        add_action('wp_ajax_bunny_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_bunny_regenerate_thumbnails', array($this, 'ajax_regenerate_thumbnails'));
        add_action('wp_ajax_bunny_get_optimization_stats', array($this, 'ajax_get_optimization_stats'));
        add_action('wp_ajax_bunny_run_optimization_diagnostics', array($this, 'ajax_run_optimization_diagnostics'));
        
        // Media library integration
        add_filter('manage_media_columns', array($this, 'add_media_column'));
        add_action('manage_media_custom_column', array($this, 'display_media_column'), 10, 2);
        add_action('admin_footer', array($this, 'add_media_library_filter'));
        add_action('pre_get_posts', array($this, 'filter_media_library_query'));
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
            __('Optimization', 'bunny-media-offload'),
            __('Optimization', 'bunny-media-offload'),
            'manage_options',
            'bunny-media-offload-optimization',
            array($this, 'optimization_page')
        );
        
        add_submenu_page(
            'bunny-media-offload',
            __('Test Connection', 'bunny-media-offload'),
            __('Test Connection', 'bunny-media-offload'),
            'manage_options',
            'bunny-media-offload-test',
            array($this, 'test_connection_page')
        );
        
        add_submenu_page(
            'bunny-media-offload',
            __('Logs', 'bunny-media-offload'),
            __('Logs', 'bunny-media-offload'),
            'manage_options',
            'bunny-media-logs',
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
        register_setting('bunny_json_settings', 'bunny_json_settings', array(
            'sanitize_callback' => array($this, 'sanitize_settings')
        ));
    }
    
    /**
     * Sanitize settings and save to JSON file
     */
    public function sanitize_settings($input) {
        // Use the settings class validation method
        $validation_result = $this->settings->validate($input);
        
        if (!empty($validation_result['errors'])) {
            foreach ($validation_result['errors'] as $field => $error) {
                add_settings_error('bunny_json_settings', $field, $error);
            }
        }
        
        // Update settings using the settings class (which saves to JSON)
        if (!empty($validation_result['validated'])) {
            $this->settings->update($validation_result['validated']);
        }
        
        // Return the current settings for WordPress (but they're not used for storage)
        return $this->settings->get_all();
    }
    
    /**
     * Dashboard page
     */
    public function dashboard_page() {
        $stats = $this->stats->get_dashboard_stats();
        $recent_logs = $this->logger->get_logs(5);
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bunny Media Offload Dashboard', 'bunny-media-offload'); ?></h1>
            
            <?php $this->render_unified_stats_widget(__('Image Overview', 'bunny-media-offload')); ?>
            
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
                    

                </div>
                
                <div class="bunny-dashboard-row">
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameter for admin tab navigation
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'connection';
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bunny Media Offload Settings', 'bunny-media-offload'); ?></h1>
            
            <?php echo wp_kses_post($this->display_config_status()); ?>
            
                            <!-- Settings Navigation Tabs -->
            <div class="bunny-settings-tabs">
                <nav class="nav-tab-wrapper wp-clearfix">
                    <a href="?page=bunny-media-offload-settings&tab=connection" class="nav-tab <?php echo $active_tab === 'connection' ? 'nav-tab-active' : ''; ?>">
                        <span class="dashicons dashicons-admin-generic"></span>
                        <?php esc_html_e('Connection', 'bunny-media-offload'); ?>
                    </a>
                    <a href="?page=bunny-media-offload-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                        <span class="dashicons dashicons-admin-settings"></span>
                        <?php esc_html_e('General', 'bunny-media-offload'); ?>
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
            <form id="bunny-settings-form" class="bunny-settings-form">
                <?php wp_nonce_field('bunny_ajax_nonce', 'bunny_ajax_nonce'); ?>
                    
                    <?php
                    switch ($active_tab) {
                        case 'connection':
                            $this->render_connection_settings($settings);
                            break;
                        case 'general':
                            $this->render_general_settings($settings);
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
                        <input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'bunny-media-offload'); ?>">
                        <?php if ($active_tab === 'connection'): ?>
                            <button type="button" class="button" id="test-connection"><?php esc_html_e('Test Connection', 'bunny-media-offload'); ?></button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
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
                            <input type="password" name="bunny_json_settings[api_key]" value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" class="regular-text" autocomplete="new-password" />
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
                                <input type="text" name="bunny_json_settings[storage_zone]" value="<?php echo esc_attr($settings['storage_zone'] ?? ''); ?>" class="regular-text" />
                            <?php endif; ?>
                        <p class="description"><?php esc_html_e('Your Bunny.net Storage Zone name. This is the name you gave your storage zone when creating it.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Image Processor API Region', 'bunny-media-offload'); ?></th>
                        <td>
                            <?php 
                            $api_region = $this->settings->get('bmo_api_region', 'us');
                            $region_label = ($api_region === 'eu') ? 'Europe (EU)' : 'United States (US)';
                            ?>
                            <input type="text" value="<?php echo esc_attr($region_label); ?>" class="regular-text bunny-readonly-field" readonly />
                            <span class="bunny-config-source"><?php esc_html_e('Configured in wp-config.php', 'bunny-media-offload'); ?></span>
                            <p class="description"><?php esc_html_e('Active image processor API region set via BMO_API_REGION constant.', 'bunny-media-offload'); ?></p>
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
                            <input type="text" name="bunny_json_settings[custom_hostname]" value="<?php echo esc_attr($settings['custom_hostname'] ?? ''); ?>" class="regular-text" placeholder="cdn.example.com" />
                            <?php endif; ?>
                                                        <p class="description"><?php esc_html_e('Required: Custom CDN hostname (without https://). This must be configured in wp-config.php as BUNNY_CUSTOM_HOSTNAME.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                    <th scope="row"><?php esc_html_e('File Versioning', 'bunny-media-offload'); ?></th>
                        <td>
                            <label>
                            <input type="checkbox" name="bunny_json_settings[file_versioning]" value="1" <?php checked($settings['file_versioning'] ?? false); ?> />
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
            <h3><?php esc_html_e('Media Management Information', 'bunny-media-offload'); ?></h3>
            
            <div class="bunny-info-box">
                <h4><?php esc_html_e('🚀 Automated Optimization on Upload', 'bunny-media-offload'); ?></h4>
                <p><?php esc_html_e('This plugin automatically optimizes your images during upload when "Optimize on Upload" is enabled in the Image Optimization section below. Images are converted to AVIF format for maximum compression and performance.', 'bunny-media-offload'); ?></p>
            </div>
            
            <div class="bunny-info-box">
                <h4><?php esc_html_e('📁 Manual Migration & Offload', 'bunny-media-offload'); ?></h4>
                <p><?php esc_html_e('Media offloading to Bunny.net CDN is handled manually through the dedicated Migration page. This gives you full control over which files are offloaded and when.', 'bunny-media-offload'); ?></p>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=bunny-media-offload-migration')); ?>" class="button button-primary">
                        <?php esc_html_e('Go to Migration Page', 'bunny-media-offload'); ?>
                    </a>
                </p>
            </div>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Delete Local Files', 'bunny-media-offload'); ?></th>
                        <td>
                            <label>
                            <input type="checkbox" name="bunny_json_settings[delete_local]" value="1" <?php checked($settings['delete_local'] ?? true); ?> />
                            <?php esc_html_e('Delete local files after successful migration to save server space', 'bunny-media-offload'); ?>
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
     * Render Performance Settings
     */
    private function render_performance_settings($settings) {
        ?>
        <div class="bunny-settings-section">
            <h3><?php esc_html_e('Migration Settings', 'bunny-media-offload'); ?></h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Concurrent Migration Tasks', 'bunny-media-offload'); ?></th>
                    <td>
                        <select name="bunny_json_settings[migration_concurrent_limit]">
                            <option value="2" <?php selected($settings['migration_concurrent_limit'] ?? 4, 2); ?>>2</option>
                            <option value="4" <?php selected($settings['migration_concurrent_limit'] ?? 4, 4); ?>>4 (recommended)</option>
                            <option value="8" <?php selected($settings['migration_concurrent_limit'] ?? 4, 8); ?>>8</option>
                        </select>
                        <p class="description"><?php esc_html_e('Number of files to upload concurrently during migration. Higher values may improve speed but can cause server issues on shared hosting.', 'bunny-media-offload'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Batch Size', 'bunny-media-offload'); ?></th>
                    <td>
                        <select name="bunny_json_settings[batch_size]">
                            <option value="50" <?php selected($settings['batch_size'] ?? 100, 50); ?>>50</option>
                            <option value="100" <?php selected($settings['batch_size'] ?? 100, 100); ?>>100 (recommended)</option>
                            <option value="150" <?php selected($settings['batch_size'] ?? 100, 150); ?>>150</option>
                            <option value="250" <?php selected($settings['batch_size'] ?? 100, 250); ?>>250</option>
                        </select>
                        <p class="description"><?php esc_html_e('Number of files to process in each migration batch. Higher values improve speed but require more memory.', 'bunny-media-offload'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="bunny-settings-section">

            <?php $this->render_bmo_settings($settings); ?>
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
        $config_file_info = $this->settings->get_config_file_info();
        $has_constants = false;
        
        foreach ($constants_status as $status) {
            if ($status['defined']) {
                $has_constants = true;
                break;
            }
        }
        
        ?>
        <div class="notice notice-info">
            <h3><?php esc_html_e('Configuration System Status', 'bunny-media-offload'); ?></h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 10px;">
                <div>
                    <h4><?php esc_html_e('wp-config.php Constants', 'bunny-media-offload'); ?></h4>
                    <?php if ($has_constants): ?>
                        <p><span style="color: #28a745;">✓</span> <?php esc_html_e('API credentials configured in wp-config.php', 'bunny-media-offload'); ?></p>
                        <p class="description"><?php esc_html_e('Settings shown below in read-only format for security.', 'bunny-media-offload'); ?></p>
                    <?php else: ?>
                        <p><span style="color: #ffc107;">⚠</span> <?php esc_html_e('API credentials not in wp-config.php', 'bunny-media-offload'); ?></p>
                        <p class="description">
                            <?php esc_html_e('For enhanced security, consider adding credentials to wp-config.php.', 'bunny-media-offload'); ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=bunny-media-offload-documentation')); ?>" target="_blank">
                                <?php esc_html_e('View Guide', 'bunny-media-offload'); ?>
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
                <div>
                    <h4><?php esc_html_e('JSON Configuration File', 'bunny-media-offload'); ?></h4>
                    <?php if ($config_file_info): ?>
                        <p><span style="color: #28a745;">✓</span> <?php esc_html_e('Configuration file active', 'bunny-media-offload'); ?></p>
                        <p class="description">
                            <?php 
                            printf(
                                // translators: %1$s is the file path, %2$s is the file size
                                esc_html__('File: %1$s (%2$s)', 'bunny-media-offload'),
                                '<code>' . esc_html(basename($config_file_info['path'])) . '</code>',
                                esc_html(size_format($config_file_info['size']))
                            ); 
                            ?>
                        </p>
                    <?php else: ?>
                        <p><span style="color: #dc3545;">✗</span> <?php esc_html_e('Configuration file missing', 'bunny-media-offload'); ?></p>
                        <p class="description"><?php esc_html_e('File will be created automatically when settings are saved.', 'bunny-media-offload'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Migration page
     */
    public function migration_page() {
        // Use consolidated stats from the stats class
        $migration_stats = $this->stats->get_migration_progress();
        
        // Get settings
        $settings = $this->settings->get_all();
        $max_file_size_kb = isset($settings['max_file_size']) ? (int) $settings['max_file_size'] : 10240; // Default 10MB in KB
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bulk Migration', 'bunny-media-offload'); ?></h1>
            
            <?php $this->render_unified_stats_widget(__('Image Statistics – Ready for Migration', 'bunny-media-offload')); ?>
            
            <div class="bunny-migration-info">
                <div class="notice notice-info">
                    <h3><?php esc_html_e('Migration Requirements', 'bunny-media-offload'); ?></h3>
                    <p><?php esc_html_e('Only images in SVG, AVIF or WebP format will be migrated. Images in other formats will be skipped.', 'bunny-media-offload'); ?></p>
                </div>
            </div>
            
            <!-- Migration Criteria Box -->
            <div class="bunny-card">
                <h3><?php esc_html_e('Migration Criteria', 'bunny-media-offload'); ?></h3>
                <div class="bunny-migration-criteria">
                    <p><?php esc_html_e('The following images will be migrated to Bunny CDN:', 'bunny-media-offload'); ?></p>
                    <ul>
                        <li>
                            <?php 
                            printf(
                                // translators: %d is the threshold in KB
                                esc_html__('WebP/AVIF: Only if size does not exceed %d KB', 'bunny-media-offload'),
                                esc_html($max_file_size_kb)
                            ); 
                            ?>
                        </li>
                        <li><?php esc_html_e('All files must be hosted locally (not on CDN)', 'bunny-media-offload'); ?></li>
                    </ul>
                    <div class="bunny-eligibility-stats">
                        <strong><?php esc_html_e('Eligible for migration:', 'bunny-media-offload'); ?></strong>
                        <span id="eligible-count"><?php echo esc_html(number_format($migration_stats['images_pending'])); ?></span> <?php esc_html_e('images', 'bunny-media-offload'); ?>
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
                        <button type="submit" class="button button-primary" id="start-migration" <?php echo $migration_stats['images_pending'] > 0 ? '' : 'disabled'; ?>>
                            <?php if ($migration_stats['images_pending'] > 0): ?>
                                <?php esc_html_e('Start Migration', 'bunny-media-offload'); ?>
                            <?php else: ?>
                                <?php esc_html_e('No Files to Migrate', 'bunny-media-offload'); ?>
                            <?php endif; ?>
                        </button>
                        <button type="button" class="button bunny-button-hidden" id="cancel-migration"><?php esc_html_e('Cancel Migration', 'bunny-media-offload'); ?></button>
                    </p>
                </form>
            </div>
            
            <div id="migration-progress" class="bunny-status-hidden">
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
            
            <!-- Migration Log -->
            <div id="migration-log" class="bunny-migration-log" style="display: none;">
                <h4><?php esc_html_e('Migration Log', 'bunny-media-offload'); ?></h4>
                <div class="bunny-log-container" id="migration-log-container"></div>
            </div>
            
            <div class="bunny-troubleshooting">
                <h3><?php esc_html_e('Troubleshooting', 'bunny-media-offload'); ?></h3>
                <div class="bunny-card">
                    <h4><?php esc_html_e('Fix Missing Thumbnails', 'bunny-media-offload'); ?></h4>
                    <p><?php esc_html_e('If you encounter 404 errors for WooCommerce product images or other thumbnails, use this tool to regenerate and upload missing thumbnail sizes.', 'bunny-media-offload'); ?></p>
                    <div class="bunny-actions">
                        <button type="button" class="button button-secondary" id="regenerate-thumbnails"><?php esc_html_e('Regenerate All Thumbnails', 'bunny-media-offload'); ?></button>
                    </div>
                    <div id="thumbnail-regeneration-status" class="bunny-status-hidden">
                        <p id="thumbnail-status-text"></p>
                    </div>
                </div>
            </div>
        </div>
        
        <script type="text/javascript">
            // Initialize the migration JS module when document is ready
            jQuery(document).ready(function($) {
                if (typeof BunnyMigration !== 'undefined') {
                    BunnyMigration.init();
                }
            });
        </script>
        <?php
    }
    
    /**
     * Logs page
     */
    public function logs_page() {
        // Get filter parameters
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for admin filtering, nonce not required for read-only operations
        $log_type = isset($_GET['log_type']) ? sanitize_text_field(wp_unslash($_GET['log_type'])) : 'all';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for admin filtering, nonce not required for read-only operations
        $log_level = isset($_GET['log_level']) ? sanitize_text_field(wp_unslash($_GET['log_level'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for admin pagination, nonce not required for read-only operations
        $paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        
        $per_page = 25; // Show 25 logs per page
        $offset = ($paged - 1) * $per_page;
        
        // Get logs based on filters and pagination
        $logs = $this->get_filtered_logs($log_type, $log_level, $per_page, $offset);
        $total_logs = $this->get_filtered_logs_count($log_type, $log_level);
        $optimization_stats = $this->get_optimization_log_stats();
        
        // Get simplified error counts for offload logs
        $offload_stats = $this->get_simple_log_stats('offload');
        
        // Calculate pagination
        $total_pages = ceil($total_logs / $per_page);
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Activity Logs', 'bunny-media-offload'); ?></h1>
            
            <div class="wp-header-end"></div>
            
            <!-- Log Statistics Summary -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <span class="displaying-num">
                        <?php if ($log_type === 'all' || $log_type === 'offload'): ?>
                            <strong><?php esc_html_e('Media Offload:', 'bunny-media-offload'); ?></strong>
                            <span class="bunny-count-error"><?php printf(esc_html__('%d errors', 'bunny-media-offload'), esc_html($offload_stats['error'])); ?></span>, 
                            <span class="bunny-count-warning"><?php printf(esc_html__('%d warnings', 'bunny-media-offload'), esc_html($offload_stats['warning'])); ?></span>, 
                            <span class="bunny-count-info"><?php printf(esc_html__('%d info', 'bunny-media-offload'), esc_html($offload_stats['info'])); ?></span>
                            <?php if ($log_type === 'all'): ?> | <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if ($log_type === 'all' || $log_type === 'optimization'): ?>
                            <strong><?php esc_html_e('Image Optimization:', 'bunny-media-offload'); ?></strong>
                            <span class="bunny-count-error"><?php printf(esc_html__('%d errors', 'bunny-media-offload'), esc_html($optimization_stats['error'])); ?></span>, 
                            <span class="bunny-count-warning"><?php printf(esc_html__('%d warnings', 'bunny-media-offload'), esc_html($optimization_stats['warning'])); ?></span>, 
                            <span class="bunny-count-info"><?php printf(esc_html__('%d info', 'bunny-media-offload'), esc_html($optimization_stats['info'])); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                
                <div class="alignright actions">
                    <button type="button" class="button" id="export-logs" data-log-type="<?php echo esc_attr($log_type); ?>" data-log-level="<?php echo esc_attr($log_level); ?>">
                        <?php esc_html_e('Export Filtered Logs', 'bunny-media-offload'); ?>
                    </button>
                    <button type="button" class="button" id="clear-logs" data-log-type="<?php echo esc_attr($log_type); ?>">
                        <?php esc_html_e('Clear Filtered Logs', 'bunny-media-offload'); ?>
                    </button>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get" action="" style="display: inline-flex; align-items: center; gap: 8px;">
                        <input type="hidden" name="page" value="bunny-media-logs" />
                        
                        <select name="log_type" id="log_type">
                            <option value="all" <?php selected($log_type, 'all'); ?>><?php esc_html_e('All log types', 'bunny-media-offload'); ?></option>
                            <option value="offload" <?php selected($log_type, 'offload'); ?>><?php esc_html_e('Media Offload', 'bunny-media-offload'); ?></option>
                            <option value="optimization" <?php selected($log_type, 'optimization'); ?>><?php esc_html_e('Image Optimization', 'bunny-media-offload'); ?></option>
                        </select>
                        
                        <select name="log_level" id="log_level">
                            <option value="" <?php selected($log_level, ''); ?>><?php esc_html_e('All log levels', 'bunny-media-offload'); ?></option>
                            <option value="info" <?php selected($log_level, 'info'); ?>><?php esc_html_e('Info', 'bunny-media-offload'); ?></option>
                            <option value="warning" <?php selected($log_level, 'warning'); ?>><?php esc_html_e('Warning', 'bunny-media-offload'); ?></option>
                            <option value="error" <?php selected($log_level, 'error'); ?>><?php esc_html_e('Error', 'bunny-media-offload'); ?></option>
                        </select>
                        
                        <?php submit_button(__('Filter', 'bunny-media-offload'), 'button', 'filter_action', false, array('id' => 'post-query-submit')); ?>
                        
                        <?php if ($log_type !== 'all' || !empty($log_level)): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=bunny-media-logs')); ?>" class="button">
                                <?php esc_html_e('Reset', 'bunny-media-offload'); ?>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php 
                        if ($total_logs > 0) {
                            $start = $offset + 1;
                            $end = min($offset + $per_page, $total_logs);
                            printf(
                                // translators: 1: start item number, 2: end item number, 3: total items
                                esc_html__('%1$s–%2$s of %3$s items', 'bunny-media-offload'),
                                esc_html(number_format_i18n($start)),
                                esc_html(number_format_i18n($end)),
                                esc_html(number_format_i18n($total_logs))
                            );
                        } else {
                            esc_html_e('0 items', 'bunny-media-offload');
                        }
                        ?>
                    </span>
                    
                    <?php if ($total_pages > 1): ?>
                        <span class="pagination-links">
                            <?php
                            $paginate_links = paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => '‹',
                                'next_text' => '›',
                                'total' => $total_pages,
                                'current' => $paged,
                                'type' => 'plain'
                            ));
                            echo $paginate_links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links is safe
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped logs">
                <thead>
                    <tr>
                        <th scope="col" id="date" class="manage-column column-date"><?php esc_html_e('Date', 'bunny-media-offload'); ?></th>
                        <th scope="col" id="type" class="manage-column column-type"><?php esc_html_e('Type', 'bunny-media-offload'); ?></th>
                        <th scope="col" id="level" class="manage-column column-level"><?php esc_html_e('Level', 'bunny-media-offload'); ?></th>
                        <th scope="col" id="message" class="manage-column column-message column-primary"><?php esc_html_e('Message', 'bunny-media-offload'); ?></th>
                    </tr>
                </thead>
                
                <tbody id="the-list">
                    <?php if (!empty($logs)): ?>
                        <?php foreach ($logs as $log): ?>
                            <?php 
                            $log_category = $this->determine_log_category($log->message);
                            ?>
                            <tr class="bunny-log-entry bunny-log-<?php echo esc_attr($log->log_level); ?> bunny-log-type-<?php echo esc_attr($log_category); ?>">
                                <td class="date column-date">
                                    <span title="<?php echo esc_attr(Bunny_Utils::format_date($log->date_created)); ?>">
                                        <?php echo esc_html(Bunny_Utils::time_ago($log->date_created)); ?>
                                    </span>
                                </td>
                                <td class="type column-type">
                                    <span class="bunny-log-type bunny-log-type-<?php echo esc_attr($log_category); ?>">
                                        <?php echo esc_html($this->get_log_type_label($log_category)); ?>
                                    </span>
                                </td>
                                <td class="level column-level">
                                    <span class="bunny-log-level bunny-log-level-<?php echo esc_attr($log->log_level); ?>">
                                        <?php echo esc_html(ucfirst($log->log_level)); ?>
                                    </span>
                                </td>
                                <td class="message column-message column-primary">
                                    <?php echo esc_html($log->message); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="no-items">
                            <td class="colspanchange" colspan="4">
                                <div style="text-align: center; padding: 40px 20px;">
                                    <div style="font-size: 48px; color: #ddd; margin-bottom: 16px;">📄</div>
                                    <p style="font-size: 16px; color: #666; margin-bottom: 8px;">
                                        <?php esc_html_e('No logs found matching the selected filters.', 'bunny-media-offload'); ?>
                                    </p>
                                    <p style="font-size: 14px; color: #999; margin: 0;">
                                        <?php esc_html_e('Try adjusting your filter criteria or check back later.', 'bunny-media-offload'); ?>
                                    </p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php 
                        if ($total_logs > 0) {
                            $start = $offset + 1;
                            $end = min($offset + $per_page, $total_logs);
                            printf(
                                // translators: 1: start item number, 2: end item number, 3: total items
                                esc_html__('%1$s–%2$s of %3$s items', 'bunny-media-offload'),
                                esc_html(number_format_i18n($start)),
                                esc_html(number_format_i18n($end)),
                                esc_html(number_format_i18n($total_logs))
                            );
                        } else {
                            esc_html_e('0 items', 'bunny-media-offload');
                        }
                        ?>
                    </span>
                    
                    <?php if ($total_pages > 1): ?>
                        <span class="pagination-links">
                            <?php
                            $paginate_links = paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => '‹',
                                'next_text' => '›',
                                'total' => $total_pages,
                                'current' => $paged,
                                'type' => 'plain'
                            ));
                            echo $paginate_links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links is safe
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Optimization page
     */
    public function optimization_page() {
        // Get optimization statistics
        $optimizer = $this->optimizer;
        
        if (!$optimizer) {
            echo '<div class="error"><p>' . esc_html__('Optimization module not initialized. Please reactivate the plugin.', 'bunny-media-offload') . '</p></div>';
            return;
        }
        
        // Get unified statistics for consistency across all pages
        $stats = $this->stats->get_unified_image_stats();
        $optimization_stats = $optimizer->get_optimization_stats(); // For optimization-specific data
        
        $settings = $this->settings->get_all();
        
        // Check if BMO API key is set
        $api_key = isset($settings['bmo_api_key']) ? $settings['bmo_api_key'] : '';
        $api_region = isset($settings['bmo_api_region']) ? $settings['bmo_api_region'] : 'us';
        $max_file_size_kb = isset($settings['max_file_size']) ? (int) $settings['max_file_size'] : 10240; // Default 10MB in KB
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Image Optimization', 'bunny-media-offload'); ?></h1>
            
            <?php if (empty($api_key)): ?>
                <div class="notice notice-error">
                    <p>
                        <?php 
                        printf(
                            // translators: %s is the URL to the settings page
                            esc_html__('BMO API key is not set. Please configure it in the %s.', 'bunny-media-offload'),
                            '<a href="' . esc_url(admin_url('admin.php?page=bunny-media-offload-settings')) . '">' . esc_html__('settings', 'bunny-media-offload') . '</a>'
                        ); 
                        ?>
                    </p>
                </div>
            <?php endif; ?>
            
            <?php if (!is_ssl()): ?>
                <div class="notice notice-error">
                    <p><?php esc_html_e('HTTPS is required for the BMO API. Please enable HTTPS on your site.', 'bunny-media-offload'); ?></p>
                </div>
            <?php endif; ?>
            
            <?php $this->render_unified_stats_widget(__('Image Statistics', 'bunny-media-offload')); ?>
            
            <div class="bunny-optimization-dashboard">
                <!-- Statistics explanation -->
                <div class="bunny-card">
                    <h3><?php esc_html_e('Optimization Criteria', 'bunny-media-offload'); ?></h3>
                    <p><?php printf(
                        /* translators: %s is the size threshold in KB */
                        esc_html__('Images larger than %s KB will be optimized to reduce file size while maintaining quality.', 'bunny-media-offload'),
                        $max_file_size_kb
                    ); ?></p>
                    
                    <?php if (!$api_key): ?>
                    <div class="notice notice-warning">
                        <p><?php esc_html_e('Please configure your Optimization API key in the settings to enable optimization.', 'bunny-media-offload'); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Optimization Controls -->
                <div class="bunny-card">
                    <h2><?php esc_html_e('Image Optimization – Not Optimized', 'bunny-media-offload'); ?></h2>
                    
                    <div class="bunny-optimization-criteria">
                        <h3><?php esc_html_e('Optimization Criteria', 'bunny-media-offload'); ?></h3>
                        <p><?php esc_html_e('The following images will be optimized:', 'bunny-media-offload'); ?></p>
                        <ul>
                            <li><?php esc_html_e('JPEG/PNG: Always converted to AVIF format', 'bunny-media-offload'); ?></li>
                            <li>
                                <?php 
                                printf(
                                    // translators: %d is the threshold in KB
                                    esc_html__('WebP/AVIF: Only compressed if size exceeds %d KB', 'bunny-media-offload'),
                                    esc_html($threshold_kb)
                                ); 
                                ?>
                            </li>
                            <li><?php esc_html_e('All files must be hosted locally (not on CDN)', 'bunny-media-offload'); ?></li>
                            <li><?php esc_html_e('Files must exceed minimum size (35 KB)', 'bunny-media-offload'); ?></li>
                        </ul>
                    </div>
                    
                    <div class="bunny-optimization-actions">
                        <div class="bunny-eligibility-stats">
                            <strong><?php esc_html_e('Eligible for optimization:', 'bunny-media-offload'); ?></strong>
                            <span id="eligible-count"><?php echo esc_html(number_format($stats['eligible_for_optimization'])); ?></span> <?php esc_html_e('images', 'bunny-media-offload'); ?>
                        </div>
                        
                        <div class="bunny-optimization-buttons">
                            <button id="start-optimization" class="button button-primary bunny-optimize-button" <?php echo empty($api_key) || !is_ssl() ? 'disabled' : ''; ?>>
                                <?php esc_html_e('Start Optimization', 'bunny-media-offload'); ?>
                            </button>
                            
                            <button id="cancel-optimization" class="button bunny-cancel-button" style="display: none;">
                                <?php esc_html_e('Cancel', 'bunny-media-offload'); ?>
                            </button>
                            
                            <button class="button bunny-diagnostic-button">
                                <?php esc_html_e('Run Diagnostics', 'bunny-media-offload'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Progress bar -->
                    <div id="optimization-progress" class="bunny-optimization-progress" style="display: none;">
                        <h3><?php esc_html_e('Optimization Progress', 'bunny-media-offload'); ?></h3>
                        
                        <div class="bunny-progress-container">
                            <div class="bunny-progress-bar">
                                <div class="bunny-progress-fill" style="width: 0%"></div>
                                <div class="bunny-progress-text">0%</div>
                            </div>
                        </div>
                        
                        <div class="bunny-batch-info">
                            <div class="bunny-batch-status">
                                <?php esc_html_e('Initializing...', 'bunny-media-offload'); ?>
                            </div>
                            
                            <div class="bunny-batch-stats">
                                <div class="bunny-batch-stat processing">
                                    <span class="icon"></span>
                                    <span class="text"><?php esc_html_e('Processing:', 'bunny-media-offload'); ?></span>
                                    <span class="value" id="processing-count">0</span>
                                </div>
                                
                                <div class="bunny-batch-stat success">
                                    <span class="icon"></span>
                                    <span class="text"><?php esc_html_e('Completed:', 'bunny-media-offload'); ?></span>
                                    <span class="value" id="completed-count">0</span>
                                </div>
                                
                                <div class="bunny-batch-stat failed">
                                    <span class="icon"></span>
                                    <span class="text"><?php esc_html_e('Failed:', 'bunny-media-offload'); ?></span>
                                    <span class="value" id="failed-count">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Live log -->
                    <div id="optimization-log" class="bunny-optimization-log" style="display: none;">
                        <h4><?php esc_html_e('Optimization Log', 'bunny-media-offload'); ?></h4>
                        <div class="bunny-log-container" id="optimization-log-container"></div>
                    </div>
                    
                    <!-- Diagnostics results -->
                    <div id="diagnostics-results" class="bunny-diagnostics-results" style="display: none;">
                        <!-- Will be filled by JavaScript -->
                    </div>
                </div>
                
                <!-- API Configuration -->
                <div class="bunny-card">
                    <h2><?php esc_html_e('BMO API Configuration', 'bunny-media-offload'); ?></h2>
                    
                    <div class="bunny-api-settings">
                        <p>
                            <strong><?php esc_html_e('API Status:', 'bunny-media-offload'); ?></strong>
                            <?php if (!empty($api_key) && is_ssl()): ?>
                                <span class="bmo-status-indicator connected">
                                    <span class="bmo-status-dot"></span>
                                    <?php esc_html_e('Ready', 'bunny-media-offload'); ?>
                                </span>
                            <?php else: ?>
                                <span class="bmo-status-indicator disconnected">
                                    <span class="bmo-status-dot"></span>
                                    <?php esc_html_e('Not Configured', 'bunny-media-offload'); ?>
                                </span>
                            <?php endif; ?>
                        </p>
                        
                        <p>
                            <strong><?php esc_html_e('API Region:', 'bunny-media-offload'); ?></strong>
                            <?php echo esc_html(strtoupper($api_region)); ?>
                        </p>
                        
                        <p>
                            <strong><?php esc_html_e('Optimization Threshold:', 'bunny-media-offload'); ?></strong>
                            <?php echo esc_html($threshold_kb); ?> KB
                        </p>
                        
                        <p class="bunny-api-settings-note">
                            <?php 
                            printf(
                                // translators: %s is the URL to the settings page
                                esc_html__('You can change these settings in the %s page.', 'bunny-media-offload'),
                                '<a href="' . esc_url(admin_url('admin.php?page=bunny-media-offload-settings')) . '">' . esc_html__('Settings', 'bunny-media-offload') . '</a>'
                            ); 
                            ?>
                        </p>
                    </div>
                </div>
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
            
            <?php include plugin_dir_path(__FILE__) . 'documentation-tabs.php'; ?>
        </div>
        <?php
    }
    
    /**
     * Test Connection page
     */
    public function test_connection_page() {
        $settings = $this->settings->get_all();
        
        // Include the test connection components
        require_once BMO_PLUGIN_DIR . 'includes/test-connection-components.php';
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Test Connection', 'bunny-media-offload'); ?></h1>
            <p><?php esc_html_e('Test your API connections to ensure proper functionality.', 'bunny-media-offload'); ?></p>
            
            <?php bunny_render_complete_test_page($settings); ?>
        </div>
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
     * AJAX: Test BMO connection
     */
    public function ajax_test_bmo_connection() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        if (!$this->optimizer) {
            wp_send_json_error(esc_html__('Optimizer module not available.', 'bunny-media-offload'));
            return;
        }
        
        $result = $this->optimizer->test_connection();
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            $response = array(
                'message' => esc_html__('BMO API connection successful!', 'bunny-media-offload')
            );
            
            // Add credits information if available
            if (isset($result['credits'])) {
                $response['credits'] = $result['credits'];
            }
            
            wp_send_json_success($response);
        }
    }
    
    /**
     * AJAX: Save settings
     */
    public function ajax_save_settings() {
        // Check for nonce in different possible parameters
        $nonce = '';
        if (isset($_POST['nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
        } elseif (isset($_POST['bunny_ajax_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['bunny_ajax_nonce']));
        }
        
        // Verify nonce
        if (!wp_verify_nonce($nonce, 'bunny_ajax_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'bunny-media-offload')));
            return;
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'bunny-media-offload')));
            return;
        }
        
        // Get settings from POST data
        if (isset($_POST['bunny_json_settings']) && is_array($_POST['bunny_json_settings'])) {
            $input = wp_unslash($_POST['bunny_json_settings']);
            
            // Process checkbox fields (they don't submit if unchecked)
            $checkbox_fields = array('auto_optimize', 'delete_local', 'file_versioning', 'enable_logs');
            foreach ($checkbox_fields as $field) {
                if (!isset($input[$field])) {
                    $input[$field] = false;
                } else {
                    $input[$field] = true;
                }
            }
            
            // Process numeric fields
            $numeric_fields = array('max_file_size', 'batch_size', 'migration_concurrent_limit');
            foreach ($numeric_fields as $field) {
                if (isset($input[$field])) {
                    $input[$field] = intval($input[$field]);
                }
            }
            
            // Make sure max_file_size has a valid value
            if (!isset($input['max_file_size']) || empty($input['max_file_size'])) {
                $input['max_file_size'] = 10240; // Default to 10MB in KB
            }
            
            // Explicitly check for auto_optimize - very important!
            if (!isset($input['auto_optimize'])) {
                $input['auto_optimize'] = false;
            }
            
            // Save settings
            $result = $this->settings->update($input);
            
            // Log for debugging
            if ($this->logger) {
                $this->logger->log('debug', 'Settings saved: ' . json_encode(array(
                    'auto_optimize' => $input['auto_optimize'] ?? false,
                    'max_file_size' => $input['max_file_size'] ?? 0,
                    'result' => $result
                )));
            }
            
            if ($result) {
                wp_send_json_success(array('message' => __('Settings saved successfully.', 'bunny-media-offload')));
            } else {
                wp_send_json_error(array('message' => __('Failed to save settings. Please try again.', 'bunny-media-offload')));
            }
        } else {
            wp_send_json_error(array('message' => __('No settings data received.', 'bunny-media-offload')));
        }
    }
    
    /**
     * AJAX: Get stats
     */
    public function ajax_get_stats() {
        // Verify nonce
        if (!check_ajax_referer('bunny_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'bunny-media-offload')));
            return;
        }
        
        // Clear all statistics caches before fetching fresh data
        $this->stats->clear_cache();
        
        // Get fresh dashboard stats
        $dashboard_stats = $this->stats->get_dashboard_stats();
        
        // Get fresh unified image stats
        $unified_stats = $this->stats->get_unified_image_stats();
        
        // Merge the stats
        $combined_stats = array_merge($dashboard_stats, $unified_stats);
        
        wp_send_json_success($combined_stats);
    }
    
    /**
     * AJAX: Export logs
     */
    public function ajax_export_logs() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'bunny-media-offload'));
        }
        
        $log_type = isset($_POST['log_type']) ? sanitize_text_field(wp_unslash($_POST['log_type'])) : 'all';
        $log_level = isset($_POST['log_level']) ? sanitize_text_field(wp_unslash($_POST['log_level'])) : '';
        
        $csv_data = $this->export_filtered_logs($log_type, $log_level);
        
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
        
        $log_type = isset($_POST['log_type']) ? sanitize_text_field(wp_unslash($_POST['log_type'])) : 'all';
        
        $result = $this->clear_filtered_logs($log_type);
        
        wp_send_json_success(array('message' => $result['message']));
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
            // No nonce verification needed for GET filter in admin - this is for display filtering only
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameter for admin filtering
            $selected = isset($_GET['bunny_filter']) ? sanitize_text_field(wp_unslash($_GET['bunny_filter'])) : '';
            
            ?>
            <select name="bunny_filter" class="bunny-media-filter">
                <option value=""><?php esc_html_e('All files', 'bunny-media-offload'); ?></option>
                <option value="local" <?php selected($selected, 'local'); ?>><?php esc_html_e('💾 Local only', 'bunny-media-offload'); ?></option>
                <option value="cloud" <?php selected($selected, 'cloud'); ?>><?php esc_html_e('☁️ Cloud only', 'bunny-media-offload'); ?></option>
            </select>
            <?php
        }
    }
    
    /**
     * Filter media library query based on bunny filter
     */
    public function filter_media_library_query($query) {
        global $pagenow, $wpdb;
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameter for admin query filtering
        if ($pagenow === 'upload.php' && isset($_GET['bunny_filter']) && !empty($_GET['bunny_filter'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameter for admin query filtering
            $filter = sanitize_text_field(wp_unslash($_GET['bunny_filter']));
            
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
    
    /**
     * Get filtered logs based on type and level with pagination support
     */
    private function get_filtered_logs($log_type, $log_level, $limit, $offset = 0) {
        if ($log_type === 'all') {
            return $this->logger->get_logs($limit, $offset, $log_level);
        }
        
        // For filtered logs, we need to get a larger set and then filter
        // This is not the most efficient but works for reasonable log volumes
        $large_limit = 10000; // Get a large set to filter from
        $all_logs = $this->logger->get_logs($large_limit, 0, $log_level);
        
        // Filter logs by type based on message content
        $filtered_logs = array();
        foreach ($all_logs as $log) {
            $category = $this->determine_log_category($log->message);
            if ($log_type === $category) {
                $filtered_logs[] = $log;
            }
        }
        
        // Apply pagination to filtered results
        return array_slice($filtered_logs, $offset, $limit);
    }
    
    /**
     * Get count of filtered logs
     */
    private function get_filtered_logs_count($log_type, $log_level) {
        if ($log_type === 'all') {
            return $this->logger->count_logs($log_level);
        }
        
        // For filtered logs, we need to count from a large set
        $large_limit = 10000; // Get a large set to filter from
        $all_logs = $this->logger->get_logs($large_limit, 0, $log_level);
        
        // Count logs by type based on message content
        $count = 0;
        foreach ($all_logs as $log) {
            $category = $this->determine_log_category($log->message);
            if ($log_type === $category) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Determine log category based on message content
     */
    private function determine_log_category($message) {
        // Optimization-related keywords
        $optimization_keywords = array(
            'optimization', 'optimize', 'optimized', 'converting', 'avif', 'webp', 
            'compression', 'compress', 'format conversion', 'image quality',
            'size reduction', 'batch optimization', 'optimization session'
        );
        
        // Offload-related keywords
        $offload_keywords = array(
            'upload', 'download', 'migration', 'offload', 'bunny.net', 'cdn',
            'storage zone', 'sync', 'transfer', 'file deleted', 'file uploaded'
        );
        
        $message_lower = strtolower($message);
        
        // Check for optimization keywords first (more specific)
        foreach ($optimization_keywords as $keyword) {
            if (strpos($message_lower, $keyword) !== false) {
                return 'optimization';
            }
        }
        
        // Check for offload keywords
        foreach ($offload_keywords as $keyword) {
            if (strpos($message_lower, $keyword) !== false) {
                return 'offload';
            }
        }
        
        // Default to offload for unmatched logs (legacy behavior)
        return 'offload';
    }
    
    /**
     * Get log type label
     */
    private function get_log_type_label($category) {
        switch ($category) {
            case 'optimization':
                return __('Optimization', 'bunny-media-offload');
            case 'offload':
                return __('Offload', 'bunny-media-offload');
            default:
                return __('General', 'bunny-media-offload');
        }
    }
    
    /**
     * Get simple log statistics by category
     */
    private function get_simple_log_stats($category) {
        global $wpdb;
        
        $cache_key = "bunny_simple_log_stats_{$category}";
        $cached_stats = wp_cache_get($cache_key, 'bunny_media_offload');
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        // Define category keywords
        $keywords = array();
        if ($category === 'offload') {
            $keywords = array('upload', 'download', 'sync', 'migration', 'offload', 'CDN');
        }
        
        if (empty($keywords)) {
            return array('error' => 0, 'warning' => 0, 'info' => 0);
        }
        
        // Build WHERE clause for keywords
        $keyword_conditions = array();
        foreach ($keywords as $keyword) {
            $keyword_conditions[] = "message LIKE '%" . esc_sql($keyword) . "%'";
        }
        $where_clause = '(' . implode(' OR ', $keyword_conditions) . ')';
        
        $query = "
            SELECT log_level, COUNT(*) as count
            FROM {$wpdb->prefix}bunny_logs 
            WHERE {$where_clause}
            AND date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY log_level
        ";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table query with caching implemented, no user input to sanitize
        $counts = $wpdb->get_results($query);
        
        $stats = array('error' => 0, 'warning' => 0, 'info' => 0);
        foreach ($counts as $count) {
            if (isset($stats[$count->log_level])) {
                $stats[$count->log_level] = (int) $count->count;
            }
        }
        
        // Cache for 3 minutes
        wp_cache_set($cache_key, $stats, 'bunny_media_offload', 180);
        
        return $stats;
    }
    
    /**
     * Get optimization log statistics
     */
    private function get_optimization_log_stats() {
        global $wpdb;
        
        $cache_key = 'bunny_optimization_log_stats';
        $cached_stats = wp_cache_get($cache_key, 'bunny_media_offload');
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        // Get optimization-related log counts by level
        $optimization_keywords = array(
            'optimization', 'optimize', 'optimized', 'converting', 'avif', 'webp', 
            'compression', 'compress', 'format conversion', 'image quality',
            'size reduction', 'batch optimization', 'optimization session'
        );
        
        $keyword_conditions = array();
        foreach ($optimization_keywords as $keyword) {
            $keyword_conditions[] = "message LIKE '%" . esc_sql($keyword) . "%'";
        }
        $where_clause = '(' . implode(' OR ', $keyword_conditions) . ')';
        
        $optimization_query = "
            SELECT log_level, COUNT(*) as count
            FROM {$wpdb->prefix}bunny_logs 
            WHERE {$where_clause}
            AND date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY log_level
        ";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table query with caching implemented, no user input to sanitize
        $counts = $wpdb->get_results($optimization_query);
        
        $stats = array(
            'error' => 0,
            'warning' => 0,
            'info' => 0,
            'debug' => 0
        );
        
        foreach ($counts as $count) {
            $stats[$count->log_level] = (int) $count->count;
        }
        
        // Cache for 3 minutes
        wp_cache_set($cache_key, $stats, 'bunny_media_offload', 180);
        
        return $stats;
    }
    
    /**
     * Export filtered logs as CSV
     */
    private function export_filtered_logs($log_type, $log_level) {
        $logs = $this->get_filtered_logs($log_type, $log_level, 10000); // Get up to 10k logs for export
        
        if (empty($logs)) {
            return '';
        }
        
        $csv_data = "Date,Type,Level,Message\n";
        
        foreach ($logs as $log) {
            $category = $this->determine_log_category($log->message);
            $type_label = $this->get_log_type_label($category);
            
            $csv_data .= sprintf(
                '"%s","%s","%s","%s"' . "\n",
                esc_html(Bunny_Utils::format_date($log->date_created)),
                esc_html($type_label),
                esc_html(ucfirst($log->log_level)),
                str_replace('"', '""', $log->message) // Escape quotes for CSV
            );
        }
        
        return $csv_data;
    }
    
    /**
     * Clear filtered logs
     */
    private function clear_filtered_logs($log_type) {
        global $wpdb;
        
        if ($log_type === 'all') {
            $this->logger->clear_logs();
            return array('message' => __('All logs cleared successfully.', 'bunny-media-offload'));
        }
        
        // Get all logs and filter them
        $all_logs = $this->logger->get_logs(10000); // Get large number for processing
        $ids_to_delete = array();
        
        foreach ($all_logs as $log) {
            $category = $this->determine_log_category($log->message);
            if ($log_type === $category) {
                $ids_to_delete[] = $log->id;
            }
        }
        
        if (empty($ids_to_delete)) {
            $type_label = $this->get_log_type_label($log_type);
            // translators: %s is the log type (e.g. "optimization", "offload")
            return array('message' => sprintf(__('No %s logs found to clear.', 'bunny-media-offload'), strtolower($type_label)));
        }
        
        // Delete the filtered logs
        $placeholders = implode(',', array_fill(0, count($ids_to_delete), '%d'));
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Deleting filtered logs, safe placeholder interpolation for IN clause
        $deleted_count = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bunny_logs WHERE id IN ($placeholders)", ...$ids_to_delete));
        
        // Clear relevant caches
        wp_cache_delete('bunny_error_stats', 'bunny_media_offload');
        wp_cache_delete('bunny_optimization_log_stats', 'bunny_media_offload');
        
        $type_label = $this->get_log_type_label($log_type);
        
        return array('message' => sprintf(
            // translators: %1$d is the number of logs, %2$s is the log type
            _n(
                '%1$d %2$s log cleared successfully.',
                '%1$d %2$s logs cleared successfully.',
                $deleted_count,
                'bunny-media-offload'
            ),
            $deleted_count,
            strtolower($type_label)
        ));
    }
    
    

    
    /**
     * Render unified image statistics widget
     */
    private function render_unified_stats_widget($title = null) {
        // Get the stats
        $stats = $this->stats->get_unified_image_stats();
        
        $default_title = __('Image Overview', 'bunny-media-offload');
        $widget_title = $title ?: $default_title;
        
        ?>
        <div class="bunny-card">
            <h2><?php echo esc_html($widget_title); ?></h2>
            
            <div class="bunny-stats-container bunny-two-column-layout">
                <div class="bunny-stats-column bunny-stats-graph">
                    <div class="bunny-donut-chart-container">
                        <svg width="150" height="150" viewBox="0 0 150 150" class="bunny-stats-donut">
                            <!-- Background circle -->
                            <circle cx="75" cy="75" r="65" fill="#1e1e1e" stroke="#333" stroke-width="1" />
                            
                            <!-- Segments -->
                            <?php 
                            // Calculate angles
                            $total_angle = 360;
                            $local_angle = ($stats['not_optimized_percent'] / 100) * $total_angle;
                            $optimized_angle = ($stats['optimized_percent'] / 100) * $total_angle;
                            $cdn_angle = ($stats['cloud_percent'] / 100) * $total_angle;
                            
                            // Calculate paths
                            if ($local_angle > 0) {
                                $this->render_donut_segment(75, 75, 60, 0, $local_angle, '#ef4444', 'bunny-segment-not-optimized');
                            }
                            
                            if ($optimized_angle > 0) {
                                $this->render_donut_segment(75, 75, 60, $local_angle, $optimized_angle, '#f59e0b', 'bunny-segment-ready-for-migration');
                            }
                            
                            if ($cdn_angle > 0) {
                                $this->render_donut_segment(75, 75, 60, $local_angle + $optimized_angle, $cdn_angle, '#10b981', 'bunny-segment-on-cdn');
                            }
                            ?>
                            
                            <!-- Center -->
                            <circle cx="75" cy="75" r="40" fill="#1e1e1e" />
                            <text x="75" y="75" text-anchor="middle" dominant-baseline="middle" fill="#fff" font-size="14" class="bunny-total-images">
                                <?php echo esc_html(number_format($stats['total_images'])); ?>
                            </text>
                            <text x="75" y="90" text-anchor="middle" dominant-baseline="middle" fill="#999" font-size="10">
                                <?php esc_html_e('TOTAL IMAGES', 'bunny-media-offload'); ?>
                            </text>
                        </svg>
                    </div>
                </div>
                
                <div class="bunny-stats-column bunny-stats-numbers">
                    <div class="bunny-stats-details">
                        <div class="bunny-stat-item bunny-stat-not-optimized">
                            <span class="bunny-stat-indicator" style="background-color: #ef4444;"></span>
                            <span class="bunny-stat-label"><?php esc_html_e('Not Optimized', 'bunny-media-offload'); ?></span>
                            <span class="bunny-stat-value">
                                <span class="bunny-not-optimized-count"><?php echo esc_html(number_format($stats['local_eligible'])); ?></span>
                                (<span class="bunny-not-optimized-percent"><?php echo esc_html($stats['not_optimized_percent']); ?></span>%)
                            </span>
                        </div>
                        
                        <div class="bunny-stat-item bunny-stat-ready">
                            <span class="bunny-stat-indicator" style="background-color: #f59e0b;"></span>
                            <span class="bunny-stat-label"><?php esc_html_e('Ready for Migration', 'bunny-media-offload'); ?></span>
                            <span class="bunny-stat-value">
                                <span class="bunny-ready-for-migration-count"><?php echo esc_html(number_format($stats['already_optimized'])); ?></span>
                                (<span class="bunny-ready-for-migration-percent"><?php echo esc_html($stats['optimized_percent']); ?></span>%)
                            </span>
                        </div>
                        
                        <div class="bunny-stat-item bunny-stat-cdn">
                            <span class="bunny-stat-indicator" style="background-color: #10b981;"></span>
                            <span class="bunny-stat-label"><?php esc_html_e('On CDN', 'bunny-media-offload'); ?></span>
                            <span class="bunny-stat-value">
                                <span class="bunny-on-cdn-count"><?php echo esc_html(number_format($stats['images_migrated'])); ?></span>
                                (<span class="bunny-on-cdn-percent"><?php echo esc_html($stats['cloud_percent']); ?></span>%)
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Refresh statistics (original method)
     */
    public function ajax_refresh_stats() {
        // Verify nonce
        if (!check_ajax_referer('bunny_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'bunny-media-offload')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'bunny-media-offload')));
            return;
        }
        
        // Get the stats
        $stats = $this->stats->get_dashboard_stats();
        
        // Add migration progress data
        $migration_progress = $this->stats->get_migration_progress();
        $stats['migration_progress'] = $migration_progress['progress_percentage'];
        
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX: Refresh all statistics with cache clearing
     */
    public function ajax_refresh_all_stats() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // Clear all stats caches
        if ($this->stats) {
            $this->stats->clear_cache();
        }
        
        // Get fresh stats
        $stats = $this->stats->get_dashboard_stats();
        
        // Get fresh unified image stats - this is the authoritative source
        $unified_stats = $this->stats->get_unified_image_stats();
        
        // Add migration progress data
        $migration_progress = $this->stats->get_migration_progress();
        $stats['migration_progress'] = $migration_progress['progress_percentage'];
        
        // Merge the stats
        $combined_stats = array_merge($stats, $unified_stats);
        
        wp_send_json_success($combined_stats);
    }
    
    /**
     * Render Settings Form
     */
    private function render_settings_form() {
        $settings = $this->settings->get_all();
        ?>
        <form id="bunny-settings-form" class="bunny-settings-form">
            <?php wp_nonce_field('bunny_ajax_nonce', 'bunny_ajax_nonce'); ?>
            
            <?php $this->render_general_settings($settings); ?>
            <?php $this->render_performance_settings($settings); ?>
            <?php $this->render_storage_settings($settings); ?>
            <?php $this->render_advanced_settings($settings); ?>
            <?php $this->render_debug_settings($settings); ?>
            
            <div class="bunny-settings-submit">
                <input type="submit" value="<?php esc_attr_e('Save Settings', 'bunny-media-offload'); ?>" class="button button-primary">
            </div>
        </form>
        <?php
    }
    
    /**
     * Render Performance Settings
     */
    private function render_debug_settings($settings) {
        ?>
        <div class="bunny-settings-section">
            <h3><?php esc_html_e('Debug & Logging', 'bunny-media-offload'); ?></h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Logging', 'bunny-media-offload'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="bunny_json_settings[enable_logs]" <?php checked($settings['enable_logs'] ?? false); ?>>
                            <?php esc_html_e('Enable logging for debugging', 'bunny-media-offload'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Logs are stored in the wp-content directory and can be viewed in the Logs page.', 'bunny-media-offload'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Log Level', 'bunny-media-offload'); ?></th>
                    <td>
                        <select name="bunny_json_settings[log_level]">
                            <option value="error" <?php selected($settings['log_level'] ?? 'info', 'error'); ?>><?php esc_html_e('Error only', 'bunny-media-offload'); ?></option>
                            <option value="warning" <?php selected($settings['log_level'] ?? 'info', 'warning'); ?>><?php esc_html_e('Warning', 'bunny-media-offload'); ?></option>
                            <option value="info" <?php selected($settings['log_level'] ?? 'info', 'info'); ?>><?php esc_html_e('Info', 'bunny-media-offload'); ?></option>
                            <option value="debug" <?php selected($settings['log_level'] ?? 'info', 'debug'); ?>><?php esc_html_e('Debug (verbose)', 'bunny-media-offload'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('More verbose levels include all messages from less verbose levels.', 'bunny-media-offload'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * AJAX: Get optimization statistics
     */
    public function ajax_get_optimization_stats() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        if (!$this->optimizer) {
            wp_send_json_error('Optimization module not initialized');
        }
        
        // Clear stats cache first to ensure fresh data
        if ($this->stats) {
            $this->stats->clear_cache();
        }
        
        $stats = $this->optimizer->get_optimization_stats();
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX: Run optimization diagnostics
     */
    public function ajax_run_optimization_diagnostics() {
        check_ajax_referer('bunny_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Optimization module not initialized');
        }
        
        if (!$this->optimizer) {
            wp_send_json_error('Optimization module not initialized');
        }
        
        $result = $this->optimizer->ajax_run_diagnostics();
        if (is_array($result) && isset($result['success'])) {
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } else {
            wp_send_json_error('Invalid response from optimizer');
        }
    }
    
    /**
     * Render BMO API settings
     */
    private function render_bmo_settings($settings) {
        $bmo_api_key = isset($settings['bmo_api_key']) ? $settings['bmo_api_key'] : '';
        $bmo_api_region = isset($settings['bmo_api_region']) ? $settings['bmo_api_region'] : 'us';
        $auto_optimize = isset($settings['auto_optimize']) ? (bool) $settings['auto_optimize'] : false;
        $max_file_size = isset($settings['max_file_size']) ? (int) $settings['max_file_size'] : 50;
        
        $key_from_constant = $this->settings->get_config_source('bmo_api_key') === 'constant';
        $region_from_constant = $this->settings->get_config_source('bmo_api_region') === 'constant';
        
        ?>
        <h3><?php esc_html_e('BMO API Settings', 'bunny-media-offload'); ?></h3>
        
        <div class="bunny-info-box">
            <p>
                <?php esc_html_e('Bunny Media Optimizer API enables image optimization in WebP and AVIF formats for significant file size reduction.', 'bunny-media-offload'); ?>
                <a href="https://bunny.net/media-optimizer/" target="_blank" rel="noopener"><?php esc_html_e('Learn more', 'bunny-media-offload'); ?> →</a>
            </p>
        </div>
        
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="bmo_api_key"><?php esc_html_e('BMO API Key', 'bunny-media-offload'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                            id="bmo_api_key"
                            value="<?php echo esc_attr($this->mask_key($bmo_api_key)); ?>"
                            class="regular-text bunny-readonly-field"
                            readonly
                        />
                        <p class="description">
                            <?php esc_html_e('Enter your Bunny Media Optimizer API key. Available in your BMO dashboard.', 'bunny-media-offload'); ?>
                        </p>
                        <button type="button" id="test-bmo-connection" class="button bunny-test-button">
                            <?php esc_html_e('Test BMO Connection', 'bunny-media-offload'); ?>
                        </button>
                        <span id="bmo-connection-result"></span>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="bmo_api_region"><?php esc_html_e('API Region', 'bunny-media-offload'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                            id="bmo_api_region"
                            value="<?php echo esc_attr($bmo_api_region === 'us' ? 'United States (US)' : 'Europe (EU)'); ?>"
                            class="regular-text bunny-readonly-field"
                            readonly
                        />
                        <p class="description">
                            <?php esc_html_e('Select the region that matches your Bunny Media Optimizer account.', 'bunny-media-offload'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="auto_optimize"><?php esc_html_e('Auto-Optimize', 'bunny-media-offload'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                id="auto_optimize"
                                name="bunny_json_settings[auto_optimize]"
                                value="1"
                                <?php checked($auto_optimize); ?>
                            />
                            <?php esc_html_e('Automatically optimize images on upload', 'bunny-media-offload'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, images will be optimized immediately when uploaded to WordPress.', 'bunny-media-offload'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="max_file_size"><?php esc_html_e('Maximum File Size (KB)', 'bunny-media-offload'); ?></label>
                    </th>
                    <td>
                        <div class="bunny-slider-container" style="padding: 10px 0;">
                            <input type="range" id="max_file_size_slider" min="0" max="9" step="1" value="<?php echo $this->get_size_slider_value($max_file_size); ?>" style="width: 100%; max-width: 400px;" />
                            <div class="bunny-slider-ticks" style="display: flex; justify-content: space-between; width: 100%; max-width: 400px; margin-top: 5px; font-size: 11px; color: #666;">
                                <span>40KB</span>
                                <span>50KB</span>
                                <span>70KB</span>
                                <span>100KB</span>
                                <span>150KB</span>
                                <span>200KB</span>
                                <span>500KB</span>
                                <span>1MB</span>
                                <span>2MB</span>
                                <span>4MB</span>
                            </div>
                        </div>
                        <div style="margin-top: 5px;">
                            <strong><?php esc_html_e('Selected:', 'bunny-media-offload'); ?></strong> 
                            <span id="max_file_size_display"></span>
                            <input type="hidden" name="bunny_json_settings[max_file_size]" id="max_file_size" value="<?php echo esc_attr($max_file_size); ?>" />
                        </div>
                        <script>
                            jQuery(document).ready(function($) {
                                // Define size values corresponding to slider positions
                                var sizeValues = [40, 50, 70, 100, 150, 200, 500, 1024, 2048, 4096];
                                
                                // Set initial display
                                updateSizeDisplay($("#max_file_size").val());
                                
                                // Update hidden input and display when slider changes
                                $("#max_file_size_slider").on("input change", function() {
                                    var sliderPos = parseInt($(this).val());
                                    var sizeValue = sizeValues[sliderPos];
                                    $("#max_file_size").val(sizeValue);
                                    updateSizeDisplay(sizeValue);
                                });
                                
                                // Format and display the selected size
                                function updateSizeDisplay(size) {
                                    var displayText = '';
                                    if (size >= 1024) {
                                        displayText = (size / 1024).toFixed(1) + ' MB';
                                    } else {
                                        displayText = size + ' KB';
                                    }
                                    $("#max_file_size_display").text(displayText);
                                    
                                    // Set slider to correct position
                                    var position = sizeValues.indexOf(parseInt(size));
                                    if (position === -1) {
                                        // Find closest value if exact match not found
                                        position = 0;
                                        var minDiff = Math.abs(sizeValues[0] - size);
                                        for (var i = 1; i < sizeValues.length; i++) {
                                            var diff = Math.abs(sizeValues[i] - size);
                                            if (diff < minDiff) {
                                                minDiff = diff;
                                                position = i;
                                            }
                                        }
                                    }
                                    $("#max_file_size_slider").val(position);
                                }
                            });
                        </script>
                        <p class="description">
                            <?php esc_html_e('Maximum file size for images to be optimized or migrated directly (range: 40KB - 5MB)', 'bunny-media-offload'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Register admin scripts and styles
     */
    public function register_admin_scripts() {
        // Only register scripts on plugin pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'bunny-media') === false) {
            return;
        }
        
        $plugin_url = plugin_dir_url(dirname(__FILE__));
        
        // Define a version for assets (use constant if defined, otherwise fallback to a timestamp)
        $version = defined('BUNNY_MEDIA_OFFLOAD_VERSION') ? BUNNY_MEDIA_OFFLOAD_VERSION : time();
        
        // Register and enqueue styles
        wp_register_style('bunny-admin-css', $plugin_url . 'assets/css/admin.css', array(), $version);
        wp_enqueue_style('bunny-admin-css');
        
        // Register scripts
        wp_register_script('bunny-admin-js', $plugin_url . 'assets/js/admin.js', array('jquery'), $version, true);
        
        // Unified Statistics Module (loads on all plugin pages)
        wp_register_script('bunny-stats-js', $plugin_url . 'assets/js/bunny-stats.js', array('jquery'), $version, true);
        
        // Migration script (only loaded on migration page)
        wp_register_script('bunny-migration-js', $plugin_url . 'assets/js/bunny-migration.js', array('jquery'), $version, true);
        
        // Optimization script (only loaded on optimization page)
        wp_register_script('bunny-optimization-js', $plugin_url . 'assets/js/bunny-optimization.js', array('jquery'), $version, true);
        
        // Enqueue the common scripts for all plugin pages
        wp_enqueue_script('bunny-admin-js');
        wp_enqueue_script('bunny-stats-js');
        
        // Localize script with ajax url and nonce
        wp_localize_script('bunny-admin-js', 'bunnyAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bunny_ajax_nonce'),
            'pull_timeout' => $this->settings->get('pull_timeout', 30),
            'preview_domain' => $this->settings->get('custom_hostname', '')
        ));
        
        // Enqueue page-specific scripts
        if (isset($_GET['page'])) {
            if ($_GET['page'] === 'bunny-media-offload-migration') {
                wp_enqueue_script('bunny-migration-js');
            } elseif ($_GET['page'] === 'bunny-media-offload-optimization') {
                wp_enqueue_script('bunny-optimization-js');
            }
        }
    }
    
    /**
     * Render a donut chart segment
     */
    private function render_donut_segment($cx, $cy, $r, $start_angle, $segment_angle, $color, $class = '') {
        // Convert angles to radians
        $start_rad = deg2rad($start_angle);
        $end_rad = deg2rad($start_angle + $segment_angle);
        
        // Calculate start and end points
        $start_x = $cx + $r * cos($start_rad);
        $start_y = $cy + $r * sin($start_rad);
        $end_x = $cx + $r * cos($end_rad);
        $end_y = $cy + $r * sin($end_rad);
        
        // Determine if arc should take the large-arc-flag
        $large_arc = $segment_angle > 180 ? 1 : 0;
        
        // Create SVG path
        $path = "M {$cx},{$cy} L {$start_x},{$start_y} A {$r},{$r} 0 {$large_arc},1 {$end_x},{$end_y} Z";
        
        // Output SVG path element
        printf(
            '<path d="%s" fill="%s" class="%s"></path>',
            esc_attr($path),
            esc_attr($color),
            esc_attr($class)
        );
    }
    
    /**
     * Mask sensitive keys for display
     * 
     * @param string $key The API key to mask
     * @return string The masked API key
     */
    private function mask_key($key) {
        if (empty($key)) {
            return '';
        }
        
        $length = strlen($key);
        $visible_chars = min(4, $length);
        $masked_length = $length - $visible_chars;
        
        return str_repeat('•', $masked_length) . substr($key, -$visible_chars);
    }
    
    /**
     * Convert file size to slider position value
     * 
     * @param int $size File size in KB
     * @return int Slider position (0-9)
     */
    private function get_size_slider_value($size) {
        // Define size values corresponding to slider positions
        $size_values = array(40, 50, 70, 100, 150, 200, 500, 1024, 2048, 4096);
        
        // Find closest position
        $position = 0;
        $min_diff = abs($size_values[0] - $size);
        
        for ($i = 1; $i < count($size_values); $i++) {
            $diff = abs($size_values[$i] - $size);
            if ($diff < $min_diff) {
                $min_diff = $diff;
                $position = $i;
            }
        }
        
        return $position;
    }
} 