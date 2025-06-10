<?php
/**
 * Test Connection Components
 * 
 * This file contains components for testing API connections
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render the complete test page
 */
function bunny_render_complete_test_page($settings) {
    // Get API credentials
    $api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
    $storage_zone = isset($settings['storage_zone']) ? $settings['storage_zone'] : '';
    $custom_hostname = isset($settings['custom_hostname']) ? $settings['custom_hostname'] : '';
    $bmo_api_key = isset($settings['bmo_api_key']) ? $settings['bmo_api_key'] : '';
    $bmo_api_region = isset($settings['bmo_api_region']) ? $settings['bmo_api_region'] : 'us';
    
    // Determine if settings are coming from constants
    $api_key_from_constant = defined('BUNNY_API_KEY');
    $storage_zone_from_constant = defined('BUNNY_STORAGE_ZONE');
    $custom_hostname_from_constant = defined('BUNNY_CUSTOM_HOSTNAME');
    $bmo_api_key_from_constant = defined('BMO_API_KEY');
    $bmo_api_region_from_constant = defined('BMO_API_REGION');
    
    // Basic configuration check
    $has_required_settings = !empty($api_key) && !empty($storage_zone) && !empty($custom_hostname);
    $has_bmo_settings = !empty($bmo_api_key);
    
    ?>
    <div class="bunny-test-connection-page">
        <!-- Storage API Test -->
        <div class="bunny-card">
            <h3><?php esc_html_e('Bunny.net Storage API Connection Test', 'bunny-media-offload'); ?></h3>
            
            <div class="bunny-test-section">
                <h4><?php esc_html_e('Configuration Status', 'bunny-media-offload'); ?></h4>
                <ul class="bunny-test-checks">
                    <li class="<?php echo !empty($api_key) ? 'success' : 'error'; ?>">
                        <span class="dashicons <?php echo !empty($api_key) ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                        <span class="test-label"><?php esc_html_e('API Key', 'bunny-media-offload'); ?></span>
                        <span class="test-status">
                            <?php echo !empty($api_key) ? esc_html__('Configured', 'bunny-media-offload') : esc_html__('Not Configured', 'bunny-media-offload'); ?>
                            <?php echo $api_key_from_constant ? ' (' . esc_html__('via wp-config.php', 'bunny-media-offload') . ')' : ''; ?>
                        </span>
                    </li>
                    <li class="<?php echo !empty($storage_zone) ? 'success' : 'error'; ?>">
                        <span class="dashicons <?php echo !empty($storage_zone) ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                        <span class="test-label"><?php esc_html_e('Storage Zone', 'bunny-media-offload'); ?></span>
                        <span class="test-status">
                            <?php echo !empty($storage_zone) ? esc_html__('Configured', 'bunny-media-offload') : esc_html__('Not Configured', 'bunny-media-offload'); ?>
                            <?php echo $storage_zone_from_constant ? ' (' . esc_html__('via wp-config.php', 'bunny-media-offload') . ')' : ''; ?>
                        </span>
                    </li>
                    <li class="<?php echo !empty($custom_hostname) ? 'success' : 'error'; ?>">
                        <span class="dashicons <?php echo !empty($custom_hostname) ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                        <span class="test-label"><?php esc_html_e('Custom Hostname', 'bunny-media-offload'); ?></span>
                        <span class="test-status">
                            <?php echo !empty($custom_hostname) ? esc_html__('Configured', 'bunny-media-offload') : esc_html__('Not Configured', 'bunny-media-offload'); ?>
                            <?php echo $custom_hostname_from_constant ? ' (' . esc_html__('via wp-config.php', 'bunny-media-offload') . ')' : ''; ?>
                        </span>
                    </li>
                </ul>
                
                <?php if (!$has_required_settings): ?>
                <div class="bunny-test-notice error">
                    <p>
                        <?php 
                        printf(
                            // translators: %s is the URL to the settings page
                            esc_html__('One or more required settings are missing. Please configure them in the %s.', 'bunny-media-offload'),
                            '<a href="' . esc_url(admin_url('admin.php?page=bunny-media-offload-settings')) . '">' . esc_html__('settings', 'bunny-media-offload') . '</a>'
                        ); 
                        ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="bunny-test-section">
                <h4><?php esc_html_e('Connection Test', 'bunny-media-offload'); ?></h4>
                <p><?php esc_html_e('Test your connection to the Bunny.net Storage API.', 'bunny-media-offload'); ?></p>
                
                <div class="bunny-test-actions">
                    <button 
                        id="test-storage-connection" 
                        class="button button-primary" 
                        <?php echo $has_required_settings ? '' : 'disabled'; ?>
                        data-action="bunny_test_connection"
                    >
                        <?php esc_html_e('Test Storage Connection', 'bunny-media-offload'); ?>
                    </button>
                </div>
                
                <div id="storage-test-results" class="bunny-test-results" style="display: none;"></div>
            </div>
        </div>
        
        <!-- BMO API Test -->
        <div class="bunny-card">
            <h3><?php esc_html_e('BMO Image Optimization API Test', 'bunny-media-offload'); ?></h3>
            
            <div class="bunny-test-section">
                <h4><?php esc_html_e('Configuration Status', 'bunny-media-offload'); ?></h4>
                <ul class="bunny-test-checks">
                    <li class="<?php echo !empty($bmo_api_key) ? 'success' : 'error'; ?>">
                        <span class="dashicons <?php echo !empty($bmo_api_key) ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                        <span class="test-label"><?php esc_html_e('BMO API Key', 'bunny-media-offload'); ?></span>
                        <span class="test-status">
                            <?php echo !empty($bmo_api_key) ? esc_html__('Configured', 'bunny-media-offload') : esc_html__('Not Configured', 'bunny-media-offload'); ?>
                            <?php echo $bmo_api_key_from_constant ? ' (' . esc_html__('via wp-config.php', 'bunny-media-offload') . ')' : ''; ?>
                        </span>
                    </li>
                    <li class="success">
                        <span class="dashicons dashicons-yes"></span>
                        <span class="test-label"><?php esc_html_e('BMO API Region', 'bunny-media-offload'); ?></span>
                        <span class="test-status">
                            <?php echo esc_html(strtoupper($bmo_api_region)); ?>
                            <?php echo $bmo_api_region_from_constant ? ' (' . esc_html__('via wp-config.php', 'bunny-media-offload') . ')' : ''; ?>
                        </span>
                    </li>
                    <li class="<?php echo is_ssl() ? 'success' : 'error'; ?>">
                        <span class="dashicons <?php echo is_ssl() ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                        <span class="test-label"><?php esc_html_e('HTTPS Enabled', 'bunny-media-offload'); ?></span>
                        <span class="test-status">
                            <?php echo is_ssl() ? esc_html__('Yes', 'bunny-media-offload') : esc_html__('No', 'bunny-media-offload'); ?>
                        </span>
                    </li>
                </ul>
                
                <?php if (!$has_bmo_settings || !is_ssl()): ?>
                <div class="bunny-test-notice error">
                    <p>
                        <?php 
                        if (!$has_bmo_settings) {
                            printf(
                                // translators: %s is the URL to the settings page
                                esc_html__('BMO API key is missing. Please configure it in the %s.', 'bunny-media-offload'),
                                '<a href="' . esc_url(admin_url('admin.php?page=bunny-media-offload-settings')) . '">' . esc_html__('settings', 'bunny-media-offload') . '</a>'
                            );
                        }
                        
                        if (!is_ssl()) {
                            echo esc_html__('HTTPS is required for the BMO API. Please enable HTTPS on your site.', 'bunny-media-offload');
                        }
                        ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="bunny-test-section">
                <h4><?php esc_html_e('Connection Test', 'bunny-media-offload'); ?></h4>
                <p><?php esc_html_e('Test your connection to the BMO Image Optimization API.', 'bunny-media-offload'); ?></p>
                
                <div class="bunny-test-actions">
                    <button 
                        id="test-bmo-connection" 
                        class="button button-primary" 
                        <?php echo ($has_bmo_settings && is_ssl()) ? '' : 'disabled'; ?>
                        data-action="bunny_test_bmo_connection"
                    >
                        <?php esc_html_e('Test BMO API Connection', 'bunny-media-offload'); ?>
                    </button>
                </div>
                
                <div id="bmo-test-results" class="bunny-test-results" style="display: none;"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Test Storage Connection
            $('#test-storage-connection').on('click', function() {
                var $button = $(this);
                var $results = $('#storage-test-results');
                
                $button.prop('disabled', true).text('<?php echo esc_js(__('Testing...', 'bunny-media-offload')); ?>');
                $results.hide().empty();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bunny_test_connection',
                        nonce: '<?php echo wp_create_nonce('bunny_ajax_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $results.html('<div class="bunny-test-result success"><span class="dashicons dashicons-yes"></span> ' + response.data + '</div>');
                        } else {
                            $results.html('<div class="bunny-test-result error"><span class="dashicons dashicons-no"></span> ' + response.data + '</div>');
                        }
                        $results.show();
                    },
                    error: function() {
                        $results.html('<div class="bunny-test-result error"><span class="dashicons dashicons-no"></span> <?php echo esc_js(__('AJAX error occurred', 'bunny-media-offload')); ?></div>');
                        $results.show();
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php echo esc_js(__('Test Storage Connection', 'bunny-media-offload')); ?>');
                    }
                });
            });
            
            // Test BMO API Connection
            $('#test-bmo-connection').on('click', function() {
                var $button = $(this);
                var $results = $('#bmo-test-results');
                
                $button.prop('disabled', true).text('<?php echo esc_js(__('Testing...', 'bunny-media-offload')); ?>');
                $results.hide().empty();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bunny_test_bmo_connection',
                        nonce: '<?php echo wp_create_nonce('bunny_ajax_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $results.html('<div class="bunny-test-result success"><span class="dashicons dashicons-yes"></span> ' + response.data.message + '</div>');
                            
                            // If credits info is available
                            if (response.data.credits !== undefined) {
                                $results.append('<div class="bunny-test-info"><strong><?php echo esc_js(__('Available Credits:', 'bunny-media-offload')); ?></strong> ' + response.data.credits + '</div>');
                            }
                        } else {
                            $results.html('<div class="bunny-test-result error"><span class="dashicons dashicons-no"></span> ' + response.data + '</div>');
                        }
                        $results.show();
                    },
                    error: function() {
                        $results.html('<div class="bunny-test-result error"><span class="dashicons dashicons-no"></span> <?php echo esc_js(__('AJAX error occurred', 'bunny-media-offload')); ?></div>');
                        $results.show();
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php echo esc_js(__('Test BMO API Connection', 'bunny-media-offload')); ?>');
                    }
                });
            });
        });
        </script>
    </div>
    <?php
} 