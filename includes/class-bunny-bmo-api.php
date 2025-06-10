<?php
/**
 * BMO API handler for external image optimization
 */
class Bunny_BMO_API {
    
    private $settings;
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct($settings, $logger) {
        $this->settings = $settings;
        $this->logger = $logger;
    }
    
    /**
     * Get BMO API endpoint based on region
     */
    private function get_api_endpoint() {
        $region = $this->settings->get('bmo_api_region', 'us');
        
        switch ($region) {
            case 'eu':
                return 'https://api-eu.bmo.nexwinds.com/v1/images/wp/optimize';
            case 'us':
            default:
                return 'https://api-us.bmo.nexwinds.com/v1/images/wp/optimize';
        }
    }
    
    /**
     * Optimize images via BMO API
     */
    public function optimize_images($images, $options = array()) {
        $api_key = $this->settings->get('bmo_api_key');
        
        if (empty($api_key)) {
            throw new Exception('BMO API key not configured. Please add "define(\'BMO_API_KEY\', \'your-api-key-here\');" to your wp-config.php file.');
        }
        
        if (empty($images)) {
            throw new Exception('No images provided for optimization.');
        }
        
        // Limit to 20 images per batch (BMO API requirement)
        $images = array_slice($images, 0, 20);
        
        $endpoint = $this->get_api_endpoint();
        
        // Prepare request data
        $request_data = array(
            'images' => $images,
            'batch' => count($images) > 1,
            'supportsAVIF' => $this->browser_supports_avif(),
            'userThresholdKb' => 150, // Add default threshold for AVIF/WEBP optimization
        );
        
        // Add format and quality options
        if (isset($options['format'])) {
            $request_data['format'] = $options['format'];
        }
        
        if (isset($options['quality'])) {
            $request_data['quality'] = intval($options['quality']);
        }
        
        $this->logger->log('info', 'Sending optimization request to BMO API', array(
            'endpoint' => $endpoint,
            'region' => $this->settings->get('bmo_api_region', 'us'),
            'image_count' => count($images),
            'batch' => $request_data['batch']
        ));
        
        // Make API request with improved configuration to prevent timeouts
        $response = wp_remote_post($endpoint, array(
            'timeout' => 120, // Increased timeout for large image processing
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true,
            'headers' => array(
                'x-api-key' => $api_key,
                'Content-Type' => 'application/json',
                'User-Agent' => 'Bunny-Media-Offload/' . BMO_PLUGIN_VERSION,
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_data)
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('BMO API request failed: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            $error_data = json_decode($body, true);
            $error_message = isset($error_data['error']) ? $error_data['error'] : 'Unknown API error';
            throw new Exception("BMO API error ({$status_code}): {$error_message}");
        }
        
        $result = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from BMO API');
        }
        
        if (!isset($result['success']) || !$result['success']) {
            $error_message = isset($result['error']) ? $result['error'] : 'Optimization failed';
            throw new Exception("BMO API optimization failed: {$error_message}");
        }
        
        $this->logger->log('info', 'BMO API optimization completed', array(
            'processed' => $result['processed'] ?? 0,
            'credits_used' => $result['creditsUsed'] ?? 0,
            'credits_remaining' => $result['creditsRemaining'] ?? 0,
            'processing_time' => $result['processingTime'] ?? 0,
            'api_version' => $result['apiVersion'] ?? 'unknown',
            'region' => $result['region'] ?? 'unknown'
        ));
        
        return $result;
    }
    
    /**
     * Optimize single image
     */
    public function optimize_single_image($image_url, $options = array()) {
        // Validate parameters
        if (empty($image_url)) {
            throw new Exception('Image URL is required for optimization.');
        }
        
        $this->logger->log('info', 'Optimizing single image', array('url' => $image_url));
        
        $image_data = array(
            'imageUrl' => $image_url
        );
        
        // Add quality if specified
        if (isset($options['quality'])) {
            $image_data['quality'] = intval($options['quality']);
        }
        
        // Create a single-image request
        $request_data = array(
            'images' => array($image_data),
            'batch' => false,
            'supportsAVIF' => $this->browser_supports_avif(),
            'userThresholdKb' => 150
        );
        
        // Add format if specified
        if (isset($options['format'])) {
            $request_data['format'] = $options['format'];
        }
        
        $endpoint = $this->get_api_endpoint();
        
        $this->logger->log('info', 'Sending single image to BMO API', array(
            'endpoint' => $endpoint,
            'region' => $this->settings->get('bmo_api_region', 'us')
        ));
        
        // Make API request with individual timeout
        $response = wp_remote_post($endpoint, array(
            'timeout' => 45, // 45 second timeout for individual image
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true,
            'headers' => array(
                'x-api-key' => $this->settings->get('bmo_api_key'),
                'Content-Type' => 'application/json',
                'User-Agent' => 'Bunny-Media-Offload/' . BMO_PLUGIN_VERSION,
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_data)
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('BMO API request failed: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            $error_data = json_decode($body, true);
            $error_message = isset($error_data['error']) ? $error_data['error'] : 'Unknown API error';
            throw new Exception("BMO API error ({$status_code}): {$error_message}");
        }
        
        $result = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from BMO API');
        }
        
        if (!isset($result['success']) || !$result['success']) {
            $error_message = isset($result['error']) ? $result['error'] : 'Optimization failed';
            throw new Exception("BMO API optimization failed: {$error_message}");
        }
        
        $this->logger->log('info', 'BMO API single image optimization completed', array(
            'credits_used' => $result['creditsUsed'] ?? 0,
            'credits_remaining' => $result['creditsRemaining'] ?? 0
        ));
        
        return $result;
    }
    
    /**
     * Prepare image data for BMO API
     */
    public function prepare_image_data($attachment_id, $image_url = null) {
        if (!$image_url) {
            $image_url = wp_get_attachment_url($attachment_id);
        }
        
        if (!$image_url) {
            throw new Exception('Could not get image URL for attachment ID: ' . $attachment_id);
        }
        
        $quality = $this->settings->get('optimization_quality', 85);
        
        return array(
            'imageUrl' => $image_url,
            'quality' => intval($quality)
        );
    }
    
    /**
     * Check if browser supports AVIF
     */
    private function browser_supports_avif() {
        // Check Accept header if available
        if (isset($_SERVER['HTTP_ACCEPT'])) {
            return strpos($_SERVER['HTTP_ACCEPT'], 'image/avif') !== false;
        }
        
        // Default to true for server-side optimization
        return true;
    }
    
    /**
     * Validate BMO API configuration
     */
    public function validate_configuration() {
        $errors = array();
        
        $api_key = $this->settings->get('bmo_api_key');
        if (empty($api_key)) {
            $errors[] = 'BMO API key is required. Please add "define(\'BMO_API_KEY\', \'your-api-key-here\');" to your wp-config.php file.';
        }
        
        $region = $this->settings->get('bmo_api_region');
        if (empty($region) || !in_array($region, array('us', 'eu'))) {
            $errors[] = 'BMO API region must be set to "us" or "eu". Please add "define(\'BMO_API_REGION\', \'us\');" or "define(\'BMO_API_REGION\', \'eu\');" to your wp-config.php file.';
        }
        
        return $errors;
    }
    
    /**
     * Test BMO API connection
     */
    public function test_connection() {
        try {
            $validation_errors = $this->validate_configuration();
            if (!empty($validation_errors)) {
                return array(
                    'success' => false,
                    'message' => 'Configuration errors: ' . implode(', ', $validation_errors)
                );
            }
            
            // Test with a small sample image URL
            $test_image_url = 'https://via.placeholder.com/150x150.jpg';
            
            $result = $this->optimize_single_image($test_image_url, array(
                'quality' => 85
            ));
            
            return array(
                'success' => true,
                'message' => 'BMO API connection successful',
                'data' => array(
                    'region' => $result['region'] ?? 'unknown',
                    'api_version' => $result['apiVersion'] ?? 'unknown',
                    'credits_remaining' => $result['creditsRemaining'] ?? 'unknown'
                )
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'BMO API connection failed: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Get API usage statistics
     */
    public function get_api_stats() {
        // This would require an additional API endpoint to get account statistics
        // For now, return basic configuration info
        return array(
            'region' => $this->settings->get('bmo_api_region', 'us'),
            'endpoint' => $this->get_api_endpoint(),
            'configured' => !empty($this->settings->get('bmo_api_key'))
        );
    }
} 