/**
 * Bunny Media Offload Admin JavaScript
 */
(function($) {
    'use strict';
    
    var BunnyAdmin = {
        
        migrationState: {
            active: false,
            sessionId: null,
            concurrentLimit: 4,
            totalFiles: 0,
            processedFiles: 0,
            successfulFiles: 0,
            failedFiles: 0,
            currentBatch: [],
            errors: []
        },
        
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
            this.initMigration();
            this.initLogs();
            this.initOptimization();
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
         * Initialize migration functionality
         */
        initMigration: function() {
            $(document).on('click', '#start-migration', function(e) {
                e.preventDefault();
                
                // Check if migration button is disabled
                if ($(this).prop('disabled')) {
                    return;
                }
                
                BunnyAdmin.startMigration();
            });
            
            $(document).on('click', '#cancel-migration', function(e) {
                e.preventDefault();
                BunnyAdmin.cancelMigration();
            });
            
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
         * Start migration with enhanced animation
         */
        startMigration: function() {
            var self = this;
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_start_migration',
                    nonce: bunnyAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.migrationState.active = true;
                        self.migrationState.sessionId = response.data.migration_id;
                        self.migrationState.totalFiles = response.data.total_files;
                        self.migrationState.concurrentLimit = response.data.concurrent_limit || 4;
                        
                        // Show migration progress interface
                        self.initMigrationInterface();
                        
                        // Start processing
                        self.processMigrationBatch(self.migrationState.sessionId);
                    } else {
                        alert('Failed to start migration: ' + response.data.message);
                    }
                },
                error: function() {
                    alert('Failed to start migration.');
                }
            });
        },
        
        /**
         * Initialize migration interface with animation
         */
        initMigrationInterface: function() {
            $('#migration-progress').show();
            $('#start-migration').hide();
            $('#cancel-migration').show();
            
            // Add basic stats without animation
            var statsHtml = '<div class="bunny-migration-stats">';
            statsHtml += '<div class="bunny-stat-item"><span class="label">Total Files:</span> <span id="total-files">' + this.migrationState.totalFiles + '</span></div>';
            statsHtml += '<div class="bunny-stat-item"><span class="label">Processed:</span> <span id="processed-files">0</span></div>';
            statsHtml += '<div class="bunny-stat-item"><span class="label">Successful:</span> <span id="successful-files">0</span></div>';
            statsHtml += '<div class="bunny-stat-item"><span class="label">Failed:</span> <span id="failed-files">0</span></div>';
            statsHtml += '<div class="bunny-stat-item"><span class="label">Speed:</span> <span id="processing-speed">0 files/min</span></div>';
            statsHtml += '</div>';
            
            $('#migration-progress').prepend(statsHtml);
            
            // Initialize speed tracking
            this.migrationState.startTime = Date.now();
        },
        
        /**
         * Process migration batch with animation
         */
        processMigrationBatch: function(migrationId) {
            var self = this;
            
            if (!this.migrationState.active) {
                return;
            }
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_migration_batch',
                    nonce: bunnyAjax.nonce,
                    migration_id: migrationId
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        
                        // Update migration state
                        self.migrationState.processedFiles = data.processed;
                        self.migrationState.successfulFiles = data.successful;
                        self.migrationState.failedFiles = data.failed;
                        
                        // Update progress bar
                        $('#migration-progress-bar').css('width', data.progress + '%');
                        
                        // Update stats
                        self.updateMigrationStats();
                        
                        // Update status text with actual numbers processed
                        // Use the original total from the backend, but if completed, show processed/processed
                        var displayTotal = data.completed ? data.processed : data.total;
                        $('#migration-status-text').text(
                            'Migration Progress Processed: ' + data.processed + '/' + displayTotal + 
                            ' (' + data.successful + ' successful, ' + data.failed + ' failed)'
                        );
                        

                        
                        // Handle errors
                        if (data.errors && data.errors.length > 0) {
                            self.displayMigrationErrors(data.errors);
                        }
                        
                        if (!data.completed && self.migrationState.active) {
                            // Continue processing with delay to show animation
                            setTimeout(function() {
                                self.processMigrationBatch(migrationId);
                            }, 1500);
                        } else {
                            // Complete migration
                            self.completeMigration(data.completed);
                        }
                    } else {
                        self.handleMigrationError(response.data.message);
                    }
                },
                error: function() {
                    self.handleMigrationError('Migration batch failed.');
                }
            });
        },
        

        
        /**
         * Update migration statistics in real-time
         */
        updateMigrationStats: function() {
            $('#processed-files').text(this.migrationState.processedFiles);
            $('#successful-files').text(this.migrationState.successfulFiles);
            $('#failed-files').text(this.migrationState.failedFiles);
            
            // Calculate and display processing speed
            var elapsed = (Date.now() - this.migrationState.startTime) / 1000 / 60; // minutes
            var speed = elapsed > 0 ? Math.round(this.migrationState.processedFiles / elapsed) : 0;
            $('#processing-speed').text(speed + ' files/min');
        },
        
        /**
         * Display migration errors
         */
        displayMigrationErrors: function(errors) {
            if (errors.length > 0) {
                $('#migration-errors').show();
                var errorList = $('#migration-error-list');
                
                errors.forEach(function(error) {
                    errorList.append('<li>' + error + '</li>');
                });
            }
        },
        
        /**
         * Handle migration error
         */
        handleMigrationError: function(message) {
            alert('Migration failed: ' + message);
            this.resetMigrationInterface();
        },
        
        /**
         * Complete migration
         */
        completeMigration: function(completed) {
            if (completed) {
                setTimeout(function() {
                    alert('Migration completed successfully!');
                }, 1000);
            }
            
            this.resetMigrationInterface();
        },
        
        /**
         * Cancel migration
         */
        cancelMigration: function() {
            if (!this.migrationState.active) {
                return;
            }
            
            var self = this;
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_cancel_migration',
                    nonce: bunnyAjax.nonce,
                    migration_id: this.migrationState.sessionId
                },
                success: function(response) {
                    if (response.success) {
                        alert('Migration cancelled.');
                    }
                    self.resetMigrationInterface();
                },
                error: function() {
                    alert('Failed to cancel migration.');
                    self.resetMigrationInterface();
                }
            });
        },
        
        /**
         * Reset migration interface
         */
        resetMigrationInterface: function() {
            this.migrationState.active = false;
            this.migrationState.sessionId = null;
            
            $('#start-migration').show();
            $('#cancel-migration').hide();
            
            // Remove stats
            $('.bunny-migration-stats').remove();
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
                        alert('Failed to clear logs: ' + (response.data.message || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Clear logs error:', xhr, status, error);
                    alert('Failed to clear logs due to an error.');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Clear Filtered Logs');
                }
            });
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
                        var blob = new Blob([response.data.csv_data], { type: 'text/csv' });
                        var url = window.URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url;
                        
                        var filename = 'bunny-logs';
                        if (logType !== 'all') {
                            filename += '-' + logType.toLowerCase();
                        }
                        if (logLevel) {
                            filename += '-' + logLevel.toLowerCase();
                        }
                        filename += '-' + new Date().toISOString().split('T')[0] + '.csv';
                        
                        a.download = filename;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        window.URL.revokeObjectURL(url);
                        
                        alert('Logs exported successfully!');
                    } else {
                        alert('Export failed: ' + (response.data.message || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Export logs error:', xhr, status, error);
                    alert('Failed to export logs due to an error.');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Export Filtered Logs');
                }
            });
        },
        
        /**
         * Run diagnostics for optimization issues
         */
        runOptimizationDiagnostics: function() {
            var self = this;
            
            // Show loading state
            var $button = $('.bunny-diagnostic-button');
            var originalText = $button.html();
            $button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt" style="animation: rotate 1s linear infinite;"></span> Running diagnostics...');
            
            // Clear any existing diagnostics results
            $('#diagnostics-results').remove();
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_run_optimization_diagnostics',
                    nonce: bunnyAjax.nonce
                },
                success: function(response) {
                    $button.prop('disabled', false).html(originalText);
                    
                    if (response.success) {
                        self.displayDiagnosticsResults(response.data);
                    } else {
                        alert('Diagnostics failed: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    $button.prop('disabled', false).html(originalText);
                    console.error('Diagnostics AJAX error:', error);
                    alert('Diagnostics failed due to network error.');
                }
            });
        },
        
        /**
         * Display diagnostics results
         */
        displayDiagnosticsResults: function(data) {
            var html = '<div id="diagnostics-results" class="notice notice-info" style="margin-top: 20px; padding: 15px;">';
            html += '<h3>🔍 Optimization Diagnostics Results</h3>';
            
            // Summary
            html += '<div style="margin-bottom: 15px;">';
            html += '<h4>Summary</h4>';
            html += '<ul>';
            html += '<li><strong>Total attachments found:</strong> ' + data.total_attachments + '</li>';
            html += '<li><strong>Valid for optimization:</strong> ' + data.valid_attachments + '</li>';
            html += '<li><strong>Problematic attachments:</strong> ' + data.problematic_attachments + '</li>';
            html += '</ul>';
            html += '</div>';
            
            // Issues found
            if (data.issues && data.issues.length > 0) {
                html += '<div style="margin-bottom: 15px;">';
                html += '<h4>Issues Found</h4>';
                html += '<ul>';
                data.issues.forEach(function(issue) {
                    html += '<li><strong>Attachment ID ' + issue.id + ':</strong> ' + issue.reason;
                    if (issue.title) {
                        html += ' (<em>' + issue.title + '</em>)';
                    }
                    html += '</li>';
                });
                html += '</ul>';
                html += '</div>';
            }
            
            // Recommendations
            if (data.recommendations && data.recommendations.length > 0) {
                html += '<div>';
                html += '<h4>Recommendations</h4>';
                html += '<ul>';
                data.recommendations.forEach(function(rec) {
                    html += '<li>' + rec + '</li>';
                });
                html += '</ul>';
                html += '</div>';
            }
            
            html += '</div>';
            
            // Insert after the optimization section
            $('.bunny-optimization-section').after(html);
        },
        
        /**
         * Show admin notice
         */
        showNotice: function(message, type) {
            type = type || 'info';
            
            var $notice = $('<div class="bunny-notice bunny-notice-' + type + '">' + message + '</div>');
            
            // Remove existing notices
            $('.bunny-notice').remove();
            
            // Add new notice
            $('.wrap h1').after($notice);
            
            // Auto-hide success notices
            if (type === 'success') {
                setTimeout(function() {
                    $notice.fadeOut();
                }, 5000);
            }
            
            // Scroll to top
            $('html, body').animate({ scrollTop: 0 }, 'fast');
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
        
        // Add a debug helper function
        window.debugOptimization = function(target) {
            target = target || 'local';
            console.log('Debug: Testing optimization for target:', target);
            BunnyAdmin.startStepOptimization(target);
        };
        
        // Add AJAX connectivity test
        window.testAjax = function() {
            console.log('Testing AJAX connectivity...');
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_test_connection',
                    nonce: bunnyAjax.nonce
                },
                success: function(response) {
                    console.log('AJAX test response:', response);
                },
                error: function(xhr, status, error) {
                    console.error('AJAX test failed:', xhr, status, error);
                }
            });
        };
        
        // Add optimization-specific AJAX test
        window.testOptimizationAjax = function() {
            console.log('Testing optimization AJAX...');
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_test_optimization',
                    nonce: bunnyAjax.nonce
                },
                success: function(response) {
                    console.log('Optimization AJAX test response:', response);
                    if (response.success) {
                        console.log('✅ Optimization AJAX is working!');
                        console.log('Settings enabled:', response.data.settings_enabled);
                    } else {
                        console.log('❌ Optimization AJAX failed:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Optimization AJAX test failed:', xhr, status, error);
                    console.log('Response text:', xhr.responseText);
                }
            });
        };
        
        // Add logs-specific test functions
        window.testExportLogs = function() {
            console.log('Testing export logs manually...');
            if (typeof bunnyAjax === 'undefined') {
                console.error('bunnyAjax not available!');
                return;
            }
            
            var logType = 'all';
            var logLevel = '';
            
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
                    console.log('Export logs test response:', response);
                    if (response.success) {
                        console.log('✅ Export logs AJAX is working!');
                        console.log('CSV data length:', response.data.csv_data.length);
                    } else {
                        console.log('❌ Export logs failed:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Export logs test failed:', xhr, status, error);
                    console.log('Response text:', xhr.responseText);
                }
            });
        };
        
        window.testClearLogs = function() {
            console.log('Testing clear logs manually (dry run)...');
            if (typeof bunnyAjax === 'undefined') {
                console.error('bunnyAjax not available!');
                return;
            }
            
            console.log('This would clear logs with type: all');
            console.log('To actually test, call: testClearLogsReal()');
        };
        
        window.testClearLogsReal = function() {
            if (!confirm('This will actually clear logs. Continue?')) {
                return;
            }
            
            console.log('Testing clear logs manually...');
            var logType = 'all';
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_clear_logs',
                    nonce: bunnyAjax.nonce,
                    log_type: logType
                },
                success: function(response) {
                    console.log('Clear logs test response:', response);
                    if (response.success) {
                        console.log('✅ Clear logs AJAX is working!');
                        console.log('Message:', response.data.message);
                    } else {
                        console.log('❌ Clear logs failed:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Clear logs test failed:', xhr, status, error);
                    console.log('Response text:', xhr.responseText);
                }
            });
        };
        
        // Add button click simulator
        window.simulateButtonClicks = function() {
            console.log('Simulating button clicks...');
            
            var $exportBtn = $('#export-logs');
            var $clearBtn = $('#clear-logs');
            
            if ($exportBtn.length > 0) {
                console.log('Simulating export button click...');
                $exportBtn.trigger('click');
            } else {
                console.log('Export button not found!');
            }
            
            setTimeout(function() {
                if ($clearBtn.length > 0) {
                    console.log('Simulating clear button click...');
                    $clearBtn.trigger('click');
                } else {
                    console.log('Clear button not found!');
                }
            }, 1000);
        };
    });
    
})(jQuery); 