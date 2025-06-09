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
            // Auto-refresh logs every 30 seconds
            if ($('.bunny-logs-table').length > 0) {
                setInterval(function() {
                    location.reload();
                }, 30000);
            }
            
            // Enhanced debugging for logs buttons
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
                BunnyAdmin.exportLogs(e);
            });
            
            $clearBtn.off('click.bunny').on('click.bunny', function(e) {
                console.log('=== CLEAR BUTTON CLICKED ===');
                console.log('Event object:', e);
                BunnyAdmin.clearLogs(e);
            });
            
            // Test click events after a short delay
            setTimeout(function() {
                console.log('Testing button click events...');
                console.log('Export button click events:', $._data($exportBtn[0], 'events'));
                console.log('Clear button click events:', $._data($clearBtn[0], 'events'));
            }, 1000);
            
            console.log('=== END LOGS INITIALIZATION ===');
        },
        
        /**
         * Initialize optimization functionality
         */
        initOptimization: function() {
            // Log initial state
            console.log('Initializing optimization functionality');
            console.log('Available optimization buttons:', $('.bunny-optimize-button').length);
            
            // Handle direct optimization button clicks
            $(document).on('click', '.bunny-optimize-button', function(e) {
                e.preventDefault();
                
                console.log('Optimization button clicked, checking state...');
                console.log('Button disabled:', $(this).prop('disabled'));
                console.log('Button element:', this);
                
                if ($(this).prop('disabled')) {
                    console.log('Button is disabled, returning early');
                    return;
                }
                
                var target = $(this).data('target');
                
                console.log('Direct optimization button clicked:', target);
                console.log('AJAX URL:', bunnyAjax.ajaxurl);
                console.log('AJAX Nonce:', bunnyAjax.nonce);
                
                if (target) {
                    BunnyAdmin.startStepOptimization(target);
                } else {
                    console.error('No target found on button');
                    alert('Invalid optimization target.');
                }
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
            
            // Start animation before processing
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
                        
                        // Update status text with actual numbers processed
                        // Use the original total from the backend, but if completed, show processed/processed
                        var displayTotal = data.completed ? data.processed : data.total;
                        $('#migration-status-text').text(
                            'Migration Progress Processed: ' + data.processed + '/' + displayTotal + 
                            ' (' + data.successful + ' successful, ' + data.failed + ' failed)'
                        );
                        
                        // Update processors with real file information
                        if (data.current_files && data.current_files.length > 0) {
                            self.updateProcessorsWithRealFiles(data.current_files);
                        }
                        
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
                            // Stop animation and complete migration
                            self.stopProcessorAnimation();
                            self.completeMigration(data.completed);
                        }
                    } else {
                        self.stopProcessorAnimation();
                        self.handleMigrationError(response.data.message);
                    }
                },
                error: function() {
                    self.stopProcessorAnimation();
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
            var self = this;
            
            processor.data('animation-timeout', setTimeout(function() {
                if (!self.migrationState.active) return; // Don't start if migration stopped
                
                processor.find('.status-text').text('Processing...');
                processor.find('.file-name').text('Uploading file ' + processorId + '...');
                
                // Animate progress bar
                var progressBar = processor.find('.bunny-processor-bar');
                progressBar.css('width', '0%');
                
                var animationInterval = progressBar.animate({
                    width: '100%'
                }, 1200, function() {
                    if (!self.migrationState.active) return; // Don't complete if migration stopped
                    
                    // Mark as completed
                    processor.removeClass('processing').addClass('completed');
                    processor.find('.status-text').text('Completed');
                    processor.find('.file-name').text('Upload successful');
                });
                
                processor.data('animation-interval', animationInterval);
            }, delay));
        },

        /**
         * Stop processor animation
         */
        stopProcessorAnimation: function() {
            var self = this;
            
            // Clear all timeouts and stop animations
            $('.bunny-processor').each(function() {
                var processor = $(this);
                var timeout = processor.data('animation-timeout');
                var interval = processor.data('animation-interval');
                
                if (timeout) {
                    clearTimeout(timeout);
                    processor.removeData('animation-timeout');
                }
                
                if (interval) {
                    processor.find('.bunny-processor-bar').stop(true, false);
                    processor.removeData('animation-interval');
                }
            });
        },

        /**
         * Update processors with real file information
         */
        updateProcessorsWithRealFiles: function(currentFiles) {
            for (var i = 0; i < currentFiles.length && i < this.migrationState.concurrentLimit; i++) {
                var file = currentFiles[i];
                var processor = $('#processor-' + (i + 1));
                
                if (processor.length > 0) {
                    processor.find('.file-name').text('Uploading: ' + (file.post_title || file.file_path || 'Unknown file'));
                }
            }
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
            // Stop all animations first
            this.stopProcessorAnimation();
            
            if (completed) {
                $('.bunny-processor').removeClass('processing').addClass('completed');
                $('.bunny-processor .status-text').text('Migration Complete');
                $('.bunny-processor .file-name').text('All files processed');
                
                // Ensure progress bars are at 100%
                $('.bunny-processor .bunny-processor-bar').stop(true, true).css('width', '100%');
                
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
            
            // Stop animations immediately
            self.stopProcessorAnimation();
            
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
            // Stop all animations first
            this.stopProcessorAnimation();
            
            this.migrationState.active = false;
            this.migrationState.sessionId = null;
            
            $('#start-migration').show();
            $('#cancel-migration').hide();
            
            // Remove concurrent processors and stats
            $('.bunny-concurrent-processors, .bunny-migration-stats').remove();
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
         * Start step-by-step optimization
         */
        startStepOptimization: function(target) {
            var self = this;
            
            console.log('Starting step optimization for target:', target);
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_start_step_optimization',
                    nonce: bunnyAjax.nonce,
                    optimization_target: target
                },
                success: function(response) {
                    console.log('=== OPTIMIZATION RESPONSE DEBUG ===');
                    console.log('Full response:', response);
                    console.log('Response type:', typeof response);
                    console.log('Response success property:', response.success);
                    console.log('Response data:', response.data);
                    console.log('Response data type:', typeof response.data);
                    
                    // Try to stringify the response for better visibility
                    try {
                        console.log('Response JSON:', JSON.stringify(response, null, 2));
                    } catch (e) {
                        console.log('Could not stringify response:', e);
                    }
                    console.log('=== END DEBUG ===');
                    
                    if (response.success) {
                        if (!response.data.session_id) {
                            console.error('No session ID in response:', response.data);
                            alert('Failed to start optimization: No session ID received.');
                            return;
                        }
                        
                        self.optimizationState = {
                            active: true,
                            sessionId: response.data.session_id,
                            totalImages: response.data.total_images,
                            target: target
                        };
                        
                        console.log('Optimization state set:', self.optimizationState);
                        
                        // Show optimization progress interface
                        self.initOptimizationInterface();
                        
                        // Initialize speed tracking
                        self.initOptimizationSpeedTracking();
                        
                        // Start processing
                        self.processOptimizationBatch(self.optimizationState.sessionId);
                    } else {
                        console.error('Optimization failed:', response);
                        var message = 'Unknown error occurred';
                        
                        if (response.data) {
                            if (typeof response.data === 'string') {
                                message = response.data;
                            } else if (response.data.message) {
                                message = response.data.message;
                            } else {
                                message = 'Server returned error without message';
                            }
                        } else {
                            message = 'Server returned error without data';
                        }
                        
                        alert('Failed to start optimization: ' + message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error details:', {
                        xhr: xhr,
                        status: status,
                        error: error,
                        responseText: xhr.responseText
                    });
                    alert('Failed to start optimization: AJAX error');
                }
            });
        },
        
        /**
         * Initialize optimization interface
         */
        initOptimizationInterface: function() {
            $('#optimization-progress').show();
            $('.bunny-optimization-actions').hide();
            $('.bunny-cancel-section').show();
            
            // Initialize progress bar
            $('#optimization-progress-bar').css('width', '0%');
            $('#optimization-progress-text').text('0%');
            $('#optimization-status-text').text('Starting optimization...');
            
            // Show current image processing section
            $('#current-image-processing').show();
            $('#recent-processed').show();
            $('#optimization-log').show();
            
            // Clear and initialize log
            this.clearOptimizationLog();
            this.addOptimizationLog('Optimization session started', 'info');
            
            // Clear any previous processing data
            $('#processed-images-list').empty();
            $('#current-image-thumb').attr('src', '').hide();
            $('#current-image-name').text('Preparing...');
            
            // Initialize process steps for target type
            var target = this.optimizationState.target;
            if (target === 'cloud') {
                $('#local-process').hide();
                $('#cloud-download').show();
                $('#cloud-process').show();
                $('#cloud-upload').show();
                
                // Reset cloud process steps
                $('#cloud-download, #cloud-process, #cloud-upload').removeClass('active completed error');
            } else {
                $('#local-process').show();
                $('#cloud-download').hide();
                $('#cloud-process').hide();
                $('#cloud-upload').hide();
                
                // Reset local process steps
                $('#local-process').removeClass('active completed error');
            }
        },
        
        /**
         * Process optimization batch
         */
        processOptimizationBatch: function(sessionId) {
            var self = this;
            
            if (!this.optimizationState.active) {
                return;
            }
            
            // Log batch processing start
            this.addOptimizationLog('Processing batch of images...', 'processing');
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_optimization_batch',
                    nonce: bunnyAjax.nonce,
                    session_id: sessionId
                },
                success: function(response) {
                    console.log('Batch processing response:', response);
                    
                    if (response.success) {
                        var data = response.data;
                        
                        // Log batch completion with enhanced statistics
                        var batchInfo = {
                            current: Math.ceil(data.processed / 20),
                            total: Math.ceil(data.total / 20),
                            images: data.recent_processed ? data.recent_processed.length : 0,
                            successful: data.successful,
                            failed: data.failed
                        };
                        
                        var statusMessage = `Batch ${batchInfo.current}/${batchInfo.total} complete: ${batchInfo.images} images processed`;
                        if (batchInfo.successful > 0 || batchInfo.failed > 0) {
                            statusMessage += ` (${batchInfo.successful} optimized, ${batchInfo.failed} failed)`;
                        }
                        self.addOptimizationLog(statusMessage, 'success', batchInfo);
                        
                        // Update progress bar
                        $('#optimization-progress-bar').css('width', data.progress + '%');
                        $('#optimization-progress-text').text(Math.round(data.progress) + '%');
                        
                        // Update status text
                        $('#optimization-status-text').text(
                            'Processed: ' + data.processed + '/' + data.total + 
                            ' (' + data.successful + ' successful, ' + data.failed + ' failed)'
                        );
                        
                        // Update speed statistics
                        self.updateOptimizationSpeed(data.processed, data.total);
                        
                        // Update current image being processed
                        if (data.current_image) {
                            self.updateCurrentImage(data.current_image);
                        }
                        
                        // Update current step information
                        if (data.current_step) {
                            self.updateCurrentStep(data.current_step);
                        }
                        
                        // Add recently processed images
                        if (data.recent_processed && data.recent_processed.length > 0) {
                            self.addRecentlyProcessed(data.recent_processed);
                        }
                        
                        // Handle errors
                        if (data.errors && data.errors.length > 0) {
                            self.displayOptimizationErrors(data.errors);
                            self.addOptimizationLog('Encountered ' + data.errors.length + ' errors in current batch', 'error');
                        }
                        
                        if (!data.completed && self.optimizationState.active) {
                            // Log waiting period
                            self.addOptimizationLog('Waiting 3 seconds before next batch...', 'waiting');
                            
                            // Continue processing after 3 seconds
                            setTimeout(function() {
                                self.processOptimizationBatch(sessionId);
                            }, 3000);
                        } else {
                            self.completeOptimization(data.completed);
                        }
                    } else {
                        console.error('Batch processing failed:', response);
                        var message = response.data && response.data.message ? response.data.message : 'Unknown batch error';
                        self.addOptimizationLog('Batch processing failed: ' + message, 'error');
                        self.handleOptimizationError(message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Batch processing AJAX error:', {
                        xhr: xhr,
                        status: status,
                        error: error,
                        responseText: xhr.responseText
                    });
                    self.addOptimizationLog('Network error during batch processing: ' + error, 'error');
                    self.handleOptimizationError('Optimization batch failed due to network error.');
                }
            });
        },
        
        /**
         * Update current image being processed
         */
        updateCurrentImage: function(imageData) {
            if (imageData && imageData.thumbnail && imageData.name) {
                $('#current-image-thumb').attr('src', imageData.thumbnail).show();
                $('#current-image-name').text(imageData.name);
                
                // Update process steps based on current status
                var target = this.optimizationState.target;
                
                if (target === 'cloud') {
                    this.updateCloudProcessSteps(imageData.status);
                } else {
                    this.updateLocalProcessSteps(imageData.status);
                }
            } else {
                // Hide current image section if no data
                $('#current-image-thumb').hide();
                $('#current-image-name').text('Processing...');
            }
        },
        
        /**
         * Update local process steps
         */
        updateLocalProcessSteps: function(status) {
            // Reset all steps
            $('#local-process').removeClass('active completed error');
            
            switch(status) {
                case 'processing':
                    $('#local-process').addClass('active');
                    $('#local-process-text').text('Processing...');
                    break;
                case 'converting':
                    $('#local-process').addClass('active');
                    $('#local-process-text').text('Converting to AVIF...');
                    break;
                case 'compressing':
                    $('#local-process').addClass('active');
                    $('#local-process-text').text('Compressing...');
                    break;
                case 'completed':
                    $('#local-process').addClass('completed');
                    $('#local-process-text').text('Completed');
                    break;
                case 'error':
                    $('#local-process').addClass('error');
                    $('#local-process-text').text('Error');
                    break;
            }
        },
        
        /**
         * Update current step information
         */
        updateCurrentStep: function(stepData) {
            if (!stepData) return;
            
            var target = this.optimizationState.target;
            
            if (target === 'cloud') {
                this.updateCloudProcessSteps(stepData.step);
            } else {
                this.updateLocalProcessSteps(stepData.step);
            }
            
            // Update step message if available
            if (stepData.message) {
                if (target === 'cloud') {
                    switch(stepData.step) {
                        case 'downloading':
                            $('#download-process-text').text(stepData.message);
                            break;
                        case 'converting':
                        case 'processing':
                            $('#cloud-process-text').text(stepData.message);
                            break;
                        case 'uploading':
                            $('#upload-process-text').text(stepData.message);
                            break;
                    }
                } else {
                    $('#local-process-text').text(stepData.message);
                }
            }
        },
        
        /**
         * Update cloud process steps
         */
        updateCloudProcessSteps: function(status) {
            // Reset all steps
            $('#cloud-download, #cloud-process, #cloud-upload').removeClass('active completed error');
            
            switch(status) {
                case 'downloading':
                    $('#cloud-download').addClass('active');
                    $('#download-process-text').text('Downloading...');
                    break;
                case 'processing':
                case 'converting':
                    $('#cloud-download').addClass('completed');
                    $('#cloud-process').addClass('active');
                    $('#download-process-text').text('Downloaded');
                    if (status === 'converting') {
                        $('#cloud-process-text').text('Converting to AVIF...');
                    } else {
                        $('#cloud-process-text').text('Processing...');
                    }
                    break;
                case 'compressing':
                    $('#cloud-download').addClass('completed');
                    $('#cloud-process').addClass('active');
                    $('#download-process-text').text('Downloaded');
                    $('#cloud-process-text').text('Compressing...');
                    break;
                case 'uploading':
                    $('#cloud-download').addClass('completed');
                    $('#cloud-process').addClass('completed');
                    $('#cloud-upload').addClass('active');
                    $('#download-process-text').text('Downloaded');
                    $('#cloud-process-text').text('Optimized');
                    $('#upload-process-text').text('Uploading...');
                    break;
                case 'completed':
                    $('#cloud-download').addClass('completed');
                    $('#cloud-process').addClass('completed');
                    $('#cloud-upload').addClass('completed');
                    $('#download-process-text').text('Downloaded');
                    $('#cloud-process-text').text('Optimized');
                    $('#upload-process-text').text('Uploaded');
                    break;
                case 'error':
                    $('#cloud-download, #cloud-process, #cloud-upload').addClass('error');
                    $('#download-process-text').text('Error');
                    $('#cloud-process-text').text('Error');
                    $('#upload-process-text').text('Error');
                    break;
            }
        },
        
        /**
         * Add recently processed images
         */
        addRecentlyProcessed: function(recentImages) {
            var $list = $('#processed-images-list');
            
            recentImages.forEach(function(image) {
                var resultClass = image.success ? 'success' : 'error';
                var resultText = image.success ? 'Optimized successfully' : 'Optimization failed';
                var actionText = image.action || 'Converted to AVIF';
                
                // Add size reduction info if available
                if (image.success && image.size_reduction && image.size_reduction > 0) {
                    resultText += ' (-' + image.size_reduction + '%)';
                }
                
                var $item = $('<div class="bunny-processed-item ' + resultClass + '">' +
                    '<div class="bunny-processed-thumb">' +
                        '<img src="' + image.thumbnail + '" alt="' + image.name + '" onerror="this.style.display=\'none\'" />' +
                    '</div>' +
                    '<div class="bunny-processed-info">' +
                        '<div class="bunny-processed-name">' + image.name + '</div>' +
                        '<div class="bunny-processed-action">' + actionText + '</div>' +
                    '</div>' +
                    '<div class="bunny-processed-result ' + resultClass + '">' + resultText + '</div>' +
                '</div>');
                
                // Add to top of list
                $list.prepend($item);
                
                // Keep only last 5 items
                $list.children().slice(5).remove();
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
            this.addOptimizationLog('Optimization session failed: ' + message, 'error');
            $('.bunny-optimization-actions').show();
            $('.bunny-cancel-section').hide();
            $('#optimization-progress').hide();
            $('#current-image-processing').hide();
            $('#recent-processed').hide();
            alert('Optimization failed: ' + message);
        },
        
        /**
         * Complete optimization
         */
        completeOptimization: function(completed) {
            this.optimizationState.active = false;
            
            if (completed) {
                $('#optimization-status-text').text('Optimization completed successfully!');
                this.addOptimizationLog('Optimization session completed successfully!', 'success');
                alert('Optimization completed successfully!');
            } else {
                this.addOptimizationLog('Optimization session ended', 'info');
            }
            
            $('.bunny-optimization-actions').show();
            $('.bunny-cancel-section').hide();
            $('#optimization-progress').hide();
            $('#current-image-processing').hide();
            $('#recent-processed').hide();
            
            // Refresh the page to update statistics
            setTimeout(function() {
                location.reload();
            }, 2000);
        },
        
        /**
         * Cancel optimization
         */
        cancelOptimization: function() {
            if (this.optimizationState && this.optimizationState.active) {
                this.optimizationState.active = false;
                
                this.addOptimizationLog('Optimization cancelled by user', 'warning');
                
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
                $('.bunny-optimization-actions').show();
                $('.bunny-cancel-section').hide();
                $('#optimization-progress').hide();
                $('#current-image-processing').hide();
                $('#recent-processed').hide();
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
        },
        
        /**
         * Initialize optimization speed tracking
         */
        initOptimizationSpeedTracking: function() {
            this.optimizationState.startTime = Date.now();
            this.optimizationState.lastUpdate = Date.now();
            this.optimizationState.lastProcessed = 0;
            
            // Add speed display if not exists
            if ($('#optimization-speed').length === 0) {
                var speedHtml = '<div class="bunny-optimization-stats">';
                speedHtml += '<div class="bunny-stat-item"><span class="label">Speed:</span> <span id="optimization-speed">0 files/min</span></div>';
                speedHtml += '<div class="bunny-stat-item"><span class="label">Time Elapsed:</span> <span id="optimization-time">00:00</span></div>';
                speedHtml += '</div>';
                
                $('#optimization-progress').append(speedHtml);
            }
        },
        
        /**
         * Update optimization speed statistics
         */
        updateOptimizationSpeed: function(processed, total) {
            if (!this.optimizationState.startTime) return;
            
            var now = Date.now();
            var elapsed = (now - this.optimizationState.startTime) / 1000; // in seconds
            var elapsedMinutes = elapsed / 60;
            
            // Calculate overall speed
            var overallSpeed = elapsedMinutes > 0 ? Math.round(processed / elapsedMinutes) : 0;
            
            // Calculate recent speed (last 30 seconds)
            var timeSinceLastUpdate = (now - this.optimizationState.lastUpdate) / 1000;
            var recentProcessed = processed - this.optimizationState.lastProcessed;
            var recentSpeed = 0;
            
            if (timeSinceLastUpdate >= 30) { // Update recent speed every 30 seconds
                recentSpeed = timeSinceLastUpdate > 0 ? Math.round((recentProcessed / timeSinceLastUpdate) * 60) : 0;
                this.optimizationState.lastUpdate = now;
                this.optimizationState.lastProcessed = processed;
            }
            
            // Display speed (use recent speed if available, otherwise overall speed)
            var displaySpeed = recentSpeed > 0 ? recentSpeed : overallSpeed;
            $('#optimization-speed').text(displaySpeed + ' files/min');
            
            // Format and display elapsed time
            var minutes = Math.floor(elapsed / 60);
            var seconds = Math.floor(elapsed % 60);
            var timeStr = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
            $('#optimization-time').text(timeStr);
        },
        
        /**
         * Add log entry to optimization log
         */
        addOptimizationLog: function(message, type, batchInfo) {
            type = type || 'info';
            var now = new Date();
            var timeStr = now.toLocaleTimeString('en-US', { hour12: false });
            
            // Map types to emoji icons
            var icons = {
                'info': 'ℹ️',
                'success': '✅', 
                'warning': '⚠️',
                'error': '❌',
                'waiting': '⏳',
                'processing': '🔄'
            };
            
            var icon = icons[type] || 'ℹ️';
            
            // Add batch info to message if provided
            if (batchInfo) {
                message += ' (Batch ' + batchInfo.current + '/' + batchInfo.total + ', ' + batchInfo.images + ' images)';
            }
            
            var logHtml = '<div class="bunny-log-entry bunny-log-' + type + '">' +
                '<span class="bunny-log-icon">' + icon + '</span>' +
                '<span class="bunny-log-message">' + message + '</span>' +
                '<span class="bunny-log-time">' + timeStr + '</span>' +
                '</div>';
            
            var $container = $('#optimization-log-container');
            
            // Remove initial placeholder log
            $container.find('.bunny-log-entry').first().remove();
            
            // Add new log entry at the top
            $container.prepend(logHtml);
            
            // Keep only last 10 entries
            $container.children().slice(10).remove();
            
            // Auto-scroll to keep latest visible
            $container.scrollTop(0);
        },
        
        /**
         * Clear optimization log
         */
        clearOptimizationLog: function() {
            var $container = $('#optimization-log-container');
            $container.empty();
            
            // Add initial placeholder
            var now = new Date();
            var timeStr = now.toLocaleTimeString('en-US', { hour12: false });
            $container.append(
                '<div class="bunny-log-entry bunny-log-info">' +
                '<span class="bunny-log-icon">ℹ️</span>' +
                '<span class="bunny-log-message">Optimization logs will appear here...</span>' +
                '<span class="bunny-log-time">' + timeStr + '</span>' +
                '</div>'
            );
        },

        /**
         * Show optimization criteria message
         */
        showOptimizationCriteria: function() {
            var criteriaHtml = '<div class="bunny-optimization-criteria notice notice-info inline">';
            criteriaHtml += '<p><strong>Optimization Criteria:</strong> Only images in supported formats (JPEG, PNG, GIF, WebP, AVIF) ';
            criteriaHtml += 'with file size <strong>exceeding 35KB</strong> will be optimized. ';
            criteriaHtml += 'Images below this threshold or in unsupported formats will be ignored.</p>';
            criteriaHtml += '</div>';
            
            // Remove existing criteria message
            $('.bunny-optimization-criteria').remove();
            
            // Add criteria message before optimization actions
            $('.bunny-optimization-actions').before(criteriaHtml);
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        BunnyAdmin.init();
        
        // Show optimization criteria on optimization page
        if ($('.bunny-optimization-actions').length > 0) {
            BunnyAdmin.showOptimizationCriteria();
        }
        
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