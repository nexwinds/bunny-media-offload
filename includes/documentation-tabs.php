<?php
/**
 * Documentation page tabs content
 * 
 * @package Bunny_Media_Offload
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
?>

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