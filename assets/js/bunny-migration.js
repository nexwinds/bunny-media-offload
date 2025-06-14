/**
 * Bunny Media Migration - JavaScript Module
 * 
 * Handles the client-side functionality for migrating images to Bunny CDN.
 */

// Create a self-executing module to avoid polluting the global namespace
(function($) {
    'use strict';
    
    // Main migration class
    var BunnyMigration = {
        // Configuration
        config: {
            queueDelay: 200,         // Delay between image migrations (ms)
            maxQueue: 100,           // Maximum internal queue size
            retryAttempts: 3,        // Number of retry attempts for failed migrations
            pollingInterval: 1000    // Polling interval for status updates (ms)
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
            startTime: null,
            languageScope: 'current' // Default to current language
        },
        
        // UI elements (initialized when needed)
        ui: {
            startButton: null,
            cancelButton: null,
            progressBar: null,
            progressFill: null,
            progressText: null,
            progressContainer: null,
            statusText: null,
            errorList: null,
            errorContainer: null,
            logContainer: null,
            logDisplay: null,
            formOptions: null
        },
        
        /**
         * Initialize the migration module
         */
        init: function() {
            var self = this;
            
            // Initialize configuration from global if available
            if (window.bunnyAjax && window.bunnyAjax.migration_config) {
                $.extend(this.config, window.bunnyAjax.migration_config);
            }
            
            // Cache UI elements
            this.ui.startButton = $('#start-migration');
            this.ui.cancelButton = $('#cancel-migration');
            this.ui.progressContainer = $('#migration-progress');
            this.ui.progressBar = $('#migration-progress-bar');
            this.ui.statusText = $('#migration-status-text');
            this.ui.errorContainer = $('#migration-errors');
            this.ui.errorList = $('#migration-error-list');
            
            // Create log container if it doesn't exist
            if ($('#migration-log').length === 0) {
                $('<div id="migration-log" class="bunny-migration-log" style="display: none;">' +
                  '<h4>Migration Log</h4>' +
                  '<div class="bunny-log-container" id="migration-log-container"></div>' +
                  '</div>').insertAfter(this.ui.progressContainer);
            }
            
            this.ui.logContainer = $('#migration-log');
            this.ui.logDisplay = $('#migration-log-container');
            
            // Bind events
            $('#migration-form').on('submit', function(e) {
                e.preventDefault();
                self.startMigration();
            });
            
            this.ui.cancelButton.on('click', function(e) {
                e.preventDefault();
                self.cancelMigration();
            });
            
            // Get language scope from form if exists
            $('input[name="language_scope"]').on('change', function() {
                self.state.languageScope = $(this).val();
            });
            
            // Log initialization
            console.log('Bunny Migration Module initialized with config:', this.config);
            
            // Make available globally for debugging and API access
            window.bunnyMigrationInstance = this;
            
            return this;
        },
        
        /**
         * Start the migration process
         */
        startMigration: function() {
            var self = this;
            
            // If already processing, do nothing
            if (this.state.isProcessing) {
                return;
            }
            
            // Reset state
            this.resetState();
            
            // Get language scope if set
            if ($('input[name="language_scope"]:checked').length) {
                this.state.languageScope = $('input[name="language_scope"]:checked').val();
            }
            
            // Update UI
            this.updateUI({
                startButton: false,
                cancelButton: true,
                progressContainer: true,
                logContainer: true,
                errorContainer: false
            });
            
            // Add log entry
            this.addLogEntry('info', 'Starting migration process...');
            
            // Start time tracking
            this.state.startTime = new Date();
            
            // Get images Ready for Migration
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_start_migration',
                    nonce: bunnyAjax.nonce,
                    language_scope: this.state.languageScope
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        self.state.totalAdded = response.data.added || 0;
                        self.state.queueRemaining = response.data.queue_size || 0;
                        
                        if (self.state.totalAdded > 0) {
                            self.addLogEntry('success', 'Added ' + self.state.totalAdded + ' images to migration queue');
                            self.updateStatusText('Processing ' + self.state.totalAdded + ' images...');
                            
                            // Start processing the queue
                            self.processMigrationQueue();
                        } else {
                            self.addLogEntry('warning', 'No eligible images found for migration');
                            self.updateStatusText('No eligible images found for migration');
                            self.completeMigration();
                        }
                    } else {
                        self.addLogEntry('error', 'Failed to add images to queue: ' + (response.data || 'Unknown error'));
                        self.updateStatusText('Failed to start migration');
                        self.completeMigration(true);
                    }
                },
                error: function(xhr, status, error) {
                    self.addLogEntry('error', 'AJAX error: ' + error);
                    self.updateStatusText('Failed to start migration: Network error');
                    self.completeMigration(true);
                }
            });
        },
        
        /**
         * Process the migration queue
         */
        processMigrationQueue: function() {
            var self = this;
            
            // If cancelled or no more items in queue, complete
            if (this.state.isCancelled || this.state.queueRemaining <= 0) {
                this.completeMigration();
                return;
            }
            
            // Update UI
            this.updateProgressBar();
            this.updateStatusText('Processing migration... (' + this.state.queueRemaining + ' images remaining)');
            
            // Process migration via AJAX
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_migration_batch',
                    nonce: bunnyAjax.nonce,
                    language_scope: this.state.languageScope
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Reset retry count on success
                        self.state.retryCount = 0;
                        
                        // Update state
                        var processed = response.data.processed || 0;
                        self.state.totalProcessed += processed;
                        self.state.queueRemaining = response.data.remaining || 0;
                        
                        // Process results
                        if (response.data.results) {
                            self.processMigrationResults(response.data.results);
                        }
                        
                        // Update UI
                        self.updateProgressBar();
                        
                        // Add log entry for batch
                        if (processed > 0) {
                            self.addLogEntry('success', 'Processed batch: ' + processed + ' images');
                        }
                        
                        // Continue processing after delay
                        setTimeout(function() {
                            self.processMigrationQueue();
                        }, self.config.queueDelay);
                    } else {
                        // Retry logic
                        if (self.state.retryCount < self.config.retryAttempts) {
                            self.state.retryCount++;
                            self.addLogEntry('warning', 'Batch failed, retrying... (Attempt ' + self.state.retryCount + '/' + self.config.retryAttempts + ')');
                            
                            // Retry after delay
                            setTimeout(function() {
                                self.processMigrationQueue();
                            }, self.config.queueDelay * 2);
                        } else {
                            self.addLogEntry('error', 'Batch processing failed: ' + (response.data.message || 'Unknown error'));
                            self.updateStatusText('Migration failed');
                            self.completeMigration(true);
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
                            self.processMigrationQueue();
                        }, self.config.queueDelay * 2);
                    } else {
                        self.addLogEntry('error', 'AJAX error: ' + error);
                        self.updateStatusText('Migration failed: Network error');
                        self.completeMigration(true);
                    }
                }
            });
        },
        
        /**
         * Process migration results
         */
        processMigrationResults: function(results) {
            if (!Array.isArray(results)) {
                if (typeof results === 'object') {
                    // Handle single result object
                    this.processSingleResult(results);
                }
                return;
            }
            
            // Process each result
            for (var i = 0; i < results.length; i++) {
                this.processSingleResult(results[i]);
            }
        },
        
        /**
         * Process a single migration result
         */
        processSingleResult: function(result) {
            if (!result) return;
            
            var attachment_id = result.attachment_id || 'unknown';
            
            if (result.success) {
                this.state.totalCompleted++;
                this.addLogEntry('success', 'Migrated image #' + attachment_id + ' to Bunny CDN');
            } else if (result.skipped) {
                this.state.totalSkipped++;
                this.addLogEntry('info', 'Skipped image #' + attachment_id + ' - ' + (result.message || 'Already migrated'));
            } else {
                this.state.totalFailed++;
                this.addLogEntry('error', 'Failed to migrate image #' + attachment_id + ' - ' + (result.message || 'Unknown error'));
                this.addErrorToList('Image #' + attachment_id + ': ' + (result.message || 'Unknown error'));
            }
        },
        
        /**
         * Cancel the migration process
         */
        cancelMigration: function() {
            var self = this;
            
            // If not processing, do nothing
            if (!this.state.isProcessing) {
                return;
            }
            
            // Set cancelled flag
            this.state.isCancelled = true;
            
            // Add log entry
            this.addLogEntry('warning', 'Cancelling migration...');
            
            // Update UI
            this.updateStatusText('Cancelling...');
            this.ui.cancelButton.prop('disabled', true);
            
            // Send cancel request to server
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_cancel_migration',
                    nonce: bunnyAjax.nonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        self.addLogEntry('info', 'Migration cancelled');
                    } else {
                        self.addLogEntry('error', 'Failed to cancel migration: ' + (response.data || 'Unknown error'));
                    }
                    
                    self.completeMigration();
                },
                error: function(xhr, status, error) {
                    self.addLogEntry('error', 'AJAX error while cancelling: ' + error);
                    self.completeMigration();
                },
                complete: function() {
                    self.ui.cancelButton.prop('disabled', false);
                }
            });
        },
        
        /**
         * Complete the migration process
         */
        completeMigration: function(hasError) {
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
                this.addLogEntry('error', 'Migration completed with errors. Processed: ' + this.state.totalProcessed + ' images in ' + elapsedTime);
                this.updateStatusText('Migration completed with errors. Processed: ' + this.state.totalProcessed + ' images');
                
                // Show error container if there are failures
                if (this.state.totalFailed > 0) {
                    this.ui.errorContainer.show();
                }
            } else if (this.state.isCancelled) {
                this.addLogEntry('warning', 'Migration cancelled. Processed: ' + this.state.totalProcessed + ' images in ' + elapsedTime);
                this.updateStatusText('Migration cancelled. Processed: ' + this.state.totalProcessed + ' images');
            } else {
                this.addLogEntry('success', 'Migration completed! Processed: ' + this.state.totalProcessed + ' images in ' + elapsedTime);
                this.updateStatusText('Migration completed! Processed: ' + this.state.totalProcessed + ' images in ' + elapsedTime);
            }
            
            // Update UI
            this.updateUI({
                startButton: true,
                cancelButton: false
            });
            
            // Final progress update
            this.updateProgressBar(100);
            
            // Reset processing state
            this.state.isProcessing = false;
            
            // Refresh stats after a delay
            setTimeout(this.refreshMigrationStats.bind(this), 1500);
        },
        
        /**
         * Reset the state for a new migration session
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
                startTime: null,
                languageScope: this.state.languageScope // Preserve language scope
            };
            
            // Clear log and errors
            this.ui.logDisplay.empty();
            this.ui.errorList.empty();
            this.ui.errorContainer.hide();
            
            // Reset progress bar
            this.updateProgressBar(0);
            this.updateStatusText('Initializing...');
        },
        
        /**
         * Update the UI elements visibility
         */
        updateUI: function(options) {
            if (options.startButton !== undefined) {
                this.ui.startButton.prop('disabled', !options.startButton);
            }
            
            if (options.cancelButton !== undefined) {
                this.ui.cancelButton.toggleClass('bunny-button-hidden', !options.cancelButton);
            }
            
            if (options.progressContainer !== undefined) {
                this.ui.progressContainer.toggleClass('bunny-status-hidden', !options.progressContainer);
            }
            
            if (options.logContainer !== undefined) {
                this.ui.logContainer.toggle(options.logContainer);
            }
            
            if (options.errorContainer !== undefined) {
                this.ui.errorContainer.toggle(options.errorContainer);
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
            
            this.ui.progressBar.css('width', percentage + '%');
            
            return percentage;
        },
        
        /**
         * Update status text
         */
        updateStatusText: function(text) {
            this.ui.statusText.text(text);
        },
        
        /**
         * Add an error to the error list
         */
        addErrorToList: function(error) {
            $('<li></li>').text(error).appendTo(this.ui.errorList);
            this.ui.errorContainer.show();
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
            console.log('[Bunny Migration] ' + message);
        },
        
        /**
         * Refresh migration statistics
         */
        refreshMigrationStats: function() {
            
        },
        
        /**
         * Format number with commas
         */
        formatNumber: function(number) {
            return number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        // Initialize the migration module
        BunnyMigration.init();
    });
    
})(jQuery); 