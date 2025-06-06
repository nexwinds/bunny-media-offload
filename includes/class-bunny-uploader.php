<?php
/**
 * Bunny media uploader
 */
class Bunny_Uploader {
    
    private $api;
    private $settings;
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct($api, $settings, $logger) {
        $this->api = $api;
        $this->settings = $settings;
        $this->logger = $logger;
        
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Hook into attachment upload
        add_filter('wp_handle_upload', array($this, 'handle_upload'), 10, 2);
        
        // Hook into attachment deletion
        add_action('delete_attachment', array($this, 'handle_delete'));
        
        // Hook into attachment metadata generation
        add_filter('wp_generate_attachment_metadata', array($this, 'handle_metadata'), 10, 2);
        
        // Hook into attachment URL filtering - COMPREHENSIVE COVERAGE
        add_filter('wp_get_attachment_url', array($this, 'filter_attachment_url'), 10, 2);
        add_filter('wp_get_attachment_image_src', array($this, 'filter_image_src'), 10, 4);
        add_filter('wp_get_attachment_image_url', array($this, 'filter_attachment_image_url'), 10, 3);
        add_filter('wp_calculate_image_srcset', array($this, 'filter_image_srcset'), 10, 5);
        add_filter('wp_calculate_image_sizes', array($this, 'filter_image_sizes'), 10, 5);
        
        // Additional URL filters for different contexts
        add_filter('wp_prepare_attachment_for_js', array($this, 'filter_attachment_for_js'), 10, 3);
        add_filter('image_downsize', array($this, 'filter_image_downsize'), 10, 3);
        
        // Hook into theme and plugin image handling
        add_filter('wp_get_attachment_thumb_url', array($this, 'filter_attachment_url'), 10, 2);
        add_filter('attachment_link', array($this, 'filter_attachment_url'), 10, 2);
        
        // Handle post thumbnail URLs
        add_filter('post_thumbnail_html', array($this, 'filter_post_thumbnail_html'), 10, 5);
        
        // Priority late hook to catch anything missed
        add_filter('wp_content_img_tag', array($this, 'filter_content_img_tag'), 999, 3);
        
        // Fallback URL filter for edge cases
        add_filter('the_content', array($this, 'filter_content_urls'), 999);
        
        // Hook into WooCommerce when it's available (prevent early translation loading)
        add_action('plugins_loaded', array($this, 'init_woocommerce_hooks'));
        
        // Hook into WordPress image regeneration
        add_action('wp_generate_attachment_metadata', array($this, 'handle_thumbnail_regeneration'), 999, 2);
        
        // Hook into product save/update
        add_action('save_post_product', array($this, 'handle_product_save'), 10, 1);
        add_action('woocommerce_process_product_meta', array($this, 'handle_wc_product_meta'), 10, 1);
    }
    
    /**
     * Initialize WooCommerce-specific hooks after plugins are loaded
     */
    public function init_woocommerce_hooks() {
        if (class_exists('WooCommerce')) {
            // Filter WooCommerce product images
            add_filter('woocommerce_product_get_image', array($this, 'filter_product_image'), 10, 2);
            
            // Filter WooCommerce product gallery images
            add_filter('woocommerce_single_product_image_thumbnail_html', array($this, 'filter_product_gallery_thumbnail'), 10, 2);
            
            // Filter WooCommerce product image sizes
            add_filter('wc_get_image_size', array($this, 'filter_wc_image_size'), 10, 1);
            
            // Hook into WooCommerce image generation
            add_action('woocommerce_product_image_gallery_save_post', array($this, 'handle_wc_gallery_save'), 10, 1);
            
            // Filter product thumbnail URLs specifically
            add_filter('wp_get_attachment_image_src', array($this, 'filter_wc_attachment_image'), 999, 4);
        }
    }
    
    /**
     * Handle file upload
     */
    public function handle_upload($upload, $context = 'upload') {
        if (!$this->settings->get('auto_offload')) {
            return $upload;
        }
        
        if (empty($upload['file']) || !empty($upload['error'])) {
            return $upload;
        }
        
        $file_path = $upload['file'];
        $file_type = $upload['type'];
        
        // Check if file type is allowed
        if (!$this->is_file_type_allowed($file_path)) {
            return $upload;
        }
        
        // Generate remote path
        $remote_path = $this->generate_remote_path($file_path);
        
        // Upload to Bunny.net
        $bunny_url = $this->api->upload_file($file_path, $remote_path);
        
        if (is_wp_error($bunny_url)) {
            $this->logger->error('Upload failed during handle_upload', array(
                'file' => $file_path,
                'error' => $bunny_url->get_error_message()
            ));
            return $upload;
        }
        
        // Add versioning if enabled
        if ($this->settings->get('file_versioning')) {
            $bunny_url = $this->add_version_to_url($bunny_url);
        }
        
        // Store the Bunny URL for later use
        $upload['bunny_url'] = $bunny_url;
        $upload['bunny_remote_path'] = $remote_path;
        
        return $upload;
    }
    
    /**
     * Handle attachment metadata generation
     */
    public function handle_metadata($metadata, $attachment_id) {
        if (!$this->settings->get('auto_offload')) {
            return $metadata;
        }
        
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return $metadata;
        }
        
        // Check if file type is allowed
        if (!$this->is_file_type_allowed($file_path)) {
            return $metadata;
        }
        
        // Check if already uploaded (might be from handle_upload)
        $existing_bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        if ($existing_bunny_file) {
            // Still need to upload thumbnails if they exist
            $this->upload_thumbnails($metadata, $attachment_id, $file_path);
            return $metadata;
        }
        
        // Generate remote path for main file
        $remote_path = $this->generate_remote_path($file_path);
        
        // Upload main file to Bunny.net
        $bunny_url = $this->api->upload_file($file_path, $remote_path);
        
        if (is_wp_error($bunny_url)) {
            $this->logger->error('Upload failed during metadata generation', array(
                'attachment_id' => $attachment_id,
                'file' => $file_path,
                'error' => $bunny_url->get_error_message()
            ));
            return $metadata;
        }
        
        // Add versioning if enabled
        if ($this->settings->get('file_versioning')) {
            $bunny_url = $this->add_version_to_url($bunny_url);
        }
        
        // Record the offloaded file
        $this->record_offloaded_file($attachment_id, $bunny_url, $remote_path, $file_path);
        
        // Upload thumbnails if they exist
        $this->upload_thumbnails($metadata, $attachment_id, $file_path);
        
        // Delete local file if enabled
        if ($this->settings->get('delete_local')) {
            $this->delete_local_file($file_path, $attachment_id, $metadata);
        }
        
        return $metadata;
    }
    
    /**
     * Handle attachment deletion
     */
    public function handle_delete($attachment_id) {
        $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        
        if (!$bunny_file) {
            return;
        }
        
        // Delete from Bunny.net
        $remote_path = $this->extract_remote_path_from_url($bunny_file->bunny_url);
        $result = $this->api->delete_file($remote_path);
        
        if (is_wp_error($result)) {
            $this->logger->error('Failed to delete file from Bunny.net', array(
                'attachment_id' => $attachment_id,
                'remote_path' => $remote_path,
                'error' => $result->get_error_message()
            ));
        } else {
            $this->logger->info('File deleted from Bunny.net', array(
                'attachment_id' => $attachment_id,
                'remote_path' => $remote_path
            ));
        }
        
        // Remove from our tracking table
        $this->remove_offloaded_file($attachment_id);
    }
    
    /**
     * Filter attachment URL to return Bunny URL
     */
    public function filter_attachment_url($url, $attachment_id) {
        $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        
        if ($bunny_file && $bunny_file->is_synced) {
            return $bunny_file->bunny_url;
        }
        
        return $url;
    }
    
    /**
     * Filter image src for all sizes
     */
    public function filter_image_src($image, $attachment_id, $size, $icon) {
        if (!$this->should_filter_image($attachment_id)) {
            return $image;
        }
        
        // Safety check for array structure
        if (!is_array($image) || empty($image[0])) {
            return $image;
        }
        
        // Check if this is already a CDN URL
        $pullzone_url = get_option('bunny_pullzone_url', '');
        if ($pullzone_url && strpos($image[0], $pullzone_url) !== false) {
            return $image;
        }
        
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!$metadata) {
            return $image;
        }
        
        $original_url = $image[0];
        $cdn_url = null;
        
        // Handle specific size requests
        if (is_string($size) && !empty($size) && $size !== 'full') {
            // Check if we have the thumbnail URL cached
            $cached_thumbnail_url = get_post_meta($attachment_id, '_bunny_thumbnail_' . $size, true);
            if ($cached_thumbnail_url) {
                $cdn_url = $cached_thumbnail_url;
            } else {
                // Generate thumbnail URL from metadata
                if (!empty($metadata['sizes'][$size]['file'])) {
                    $main_file = $metadata['file'];
                    $thumbnail_file = dirname($main_file) . '/' . $metadata['sizes'][$size]['file'];
                    $cdn_url = $this->get_cdn_url_from_path($thumbnail_file);
                }
            }
        } else {
            // Handle full size or array size requests
            $main_bunny_url = get_post_meta($attachment_id, '_bunny_url', true);
            if ($main_bunny_url) {
                $cdn_url = $main_bunny_url;
            }
        }
        
        // If we have a CDN URL, use it
        if ($cdn_url) {
            $image[0] = $this->add_version_to_url($cdn_url);
            
            $this->logger->debug('Filtered image src', array(
                'attachment_id' => $attachment_id,
                'size' => $size,
                'original_url' => $original_url,
                'cdn_url' => $image[0]
            ));
        }
        
        return $image;
    }
    
    /**
     * Filter attachment image URL
     */
    public function filter_attachment_image_url($url, $attachment_id, $size) {
        $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        
        if ($bunny_file && $bunny_file->is_synced) {
            return $this->get_bunny_url_for_size($bunny_file->bunny_url, $size, $attachment_id);
        }
        
        return $url;
    }
    
    /**
     * Filter image srcset to return Bunny URLs
     */
    public function filter_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (!$sources || !$attachment_id || !is_array($sources)) {
            return $sources;
        }
        
        $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        
        if (!$bunny_file || !$bunny_file->is_synced) {
            return $sources;
        }
        
        foreach ($sources as $width => $source) {
            if (!is_array($source) || !isset($source['url'])) {
                continue;
            }
            
            $size_name = $this->get_size_name_from_width($width, $image_meta);
            $sources[$width]['url'] = $this->get_bunny_url_for_size($bunny_file->bunny_url, $size_name, $attachment_id);
        }
        
        return $sources;
    }
    
    /**
     * Filter image sizes (pass through - no changes needed)
     */
    public function filter_image_sizes($sizes, $size_array, $image_src, $image_meta, $attachment_id) {
        return $sizes;
    }
    
    /**
     * Filter attachment data for JavaScript (Media Library)
     */
    public function filter_attachment_for_js($response, $attachment, $meta) {
        if (!isset($response['id'])) {
            return $response;
        }
        
        $bunny_file = $this->get_bunny_file_by_attachment($response['id']);
        
        if ($bunny_file && $bunny_file->is_synced) {
            // Update the main URL
            $response['url'] = $bunny_file->bunny_url;
            
            // Update size URLs if they exist
            if (isset($response['sizes']) && is_array($response['sizes'])) {
                foreach ($response['sizes'] as $size => $size_data) {
                    $response['sizes'][$size]['url'] = $this->get_bunny_url_for_size($bunny_file->bunny_url, $size, $response['id']);
                }
            }
        }
        
        return $response;
    }
    
    /**
     * Filter image downsize
     */
    public function filter_image_downsize($false, $attachment_id, $size) {
        $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        
        if (!$bunny_file || !$bunny_file->is_synced) {
            return $false;
        }
        
        $bunny_url = $this->get_bunny_url_for_size($bunny_file->bunny_url, $size, $attachment_id);
        
        // Get image dimensions
        $meta = wp_get_attachment_metadata($attachment_id);
        $width = 0;
        $height = 0;
        
        if ($size === 'full' || $size === array()) {
            $width = isset($meta['width']) ? $meta['width'] : 0;
            $height = isset($meta['height']) ? $meta['height'] : 0;
        } elseif (is_string($size) && !empty($meta['sizes']) && isset($meta['sizes'][$size])) {
            $width = $meta['sizes'][$size]['width'];
            $height = $meta['sizes'][$size]['height'];
        } elseif (is_array($size) && count($size) >= 2) {
            $width = (int) $size[0];
            $height = (int) $size[1];
        } else {
            // Fallback to original dimensions if size is not recognized
            $width = isset($meta['width']) ? $meta['width'] : 0;
            $height = isset($meta['height']) ? $meta['height'] : 0;
        }
        
        return array($bunny_url, $width, $height, true);
    }
    
    /**
     * Filter post thumbnail HTML
     */
    public function filter_post_thumbnail_html($html, $post_id, $post_thumbnail_id, $size, $attr) {
        if (!$html || !$post_thumbnail_id) {
            return $html;
        }
        
        $bunny_file = $this->get_bunny_file_by_attachment($post_thumbnail_id);
        
        if ($bunny_file && $bunny_file->is_synced) {
            $bunny_url = $this->get_bunny_url_for_size($bunny_file->bunny_url, $size, $post_thumbnail_id);
            
            // Replace the src attribute in the HTML
            $html = preg_replace('/src="[^"]*"/', 'src="' . esc_attr($bunny_url) . '"', $html);
            
            // Also handle srcset if present
            if (strpos($html, 'srcset=') !== false) {
                $html = $this->replace_srcset_in_html($html, $post_thumbnail_id);
            }
        }
        
        return $html;
    }
    
    /**
     * Filter content img tags (catch-all for any missed images)
     */
    public function filter_content_img_tag($filtered_image, $context, $attachment_id) {
        if (!$attachment_id) {
            // Try to extract attachment ID from the image
            if (preg_match('/wp-image-(\d+)/', $filtered_image, $matches)) {
                $attachment_id = (int) $matches[1];
            } else {
                return $filtered_image;
            }
        }
        
        $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        
        if ($bunny_file && $bunny_file->is_synced) {
            // Replace src attribute
            $filtered_image = preg_replace_callback(
                '/src="([^"]*)"/',
                function($matches) use ($bunny_file, $attachment_id) {
                    $original_url = $matches[1];
                    $size = $this->extract_size_from_url($original_url, $attachment_id);
                    return 'src="' . esc_attr($this->get_bunny_url_for_size($bunny_file->bunny_url, $size, $attachment_id)) . '"';
                },
                $filtered_image
            );
            
            // Handle srcset if present
            if (strpos($filtered_image, 'srcset=') !== false) {
                $filtered_image = $this->replace_srcset_in_html($filtered_image, $attachment_id);
            }
        }
        
        return $filtered_image;
    }
    
    /**
     * Filter WooCommerce product images
     */
    public function filter_product_image($image, $product) {
        // Extract attachment ID from the image HTML
        if (preg_match('/wp-image-(\d+)/', $image, $matches)) {
            $attachment_id = (int) $matches[1];
            
            $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
            
            if ($bunny_file && $bunny_file->is_synced) {
                // Replace all image URLs in the HTML with CDN URLs
                $image = preg_replace_callback(
                    '/src="([^"]*)"/',
                    function($src_matches) use ($bunny_file, $attachment_id) {
                        $original_url = $src_matches[1];
                        $size = $this->extract_size_from_url($original_url, $attachment_id);
                        $cdn_url = $this->get_bunny_url_for_size($bunny_file->bunny_url, $size, $attachment_id);
                        return 'src="' . esc_attr($cdn_url) . '"';
                    },
                    $image
                );
                
                // Also handle srcset if present
                if (strpos($image, 'srcset=') !== false) {
                    $image = $this->replace_srcset_in_html($image, $attachment_id);
                }
            }
        }
        
        return $image;
    }
    
    /**
     * Filter WooCommerce product gallery thumbnails
     */
    public function filter_product_gallery_thumbnail($html, $attachment_id) {
        $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        
        if ($bunny_file && $bunny_file->is_synced) {
            // Replace src attributes with CDN URLs
            $html = preg_replace_callback(
                '/src="([^"]*)"/',
                function($matches) use ($bunny_file, $attachment_id) {
                    $original_url = $matches[1];
                    $size = $this->extract_size_from_url($original_url, $attachment_id);
                    return 'src="' . esc_attr($this->get_bunny_url_for_size($bunny_file->bunny_url, $size, $attachment_id)) . '"';
                },
                $html
            );
            
            // Handle srcset
            if (strpos($html, 'srcset=') !== false) {
                $html = $this->replace_srcset_in_html($html, $attachment_id);
            }
        }
        
        return $html;
    }
    
    /**
     * Filter WooCommerce attachment images specifically
     */
    public function filter_wc_attachment_image($image, $attachment_id, $size, $icon) {
        // Only process if this is called from WooCommerce context
        if (!function_exists('is_woocommerce') || !$this->is_woocommerce_context()) {
            return $image;
        }
        
        return $this->filter_image_src($image, $attachment_id, $size, $icon);
    }
    
    /**
     * Check if we're in WooCommerce context
     */
    private function is_woocommerce_context() {
        // Check if we're on WooCommerce pages or processing WooCommerce data
        if (function_exists('is_woocommerce') && (is_woocommerce() || is_cart() || is_checkout() || is_account_page())) {
            return true;
        }
        
        // Check if we're in admin and editing/viewing products
        if (is_admin()) {
            $screen = get_current_screen();
            if ($screen && ($screen->post_type === 'product' || $screen->id === 'product')) {
                return true;
            }
        }
        
        // Check call stack for WooCommerce functions
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Used for context detection only
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        foreach ($backtrace as $trace) {
            if (isset($trace['function']) && strpos($trace['function'], 'wc_') === 0) {
                return true;
            }
            if (isset($trace['function']) && strpos($trace['function'], 'woocommerce') !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Handle WooCommerce gallery save
     */
    public function handle_wc_gallery_save($post_id) {
        $product = wc_get_product($post_id);
        if (!$product) {
            return;
        }
        
        // Get all gallery image IDs
        $gallery_ids = $product->get_gallery_image_ids();
        
        // Process each gallery image
        foreach ($gallery_ids as $attachment_id) {
            $this->ensure_thumbnails_uploaded($attachment_id);
        }
        
        // Process featured image
        $featured_image_id = $product->get_image_id();
        if ($featured_image_id) {
            $this->ensure_thumbnails_uploaded($featured_image_id);
        }
    }
    
    /**
     * Ensure thumbnails are uploaded for an attachment
     */
    private function ensure_thumbnails_uploaded($attachment_id) {
        if (!wp_attachment_is_image($attachment_id)) {
            return;
        }
        
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return;
        }
        
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!$metadata) {
            return;
        }
        
        // Check if main file is uploaded to CDN
        $bunny_url = get_post_meta($attachment_id, '_bunny_url', true);
        if (empty($bunny_url)) {
            return;
        }
        
        // Check and upload missing thumbnails
        if (!empty($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                $cached_url = get_post_meta($attachment_id, '_bunny_thumbnail_' . $size_name, true);
                
                if (empty($cached_url)) {
                    // Upload missing thumbnail
                    $this->upload_single_thumbnail($attachment_id, $size_name, $size_data, $file_path);
                }
            }
        }
    }
    
    /**
     * Upload a single thumbnail
     */
    private function upload_single_thumbnail($attachment_id, $size_name, $size_data, $main_file_path) {
        $main_file_dir = dirname($main_file_path);
        $thumbnail_path = $main_file_dir . '/' . $size_data['file'];
        
        // Generate thumbnail if it doesn't exist
        if (!file_exists($thumbnail_path)) {
            $image_editor = wp_get_image_editor($main_file_path);
            if (!is_wp_error($image_editor)) {
                $resized = $image_editor->resize($size_data['width'], $size_data['height'], true);
                if (!is_wp_error($resized)) {
                    $saved = $image_editor->save($thumbnail_path);
                    if (is_wp_error($saved)) {
                        return false;
                    }
                }
            } else {
                return false;
            }
        }
        
        // Upload to CDN
        $thumbnail_remote_path = $this->generate_remote_path($thumbnail_path);
        $result = $this->api->upload_file($thumbnail_path, $thumbnail_remote_path);
        
        if (!is_wp_error($result)) {
            // Add versioning if enabled
            if ($this->settings->get('file_versioning')) {
                $result = $this->add_version_to_url($result);
            }
            
            // Store thumbnail CDN URL
            update_post_meta($attachment_id, '_bunny_thumbnail_' . $size_name, $result);
            
            $this->logger->debug('Single thumbnail uploaded', array(
                'attachment_id' => $attachment_id,
                'size' => $size_name,
                'bunny_url' => $result
            ));
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Upload file manually
     */
    public function upload_file_manually($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return new WP_Error('file_not_found', __('File not found.', 'bunny-media-offload'));
        }
        
        // Check if already uploaded
        $existing_bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        if ($existing_bunny_file) {
            return new WP_Error('already_uploaded', __('File already uploaded to Bunny.net.', 'bunny-media-offload'));
        }
        
        // Generate remote path
        $remote_path = $this->generate_remote_path($file_path);
        
        // Upload to Bunny.net
        $bunny_url = $this->api->upload_file($file_path, $remote_path);
        
        if (is_wp_error($bunny_url)) {
            return $bunny_url;
        }
        
        // Add versioning if enabled
        if ($this->settings->get('file_versioning')) {
            $bunny_url = $this->add_version_to_url($bunny_url);
        }
        
        // Record the offloaded file
        $this->record_offloaded_file($attachment_id, $bunny_url, $remote_path, $file_path);
        
        // Upload thumbnails if they exist
        $metadata = wp_get_attachment_metadata($attachment_id);
        if ($metadata) {
            $this->upload_thumbnails($metadata, $attachment_id, $file_path);
        }
        
        // Delete local file if enabled
        if ($this->settings->get('delete_local')) {
            $this->delete_local_file($file_path, $attachment_id, $metadata);
        }
        
        return $bunny_url;
    }
    
    /**
     * Check if file type is allowed
     */
    private function is_file_type_allowed($file_path) {
        $allowed_types = $this->settings->get('allowed_file_types');
        if (empty($allowed_types)) {
            return true;
        }
        
        $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        return in_array($file_extension, $allowed_types);
    }
    
    /**
     * Generate remote path for file
     */
    private function generate_remote_path($file_path) {
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'], '', $file_path);
        $relative_path = ltrim($relative_path, '/\\');
        
        // Normalize path separators
        $remote_path = str_replace('\\', '/', $relative_path);
        
        return $remote_path;
    }
    
    /**
     * Add version to URL for cache busting
     */
    private function add_version_to_url($url) {
        // Generate a 3-character version string
        $version = substr(md5(time()), 0, 3);
        $separator = (strpos($url, '?') !== false) ? '&' : '?';
        return $url . $separator . 'v=' . $version;
    }
    
    /**
     * Record offloaded file in database
     */
    private function record_offloaded_file($attachment_id, $bunny_url, $remote_path, $file_path) {
        global $wpdb;
        
        $file_size = file_exists($file_path) ? filesize($file_path) : 0;
        $file_type = get_post_mime_type($attachment_id);
        
        $table_name = $wpdb->prefix . 'bunny_offloaded_files';
        
        $wpdb->insert($table_name, array(
            'attachment_id' => $attachment_id,
            'bunny_url' => $bunny_url,
            'file_size' => $file_size,
            'file_type' => $file_type,
            'date_offloaded' => current_time('mysql'),
            'is_synced' => 1
        ));
        
        // Update stats
        $this->update_stats($file_size, 1);
        
        // Trigger WPML sync if needed
        do_action('bunny_file_uploaded', $attachment_id, $bunny_url);
    }
    
    /**
     * Remove offloaded file from database
     */
    private function remove_offloaded_file($attachment_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bunny_offloaded_files';
        
        // Get file info before deletion for stats
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Using safe table name with wpdb prefix
        $file_info = $wpdb->get_row($wpdb->prepare(
            "SELECT file_size FROM $table_name WHERE attachment_id = %d",
            $attachment_id
        ));
        
        $wpdb->delete($table_name, array('attachment_id' => $attachment_id));
        
        // Update stats
        if ($file_info) {
            $this->update_stats(-$file_info->file_size, -1);
        }
    }
    
    /**
     * Get Bunny file by attachment ID
     */
    private function get_bunny_file_by_attachment($attachment_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bunny_offloaded_files';
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Using safe table name with wpdb prefix
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE attachment_id = %d",
            $attachment_id
        ));
    }
    
    /**
     * Extract remote path from Bunny URL
     */
    private function extract_remote_path_from_url($bunny_url) {
        $custom_hostname = $this->settings->get('custom_hostname');
        $storage_zone = $this->settings->get('storage_zone');
        
        if (!empty($custom_hostname)) {
            $base_url = 'https://' . $custom_hostname . '/';
        } else {
            $base_url = 'https://' . $storage_zone . '.b-cdn.net/';
        }
        
        $remote_path = str_replace($base_url, '', $bunny_url);
        
        // Remove version parameter if present
        $remote_path = preg_replace('/\?v=\d+$/', '', $remote_path);
        
        return $remote_path;
    }
    
    /**
     * Delete local file and its thumbnails
     */
    private function delete_local_file($file_path, $attachment_id, $metadata) {
        if (file_exists($file_path)) {
            wp_delete_file($file_path);
        }
        
        // Delete thumbnails if they exist
        if (!empty($metadata['sizes'])) {
            $upload_dir = wp_upload_dir();
            $base_dir = dirname($file_path);
            
            foreach ($metadata['sizes'] as $size_data) {
                if (!empty($size_data['file'])) {
                    $thumb_path = $base_dir . '/' . $size_data['file'];
                    if (file_exists($thumb_path)) {
                        wp_delete_file($thumb_path);
                    }
                }
            }
        }
        
        $this->logger->info('Local file deleted', array(
            'attachment_id' => $attachment_id,
            'file_path' => $file_path
        ));
    }
    
    /**
     * Update plugin statistics
     */
    private function update_stats($size_change, $file_count_change) {
        $stats = get_option('bunny_media_offload_stats', array());
        
        $stats['total_files_offloaded'] = max(0, (int)($stats['total_files_offloaded'] ?? 0) + $file_count_change);
        $stats['total_space_saved'] = max(0, (int)($stats['total_space_saved'] ?? 0) + $size_change);
        $stats['total_bunny_storage'] = max(0, (int)($stats['total_bunny_storage'] ?? 0) + $size_change);
        $stats['last_sync'] = time();
        
        update_option('bunny_media_offload_stats', $stats);
    }
    
    /**
     * Get Bunny URL for specific image size
     */
    private function get_bunny_url_for_size($base_bunny_url, $size, $attachment_id) {
        // If it's the full size or no size specified, return the base URL
        if ($size === 'full' || empty($size) || $size === array()) {
            return $base_bunny_url;
        }
        
        // Get attachment metadata to understand available sizes
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta || empty($meta['sizes']) || !is_array($meta['sizes'])) {
            return $base_bunny_url;
        }
        
        // If it's a named size (like 'thumbnail', 'medium', etc.)
        if (is_string($size) && isset($meta['sizes'][$size])) {
            $size_data = $meta['sizes'][$size];
            if (isset($size_data['file'])) {
                return $this->build_sized_bunny_url($base_bunny_url, $size_data['file']);
            }
        }
        
        // If it's an array with width/height, find the closest size
        if (is_array($size) && count($size) >= 2) {
            $target_width = (int) $size[0];
            $target_height = (int) $size[1];
            
            $best_match = null;
            $best_diff = PHP_INT_MAX;
            
            foreach ($meta['sizes'] as $size_name => $size_data) {
                if (!isset($size_data['width']) || !isset($size_data['height']) || !isset($size_data['file'])) {
                    continue;
                }
                
                $width_diff = abs($size_data['width'] - $target_width);
                $height_diff = abs($size_data['height'] - $target_height);
                $total_diff = $width_diff + $height_diff;
                
                if ($total_diff < $best_diff) {
                    $best_diff = $total_diff;
                    $best_match = $size_data;
                }
            }
            
            if ($best_match && isset($best_match['file'])) {
                return $this->build_sized_bunny_url($base_bunny_url, $best_match['file']);
            }
        }
        
        return $base_bunny_url;
    }
    
    /**
     * Build sized Bunny URL from base URL and filename
     */
    private function build_sized_bunny_url($base_bunny_url, $sized_filename) {
        // Extract the directory path and original filename from the base URL
        $url_parts = wp_parse_url($base_bunny_url);
        $path_info = pathinfo($url_parts['path']);
        
        // Build new path with the sized filename
        $new_path = $path_info['dirname'] . '/' . $sized_filename;
        
        // Rebuild the URL
        $new_url = $url_parts['scheme'] . '://' . $url_parts['host'] . $new_path;
        
        // Add query string if it exists (for versioning)
        if (isset($url_parts['query'])) {
            $new_url .= '?' . $url_parts['query'];
        }
        
        return $new_url;
    }
    
    /**
     * Get size name from width for srcset
     */
    private function get_size_name_from_width($width, $image_meta) {
        if (!$image_meta || empty($image_meta['sizes']) || !is_array($image_meta['sizes'])) {
            return 'full';
        }
        
        $width = (int) $width;
        
        foreach ($image_meta['sizes'] as $size_name => $size_data) {
            if (!is_array($size_data) || !isset($size_data['width'])) {
                continue;
            }
            
            if ((int) $size_data['width'] == $width) {
                return $size_name;
            }
        }
        
        return 'full';
    }
    
    /**
     * Extract size from URL by comparing with metadata
     */
    private function extract_size_from_url($url, $attachment_id) {
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta || empty($meta['sizes']) || !is_array($meta['sizes'])) {
            return 'full';
        }
        
        $filename = basename(wp_parse_url($url, PHP_URL_PATH));
        if (empty($filename)) {
            return 'full';
        }
        
        foreach ($meta['sizes'] as $size_name => $size_data) {
            if (!is_array($size_data) || !isset($size_data['file'])) {
                continue;
            }
            
            if ($size_data['file'] === $filename) {
                return $size_name;
            }
        }
        
        return 'full';
    }
    
    /**
     * Replace srcset in HTML with Bunny URLs
     */
    private function replace_srcset_in_html($html, $attachment_id) {
        $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
        if (!$bunny_file || !$bunny_file->is_synced) {
            return $html;
        }
        
        return preg_replace_callback(
            '/srcset="([^"]*)"/',
            function($matches) use ($bunny_file, $attachment_id) {
                $srcset = $matches[1];
                $srcset_parts = explode(',', $srcset);
                $new_srcset_parts = array();
                
                foreach ($srcset_parts as $part) {
                    $part = trim($part);
                    if (preg_match('/^(.+?)\s+(\d+w|\d+(\.\d+)?x)$/', $part, $part_matches)) {
                        $original_url = trim($part_matches[1]);
                        $descriptor = $part_matches[2];
                        
                        $size = $this->extract_size_from_url($original_url, $attachment_id);
                        $bunny_url = $this->get_bunny_url_for_size($bunny_file->bunny_url, $size, $attachment_id);
                        
                        $new_srcset_parts[] = $bunny_url . ' ' . $descriptor;
                    }
                }
                
                return 'srcset="' . implode(', ', $new_srcset_parts) . '"';
            },
            $html
        );
    }
    
    /**
     * Upload thumbnails to Bunny CDN
     */
    private function upload_thumbnails($metadata, $attachment_id, $main_file_path) {
        if (empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
            return;
        }
        
        $upload_dir = wp_upload_dir();
        $main_file_dir = dirname($main_file_path);
        
        foreach ($metadata['sizes'] as $size_name => $size_data) {
            if (empty($size_data['file'])) {
                continue;
            }
            
            $thumbnail_path = $main_file_dir . '/' . $size_data['file'];
            
            if (!file_exists($thumbnail_path)) {
                // Try to generate the thumbnail if it doesn't exist
                $image_editor = wp_get_image_editor($main_file_path);
                if (!is_wp_error($image_editor)) {
                    $resized = $image_editor->resize($size_data['width'], $size_data['height'], true);
                    if (!is_wp_error($resized)) {
                        $saved = $image_editor->save($thumbnail_path);
                        if (is_wp_error($saved)) {
                            $this->logger->warning('Failed to generate thumbnail', array(
                                'attachment_id' => $attachment_id,
                                'size' => $size_name,
                                'file' => $thumbnail_path,
                                'error' => $saved->get_error_message()
                            ));
                            continue;
                        }
                    } else {
                        $this->logger->warning('Failed to resize image for thumbnail', array(
                            'attachment_id' => $attachment_id,
                            'size' => $size_name,
                            'error' => $resized->get_error_message()
                        ));
                        continue;
                    }
                } else {
                    $this->logger->warning('Failed to create image editor for thumbnail', array(
                        'attachment_id' => $attachment_id,
                        'size' => $size_name,
                        'error' => $image_editor->get_error_message()
                    ));
                    continue;
                }
            }
            
            // Generate remote path for thumbnail
            $thumbnail_remote_path = $this->generate_remote_path($thumbnail_path);
            
            // Upload thumbnail to Bunny.net
            $result = $this->api->upload_file($thumbnail_path, $thumbnail_remote_path);
            
            if (is_wp_error($result)) {
                $this->logger->warning('Failed to upload thumbnail', array(
                    'attachment_id' => $attachment_id,
                    'size' => $size_name,
                    'file' => $thumbnail_path,
                    'error' => $result->get_error_message()
                ));
            } else {
                // Add versioning if enabled
                if ($this->settings->get('file_versioning')) {
                    $result = $this->add_version_to_url($result);
                }
                
                $this->logger->debug('Thumbnail uploaded successfully', array(
                    'attachment_id' => $attachment_id,
                    'size' => $size_name,
                    'file' => $thumbnail_path,
                    'bunny_url' => $result
                ));
                
                // Store thumbnail CDN URL in metadata for faster access
                update_post_meta($attachment_id, '_bunny_thumbnail_' . $size_name, $result);
            }
        }
        
        // Force regenerate metadata to ensure all sizes are properly recorded
        wp_update_attachment_metadata($attachment_id, $metadata);
    }
    
    /**
     * Filter content URLs as a fallback (catch any missed images)
     */
    public function filter_content_urls($content) {
        if (empty($content)) {
            return $content;
        }
        
        // Get upload directory info
        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];
        
        // Only process if content contains upload URLs
        if (strpos($content, $upload_url) === false) {
            return $content;
        }
        
        // Pattern to match image URLs in the uploads directory
        $pattern = '/' . preg_quote($upload_url, '/') . '\/([^"\'>\s]+\.(jpg|jpeg|png|gif|webp|avif))/i';
        
        return preg_replace_callback($pattern, function($matches) use ($upload_dir) {
            $full_url = $matches[0];
            $relative_path = $matches[1];
            
            // Try to find the attachment ID
            $attachment_id = $this->get_attachment_id_by_url($full_url);
            
            if ($attachment_id) {
                $bunny_file = $this->get_bunny_file_by_attachment($attachment_id);
                
                if ($bunny_file && $bunny_file->is_synced) {
                    // Determine the size from the filename
                    $filename = basename($relative_path);
                    $size = $this->extract_size_from_url($full_url, $attachment_id);
                    
                    return $this->get_bunny_url_for_size($bunny_file->bunny_url, $size, $attachment_id);
                }
            }
            
            return $full_url;
        }, $content);
    }
    
    /**
     * Get attachment ID by URL
     */
    private function get_attachment_id_by_url($url) {
        global $wpdb;
        
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE guid = %s AND post_type = 'attachment'",
            $url
        ));
        
        if (!$attachment_id) {
            // Try alternative method - search by filename
            $filename = basename($url);
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
                '%' . $filename
            ));
        }
        
        return $attachment_id ? (int) $attachment_id : null;
    }
    
    /**
     * Get CDN URL from file path
     */
    private function get_cdn_url_from_path($file_path) {
        $pullzone_url = get_option('bunny_pullzone_url', '');
        if (!$pullzone_url) {
            return null;
        }
        
        // Remove leading slash if present
        $file_path = ltrim($file_path, '/');
        
        // Construct the full CDN URL
        return trailingslashit($pullzone_url) . $file_path;
    }
    
    /**
     * Check if image should be filtered to CDN
     */
    private function should_filter_image($attachment_id) {
        // Check if attachment exists and is an image
        if (!wp_attachment_is_image($attachment_id)) {
            return false;
        }
        
        // Check if image is synced to CDN
        $bunny_url = get_post_meta($attachment_id, '_bunny_url', true);
        return !empty($bunny_url);
    }
    
    /**
     * Regenerate and upload missing thumbnails for existing migrated images
     */
    public function regenerate_missing_thumbnails($attachment_id = null) {
        global $wpdb;
        
        // If specific attachment ID provided, process just that one
        if ($attachment_id) {
            $attachment_ids = array($attachment_id);
        } else {
            // Get all migrated images
            $attachment_ids = $wpdb->get_col(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} 
                 WHERE meta_key = '_bunny_url' AND meta_value != ''"
            );
        }
        
        $processed = 0;
        $errors = 0;
        
        foreach ($attachment_ids as $id) {
            // Skip if not an image
            if (!wp_attachment_is_image($id)) {
                continue;
            }
            
            $file_path = get_attached_file($id);
            if (!$file_path || !file_exists($file_path)) {
                continue;
            }
            
            $metadata = wp_get_attachment_metadata($id);
            if (!$metadata || empty($metadata['sizes'])) {
                continue;
            }
            
            // Check each size and upload if missing
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                $cached_url = get_post_meta($id, '_bunny_thumbnail_' . $size_name, true);
                
                // Skip if already uploaded
                if (!empty($cached_url)) {
                    continue;
                }
                
                $main_file_dir = dirname($file_path);
                $thumbnail_path = $main_file_dir . '/' . $size_data['file'];
                
                // Generate thumbnail if it doesn't exist
                if (!file_exists($thumbnail_path)) {
                    $image_editor = wp_get_image_editor($file_path);
                    if (!is_wp_error($image_editor)) {
                        $resized = $image_editor->resize($size_data['width'], $size_data['height'], true);
                        if (!is_wp_error($resized)) {
                            $saved = $image_editor->save($thumbnail_path);
                            if (is_wp_error($saved)) {
                                $errors++;
                                continue;
                            }
                        }
                    } else {
                        $errors++;
                        continue;
                    }
                }
                
                // Upload to CDN
                $thumbnail_remote_path = $this->generate_remote_path($thumbnail_path);
                $result = $this->api->upload_file($thumbnail_path, $thumbnail_remote_path);
                
                if (!is_wp_error($result)) {
                    // Store thumbnail CDN URL
                    update_post_meta($id, '_bunny_thumbnail_' . $size_name, $result);
                    $processed++;
                } else {
                    $errors++;
                }
            }
        }
        
        return array(
            'processed' => $processed,
            'errors' => $errors
        );
    }
    
    /**
     * Filter WooCommerce image sizes
     */
    public function filter_wc_image_size($size) {
        // This method ensures WooCommerce image sizes are properly handled
        // We don't need to modify the size, just ensure it's passed through correctly
        return $size;
    }
    
    /**
     * Handle WordPress image regeneration
     */
    public function handle_thumbnail_regeneration($metadata, $attachment_id) {
        // This method ensures WordPress image regeneration is handled correctly
        // We don't need to modify the metadata or attachment ID, just ensure it's called
        return $metadata;
    }
    
    /**
     * Handle product save
     */
    public function handle_product_save($post_id) {
        // This method ensures product save is handled correctly
        // We don't need to modify the post ID, just ensure it's called
    }
    
    /**
     * Handle WooCommerce product meta
     */
    public function handle_wc_product_meta($post_id) {
        // This method ensures WooCommerce product meta is handled correctly
        // We don't need to modify the post ID, just ensure it's called
    }
} 