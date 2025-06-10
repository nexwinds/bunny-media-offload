/**
 * Bunny Media Offload - BMO Optimization Module
 * 
 * Handles image optimization via BMO API with proper batch processing
 * Based on BMO API documentation: https://api-us.bmo.nexwinds.com/docs
 * 
 * @version 1.0.0
 */

(function($) {
    'use strict';

    /**
     * BMO Optimization Handler
     * Implements BMO API batch processing specifications
     */
    class BunnyOptimization {
        constructor(options = {}) {
            this.options = $.extend({
                batchSize: 20,          // BMO API maximum batch size
                maxQueue: 100,          // Maximum internal queue size
                processingDelay: 1000,  // Delay between batches (ms)
                retryAttempts: 3,       // Retry attempts for failed batches
                strategy: 'FIFO'        // Processing strategy (First In, First Out)
            }, options);

            this.state = {
                active: false,
                sessionId: null,
                totalImages: 0,
                processedImages: 0,
                successfulImages: 0,
                failedImages: 0,
                currentBatch: 0,
                totalBatches: 0,
                target: 'local',
                startTime: null,
                errors: []
            };

            this.callbacks = {
                onStart: null,
                onProgress: null,
                onBatchComplete: null,
                onComplete: null,
                onError: null
            };

            this.init();
        }

        /**
         * Initialize optimization module
         */
        init() {
            this.bindEvents();
            this.setupProgressInterface();
            console.log('BMO Optimization Module initialized with config:', this.options);
        }

        /**
         * Bind optimization events
         */
        bindEvents() {
            const self = this;

            // Bind optimization buttons
            $(document).on('click', '.bunny-optimize-button', function(e) {
                e.preventDefault();
                const target = $(this).data('target') || 'local';
                self.startOptimization(target);
            });

            // Bind cancel button
            $(document).on('click', '.bunny-cancel-optimization', function(e) {
                e.preventDefault();
                self.cancelOptimization();
            });
        }

        /**
         * Start BMO optimization process
         * @param {string} target - Optimization target ('local' or 'cloud')
         */
        async startOptimization(target = 'local') {
            try {
                this.log('info', `Starting BMO optimization for target: ${target}`);
                
                // Reset state
                this.resetState();
                this.state.target = target;
                this.state.startTime = Date.now();

                // Validate BMO API configuration
                const configValid = await this.validateBMOConfig();
                if (!configValid) {
                    throw new Error('BMO API configuration is invalid');
                }

                // Create optimization session
                const session = await this.createOptimizationSession(target);
                if (!session.success || !session.data.session_id) {
                    throw new Error(session.data?.message || 'Failed to create optimization session');
                }

                // Update state with session data
                this.state.sessionId = session.data.session_id;
                this.state.totalImages = session.data.total_images;
                this.state.totalBatches = Math.ceil(this.state.totalImages / this.options.batchSize);
                this.state.active = true;

                this.log('success', `Optimization session created: ${this.state.sessionId}`);
                this.log('info', `Total images: ${this.state.totalImages}, Batches: ${this.state.totalBatches}`);

                // Initialize UI
                this.initOptimizationInterface();
                
                // Trigger start callback
                if (this.callbacks.onStart) {
                    this.callbacks.onStart(this.state);
                }

                // Start batch processing
                await this.processBatches();

            } catch (error) {
                this.handleError('Failed to start optimization', error);
            }
        }

        /**
         * Process optimization batches using BMO API specifications
         */
        async processBatches() {
            while (this.state.active && this.state.currentBatch < this.state.totalBatches) {
                try {
                    this.state.currentBatch++;
                    this.log('info', `Processing batch ${this.state.currentBatch}/${this.state.totalBatches}`);

                    // Process single batch
                    const batchResult = await this.processBatch();
                    
                    if (batchResult.success) {
                        this.updateProgress(batchResult.data);
                        
                        // Trigger batch complete callback
                        if (this.callbacks.onBatchComplete) {
                            this.callbacks.onBatchComplete(batchResult.data, this.state);
                        }

                        // Check if optimization is complete
                        if (batchResult.data.completed) {
                            await this.completeOptimization(true);
                            return;
                        }

                        // BMO API rate limiting - delay between batches
                        if (this.state.currentBatch < this.state.totalBatches) {
                            this.log('info', `Waiting ${this.options.processingDelay}ms before next batch...`);
                            await this.delay(this.options.processingDelay);
                        }

                    } else {
                        throw new Error(batchResult.data?.message || 'Batch processing failed');
                    }

                } catch (error) {
                    this.handleBatchError(error);
                    break;
                }
            }
        }

        /**
         * Process single optimization batch
         * @returns {Promise<Object>} Batch processing result
         */
        async processBatch() {
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: window.bunnyAjax?.ajaxurl || '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    timeout: 60000, // 60 second timeout for BMO API processing
                    data: {
                        action: 'bunny_optimization_batch',
                        nonce: window.bunnyAjax?.nonce,
                        session_id: this.state.sessionId
                    },
                    success: (response) => {
                        this.logBatchResponse(response);
                        resolve(response);
                    },
                    error: (xhr, status, error) => {
                        const errorMsg = `AJAX Error: ${status} - ${error}`;
                        this.log('error', errorMsg);
                        reject(new Error(errorMsg));
                    }
                });
            });
        }

        /**
         * Create optimization session
         * @param {string} target - Optimization target
         * @returns {Promise<Object>} Session creation result
         */
        async createOptimizationSession(target) {
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: window.bunnyAjax?.ajaxurl || '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: {
                        action: 'bunny_start_step_optimization',
                        nonce: window.bunnyAjax?.nonce,
                        optimization_target: target
                    },
                    success: resolve,
                    error: (xhr, status, error) => {
                        reject(new Error(`Session creation failed: ${status} - ${error}`));
                    }
                });
            });
        }

        /**
         * Validate BMO API configuration
         * @returns {Promise<boolean>} Configuration validity
         */
        async validateBMOConfig() {
            // Check if BMO API credentials are available
            if (!window.bunnyAjax?.nonce) {
                this.log('error', 'WordPress AJAX nonce not available');
                return false;
            }

            // Additional BMO API validation can be added here
            return true;
        }

        /**
         * Update optimization progress
         * @param {Object} data - Progress data from server
         */
        updateProgress(data) {
            // Update state
            this.state.processedImages = data.processed || 0;
            this.state.successfulImages = data.successful || 0;
            this.state.failedImages = data.failed || 0;

            // Calculate progress percentage based on total images in session
            const progress = this.state.totalImages > 0 
                ? Math.round((this.state.processedImages / this.state.totalImages) * 100) 
                : 0;

            // Update UI elements
            this.updateProgressBar(progress);
            this.updateStatusText(data);
            this.displayRecentProcessed(data.recent_processed);

            // Show API preparation info if images couldn't be prepared
            if (data.batch_info && data.batch_info.requested_batch_size > data.batch_info.validation_passed) {
                const skipped = data.batch_info.requested_batch_size - data.batch_info.validation_passed;
                this.log('warning', `${skipped} images could not be prepared for BMO API (URL access issues, API errors)`);
            }

            // Trigger progress callback
            if (this.callbacks.onProgress) {
                this.callbacks.onProgress(data, this.state);
            }

            this.log('info', `Progress: ${this.state.processedImages}/${this.state.totalImages} (${progress}%)`);
        }

        /**
         * Complete optimization process
         * @param {boolean} success - Whether optimization completed successfully
         */
        async completeOptimization(success = false) {
            this.state.active = false;
            const duration = Date.now() - this.state.startTime;
            const durationSeconds = Math.round(duration / 1000);

            if (success) {
                this.log('success', `BMO optimization completed successfully in ${durationSeconds}s`);
                this.log('info', `Results: ${this.state.successfulImages} successful, ${this.state.failedImages} failed`);
                this.showSuccessMessage();
            } else {
                this.log('error', `BMO optimization failed after ${durationSeconds}s`);
                this.showErrorMessage();
            }

            // Reset UI
            this.resetOptimizationInterface();

            // Trigger complete callback
            if (this.callbacks.onComplete) {
                this.callbacks.onComplete(success, this.state);
            }
        }

        /**
         * Cancel optimization process
         */
        async cancelOptimization() {
            if (!this.state.active || !this.state.sessionId) {
                return;
            }

            try {
                this.log('info', 'Cancelling optimization...');
                
                const response = await $.ajax({
                    url: window.bunnyAjax?.ajaxurl || '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: {
                        action: 'bunny_cancel_optimization',
                        nonce: window.bunnyAjax?.nonce,
                        session_id: this.state.sessionId
                    }
                });

                if (response.success) {
                    this.log('info', 'Optimization cancelled successfully');
                    await this.completeOptimization(false);
                } else {
                    throw new Error(response.data?.message || 'Failed to cancel optimization');
                }

            } catch (error) {
                this.handleError('Failed to cancel optimization', error);
            }
        }

        /**
         * Handle optimization errors
         * @param {string} message - Error message
         * @param {Error} error - Error object
         */
        handleError(message, error) {
            const fullMessage = `${message}: ${error.message}`;
            this.log('error', fullMessage);
            this.state.errors.push(fullMessage);
            
            if (this.callbacks.onError) {
                this.callbacks.onError(error, this.state);
            }
            
            this.completeOptimization(false);
        }

        /**
         * Handle batch processing errors
         * @param {Error} error - Error object
         */
        handleBatchError(error) {
            this.log('error', `Batch ${this.state.currentBatch} failed: ${error.message}`);
            this.state.errors.push(`Batch ${this.state.currentBatch}: ${error.message}`);
            
            // Attempt retry if within retry limits
            if (this.state.retryAttempts < this.options.retryAttempts) {
                this.state.retryAttempts++;
                this.log('info', `Retrying batch ${this.state.currentBatch} (attempt ${this.state.retryAttempts})`);
                this.state.currentBatch--; // Retry same batch
                return;
            }
            
            this.handleError('Batch processing failed', error);
        }

        /**
         * Log batch response for debugging
         * @param {Object} response - Server response
         */
        logBatchResponse(response) {
            console.group('🔍 BMO Batch Processing Debug');
            console.log('Full response:', response);
            console.log('Success:', response.success);
            
            if (response.success && response.data) {
                const data = response.data;
                console.log('Images in batch:', data.recent_processed?.length || 0);
                console.log('Total progress:', `${data.processed || 0}/${data.total || 0}`);
                console.log('Batch info:', data.batch_info);
                console.log('Completed:', data.completed);
                console.log('Message:', data.message);
                
                // Log validation details if available
                if (data.batch_info) {
                    const info = data.batch_info;
                    if (info.requested_batch_size !== info.validation_passed) {
                        console.warn(`⚠️ BMO API Preparation: ${info.validation_passed}/${info.requested_batch_size} images prepared successfully`);
                        const skipped = info.requested_batch_size - info.validation_passed;
                        console.log(`📋 ${skipped} images could not be prepared for BMO API (URL issues, API preparation errors)`);
                    }
                }
            }
            
            console.groupEnd();
        }

        /**
         * Initialize optimization interface
         */
        initOptimizationInterface() {
            $('#optimization-progress').show();
            $('.bunny-optimization-actions').hide();
            $('.bunny-cancel-section').show();
            
            this.updateProgressBar(0);
            this.updateStatusText({ message: 'Starting BMO optimization...' });
            this.clearLog();
        }

        /**
         * Reset optimization interface
         */
        resetOptimizationInterface() {
            $('#optimization-progress').hide();
            $('.bunny-optimization-actions').show();
            $('.bunny-cancel-section').hide();
        }

        /**
         * Setup progress interface elements
         */
        setupProgressInterface() {
            // Ensure progress elements exist
            if ($('#optimization-progress').length === 0) {
                // Progress interface will be created by PHP template
                console.log('Progress interface elements will be rendered by server');
            }
        }

        /**
         * Update progress bar
         * @param {number} progress - Progress percentage (0-100)
         */
        updateProgressBar(progress) {
            $('#optimization-progress-bar').css('width', progress + '%');
            $('#optimization-progress-text').text(Math.round(progress) + '%');
        }

        /**
         * Update status text
         * @param {Object} data - Status data
         */
        updateStatusText(data) {
            const message = data.message || `Processed: ${this.state.processedImages}/${this.state.totalImages}`;
            $('#optimization-status-text').text(message);
        }

        /**
         * Display recently processed images
         * @param {Array} recentProcessed - Array of recently processed image results
         */
        displayRecentProcessed(recentProcessed) {
            if (!recentProcessed || !Array.isArray(recentProcessed)) {
                return;
            }

            recentProcessed.forEach(result => {
                if (result.success) {
                    this.log('success', `✅ ${result.filename} ${result.savings ? `(${result.savings})` : ''}`);
                } else {
                    this.log('error', `❌ ${result.filename} - ${result.error || 'Unknown error'}`);
                }
            });
        }

        /**
         * Show success message
         */
        showSuccessMessage() {
            if (typeof DevExpress !== 'undefined' && DevExpress.ui && DevExpress.ui.notify) {
                DevExpress.ui.notify('Optimization completed successfully!', 'success', 3000);
            } else {
                alert('Optimization completed successfully!');
            }
        }

        /**
         * Show error message
         */
        showErrorMessage() {
            const message = this.state.errors.length > 0 
                ? `Optimization failed: ${this.state.errors[this.state.errors.length - 1]}`
                : 'Optimization failed with unknown error';
                
            if (typeof DevExpress !== 'undefined' && DevExpress.ui && DevExpress.ui.notify) {
                DevExpress.ui.notify(message, 'error', 5000);
            } else {
                alert(message);
            }
        }

        /**
         * Reset optimization state
         */
        resetState() {
            this.state = {
                active: false,
                sessionId: null,
                totalImages: 0,
                processedImages: 0,
                successfulImages: 0,
                failedImages: 0,
                currentBatch: 0,
                totalBatches: 0,
                target: 'local',
                startTime: null,
                errors: [],
                retryAttempts: 0
            };
        }

        /**
         * Clear optimization log
         */
        clearLog() {
            $('#optimization-log').empty();
        }

        /**
         * Add log entry
         * @param {string} type - Log type ('info', 'success', 'error', 'warning')
         * @param {string} message - Log message
         */
        log(type, message) {
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = `[${timestamp}] ${message}`;
            
            // Console logging
            switch (type) {
                case 'error':
                    console.error(logEntry);
                    break;
                case 'warning':
                    console.warn(logEntry);
                    break;
                case 'success':
                    console.log(`✅ ${logEntry}`);
                    break;
                default:
                    console.log(logEntry);
            }
            
            // UI logging
            const $logContainer = $('#optimization-log');
            if ($logContainer.length > 0) {
                const $logEntry = $(`<div class="log-entry log-${type}">${logEntry}</div>`);
                $logContainer.append($logEntry);
                $logContainer.scrollTop($logContainer[0].scrollHeight);
            }
        }

        /**
         * Set callback functions
         * @param {Object} callbacks - Callback functions
         */
        setCallbacks(callbacks) {
            this.callbacks = $.extend(this.callbacks, callbacks);
        }

        /**
         * Get current optimization state
         * @returns {Object} Current state
         */
        getState() {
            return { ...this.state };
        }

        /**
         * Utility function to create delays
         * @param {number} ms - Milliseconds to delay
         * @returns {Promise} Promise that resolves after delay
         */
        delay(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }
    }

    // Export to global scope
    window.BunnyOptimization = BunnyOptimization;

    // Auto-initialize when DOM is ready
    $(document).ready(function() {
        if (typeof window.bunnyOptimizationInstance === 'undefined') {
            window.bunnyOptimizationInstance = new BunnyOptimization();
            
            // Set up callbacks for integration with existing UI
            window.bunnyOptimizationInstance.setCallbacks({
                onStart: function(state) {
                    console.log('🚀 BMO optimization started:', state);
                },
                onProgress: function(data, state) {
                    // Integration point for custom progress handling
                },
                onBatchComplete: function(data, state) {
                    console.log(`📦 Batch ${state.currentBatch}/${state.totalBatches} completed`);
                },
                onComplete: function(success, state) {
                    console.log('🏁 BMO optimization completed:', { success, state });
                },
                onError: function(error, state) {
                    console.error('❌ BMO optimization error:', { error, state });
                }
            });
        }
    });

})(jQuery); 