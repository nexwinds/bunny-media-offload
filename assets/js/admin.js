/**
 * Bunny Media Offload Admin JavaScript
 */
(function($) {
    'use strict';
    
    var BunnyAdmin = {
        
        optimizationState: {
            active: false,
            sessionId: null,
            totalImages: 0
        },
        
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.initLogs();
            this.initOptimization();
            this.initTroubleshooting();
            
            // Refresh stats on page load for consistent display
            this.refreshAllStats();
        },
        
        /**
         * Bind events
         */
        bindEvents: function() {
            var self = this;
            
            console.log('Binding global events...');
            
            // Test connection
            $(document).on('click', '#test-connection', this.testConnection);
            
            // Settings form
            $(document).on('submit', '#bunny-settings-form', this.saveSettings);
            
            // Clear logs - with proper context binding
            $(document).on('click', '#clear-logs', function(e) {
                console.log('Clear logs event triggered via delegation');
                self.clearLogs(e);
            });
            
            // Export logs - with proper context binding
            $(document).on('click', '#export-logs', function(e) {
                console.log('Export logs event triggered via delegation');
                self.exportLogs(e);
            });
            
            console.log('Global events bound successfully');
        },
        
        /**
         * Initialize troubleshooting functionality
         */
        initTroubleshooting: function() {
            // Regenerate thumbnails
            $(document).on('click', '#regenerate-thumbnails', function(e) {
                e.preventDefault();
                BunnyAdmin.regenerateThumbnails();
            });
        },
        
        /**
         * Initialize logs functionality
         */
        initLogs: function() {
            // Check if we're on the logs page
            var isLogsPage = window.location.href.indexOf('page=bunny-media-logs') !== -1;
            
            if (!isLogsPage) {
                return;
            }
            
            console.log('=== LOGS INITIALIZATION DEBUG ===');
            console.log('Logs page detected, checking buttons...');
            console.log('Export button found:', $('#export-logs').length > 0);
            console.log('Clear button found:', $('#clear-logs').length > 0);
            console.log('bunnyAjax available:', typeof bunnyAjax !== 'undefined');
            
            if (typeof bunnyAjax !== 'undefined') {
                console.log('bunnyAjax.ajaxurl:', bunnyAjax.ajaxurl);
                console.log('bunnyAjax.nonce:', bunnyAjax.nonce);
            } else {
                console.error('bunnyAjax object is not available!');
                return;
            }
            
            // Test if buttons exist and have data attributes
            var $exportBtn = $('#export-logs');
            var $clearBtn = $('#clear-logs');
            
            if ($exportBtn.length > 0) {
                console.log('Export button data-log-type:', $exportBtn.data('log-type'));
                console.log('Export button data-log-level:', $exportBtn.data('log-level'));
            }
            
            if ($clearBtn.length > 0) {
                console.log('Clear button data-log-type:', $clearBtn.data('log-type'));
            }
            
            // Remove any existing handlers and add new ones
            $exportBtn.off('click.bunny').on('click.bunny', function(e) {
                console.log('=== EXPORT BUTTON CLICKED ===');
                console.log('Event object:', e);
                e.preventDefault();
                BunnyAdmin.exportLogs(e);
            });
            
            $clearBtn.off('click.bunny').on('click.bunny', function(e) {
                console.log('=== CLEAR BUTTON CLICKED ===');
                console.log('Event object:', e);
                e.preventDefault();
                BunnyAdmin.clearLogs(e);
            });
            
            console.log('=== END LOGS INITIALIZATION ===');
        },
        
        /**
         * Initialize optimization functionality
         */
        initOptimization: function() {
            // Log initial state
            console.log('Initializing optimization functionality');
            console.log('Available optimization buttons:', $('.bunny-optimize-button').length);
            
            // Optimization is now handled by the modular BunnyOptimization system
            // Remove duplicate event handlers to prevent double triggering
            
            $(document).on('click', '#cancel-optimization', function(e) {
                e.preventDefault();
                if (window.bunnyOptimizationInstance) {
                    window.bunnyOptimizationInstance.cancelOptimization();
                }
            });
            
            // Handle diagnostic button clicks
            $(document).on('click', '.bunny-diagnostic-button', function(e) {
                e.preventDefault();
                BunnyAdmin.runOptimizationDiagnostics();
            });
        },
        
        /**
         * Test Bunny.net connection
         */
        testConnection: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var originalText = $button.text();
            
            $button.text('Testing...').prop('disabled', true);
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_test_connection',
                    nonce: bunnyAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('Connection successful!');
                    } else {
                        alert('Connection failed: ' + response.data.message);
                    }
                },
                error: function() {
                    alert('Connection test failed due to an error.');
                },
                complete: function() {
                    $button.text(originalText).prop('disabled', false);
                }
            });
        },
        
        /**
         * Save settings
         */
        saveSettings: function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var formData = $form.serialize();
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: formData + '&action=bunny_save_settings',
                success: function(response) {
                    if (response.success) {
                        BunnyAdmin.showNotice(response.data.message, 'success');
                    } else {
                        if (response.data.errors) {
                            var errors = Object.values(response.data.errors).join('<br>');
                            BunnyAdmin.showNotice(errors, 'error');
                        } else {
                            BunnyAdmin.showNotice(response.data.message, 'error');
                        }
                    }
                },
                error: function() {
                    BunnyAdmin.showNotice('Failed to save settings.', 'error');
                }
            });
        },
        
        /**
         * Show notification
         */
        showNotice: function(message, type) {
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            var $notices = $('.bunny-notices');
            
            if ($notices.length === 0) {
                $notices = $('<div class="bunny-notices"></div>');
                $('.wrap > h1').after($notices);
            }
            
            $notices.append($notice);
            
            // Add dismiss button
            $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
            
            // Handle dismiss click
            $notice.find('.notice-dismiss').on('click', function() {
                $(this).closest('.notice').fadeOut(300, function() {
                    $(this).remove();
                });
            });
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        },
        
        /**
         * Export logs
         */
        exportLogs: function(e) {
            e.preventDefault();
            console.log('exportLogs function called');
            
            var $button = $(e.target);
            var logType = $button.data('log-type') || 'all';
            var logLevel = $button.data('log-level') || '';
            
            console.log('Button:', $button);
            console.log('Log type:', logType);
            console.log('Log level:', logLevel);
            console.log('bunnyAjax:', bunnyAjax);
            
            // Show loading state
            $button.prop('disabled', true).text('Exporting...');
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_export_logs',
                    nonce: bunnyAjax.nonce,
                    log_type: logType,
                    log_level: logLevel
                },
                success: function(response) {
                    console.log('Export logs response:', response);
                    if (response.success) {
                        // Create download link
                        var blob = new Blob([response.data.content], { type: 'text/csv' });
                        var link = document.createElement('a');
                        link.href = window.URL.createObjectURL(blob);
                        link.download = response.data.filename;
                        link.click();
                    } else {
                        alert('Failed to export logs: ' + response.data.message);
                    }
                },
                error: function() {
                    alert('Failed to export logs.');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Export Filtered Logs');
                }
            });
        },
        
        /**
         * Clear logs
         */
        clearLogs: function(e) {
            e.preventDefault();
            console.log('clearLogs function called');
            
            var $button = $(e.target);
            var logType = $button.data('log-type') || 'all';
            
            console.log('Button:', $button);
            console.log('Log type:', logType);
            console.log('bunnyAjax:', bunnyAjax);
            
            var confirmMessage = logType === 'all' 
                ? 'This will permanently delete all logs. Continue?'
                : 'This will permanently delete all ' + logType + ' logs. Continue?';
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            // Show loading state
            $button.prop('disabled', true).text('Clearing...');
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_clear_logs',
                    nonce: bunnyAjax.nonce,
                    log_type: logType
                },
                success: function(response) {
                    console.log('Clear logs response:', response);
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert('Failed to clear logs: ' + response.data.message);
                    }
                },
                error: function() {
                    alert('Failed to clear logs.');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Clear Filtered Logs');
                }
            });
        },
        
        /**
         * Format file size
         */
        formatFileSize: function(bytes) {
            if (bytes === 0) return '0 Bytes';
            
            var k = 1024;
            var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },
        
        /**
         * Regenerate missing thumbnails
         */
        regenerateThumbnails: function() {
            var $button = $('#regenerate-thumbnails');
            var originalText = $button.text();
            
            $button.text('Regenerating...').prop('disabled', true);
            $('#thumbnail-regeneration-status').show();
            $('#thumbnail-status-text').text('Starting thumbnail regeneration...');
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_regenerate_thumbnails',
                    nonce: bunnyAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#thumbnail-status-text').text(response.data.message);
                        BunnyAdmin.showNotice('Thumbnails regenerated successfully: ' + response.data.message, 'success');
                    } else {
                        $('#thumbnail-status-text').text('Error: ' + response.data.message);
                        BunnyAdmin.showNotice('Failed to regenerate thumbnails: ' + response.data.message, 'error');
                    }
                },
                error: function() {
                    $('#thumbnail-status-text').text('Failed to regenerate thumbnails due to a network error.');
                    BunnyAdmin.showNotice('Failed to regenerate thumbnails due to a network error.', 'error');
                },
                complete: function() {
                    $button.text(originalText).prop('disabled', false);
                }
            });
        },
        
        /**
         * Update dashboard stats (real-time)
         */
        updateDashboardStats: function() {
            if ($('.bunny-dashboard').length === 0) {
                return;
            }
            
            // If the unified stats module is available, use it instead
            if (window.BunnyStats) {
                window.BunnyStats.fetchStats();
                return;
            }
            
            // Fallback to original implementation
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_get_stats',
                    nonce: bunnyAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var stats = response.data;
                        
                        // Update stat cards
                        $('.bunny-stat-card').each(function() {
                            var $card = $(this);
                            var $number = $card.find('.bunny-stat-number');
                            
                            if ($card.find('h3').text().includes('Files')) {
                                $number.text(stats.total_files.toLocaleString());
                            } else if ($card.find('h3').text().includes('Space')) {
                                $number.text(stats.space_saved);
                            } else if ($card.find('h3').text().includes('Progress')) {
                                $number.text(stats.migration_progress + '%');
                                $card.find('.bunny-progress-fill').css('width', stats.migration_progress + '%');
                            } else if ($card.find('h3').text().includes('Savings')) {
                                $number.text(stats.monthly_savings);
                            }
                        });
                    }
                }
            });
        },
        
        /**
         * Show optimization criteria
         */
        showOptimizationCriteria: function() {
            // Make sure the container exists
            if ($('.bunny-optimization-criteria').length > 0) {
                $('.bunny-optimization-criteria').show();
            }
        },
        
        /**
         * Update optimization UI
         */
        updateOptimizationUI: function(isProcessing) {
            var $startBtn = $('#start-optimization');
            var $cancelBtn = $('#cancel-optimization');
            var $progress = $('#optimization-progress');
            
            if (isProcessing) {
                $startBtn.hide();
                $cancelBtn.show();
                $progress.show();
            } else {
                $startBtn.show();
                $cancelBtn.hide();
                $progress.hide();
            }
        },
        
        /**
         * Handle optimization error
         */
        onOptimizationError: function(data) {
            console.log('Optimization error:', data);
            this.updateOptimizationUI(false);
        },

        /**
         * Run optimization diagnostics
         */
        runOptimizationDiagnostics: function() {
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_run_optimization_diagnostics',
                    nonce: bunnyAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var results = response.data.results;
                        var html = '<div class="bunny-diagnostic-results">';
                        
                        // BMO API Status
                        html += '<h3>BMO API Connection</h3>';
                        html += '<div class="bunny-diagnostic-item">';
                        html += '<span class="bunny-diagnostic-label">API Status:</span> ';
                        html += '<span class="bunny-diagnostic-value ';
                        html += results.bmo_api_connected ? 'bunny-diagnostic-success">Connected' : 'bunny-diagnostic-error">Not Connected';
                        html += '</span></div>';
                        
                        // API Key
                        html += '<div class="bunny-diagnostic-item">';
                        html += '<span class="bunny-diagnostic-label">API Key:</span> ';
                        html += '<span class="bunny-diagnostic-value ';
                        html += results.bmo_api_key ? 'bunny-diagnostic-success">Configured' : 'bunny-diagnostic-error">Not Configured';
                        html += '</span></div>';
                        
                        // Display details
                        if (results.details) {
                            html += '<h3>Diagnostic Details</h3>';
                            html += '<pre class="bunny-diagnostic-details">' + results.details + '</pre>';
                        }
                        
                        html += '</div>';
                        
                        // Show results in a modal or container
                        if ($('#optimization-diagnostic-results').length === 0) {
                            $('.bunny-optimization-dashboard').prepend('<div id="optimization-diagnostic-results"></div>');
                        }
                        
                        $('#optimization-diagnostic-results').html(html).show();
                    } else {
                        alert('Diagnostics failed: ' + response.data.message);
                    }
                },
                error: function() {
                    alert('Failed to run diagnostics due to a network error.');
                }
            });
        },
        
        /**
         * Refresh all statistics on page load to ensure consistency
         */
        refreshAllStats: function() {
            // Only refresh if we're on an admin page for this plugin
            if (window.location.href.indexOf('page=bunny-media-offload') !== -1) {
                // If the unified stats module is available, use it
                if (window.BunnyStats) {
                    window.BunnyStats.fetchStats();
                    return;
                }
                
                // Fallback to original implementation
                $.ajax({
                    url: bunnyAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bunny_refresh_all_stats',
                        nonce: bunnyAjax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update dashboard stats and other UI elements
                            BunnyAdmin.updateDashboardStats();
                            
                            // Refresh optimization stats if on optimization page
                            if (window.location.href.indexOf('optimization') !== -1 && 
                                typeof window.bunnyOptimizationInstance !== 'undefined') {
                                window.bunnyOptimizationInstance.refreshOptimizationStats();
                            }
                            
                            console.log('Statistics refreshed successfully');
                        }
                    }
                });
            }
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        BunnyAdmin.init();
        
        // Show optimization criteria on optimization page
        if ($('.bunny-optimization-actions').length > 0) {
            BunnyAdmin.showOptimizationCriteria();
        }
        
        // Listen for optimization errors
        $(document).on('bunny_optimization_error', function(e, data) {
            BunnyAdmin.onOptimizationError(data);
        });
        
        // Update dashboard stats every 30 seconds
        setInterval(BunnyAdmin.updateDashboardStats, 30000);
        
        // Additional initialization for logs page (safety check)
        if (window.location.href.indexOf('page=bunny-media-logs') !== -1) {
            console.log('=== LOGS PAGE DETECTED - ADDITIONAL INIT ===');
            
            // Wait for DOM to be fully ready
            setTimeout(function() {
                // Direct binding as a fallback
                var $exportBtn = $('#export-logs');
                var $clearBtn = $('#clear-logs');
                
                if ($exportBtn.length > 0 && !$exportBtn.data('bunny-bound')) {
                    console.log('Adding fallback export handler');
                    $exportBtn.data('bunny-bound', true).on('click', function(e) {
                        console.log('Fallback export handler triggered');
                        e.preventDefault();
                        BunnyAdmin.exportLogs(e);
                    });
                }
                
                if ($clearBtn.length > 0 && !$clearBtn.data('bunny-bound')) {
                    console.log('Adding fallback clear handler');
                    $clearBtn.data('bunny-bound', true).on('click', function(e) {
                        console.log('Fallback clear handler triggered');
                        e.preventDefault();
                        BunnyAdmin.clearLogs(e);
                    });
                }
            }, 500);
        }
        
        // Make BunnyAdmin available globally for debugging
        window.BunnyAdmin = BunnyAdmin;
    });
    
})(jQuery); 