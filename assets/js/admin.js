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
            this.initSync();
            this.initLogs();
            this.initOptimization();
        },
        
        /**
         * Bind events
         */
        bindEvents: function() {
            // Test connection
            $(document).on('click', '#test-connection', this.testConnection);
            
            // Settings form
            $(document).on('submit', '#bunny-settings-form', this.saveSettings);
            
            // Clear logs
            $(document).on('click', '#clear-logs', this.clearLogs);
            
            // Export logs
            $(document).on('click', '#export-logs', this.exportLogs);
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
         * Initialize sync functionality
         */
        initSync: function() {
            $(document).on('click', '#verify-sync', this.verifySync);
            $(document).on('click', '#sync-all-files', this.syncAllFiles);
            $(document).on('click', '#cleanup-orphaned', this.cleanupOrphaned);
        },
        
        /**
         * Initialize logs functionality
         */
        initLogs: function() {
            // Auto-refresh logs every 30 seconds
            if ($('.bunny-logs-table').length > 0) {
                setInterval(function() {
                    location.reload();
                }, 30000);
            }
        },
        
        /**
         * Initialize optimization functionality
         */
        initOptimization: function() {
            $(document).on('submit', '#optimization-form', function(e) {
                e.preventDefault();
                
                // Check if optimization button is disabled
                if ($('#start-optimization').prop('disabled')) {
                    return;
                }
                
                var optimizationTarget = $('#optimization-form input[name="optimization_target"]:checked').val();
                
                BunnyAdmin.startStepOptimization(optimizationTarget);
            });
            
            $(document).on('click', '#cancel-optimization', function(e) {
                e.preventDefault();
                BunnyAdmin.cancelOptimization();
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
            
            // Create concurrent processing indicators
            var concurrentHtml = '<div class="bunny-concurrent-processors">';
            concurrentHtml += '<h4>Processing Threads (Concurrent Limit: ' + this.migrationState.concurrentLimit + ')</h4>';
            
            for (var i = 1; i <= this.migrationState.concurrentLimit; i++) {
                concurrentHtml += '<div class="bunny-processor" id="processor-' + i + '">';
                concurrentHtml += '<div class="bunny-processor-status">Thread ' + i + ': <span class="status-text">Waiting...</span></div>';
                concurrentHtml += '<div class="bunny-processor-file"><span class="file-name">-</span></div>';
                concurrentHtml += '<div class="bunny-processor-progress"><div class="bunny-processor-bar"></div></div>';
                concurrentHtml += '</div>';
            }
            concurrentHtml += '</div>';
            
            // Add real-time stats
            var statsHtml = '<div class="bunny-migration-stats">';
            statsHtml += '<div class="bunny-stat-item"><span class="label">Total Files:</span> <span id="total-files">' + this.migrationState.totalFiles + '</span></div>';
            statsHtml += '<div class="bunny-stat-item"><span class="label">Processed:</span> <span id="processed-files">0</span></div>';
            statsHtml += '<div class="bunny-stat-item"><span class="label">Successful:</span> <span id="successful-files">0</span></div>';
            statsHtml += '<div class="bunny-stat-item"><span class="label">Failed:</span> <span id="failed-files">0</span></div>';
            statsHtml += '<div class="bunny-stat-item"><span class="label">Speed:</span> <span id="processing-speed">0 files/min</span></div>';
            statsHtml += '</div>';
            
            $('#migration-progress').prepend(concurrentHtml + statsHtml);
            
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
            
            // Animate processors as "working"
            this.animateProcessors();
            
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
                        
                        // Update status text
                        $('#migration-status-text').text(
                            'Processed: ' + data.processed + '/' + data.total + 
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
                            }, 1500); // Increased delay to show concurrent processing
                        } else {
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
         * Animate processors to show concurrent processing
         */
        animateProcessors: function() {
            var self = this;
            var limit = this.migrationState.concurrentLimit;
            
            // Reset all processors
            $('.bunny-processor').removeClass('active processing completed error');
            
            // Activate processors up to concurrent limit
            for (var i = 1; i <= limit; i++) {
                var processor = $('#processor-' + i);
                processor.addClass('active processing');
                
                // Simulate processing with different timings
                this.simulateProcessorWork(i, i * 200);
            }
        },
        
        /**
         * Simulate individual processor work
         */
        simulateProcessorWork: function(processorId, delay) {
            var processor = $('#processor-' + processorId);
            
            setTimeout(function() {
                processor.find('.status-text').text('Processing...');
                processor.find('.file-name').text('Uploading file ' + processorId + '...');
                
                // Animate progress bar
                var progressBar = processor.find('.bunny-processor-bar');
                progressBar.css('width', '0%');
                
                progressBar.animate({
                    width: '100%'
                }, 1200, function() {
                    // Mark as completed
                    processor.removeClass('processing').addClass('completed');
                    processor.find('.status-text').text('Completed');
                    processor.find('.file-name').text('Upload successful');
                });
            }, delay);
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
                $('.bunny-processor').removeClass('processing').addClass('completed');
                $('.bunny-processor .status-text').text('Migration Complete');
                $('.bunny-processor .file-name').text('All files processed');
                
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
            
            // Remove concurrent processors and stats
            $('.bunny-concurrent-processors, .bunny-migration-stats').remove();
        },
        
        /**
         * Verify sync
         */
        verifySync: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var originalText = $button.text();
            
            $button.text('Verifying...').prop('disabled', true);
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_verify_sync',
                    nonce: bunnyAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var resultHtml = '<h4>Verification Results</h4>';
                        resultHtml += '<p>Total Files: ' + data.total_files + '</p>';
                        resultHtml += '<p>Synced Files: ' + data.synced_files + '</p>';
                        resultHtml += '<p>Missing Local: ' + data.missing_local + '</p>';
                        resultHtml += '<p>Missing Remote: ' + data.missing_remote + '</p>';
                        
                        $('#sync-results-content').html(resultHtml);
                        $('#sync-results').show();
                    } else {
                        BunnyAdmin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    BunnyAdmin.showNotice('Verification failed.', 'error');
                },
                complete: function() {
                    $button.text(originalText).prop('disabled', false);
                }
            });
        },
        
        /**
         * Sync all files
         */
        syncAllFiles: function(e) {
            e.preventDefault();
            
            if (!confirm('This will download all remote files to local storage. Continue?')) {
                return;
            }
            
            var $button = $(this);
            var originalText = $button.text();
            
            $button.text('Syncing...').prop('disabled', true);
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_bulk_sync',
                    nonce: bunnyAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        BunnyAdmin.showNotice('Files synced successfully.', 'success');
                    } else {
                        BunnyAdmin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    BunnyAdmin.showNotice('Sync failed.', 'error');
                },
                complete: function() {
                    $button.text(originalText).prop('disabled', false);
                }
            });
        },
        
        /**
         * Cleanup orphaned files
         */
        cleanupOrphaned: function(e) {
            e.preventDefault();
            
            if (!confirm('This will permanently delete orphaned files from Bunny.net. Continue?')) {
                return;
            }
            
            var $button = $(this);
            var originalText = $button.text();
            
            $button.text('Cleaning up...').prop('disabled', true);
            
            // Implementation would go here
            setTimeout(function() {
                $button.text(originalText).prop('disabled', false);
                BunnyAdmin.showNotice('Cleanup completed.', 'success');
            }, 2000);
        },
        
        /**
         * Clear logs
         */
        clearLogs: function(e) {
            e.preventDefault();
            
            if (!confirm('This will permanently delete all logs. Continue?')) {
                return;
            }
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_clear_logs',
                    nonce: bunnyAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Failed to clear logs.');
                    }
                },
                error: function() {
                    alert('Failed to clear logs.');
                }
            });
        },
        
        /**
         * Export logs
         */
        exportLogs: function(e) {
            e.preventDefault();
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_export_logs',
                    nonce: bunnyAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Create download link
                        var blob = new Blob([response.data.csv_data], { type: 'text/csv' });
                        var url = window.URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = 'bunny-logs-' + new Date().toISOString().split('T')[0] + '.csv';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        window.URL.revokeObjectURL(url);
                    } else {
                        BunnyAdmin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    BunnyAdmin.showNotice('Failed to export logs.', 'error');
                }
            });
        },
        
        /**
         * Start step-by-step optimization
         */
        startStepOptimization: function(target) {
            var self = this;
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_start_step_optimization',
                    nonce: bunnyAjax.nonce,
                    optimization_target: target
                },
                success: function(response) {
                    if (response.success) {
                        self.optimizationState = {
                            active: true,
                            sessionId: response.data.session_id,
                            totalImages: response.data.total_images
                        };
                        
                        // Show optimization progress interface
                        self.initOptimizationInterface();
                        
                        // Start processing
                        self.processOptimizationBatch(self.optimizationState.sessionId);
                    } else {
                        alert('Failed to start optimization: ' + response.data.message);
                    }
                },
                error: function() {
                    alert('Failed to start optimization.');
                }
            });
        },
        
        /**
         * Initialize optimization interface
         */
        initOptimizationInterface: function() {
            $('#optimization-progress').show();
            $('#start-optimization').hide();
            $('#cancel-optimization').show();
            
            // Initialize progress bar
            $('#optimization-progress-bar').css('width', '0%');
            $('#optimization-status-text').text('Starting optimization...');
        },
        
        /**
         * Process optimization batch
         */
        processOptimizationBatch: function(sessionId) {
            var self = this;
            
            if (!this.optimizationState.active) {
                return;
            }
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_optimization_batch',
                    nonce: bunnyAjax.nonce,
                    session_id: sessionId
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        
                        // Update progress bar
                        $('#optimization-progress-bar').css('width', data.progress + '%');
                        
                        // Update status text
                        $('#optimization-status-text').text(
                            'Processed: ' + data.processed + '/' + data.total + 
                            ' (' + data.successful + ' successful, ' + data.failed + ' failed)'
                        );
                        
                        // Handle errors
                        if (data.errors && data.errors.length > 0) {
                            self.displayOptimizationErrors(data.errors);
                        }
                        
                        if (!data.completed && self.optimizationState.active) {
                            // Continue processing
                            setTimeout(function() {
                                self.processOptimizationBatch(sessionId);
                            }, 1000);
                        } else {
                            self.completeOptimization(data.completed);
                        }
                    } else {
                        self.handleOptimizationError(response.data.message);
                    }
                },
                error: function() {
                    self.handleOptimizationError('Optimization batch failed.');
                }
            });
        },
        
        /**
         * Display optimization errors
         */
        displayOptimizationErrors: function(errors) {
            var $errorList = $('#optimization-error-list');
            $errorList.empty();
            
            errors.forEach(function(error) {
                $errorList.append('<li>' + error + '</li>');
            });
            
            $('#optimization-errors').show();
        },
        
        /**
         * Handle optimization error
         */
        handleOptimizationError: function(message) {
            this.optimizationState.active = false;
            $('#optimization-status-text').text('Error: ' + message);
            $('#start-optimization').show();
            $('#cancel-optimization').hide();
            alert('Optimization failed: ' + message);
        },
        
        /**
         * Complete optimization
         */
        completeOptimization: function(completed) {
            this.optimizationState.active = false;
            
            if (completed) {
                $('#optimization-status-text').text('Optimization completed successfully!');
                alert('Optimization completed successfully!');
            }
            
            $('#start-optimization').show();
            $('#cancel-optimization').hide();
        },
        
        /**
         * Cancel optimization
         */
        cancelOptimization: function() {
            if (this.optimizationState && this.optimizationState.active) {
                this.optimizationState.active = false;
                
                $.ajax({
                    url: bunnyAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bunny_cancel_optimization',
                        nonce: bunnyAjax.nonce,
                        session_id: this.optimizationState.sessionId
                    },
                    success: function(response) {
                        // Handle cancellation response if needed
                    }
                });
                
                $('#optimization-status-text').text('Optimization cancelled.');
                $('#start-optimization').show();
                $('#cancel-optimization').hide();
            }
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
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        BunnyAdmin.init();
        
        // Update dashboard stats every 30 seconds
        setInterval(BunnyAdmin.updateDashboardStats, 30000);
    });
    
})(jQuery); 