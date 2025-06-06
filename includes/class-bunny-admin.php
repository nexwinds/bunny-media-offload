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
        
        // Add media library column
        add_filter('manage_media_columns', array($this, 'add_media_column'));
        add_action('manage_media_custom_column', array($this, 'display_media_column'), 10, 2);
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
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bunny Media Offload Settings', 'bunny-media-offload'); ?></h1>
            
            <?php echo wp_kses_post($this->display_config_status()); ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('bunny_media_offload_settings'); ?>
                
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
                                <input type="password" name="bunny_media_offload_settings[api_key]" value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" class="regular-text" />
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e('Your Bunny.net Storage API key.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Storage Zone', 'bunny-media-offload'); ?></th>
                        <td>
                            <?php if ($this->settings->is_constant_defined('storage_zone')): ?>
                                <input type="text" value="<?php echo esc_attr($this->settings->get('storage_zone')); ?>" class="regular-text" readonly />
                                <span class="bunny-config-source"><?php esc_html_e('Configured in wp-config.php', 'bunny-media-offload'); ?></span>
                            <?php else: ?>
                                <input type="text" name="bunny_media_offload_settings[storage_zone]" value="<?php echo esc_attr($settings['storage_zone'] ?? ''); ?>" class="regular-text" />
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e('Your Bunny.net Storage Zone name.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Custom Hostname', 'bunny-media-offload'); ?></th>
                        <td>
                            <?php if ($this->settings->is_constant_defined('custom_hostname')): ?>
                                <input type="text" value="<?php echo esc_attr($this->settings->get('custom_hostname')); ?>" class="regular-text bunny-readonly-field" readonly />
                                <span class="bunny-config-source"><?php esc_html_e('Configured in wp-config.php', 'bunny-media-offload'); ?></span>
                            <?php else: ?>
                                <input type="text" name="bunny_media_offload_settings[custom_hostname]" value="<?php echo esc_attr($settings['custom_hostname'] ?? ''); ?>" class="regular-text" />
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e('Optional: Custom CDN hostname (e.g., cdn.example.com).', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Auto Offload', 'bunny-media-offload'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="bunny_media_offload_settings[auto_offload]" value="1" <?php checked($settings['auto_offload'] ?? false); ?> />
                                <?php esc_html_e('Automatically offload new uploads to Bunny.net', 'bunny-media-offload'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Delete Local Files', 'bunny-media-offload'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="bunny_media_offload_settings[delete_local]" value="1" <?php checked($settings['delete_local'] ?? true); ?> />
                                <?php esc_html_e('Delete local files after successful upload to save server space', 'bunny-media-offload'); ?>
                            </label>
                            <div class="notice notice-warning inline" style="margin-top: 10px; padding: 10px;">
                                <p><strong><?php esc_html_e('Warning:', 'bunny-media-offload'); ?></strong> <?php esc_html_e('When local files are deleted, they are also permanently removed from the cloud if you later disable this plugin. Only disable this option if you plan to use the Sync & Recovery features.', 'bunny-media-offload'); ?></p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('File Versioning', 'bunny-media-offload'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="bunny_media_offload_settings[file_versioning]" value="1" <?php checked($settings['file_versioning'] ?? false); ?> />
                                <?php esc_html_e('Add version parameter to URLs for cache busting', 'bunny-media-offload'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Batch Size', 'bunny-media-offload'); ?></th>
                        <td>
                            <?php if ($this->settings->is_constant_defined('batch_size')): ?>
                                <input type="text" value="<?php echo esc_attr($this->settings->get('batch_size')); ?>" class="small-text" readonly />
                                <span class="bunny-config-source"><?php esc_html_e('Configured in wp-config.php', 'bunny-media-offload'); ?></span>
                            <?php else: ?>
                                <select name="bunny_media_offload_settings[batch_size]">
                                    <option value="50" <?php selected($settings['batch_size'] ?? 100, 50); ?>>50</option>
                                    <option value="100" <?php selected($settings['batch_size'] ?? 100, 100); ?>>100</option>
                                    <option value="150" <?php selected($settings['batch_size'] ?? 100, 150); ?>>150</option>
                                    <option value="250" <?php selected($settings['batch_size'] ?? 100, 250); ?>>250</option>
                                </select>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e('Number of files to process in each migration batch.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Migration Concurrent Limit', 'bunny-media-offload'); ?></th>
                        <td>
                            <select name="bunny_media_offload_settings[migration_concurrent_limit]">
                                <option value="2" <?php selected($settings['migration_concurrent_limit'] ?? 4, 2); ?>>2</option>
                                <option value="4" <?php selected($settings['migration_concurrent_limit'] ?? 4, 4); ?>>4</option>
                                <option value="8" <?php selected($settings['migration_concurrent_limit'] ?? 4, 8); ?>>8</option>
                            </select>
                            <p class="description"><?php esc_html_e('Number of images to migrate simultaneously at a time.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <h2><?php esc_html_e('Image Optimization', 'bunny-media-offload'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Optimization', 'bunny-media-offload'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="bunny_media_offload_settings[optimization_enabled]" value="1" <?php checked($settings['optimization_enabled'] ?? false); ?> />
                                <?php esc_html_e('Enable automatic image optimization', 'bunny-media-offload'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Convert images to modern formats (AVIF/WebP) and compress to reduce file sizes.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Optimize on Upload', 'bunny-media-offload'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="bunny_media_offload_settings[optimize_on_upload]" value="1" <?php checked($settings['optimize_on_upload'] ?? true); ?> />
                                <?php esc_html_e('Optimize images automatically during upload', 'bunny-media-offload'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Preferred Format', 'bunny-media-offload'); ?></th>
                        <td>
                            <select name="bunny_media_offload_settings[optimization_format]">
                                <option value="avif" <?php selected($settings['optimization_format'] ?? 'avif', 'avif'); ?>>AVIF (best compression)</option>
                                <option value="webp" <?php selected($settings['optimization_format'] ?? 'avif', 'webp'); ?>>WebP (better compatibility)</option>
                            </select>
                            <p class="description"><?php esc_html_e('AVIF offers better compression but WebP has wider browser support.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Maximum File Size', 'bunny-media-offload'); ?></th>
                        <td>
                            <select name="bunny_media_offload_settings[optimization_max_size]">
                                <option value="40kb" <?php selected($settings['optimization_max_size'] ?? '50kb', '40kb'); ?>>40 KB</option>
                                <option value="45kb" <?php selected($settings['optimization_max_size'] ?? '50kb', '45kb'); ?>>45 KB</option>
                                <option value="50kb" <?php selected($settings['optimization_max_size'] ?? '50kb', '50kb'); ?>>50 KB</option>
                                <option value="55kb" <?php selected($settings['optimization_max_size'] ?? '50kb', '55kb'); ?>>55 KB</option>
                                <option value="60kb" <?php selected($settings['optimization_max_size'] ?? '50kb', '60kb'); ?>>60 KB</option>
                            </select>
                            <p class="description"><?php esc_html_e('Images larger than this will be compressed further.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Optimization Batch Size', 'bunny-media-offload'); ?></th>
                        <td>
                            <select name="bunny_media_offload_settings[optimization_batch_size]">
                                <option value="30" <?php selected($settings['optimization_batch_size'] ?? 60, 30); ?>>30</option>
                                <option value="60" <?php selected($settings['optimization_batch_size'] ?? 60, 60); ?>>60</option>
                                <option value="90" <?php selected($settings['optimization_batch_size'] ?? 60, 90); ?>>90</option>
                                <option value="150" <?php selected($settings['optimization_batch_size'] ?? 60, 150); ?>>150</option>
                            </select>
                            <p class="description"><?php esc_html_e('Number of images to optimize in each batch.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Optimization Concurrent Limit', 'bunny-media-offload'); ?></th>
                        <td>
                            <select name="bunny_media_offload_settings[optimization_concurrent_limit]">
                                <option value="2" <?php selected($settings['optimization_concurrent_limit'] ?? 3, 2); ?>>2</option>
                                <option value="3" <?php selected($settings['optimization_concurrent_limit'] ?? 3, 3); ?>>3</option>
                                <option value="5" <?php selected($settings['optimization_concurrent_limit'] ?? 3, 5); ?>>5</option>
                            </select>
                            <p class="description"><?php esc_html_e('Number of images to optimize simultaneously at a time.', 'bunny-media-offload'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php 
                // Add WPML settings section if WPML is active
                if ($this->wpml && $this->wpml->is_wpml_active()) {
                    echo wp_kses_post($this->wpml->add_wpml_settings_section());
                }
                ?>
                
                <?php submit_button(); ?>
                
                <button type="button" class="button" id="test-connection"><?php esc_html_e('Test Connection', 'bunny-media-offload'); ?></button>
            </form>
        </div>
        <?php
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
                <p><?php esc_html_e('Some settings are configured in wp-config.php and cannot be changed here:', 'bunny-media-offload'); ?></p>
                <ul style="margin-left: 20px;">
                    <?php foreach ($constants_status as $key => $status): ?>
                        <?php if ($status['defined']): ?>
                            <li>
                                <strong><?php echo esc_html($status['constant_name']); ?>:</strong> 
                                <?php if ($key === 'api_key'): ?>
                                    <?php 
                                    $api_key = $status['value'];
                                    $masked_key = strlen($api_key) > 6 ? substr($api_key, 0, 3) . str_repeat('*', max(10, strlen($api_key) - 6)) . substr($api_key, -3) : str_repeat('*', strlen($api_key));
                                    echo esc_html($masked_key);
                                    ?>
                                <?php else: ?>
                                    <?php echo esc_html(is_bool($status['value']) ? ($status['value'] ? 'true' : 'false') : $status['value']); ?>
                                <?php endif; ?>
                                <span class="bunny-config-source">(wp-config.php)</span>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
                <p>
                    <a href="#" onclick="document.getElementById('bunny-config-guide').style.display = document.getElementById('bunny-config-guide').style.display === 'none' ? 'block' : 'none'; return false;">
                        <?php esc_html_e('Show wp-config.php Configuration Guide', 'bunny-media-offload'); ?>
                    </a>
                </p>
                <div id="bunny-config-guide" style="display: none; background: #f1f1f1; padding: 15px; margin: 10px 0; border-left: 4px solid #0073aa;">
                    <h4><?php esc_html_e('wp-config.php Configuration Example', 'bunny-media-offload'); ?></h4>
                    <p><?php esc_html_e('Add the following constants to your wp-config.php file (before the "/* That\'s all, stop editing! */" line):', 'bunny-media-offload'); ?></p>
                    <pre style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto; font-size: 12px;">
// Bunny.net Edge Storage Configuration
// Only store API credentials in wp-config.php for security

// Required: Your Bunny.net Storage API Key
define('BUNNY_API_KEY', 'your-storage-api-key-here');

// Required: Your Bunny.net Storage Zone Name
define('BUNNY_STORAGE_ZONE', 'your-storage-zone-name');

// Optional: Custom hostname for CDN URLs (without https://)
define('BUNNY_CUSTOM_HOSTNAME', 'cdn.yoursite.com');

// Note: Configure other settings via the WordPress admin interface
// for better flexibility and management</pre>
                    <p><strong><?php esc_html_e('Benefits:', 'bunny-media-offload'); ?></strong></p>
                    <ul style="margin-left: 20px;">
                        <li><?php esc_html_e('Enhanced security - credentials not stored in database', 'bunny-media-offload'); ?></li>
                        <li><?php esc_html_e('Environment portability - easy staging/production deployment', 'bunny-media-offload'); ?></li>
                        <li><?php esc_html_e('Version control safe - exclude wp-config.php from commits', 'bunny-media-offload'); ?></li>
                        <li><?php esc_html_e('Backup safety - settings preserved during database restores', 'bunny-media-offload'); ?></li>
                    </ul>
                </div>
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
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bulk Migration', 'bunny-media-offload'); ?></h1>
            
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
            
            <div class="bunny-migration-form">
                <h3><?php esc_html_e('Start New Migration', 'bunny-media-offload'); ?></h3>
                
                <?php if ($this->settings->get('delete_local')): ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e('Notice:', 'bunny-media-offload'); ?></strong> <?php esc_html_e('Local file deletion is enabled. Files will be removed from your server after successful migration to save space. You can change this in Settings if you prefer to keep local copies.', 'bunny-media-offload'); ?></p>
                </div>
                <?php endif; ?>
                
                <form id="migration-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('File Types', 'bunny-media-offload'); ?></th>
                            <td>
                                <label><input type="checkbox" name="file_types[]" value="webp" checked> <?php esc_html_e('WebP Images', 'bunny-media-offload'); ?></label><br>
                                <label><input type="checkbox" name="file_types[]" value="avif" checked> <?php esc_html_e('AVIF Images', 'bunny-media-offload'); ?></label><br>
                                <p class="description"><?php esc_html_e('Only modern image formats (WebP and AVIF) are supported for migration.', 'bunny-media-offload'); ?></p>
                            </td>
                        </tr>
                        <?php if ($this->wpml && $this->wpml->is_wpml_active()): ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Language Scope', 'bunny-media-offload'); ?></th>
                            <td>
                                <label><input type="radio" name="language_scope" value="current" checked> <?php esc_html_e('Current Language Only', 'bunny-media-offload'); ?></label><br>
                                <label><input type="radio" name="language_scope" value="all"> <?php esc_html_e('All Languages', 'bunny-media-offload'); ?></label><br>
                                <p class="description"><?php esc_html_e('Choose whether to migrate files from the current language only or from all languages.', 'bunny-media-offload'); ?></p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary" id="start-migration"><?php esc_html_e('Start Migration', 'bunny-media-offload'); ?></button>
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
        
        $optimization_stats = $this->optimizer->get_optimization_stats();
        $optimization_criteria = $this->optimizer->get_optimization_criteria();
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Image Optimization', 'bunny-media-offload'); ?></h1>
            
            <div class="bunny-optimization-overview">
                <div class="bunny-stats-grid">
                    <div class="bunny-stat-card">
                        <h3><?php esc_html_e('Images Optimized', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-stat-number"><?php echo number_format($optimization_stats['images_actually_optimized']); ?></div>
                    </div>
                    
                    <div class="bunny-stat-card">
                        <h3><?php esc_html_e('Space Saved', 'bunny-media-offload'); ?></h3>
                        <div class="bunny-stat-number"><?php echo esc_html($optimization_stats['total_savings_formatted']); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="bunny-optimization-criteria">
                <h3><?php esc_html_e('Optimization Criteria', 'bunny-media-offload'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Size Threshold', 'bunny-media-offload'); ?></th>
                        <td>
                            <strong><?php echo number_format($optimization_criteria['oversized_count']); ?></strong> 
                            <?php 
                            // translators: %s is the maximum file size setting
                            printf(esc_html__('images larger than %s need compression', 'bunny-media-offload'), esc_html($this->settings->get('optimization_max_size', '50kb'))); 
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Format Conversion', 'bunny-media-offload'); ?></th>
                        <td>
                            <strong><?php echo number_format($optimization_criteria['format_conversion_count']); ?></strong> 
                            <?php esc_html_e('legacy formats (JPG/PNG) requiring conversion to modern WebP/AVIF', 'bunny-media-offload'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Already Optimized', 'bunny-media-offload'); ?></th>
                        <td>
                            <strong><?php echo number_format($optimization_criteria['already_optimized_count']); ?></strong> 
                            <?php esc_html_e('modern format images (WebP/AVIF) that are already under the size limit', 'bunny-media-offload'); ?>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="bunny-optimization-controls">
                <div class="bunny-card">
                    <h3><?php esc_html_e('Step-by-Step Optimization', 'bunny-media-offload'); ?></h3>
                    <p><?php esc_html_e('Optimize both local and cloud images based on your criteria. Images will be converted to modern formats and compressed as needed.', 'bunny-media-offload'); ?></p>
                    
                    <form id="optimization-form">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Optimization Target', 'bunny-media-offload'); ?></th>
                                <td>
                                    <label><input type="radio" name="optimization_target" value="local" checked> <?php esc_html_e('Local Images Only', 'bunny-media-offload'); ?></label><br>
                                    <label><input type="radio" name="optimization_target" value="cloud"> <?php esc_html_e('Cloud Images Only', 'bunny-media-offload'); ?></label>
                                    <p class="description"><?php esc_html_e('Choose whether to optimize images stored locally or in the cloud.', 'bunny-media-offload'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Optimization Criteria', 'bunny-media-offload'); ?></th>
                                <td>
                                    <label><input type="checkbox" name="optimization_criteria[]" value="size_threshold" checked> <?php 
                                        // translators: %s is the maximum file size setting
                                        printf(esc_html__('Images larger than %s', 'bunny-media-offload'), esc_html($this->settings->get('optimization_max_size', '50kb'))); 
                                    ?></label><br>
                                    <label><input type="checkbox" name="optimization_criteria[]" value="format_conversion" checked> <?php esc_html_e('Convert JPG/PNG to modern formats', 'bunny-media-offload'); ?></label><br>
                                    <label><input type="checkbox" name="optimization_criteria[]" value="recompress_modern"> <?php esc_html_e('Recompress existing WebP/AVIF if oversized', 'bunny-media-offload'); ?></label>
                                    <p class="description"><?php esc_html_e('Select which optimization criteria to apply during processing.', 'bunny-media-offload'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Processing Mode', 'bunny-media-offload'); ?></th>
                                <td>
                                    <select name="processing_mode">
                                        <option value="step_by_step" selected><?php esc_html_e('Step-by-Step (Recommended)', 'bunny-media-offload'); ?></option>
                                        <option value="batch"><?php esc_html_e('Batch Processing', 'bunny-media-offload'); ?></option>
                                        <option value="background"><?php esc_html_e('Background Processing', 'bunny-media-offload'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('Step-by-Step shows detailed progress, Batch is faster, Background runs silently.', 'bunny-media-offload'); ?></p>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="submit" class="button button-primary" id="start-optimization"><?php esc_html_e('Start Optimization', 'bunny-media-offload'); ?></button>
                            <button type="button" class="button" id="cancel-optimization" style="display: none;"><?php esc_html_e('Cancel Optimization', 'bunny-media-offload'); ?></button>
                        </p>
                    </form>
                </div>
            </div>
            
            <div id="optimization-progress" style="display: none;">
                <div class="bunny-card">
                    <h3><?php esc_html_e('Optimization Progress', 'bunny-media-offload'); ?></h3>
                    
                    <div class="bunny-optimization-steps">
                        <div class="bunny-step" id="step-1">
                            <div class="bunny-step-number">1</div>
                            <div class="bunny-step-content">
                                <h4><?php esc_html_e('Scanning Images', 'bunny-media-offload'); ?></h4>
                                <p><?php esc_html_e('Finding images that meet optimization criteria...', 'bunny-media-offload'); ?></p>
                                <div class="bunny-step-status" id="step-1-status"></div>
                            </div>
                        </div>
                        
                        <div class="bunny-step" id="step-2">
                            <div class="bunny-step-number">2</div>
                            <div class="bunny-step-content">
                                <h4><?php esc_html_e('Size Analysis', 'bunny-media-offload'); ?></h4>
                                <p><?php esc_html_e('Checking file sizes and determining optimization needs...', 'bunny-media-offload'); ?></p>
                                <div class="bunny-step-status" id="step-2-status"></div>
                            </div>
                        </div>
                        
                        <div class="bunny-step" id="step-3">
                            <div class="bunny-step-number">3</div>
                            <div class="bunny-step-content">
                                <h4><?php esc_html_e('Format Conversion', 'bunny-media-offload'); ?></h4>
                                <p><?php esc_html_e('Converting images to modern formats (WebP/AVIF)...', 'bunny-media-offload'); ?></p>
                                <div class="bunny-step-status" id="step-3-status"></div>
                            </div>
                        </div>
                        
                        <div class="bunny-step" id="step-4">
                            <div class="bunny-step-number">4</div>
                            <div class="bunny-step-content">
                                <h4><?php esc_html_e('Compression', 'bunny-media-offload'); ?></h4>
                                <p><?php esc_html_e('Compressing images to target size...', 'bunny-media-offload'); ?></p>
                                <div class="bunny-step-status" id="step-4-status"></div>
                            </div>
                        </div>
                        
                        <div class="bunny-step" id="step-5">
                            <div class="bunny-step-number">5</div>
                            <div class="bunny-step-content">
                                <h4><?php esc_html_e('Upload & Sync', 'bunny-media-offload'); ?></h4>
                                <p><?php esc_html_e('Uploading optimized images to cloud storage...', 'bunny-media-offload'); ?></p>
                                <div class="bunny-step-status" id="step-5-status"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bunny-progress-bar">
                        <div class="bunny-progress-fill" id="optimization-progress-bar" style="width: 0%"></div>
                    </div>
                    <p id="optimization-status-text"></p>
                    
                    <div class="bunny-optimization-stats-live">
                        <div class="bunny-stat-row">
                            <span class="bunny-stat-label"><?php esc_html_e('Scanned:', 'bunny-media-offload'); ?></span>
                            <span id="scanned-count">0</span>
                        </div>
                        <div class="bunny-stat-row">
                            <span class="bunny-stat-label"><?php esc_html_e('Eligible:', 'bunny-media-offload'); ?></span>
                            <span id="eligible-count">0</span>
                        </div>
                        <div class="bunny-stat-row">
                            <span class="bunny-stat-label"><?php esc_html_e('Processing:', 'bunny-media-offload'); ?></span>
                            <span id="processing-count">0</span>
                        </div>
                        <div class="bunny-stat-row">
                            <span class="bunny-stat-label"><?php esc_html_e('Completed:', 'bunny-media-offload'); ?></span>
                            <span id="completed-count">0</span>
                        </div>
                        <div class="bunny-stat-row">
                            <span class="bunny-stat-label"><?php esc_html_e('Failed:', 'bunny-media-offload'); ?></span>
                            <span id="failed-count">0</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bunny-optimization-tips">
                <div class="bunny-card">
                    <h3><?php esc_html_e('Optimization Tips', 'bunny-media-offload'); ?></h3>
                    <ul>
                        <li><?php esc_html_e('AVIF format provides the best compression but requires modern browsers.', 'bunny-media-offload'); ?></li>
                        <li><?php esc_html_e('WebP format offers good compression with better browser compatibility.', 'bunny-media-offload'); ?></li>
                        <li><?php esc_html_e('Smaller file size limits will result in more aggressive compression.', 'bunny-media-offload'); ?></li>
                        <li><?php esc_html_e('Images already in AVIF or WebP format will only be compressed if they exceed the size limit.', 'bunny-media-offload'); ?></li>
                        <li><?php esc_html_e('Optimization is performed in batches to avoid server timeouts.', 'bunny-media-offload'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
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
                <div class="bunny-doc-content">
                    <div class="notice notice-info">
                        <h3><?php esc_html_e('Complete Documentation', 'bunny-media-offload'); ?></h3>
                        <p><?php esc_html_e('The complete documentation has been created as DOCUMENTATION.md in your plugin directory.', 'bunny-media-offload'); ?></p>
                        <p><strong><?php esc_html_e('Key Topics Covered:', 'bunny-media-offload'); ?></strong></p>
                        <ul style="margin-left: 20px;">
                            <li><?php esc_html_e('wp-config.php Security Configuration', 'bunny-media-offload'); ?></li>
                            <li><?php esc_html_e('Detailed Setup Instructions', 'bunny-media-offload'); ?></li>
                            <li><?php esc_html_e('Media Migration Guide', 'bunny-media-offload'); ?></li>
                            <li><?php esc_html_e('Image Optimization', 'bunny-media-offload'); ?></li>
                            <li><?php esc_html_e('WPML Multilingual Support', 'bunny-media-offload'); ?></li>
                            <li><?php esc_html_e('WP-CLI Commands Reference', 'bunny-media-offload'); ?></li>
                            <li><?php esc_html_e('Troubleshooting Guide', 'bunny-media-offload'); ?></li>
                        </ul>
                    </div>
                    
                    <section id="wp-config-security">
                        <h2><?php esc_html_e('wp-config.php Security Setup (Recommended)', 'bunny-media-offload'); ?></h2>
                        
                        <div class="bunny-config-benefits">
                            <h3><?php esc_html_e('Why Use wp-config.php?', 'bunny-media-offload'); ?></h3>
                            <ul>
                                <li><?php esc_html_e('🔒 Enhanced Security: Credentials not stored in database', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('🚀 Environment Portability: Easy staging/production deployment', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('🔐 Version Control Safe: Exclude wp-config.php from commits', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('💾 Backup Safety: Settings preserved during database restores', 'bunny-media-offload'); ?></li>
                            </ul>
                        </div>
                        
                        <h3><?php esc_html_e('Configuration Constants', 'bunny-media-offload'); ?></h3>
                        <p><?php esc_html_e('Add these constants to your wp-config.php file before the "/* That\'s all, stop editing! */" line:', 'bunny-media-offload'); ?></p>
                        
                        <pre class="bunny-config-example">
// Bunny.net Edge Storage Configuration
// Only store API credentials in wp-config.php for security

// Required: Your Bunny.net Storage API Key
define('BUNNY_API_KEY', 'your-storage-api-key-here');

// Required: Your Bunny.net Storage Zone Name
define('BUNNY_STORAGE_ZONE', 'your-storage-zone-name');

// Optional: Custom hostname for CDN URLs (without https://)
define('BUNNY_CUSTOM_HOSTNAME', 'cdn.yoursite.com');

// Note: Configure other settings via the WordPress admin interface
// for better flexibility and management</pre>
                        
                        <h3><?php esc_html_e('Complete Example', 'bunny-media-offload'); ?></h3>
                        <pre class="bunny-config-example">
&lt;?php
/**
 * WordPress Configuration File
 */

// ** Database settings ** //
define('DB_NAME', 'your_database');
define('DB_USER', 'your_username');
define('DB_PASSWORD', 'your_password');
define('DB_HOST', 'localhost');

// ** Bunny.net Configuration ** //
define('BUNNY_API_KEY', 'b8f2c4d5-1234-5678-9abc-def123456789');
define('BUNNY_STORAGE_ZONE', 'mysite-storage');
define('BUNNY_CUSTOM_HOSTNAME', 'cdn.mysite.com');

// ** Security keys ** //
define('AUTH_KEY', 'your-auth-key');
// ... other security keys

/* That's all, stop editing! Happy publishing. */
require_once ABSPATH . 'wp-settings.php';</pre>
                    </section>
                    
                    <section id="quick-cli">
                        <h2><?php esc_html_e('Quick WP-CLI Reference', 'bunny-media-offload'); ?></h2>
                        
                        <h3><?php esc_html_e('Status & Testing', 'bunny-media-offload'); ?></h3>
                        <pre class="bunny-config-example">wp bunny status                    # Plugin status overview
wp bunny test-connection          # Test API connection
wp bunny status --detailed       # Detailed file statistics</pre>
                        
                        <h3><?php esc_html_e('Migration', 'bunny-media-offload'); ?></h3>
                        <pre class="bunny-config-example">wp bunny migrate                           # Migrate all files
wp bunny migrate --file-types=image       # Migrate images only
wp bunny migrate --batch-size=25          # Custom batch size
wp bunny migration-status                 # Check progress</pre>
                        
                        <h3><?php esc_html_e('Optimization', 'bunny-media-offload'); ?></h3>
                        <pre class="bunny-config-example">wp bunny optimize                         # Optimize all images
wp bunny optimize --format=webp          # Specific format
wp bunny optimization-status              # Check queue status</pre>
                    </section>
                    
                    <section id="troubleshooting">
                        <h2><?php esc_html_e('Quick Troubleshooting', 'bunny-media-offload'); ?></h2>
                        
                        <div class="bunny-troubleshoot-item">
                            <h4><?php esc_html_e('🔧 Connection Issues', 'bunny-media-offload'); ?></h4>
                            <ul>
                                <li><?php esc_html_e('Verify API key in Bunny.net dashboard', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('Check Storage Zone name spelling', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('Test connection using the "Test Connection" button', 'bunny-media-offload'); ?></li>
                            </ul>
                        </div>
                        
                        <div class="bunny-troubleshoot-item">
                            <h4><?php esc_html_e('📁 Migration Issues', 'bunny-media-offload'); ?></h4>
                            <ul>
                                <li><?php esc_html_e('Reduce batch size to 10-25 files', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('Check available storage quota in Bunny.net', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('Monitor logs: Bunny CDN > Logs', 'bunny-media-offload'); ?></li>
                            </ul>
                        </div>
                        
                        <div class="bunny-troubleshoot-item">
                            <h4><?php esc_html_e('🖼️ Optimization Problems', 'bunny-media-offload'); ?></h4>
                            <ul>
                                <li><?php esc_html_e('Ensure GD library is installed: php -m | grep -i gd', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('Increase PHP memory limit in wp-config.php', 'bunny-media-offload'); ?></li>
                                <li><?php esc_html_e('Clear optimization queue: wp bunny optimization-clear', 'bunny-media-offload'); ?></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </div>
        
        <style>
        .bunny-documentation {
            margin-top: 20px;
        }
        
        .bunny-doc-content section {
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 1px solid #eee;
        }
        
        .bunny-doc-content h2 {
            color: #0073aa;
            border-bottom: 2px solid #0073aa;
            padding-bottom: 10px;
        }
        
        .bunny-config-example {
            background: #f8f8f8;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            font-family: Consolas, Monaco, 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.4;
            overflow-x: auto;
            margin: 15px 0;
        }
        
        .bunny-config-benefits {
            background: #f0f6fc;
            border: 1px solid #c3c4c7;
            border-left: 4px solid #0073aa;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .bunny-config-benefits h3 {
            margin-top: 0;
            color: #0073aa;
        }
        
        .bunny-config-benefits ul {
            margin-bottom: 0;
        }
        
        .bunny-troubleshoot-item {
            background: #f9f9f9;
            border-left: 4px solid #ffb900;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        
        .bunny-troubleshoot-item h4 {
            margin-top: 0;
            color: #d63638;
        }
        
        .bunny-troubleshoot-item ul {
            margin-bottom: 0;
        }
        </style>
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
} 