/**
 * Bunny Media Offload - BMO Optimization Module
 * 
 * Handles image optimization via BMO API with one-by-one processing
 * Based on BMO API documentation: https://api-us.bmo.nexwinds.com/docs
 * 
 * @version 1.0.1
 */

(function($) {
    'use strict';

    /**
     * BMO Optimization Handler
     * Implements image-by-image processing to avoid timeout issues
     */
    class BunnyOptimization {
        constructor(options = {}) {
            this.options = $.extend({
                processingDelay: 1000,   // Delay between processing images (ms)
                maxRetries: 3,           // Maximum retries per image
                retryDelay: 3000         // Delay between retries (ms)
            }, options);

            this.state = {
                active: false,
                sessionId: null,
                totalImages: 0,
                processedImages: 0,
                successfulImages: 0,
                failedImages: 0,
                startTime: null,
                target: 'local',
                errors: [],
                currentImage: null,
                imageQueue: [],
                processing: false
            };

            this.callbacks = {
                onStart: null,
                onProgress: null,
                onImageComplete: null,
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

                // Create optimization session
                const session = await this.createOptimizationSession(target);
                if (!session.success || !session.data.session_id) {
                    throw new Error(session.data?.message || 'Failed to create optimization session');
                }

                // Update state with session data
                this.state.sessionId = session.data.session_id;
                this.state.totalImages = session.data.total_images;
                this.state.active = true;

                this.log('success', `Optimization session created: ${this.state.sessionId}`);
                this.log('info', `Total images: ${this.state.totalImages}`);

                // Initialize UI
                this.initOptimizationInterface();
                
                // Trigger start callback
                if (this.callbacks.onStart) {
                    this.callbacks.onStart(this.state);
                }

                // Start image-by-image processing
                await this.processImagesOneByOne();

            } catch (error) {
                this.handleError('Failed to start optimization', error);
            }
        }

        /**
         * Process images one by one to avoid timeouts
         */
        async processImagesOneByOne() {
            try {
                // Get initial batch of images to process
                const initialImages = await this.getImagesToProcess();
                this.state.imageQueue = initialImages;
                
                // Process each image sequentially
                await this.processNextImage();
                
            } catch (error) {
                this.handleError('Failed to get images to process', error);
            }
        }
        
        /**
         * Process the next image in the queue
         */
        async processNextImage() {
            // Check if optimization is still active
            if (!this.state.active) {
                this.log('info', 'Optimization was cancelled');
                return;
            }
            
            // Check if we have more images to process
            if (this.state.imageQueue.length === 0) {
                // Try to get more images
                const moreImages = await this.getImagesToProcess();
                
                if (moreImages.length === 0) {
                    // No more images to process - complete optimization
                    await this.completeOptimization(true);
                    return;
                }
                
                this.state.imageQueue = moreImages;
            }
            
            // Get the next image from the queue
            const imageId = this.state.imageQueue.shift();
            this.state.currentImage = imageId;
            
            try {
                this.log('info', `Processing image ID: ${imageId}`);
                this.state.processing = true;
                
                // Process the single image with retries
                let retries = 0;
                let success = false;
                let lastError = null;
                
                while (retries <= this.options.maxRetries && !success) {
                    try {
                        const result = await this.processSingleImage(imageId);
                        success = true;
                        
                        // Update progress
                        this.state.processedImages++;
                        if (result.success) {
                            this.state.successfulImages++;
                            this.log('success', `Image ${imageId} optimized successfully`);
                        } else {
                            this.state.failedImages++;
                            this.log('warning', `Image ${imageId} optimization skipped: ${result.message || 'No reason provided'}`);
                        }
                        
                        // Trigger image complete callback
                        if (this.callbacks.onImageComplete) {
                            this.callbacks.onImageComplete(imageId, result, this.state);
                        }
                        
                    } catch (error) {
                        retries++;
                        lastError = error;
                        this.log('warning', `Failed to process image ${imageId} (attempt ${retries}/${this.options.maxRetries}): ${error.message}`);
                        
                        if (retries <= this.options.maxRetries) {
                            // Wait before retry
                            await this.delay(this.options.retryDelay);
                        }
                    }
                }
                
                // If all retries failed
                if (!success) {
                    this.state.processedImages++;
                    this.state.failedImages++;
                    this.log('error', `Failed to process image ${imageId} after ${this.options.maxRetries} attempts: ${lastError?.message}`);
                    
                    // Add to error list
                    this.state.errors.push({
                        imageId: imageId,
                        message: `Failed after ${this.options.maxRetries} attempts: ${lastError?.message}`,
                        time: new Date()
                    });
                }
                
                // Update UI
                this.updateProgressBar(this.state.processedImages / this.state.totalImages * 100);
                this.updateStatusText();
                
                // Trigger progress callback
                if (this.callbacks.onProgress) {
                    this.callbacks.onProgress(this.state);
                }
                
                // Delay before processing next image
                await this.delay(this.options.processingDelay);
                
                // Process next image
                this.state.processing = false;
                this.state.currentImage = null;
                await this.processNextImage();
                
            } catch (error) {
                this.handleError(`Failed to process image ${imageId}`, error);
            }
        }

        /**
         * Process a single image
         * @param {number} imageId - The ID of the image to process
         * @returns {Promise<Object>} Processing result
         */
        async processSingleImage(imageId) {
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: window.bunnyAjax?.ajaxurl || '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    timeout: 60000, // 60 second timeout for individual image processing
                    data: {
                        action: 'bunny_optimize_single_image',
                        nonce: window.bunnyAjax?.nonce,
                        session_id: this.state.sessionId,
                        image_id: imageId
                    },
                    success: (response) => {
                        if (response.success) {
                            resolve(response.data);
                        } else {
                            reject(new Error(response.data?.message || 'Failed to process image'));
                        }
                    },
                    error: (xhr, status, error) => {
                        const errorMsg = `AJAX Error: ${status} - ${error}`;
                        reject(new Error(errorMsg));
                    }
                });
            });
        }

        /**
         * Get images to process from the server
         * @returns {Promise<Array>} Array of image IDs to process
         */
        async getImagesToProcess() {
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: window.bunnyAjax?.ajaxurl || '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: {
                        action: 'bunny_get_images_to_optimize',
                        nonce: window.bunnyAjax?.nonce,
                        session_id: this.state.sessionId,
                        limit: 10 // Get 10 images at a time
                    },
                    success: (response) => {
                        if (response.success) {
                            resolve(response.data.images || []);
                        } else {
                            reject(new Error(response.data?.message || 'Failed to get images'));
                        }
                    },
                    error: (xhr, status, error) => {
                        reject(new Error(`AJAX Error: ${status} - ${error}`));
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
         * Complete optimization process
         * @param {boolean} success - Whether optimization completed successfully
         */
        async completeOptimization(success = false) {
            if (!this.state.active) {
                return; // Already completed or cancelled
            }
            
            this.log(success ? 'success' : 'info', `BMO optimization completed ${success ? 'successfully' : 'with errors'} in ${this.getElapsedTime()}`);
            this.log('info', `Results: ${this.state.successfulImages} successful, ${this.state.failedImages} failed`);
            
            // Mark as inactive
            this.state.active = false;
            
            // Update UI
            this.updateProgressBar(100);
            this.updateStatusText();
            
            // Show success or error message
            if (success) {
                this.showSuccessMessage();
            } else {
                this.showErrorMessage();
            }
            
            // Trigger complete callback
            if (this.callbacks.onComplete) {
                this.callbacks.onComplete(success, this.state);
            }
            
            // Reset the interface after a delay
            setTimeout(() => {
                this.resetOptimizationInterface();
            }, 5000);
        }

        /**
         * Cancel ongoing optimization
         */
        async cancelOptimization() {
            if (!this.state.active) {
                return;
            }
            
            this.log('info', 'Cancelling optimization...');
            
            try {
                // Notify server of cancellation
                await $.ajax({
                    url: window.bunnyAjax?.ajaxurl || '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: {
                        action: 'bunny_cancel_optimization',
                        nonce: window.bunnyAjax?.nonce,
                        session_id: this.state.sessionId
                    }
                });
                
                this.log('info', 'Optimization cancelled by user');
                
                // Mark as inactive
                this.state.active = false;
                
                // Reset the interface
                this.resetOptimizationInterface();
                
            } catch (error) {
                this.log('error', `Failed to cancel optimization: ${error.message}`);
            }
        }

        /**
         * Handle error during optimization
         */
        handleError(message, error) {
            this.log('error', `${message}: ${error.message || error}`);
            
            this.state.errors.push({
                message: message,
                error: error.message || error,
                time: new Date()
            });
            
            // Show error UI
            this.showErrorMessage(message);
            
            if (this.callbacks.onError) {
                this.callbacks.onError(message, error, this.state);
            }
            
            // If a critical error, stop optimization
            if (!this.state.processing) {
                this.state.active = false;
            }
        }

        /**
         * Initialize optimization interface
         */
        initOptimizationInterface() {
            $('.bunny-optimization-progress').show();
            $('.bunny-optimize-button').prop('disabled', true);
            $('.bunny-cancel-optimization').prop('disabled', false).show();
        }

        /**
         * Reset optimization interface
         */
        resetOptimizationInterface() {
            $('.bunny-optimization-progress').hide();
            $('.bunny-optimize-button').prop('disabled', false);
            $('.bunny-cancel-optimization').prop('disabled', true).hide();
        }

        /**
         * Setup progress interface
         */
        setupProgressInterface() {
            // Can be extended for more complex UI initialization
            $('.bunny-optimization-progress').hide();
            $('.bunny-cancel-optimization').hide();
        }

        /**
         * Update progress bar
         * @param {number} percent - Progress percentage
         */
        updateProgressBar(percent) {
            $('.bunny-optimization-progress-bar').css('width', `${percent}%`);
            $('.bunny-optimization-progress-text').text(`${Math.round(percent)}%`);
        }

        /**
         * Update status text
         */
        updateStatusText() {
            $('.bunny-optimization-status').text(
                `Processing: ${this.state.processedImages}/${this.state.totalImages} - ` +
                `Success: ${this.state.successfulImages}, Failed: ${this.state.failedImages}`
            );
        }

        /**
         * Show success message
         */
        showSuccessMessage() {
            const message = `Optimization completed: ${this.state.successfulImages} images optimized, ${this.state.failedImages} failed`;
            
            if (typeof DevExpress !== 'undefined' && DevExpress.ui && DevExpress.ui.notify) {
                DevExpress.ui.notify(message, 'success', 5000);
            } else {
                alert(message);
            }
        }

        /**
         * Show error message
         */
        showErrorMessage(message) {
            const errorMessage = message || (this.state.errors.length > 0 
                ? `Optimization failed: ${this.state.errors[this.state.errors.length - 1].error}`
                : 'Optimization failed with unknown error');
                
            if (typeof DevExpress !== 'undefined' && DevExpress.ui && DevExpress.ui.notify) {
                DevExpress.ui.notify(errorMessage, 'error', 5000);
            } else {
                alert(errorMessage);
            }
        }

        /**
         * Reset state
         */
        resetState() {
            this.state = {
                active: false,
                sessionId: null,
                totalImages: 0,
                processedImages: 0,
                successfulImages: 0,
                failedImages: 0,
                startTime: null,
                target: 'local',
                errors: [],
                currentImage: null,
                imageQueue: [],
                processing: false
            };
            
            this.clearLog();
        }

        /**
         * Clear log
         */
        clearLog() {
            // Clear log container if exists
            const $logContainer = $('.bunny-optimization-log');
            if ($logContainer.length) {
                $logContainer.empty();
            }
        }

        /**
         * Log message
         * @param {string} type - Log type ('info', 'success', 'warning', 'error')
         * @param {string} message - Log message
         */
        log(type, message) {
            const timestamp = new Date().toLocaleTimeString();
            const logPrefix = {
                'info': '',
                'success': '✅ ',
                'warning': '⚠️ ',
                'error': '❌ '
            }[type] || '';
            
            console.log(`[${timestamp}] ${logPrefix}${message}`);
            
            // Add to log container if exists
            const $logContainer = $('.bunny-optimization-log');
            if ($logContainer.length) {
                $logContainer.append(`<div class="log-${type}">[${timestamp}] ${logPrefix}${message}</div>`);
                $logContainer.scrollTop($logContainer[0].scrollHeight);
            }
        }

        /**
         * Set callbacks
         * @param {Object} callbacks - Callback functions
         */
        setCallbacks(callbacks) {
            this.callbacks = $.extend(this.callbacks, callbacks);
        }

        /**
         * Get state
         * @returns {Object} Current state
         */
        getState() {
            return this.state;
        }

        /**
         * Get elapsed time as formatted string
         * @returns {string} Elapsed time
         */
        getElapsedTime() {
            const elapsed = Math.floor((Date.now() - this.state.startTime) / 1000);
            return `${elapsed}s`;
        }

        /**
         * Helper: Delay execution
         * @param {number} ms - Milliseconds to delay
         * @returns {Promise} Promise that resolves after delay
         */
        delay(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }
    }

    // Export to global
    window.BunnyOptimization = BunnyOptimization;

    // Initialize on document ready
    $(document).ready(function() {
        // Create global instance with callbacks
        window.bunnyOptimizer = new BunnyOptimization();
        
        // Set callbacks
        window.bunnyOptimizer.setCallbacks({
            onStart: function(state) {
                console.log('🚀 BMO optimization started:', state);
            },
            onProgress: function(state) {
                console.log(`📊 Progress: ${state.processedImages}/${state.totalImages} (${Math.round(state.processedImages / state.totalImages * 100)}%)`);
            },
            onImageComplete: function(imageId, result, state) {
                console.log(`📦 Image ${imageId} processed:`, result);
            },
            onComplete: function(success, state) {
                console.log('🏁 BMO optimization completed:', { success, state });
            },
            onError: function(message, error, state) {
                console.error('❌ BMO optimization error:', { message, error, state });
            }
        });
    });

})(jQuery); 