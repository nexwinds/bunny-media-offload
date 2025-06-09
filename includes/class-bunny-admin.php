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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameter for admin tab navigation
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'connection';
        
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
                <?php settings_fields('bunny_json_settings'); ?>
                    
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
     * Render Image Optimization Settings
     */
    private function render_optimization_settings($settings) {
        ?>
        <div class="bunny-settings-section">
            <h3><?php esc_html_e('Image Optimization', 'bunny-media-offload'); ?></h3>
            
            <div class="bunny-info-box">
                <p><?php esc_html_e('Image optimization converts your images to AVIF format and compresses them to reduce file sizes and improve loading speeds. Optimization can be done manually from the Optimization page.', 'bunny-media-offload'); ?></p>
            </div>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Maximum File Size', 'bunny-media-offload'); ?></th>
                    <td>
                        <select name="bunny_json_settings[optimization_max_size]">
                            <option value="40kb" <?php selected($settings['optimization_max_size'] ?? '50kb', '40kb'); ?>>40 KB</option>
                            <option value="45kb" <?php selected($settings['optimization_max_size'] ?? '50kb', '45kb'); ?>>45 KB</option>
                            <option value="50kb" <?php selected($settings['optimization_max_size'] ?? '50kb', '50kb'); ?>>50 KB (recommended)</option>
                            <option value="55kb" <?php selected($settings['optimization_max_size'] ?? '50kb', '55kb'); ?>>55 KB</option>
                            <option value="60kb" <?php selected($settings['optimization_max_size'] ?? '50kb', '60kb'); ?>>60 KB</option>
                        </select>
                        <p class="description"><?php esc_html_e('Images larger than this threshold will be recompressed to AVIF format. Smaller files are usually already well optimized.', 'bunny-media-offload'); ?></p>
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
                            <select name="bunny_json_settings[batch_size]">
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
                        <select name="bunny_json_settings[migration_concurrent_limit]">
                            <option value="2" <?php selected($settings['migration_concurrent_limit'] ?? 4, 2); ?>>2 (safe)</option>
                            <option value="4" <?php selected($settings['migration_concurrent_limit'] ?? 4, 4); ?>>4 (recommended)</option>
                            <option value="8" <?php selected($settings['migration_concurrent_limit'] ?? 4, 8); ?>>8 (fast)</option>
                        </select>
                        <p class="description"><?php esc_html_e('Number of images to migrate simultaneously. Use lower values for shared hosting.', 'bunny-media-offload'); ?></p>
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
        // Use only detailed stats to ensure consistency across all displays
        $detailed_stats = $this->migration->get_migration_stats(true);
        $migration_stats = $detailed_stats; // Use same data source for consistency
        $max_file_size = $this->settings->get('optimization_max_size', '50kb');
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bulk Migration', 'bunny-media-offload'); ?></h1>
            
            <div class="bunny-migration-info">
                <div class="notice notice-info">
                    <h3><?php esc_html_e('Migration Requirements', 'bunny-media-offload'); ?></h3>
                    <p><?php 
                        printf(
                            // translators: %s is the maximum file size setting
                            esc_html__('Only images in SVG, AVIF or WebP format with a maximum file size lower than %s will be migrated. Images in other formats or exceeding this size limit will be skipped.', 'bunny-media-offload'), 
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
                            <span><?php echo esc_html(number_format($detailed_stats['svg_total'] ?? 0)); ?> SVG</span> • 
                            <span><?php echo esc_html(number_format($detailed_stats['avif_total'] ?? 0)); ?> AVIF</span> • 
                            <span><?php echo esc_html(number_format($detailed_stats['webp_total'] ?? 0)); ?> WebP</span>
                        </div>
                    </div>
                    
                    <div class="bunny-stat-card">
                        <h4><?php esc_html_e('Images per Batch', 'bunny-media-offload'); ?></h4>
                        <div class="bunny-stat-number"><?php echo number_format($detailed_stats['batch_size']); ?></div>
                        <div class="bunny-stat-description"><?php esc_html_e('Configurable in Settings', 'bunny-media-offload'); ?></div>
                    </div>
                    
                    <div class="bunny-stat-card">
                        <h4><?php esc_html_e('Already Migrated', 'bunny-media-offload'); ?></h4>
                        <div class="bunny-stat-number"><?php echo number_format($detailed_stats['total_migrated']); ?></div>
                        <div class="bunny-stat-breakdown">
                            <span><?php echo esc_html(number_format($detailed_stats['svg_migrated'] ?? 0)); ?> SVG</span> • 
                            <span><?php echo esc_html(number_format($detailed_stats['avif_migrated'] ?? 0)); ?> AVIF</span> • 
                            <span><?php echo esc_html(number_format($detailed_stats['webp_migrated'] ?? 0)); ?> WebP</span>
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
                <div id="migration-errors" class="bunny-errors-hidden">
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
                    <div id="thumbnail-regeneration-status" class="bunny-status-hidden">
                        <p id="thumbnail-status-text"></p>
                    </div>
                </div>
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
        
        // Manual optimization is always available when users visit this page
        
        // Debug: Check if we can get stats
        try {
            $detailed_stats = $this->optimizer->get_detailed_optimization_stats();
            
            // Debug: Log the stats we got
            if ($this->logger) {
                $this->logger->log('info', 'Optimization page detailed stats', array(
                    'stats' => $detailed_stats,
                    'is_array' => is_array($detailed_stats),
                    'has_optimizer' => !empty($this->optimizer)
                ));
            }
            
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
            // Log error only in debug mode
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Use WordPress logging instead of error_log for production compatibility
                $this->logger->log_error('Bunny Optimization Stats Error: ' . $e->getMessage());
            }
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
            

            
            <!-- Optimization Statistics -->
            <div class="bunny-stats-grid">
                <div class="bunny-stat-card">
                    <h3><?php esc_html_e('Local Images Eligible', 'bunny-media-offload'); ?></h3>
                    <div class="bunny-stat-number"><?php echo number_format($detailed_stats['local']['total_eligible']); ?></div>
                    <div class="bunny-stat-breakdown">
                        <small><?php echo number_format($detailed_stats['local']['convertible_formats'] ?? 0); ?> JPG/PNG • <?php echo number_format($detailed_stats['local']['compressible_formats'] ?? 0); ?> WebP/AVIF</small>
                    </div>
                </div>
                
                <div class="bunny-stat-card">
                    <h3><?php esc_html_e('Cloud Images Eligible', 'bunny-media-offload'); ?></h3>
                    <div class="bunny-stat-number"><?php echo number_format($detailed_stats['cloud']['total_eligible']); ?></div>
                    <div class="bunny-stat-breakdown">
                        <small><?php echo number_format($detailed_stats['cloud']['convertible_formats'] ?? 0); ?> JPG/PNG • <?php echo number_format($detailed_stats['cloud']['compressible_formats'] ?? 0); ?> WebP/AVIF</small>
                    </div>
                </div>
                
                <div class="bunny-stat-card">
                    <h3><?php esc_html_e('Already Optimized', 'bunny-media-offload'); ?></h3>
                    <div class="bunny-stat-number"><?php echo number_format($detailed_stats['already_optimized']); ?></div>
                    <div class="bunny-stat-description">
                        <small><?php esc_html_e('Images already in optimal format', 'bunny-media-offload'); ?></small>
                    </div>
                </div>
                
                <div class="bunny-stat-card">
                    <h3><?php esc_html_e('Images Migrated', 'bunny-media-offload'); ?></h3>
                    <div class="bunny-stat-number"><?php echo number_format($this->stats->get_dashboard_stats()['total_files']); ?></div>
                    <div class="bunny-stat-description">
                        <small><?php esc_html_e('Images successfully migrated to CDN', 'bunny-media-offload'); ?></small>
                    </div>
                </div>
            </div>
            

            
            <div class="bunny-optimization-actions">
                <h3><?php esc_html_e('Start Image Optimization', 'bunny-media-offload'); ?></h3>
                <p><?php esc_html_e('Choose which images you want to optimize. All images will be converted to AVIF format for best compression.', 'bunny-media-offload'); ?></p>
                
                <!-- Direct Optimization Action Buttons -->
                <div class="bunny-optimization-targets">
                    <div class="bunny-action-buttons">
                        <div class="bunny-action-card">
                            <h4><?php esc_html_e('Local Images', 'bunny-media-offload'); ?></h4>
                            <div class="bunny-action-count"><?php echo number_format($detailed_stats['local']['total_eligible']); ?></div>
                            <div class="bunny-action-description"><?php esc_html_e('Images stored on your server', 'bunny-media-offload'); ?></div>
                            <button type="button" class="button button-primary bunny-optimize-button" data-target="local" 
                                    <?php echo $detailed_stats['local']['has_files_to_optimize'] ? '' : 'disabled'; ?>>
                                <span class="dashicons dashicons-controls-play"></span>
                                <?php esc_html_e('Optimize Local Images', 'bunny-media-offload'); ?>
                            </button>
                        </div>
                        
                        <div class="bunny-action-card">
                            <h4><?php esc_html_e('Cloud Images', 'bunny-media-offload'); ?></h4>
                            <div class="bunny-action-count"><?php echo number_format($detailed_stats['cloud']['total_eligible']); ?></div>
                            <div class="bunny-action-description"><?php esc_html_e('Images stored on Bunny.net CDN', 'bunny-media-offload'); ?></div>
                            <button type="button" class="button button-primary bunny-optimize-button" data-target="cloud" 
                                    <?php echo $detailed_stats['cloud']['has_files_to_optimize'] ? '' : 'disabled'; ?>>
                                <span class="dashicons dashicons-controls-play"></span>
                                <?php esc_html_e('Optimize Cloud Images', 'bunny-media-offload'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <p class="description">
                        <?php if ($detailed_stats['batch_size']): ?>
                            <?php 
                            // translators: %d is the number of images processed per batch
                            echo sprintf(esc_html__('Processing %d images per batch. Each optimization runs independently.', 'bunny-media-offload'), esc_html($detailed_stats['batch_size'])); ?>
                        <?php endif; ?>
                    </p>
                    
                    <div class="bunny-cancel-section bunny-status-hidden">
                        <button type="button" class="button button-secondary" id="cancel-optimization">
                            <span class="dashicons dashicons-no"></span>
                            <?php esc_html_e('Cancel Optimization', 'bunny-media-offload'); ?>
                        </button>
                    </div>
                </div>
            </div>
            
            <div id="optimization-progress" class="bunny-status-hidden">
                <h3><?php esc_html_e('Optimization Progress', 'bunny-media-offload'); ?></h3>
                
                <!-- Overall Progress Bar -->
                <div class="bunny-progress-bar">
                    <div class="bunny-progress-fill" id="optimization-progress-bar" style="width: 0%"></div>
                    <span class="bunny-progress-text" id="optimization-progress-text">0%</span>
                </div>
                <p id="optimization-status-text"></p>
                
                <!-- Current Image Being Processed -->
                <div id="current-image-processing" class="bunny-current-image bunny-status-hidden">
                    <h4><?php esc_html_e('Currently Processing', 'bunny-media-offload'); ?></h4>
                    <div class="bunny-image-card">
                        <div class="bunny-image-thumbnail">
                            <?php
                            // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- Dynamic placeholder image for optimization UI process display, src attribute updated via JavaScript during optimization
                            ?>
                            <img id="current-image-thumb" src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMSIgaGVpZ2h0PSIxIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9InRyYW5zcGFyZW50Ii8+PC9zdmc+" alt="" /> <?php // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- Dynamic placeholder for optimization UI, src updated via JS ?>
                            <div class="bunny-image-overlay">
                                <div class="bunny-spinner"></div>
                            </div>
                        </div>
                        <div class="bunny-image-details">
                            <h5 id="current-image-name"></h5>
                            <div class="bunny-image-status">
                                <!-- Local Image Process -->
                                <div id="local-process" class="bunny-process-step">
                                    <span class="bunny-step-icon">
                                        <span class="dashicons dashicons-format-image"></span>
                                    </span>
                                    <span class="bunny-step-text" id="local-process-text"><?php esc_html_e('Processing...', 'bunny-media-offload'); ?></span>
                                    <span class="bunny-step-spinner bunny-spinner-small"></span>
                                </div>
                                
                                <!-- Cloud Image Process (Download, Process, Upload) -->
                                <div id="cloud-download" class="bunny-process-step bunny-status-hidden">
                                    <span class="bunny-step-icon">
                                        <span class="dashicons dashicons-download"></span>
                                    </span>
                                    <span class="bunny-step-text" id="download-process-text"><?php esc_html_e('Downloading...', 'bunny-media-offload'); ?></span>
                                    <span class="bunny-step-spinner bunny-spinner-small"></span>
                                </div>
                                
                                <div id="cloud-process" class="bunny-process-step bunny-status-hidden">
                                    <span class="bunny-step-icon">
                                        <span class="dashicons dashicons-format-image"></span>
                                    </span>
                                    <span class="bunny-step-text" id="cloud-process-text"><?php esc_html_e('Optimizing...', 'bunny-media-offload'); ?></span>
                                    <span class="bunny-step-spinner bunny-spinner-small"></span>
                                </div>
                                
                                <div id="cloud-upload" class="bunny-process-step bunny-status-hidden">
                                    <span class="bunny-step-icon">
                                        <span class="dashicons dashicons-upload"></span>
                                    </span>
                                    <span class="bunny-step-text" id="upload-process-text"><?php esc_html_e('Uploading...', 'bunny-media-offload'); ?></span>
                                    <span class="bunny-step-spinner bunny-spinner-small"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recently Processed Images -->
                <div id="recent-processed" class="bunny-recent-processed bunny-status-hidden">
                    <h4><?php esc_html_e('Recently Processed', 'bunny-media-offload'); ?></h4>
                    <div class="bunny-processed-list" id="processed-images-list">
                        <!-- Recently processed images will be added here dynamically -->
                    </div>
                </div>
                
                <!-- Real-time Optimization Log -->
                <div id="optimization-log" class="bunny-optimization-log bunny-status-hidden">
                    <h4><?php esc_html_e('Optimization Log', 'bunny-media-offload'); ?></h4>
                    <div class="bunny-log-container" id="optimization-log-container">
                        <div class="bunny-log-entry bunny-log-info">
                            <span class="bunny-log-icon">ℹ️</span>
                            <span class="bunny-log-message"><?php esc_html_e('Optimization logs will appear here...', 'bunny-media-offload'); ?></span>
                            <span class="bunny-log-time"><?php echo esc_html(current_time('H:i:s')); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Errors Section -->
                <div id="optimization-errors" class="bunny-errors-hidden">
                    <h4><?php esc_html_e('Errors', 'bunny-media-offload'); ?></h4>
                    <ul id="optimization-error-list"></ul>
                </div>
            </div>
        </div>
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
        $limit = 100;
        
        // Get logs based on filters
        $logs = $this->get_filtered_logs($log_type, $log_level, $limit);
        $optimization_stats = $this->get_optimization_log_stats();
        
        // Get simplified error counts for offload logs
        $offload_stats = $this->get_simple_log_stats('offload');
        
        ?>
        <div class="wrap">
            <div class="bunny-page-header">
                <h1><?php esc_html_e('Activity Logs', 'bunny-media-offload'); ?></h1>
                <p class="bunny-page-description"><?php esc_html_e('Monitor plugin activity and troubleshoot issues with detailed logging.', 'bunny-media-offload'); ?></p>
            </div>
            
            <!-- Log Filters -->
            <div class="bunny-logs-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="bunny-media-logs" />
                    
                    <div class="bunny-filter-group">
                        <label for="log_type"><?php esc_html_e('Log Type:', 'bunny-media-offload'); ?></label>
                        <select name="log_type" id="log_type">
                            <option value="all" <?php selected($log_type, 'all'); ?>><?php esc_html_e('All Logs', 'bunny-media-offload'); ?></option>
                            <option value="offload" <?php selected($log_type, 'offload'); ?>><?php esc_html_e('Media Offload', 'bunny-media-offload'); ?></option>
                            <option value="optimization" <?php selected($log_type, 'optimization'); ?>><?php esc_html_e('Image Optimization', 'bunny-media-offload'); ?></option>
                        </select>
                    </div>
                    
                    <div class="bunny-filter-group">
                        <label for="log_level"><?php esc_html_e('Log Level:', 'bunny-media-offload'); ?></label>
                        <select name="log_level" id="log_level">
                            <option value="" <?php selected($log_level, ''); ?>><?php esc_html_e('All Levels', 'bunny-media-offload'); ?></option>
                            <option value="info" <?php selected($log_level, 'info'); ?>><?php esc_html_e('Info', 'bunny-media-offload'); ?></option>
                            <option value="warning" <?php selected($log_level, 'warning'); ?>><?php esc_html_e('Warning', 'bunny-media-offload'); ?></option>
                            <option value="error" <?php selected($log_level, 'error'); ?>><?php esc_html_e('Error', 'bunny-media-offload'); ?></option>
                        </select>
                    </div>
                    
                    <div class="bunny-filter-group">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'bunny-media-offload'); ?></button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=bunny-media-logs')); ?>" class="button"><?php esc_html_e('Reset', 'bunny-media-offload'); ?></a>
                    </div>
                </form>
            </div>
            
            <div class="bunny-logs-header">
                <div class="bunny-log-stats">
                    <?php if ($log_type === 'all' || $log_type === 'offload'): ?>
                        <div class="bunny-log-stat-group">
                            <h4><?php esc_html_e('Media Offload', 'bunny-media-offload'); ?></h4>
                            <span class="bunny-log-stat bunny-log-error"><?php esc_html_e('Errors:', 'bunny-media-offload'); ?> <?php echo esc_html($offload_stats['error']); ?></span>
                            <span class="bunny-log-stat bunny-log-warning"><?php esc_html_e('Warnings:', 'bunny-media-offload'); ?> <?php echo esc_html($offload_stats['warning']); ?></span>
                            <span class="bunny-log-stat bunny-log-info"><?php esc_html_e('Info:', 'bunny-media-offload'); ?> <?php echo esc_html($offload_stats['info']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($log_type === 'all' || $log_type === 'optimization'): ?>
                        <div class="bunny-log-stat-group">
                            <h4><?php esc_html_e('Image Optimization', 'bunny-media-offload'); ?></h4>
                            <span class="bunny-log-stat bunny-log-error"><?php esc_html_e('Errors:', 'bunny-media-offload'); ?> <?php echo esc_html($optimization_stats['error']); ?></span>
                            <span class="bunny-log-stat bunny-log-warning"><?php esc_html_e('Warnings:', 'bunny-media-offload'); ?> <?php echo esc_html($optimization_stats['warning']); ?></span>
                            <span class="bunny-log-stat bunny-log-info"><?php esc_html_e('Info:', 'bunny-media-offload'); ?> <?php echo esc_html($optimization_stats['info']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="bunny-log-actions">
                    <button type="button" class="button" id="export-logs" data-log-type="<?php echo esc_attr($log_type); ?>" data-log-level="<?php echo esc_attr($log_level); ?>"><?php esc_html_e('Export Filtered Logs', 'bunny-media-offload'); ?></button>
                    <button type="button" class="button" id="clear-logs" data-log-type="<?php echo esc_attr($log_type); ?>"><?php esc_html_e('Clear Filtered Logs', 'bunny-media-offload'); ?></button>
                </div>
            </div>
            
            <div class="bunny-logs-table">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'bunny-media-offload'); ?></th>
                            <th><?php esc_html_e('Type', 'bunny-media-offload'); ?></th>
                            <th><?php esc_html_e('Level', 'bunny-media-offload'); ?></th>
                            <th><?php esc_html_e('Message', 'bunny-media-offload'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($logs)): ?>
                            <?php foreach ($logs as $log): ?>
                                <?php 
                                $log_category = $this->determine_log_category($log->message);
                                ?>
                                <tr class="bunny-log-<?php echo esc_attr($log->log_level); ?> bunny-log-type-<?php echo esc_attr($log_category); ?>">
                                    <td><?php echo esc_html(Bunny_Utils::format_date($log->date_created)); ?></td>
                                    <td>
                                        <span class="bunny-log-type bunny-log-type-<?php echo esc_attr($log_category); ?>">
                                            <?php echo esc_html($this->get_log_type_label($log_category)); ?>
                                        </span>
                                    </td>
                                    <td><span class="bunny-log-level bunny-log-level-<?php echo esc_attr($log->log_level); ?>"><?php echo esc_html(ucfirst($log->log_level)); ?></span></td>
                                    <td><?php echo esc_html($log->message); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="bunny-empty-state">
                                    <div class="bunny-empty-icon">📄</div>
                                    <p><?php esc_html_e('No logs found matching the selected filters.', 'bunny-media-offload'); ?></p>
                                    <small><?php esc_html_e('Try adjusting your filter criteria or check back later.', 'bunny-media-offload'); ?></small>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (count($logs) >= $limit): ?>
                <div class="bunny-logs-pagination">
                    <p><?php esc_html_e('Showing the most recent 100 entries. Use filters to narrow down results or export for complete logs.', 'bunny-media-offload'); ?></p>
                </div>
            <?php endif; ?>
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
define('BUNNY_CUSTOM_HOSTNAME', 'cdn.yoursite.com'); // Required</pre>
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
                                        <td><?php esc_html_e('Required', 'bunny-media-offload'); ?></td>
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
                            <pre>wp bunny cleanup-orphaned                # Clean orphaned files
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
     * Get filtered logs based on type and level
     */
    private function get_filtered_logs($log_type, $log_level, $limit) {
        $logs = $this->logger->get_logs($limit, 0, $log_level);
        
        if ($log_type === 'all') {
            return $logs;
        }
        
        // Filter logs by type based on message content
        $filtered_logs = array();
        foreach ($logs as $log) {
            $category = $this->determine_log_category($log->message);
            if ($log_type === $category) {
                $filtered_logs[] = $log;
            }
        }
        
        return $filtered_logs;
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
} 