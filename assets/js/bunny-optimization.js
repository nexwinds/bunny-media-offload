/**
 * Bunny Media Optimizer - JavaScript Module
 * 
 * Handles the client-side functionality for image optimization using the BMO API.
 */

// Create a self-executing module to avoid polluting the global namespace
(function($) {
    'use strict';
    
    // Main optimization class
    var BunnyOptimization = {
        // Configuration
        config: {
            batchSize: 5,           // Default batch size (fixed at 5 for BMO API)
            maxQueue: 100,          // Maximum internal queue size
            processingDelay: 1000,  // Delay between batches (ms)
            retryAttempts: 3        // Number of retry attempts for failed batches
        },
        
        // State tracking
        state: {
            isProcessing: false,
            isCancelled: false,
            totalAdded: 0,
            totalProcessed: 0,
            totalCompleted: 0,
            totalFailed: 0,
            totalSkipped: 0,
            retryCount: 0,
            queueRemaining: 0,
            startTime: null
        },
        
        // UI elements (initialized when needed)
        ui: {
            startButton: null,
            cancelButton: null,
            progressBar: null,
            progressFill: null,
            progressText: null,
            progressContainer: null,
            batchStatus: null,
            processingCount: null,
            completedCount: null,
            failedCount: null,
            logContainer: null,
            logDisplay: null,
            diagnosticsResults: null,
            eligibleCount: null
        },
        
        /**
         * Initialize the optimization module
         */
        init: function() {
            var self = this;
            
            // Initialize configuration from global if available
            if (window.bunnyAjax && window.bunnyAjax.bmo_config) {
                $.extend(this.config, window.bunnyAjax.bmo_config);
            }
            
            // Cache UI elements
            this.ui.startButton = $('#start-optimization');
            this.ui.cancelButton = $('#cancel-optimization');
            this.ui.progressContainer = $('#optimization-progress');
            this.ui.progressBar = $('.bunny-progress-bar', this.ui.progressContainer);
            this.ui.progressFill = $('.bunny-progress-fill', this.ui.progressBar);
            this.ui.progressText = $('.bunny-progress-text', this.ui.progressBar);
            this.ui.batchStatus = $('.bunny-batch-status', this.ui.progressContainer);
            this.ui.processingCount = $('#processing-count');
            this.ui.completedCount = $('#completed-count');
            this.ui.failedCount = $('#failed-count');
            this.ui.logContainer = $('#optimization-log');
            this.ui.logDisplay = $('#optimization-log-container');
            this.ui.diagnosticsResults = $('#diagnostics-results');
            this.ui.eligibleCount = $('#eligible-count');
            
            // Bind events
            this.ui.startButton.on('click', function(e) {
                e.preventDefault();
                self.startOptimization();
            });
            
            this.ui.cancelButton.on('click', function(e) {
                e.preventDefault();
                self.cancelOptimization();
            });
            
            // Log initialization
            console.log('Bunny Optimization Module initialized with config:', this.config);
            
            // Make available globally for debugging and API access
            window.bunnyOptimizationInstance = this;
            
            return this;
        },
        
        /**
         * Start the optimization process
         */
        startOptimization: function() {
            var self = this;
            
            // If already processing, do nothing
            if (this.state.isProcessing) {
                return;
            }
            
            // Reset state
            this.resetState();
            
            // Update UI
            this.updateUI({
                startButton: false,
                cancelButton: true,
                progressContainer: true,
                logContainer: true
            });
            
            // Add log entry
            this.addLogEntry('info', 'Starting optimization process...');
            
            // Start time tracking
            this.state.startTime = new Date();
            
            // Add images to optimization queue
            this.fetchImages()
                .then(function(data) {
                    self.state.totalAdded = data.added || 0;
                    self.state.queueRemaining = data.queue_size || 0;
                    
                    if (self.state.totalAdded > 0) {
                        self.addLogEntry('success', 'Added ' + self.state.totalAdded + ' images to optimization queue');
                        
                        // Start processing the queue
                        self.processNextBatch();
                    } else {
                        self.addLogEntry('warning', 'No eligible images found for optimization');
                        self.completeOptimization();
                    }
                })
                .catch(function(error) {
                    self.handleError('Failed to start optimization: ' + error);
                });
        },
        
        /**
         * Process the next batch of images
         */
        processNextBatch: function() {
            var self = this;
            
            // If cancelled or no more items in queue, complete
            if (this.state.isCancelled || this.state.queueRemaining <= 0) {
                this.completeOptimization();
                return;
            }
            
            // Update UI
            this.updateProgressBar();
            this.ui.batchStatus.text('Processing batch... (' + this.state.queueRemaining + ' images remaining)');
            
            // Add log entry
            this.addLogEntry('info', 'Processing batch of ' + this.config.batchSize + ' images...');
            
            // Process batch via AJAX
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_optimization_batch',
                    nonce: bunnyAjax.nonce,
                    batch_size: this.config.batchSize
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Reset retry count on success
                        self.state.retryCount = 0;
                        
                        // Update state
                        self.state.totalProcessed += response.data.processed || 0;
                        self.state.queueRemaining = response.data.remaining || 0;
                        
                        // Process results
                        if (response.data.results && response.data.results.length > 0) {
                            self.processResults(response.data.results);
                        }
                        
                        // Update UI
                        self.updateCounts();
                        self.updateProgressBar();
                        
                        // Add log entry
                        self.addLogEntry('success', 'Batch processed: ' + response.data.processed + ' images');
                        
                        // Process next batch after delay
                        setTimeout(function() {
                            self.processNextBatch();
                        }, self.config.processingDelay);
                    } else {
                        // Retry logic
                        if (self.state.retryCount < self.config.retryAttempts) {
                            self.state.retryCount++;
                            self.addLogEntry('warning', 'Batch failed, retrying... (Attempt ' + self.state.retryCount + '/' + self.config.retryAttempts + ')');
                            
                            // Retry after delay
                            setTimeout(function() {
                                self.processNextBatch();
                            }, self.config.processingDelay * 2);
                        } else {
                            self.addLogEntry('error', 'Batch processing failed: ' + (response.data.message || 'Unknown error'));
                            self.completeOptimization(true);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    // Retry logic for network errors
                    if (self.state.retryCount < self.config.retryAttempts) {
                        self.state.retryCount++;
                        self.addLogEntry('warning', 'Network error, retrying... (Attempt ' + self.state.retryCount + '/' + self.config.retryAttempts + ')');
                        
                        // Retry after delay
                        setTimeout(function() {
                            self.processNextBatch();
                        }, self.config.processingDelay * 2);
                    } else {
                        self.addLogEntry('error', 'AJAX error: ' + error);
                        self.completeOptimization(true);
                    }
                }
            });
        },
        
        /**
         * Process batch results
         */
        processResults: function(results) {
            var self = this;
            
            // Process each result
            results.forEach(function(result) {
                if (result.status === 'optimized') {
                    self.state.totalCompleted++;
                    self.addLogEntry('success', 'Optimized image #' + result.attachment_id + ' - Reduced by ' + result.compression_ratio + '%');
                } else if (result.status === 'skipped') {
                    self.state.totalSkipped++;
                    self.addLogEntry('info', 'Skipped image #' + result.attachment_id + ' - ' + result.message);
                } else if (result.status === 'failed') {
                    self.state.totalFailed++;
                    self.addLogEntry('error', 'Failed to optimize image #' + result.attachment_id + ' - ' + result.message);
                }
            });
            
            // Update UI counts
            this.updateCounts();
        },
        
        /**
         * Cancel the optimization process
         */
        cancelOptimization: function() {
            var self = this;
            
            // If not processing, do nothing
            if (!this.state.isProcessing) {
                return;
            }
            
            // Set cancelled flag
            this.state.isCancelled = true;
            
            // Add log entry
            this.addLogEntry('warning', 'Cancelling optimization...');
            
            // Update UI
            this.ui.batchStatus.text('Cancelling...');
            this.ui.cancelButton.prop('disabled', true);
            
            // Send cancel request to server
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_cancel_optimization',
                    nonce: bunnyAjax.nonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        self.addLogEntry('info', 'Optimization cancelled');
                    } else {
                        self.addLogEntry('error', 'Failed to cancel optimization: ' + (response.data || 'Unknown error'));
                    }
                    
                    self.completeOptimization();
                },
                error: function(xhr, status, error) {
                    self.addLogEntry('error', 'AJAX error while cancelling: ' + error);
                    self.completeOptimization();
                },
                complete: function() {
                    self.ui.cancelButton.prop('disabled', false);
                }
            });
        },
        
        /**
         * Complete the optimization process
         */
        completeOptimization: function(hasError) {
            // Calculate elapsed time
            var elapsedTime = '0s';
            if (this.state.startTime) {
                var seconds = Math.floor((new Date() - this.state.startTime) / 1000);
                var minutes = Math.floor(seconds / 60);
                seconds = seconds % 60;
                elapsedTime = (minutes > 0 ? minutes + 'm ' : '') + seconds + 's';
            }
            
            // Add summary log entry
            if (hasError) {
                this.addLogEntry('error', 'Optimization completed with errors. Processed: ' + this.state.totalProcessed + ' images in ' + elapsedTime);
            } else if (this.state.isCancelled) {
                this.addLogEntry('warning', 'Optimization cancelled. Processed: ' + this.state.totalProcessed + ' images in ' + elapsedTime);
            } else {
                this.addLogEntry('success', 'Optimization completed! Processed: ' + this.state.totalProcessed + ' images in ' + elapsedTime);
            }
            
            // Update UI
            this.updateUI({
                startButton: true,
                cancelButton: false
            });
            
            // Final progress update
            this.updateProgressBar(100);
            this.ui.batchStatus.text(hasError ? 'Completed with errors' : (this.state.isCancelled ? 'Cancelled' : 'Completed'));
            
            // Reset processing state
            this.state.isProcessing = false;
            
            // Refresh stats after a delay
            setTimeout(this.refreshOptimizationStats.bind(this), 1500);
        },
        
        /**
         * Reset the state for a new optimization session
         */
        resetState: function() {
            this.state = {
                isProcessing: true,
                isCancelled: false,
                totalAdded: 0,
                totalProcessed: 0,
                totalCompleted: 0,
                totalFailed: 0,
                totalSkipped: 0,
                retryCount: 0,
                queueRemaining: 0,
                startTime: null
            };
            
            // Clear log
            this.ui.logDisplay.empty();
            
            // Reset UI counts
            this.updateCounts();
            
            // Reset progress bar
            this.updateProgressBar(0);
        },
        
        /**
         * Update the UI elements visibility
         */
        updateUI: function(options) {
            if (options.startButton !== undefined) {
                this.ui.startButton.prop('disabled', !options.startButton);
            }
            
            if (options.cancelButton !== undefined) {
                this.ui.cancelButton.toggle(options.cancelButton);
            }
            
            if (options.progressContainer !== undefined) {
                this.ui.progressContainer.toggle(options.progressContainer);
            }
            
            if (options.logContainer !== undefined) {
                this.ui.logContainer.toggle(options.logContainer);
            }
        },
        
        /**
         * Update the progress bar
         */
        updateProgressBar: function(forcePercentage) {
            var percentage;
            
            if (forcePercentage !== undefined) {
                percentage = forcePercentage;
            } else if (this.state.totalAdded > 0) {
                percentage = Math.round((this.state.totalProcessed / this.state.totalAdded) * 100);
            } else {
                percentage = 0;
            }
            
            this.ui.progressFill.css('width', percentage + '%');
            this.ui.progressText.text(percentage + '%');
            
            return percentage;
        },
        
        /**
         * Update count displays
         */
        updateCounts: function() {
            this.ui.completedCount.text(this.state.totalCompleted);
            this.ui.failedCount.text(this.state.totalFailed);
            this.ui.processingCount.text(this.state.totalProcessed - this.state.totalCompleted - this.state.totalFailed);
        },
        
        /**
         * Add an entry to the log display
         */
        addLogEntry: function(type, message) {
            var timestamp = new Date().toLocaleTimeString();
            var entry = $('<div class="bunny-log-entry bunny-log-' + type + '"></div>');
            entry.text('[' + timestamp + '] ' + message);
            
            // Add to log display
            this.ui.logDisplay.append(entry);
            
            // Scroll to bottom
            this.ui.logDisplay.scrollTop(this.ui.logDisplay[0].scrollHeight);
            
            // Also log to console
            console.log('[BMO] ' + message);
        },
        
        /**
         * Refresh optimization statistics
         */
        refreshOptimizationStats: function() {
            var self = this;
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_get_optimization_stats',
                    nonce: bunnyAjax.nonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data) {
                        // Update eligible count if element exists
                        if (self.ui.eligibleCount.length) {
                            self.ui.eligibleCount.text(self.formatNumber(response.data.eligible_for_optimization || 0));
                        }
                        
                        // If page has chart elements, refresh them
                        if ($('.bunny-circular-chart').length) {
                            self.refreshStatsChart(response.data);
                        }
                    }
                }
            });
        },
        
        /**
         * Refresh stats chart with new data
         */
        refreshStatsChart: function(stats) {
            if (!stats) return;
            
            // Update chart segments if they exist
            var $chart = $('.bunny-circular-chart');
            if ($chart.length) {
                // Calculate percentages
                var total = Math.max(1, stats.total_images);
                var optimizedPercent = (stats.optimized / total) * 100;
                var notOptimizedPercent = (stats.not_optimized / total) * 100;
                var inProgressPercent = (stats.in_progress / total) * 100;
                
                // Update chart center percentage
                $('.bunny-chart-percent').text(stats.optimization_percent + '%');
                
                // Update legend values
                $('.bunny-legend-value').each(function() {
                    var $this = $(this);
                    var $parent = $this.parent();
                    
                    if ($parent.hasClass('bunny-legend-total')) {
                        $this.text(self.formatNumber(stats.total_images));
                    } else if ($parent.hasClass('bunny-legend-saved')) {
                        $this.text(stats.space_saved);
                    } else if ($parent.hasClass('bunny-legend-reduction')) {
                        $this.text(stats.average_reduction + '%');
                    } else if ($parent.hasClass('bunny-optimized-color')) {
                        $this.text(self.formatNumber(stats.optimized));
                    } else if ($parent.hasClass('bunny-not-optimized-color')) {
                        $this.text(self.formatNumber(stats.not_optimized));
                    } else if ($parent.hasClass('bunny-in-progress-color')) {
                        $this.text(self.formatNumber(stats.in_progress));
                    }
                });
            }
        },
        
        /**
         * Format number with commas
         */
        formatNumber: function(number) {
            return number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        },
        
        /**
         * Handle optimization errors
         */
        handleError: function(message, error) {
            // Log the error
            console.error('Optimization error:', message, error);
            
            // Add to log if available
            if (this.addLogEntry) {
                this.addLogEntry('error', message);
            }
            
            // Show in diagnostics if available
            if (this.ui.diagnosticsResults && this.ui.diagnosticsResults.length) {
                this.ui.diagnosticsResults.html('<p class="error">' + message + '</p>').show();
            }
            
            // Trigger error event for external handlers
            $(document).trigger('bunny_optimization_error', {
                message: message,
                state: this.state
            });
            
            // Complete the optimization with error flag
            if (this.completeOptimization) {
                this.completeOptimization(true);
            }
            
            return { success: false, message: message };
        },
        
        /**
         * Fetch images for optimization
         * This is a wrapper around the start optimization AJAX call
         */
        fetchImages: function() {
            var self = this;
            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: bunnyAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bunny_start_optimization',
                        nonce: bunnyAjax.nonce
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            resolve(response.data);
                        } else {
                            reject(response.data || 'Unknown error');
                        }
                    },
                    error: function(xhr, status, error) {
                        reject(error || 'AJAX error');
                    }
                });
            });
        },
        
        /**
         * Run optimization diagnostics
         */
        runDiagnostics: function() {
            var self = this;
            
            // Add diagnostics results container if it doesn't exist
            if (!this.ui.diagnosticsResults.length) {
                this.ui.diagnosticsResults = $('#diagnostics-results');
                
                if (!this.ui.diagnosticsResults.length) {
                    this.ui.diagnosticsResults = $('<div id="diagnostics-results" class="bunny-diagnostics-results"></div>');
                    $('.bunny-optimization-actions').after(this.ui.diagnosticsResults);
                }
            }
            
            // Show loading
            this.ui.diagnosticsResults.html('<p>Running diagnostics...</p>').show();
            
            // Run diagnostics via AJAX
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_run_optimization_diagnostics',
                    nonce: bunnyAjax.nonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data) {
                        self.displayDiagnosticsResults(response.data);
                    } else {
                        self.ui.diagnosticsResults.html('<p class="error">Failed to run diagnostics: ' + (response.data || 'Unknown error') + '</p>');
                    }
                },
                error: function(xhr, status, error) {
                    self.ui.diagnosticsResults.html('<p class="error">AJAX error: ' + error + '</p>');
                }
            });
        },
        
        /**
         * Display diagnostics results
         */
        displayDiagnosticsResults: function(data) {
            var html = '<h3>Optimization Diagnostics Results</h3>';
            
            // General status
            html += '<div class="bunny-diagnostics-section">';
            html += '<h4>System Status</h4>';
            html += '<ul class="bunny-diagnostics-list">';
            html += '<li class="' + (data.api_key_set ? 'success' : 'error') + '">API Key: ' + (data.api_key_set ? 'Set' : 'Not Set') + '</li>';
            html += '<li class="' + (data.api_connection ? 'success' : 'error') + '">API Connection: ' + (data.api_connection ? 'Connected' : 'Failed') + '</li>';
            html += '<li class="' + (data.credits_available > 10 ? 'success' : 'warning') + '">Credits Available: ' + data.credits_available + '</li>';
            html += '<li class="' + (data.https_enabled ? 'success' : 'error') + '">HTTPS Enabled: ' + (data.https_enabled ? 'Yes' : 'No') + '</li>';
            html += '<li class="' + (data.queue_table_exists ? 'success' : 'error') + '">Queue Table: ' + (data.queue_table_exists ? 'Exists' : 'Missing') + '</li>';
            html += '<li class="' + (data.php_version_ok ? 'success' : 'error') + '">PHP Version: ' + (data.php_version_ok ? 'Compatible' : 'Incompatible') + '</li>';
            html += '</ul>';
            html += '</div>';
            
            // Queue status
            html += '<div class="bunny-diagnostics-section">';
            html += '<h4>Queue Status</h4>';
            html += '<ul class="bunny-diagnostics-list">';
            html += '<li>Eligible Images: ' + data.eligible_images + '</li>';
            html += '<li>Pending in Queue: ' + data.pending_in_queue + '</li>';
            html += '<li>Failed in Queue: ' + data.failed_in_queue + '</li>';
            html += '</ul>';
            html += '</div>';
            
            // Server environment
            html += '<div class="bunny-diagnostics-section">';
            html += '<h4>Server Environment</h4>';
            html += '<ul class="bunny-diagnostics-list">';
            html += '<li class="' + (parseInt(data.max_execution_time) >= 30 ? 'success' : 'warning') + '">Max Execution Time: ' + data.max_execution_time + 's</li>';
            html += '<li>Memory Limit: ' + data.memory_limit + '</li>';
            html += '</ul>';
            html += '</div>';
            
            // Recommendations
            if (data.recommendations && data.recommendations.length > 0) {
                html += '<div class="bunny-diagnostics-section">';
                html += '<h4>Recommendations</h4>';
                html += '<ul class="bunny-diagnostics-recommendations">';
                data.recommendations.forEach(function(recommendation) {
                    html += '<li>' + recommendation + '</li>';
                });
                html += '</ul>';
                html += '</div>';
            }
            
            // Display results
            this.ui.diagnosticsResults.html(html);
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        // Initialize the optimization module
        BunnyOptimization.init();
        
        // Bind diagnostics button
        $('.bunny-diagnostic-button').on('click', function(e) {
            e.preventDefault();
            BunnyOptimization.runDiagnostics();
        });
    });
    
})(jQuery); 