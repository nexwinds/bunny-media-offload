/**
 * Bunny Media Offload - Unified Statistics Module
 * 
 * This module serves as the single source of truth for all statistics
 * displayed across the plugin's admin pages.
 */

(function($) {
    'use strict';
    
    // Main statistics module
    var BunnyStats = {
        // Cache for statistics data
        data: null,
        
        // DOM selectors for statistics elements
        selectors: {
            totalImages: '.bunny-total-images',
            notOptimized: '.bunny-not-optimized-count',
            readyForMigration: '.bunny-ready-for-migration-count',
            onCDN: '.bunny-on-cdn-count',
            optimizedImages: '.bunny-optimized-images-count',
            inProgress: '.bunny-in-progress-count',
            spaceSaved: '.bunny-space-saved',
            percentNotOptimized: '.bunny-not-optimized-percent',
            percentReadyForMigration: '.bunny-ready-for-migration-percent',
            percentOnCDN: '.bunny-on-cdn-percent',
            percentOptimized: '.bunny-optimization-percent',
            progressBar: '.bunny-progress-fill'
        },
        
        /**
         * Initialize the statistics module
         */
        init: function() {
            console.log('Initializing Bunny Stats Module');
            
            // Fetch statistics on page load
            this.fetchStats();
            
            // Listen for statistics update events
            $(document).on('bunny_stats_updated', this.onStatsUpdated.bind(this));
            
            // Setup refresh interval (every 30 seconds)
            setInterval(this.fetchStats.bind(this), 30000);
            
            return this;
        },
        
        /**
         * Fetch unified statistics from the server
         */
        fetchStats: function() {
            var self = this;
            
            $.ajax({
                url: bunnyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bunny_refresh_all_stats',
                    nonce: bunnyAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Store the data
                        self.data = response.data;
                        
                        // Update all UI elements
                        self.updateAllStats();
                        
                        // Notify other components that stats have been updated
                        $(document).trigger('bunny_stats_refreshed', [self.data]);
                    }
                }
            });
        },
        
        /**
         * Update all statistics displays
         */
        updateAllStats: function() {
            if (!this.data) return;
            
            // Update basic stats
            this.updateElement(this.selectors.totalImages, this.data.total_images);
            this.updateElement(this.selectors.notOptimized, this.data.local_eligible);
            this.updateElement(this.selectors.readyForMigration, this.data.already_optimized);
            this.updateElement(this.selectors.onCDN, this.data.images_migrated);
            
            // Update percentages
            this.updateElement(this.selectors.percentNotOptimized, this.data.not_optimized_percent + '%');
            this.updateElement(this.selectors.percentReadyForMigration, this.data.optimized_percent + '%');
            this.updateElement(this.selectors.percentOnCDN, this.data.cloud_percent + '%');
            
            // Update optimization-specific stats
            this.updateElement(this.selectors.optimizedImages, 
                (this.data.already_optimized || 0) + (this.data.images_migrated || 0));
            this.updateElement(this.selectors.inProgress, this.data.in_progress || 0);
            this.updateElement(this.selectors.spaceSaved, this.data.space_saved || '0 B');
            this.updateElement(this.selectors.percentOptimized, 
                (this.data.optimized_percent + this.data.cloud_percent) + '%');
            
            // Update progress bars
            var self = this;
            $(this.selectors.progressBar).each(function() {
                var type = $(this).data('stat-type');
                var percentage = 0;
                
                if (type === 'migration') {
                    percentage = self.data.migration_progress || 0;
                } else if (type === 'optimization') {
                    percentage = self.data.optimized_percent + self.data.cloud_percent || 0;
                }
                
                $(this).css('width', percentage + '%');
            });
            
            // Update charts if they exist
            this.updateCharts();
        },
        
        /**
         * Update specific UI element with value
         */
        updateElement: function(selector, value) {
            var formattedValue = this.formatValue(value);
            $(selector).text(formattedValue);
        },
        
        /**
         * Format values for display
         */
        formatValue: function(value) {
            if (typeof value === 'number' && !isNaN(value)) {
                return value.toLocaleString();
            }
            return value;
        },
        
        /**
         * Update chart visualizations
         */
        updateCharts: function() {
            var self = this;
            
            // Update donut charts if they exist
            $('.bunny-circular-chart').each(function() {
                self.updateDonutChart($(this));
            });
            
            // Update bar charts if they exist
            $('.bunny-bar-chart').each(function() {
                self.updateBarChart($(this));
            });
        },
        
        /**
         * Update donut chart with current statistics
         */
        updateDonutChart: function($chart) {
            if (!this.data || !$chart.length) return;
            
            var total = Math.max(1, this.data.total_images);
            var notOptimizedPercent = (this.data.local_eligible / total) * 100;
            var readyForMigrationPercent = (this.data.already_optimized / total) * 100;
            var onCDNPercent = (this.data.images_migrated / total) * 100;
            
            // Calculate stroke-dasharray values
            var circumference = 2 * Math.PI * 80;
            
            // Update chart segments
            $chart.find('.bunny-segment-not-optimized').each(function() {
                var dashLength = (notOptimizedPercent / 100) * circumference;
                $(this).attr('stroke-dasharray', dashLength + ' ' + (circumference - dashLength));
            });
            
            $chart.find('.bunny-segment-ready-for-migration').each(function() {
                var dashLength = (readyForMigrationPercent / 100) * circumference;
                $(this).attr('stroke-dasharray', dashLength + ' ' + (circumference - dashLength));
                $(this).attr('stroke-dashoffset', -(notOptimizedPercent / 100) * circumference);
            });
            
            $chart.find('.bunny-segment-on-cdn').each(function() {
                var dashLength = (onCDNPercent / 100) * circumference;
                $(this).attr('stroke-dasharray', dashLength + ' ' + (circumference - dashLength));
                $(this).attr('stroke-dashoffset', -((notOptimizedPercent + readyForMigrationPercent) / 100) * circumference);
            });
            
            // Update center text
            var $centerPercent = $chart.siblings('.bunny-chart-center').find('.bunny-chart-percent');
            if ($centerPercent.length) {
                var optimizationPercent = this.data.optimized_percent + this.data.cloud_percent;
                $centerPercent.text(optimizationPercent.toFixed(1) + '%');
            }
        },
        
        /**
         * Update bar chart with current statistics
         */
        updateBarChart: function($chart) {
            if (!this.data || !$chart.length) return;
            
            var self = this;
            $chart.find('[data-stat]').each(function() {
                var statName = $(this).data('stat');
                var value = 0;
                
                switch (statName) {
                    case 'not-optimized':
                        value = self.data.local_eligible || 0;
                        break;
                    case 'ready-for-migration':
                        value = self.data.already_optimized || 0;
                        break;
                    case 'on-cdn':
                        value = self.data.images_migrated || 0;
                        break;
                    case 'in-progress':
                        value = self.data.in_progress || 0;
                        break;
                }
                
                $(this).find('.bunny-stat-value').text(value.toLocaleString());
            });
        },
        
        /**
         * Handle stats updated event
         */
        onStatsUpdated: function(event, newData) {
            // Update our cached data
            if (newData) {
                this.data = newData;
            }
            
            // Update all UI elements
            this.updateAllStats();
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        // Make the stats module globally accessible
        window.BunnyStats = BunnyStats.init();
    });
    
})(jQuery); 