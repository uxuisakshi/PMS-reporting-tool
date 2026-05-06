<?php

require_once __DIR__ . '/ExportEngine.php';
require_once __DIR__ . '/AnalyticsReport.php';

/**
 * ExcelExporter - Generate structured Excel files with multiple worksheets using PhpSpreadsheet
 * 
 * Provides comprehensive Excel export functionality for analytics reports:
 * - Multiple worksheets for different report types and data views
 * - Raw data tables alongside summary statistics
 * - Professional formatting with headers, styling, and data validation
 * - Support for both .xlsx and .xls formats
 * - Secure file generation and handling
 * 
 * Requirements: 15.1, 15.2, 15.3, 15.4
 */
class ExcelExporter extends ExportEngine {
    
    private $spreadsheet;
    private $worksheetIndex;
    private $styleConfig;
    private $formatConfig;
    private $maxRowsPerSheet;
    
    public function __construct() {
        parent::__construct();
        
        // Initialize Excel configuration
        $this->initializeExcelConfig();
        
        // Load formatting configuration
        $this->loadFormatConfig();
    }
    
    /**
     * Generate export file - Excel implementation
     * 
     * @param int $requestId Export request ID
     * @param string $exportType Should be 'excel'
     * @param string $reportType Type of analytics report
     * @param array $projectIds Array of project IDs
     * @param array $options Export configuration options
     * @return string Path to generated Excel file
     * @throws Exception If Excel generation fails
     */
    protected function generateExportFile($requestId, $exportType, $reportType, $projectIds, $options): string {
        if ($exportType !== 'excel') {
            throw new Exception('ExcelExporter only supports Excel format');
        }
        
        // Generate analytics report data
        $reportData = $this->generateReportData($reportType, $projectIds, $options);
        
        // Initialize PhpSpreadsheet
        $this->initializeSpreadsheet($options);
        
        // Generate Excel content with multiple worksheets
        $this->generateExcelContent($reportData, $projectIds, $options);
        
        // Save Excel file
        $filename = $this->generateExcelFilename($reportType, $projectIds, $requestId, $options);
        $filePath = $this->exportDir . $filename;
        
        $this->saveExcel($filePath, $options);
        
        return $filePath;
    }
    
    /**
     * Initialize Excel configuration
     */
    private function initializeExcelConfig() {
        $this->worksheetIndex = 0;
        $this->maxRowsPerSheet = 65000; // Leave room for headers and formatting
        
        $this->styleConfig = [
            'header' => [
                'font' => ['bold' => true, 'size' => 12],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E6E6FA']],
                'borders' => ['allBorders' => ['borderStyle' => 'thin']]
            ],
            'title' => [
                'font' => ['bold' => true, 'size' => 16],
                'alignment' => ['horizontal' => 'center']
            ],
            'summary' => [
                'font' => ['bold' => true, 'size' => 11],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F0F8FF']]
            ],
            'data' => [
                'font' => ['size' => 10],
                'borders' => ['allBorders' => ['borderStyle' => 'thin']]
            ]
        ];
    }
    
    /**
     * Initialize PhpSpreadsheet instance
     */
    private function initializeSpreadsheet($options = []) {
        // Check if PhpSpreadsheet is available
        if (!$this->isPhpSpreadsheetAvailable()) {
            throw new Exception('PhpSpreadsheet library is required for Excel export. Please install via Composer: composer require phpoffice/phpspreadsheet');
        }
        
        // Create new spreadsheet
        $spreadsheetClass = '\PhpOffice\PhpSpreadsheet\Spreadsheet';
        $this->spreadsheet = new $spreadsheetClass();
        
        // Set document properties
        $this->setDocumentProperties($options);
        
        // Remove default worksheet
        $this->spreadsheet->removeSheetByIndex(0);
        $this->worksheetIndex = 0;
    }
    
    /**
     * Check if PhpSpreadsheet is available
     */
    private function isPhpSpreadsheetAvailable() {
        // Try to include PhpSpreadsheet if it exists in common locations
        $phpSpreadsheetPaths = [
            __DIR__ . '/../../vendor/phpoffice/phpspreadsheet/src/Bootstrap.php',
            __DIR__ . '/../../vendor/autoload.php',
            '/usr/share/php/PhpSpreadsheet/autoload.php'
        ];
        
        foreach ($phpSpreadsheetPaths as $path) {
            if (file_exists($path)) {
                require_once $path;
                break;
            }
        }
        
        return class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet');
    }
    
    /**
     * Set document properties
     */
    private function setDocumentProperties($options) {
        $properties = $this->spreadsheet->getProperties();
        $properties->setCreator('Analytics System')
                   ->setLastModifiedBy('Analytics System')
                   ->setTitle('Analytics Report Export')
                   ->setSubject('Client Analytics Report')
                   ->setDescription('Comprehensive analytics report with multiple data views')
                   ->setKeywords('analytics accessibility compliance report')
                   ->setCategory('Reports');
    }
    
    /**
     * Generate Excel content with multiple worksheets
     */
    private function generateExcelContent($reportData, $projectIds, $options) {
        // Generate report header information
        $headerInfo = $this->generateReportHeader($projectIds);
        
        // Create summary worksheet
        $this->createSummaryWorksheet($reportData, $headerInfo, $options);
        
        // Create raw data worksheet
        $this->createRawDataWorksheet($reportData, $headerInfo, $options);
        
        // Create detailed analytics worksheets based on report type
        $this->createAnalyticsWorksheets($reportData, $headerInfo, $options);
        
        // Create charts worksheet if requested
        if (isset($options['includeCharts']) && $options['includeCharts']) {
            $this->createChartsWorksheet($reportData, $headerInfo, $options);
        }
        
        // Set active worksheet to summary
        $this->spreadsheet->setActiveSheetIndex(0);
    }
    
    /**
     * Create summary worksheet
     */
    private function createSummaryWorksheet($reportData, $headerInfo, $options) {
        $worksheet = $this->spreadsheet->createSheet($this->worksheetIndex++);
        $worksheet->setTitle('Summary');
        
        $row = 1;
        
        // Add title
        $worksheet->setCellValue('A' . $row, $headerInfo['title']);
        $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['title']);
        $worksheet->mergeCells('A' . $row . ':F' . $row);
        $row += 2;
        
        // Add generation info
        $worksheet->setCellValue('A' . $row, 'Generated:');
        $worksheet->setCellValue('B' . $row, $headerInfo['generated_at']);
        $worksheet->setCellValue('A' . ($row + 1), 'Projects:');
        $worksheet->setCellValue('B' . ($row + 1), $headerInfo['total_projects']);
        $row += 3;
        
        // Add project list
        if (!empty($headerInfo['projects'])) {
            $worksheet->setCellValue('A' . $row, 'Project Details:');
            $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['summary']);
            $row++;
            
            // Headers
            $headers = ['ID', 'Title', 'Description'];
            $col = 'A';
            foreach ($headers as $header) {
                $worksheet->setCellValue($col . $row, $header);
                $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['header']);
                $col++;
            }
            $row++;
            
            // Project data
            foreach ($headerInfo['projects'] as $project) {
                $worksheet->setCellValue('A' . $row, $project['id']);
                $worksheet->setCellValue('B' . $row, $project['title']);
                $worksheet->setCellValue('C' . $row, $project['description'] ?? '');
                $worksheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($this->styleConfig['data']);
                $row++;
            }
            $row++;
        }
        
        // Add summary statistics
        $this->addSummaryStatistics($worksheet, $reportData, $row);
        
        // Auto-size columns
        foreach (range('A', 'F') as $col) {
            $worksheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
    
    /**
     * Create raw data worksheet
     */
    private function createRawDataWorksheet($reportData, $headerInfo, $options) {
        $worksheet = $this->spreadsheet->createSheet($this->worksheetIndex++);
        $worksheet->setTitle('Raw Data');
        
        $row = 1;
        
        // Add title
        $worksheet->setCellValue('A' . $row, 'Raw Analytics Data');
        $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['title']);
        $worksheet->mergeCells('A' . $row . ':H' . $row);
        $row += 2;
        
        // Add raw data tables
        foreach ($reportData as $dataType => $data) {
            if (is_array($data) && !empty($data)) {
                $row = $this->addDataTable($worksheet, $dataType, $data, $row);
                $row += 2; // Add spacing between tables
            }
        }
        
        // Auto-size columns
        foreach (range('A', 'H') as $col) {
            $worksheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
    
    /**
     * Create detailed analytics worksheets
     */
    private function createAnalyticsWorksheets($reportData, $headerInfo, $options) {
        // Create separate worksheets for different analytics types
        $analyticsTypes = [
            'user_affected' => 'User Impact Analysis',
            'wcag_compliance' => 'WCAG Compliance',
            'severity_analysis' => 'Severity Distribution',
            'common_issues' => 'Common Issues',
            'blocker_issues' => 'Blocker Analysis',
            'page_issues' => 'Page-wise Issues',
            'commented_issues' => 'Commented Issues',
            'compliance_trend' => 'Compliance Trends'
        ];
        
        foreach ($analyticsTypes as $type => $title) {
            if (isset($reportData[$type]) && !empty($reportData[$type])) {
                $this->createAnalyticsWorksheet($reportData[$type], $title, $type);
            }
        }
    }
    
    /**
     * Create individual analytics worksheet
     */
    private function createAnalyticsWorksheet($data, $title, $type) {
        $worksheet = $this->spreadsheet->createSheet($this->worksheetIndex++);
        $worksheet->setTitle($this->sanitizeSheetName($title));
        
        $row = 1;
        
        // Add title
        $worksheet->setCellValue('A' . $row, $title);
        $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['title']);
        $worksheet->mergeCells('A' . $row . ':F' . $row);
        $row += 2;
        
        // Add analytics-specific content
        $row = $this->addAnalyticsContent($worksheet, $data, $type, $row);
        
        // Auto-size columns
        foreach (range('A', 'F') as $col) {
            $worksheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
    
    /**
     * Create charts worksheet
     */
    private function createChartsWorksheet($reportData, $headerInfo, $options) {
        $worksheet = $this->spreadsheet->createSheet($this->worksheetIndex++);
        $worksheet->setTitle('Charts');
        
        $row = 1;
        
        // Add title
        $worksheet->setCellValue('A' . $row, 'Analytics Charts');
        $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['title']);
        $worksheet->mergeCells('A' . $row . ':F' . $row);
        $row += 2;
        
        // Add note about charts
        $worksheet->setCellValue('A' . $row, 'Note: Chart data is available in other worksheets. For visual charts, please use the PDF export option.');
        $worksheet->getStyle('A' . $row)->getFont()->setItalic(true);
        $row += 2;
        
        // Add chart data summaries
        $this->addChartDataSummaries($worksheet, $reportData, $row);
    }
    
    /**
     * Add summary statistics to worksheet
     */
    private function addSummaryStatistics($worksheet, $reportData, $startRow) {
        $row = $startRow;
        
        $worksheet->setCellValue('A' . $row, 'Summary Statistics:');
        $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['summary']);
        $row++;
        
        // Headers
        $headers = ['Metric', 'Value', 'Description'];
        $col = 'A';
        foreach ($headers as $header) {
            $worksheet->setCellValue($col . $row, $header);
            $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['header']);
            $col++;
        }
        $row++;
        
        // Calculate and add summary metrics
        $summaryMetrics = $this->calculateSummaryMetrics($reportData);
        
        foreach ($summaryMetrics as $metric) {
            $worksheet->setCellValue('A' . $row, $metric['name']);
            $worksheet->setCellValue('B' . $row, $metric['value']);
            $worksheet->setCellValue('C' . $row, $metric['description']);
            $worksheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($this->styleConfig['data']);
            $row++;
        }
        
        return $row;
    }
    
    /**
     * Add data table to worksheet
     */
    private function addDataTable($worksheet, $title, $data, $startRow) {
        $row = $startRow;
        
        // Add table title
        $worksheet->setCellValue('A' . $row, ucfirst(str_replace('_', ' ', $title)));
        $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['summary']);
        $row++;
        
        if (empty($data)) {
            $worksheet->setCellValue('A' . $row, 'No data available');
            return $row + 1;
        }
        
        // Handle different data structures
        if (isset($data[0]) && is_array($data[0])) {
            // Array of arrays - tabular data
            $headers = array_keys($data[0]);
            
            // Add headers
            $col = 'A';
            foreach ($headers as $header) {
                $worksheet->setCellValue($col . $row, ucfirst(str_replace('_', ' ', $header)));
                $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['header']);
                $col++;
            }
            $row++;
            
            // Add data rows
            foreach ($data as $dataRow) {
                $col = 'A';
                foreach ($headers as $header) {
                    $value = $dataRow[$header] ?? '';
                    $worksheet->setCellValue($col . $row, $value);
                    $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['data']);
                    $col++;
                }
                $row++;
                
                // Check row limit
                if ($row > $this->maxRowsPerSheet) {
                    $worksheet->setCellValue('A' . $row, '... Data truncated due to size limits ...');
                    break;
                }
            }
        } else {
            // Key-value pairs
            $headers = ['Property', 'Value'];
            
            // Add headers
            $col = 'A';
            foreach ($headers as $header) {
                $worksheet->setCellValue($col . $row, $header);
                $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['header']);
                $col++;
            }
            $row++;
            
            // Add data
            foreach ($data as $key => $value) {
                $worksheet->setCellValue('A' . $row, ucfirst(str_replace('_', ' ', $key)));
                $worksheet->setCellValue('B' . $row, is_array($value) ? json_encode($value) : $value);
                $worksheet->getStyle('A' . $row . ':B' . $row)->applyFromArray($this->styleConfig['data']);
                $row++;
            }
        }
        
        return $row;
    }
    
    /**
     * Add analytics-specific content
     */
    private function addAnalyticsContent($worksheet, $data, $type, $startRow) {
        $row = $startRow;
        
        switch ($type) {
            case 'user_affected':
                $row = $this->addUserAffectedContent($worksheet, $data, $row);
                break;
            case 'wcag_compliance':
                $row = $this->addWCAGComplianceContent($worksheet, $data, $row);
                break;
            case 'severity_analysis':
                $row = $this->addSeverityAnalysisContent($worksheet, $data, $row);
                break;
            case 'common_issues':
                $row = $this->addCommonIssuesContent($worksheet, $data, $row);
                break;
            case 'blocker_issues':
                $row = $this->addBlockerIssuesContent($worksheet, $data, $row);
                break;
            case 'page_issues':
                $row = $this->addPageIssuesContent($worksheet, $data, $row);
                break;
            case 'commented_issues':
                $row = $this->addCommentedIssuesContent($worksheet, $data, $row);
                break;
            case 'compliance_trend':
                $row = $this->addComplianceTrendContent($worksheet, $data, $row);
                break;
            default:
                $row = $this->addDataTable($worksheet, $type, $data, $row);
        }
        
        return $row;
    }
    
    /**
     * Add user affected specific content
     */
    private function addUserAffectedContent($worksheet, $data, $startRow) {
        $row = $startRow;
        
        // Distribution table
        if (isset($data['distribution'])) {
            $worksheet->setCellValue('A' . $row, 'User Impact Distribution:');
            $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['summary']);
            $row++;
            
            $headers = ['Range', 'Count', 'Percentage'];
            $col = 'A';
            foreach ($headers as $header) {
                $worksheet->setCellValue($col . $row, $header);
                $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['header']);
                $col++;
            }
            $row++;
            
            foreach ($data['distribution'] as $range => $count) {
                $percentage = isset($data['total']) && $data['total'] > 0 ? 
                    round(($count / $data['total']) * 100, 1) . '%' : '0%';
                
                $worksheet->setCellValue('A' . $row, $range);
                $worksheet->setCellValue('B' . $row, $count);
                $worksheet->setCellValue('C' . $row, $percentage);
                $worksheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($this->styleConfig['data']);
                $row++;
            }
            $row++;
        }
        
        // Summary statistics
        if (isset($data['total_affected'])) {
            $worksheet->setCellValue('A' . $row, 'Total Users Affected:');
            $worksheet->setCellValue('B' . $row, $data['total_affected']);
            $worksheet->getStyle('A' . $row . ':B' . $row)->applyFromArray($this->styleConfig['summary']);
            $row++;
        }
        
        return $row;
    }
    
    /**
     * Add WCAG compliance specific content
     */
    private function addWCAGComplianceContent($worksheet, $data, $startRow) {
        $row = $startRow;
        
        // Compliance by level
        if (isset($data['by_level'])) {
            $worksheet->setCellValue('A' . $row, 'Compliance by WCAG Level:');
            $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['summary']);
            $row++;
            
            $headers = ['Level', 'Total Issues', 'Resolved', 'Compliance %'];
            $col = 'A';
            foreach ($headers as $header) {
                $worksheet->setCellValue($col . $row, $header);
                $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['header']);
                $col++;
            }
            $row++;
            
            foreach ($data['by_level'] as $level => $stats) {
                $worksheet->setCellValue('A' . $row, $level);
                $worksheet->setCellValue('B' . $row, $stats['total'] ?? 0);
                $worksheet->setCellValue('C' . $row, $stats['resolved'] ?? 0);
                $worksheet->setCellValue('D' . $row, ($stats['compliance_percentage'] ?? 0) . '%');
                $worksheet->getStyle('A' . $row . ':D' . $row)->applyFromArray($this->styleConfig['data']);
                $row++;
            }
            $row++;
        }
        
        // Common violations
        if (isset($data['common_violations'])) {
            $worksheet->setCellValue('A' . $row, 'Most Common WCAG Violations:');
            $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['summary']);
            $row++;
            
            $headers = ['Guideline', 'Count', 'Level'];
            $col = 'A';
            foreach ($headers as $header) {
                $worksheet->setCellValue($col . $row, $header);
                $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['header']);
                $col++;
            }
            $row++;
            
            foreach ($data['common_violations'] as $violation) {
                $worksheet->setCellValue('A' . $row, $violation['guideline'] ?? '');
                $worksheet->setCellValue('B' . $row, $violation['count'] ?? 0);
                $worksheet->setCellValue('C' . $row, $violation['level'] ?? '');
                $worksheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($this->styleConfig['data']);
                $row++;
            }
        }
        
        return $row;
    }
    
    /**
     * Add severity analysis specific content
     */
    private function addSeverityAnalysisContent($worksheet, $data, $startRow) {
        $row = $startRow;
        
        if (isset($data['distribution'])) {
            $worksheet->setCellValue('A' . $row, 'Severity Distribution:');
            $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['summary']);
            $row++;
            
            $headers = ['Severity', 'Count', 'Percentage'];
            $col = 'A';
            foreach ($headers as $header) {
                $worksheet->setCellValue($col . $row, $header);
                $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['header']);
                $col++;
            }
            $row++;
            
            foreach ($data['distribution'] as $severity => $count) {
                $percentage = isset($data['total']) && $data['total'] > 0 ? 
                    round(($count / $data['total']) * 100, 1) . '%' : '0%';
                
                $worksheet->setCellValue('A' . $row, $severity);
                $worksheet->setCellValue('B' . $row, $count);
                $worksheet->setCellValue('C' . $row, $percentage);
                $worksheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($this->styleConfig['data']);
                $row++;
            }
        }
        
        return $row;
    }
    
    /**
     * Add common issues specific content
     */
    private function addCommonIssuesContent($worksheet, $data, $startRow) {
        $row = $startRow;
        
        if (isset($data['top_issues'])) {
            $worksheet->setCellValue('A' . $row, 'Most Common Issues:');
            $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['summary']);
            $row++;
            
            $headers = ['Issue', 'Occurrences', 'Impact Potential'];
            $col = 'A';
            foreach ($headers as $header) {
                $worksheet->setCellValue($col . $row, $header);
                $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['header']);
                $col++;
            }
            $row++;
            
            foreach ($data['top_issues'] as $issue) {
                $worksheet->setCellValue('A' . $row, $issue['title'] ?? '');
                $worksheet->setCellValue('B' . $row, $issue['count'] ?? 0);
                $worksheet->setCellValue('C' . $row, $issue['impact_potential'] ?? '');
                $worksheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($this->styleConfig['data']);
                $row++;
            }
        }
        
        return $row;
    }
    
    /**
     * Add blocker issues specific content
     */
    private function addBlockerIssuesContent($worksheet, $data, $startRow) {
        $row = $startRow;
        
        if (isset($data['blockers'])) {
            $worksheet->setCellValue('A' . $row, 'Blocker Issues:');
            $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['summary']);
            $row++;
            
            $headers = ['Issue', 'Priority', 'Status', 'Affected Functionality'];
            $col = 'A';
            foreach ($headers as $header) {
                $worksheet->setCellValue($col . $row, $header);
                $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['header']);
                $col++;
            }
            $row++;
            
            foreach ($data['blockers'] as $blocker) {
                $worksheet->setCellValue('A' . $row, $blocker['title'] ?? '');
                $worksheet->setCellValue('B' . $row, $blocker['priority'] ?? '');
                $worksheet->setCellValue('C' . $row, $blocker['status'] ?? '');
                $worksheet->setCellValue('D' . $row, $blocker['affected_functionality'] ?? '');
                $worksheet->getStyle('A' . $row . ':D' . $row)->applyFromArray($this->styleConfig['data']);
                $row++;
            }
        }
        
        return $row;
    }
    
    /**
     * Add page issues specific content
     */
    private function addPageIssuesContent($worksheet, $data, $startRow) {
        $row = $startRow;
        
        if (isset($data['by_page'])) {
            $worksheet->setCellValue('A' . $row, 'Issues by Page:');
            $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['summary']);
            $row++;
            
            $headers = ['Page/URL', 'Issue Count', 'Issue Density', 'Top Severity'];
            $col = 'A';
            foreach ($headers as $header) {
                $worksheet->setCellValue($col . $row, $header);
                $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['header']);
                $col++;
            }
            $row++;
            
            foreach ($data['by_page'] as $page) {
                $worksheet->setCellValue('A' . $row, $page['page'] ?? '');
                $worksheet->setCellValue('B' . $row, $page['issue_count'] ?? 0);
                $worksheet->setCellValue('C' . $row, $page['density'] ?? '');
                $worksheet->setCellValue('D' . $row, $page['top_severity'] ?? '');
                $worksheet->getStyle('A' . $row . ':D' . $row)->applyFromArray($this->styleConfig['data']);
                $row++;
            }
        }
        
        return $row;
    }
    
    /**
     * Add commented issues specific content
     */
    private function addCommentedIssuesContent($worksheet, $data, $startRow) {
        $row = $startRow;
        
        if (isset($data['commented_issues'])) {
            $worksheet->setCellValue('A' . $row, 'Issues with Comments:');
            $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['summary']);
            $row++;
            
            $headers = ['Issue', 'Comment Count', 'Last Activity', 'Engagement Level'];
            $col = 'A';
            foreach ($headers as $header) {
                $worksheet->setCellValue($col . $row, $header);
                $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['header']);
                $col++;
            }
            $row++;
            
            foreach ($data['commented_issues'] as $issue) {
                $worksheet->setCellValue('A' . $row, $issue['title'] ?? '');
                $worksheet->setCellValue('B' . $row, $issue['comment_count'] ?? 0);
                $worksheet->setCellValue('C' . $row, $issue['last_activity'] ?? '');
                $worksheet->setCellValue('D' . $row, $issue['engagement_level'] ?? '');
                $worksheet->getStyle('A' . $row . ':D' . $row)->applyFromArray($this->styleConfig['data']);
                $row++;
            }
        }
        
        return $row;
    }
    
    /**
     * Add compliance trend specific content
     */
    private function addComplianceTrendContent($worksheet, $data, $startRow) {
        $row = $startRow;
        
        if (isset($data['trend_data'])) {
            $worksheet->setCellValue('A' . $row, 'Compliance Trend Over Time:');
            $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['summary']);
            $row++;
            
            $headers = ['Period', 'Total Issues', 'Resolved', 'New Issues', 'Compliance %'];
            $col = 'A';
            foreach ($headers as $header) {
                $worksheet->setCellValue($col . $row, $header);
                $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['header']);
                $col++;
            }
            $row++;
            
            foreach ($data['trend_data'] as $period) {
                $worksheet->setCellValue('A' . $row, $period['period'] ?? '');
                $worksheet->setCellValue('B' . $row, $period['total_issues'] ?? 0);
                $worksheet->setCellValue('C' . $row, $period['resolved'] ?? 0);
                $worksheet->setCellValue('D' . $row, $period['new_issues'] ?? 0);
                $worksheet->setCellValue('E' . $row, ($period['compliance_percentage'] ?? 0) . '%');
                $worksheet->getStyle('A' . $row . ':E' . $row)->applyFromArray($this->styleConfig['data']);
                $row++;
            }
        }
        
        return $row;
    }
    
    /**
     * Add chart data summaries
     */
    private function addChartDataSummaries($worksheet, $reportData, $startRow) {
        $row = $startRow;
        
        // Add chart data for each analytics type
        foreach ($reportData as $type => $data) {
            if (is_array($data) && !empty($data)) {
                $worksheet->setCellValue('A' . $row, ucfirst(str_replace('_', ' ', $type)) . ' Chart Data:');
                $worksheet->getStyle('A' . $row)->applyFromArray($this->styleConfig['summary']);
                $row++;
                
                // Extract chart-friendly data
                $chartData = $this->extractChartData($data, $type);
                
                if (!empty($chartData)) {
                    $headers = array_keys($chartData[0]);
                    
                    // Add headers
                    $col = 'A';
                    foreach ($headers as $header) {
                        $worksheet->setCellValue($col . $row, ucfirst(str_replace('_', ' ', $header)));
                        $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['header']);
                        $col++;
                    }
                    $row++;
                    
                    // Add data
                    foreach ($chartData as $dataRow) {
                        $col = 'A';
                        foreach ($headers as $header) {
                            $worksheet->setCellValue($col . $row, $dataRow[$header] ?? '');
                            $worksheet->getStyle($col . $row)->applyFromArray($this->styleConfig['data']);
                            $col++;
                        }
                        $row++;
                    }
                }
                
                $row += 2; // Add spacing
            }
        }
        
        return $row;
    }
    
    /**
     * Extract chart-friendly data from analytics data
     */
    private function extractChartData($data, $type) {
        $chartData = [];
        
        switch ($type) {
            case 'user_affected':
                if (isset($data['distribution'])) {
                    foreach ($data['distribution'] as $range => $count) {
                        $chartData[] = ['Range' => $range, 'Count' => $count];
                    }
                }
                break;
                
            case 'wcag_compliance':
                if (isset($data['by_level'])) {
                    foreach ($data['by_level'] as $level => $stats) {
                        $chartData[] = [
                            'Level' => $level,
                            'Total' => $stats['total'] ?? 0,
                            'Resolved' => $stats['resolved'] ?? 0,
                            'Compliance' => ($stats['compliance_percentage'] ?? 0) . '%'
                        ];
                    }
                }
                break;
                
            case 'severity_analysis':
                if (isset($data['distribution'])) {
                    foreach ($data['distribution'] as $severity => $count) {
                        $chartData[] = ['Severity' => $severity, 'Count' => $count];
                    }
                }
                break;
                
            default:
                // Generic extraction for other types
                if (isset($data['distribution'])) {
                    foreach ($data['distribution'] as $key => $value) {
                        $chartData[] = ['Category' => $key, 'Value' => $value];
                    }
                }
        }
        
        return $chartData;
    }
    
    /**
     * Calculate summary metrics from report data
     */
    private function calculateSummaryMetrics($reportData) {
        $metrics = [];
        
        // Total issues across all analytics
        $totalIssues = 0;
        foreach ($reportData as $data) {
            if (isset($data['total'])) {
                $totalIssues += $data['total'];
            }
        }
        
        if ($totalIssues > 0) {
            $metrics[] = [
                'name' => 'Total Issues Analyzed',
                'value' => $totalIssues,
                'description' => 'Total number of client-ready issues included in this report'
            ];
        }
        
        // User impact metrics
        if (isset($reportData['user_affected']['total_affected'])) {
            $metrics[] = [
                'name' => 'Total Users Affected',
                'value' => $reportData['user_affected']['total_affected'],
                'description' => 'Total number of users impacted by accessibility issues'
            ];
        }
        
        // WCAG compliance metrics
        if (isset($reportData['wcag_compliance']['overall_compliance'])) {
            $metrics[] = [
                'name' => 'Overall WCAG Compliance',
                'value' => $reportData['wcag_compliance']['overall_compliance'] . '%',
                'description' => 'Overall compliance percentage across all WCAG levels'
            ];
        }
        
        // Critical issues
        if (isset($reportData['severity_analysis']['distribution']['Critical'])) {
            $metrics[] = [
                'name' => 'Critical Issues',
                'value' => $reportData['severity_analysis']['distribution']['Critical'],
                'description' => 'Number of critical severity issues requiring immediate attention'
            ];
        }
        
        // Blocker issues
        if (isset($reportData['blocker_issues']['total_blockers'])) {
            $metrics[] = [
                'name' => 'Blocker Issues',
                'value' => $reportData['blocker_issues']['total_blockers'],
                'description' => 'Number of issues blocking accessibility compliance'
            ];
        }
        
        return $metrics;
    }
    
    /**
     * Generate analytics report data
     */
    private function generateReportData($reportType, $projectIds, $options) {
        // This would typically integrate with the analytics engine
        // For now, return mock data structure that matches expected format
        
        $mockData = [
            'user_affected' => [
                'distribution' => ['1-10' => 5, '11-50' => 3, '51-100' => 2, '100+' => 1],
                'total' => 11,
                'total_affected' => 250
            ],
            'wcag_compliance' => [
                'by_level' => [
                    'A' => ['total' => 20, 'resolved' => 18, 'compliance_percentage' => 90],
                    'AA' => ['total' => 15, 'resolved' => 12, 'compliance_percentage' => 80],
                    'AAA' => ['total' => 5, 'resolved' => 3, 'compliance_percentage' => 60]
                ],
                'overall_compliance' => 82.5,
                'common_violations' => [
                    ['guideline' => '1.4.3 Contrast', 'count' => 8, 'level' => 'AA'],
                    ['guideline' => '2.4.6 Headings', 'count' => 6, 'level' => 'AA']
                ]
            ],
            'severity_analysis' => [
                'distribution' => ['Critical' => 3, 'High' => 8, 'Medium' => 12, 'Low' => 7],
                'total' => 30
            ],
            'common_issues' => [
                'top_issues' => [
                    ['title' => 'Missing alt text', 'count' => 12, 'impact_potential' => 'High'],
                    ['title' => 'Low color contrast', 'count' => 8, 'impact_potential' => 'Medium']
                ]
            ],
            'blocker_issues' => [
                'blockers' => [
                    ['title' => 'Keyboard navigation broken', 'priority' => 'Critical', 'status' => 'Open', 'affected_functionality' => 'Navigation'],
                    ['title' => 'Screen reader incompatible', 'priority' => 'High', 'status' => 'In Progress', 'affected_functionality' => 'Content']
                ],
                'total_blockers' => 2
            ],
            'page_issues' => [
                'by_page' => [
                    ['page' => '/home', 'issue_count' => 5, 'density' => '2.5 issues/page', 'top_severity' => 'High'],
                    ['page' => '/contact', 'issue_count' => 3, 'density' => '1.5 issues/page', 'top_severity' => 'Medium']
                ]
            ],
            'commented_issues' => [
                'commented_issues' => [
                    ['title' => 'Form validation issues', 'comment_count' => 4, 'last_activity' => '2024-01-15', 'engagement_level' => 'High'],
                    ['title' => 'Image accessibility', 'comment_count' => 2, 'last_activity' => '2024-01-10', 'engagement_level' => 'Medium']
                ]
            ],
            'compliance_trend' => [
                'trend_data' => [
                    ['period' => '2024-01', 'total_issues' => 35, 'resolved' => 28, 'new_issues' => 5, 'compliance_percentage' => 80],
                    ['period' => '2024-02', 'total_issues' => 32, 'resolved' => 26, 'new_issues' => 3, 'compliance_percentage' => 81.25]
                ]
            ]
        ];
        
        // Return specific report type data or all data
        if ($reportType === 'unified_dashboard') {
            return $mockData;
        } else {
            return isset($mockData[$reportType]) ? [$reportType => $mockData[$reportType]] : $mockData;
        }
    }
    
    /**
     * Generate Excel filename
     */
    private function generateExcelFilename($reportType, $projectIds, $requestId, $options) {
        $format = isset($options['format']) && $options['format'] === 'xls' ? 'xls' : 'xlsx';
        $timestamp = date('Y-m-d_H-i-s');
        $projectsStr = count($projectIds) > 1 ? 'multi-project' : 'project-' . $projectIds[0];
        
        return "analytics-{$reportType}-{$projectsStr}-{$timestamp}-{$requestId}.{$format}";
    }
    
    /**
     * Save Excel file
     */
    private function saveExcel($filePath, $options) {
        $format = isset($options['format']) && $options['format'] === 'xls' ? 'xls' : 'xlsx';
        
        try {
            if ($format === 'xls') {
                $writerClass = '\PhpOffice\PhpSpreadsheet\Writer\Xls';
                $writer = new $writerClass($this->spreadsheet);
            } else {
                $writerClass = '\PhpOffice\PhpSpreadsheet\Writer\Xlsx';
                $writer = new $writerClass($this->spreadsheet);
            }
            
            $writer->save($filePath);
            
            // Set appropriate file permissions
            chmod($filePath, 0640);
            
        } catch (Exception $e) {
            throw new Exception('Failed to save Excel file: ' . $e->getMessage());
        }
    }
    
    /**
     * Sanitize worksheet name for Excel compatibility
     */
    private function sanitizeSheetName($name) {
        // Excel worksheet names cannot exceed 31 characters and cannot contain certain characters
        $sanitized = preg_replace('/[\\\\\/\?\*\[\]:]+/', '_', $name);
        return substr($sanitized, 0, 31);
    }
    
    /**
     * Load format configuration
     */
    private function loadFormatConfig() {
        $this->formatConfig = [
            'date_format' => 'Y-m-d H:i:s',
            'number_format' => '#,##0',
            'percentage_format' => '0.0%',
            'currency_format' => '$#,##0.00'
        ];
    }
}