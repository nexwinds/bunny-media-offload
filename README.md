# Bunny Media Offload

**Contributors:** nexwinds  
**Tags:** bunny, cdn, media, offload, optimization  
**Requires at least:** 5.0  
**Tested up to:** 6.8  
**Stable tag:** 1.0.0  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  

Integrates with Bunny.net Edge Storage to automatically offload and manage WordPress media files with CDN acceleration and optimization.

## Description

A comprehensive WordPress plugin that integrates with Bunny.net Edge Storage (SSD) to automatically offload and manage media files, providing CDN acceleration and significant storage savings.

## Features

### 🚀 Core Functionality
- **Automatic Media Offload**: Automatically uploads new media files to Bunny.net Edge Storage
- **Image Optimization**: Convert images to modern formats (AVIF/WebP) and compress for web performance
- **Local File Management**: Optionally deletes local copies after successful upload
- **File Versioning**: Adds timestamp-based versioning for CDN cache busting
- **WooCommerce & HPOS Compatible**: Full integration with WooCommerce product images and High-Performance Order Storage

### 📊 Migration & Management
- **Bulk Migration Tool**: Migrate existing media files in batches of 90 (configurable)
- **Bulk Optimization**: Queue-based image optimization with configurable batch processing
- **Progress Tracking**: Real-time migration and optimization progress with detailed logs
- **Selective Migration**: Choose file types to migrate (images, videos, documents)
- **Recovery Options**: Re-download files from Bunny.net back to local storage

### 🔧 Advanced Features
- **Connection Testing**: Built-in Bunny.net API connectivity verification
- **Custom Hostname Support**: Use your own domain for CDN URLs
- **Statistics Dashboard**: Track storage savings, bandwidth usage, and costs
- **Activity Logging**: Comprehensive logging with multiple levels (error, warning, info, debug)
- **WP-CLI Commands**: Command-line interface for automation and scripting

### 🎛️ Configuration Options
- **File Type Filtering**: Enable/disable offload for specific file types
- **Post Type Support**: Configure which post types should have media offloaded
- **Batch Processing**: Configurable batch sizes to prevent timeouts
- **Automatic Cleanup**: Remove orphaned files and maintain data integrity

## Installation

### Requirements
- WordPress 5.0 or higher
- PHP 7.4 or higher
- WooCommerce 5.0+ (for e-commerce features)
- Bunny.net account with Edge Storage zone

### Manual Installation
1. Download the plugin files
2. Upload the `bunny-media-offload` folder to `/wp-content/plugins/`
3. Activate the plugin through the WordPress admin panel
4. Navigate to **Bunny CDN** in the admin menu to configure

### Configuration

#### 1. Bunny.net Setup
1. Create a Bunny.net account at [bunny.net](https://bunny.net)
2. Create a new Storage Zone in your Bunny.net dashboard
3. Generate an API key with storage permissions
4. Note your storage zone name and API key

#### 2. Plugin Configuration
1. Go to **Bunny CDN > Settings** in WordPress admin
2. Enter your Bunny.net API key
3. Enter your storage zone name
4. (Optional) Set a custom hostname for your CDN URLs
5. Configure automatic offload settings
6. Test the connection using the "Test Connection" button

#### 3. Migration (Optional)
1. Go to **Bunny CDN > Migration**
2. Select file types to migrate
3. Click "Start Migration" to begin bulk upload
4. Monitor progress in real-time

## Usage

### Automatic Offloading
Once configured, the plugin automatically handles new media uploads:
1. User uploads media file
2. File is uploaded to Bunny.net Edge Storage
3. Local file is optionally deleted
4. WordPress URLs automatically point to CDN

### Manual Operations
- **Individual File Sync**: Download specific files back to local storage
- **Bulk Operations**: Mass sync or cleanup operations
- **Verification**: Check file integrity across local and remote storage

### WP-CLI Commands

The plugin includes comprehensive WP-CLI support:

```bash
# Check plugin status
wp bunny status

# Offload all images
wp bunny offload --file-types=image

# Sync specific file
wp bunny sync 123

# Verify file integrity
wp bunny verify --fix

# Cleanup orphaned files
wp bunny cleanup

# Optimize all images
wp bunny optimize --file-types=jpg,png,gif

# Optimize specific attachment
wp bunny optimize 123

# Check optimization status
wp bunny optimization-status
```

## WPML Multilingual Support

Full compatibility with WPML (WordPress Multilingual Plugin) for multilingual websites:

### Features
- **Automatic Synchronization**: Media files are automatically synchronized across all language versions
- **Shared CDN URLs**: All language versions of a file share the same CDN URL, reducing storage costs and improving efficiency
- **Language-Specific Migration**: Choose to migrate files from current language only or all languages during bulk operations
- **Translation Awareness**: Optimization and offloading work intelligently with translated content to avoid duplicating work
- **Admin Interface**: WPML status and language information displayed throughout the admin interface

### How It Works
1. **Detection**: Plugin automatically detects WPML installation and enables multilingual features
2. **Settings Sync**: All plugin settings are shared across languages for consistency
3. **Media Sync**: When WPML creates a translated attachment, Bunny metadata is automatically copied
4. **Smart Optimization**: Original files are optimized once and shared across all language versions

### Configuration
1. Install and configure WPML as usual for your multilingual site
2. The plugin automatically detects WPML and enables multilingual features
3. Navigate to **Bunny CDN > Settings** to see WPML status and active languages
4. All plugin settings work globally across all languages

### Migration with WPML
When performing bulk migrations, you can choose:
- **Current Language Only**: Migrate only files from the currently selected language
- **All Languages**: Migrate files from all active languages at once
- The plugin intelligently handles duplicate files across languages to avoid redundant uploads

### Optimization with WPML
- Original files are optimized once and the optimized version is shared across all translations
- Prevents redundant optimization of the same physical file for different language versions
- Optimization metadata is synchronized across all language versions automatically

### Developer Integration
The plugin provides WPML-specific hooks for developers:

```php
// Triggered when a file is uploaded (used for WPML sync)
do_action('bunny_file_uploaded', $attachment_id, $bunny_url);

// Filter migration results by language
apply_filters('bunny_migration_attachments', $attachments, $args);

// Filter optimization queue for multilingual sites
apply_filters('bunny_optimization_attachments', $attachment_ids);

// Modify CDN URLs based on language (for language-specific subdomains)
apply_filters('bunny_cdn_url', $url, $attachment_id, $original_url);
```

### Requirements
- WPML version 4.0 or higher
- WPML Media Translation enabled for full functionality

## Settings Reference

### Basic Settings
- **API Key**: Your Bunny.net Storage API key
- **Storage Zone**: Name of your Bunny.net storage zone
- **Custom Hostname**: Optional custom domain for CDN URLs

### Behavior Settings
- **Auto Offload**: Automatically offload new uploads
- **Delete Local**: Remove local files after successful upload
- **File Versioning**: Add version parameters for cache busting
- **Batch Size**: Number of files to process per batch (1-500)

### File Type Settings
- **Allowed File Types**: File extensions to offload (jpg, png, pdf, etc.)
- **Allowed Post Types**: Post types that should have media offloaded

## Troubleshooting

### Common Issues

#### Connection Test Fails
- Verify API key is correct and has storage permissions
- Check storage zone name spelling
- Ensure firewall allows outbound HTTPS connections

#### Files Not Offloading
- Check "Auto Offload" is enabled
- Verify file type is in allowed list
- Review activity logs for error messages

#### Migration Stalls
- Reduce batch size in settings
- Check server timeout limits
- Monitor error logs for specific failures

### Support Resources
- **Activity Logs**: Check **Bunny CDN > Logs** for detailed error information
- **Connection Test**: Use built-in connectivity verification
- **File Verification**: Run integrity checks to identify issues

## Performance & Costs

### Storage Savings
- Typical reduction: 60-90% of local server storage
- Faster page loads through global CDN
- Reduced hosting bandwidth costs

### Cost Comparison
- Traditional hosting storage: ~$0.10/GB/month
- Bunny.net Edge Storage: ~$0.01/GB/month
- Additional bandwidth savings through CDN caching

### Performance Benefits
- Global edge locations for faster delivery
- Automatic image optimization (with Bunny.net Optimizer)
- Reduced server load and improved site performance

## Security

### Data Protection
- All transfers use HTTPS encryption
- API keys stored securely in WordPress database
- File integrity verification during transfers

### Access Control
- WordPress capability-based permissions
- Secure nonce verification for all AJAX requests
- Input sanitization and validation

## Development

### File Structure
```
bunny-media-offload/
├── bunny-media-offload.php     # Main plugin file
├── includes/
│   ├── class-bunny-media-offload.php
│   ├── class-bunny-api.php
│   ├── class-bunny-uploader.php
│   ├── class-bunny-migration.php
│   ├── class-bunny-sync.php
│   ├── class-bunny-admin.php
│   ├── class-bunny-settings.php
│   ├── class-bunny-stats.php
│   ├── class-bunny-logger.php
│   ├── class-bunny-cli.php
│   └── class-bunny-utils.php
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
└── README.md
```

### Hooks & Filters
The plugin provides numerous hooks for customization:

```php
// Modify allowed file types
add_filter('bunny_allowed_file_types', function($types) {
    $types[] = 'svg';
    return $types;
});

// Custom upload path
add_filter('bunny_remote_path', function($path, $attachment_id) {
    return 'custom/' . $path;
}, 10, 2);
```

## License

GPL v2 or later

## Support

For technical support and feature requests, please use the WordPress plugin support forums or contact the plugin developer.

---

**Bunny Media Offload** - Accelerate your WordPress site with Bunny.net Edge Storage 