# Bunny Media Offload - Complete Documentation

## Table of Contents

1. [Introduction](#introduction)
2. [System Requirements](#system-requirements)
3. [Installation](#installation)
4. [Security Configuration](#security-configuration)
5. [Basic Setup](#basic-setup)
6. [Advanced Configuration](#advanced-configuration)
7. [Media Migration](#media-migration)
8. [Image Optimization](#image-optimization)
9. [WPML Multilingual Support](#wpml-multilingual-support)
10. [WP-CLI Commands](#wp-cli-commands)
11. [Troubleshooting](#troubleshooting)
12. [Performance Optimization](#performance-optimization)
13. [Developer Guide](#developer-guide)
14. [FAQ](#faq)

---

## Introduction

Bunny Media Offload is a comprehensive WordPress plugin that seamlessly integrates with Bunny.net Edge Storage to offload, optimize, and deliver your media files through a global CDN network. The plugin provides automatic media offloading, bulk migration tools, image optimization, and full WPML multilingual support.

### Key Features

- **Automatic Media Offloading**: Optimize and Upload new media directly to Bunny.net Edge Storage
- **Bulk Migration**: Migrate existing media libraries with animated progress tracking
- **Image Optimization**: Convert to modern formats AVIF with intelligent compression
- **Global CDN Delivery**: Serve media from 114+ global edge locations
- **WPML Compatible**: Full multilingual support with shared CDN URLs
- **WooCommerce**: Seamless product image handling with High-Performance Order Storage support
- **CLI Support**: Comprehensive WP-CLI commands for automation
- **Security First**: wp-config.php configuration support for sensitive data

---

## System Requirements

### Minimum Requirements
- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher (8.0+ recommended)
- **Memory**: 128MB minimum, 256MB recommended
- **cURL**: Required for API communication
- **GD Library**: Required for image optimization

### Recommended Environment
- **WordPress**: 6.0+
- **PHP**: 8.1+
- **Memory**: 512MB+
- **WooCommerce**: 5.0+ (if using e-commerce features)
- **WPML**: 4.0+ (if using multilingual features)

### Bunny.net Requirements
- Active Bunny.net account
- Edge Storage zone configured
- API key with storage permissions
- Required: Custom hostname configured

---

## Installation

### Automatic Installation (WordPress Repository)
1. Log in to your WordPress admin panel
2. Navigate to **Plugins > Add New**
3. Search for "Bunny Media Offload"
4. Click **Install Now** and then **Activate**

### Manual Installation
1. Download the plugin files
2. Upload the `bunny-media-offload` folder to `/wp-content/plugins/`
3. Activate the plugin through **Plugins > Installed Plugins**
4. Navigate to **Bunny CDN** in the admin menu

### Using WP-CLI
```bash
wp plugin install bunny-media-offload --activate
```

---

## Configuration System

The plugin uses a hybrid configuration system:
- **API credentials** are stored in `wp-config.php` for security
- **All other settings** are stored in a JSON configuration file for portability and performance

### wp-config.php Constants (Required)

Add **only these three constants** to your `wp-config.php` file, **before** the `/* That's all, stop editing! */` line:

```php
<?php
// Bunny.net Edge Storage Configuration
// Only these three constants should be defined in wp-config.php

// Required: Your Bunny.net Storage API Key
define('BUNNY_API_KEY', 'your-storage-api-key-here');

// Required: Your Bunny.net Storage Zone Name
define('BUNNY_STORAGE_ZONE', 'your-storage-zone-name');

// Required: Custom hostname for CDN URLs (without https://)
define('BUNNY_CUSTOM_HOSTNAME', 'cdn.yoursite.com');
```

### JSON Configuration File

All other settings are automatically managed in `/wp-content/bunny-config.json`. This file is created automatically with defaults when the plugin is first activated.

### Example Complete wp-config.php Section

```php
<?php

// ** Bunny.net Configuration ** //
define('BUNNY_API_KEY', 'b8f2c4d5-1234-5678-9abc-def123456789');
define('BUNNY_STORAGE_ZONE', 'mysite-storage');
define('BUNNY_CUSTOM_HOSTNAME', 'cdn.mysite.com');


/* That's all, stop editing! Happy publishing. */
require_once ABSPATH . 'wp-settings.php';
```

### Benefits of This Configuration System

1. **Enhanced Security**: API credentials stored in `wp-config.php`, not in database or JSON file
2. **Environment Portability**: 
   - Credentials in `wp-config.php` for environment-specific settings
   - Configuration in JSON file for consistent application settings
3. **Version Control Safe**: 
   - Exclude `wp-config.php` from commits (credentials)
   - Include `bunny-config.json` in version control (shared settings)
4. **Performance**: JSON file loads faster than database queries
5. **Backup Safety**: Settings preserved during database restores
6. **Easy Management**: Modify JSON file directly or use admin interface

---

## Basic Setup

### Step 1: Bunny.net Account Setup

1. **Create Account**: Sign up at [bunny.net](https://bunny.net)
2. **Create Storage Zone**:
   - Navigate to **Storage > Storage Zones**
   - Click **Add Storage Zone**
   - Enter a name (e.g., "mysite-media")
   - Select your preferred region
   - Note the storage zone name for configuration

3. **Generate API Key**:
   - Go to **Account > API**
   - Create a new API key with **Storage** permissions
   - Copy the API key securely

4. **Required - Custom Hostname**:
   - Navigate to **Storage > Storage Zones**
   - Click on your storage zone
   - Go to **Custom Hostnames**
   - Add your custom domain (e.g., cdn.yoursite.com)
   - Configure DNS CNAME record pointing to your storage zone

### Step 2: Plugin Configuration

#### Option A: Using wp-config.php (Recommended)
Add the constants to your `wp-config.php` file as shown in the [Security Configuration](#security-configuration) section.

#### Option B: Using Admin Interface
1. Navigate to **Bunny CDN > Settings**
2. Enter your API key and storage zone name
3. Configure optional settings
4. Click **Test Connection** to verify setup

### Step 3: Test Connection

1. Go to **Bunny CDN > Dashboard**
2. Click **Test Connection**
3. Verify you see a success message
4. Check that the connection indicator shows green

---

## Advanced Configuration

### File Type Support

This plugin focuses exclusively on modern image formats for optimal performance:

- **WebP**: Modern image format with excellent compression and wide browser support
- **AVIF**: Next-generation image format with superior compression ratios

> **Important**: Only WebP and AVIF file formats are supported for migration and synchronization. This ensures optimal performance and modern web standards compliance.

### Post Type Filtering

Limit offloading to specific post types:

```php
// In wp-config.php  
define('BUNNY_ALLOWED_POST_TYPES', 'post,page,product');
```

### Custom Upload Paths

Organize files with custom directory structures:

```php
// Custom filter in your theme's functions.php
add_filter('bunny_remote_path', function($path, $attachment_id) {
    $post = get_post($attachment_id);
    $year = date('Y', strtotime($post->post_date));
    $month = date('m', strtotime($post->post_date));
    
    return "uploads/{$year}/{$month}/" . basename($path);
}, 10, 2);
```

### CDN URL Customization

Modify CDN URLs for specific use cases:

```php
// Force HTTPS and add custom parameters
add_filter('bunny_cdn_url', function($url, $attachment_id) {
    $url = str_replace('http://', 'https://', $url);
    return $url . '?quality=85&format=auto';
}, 10, 2);
```

---

## Media Migration

### Planning Your Migration

Before starting a bulk migration:

1. **Backup Your Site**: Create a full site backup
2. **Test with Small Batch**: Start with 10-20 files
3. **Check Available Storage**: Ensure sufficient Bunny.net storage quota
4. **Plan Downtime**: Large migrations may impact site performance

### Starting Migration

#### Via Admin Interface
1. Navigate to **Bunny CDN > Migration**
2. Supported file types to migrate:
   - **WebP Images**: Modern image format with excellent compression
   - **AVIF Images**: Next-generation image format with superior compression
   
   > **Note**: Only WebP and AVIF formats are supported for migration. The plugin focuses on modern image formats for optimal performance.
   - **Other**: Any other file types
3. Choose batch size (recommended: 50-100 files)
4. For WPML sites, select language scope
5. Click **Start Migration**

#### Via WP-CLI
```bash
# Migrate all images
wp bunny migrate --file-types=image

# Migrate with custom batch size
wp bunny migrate --file-types=image,video --batch-size=25

# Migrate specific language (WPML)
wp bunny migrate --language=en --file-types=image

# Migrate all languages
wp bunny migrate --all-languages --file-types=image,document
```

### Monitoring Migration Progress

The migration interface provides real-time updates:
- **Total Files**: Number of files to process
- **Processed**: Files completed (successful + failed)
- **Success Rate**: Percentage of successful uploads
- **Current Batch**: Files in current processing batch
- **Estimated Time**: Remaining time based on current speed

### Handling Migration Issues

#### Common Issues and Solutions

**Migration Stalls:**
```bash
# Check migration status
wp bunny migration-status

# Cancel and restart if needed
wp bunny migration-cancel
wp bunny migrate --file-types=image --batch-size=10
```

**File Permission Errors:**
```bash
# Check file permissions
ls -la wp-content/uploads/

# Fix permissions if needed
chmod 644 wp-content/uploads/2024/01/*
```

**Memory Issues:**
```php
// In wp-config.php - increase memory limit
ini_set('memory_limit', '512M');
define('WP_MEMORY_LIMIT', '512M');
```

---

## Image Optimization

### Understanding Optimization

The plugin's optimization feature:
- Converts images to modern formats (AVIF/WebP)
- Compresses images to target file sizes
- Maintains visual quality while reducing bandwidth
- Processes images before uploading to Bunny.net

### Optimization Settings

#### Format Selection
- **AVIF**: Best compression, newest format, limited browser support
- **WebP**: Good compression, wide browser support

#### File Size Targets
- **40KB**: Aggressive compression for thumbnails
- **45KB**: Balanced compression for medium images  
- **50KB**: Light compression for large images
- **55KB**: Minimal compression for high-quality images
- **60KB**: Very light compression for hero images

### Automatic Optimization

Enable automatic optimization for new uploads:

```php
// In wp-config.php
define('BUNNY_OPTIMIZATION_ENABLED', true);
define('BUNNY_OPTIMIZE_ON_UPLOAD', true);
define('BUNNY_OPTIMIZATION_FORMAT', 'avif');
define('BUNNY_OPTIMIZATION_MAX_SIZE', 50);
```

### Bulk Optimization

#### Via Admin Interface
1. Navigate to **Bunny CDN > Optimization**
2. Review optimization statistics
3. Click **Run Optimization Now**
4. Monitor progress in real-time

#### Via WP-CLI
```bash
# Optimize all images
wp bunny optimize

# Optimize specific file types
wp bunny optimize --file-types=jpg,png

# Optimize with custom settings
wp bunny optimize --format=webp --max-size=45

# Check optimization status
wp bunny optimization-status

# Optimize specific attachment
wp bunny optimize 123
```

### Optimization Results

After optimization, you'll see:
- **Original Size**: Size before optimization
- **Optimized Size**: Size after optimization  
- **Compression Ratio**: Percentage reduction
- **Format**: Final image format (AVIF/WebP/original)

---

## WPML Multilingual Support

### Setup Requirements

1. **Install WPML**: Core, String Translation, and Media Translation
2. **Configure Languages**: Set up your site's languages
3. **Enable Media Translation**: Go to **WPML > Settings > Media Translation**

### Automatic Features

When WPML is detected, the plugin automatically:
- Shares CDN URLs across all language versions
- Synchronizes offload metadata when translations are created  
- Prevents duplicate optimization of the same physical file
- Provides language-specific migration options

### Migration with WPML

#### Language Scope Options

**Current Language Only:**
- Migrates only files attached to content in the current language
- Useful for gradual, language-by-language migration
- Reduces processing load

**All Languages:**
- Migrates files from all active languages
- Ensures complete coverage
- May include duplicate files across languages

#### CLI Examples
```bash
# Migrate current language only
wp bunny migrate --language-scope=current

# Migrate all languages  
wp bunny migrate --language-scope=all

# Check language-specific status
wp bunny status --language=es
```

### Optimization with WPML

The plugin optimizes efficiently across languages:
- Original files optimized once
- All language versions share the optimized file
- Metadata synchronized across translations
- No redundant processing

---

## WP-CLI Commands

### Installation and Status

```bash
# Install plugin via CLI
wp plugin install bunny-media-offload --activate

# Check plugin status
wp bunny status

# Detailed status with file counts
wp bunny status --detailed

# Test API connection
wp bunny test-connection
```

### Migration Commands

```bash
# Basic migration
wp bunny migrate

# Migrate specific file types
wp bunny migrate --file-types=image,video

# Custom batch size
wp bunny migrate --batch-size=25

# Show migration progress
wp bunny migration-status

# Cancel running migration
wp bunny migration-cancel
```

### File Operations

```bash
# Offload specific attachment
wp bunny offload 123

# Offload all images
wp bunny offload --file-types=image

# Sync file back to local
wp bunny sync 123

# Verify file integrity
wp bunny verify 123

# Verify all files with auto-fix
wp bunny verify --all --fix
```

### Optimization Commands

```bash
# Optimize all images
wp bunny optimize

# Optimize specific format
wp bunny optimize --file-types=jpg,png --format=webp

# Optimize with size limit
wp bunny optimize --max-size=40

# Check optimization queue
wp bunny optimization-status

# Clear optimization queue
wp bunny optimization-clear
```

### Maintenance Commands

```bash
# Cleanup orphaned files
wp bunny cleanup

# Regenerate file statistics
wp bunny stats --regenerate

# Export logs
wp bunny logs --export=/tmp/bunny-logs.csv

# Clear old logs
wp bunny logs --clear-old=30
```

### WPML-Specific Commands

```bash
# Migrate specific language
wp bunny migrate --language=es

# Status for specific language
wp bunny status --language=fr

# Optimize originals only (WPML sites)
wp bunny optimize --originals-only
```

---

## Troubleshooting

### Connection Issues

#### API Key Problems
**Symptoms**: "Invalid API key" or "Authorization failed"

**Solutions**:
```bash
# Test API key directly
curl -H "AccessKey: YOUR_API_KEY" \
     https://storage.bunnycdn.com/YOUR_ZONE/

# Verify API key has storage permissions in Bunny.net dashboard
# Regenerate API key if necessary
```

#### Network Connectivity
**Symptoms**: "Connection timeout" or "Could not resolve host"

**Solutions**:
```bash
# Test connectivity from server
curl -I https://storage.bunnycdn.com/

# Check firewall rules allow outbound HTTPS
# Verify DNS resolution works

# Test with specific storage zone
curl -I https://YOUR_ZONE.b-cdn.net/
```

### Upload Failures

#### File Permission Issues
**Symptoms**: "Permission denied" or "Could not read file"

**Solutions**:
```bash
# Check file permissions
ls -la wp-content/uploads/2024/01/

# Fix permissions
find wp-content/uploads/ -type f -exec chmod 644 {} \;
find wp-content/uploads/ -type d -exec chmod 755 {} \;

# Check PHP file upload limits
php -i | grep -E "(upload_max_filesize|post_max_size|max_execution_time)"
```

#### Storage Quota Exceeded
**Symptoms**: "Storage quota exceeded" or "Insufficient storage"

**Solutions**:
1. Check storage usage in Bunny.net dashboard
2. Upgrade storage plan if needed
3. Clean up unnecessary files
4. Implement file retention policies

### Migration Problems

#### Stalled Migration
**Symptoms**: Migration progress stops advancing

**Solutions**:
```bash
# Check current migration status
wp bunny migration-status

# Cancel and restart with smaller batch size
wp bunny migration-cancel
wp bunny migrate --batch-size=10

# Check server logs for errors
tail -f wp-content/debug.log
```

#### Memory Issues
**Symptoms**: "Fatal error: Allowed memory size exhausted"

**Solutions**:
```php
// In wp-config.php
ini_set('memory_limit', '512M');
define('WP_MEMORY_LIMIT', '512M');

// For CLI operations
ini_set('memory_limit', '1G');
```

### Performance Issues

#### Slow Migration
**Symptoms**: Migration takes much longer than expected

**Solutions**:
1. Reduce batch size: 10-25 files per batch
2. Increase PHP max_execution_time
3. Run migrations during low-traffic periods
4. Use WP-CLI for better performance:
   ```bash
   wp bunny migrate --batch-size=15
   ```

#### High Server Load
**Symptoms**: Website becomes slow during operations

**Solutions**:
1. Schedule operations during maintenance windows
2. Use smaller batch sizes
3. Implement rate limiting:
   ```php
   // Add delay between operations
   add_filter('bunny_operation_delay', function() {
       return 2; // 2 second delay
   });
   ```

### File Integrity Issues

#### Missing Files
**Symptoms**: "File not found" errors on frontend

**Solutions**:
```bash
# Verify file integrity
wp bunny verify --all

# Sync missing files back to local
wp bunny sync --missing-only

# Re-upload specific files
wp bunny offload 123 --force
```

#### Corrupted Files
**Symptoms**: Images appear broken or incomplete

**Solutions**:
```bash
# Verify and fix file integrity
wp bunny verify --all --fix

# Re-upload corrupted files
wp bunny offload --corrupted-only --force

# Check file hashes
wp bunny verify 123 --verbose
```

### Optimization Issues

#### Optimization Failures
**Symptoms**: Images not being optimized or errors in logs

**Solutions**:
```bash
# Check GD library support
php -m | grep -i gd

# Verify optimization queue
wp bunny optimization-status

# Clear stuck optimization jobs
wp bunny optimization-clear

# Re-run optimization with verbose output
wp bunny optimize 123 --verbose
```

#### Quality Issues
**Symptoms**: Optimized images appear too compressed

**Solutions**:
1. Increase target file size (55KB or 60KB)
2. Switch to WebP format for better quality
3. Disable optimization for specific file types:
   ```php
   add_filter('bunny_skip_optimization', function($skip, $attachment_id) {
       $file = get_attached_file($attachment_id);
       // Skip PNG files
       return pathinfo($file, PATHINFO_EXTENSION) === 'png';
   }, 10, 2);
   ```

### WPML Issues

#### Translation Sync Problems
**Symptoms**: Translated attachments missing CDN URLs

**Solutions**:
```bash
# Check WPML media translation status
wp eval "var_dump(apply_filters('wpml_active_languages', null));"

# Re-sync translated attachments
wp bunny sync --translations-only

# Verify WPML configuration
wp bunny status --wpml-debug
```

### Debugging

#### Enable Debug Logging
```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('BUNNY_DEBUG', true);
```

#### Useful Log Monitoring
```bash
# Monitor WordPress debug log
tail -f wp-content/debug.log

# Monitor plugin-specific logs
wp bunny logs --tail

# Export detailed logs for support
wp bunny logs --export=/tmp/bunny-debug-logs.csv --level=debug
```

---

## Performance Optimization

### Server Configuration

#### PHP Settings
```php
// Recommended php.ini settings
memory_limit = 512M
max_execution_time = 300
upload_max_filesize = 100M
post_max_size = 100M
max_input_time = 300
```

#### WordPress Configuration
```php
// In wp-config.php
define('WP_MEMORY_LIMIT', '512M');
define('WP_MAX_MEMORY_LIMIT', '512M');

// Increase cron timeout for background operations
define('WP_CRON_LOCK_TIMEOUT', 300);
```

### CDN Optimization

#### Cache Headers
Configure appropriate cache headers in Bunny.net:
- **Images**: 1 year (31536000 seconds)
- **Videos**: 1 month (2592000 seconds) 
- **Documents**: 1 week (604800 seconds)

#### Compression
Enable gzip compression in your Bunny.net pull zone:
1. Go to **Pull Zones > Your Zone > Edge Rules**
2. Add rule: **Enable Gzip Compression**
3. Apply to all file types

### Database Optimization

#### Regular Maintenance
```bash
# Optimize plugin tables
wp db optimize

# Clean old logs (keep last 30 days)
wp bunny logs --cleanup --days=30

# Regenerate statistics cache
wp bunny stats --regenerate
```

#### Index Optimization
The plugin automatically creates optimal database indexes, but you can verify:
```sql
SHOW INDEX FROM wp_bunny_offloaded_files;
SHOW INDEX FROM wp_bunny_optimization_queue;
```

### Monitoring Performance

#### Built-in Statistics
Monitor performance via **Bunny CDN > Dashboard**:
- Files offloaded per day/week/month
- Storage savings percentage
- Bandwidth usage reduction
- Optimization compression ratios

#### CLI Monitoring
```bash
# Performance summary
wp bunny stats --summary

# Daily breakdown
wp bunny stats --period=daily --days=7

# Bandwidth savings
wp bunny stats --bandwidth --month=2024-01
```

---

## Developer Guide

### Architecture Overview

The plugin follows WordPress best practices with a modular architecture:

```
Bunny_Media_Offload (Main Controller)
├── Bunny_API (External API Communication)
├── Bunny_Uploader (File Upload Handling)
├── Bunny_Migration (Bulk Operations)
├── Bunny_Optimizer (Image Optimization)
├── Bunny_Sync (File Synchronization)
├── Bunny_Admin (WordPress Admin Interface)
├── Bunny_Settings (Configuration Management)
├── Bunny_Stats (Statistics and Analytics)
├── Bunny_Logger (Logging and Debugging)
├── Bunny_CLI (WP-CLI Commands)
├── Bunny_WPML (Multilingual Support)
└── Bunny_Utils (Helper Functions)
```

### Hooks and Filters

#### Upload Hooks
```php
// Modify upload behavior
add_filter('bunny_before_upload', function($file_path, $attachment_id) {
    // Custom logic before upload
    return $file_path;
}, 10, 2);

// Post-upload processing
add_action('bunny_after_upload', function($attachment_id, $bunny_url) {
    // Custom logic after successful upload
}, 10, 2);

// Upload failure handling
add_action('bunny_upload_failed', function($attachment_id, $error) {
    // Custom error handling
}, 10, 2);
```

#### URL Filtering
```php
// Customize CDN URLs
add_filter('bunny_cdn_url', function($url, $attachment_id) {
    // Add custom parameters
    return $url . '?watermark=true';
}, 10, 2);

// Conditional URL modification
add_filter('bunny_cdn_url', function($url, $attachment_id) {
    $post = get_post($attachment_id);
    if ($post && $post->post_type === 'product') {
        // Use different CDN for product images
        return str_replace('cdn.site.com', 'products.cdn.site.com', $url);
    }
    return $url;
}, 10, 2);
```

#### Optimization Hooks
```php
// Skip optimization for specific files
add_filter('bunny_skip_optimization', function($skip, $attachment_id) {
    $meta = wp_get_attachment_metadata($attachment_id);
    // Skip files larger than 5MB
    return $meta && $meta['filesize'] > 5242880;
}, 10, 2);

// Custom optimization settings per file
add_filter('bunny_optimization_settings', function($settings, $attachment_id) {
    $post = get_post($attachment_id);
    if ($post && has_term('high-quality', 'attachment_category', $post)) {
        $settings['max_size'] = 100; // Less compression for high-quality images
    }
    return $settings;
}, 10, 2);
```

#### Migration Hooks
```php
// Custom migration filters
add_filter('bunny_migration_query', function($query) {
    // Only migrate images from the last year
    global $wpdb;
    $query .= $wpdb->prepare(
        " AND post_date > %s", 
        date('Y-m-d', strtotime('-1 year'))
    );
    return $query;
});

// Pre-migration validation
add_filter('bunny_before_migration', function($attachment_ids) {
    // Remove attachments that shouldn't be migrated
    return array_filter($attachment_ids, function($id) {
        return !get_post_meta($id, '_skip_bunny_migration', true);
    });
});
```

### Custom Extensions

#### Creating a Custom Module
```php
class My_Bunny_Extension {
    private $bunny;
    
    public function __construct() {
        add_action('bunny_loaded', array($this, 'init'));
    }
    
    public function init($bunny_instance) {
        $this->bunny = $bunny_instance;
        $this->add_hooks();
    }
    
    private function add_hooks() {
        add_filter('bunny_cdn_url', array($this, 'modify_urls'), 20, 2);
        add_action('bunny_after_upload', array($this, 'post_upload'), 10, 2);
    }
    
    public function modify_urls($url, $attachment_id) {
        // Custom URL logic
        return $url;
    }
    
    public function post_upload($attachment_id, $bunny_url) {
        // Post-upload logic
    }
}

new My_Bunny_Extension();
```

#### Database Schema Extensions

Add custom tables for extensions:
```php
function my_bunny_create_tables() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'my_bunny_extension';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        attachment_id int(11) NOT NULL,
        custom_data longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY attachment_id (attachment_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

register_activation_hook(__FILE__, 'my_bunny_create_tables');
```

### API Integration Examples

#### Custom API Endpoints
```php
add_action('rest_api_init', function() {
    register_rest_route('bunny/v1', '/migrate', array(
        'methods' => 'POST',
        'callback' => 'my_custom_migration',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
});

function my_custom_migration($request) {
    $bunny = Bunny_Media_Offload::get_instance();
    
    // Custom migration logic
    $result = $bunny->migration->start_custom_migration($request->get_params());
    
    return new WP_REST_Response($result, 200);
}
```

#### External Service Integration
```php
// Integrate with external image processing service
add_action('bunny_before_upload', function($file_path, $attachment_id) {
    if (wp_attachment_is_image($attachment_id)) {
        // Send to external processing service
        $processed_path = my_external_processor($file_path);
        return $processed_path;
    }
    return $file_path;
}, 5, 2); // Priority 5 to run before optimization
```

---

## FAQ

### General Questions

**Q: Is my data safe with Bunny.net?**
A: Yes, Bunny.net provides enterprise-grade security with SSL encryption, DDoS protection, and SOC 2 compliance. Your media files are distributed across multiple data centers for redundancy.

**Q: Will this plugin slow down my WordPress site?**
A: No, the plugin is designed for performance. After setup, your site will actually load faster due to CDN delivery. The initial migration may temporarily impact performance.

**Q: Can I use this with other CDN plugins?**
A: It's not recommended to use multiple CDN plugins simultaneously as they may conflict. Disable other CDN plugins before using Bunny Media Offload.

**Q: What happens if I deactivate the plugin?**
A: Your media files remain on Bunny.net, but WordPress will revert to looking for local files. You can re-download files using the sync feature before deactivation.

### Technical Questions

**Q: Do I need to modify my theme?**
A: No, the plugin automatically handles URL rewriting. Your theme's image display code remains unchanged.

**Q: Can I migrate existing media files?**
A: Yes, use the bulk migration tool in **Bunny CDN > Migration** or the WP-CLI command `wp bunny migrate`.

**Q: How does the plugin handle image sizes (thumbnails)?**
A: WordPress thumbnail generation works normally. The plugin uploads all image sizes to Bunny.net and serves them via CDN.

**Q: Can I use custom image transformations?**
A: Yes, if you have Bunny.net's Image Optimizer enabled, you can use URL parameters for transformations:
```
https://cdn.yoursite.com/image.jpg?width=300&height=200&quality=85
```

### Pricing and Costs

**Q: How much does Bunny.net storage cost?**
A: Bunny.net charges approximately $0.01/GB/month for storage plus $0.01/GB for bandwidth. Most WordPress sites see significant cost savings compared to traditional hosting.

**Q: Are there any hidden fees?**
A: No, Bunny.net uses transparent pay-as-you-go pricing. You only pay for storage used and bandwidth consumed.

**Q: How can I estimate my costs?**
A: Use the cost calculator on Bunny.net or check your current storage usage in **Bunny CDN > Dashboard** after installation.

### Troubleshooting

**Q: Why do I see "broken image" icons?**
A: This usually indicates:
1. Incorrect API key or storage zone configuration
2. Files not properly uploaded to Bunny.net
3. DNS issues with custom hostname

Run **Test Connection** in settings and check the logs.

**Q: Can I restore my local files?**
A: Yes, use the sync feature to download files back from Bunny.net:
```bash
wp bunny sync --all
```

**Q: How do I handle SSL certificate issues?**
A: Ensure your custom hostname has a valid SSL certificate. Bunny.net provides free SSL certificates for custom hostnames.

### Migration Questions

**Q: How long does migration take?**
A: Migration time depends on:
- Number of files
- Total file size
- Server performance
- Network speed

Typical rates: 50-100 files per minute for images.

**Q: Can I pause and resume migration?**
A: Yes, migrations can be paused and resumed. Progress is saved automatically.

**Q: What if migration fails partway through?**
A: The plugin tracks progress and only processes remaining files when restarted. Check logs for specific error details.

### WPML Questions

**Q: Do I need separate storage zones for each language?**
A: No, all languages can share the same storage zone and CDN URLs, reducing costs.

**Q: How does media translation work?**
A: When WPML creates translated attachments, the plugin automatically links them to the same CDN file, preventing duplicate uploads.

**Q: Can I migrate specific languages only?**
A: Yes, the migration tool offers "Current Language Only" and "All Languages" options.

---

## Support and Resources

### Getting Help

1. **Documentation**: This comprehensive guide covers most use cases
2. **WordPress Support Forums**: Community support and discussions
3. **Plugin Logs**: Check **Bunny CDN > Logs** for error details
4. **WP-CLI Debugging**: Use `--debug` flag for verbose output

### Useful Resources

- **Bunny.net Documentation**: [docs.bunny.net](https://docs.bunny.net)
- **WordPress Developer Reference**: [developer.wordpress.org](https://developer.wordpress.org)
- **WP-CLI Documentation**: [wp-cli.org](https://wp-cli.org)
- **WPML Documentation**: [wpml.org/documentation](https://wpml.org/documentation)

### Reporting Issues

When reporting issues, please include:
1. WordPress and PHP versions
2. Plugin version
3. Error messages from logs
4. Steps to reproduce the issue
5. Server configuration details

### Contributing

The plugin is open source and welcomes contributions:
- Report bugs and feature requests
- Submit translations
- Contribute code improvements
- Help with documentation

---

*Last updated: January 2024*
*Plugin version: 1.0.0* 