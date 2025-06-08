<?php
/**
 * Plugin Name: BMO WordPress Image Optimization
 * Description: WordPress-focused image optimization with mandatory AVIF conversion
 * Version: 1.0.0
 * Author: BMO
 * License: GPLv3
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BMO_WordPress_Image_Optimizer {
    
    private $api_key;
    private $api_endpoint;
    private $user_threshold_kb;
    
    public function __construct() {
        $this->api_key = get_option('bmo_api_key');
        $this->api_endpoint = get_option('bmo_api_endpoint', 'https://api-eu.bmo.nexwinds.com/v1/images/wp/optimize');
        $this->user_threshold_kb = get_option('bmo_user_threshold_kb', 150);
        
        add_action('init', array($this, 'init'));
        add_filter('wp_handle_upload', array($this, 'optimize_on_upload'));
        add_action('add_attachment', array($this, 'optimize_existing_attachment'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
    }
    
    public function init() {
        // Ensure we have API key configured
        if (!$this->api_key) {
            add_action('admin_notices', array($this, 'missing_api_key_notice'));
        }
    }
    
    /**
     * WordPress requirement: HTTPS is mandatory
     */
    private function ensure_https() {
        if (!is_ssl() && !wp_is_json_request()) {
            wp_die('HTTPS is required for BMO image optimization');
        }
    }
    
    /**
     * Optimize image on upload - WordPress integration point
     */
    public function optimize_on_upload($upload) {
        if (!$this->api_key || !isset($upload['file'])) {
            return $upload;
        }
        
        $this->ensure_https();
        
        $file_path = $upload['file'];
        $file_type = $upload['type'];
        
        // Check if it's an image
        if (!strpos($file_type, 'image/') === 0) {
            return $upload;
        }
        
        // Get file size
        $file_size_bytes = filesize($file_path);
        $file_size_kb = round($file_size_bytes / 1024);
        
        // API constraints check
        if ($file_size_kb < 35 || $file_size_kb > 5120) {
            error_log("BMO: File size {$file_size_kb}KB outside allowed range (35-5120KB)");
            return $upload;
        }
        
        try {
            $optimized = $this->call_bmo_api($file_path, $file_type);
            
            if ($optimized && $optimized['success']) {
                $this->apply_optimization_result($file_path, $optimized, $upload);
            }
            
        } catch (Exception $e) {
            error_log('BMO Optimization Error: ' . $e->getMessage());
        }
        
        return $upload;
    }
    
    /**
     * Call BMO API with WordPress requirements
     */
    private function call_bmo_api($file_path, $file_type) {
        // WordPress requirement: Detect SVG and handle specially
        if ($file_type === 'image/svg+xml') {
            // SVG files are returned as-is by the API
            error_log('BMO: SVG file detected, will be returned as-is');
        }
        
        // Read file and encode to base64
        $image_data = file_get_contents($file_path);
        $base64_image = base64_encode($image_data);
        $data_url = "data:{$file_type};base64,{$base64_image}";
        
        $request_body = array(
            'images' => array(
                array(
                    'imageData' => $data_url,
                    'quality' => 85 // Default quality
                    // Note: No 'format' parameter - WordPress API always uses AVIF (except SVG/WEBP)
                )
            ),
            'batch' => false,
            'userThresholdKb' => $this->user_threshold_kb // User-defined threshold
            // Note: No 'supportsAVIF' - always true for WordPress
            // Note: No 'async' - WordPress uses synchronous processing
        );
        
        $response = wp_remote_post($this->api_endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
                'User-Agent' => 'BMO-WordPress-Plugin/1.0'
            ),
            'body' => json_encode($request_body),
            'sslverify' => true // HTTPS required
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Handle specific error codes
        switch ($status_code) {
            case 200:
                return $data;
                
            case 401:
                throw new Exception('Invalid API key');
                
            case 402:
                throw new Exception('Insufficient credits');
                
            case 426:
                throw new Exception('HTTPS is required');
                
            case 429:
                $retry_after = wp_remote_retrieve_header($response, 'Retry-After');
                throw new Exception("Rate limit exceeded. Retry after {$retry_after} seconds");
                
            case 503:
                throw new Exception('BMO service temporarily unavailable');
                
            default:
                $error_msg = isset($data['error']) ? $data['error'] : 'Unknown error';
                throw new Exception("API error ({$status_code}): {$error_msg}");
        }
    }
    
    /**
     * Apply optimization result to the file
     */
    private function apply_optimization_result($file_path, $result, &$upload) {
        if (!isset($result['results'])) {
            return;
        }
        
        $image_result = $result['results'];
        
        // Handle skipped processing
        if (isset($image_result['skipped']) && $image_result['skipped']) {
            error_log('BMO: Processing skipped - ' . $image_result['reason']);
            return;
        }
        
        if (!isset($image_result['data']['base64'])) {
            return;
        }
        
        // Extract base64 data
        $base64_data = $image_result['data']['base64'];
        $base64_parts = explode(',', $base64_data);
        
        if (count($base64_parts) !== 2) {
            error_log('BMO: Invalid base64 data format');
            return;
        }
        
        $image_data = base64_decode($base64_parts[1]);
        
        if ($image_data === false) {
            error_log('BMO: Failed to decode base64 image data');
            return;
        }
        
        // Create backup (optional)
        $backup_path = $file_path . '.bmo-original';
        copy($file_path, $backup_path);
        
        // Write optimized image
        if (file_put_contents($file_path, $image_data) === false) {
            error_log('BMO: Failed to write optimized image');
            return;
        }
        
        // Update upload info
        $new_size = filesize($file_path);
        $original_size = $image_result['data']['originalSize'] * 1024; // Convert KB to bytes
        $compression_ratio = $image_result['data']['compressionRatio'];
        
        // Log success
        error_log("BMO: Image optimized - {$compression_ratio}% size reduction");
        
        // Store metadata for WordPress
        update_post_meta(0, '_bmo_optimized', array(
            'original_size' => $original_size,
            'optimized_size' => $new_size,
            'compression_ratio' => $compression_ratio,
            'original_format' => $image_result['data']['originalFormat'],
            'target_format' => $image_result['data']['targetFormat'],
            'credits_used' => $image_result['creditsUsed'] ?? 1, // WordPress: 1 credit per image
            'timestamp' => current_time('mysql')
        ));
    }
    
    /**
     * Batch processing for existing attachments
     */
    public function bulk_optimize_existing_images() {
        $this->ensure_https();
        
        $attachments = get_posts(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => 10, // Process in batches of 10 (API limit)
            'meta_query' => array(
                array(
                    'key' => '_bmo_optimized',
                    'compare' => 'NOT EXISTS'
                )
            )
        ));
        
        if (empty($attachments)) {
            return array('success' => true, 'message' => 'No images to optimize');
        }
        
        $batch_images = array();
        
        foreach ($attachments as $attachment) {
            $file_path = get_attached_file($attachment->ID);
            
            if (!file_exists($file_path)) {
                continue;
            }
            
            $file_size_kb = round(filesize($file_path) / 1024);
            
            // Skip files outside API constraints
            if ($file_size_kb < 35 || $file_size_kb > 5120) {
                continue;
            }
            
            $image_data = file_get_contents($file_path);
            $file_type = get_post_mime_type($attachment->ID);
            $base64_image = base64_encode($image_data);
            $data_url = "data:{$file_type};base64,{$base64_image}";
            
            $batch_images[] = array(
                'imageData' => $data_url,
                'quality' => 85,
                'attachment_id' => $attachment->ID,
                'file_path' => $file_path
            );
        }
        
        if (empty($batch_images)) {
            return array('success' => true, 'message' => 'No valid images to optimize');
        }
        
        // Prepare API request
        $api_images = array_map(function($img) {
            return array(
                'imageData' => $img['imageData'],
                'quality' => $img['quality']
            );
        }, $batch_images);
        
        $request_body = array(
            'images' => $api_images,
            'batch' => true,
            'userThresholdKb' => $this->user_threshold_kb
        );
        
        try {
            $response = wp_remote_post($this->api_endpoint, array(
                'timeout' => 60, // Longer timeout for batch
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->api_key,
                    'User-Agent' => 'BMO-WordPress-Plugin/1.0'
                ),
                'body' => json_encode($request_body),
                'sslverify' => true
            ));
            
            if (is_wp_error($response)) {
                throw new Exception('Batch API request failed: ' . $response->get_error_message());
            }
            
            $data = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!$data['success']) {
                throw new Exception('Batch optimization failed');
            }
            
            // Process results
            $results = $data['results'];
            $total_credits = 0;
            
            foreach ($results as $index => $result) {
                if (isset($batch_images[$index])) {
                    $attachment_id = $batch_images[$index]['attachment_id'];
                    $file_path = $batch_images[$index]['file_path'];
                    
                    if ($result['success'] && !$result['skipped']) {
                        $this->apply_batch_result($file_path, $result, $attachment_id);
                        $total_credits += $result['creditsUsed'] ?? 1;
                    }
                }
            }
            
            return array(
                'success' => true,
                'processed' => $data['processed'],
                'credits_used' => $total_credits,
                'message' => "Optimized {$data['processed']} images using {$total_credits} credits"
            );
            
        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }
    
    private function apply_batch_result($file_path, $result, $attachment_id) {
        if (!isset($result['data']['base64'])) {
            return;
        }
        
        $base64_data = $result['data']['base64'];
        $base64_parts = explode(',', $base64_data);
        
        if (count($base64_parts) === 2) {
            $image_data = base64_decode($base64_parts[1]);
            
            if ($image_data !== false) {
                // Create backup
                copy($file_path, $file_path . '.bmo-original');
                
                // Write optimized image
                file_put_contents($file_path, $image_data);
                
                // Store metadata
                update_post_meta($attachment_id, '_bmo_optimized', array(
                    'original_size' => $result['data']['originalSize'] * 1024,
                    'optimized_size' => filesize($file_path),
                    'compression_ratio' => $result['data']['compressionRatio'],
                    'original_format' => $result['data']['originalFormat'],
                    'target_format' => $result['data']['targetFormat'],
                    'credits_used' => $result['creditsUsed'] ?? 1,
                    'timestamp' => current_time('mysql')
                ));
            }
        }
    }
    
    public function missing_api_key_notice() {
        echo '<div class="notice notice-error"><p>BMO Image Optimizer: Please configure your API key in Settings > BMO Optimization.</p></div>';
    }
    
    public function add_admin_menu() {
        add_options_page(
            'BMO Image Optimization',
            'BMO Optimization',
            'manage_options',
            'bmo-optimization',
            array($this, 'admin_page')
        );
    }
    
    public function admin_init() {
        register_setting('bmo_optimization', 'bmo_api_key');
        register_setting('bmo_optimization', 'bmo_api_endpoint');
        register_setting('bmo_optimization', 'bmo_user_threshold_kb');
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>BMO Image Optimization Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('bmo_optimization'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="text" name="bmo_api_key" value="<?php echo esc_attr($this->api_key); ?>" class="regular-text" />
                            <p class="description">Your BMO API key</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Endpoint</th>
                        <td>
                            <input type="url" name="bmo_api_endpoint" value="<?php echo esc_attr($this->api_endpoint); ?>" class="regular-text" />
                            <p class="description">BMO API endpoint (default: EU region)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Compression Threshold (KB)</th>
                        <td>
                            <input type="number" name="bmo_user_threshold_kb" value="<?php echo esc_attr($this->user_threshold_kb); ?>" min="50" max="1000" />
                            <p class="description">Images above this size will be compressed even if already in AVIF/WEBP format</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <h2>Bulk Optimize Existing Images</h2>
            <p>Optimize existing images in your media library (processes up to 10 images at a time).</p>
            <button type="button" id="bulk-optimize" class="button button-primary">Start Bulk Optimization</button>
            <div id="bulk-status"></div>
            
            <h2>WordPress API Requirements</h2>
            <ul>
                <li><strong>AVIF Conversion:</strong> All images converted to AVIF (except SVG and WEBP)</li>
                <li><strong>SVG Handling:</strong> SVG files are returned as-is without processing</li>
                <li><strong>WEBP Handling:</strong> WEBP files are never converted, only compressed if above threshold</li>
                <li><strong>Credits:</strong> 1 credit = 1 image processed (regardless of size)</li>
                <li><strong>HTTPS:</strong> Required for all API requests</li>
                <li><strong>Batch Size:</strong> Maximum 10 images per request</li>
            </ul>
        </div>
        
        <script>
        document.getElementById('bulk-optimize').addEventListener('click', function() {
            var button = this;
            var status = document.getElementById('bulk-status');
            
            button.disabled = true;
            button.textContent = 'Processing...';
            status.innerHTML = '<p>Starting bulk optimization...</p>';
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: new FormData().append('action', 'bmo_bulk_optimize')
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    status.innerHTML = '<p style="color: green;">' + data.message + '</p>';
                } else {
                    status.innerHTML = '<p style="color: red;">Error: ' + data.error + '</p>';
                }
                button.disabled = false;
                button.textContent = 'Start Bulk Optimization';
            })
            .catch(error => {
                status.innerHTML = '<p style="color: red;">Error: ' + error.message + '</p>';
                button.disabled = false;
                button.textContent = 'Start Bulk Optimization';
            });
        });
        </script>
        <?php
    }
}

// Initialize the plugin
new BMO_WordPress_Image_Optimizer(); 