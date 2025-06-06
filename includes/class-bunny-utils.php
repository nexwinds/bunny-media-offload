<?php
/**
 * Bunny utilities
 */
class Bunny_Utils {
    
    /**
     * Format file size for display
     */
    public static function format_file_size($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            return $bytes . ' bytes';
        } elseif ($bytes == 1) {
            return $bytes . ' byte';
        } else {
            return '0 bytes';
        }
    }
    
    /**
     * Get file extension from path
     */
    public static function get_file_extension($file_path) {
        return strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    }
    
    /**
     * Check if file is an image
     */
    public static function is_image($file_path) {
        $image_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff');
        $extension = self::get_file_extension($file_path);
        return in_array($extension, $image_extensions);
    }
    
    /**
     * Check if file is a video
     */
    public static function is_video($file_path) {
        $video_extensions = array('mp4', 'mov', 'avi', 'wmv', 'flv', 'webm', 'mkv');
        $extension = self::get_file_extension($file_path);
        return in_array($extension, $video_extensions);
    }
    
    /**
     * Check if file is a document
     */
    public static function is_document($file_path) {
        $doc_extensions = array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf');
        $extension = self::get_file_extension($file_path);
        return in_array($extension, $doc_extensions);
    }
    
    /**
     * Get file type category
     */
    public static function get_file_type($file_path) {
        if (self::is_image($file_path)) {
            return 'image';
        } elseif (self::is_video($file_path)) {
            return 'video';
        } elseif (self::is_document($file_path)) {
            return 'document';
        } else {
            return 'other';
        }
    }
    
    /**
     * Sanitize file name for remote storage
     */
    public static function sanitize_file_name($filename) {
        // Remove or replace special characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        // Remove multiple underscores
        $filename = preg_replace('/_+/', '_', $filename);
        
        // Remove leading/trailing underscores
        $filename = trim($filename, '_');
        
        return $filename;
    }
    
    /**
     * Generate unique file name to prevent conflicts
     */
    public static function generate_unique_filename($filename, $existing_files = array()) {
        $original_filename = $filename;
        $counter = 1;
        
        while (in_array($filename, $existing_files)) {
            $file_info = pathinfo($original_filename);
            $filename = $file_info['filename'] . '_' . $counter;
            if (!empty($file_info['extension'])) {
                $filename .= '.' . $file_info['extension'];
            }
            $counter++;
        }
        
        return $filename;
    }
    
    /**
     * Convert relative path to absolute
     */
    public static function rel_to_abs_path($rel_path) {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir']) . ltrim($rel_path, '/\\');
    }
    
    /**
     * Convert absolute path to relative
     */
    public static function abs_to_rel_path($abs_path) {
        $upload_dir = wp_upload_dir();
        return str_replace($upload_dir['basedir'], '', $abs_path);
    }
    
    /**
     * Check if URL is external
     */
    public static function is_external_url($url) {
        $site_url = parse_url(site_url());
        $url_parts = parse_url($url);
        
        return !empty($url_parts['host']) && $url_parts['host'] !== $site_url['host'];
    }
    
    /**
     * Get MIME type from file extension
     */
    public static function get_mime_type($file_path) {
        $extension = self::get_file_extension($file_path);
        
        $mime_types = array(
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'tiff' => 'image/tiff',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'rtf' => 'application/rtf',
            'zip' => 'application/zip',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav'
        );
        
        return isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
    }
    
    /**
     * Format date for display
     */
    public static function format_date($date, $format = null) {
        if (empty($format)) {
            $format = get_option('date_format') . ' ' . get_option('time_format');
        }
        
        if (is_string($date)) {
            $date = strtotime($date);
        }
        
        return date_i18n($format, $date);
    }
    
    /**
     * Calculate time difference
     */
    public static function time_ago($date) {
        if (is_string($date)) {
            $date = strtotime($date);
        }
        
        $diff = time() - $date;
        
        if ($diff < 60) {
            return __('Just now', 'bunny-media-offload');
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            // translators: %d is the number of minutes
            return sprintf(_n('%d minute ago', '%d minutes ago', $minutes, 'bunny-media-offload'), $minutes);
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            // translators: %d is the number of hours
            return sprintf(_n('%d hour ago', '%d hours ago', $hours, 'bunny-media-offload'), $hours);
        } elseif ($diff < 2592000) {
            $days = floor($diff / 86400);
            // translators: %d is the number of days
            return sprintf(_n('%d day ago', '%d days ago', $days, 'bunny-media-offload'), $days);
        } else {
            return self::format_date($date);
        }
    }
    
    /**
     * Validate API key format
     */
    public static function validate_api_key($api_key) {
        // Bunny.net API keys are typically alphanumeric with dashes
        return preg_match('/^[a-zA-Z0-9\-]{20,}$/', $api_key);
    }
    
    /**
     * Validate storage zone name
     */
    public static function validate_storage_zone($zone_name) {
        // Storage zone names should be alphanumeric with dashes
        return preg_match('/^[a-zA-Z0-9\-]{3,}$/', $zone_name);
    }
    
    /**
     * Get progress percentage
     */
    public static function get_progress_percentage($current, $total) {
        if ($total == 0) {
            return 0;
        }
        
        return min(100, round(($current / $total) * 100, 2));
    }
    
    /**
     * Estimate time remaining
     */
    public static function estimate_time_remaining($current, $total, $start_time) {
        if ($current == 0 || $total == 0) {
            return null;
        }
        
        $elapsed = time() - $start_time;
        $rate = $current / $elapsed;
        $remaining = $total - $current;
        
        if ($rate > 0) {
            return round($remaining / $rate);
        }
        
        return null;
    }
    
    /**
     * Format time duration
     */
    public static function format_duration($seconds) {
        if ($seconds < 60) {
            // translators: %d is the number of seconds
            return sprintf(__('%d seconds', 'bunny-media-offload'), $seconds);
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            // translators: %d is the number of minutes
            return sprintf(__('%d minutes', 'bunny-media-offload'), $minutes);
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            // translators: %1$d is the number of hours, %2$d is the number of minutes
            return sprintf(__('%1$d hours %2$d minutes', 'bunny-media-offload'), $hours, $minutes);
        }
    }
} 