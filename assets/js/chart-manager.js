/**
 * Chart Manager - Chart.js Integration Module
 * Handles interactive chart rendering with accessibility compliance
 * Supports responsive design and multiple chart types
 */

class ChartManager {
    constructor() {
        this.charts = new Map();
        this.defaultOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        padding: 20
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    borderColor: '#333',
                    borderWidth: 1
                }
            },
            accessibility: {
                enabled: true,
                announceNewData: {
                    enabled: true
                }
            }
        };
        this.colors = [
            '#3498db', '#e74c3c', '#2ecc71', '#f39c12',
            '#9b59b6', '#1abc9c', '#34495e', '#e67e22'
        ];
    }

    /**
     * Initialize Chart.js with accessibility features
     */
    init() {
        // Register Chart.js accessibility plugin if available
        if (typeof Chart !== 'undefined' && Chart.register) {
            Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
            Chart.defaults.font.size = 12;
            Chart.defaults.color = '#374151';
            Chart.defaults.borderColor = '#e5e7eb';
            Chart.defaults.backgroundColor = 'rgba(59, 130, 246, 0.1)';

            // Register accessibility plugin if available
            if (Chart.registry && Chart.registry.plugins) {
                // Chart.js accessibility features are built-in in v3+
            }
        }
    }

    /**
     * Create a pie chart for analytics data
     */
    createPieChart(canvasId, data, options = {}) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            console.error(`Canvas element ${canvasId} not found`);
            return null;
        }

        const config = {
            type: 'pie',
            data: {
                labels: data.labels || [],
                datasets: [{
                    data: data.values || [],
                    backgroundColor: this.colors.slice(0, data.values?.length || 0),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                ...this.defaultOptions,
                ...options,
                plugins: {
                    ...this.defaultOptions.plugins,
                    ...options.plugins,
                    legend: {
                        ...this.defaultOptions.plugins.legend,
                        ...options.plugins?.legend
                    }
                }
            }
        };

        const chart = new Chart(canvas, config);
        this.charts.set(canvasId, chart);
        this.addAccessibilityAttributes(canvas, data);
        return chart;
    }

    /**
     * Create a bar chart for analytics data
     */
    createBarChart(canvasId, data, options = {}) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            console.error(`Canvas element ${canvasId} not found`);
            return null;
        }

        const config = {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: [{
                    label: data.label || 'Data',
                    data: data.values || [],
                    backgroundColor: this.colors[0],
                    borderColor: this.colors[0],
                    borderWidth: 1
                }]
            },
            options: {
                ...this.defaultOptions,
                ...options,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            stepSize: 10
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        };

        const chart = new Chart(canvas, config);
        this.charts.set(canvasId, chart);
        this.addAccessibilityAttributes(canvas, data);
        return chart;
    }

    /**
     * Create a line chart for trend analysis
     */
    createLineChart(canvasId, data, options = {}) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            console.error(`Canvas element ${canvasId} not found`);
            return null;
        }

        const datasets = data.datasets || [{
            label: data.label || 'Data',
            data: data.values || [],
            borderColor: this.colors[0],
            backgroundColor: this.colors[0] + '20',
            tension: 0.4,
            fill: true
        }];

        const config = {
            type: 'line',
            data: {
                labels: data.labels || [],
                datasets: datasets
            },
            options: {
                ...this.defaultOptions,
                ...options,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            stepSize: 10
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    }
                }
            }
        };

        const chart = new Chart(canvas, config);
        this.charts.set(canvasId, chart);
        this.addAccessibilityAttributes(canvas, data);
        return chart;
    }

    /**
     * Create a stacked bar chart for multi-dataset analytics
     */
    createStackedBarChart(canvasId, data, options = {}) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            console.error(`Canvas element ${canvasId} not found`);
            return null;
        }

        const config = {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: data.datasets || []
            },
            options: {
                ...this.defaultOptions,
                ...options,
                scales: {
                    x: {
                        stacked: true,
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            stepSize: 10
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    }
                }
            }
        };

        const chart = new Chart(canvas, config);
        this.charts.set(canvasId, chart);
        this.addAccessibilityAttributes(canvas, data);
        return chart;
    }

    /**
     * Create a horizontal bar chart
     */
    createHorizontalBarChart(canvasId, data, options = {}) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            console.error(`Canvas element ${canvasId} not found`);
            return null;
        }

        const config = {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: [{
                    label: data.label || 'Data',
                    data: data.values || [],
                    backgroundColor: this.colors.slice(0, data.values?.length || 0),
                    borderWidth: 1
                }]
            },
            options: {
                ...this.defaultOptions,
                ...options,
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    },
                    y: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        };

        const chart = new Chart(canvas, config);
        this.charts.set(canvasId, chart);
        this.addAccessibilityAttributes(canvas, data);
        return chart;
    }

    /**
     * Add accessibility attributes to chart canvas
     */
    addAccessibilityAttributes(canvas, data) {
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', this.generateChartDescription(data));

        // Add tabindex for keyboard navigation
        canvas.setAttribute('tabindex', '0');

        // Add keyboard event listeners
        canvas.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                this.announceChartData(canvas.id, data);
            }
        });
    }

    /**
     * Generate accessible description for chart
     */
    generateChartDescription(data) {
        const totalItems = data.values?.length || 0;
        const maxValue = Math.max(...(data.values || [0]));
        const minValue = Math.min(...(data.values || [0]));

        return `Chart with ${totalItems} data points. Values range from ${minValue} to ${maxValue}.`;
    }

    /**
     * Announce chart data for screen readers
     */
    announceChartData(chartId, data) {
        const announcement = document.createElement('div');
        announcement.setAttribute('aria-live', 'polite');
        announcement.setAttribute('aria-atomic', 'true');
        announcement.style.position = 'absolute';
        announcement.style.left = '-10000px';

        let text = 'Chart data: ';
        if (data.labels && data.values) {
            for (let i = 0; i < Math.min(data.labels.length, data.values.length); i++) {
                text += `${data.labels[i]}: ${data.values[i]}. `;
            }
        }

        announcement.textContent = text;
        document.body.appendChild(announcement);

        setTimeout(() => {
            document.body.removeChild(announcement);
        }, 1000);
    }

    /**
     * Update chart data
     */
    updateChart(canvasId, newData) {
        const chart = this.charts.get(canvasId);
        if (!chart) {
            console.error(`Chart ${canvasId} not found`);
            return;
        }

        chart.data.labels = newData.labels || chart.data.labels;
        chart.data.datasets[0].data = newData.values || chart.data.datasets[0].data;
        chart.update();

        // Update accessibility attributes
        const canvas = document.getElementById(canvasId);
        if (canvas) {
            this.addAccessibilityAttributes(canvas, newData);
        }
    }

    /**
     * Destroy chart and clean up
     */
    destroyChart(canvasId) {
        const chart = this.charts.get(canvasId);
        if (chart) {
            chart.destroy();
            this.charts.delete(canvasId);
        }
    }

    /**
     * Resize all charts
     */
    resizeCharts() {
        this.charts.forEach(chart => {
            chart.resize();
        });
    }

    /**
     * Get chart instance
     */
    getChart(canvasId) {
        return this.charts.get(canvasId);
    }
}

// Initialize global chart manager
window.chartManager = new ChartManager();

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.chartManager.init();
});

// Handle window resize
window.addEventListener('resize', () => {
    window.chartManager.resizeCharts();
});