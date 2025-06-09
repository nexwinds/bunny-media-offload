<?php
/**
 * Test Connection Components
 * Reusable components for testing API connections
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render Bunny Storage API Test Component
 */
function bunny_render_storage_test_component($settings) {
    ?>
    <div class="bunny-test-card">
        <div class="bunny-test-header">
            <h2><?php esc_html_e('Bunny Storage API', 'bunny-media-offload'); ?></h2>
            <p><?php esc_html_e('Test connection to Bunny.net Edge Storage for media offloading.', 'bunny-media-offload'); ?></p>
        </div>
        
        <div class="bunny-test-content">
            <div class="bunny-test-status" id="bunny-storage-status">
                <span class="bunny-status-indicator" id="bunny-storage-indicator">⚪</span>
                <span class="bunny-status-text" id="bunny-storage-text"><?php esc_html_e('Ready to test', 'bunny-media-offload'); ?></span>
            </div>
            
            <div class="bunny-test-details" id="bunny-storage-details">
                <strong><?php esc_html_e('Configuration:', 'bunny-media-offload'); ?></strong>
                <ul>
                    <li><strong><?php esc_html_e('API Key:', 'bunny-media-offload'); ?></strong> <?php echo !empty($settings['api_key']) ? esc_html__('Configured', 'bunny-media-offload') : esc_html__('Not configured', 'bunny-media-offload'); ?></li>
                    <li><strong><?php esc_html_e('Storage Zone:', 'bunny-media-offload'); ?></strong> <?php echo !empty($settings['storage_zone']) ? esc_html($settings['storage_zone']) : esc_html__('Not configured', 'bunny-media-offload'); ?></li>
                    <li><strong><?php esc_html_e('CDN URL:', 'bunny-media-offload'); ?></strong> <?php echo !empty($settings['cdn_url']) ? esc_html($settings['cdn_url']) : esc_html__('Not configured', 'bunny-media-offload'); ?></li>
                </ul>
            </div>
            
            <button type="button" class="button button-primary bunny-test-btn" id="test-bunny-storage" 
                    <?php echo (empty($settings['api_key']) || empty($settings['storage_zone'])) ? 'disabled' : ''; ?>>
                <span class="bunny-btn-text"><?php esc_html_e('Test Connection', 'bunny-media-offload'); ?></span>
                <span class="bunny-btn-loading" style="display: none;"><?php esc_html_e('Testing...', 'bunny-media-offload'); ?></span>
            </button>
        </div>
    </div>
    <?php
}

/**
 * Render BMO Nexwinds API Test Component
 */
function bunny_render_bmo_test_component($settings) {
    ?>
    <div class="bunny-test-card">
        <div class="bunny-test-header">
            <h2><?php esc_html_e('BMO Nexwinds API', 'bunny-media-offload'); ?></h2>
            <p><?php esc_html_e('Test connection to BMO API for image optimization services.', 'bunny-media-offload'); ?></p>
        </div>
        
        <div class="bunny-test-content">
            <div class="bunny-test-status" id="bmo-status">
                <span class="bunny-status-indicator" id="bmo-indicator">⚪</span>
                <span class="bunny-status-text" id="bmo-text"><?php esc_html_e('Ready to test', 'bunny-media-offload'); ?></span>
            </div>
            
            <div class="bunny-test-details" id="bmo-details">
                <strong><?php esc_html_e('Configuration:', 'bunny-media-offload'); ?></strong>
                <ul>
                    <li><strong><?php esc_html_e('API Key:', 'bunny-media-offload'); ?></strong> <?php echo !empty($settings['bmo_api_key']) ? esc_html__('Configured', 'bunny-media-offload') : esc_html__('Not configured', 'bunny-media-offload'); ?></li>
                    <li><strong><?php esc_html_e('Region:', 'bunny-media-offload'); ?></strong> <?php echo !empty($settings['bmo_api_region']) ? esc_html(strtoupper($settings['bmo_api_region'])) : esc_html__('US (default)', 'bunny-media-offload'); ?></li>
                    <li><strong><?php esc_html_e('Quality:', 'bunny-media-offload'); ?></strong> <?php echo !empty($settings['optimization_quality']) ? esc_html($settings['optimization_quality']) . '%' : esc_html__('85% (default)', 'bunny-media-offload'); ?></li>
                </ul>
            </div>
            
            <button type="button" class="button button-primary bunny-test-btn" id="test-bmo-api" 
                    <?php echo empty($settings['bmo_api_key']) ? 'disabled' : ''; ?>>
                <span class="bunny-btn-text"><?php esc_html_e('Test Connection', 'bunny-media-offload'); ?></span>
                <span class="bunny-btn-loading" style="display: none;"><?php esc_html_e('Testing...', 'bunny-media-offload'); ?></span>
            </button>
        </div>
    </div>
    <?php
}

/**
 * Render Test Connection JavaScript
 */
function bunny_render_test_connection_scripts() {
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Bunny Storage API Test
        $('#test-bunny-storage').on('click', function() {
            var $btn = $(this);
            var $indicator = $('#bunny-storage-indicator');
            var $text = $('#bunny-storage-text');
            
            $btn.prop('disabled', true);
            $btn.find('.bunny-btn-text').hide();
            $btn.find('.bunny-btn-loading').show();
            $indicator.text('🔄');
            $text.text('<?php esc_html_e('Testing connection...', 'bunny-media-offload'); ?>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_test_connection',
                    nonce: '<?php echo esc_js(wp_create_nonce('bunny_ajax_nonce')); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $indicator.text('✅');
                        $text.html('<span style="color: #46b450;">' + response.data.message + '</span>');
                    } else {
                        $indicator.text('❌');
                        $text.html('<span style="color: #dc3232;">' + response.data.message + '</span>');
                    }
                },
                error: function() {
                    $indicator.text('❌');
                    $text.html('<span style="color: #dc3232;"><?php esc_html_e('Connection test failed', 'bunny-media-offload'); ?></span>');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $btn.find('.bunny-btn-text').show();
                    $btn.find('.bunny-btn-loading').hide();
                }
            });
        });
        
        // BMO API Test
        $('#test-bmo-api').on('click', function() {
            var $btn = $(this);
            var $indicator = $('#bmo-indicator');
            var $text = $('#bmo-text');
            
            $btn.prop('disabled', true);
            $btn.find('.bunny-btn-text').hide();
            $btn.find('.bunny-btn-loading').show();
            $indicator.text('🔄');
            $text.text('<?php esc_html_e('Testing connection...', 'bunny-media-offload'); ?>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_test_bmo_connection',
                    nonce: '<?php echo esc_js(wp_create_nonce('bunny_ajax_nonce')); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $indicator.text('✅');
                        var message = response.data.message;
                        if (response.data.data) {
                            message += '<br><small>Region: ' + response.data.data.region + ', API Version: ' + response.data.data.api_version + '</small>';
                            if (response.data.data.credits_remaining !== 'unknown') {
                                message += '<br><small>Credits Remaining: ' + response.data.data.credits_remaining + '</small>';
                            }
                        }
                        $text.html('<span style="color: #46b450;">' + message + '</span>');
                    } else {
                        $indicator.text('❌');
                        $text.html('<span style="color: #dc3232;">' + response.data.message + '</span>');
                    }
                },
                error: function() {
                    $indicator.text('❌');
                    $text.html('<span style="color: #dc3232;"><?php esc_html_e('Connection test failed', 'bunny-media-offload'); ?></span>');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $btn.find('.bunny-btn-text').show();
                    $btn.find('.bunny-btn-loading').hide();
                }
            });
        });
    });
    </script>
    <?php
}

/**
 * Render Test Connection Styles
 */
function bunny_render_test_connection_styles() {
    ?>
    <style>
    .bunny-test-connections {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-top: 20px;
    }
    
    .bunny-test-card {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .bunny-test-header h2 {
        margin: 0 0 10px 0;
        color: #1e73be;
        font-size: 18px;
    }
    
    .bunny-test-header p {
        margin: 0 0 20px 0;
        color: #666;
    }
    
    .bunny-test-status {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 15px;
        padding: 10px;
        background: #f8f9fa;
        border-radius: 4px;
    }
    
    .bunny-status-indicator {
        font-size: 18px;
        line-height: 1;
    }
    
    .bunny-status-text {
        font-weight: 500;
    }
    
    .bunny-test-details {
        margin-bottom: 20px;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 4px;
    }
    
    .bunny-test-details ul {
        margin: 10px 0 0 0;
        list-style: none;
        padding: 0;
    }
    
    .bunny-test-details li {
        padding: 5px 0;
        border-bottom: 1px solid #eee;
    }
    
    .bunny-test-details li:last-child {
        border-bottom: none;
    }
    
    .bunny-test-btn {
        position: relative;
        min-width: 140px;
    }
    
    .bunny-test-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    
    @media (max-width: 768px) {
        .bunny-test-connections {
            grid-template-columns: 1fr;
        }
    }
    </style>
    <?php
}

/**
 * Complete Test Connection Page Render Function
 */
function bunny_render_complete_test_page($settings) {
    ?>
    <div class="bunny-test-connections">
        <?php 
        bunny_render_storage_test_component($settings);
        bunny_render_bmo_test_component($settings);
        ?>
    </div>
    
    <?php
    bunny_render_test_connection_scripts();
    bunny_render_test_connection_styles();
} 