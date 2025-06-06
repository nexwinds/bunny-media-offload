<?php
/**
 * WPML Compatibility for Bunny Media Offload
 */
class Bunny_WPML {
    
    private $settings;
    private $logger;
    private $default_language;
    private $current_language;
    
    /**
     * Constructor
     */
    public function __construct($settings, $logger) {
        $this->settings = $settings;
        $this->logger = $logger;
        
        // Only initialize if WPML is active
        if (!$this->is_wpml_active()) {
            return;
        }
        
        $this->default_language = apply_filters('wpml_default_language', null);
        $this->current_language = apply_filters('wpml_current_language', null);
        
        $this->init_hooks();
    }
    
    /**
     * Check if WPML is active and properly configured
     */
    public function is_wpml_active() {
        return defined('ICL_SITEPRESS_VERSION') && function_exists('icl_object_id');
    }
    
    /**
     * Initialize WPML-specific hooks
     */
    private function init_hooks() {
        // Handle attachment duplication for translations
        add_action('wpml_media_create_duplicate_attachment', array($this, 'handle_attachment_duplication'), 10, 2);
        
        // Handle language switching
        add_action('wpml_language_has_switched', array($this, 'handle_language_switch'));
        
        // Filter CDN URLs based on language
        add_filter('bunny_cdn_url', array($this, 'filter_cdn_url_by_language'), 10, 3);
        
        // Handle translated attachment metadata
        add_filter('wp_get_attachment_metadata', array($this, 'get_translated_attachment_metadata'), 10, 2);
        
        // Synchronize Bunny metadata across language versions
        add_action('bunny_file_uploaded', array($this, 'sync_bunny_metadata_across_languages'), 10, 2);
        
        // Handle admin interface language
        add_action('admin_init', array($this, 'handle_admin_language'));
        
        // Filter queries for multilingual support
        add_filter('bunny_get_attachments_query', array($this, 'filter_attachments_query'), 10, 1);
        
        // Handle migration across languages
        add_filter('bunny_migration_attachments', array($this, 'filter_migration_attachments'), 10, 2);
        
        // Handle optimization queue for multilingual sites
        add_filter('bunny_optimization_attachments', array($this, 'filter_optimization_attachments'), 10, 1);
    }
    
    /**
     * Handle attachment duplication when WPML creates translated versions
     */
    public function handle_attachment_duplication($original_attachment_id, $duplicated_attachment_id) {
        global $wpdb;
        
        // Check if original attachment is offloaded
        $bunny_table = $wpdb->prefix . 'bunny_offloaded_files';
        $original_bunny_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bunny_offloaded_files WHERE attachment_id = %d",
            $original_attachment_id
        ));
        
        if ($original_bunny_data) {
            // Copy Bunny offload data to the duplicated attachment
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Inserting plugin-specific data for WPML translation, not available via WordPress functions
            $wpdb->insert(
                $bunny_table,
                array(
                    'attachment_id' => $duplicated_attachment_id,
                    'bunny_url' => $original_bunny_data->bunny_url,
                    'file_size' => $original_bunny_data->file_size,
                    'file_type' => $original_bunny_data->file_type,
                    'date_offloaded' => current_time('mysql'),
                    'is_synced' => 1
                ),
                array('%d', '%s', '%d', '%s', '%s', '%d')
            );
            
            // Copy optimization metadata
            $optimization_data = get_post_meta($original_attachment_id, '_bunny_optimization_data', true);
            if ($optimization_data) {
                update_post_meta($duplicated_attachment_id, '_bunny_optimization_data', $optimization_data);
                update_post_meta($duplicated_attachment_id, '_bunny_optimized', true);
                update_post_meta($duplicated_attachment_id, '_bunny_last_optimized', get_post_meta($original_attachment_id, '_bunny_last_optimized', true));
            }
            
            $this->logger->log('info', "Synchronized Bunny data for translated attachment", array(
                'original_id' => $original_attachment_id,
                'translated_id' => $duplicated_attachment_id,
                'language' => $this->get_attachment_language($duplicated_attachment_id)
            ));
        }
    }
    
    /**
     * Handle language switching
     */
    public function handle_language_switch() {
        $this->current_language = apply_filters('wpml_current_language', null);
        
        // Update any language-specific caching or processing
        wp_cache_delete('bunny_wpml_current_lang');
        wp_cache_set('bunny_wpml_current_lang', $this->current_language, '', 3600);
    }
    
    /**
     * Filter CDN URLs based on language (for language-specific subdomains)
     */
    public function filter_cdn_url_by_language($url, $attachment_id, $original_url) {
        if (!$this->is_wpml_active()) {
            return $url;
        }
        
        $attachment_language = $this->get_attachment_language($attachment_id);
        
        // Apply language-specific URL modifications if needed
        $custom_hostname = $this->settings->get('custom_hostname');
        if ($custom_hostname && $attachment_language && $attachment_language !== $this->default_language) {
            // Example: Add language prefix to subdomain
            // cdn.example.com -> fr.cdn.example.com
            $language_specific_hostname = $attachment_language . '.' . $custom_hostname;
            $url = str_replace($custom_hostname, $language_specific_hostname, $url);
        }
        
        return $url;
    }
    
    /**
     * Get translated attachment metadata
     */
    public function get_translated_attachment_metadata($metadata, $attachment_id) {
        if (!$this->is_wpml_active() || !$metadata) {
            return $metadata;
        }
        
        // Get the original attachment ID in default language
        $original_id = apply_filters('wpml_object_id', $attachment_id, 'attachment', false, $this->default_language);
        
        if ($original_id && $original_id !== $attachment_id) {
            // If this is a translation, ensure it has the same Bunny metadata
            $original_metadata = get_post_meta($original_id, '_wp_attachment_metadata', true);
            if ($original_metadata && isset($original_metadata['bunny_url'])) {
                $metadata['bunny_url'] = $original_metadata['bunny_url'];
            }
        }
        
        return $metadata;
    }
    
    /**
     * Sync Bunny metadata across all language versions of an attachment
     */
    public function sync_bunny_metadata_across_languages($attachment_id, $bunny_url) {
        if (!$this->is_wpml_active()) {
            return;
        }
        
        $active_languages = apply_filters('wpml_active_languages', null);
        
        if (!$active_languages) {
            return;
        }
        
        global $wpdb;
        $bunny_table = $wpdb->prefix . 'bunny_offloaded_files';
        
        foreach ($active_languages as $language) {
            $translated_id = apply_filters('wpml_object_id', $attachment_id, 'attachment', false, $language['code']);
            
            if ($translated_id && $translated_id !== $attachment_id) {
                // Check if translation already has Bunny data
                $existing_data = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}bunny_offloaded_files WHERE attachment_id = %d",
                    $translated_id
                ));
                
                if (!$existing_data) {
                    // Copy Bunny data to translation
                    $original_data = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}bunny_offloaded_files WHERE attachment_id = %d",
                        $attachment_id
                    ));
                    
                    if ($original_data) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Inserting plugin-specific data for WPML synchronization, not available via WordPress functions
                        $wpdb->insert(
                            $bunny_table,
                            array(
                                'attachment_id' => $translated_id,
                                'bunny_url' => $original_data->bunny_url,
                                'file_size' => $original_data->file_size,
                                'file_type' => $original_data->file_type,
                                'date_offloaded' => current_time('mysql'),
                                'is_synced' => 1
                            ),
                            array('%d', '%s', '%d', '%s', '%s', '%d')
                        );
                    }
                }
            }
        }
    }
    
    /**
     * Handle admin interface language
     */
    public function handle_admin_language() {
        if (is_admin() && $this->is_wpml_active()) {
            // Ensure admin notices and messages use the correct language
            $admin_language = $this->get_admin_language();
            if ($admin_language && $admin_language !== $this->current_language) {
                do_action('wpml_switch_language', $admin_language);
            }
        }
    }
    
    /**
     * Filter attachment queries for multilingual support
     */
    public function filter_attachments_query($query) {
        if (!$this->is_wpml_active()) {
            return $query;
        }
        
        // Include language-specific WHERE conditions
        global $wpdb;
        
        $language_condition = '';
        if ($this->should_filter_by_language()) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML translation query for multilingual attachment filtering
            $language_condition = $wpdb->prepare(
                " AND p.ID IN (
                    SELECT element_id 
                    FROM {$wpdb->prefix}icl_translations 
                    WHERE element_type = 'post_attachment' 
                    AND language_code = %s
                )",
                $this->current_language
            );
        }
        
        return $query . $language_condition;
    }
    
    /**
     * Filter migration attachments for multilingual sites
     */
    public function filter_migration_attachments($attachments, $args) {
        if (!$this->is_wpml_active()) {
            return $attachments;
        }
        
        $migrate_all_languages = isset($args['all_languages']) ? $args['all_languages'] : false;
        
        if (!$migrate_all_languages) {
            // Filter to only include attachments in the current language
            $filtered_attachments = array();
            
            foreach ($attachments as $attachment) {
                $attachment_language = $this->get_attachment_language($attachment->ID);
                if ($attachment_language === $this->current_language) {
                    $filtered_attachments[] = $attachment;
                }
            }
            
            return $filtered_attachments;
        }
        
        return $attachments;
    }
    
    /**
     * Filter optimization attachments for multilingual sites
     */
    public function filter_optimization_attachments($attachment_ids) {
        if (!$this->is_wpml_active()) {
            return $attachment_ids;
        }
        
        // Remove duplicates - only optimize the original version
        $filtered_ids = array();
        
        foreach ($attachment_ids as $attachment_id) {
            $original_id = apply_filters('wpml_object_id', $attachment_id, 'attachment', false, $this->default_language);
            
            // Only include if this is the original or if we haven't already included the original
            if ($original_id === $attachment_id || !in_array($original_id, $filtered_ids)) {
                $filtered_ids[] = $attachment_id;
            }
        }
        
        return $filtered_ids;
    }
    
    /**
     * Get the language of an attachment
     */
    private function get_attachment_language($attachment_id) {
        if (!$this->is_wpml_active()) {
            return null;
        }
        
        return apply_filters('wpml_element_language_code', null, array(
            'element_id' => $attachment_id,
            'element_type' => 'post_attachment'
        ));
    }
    
    /**
     * Get admin language
     */
    private function get_admin_language() {
        if (!$this->is_wpml_active()) {
            return null;
        }
        
        return apply_filters('wpml_current_language', null);
    }
    
    /**
     * Check if queries should be filtered by language
     */
    private function should_filter_by_language() {
        // Don't filter in admin unless specifically requested
        if (is_admin()) {
            return apply_filters('bunny_wpml_filter_admin_queries', false);
        }
        
        return true;
    }
    
    /**
     * Get all language versions of an attachment
     */
    public function get_attachment_translations($attachment_id) {
        if (!$this->is_wpml_active()) {
            return array($attachment_id);
        }
        
        $translations = apply_filters('wpml_get_element_translations', null, array(
            'element_id' => $attachment_id,
            'element_type' => 'post_attachment'
        ));
        
        $translation_ids = array();
        if ($translations) {
            foreach ($translations as $translation) {
                if (isset($translation->element_id)) {
                    $translation_ids[] = $translation->element_id;
                }
            }
        }
        
        return !empty($translation_ids) ? $translation_ids : array($attachment_id);
    }
    
    /**
     * Get localized admin strings
     */
    public function get_admin_strings() {
        return array(
            'wpml_detected' => __('WPML detected - multilingual support enabled', 'bunny-media-offload'),
            'language_sync' => __('Synchronizing across languages', 'bunny-media-offload'),
            'translation_created' => __('Translation created and synchronized', 'bunny-media-offload'),
            'all_languages' => __('All Languages', 'bunny-media-offload'),
            'current_language_only' => __('Current Language Only', 'bunny-media-offload'),
            'default_language' => __('Default Language', 'bunny-media-offload'),
            'migrate_all_languages' => __('Migrate files from all languages', 'bunny-media-offload'),
            'optimize_originals_only' => __('Optimize original files only (translations will share optimized versions)', 'bunny-media-offload'),
        );
    }
    
    /**
     * Add WPML-specific settings to the admin interface
     */
    public function add_wpml_settings_section() {
        if (!$this->is_wpml_active()) {
            return '';
        }
        
        $active_languages = apply_filters('wpml_active_languages', null);
        $default_language = $this->default_language;
        
        ob_start();
        ?>
        <h2><?php esc_html_e('Multilingual Settings (WPML)', 'bunny-media-offload'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('WPML Status', 'bunny-media-offload'); ?></th>
                <td>
                    <span class="bunny-status bunny-status-offloaded">✓ <?php esc_html_e('WPML Active', 'bunny-media-offload'); ?></span>
                    <p class="description">
                        <?php 
                        printf(
                            // translators: %1$d is the number of active languages, %2$s is the default language code
                            esc_html__('Detected %1$d active languages. Default language: %2$s', 'bunny-media-offload'),
                            count($active_languages),
                            esc_html(strtoupper($default_language))
                        ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Active Languages', 'bunny-media-offload'); ?></th>
                <td>
                    <?php if ($active_languages): ?>
                        <ul style="margin: 0; padding-left: 20px;">
                            <?php foreach ($active_languages as $lang): ?>
                                <li>
                                    <?php echo esc_html($lang['translated_name']); ?> 
                                    (<?php echo esc_html($lang['code']); ?>)
                                    <?php if ($lang['code'] === $default_language): ?>
                                        <em><?php esc_html_e('- Default', 'bunny-media-offload'); ?></em>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Media Synchronization', 'bunny-media-offload'); ?></th>
                <td>
                    <p class="description">
                        <?php esc_html_e('Media files are automatically synchronized across all language versions. When a file is offloaded or optimized, all translations will reference the same CDN URL.', 'bunny-media-offload'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
        return ob_get_clean();
    }
} 