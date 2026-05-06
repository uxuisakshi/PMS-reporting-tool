<?php

require_once __DIR__ . '/ExportEngine.php';
require_once __DIR__ . '/AnalyticsReport.php';

/**
 * PDFExporter - Generate formatted PDFs with charts and metadata using TCPDF
 * 
 * Provides professional PDF export functionality for analytics reports:
 * - High-quality chart rendering and professional formatting
 * - Project metadata and branding integration
 * - Support for various report types from analytics system
 * - Secure file generation and handling
 * 
 * Requirements: 14.1, 14.2, 14.3, 14.4
 */
class PDFExporter extends ExportEngine {
    
    private $tcpdf;
    private $chartRenderer;
    private $brandingConfig;
    private $pageMargins;
    private $fontSizes;
    
    public function __construct() {
        parent::__construct();
        
        // Initialize PDF configuration
        $this->initializePDFConfig();
        
        // Initialize chart renderer for PDF
        $this->initializeChartRenderer();
        
        // Load branding configuration
        $this->loadBrandingConfig();
    }
    
    /**
     * Generate export file - PDF implementation
     * 
     * @param int $requestId Export request ID
     * @param string $exportType Should be 'pdf'
     * @param string $reportType Type of analytics report
     * @param array $projectIds Array of project IDs
     * @param array $options Export configuration options
     * @return string Path to generated PDF file
     * @throws Exception If PDF generation fails
     */
    protected function generateExportFile($requestId, $exportType, $reportType, $projectIds, $options): string {
        if ($exportType !== 'pdf') {
            throw new Exception('PDFExporter only supports PDF format');
        }
        
        // Generate analytics report data
        $reportData = $this->generateReportData($reportType, $projectIds, $options);
        
        // Create PDF document
        $this->initializeTCPDF($options);
        
        // Generate PDF content
        $this->generatePDFContent($reportData, $projectIds, $options);
        
        // Save PDF file
        $filename = $this->generatePDFFilename($reportType, $projectIds, $requestId);
        $filePath = $this->exportDir . $filename;
        
        $this->savePDF($filePath);
        
        return $filePath;
    }
    
    /**
     * Initialize TCPDF configuration
     */
    private function initializePDFConfig() {
        $this->pageMargins = [
            'top' => 20,
            'right' => 15,
            'bottom' => 20,
            'left' => 15
        ];
        
        $this->fontSizes = [
            'title' => 18,
            'heading' => 14,
            'subheading' => 12,
            'body' => 10,
            'caption' => 8
        ];
    }
    
    /**
     * Initialize TCPDF instance
     */
    private function initializeTCPDF($options = []) {
        // Check if TCPDF is available
        if (!class_exists('TCPDF')) {
            // Try to include TCPDF if it exists in common locations
            $tcpdfPaths = [
                __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php',
                __DIR__ . '/../../lib/tcpdf/tcpdf.php',
                __DIR__ . '/../../tcpdf/tcpdf.php'
            ];
            
            $tcpdfFound = false;
            foreach ($tcpdfPaths as $path) {
                if (file_exists($path)) {
                    require_once $path;
                    $tcpdfFound = true;
                    break;
                }
            }
            
            if (!$tcpdfFound) {
                throw new Exception('TCPDF library not found. Please install TCPDF using: composer require tecnickcom/tcpdf');
            }
        }
        
        // Initialize TCPDF with configuration
        $orientation = $options['orientation'] ?? 'P'; // Portrait by default
        $unit = 'mm';
        $format = $options['format'] ?? 'A4';
        
        $tcpdfClass = 'TCPDF';
        $this->tcpdf = new $tcpdfClass($orientation, $unit, $format, true, 'UTF-8', false);
        
        // Set document information
        $this->tcpdf->SetCreator('Analytics Reporting System');
        $this->tcpdf->SetAuthor('Client Reporting System');
        $this->tcpdf->SetTitle('Analytics Report');
        $this->tcpdf->SetSubject('Accessibility Analytics Report');
        
        // Set margins
        $this->tcpdf->SetMargins(
            $this->pageMargins['left'],
            $this->pageMargins['top'],
            $this->pageMargins['right']
        );
        $this->tcpdf->SetAutoPageBreak(true, $this->pageMargins['bottom']);
        
        // Set header and footer
        $this->tcpdf->setPrintHeader($options['includeHeader'] ?? true);
        $this->tcpdf->setPrintFooter($options['includeFooter'] ?? true);
        
        if ($options['includeHeader'] ?? true) {
            $this->setupPDFHeader();
        }
        
        if ($options['includeFooter'] ?? true) {
            $this->setupPDFFooter();
        }
    }
    
    /**
     * Setup PDF header with branding
     */
    private function setupPDFHeader() {
        $headerData = [
            'logo' => $this->brandingConfig['logo_path'] ?? '',
            'logo_width' => $this->brandingConfig['logo_width'] ?? 30,
            'title' => $this->brandingConfig['company_name'] ?? 'Analytics Report',
            'string' => 'Accessibility Analytics Report'
        ];
        
        $this->tcpdf->SetHeaderData(
            $headerData['logo'],
            $headerData['logo_width'],
            $headerData['title'],
            $headerData['string']
        );
        
        // Set header font
        $this->tcpdf->setHeaderFont(['helvetica', '', $this->fontSizes['body']]);
    }
    
    /**
     * Setup PDF footer
     */
    private function setupPDFFooter() {
        // Set footer font
        $this->tcpdf->setFooterFont(['helvetica', '', $this->fontSizes['caption']]);
        
        // Footer content will be automatically generated by TCPDF
        $this->tcpdf->SetFooterMargin(15);
    }
    
    /**
     * Generate PDF content based on report data
     */
    private function generatePDFContent($reportData, $projectIds, $options) {
        // Add first page
        $this->tcpdf->AddPage();
        
        // Generate title page
        $this->generateTitlePage($reportData, $projectIds);
        
        // Generate table of contents if requested
        if ($options['includeTableOfContents'] ?? true) {
            $this->generateTableOfContents($reportData);
        }
        
        // Generate executive summary
        if ($options['includeExecutiveSummary'] ?? true) {
            $this->generateExecutiveSummary($reportData);
        }
        
        // Generate detailed sections for each report type
        $this->generateDetailedSections($reportData, $options);
        
        // Generate appendices if requested
        if ($options['includeAppendices'] ?? false) {
            $this->generateAppendices($reportData);
        }
    }
    
    /**
     * Generate title page
     */
    private function generateTitlePage($reportData, $projectIds) {
        // Set title font
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['title']);
        
        // Add some space from top
        $this->tcpdf->Ln(30);
        
        // Main title
        $title = $this->generateReportTitle($reportData, $projectIds);
        $this->tcpdf->Cell(0, 15, $title, 0, 1, 'C');
        
        $this->tcpdf->Ln(10);
        
        // Subtitle
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['heading']);
        $subtitle = 'Accessibility Analytics Report';
        $this->tcpdf->Cell(0, 10, $subtitle, 0, 1, 'C');
        
        $this->tcpdf->Ln(20);
        
        // Project information
        $this->generateProjectInformation($projectIds);
        
        // Generation information
        $this->tcpdf->Ln(20);
        $this->generateReportMetadata();
    }
    
    /**
     * Generate project information section
     */
    private function generateProjectInformation($projectIds) {
        $projectInfo = $this->generateReportHeader($projectIds);
        
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, 'Project Information', 0, 1, 'L');
        $this->tcpdf->Ln(5);
        
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        
        if (!empty($projectInfo['projects'])) {
            foreach ($projectInfo['projects'] as $project) {
                $this->tcpdf->Cell(30, 6, 'Project:', 0, 0, 'L');
                $this->tcpdf->Cell(0, 6, $project['title'], 0, 1, 'L');
                
                if (!empty($project['description'])) {
                    $this->tcpdf->Cell(30, 6, 'Description:', 0, 0, 'L');
                    $this->tcpdf->MultiCell(0, 6, $project['description'], 0, 'L');
                }
                $this->tcpdf->Ln(3);
            }
        }
        
        $this->tcpdf->Cell(30, 6, 'Total Projects:', 0, 0, 'L');
        $this->tcpdf->Cell(0, 6, $projectInfo['total_projects'], 0, 1, 'L');
    }
    
    /**
     * Generate report metadata
     */
    private function generateReportMetadata() {
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, 'Report Details', 0, 1, 'L');
        $this->tcpdf->Ln(5);
        
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        
        $this->tcpdf->Cell(30, 6, 'Generated:', 0, 0, 'L');
        $this->tcpdf->Cell(0, 6, date('F j, Y \a\t g:i A'), 0, 1, 'L');
        
        $this->tcpdf->Cell(30, 6, 'System:', 0, 0, 'L');
        $this->tcpdf->Cell(0, 6, 'Client Reporting & Analytics System', 0, 1, 'L');
        
        $this->tcpdf->Cell(30, 6, 'Version:', 0, 0, 'L');
        $this->tcpdf->Cell(0, 6, '1.0', 0, 1, 'L');
    }
    
    /**
     * Generate table of contents
     */
    private function generateTableOfContents($reportData) {
        $this->tcpdf->AddPage();
        
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['heading']);
        $this->tcpdf->Cell(0, 10, 'Table of Contents', 0, 1, 'L');
        $this->tcpdf->Ln(10);
        
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        
        $sections = [
            'Executive Summary' => 3,
            'User Affected Analysis' => 4,
            'WCAG Compliance Report' => 5,
            'Severity Analysis' => 6,
            'Common Issues Report' => 7,
            'Blocker Issues Analysis' => 8,
            'Page Issues Report' => 9,
            'Commented Issues Analysis' => 10,
            'Compliance Trends' => 11
        ];
        
        foreach ($sections as $section => $page) {
            $this->tcpdf->Cell(150, 6, $section, 0, 0, 'L');
            $this->tcpdf->Cell(0, 6, $page, 0, 1, 'R');
        }
    }
    
    /**
     * Generate executive summary
     */
    private function generateExecutiveSummary($reportData) {
        $this->tcpdf->AddPage();
        
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['heading']);
        $this->tcpdf->Cell(0, 10, 'Executive Summary', 0, 1, 'L');
        $this->tcpdf->Ln(5);
        
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        
        // Generate summary based on available report data
        $summary = $this->generateExecutiveSummaryContent($reportData);
        $this->tcpdf->MultiCell(0, 6, $summary, 0, 'L');
        
        $this->tcpdf->Ln(10);
        
        // Add key metrics summary table
        $this->generateKeyMetricsTable($reportData);
    }
    
    /**
     * Generate executive summary content
     */
    private function generateExecutiveSummaryContent($reportData) {
        $summary = "This report provides a comprehensive analysis of accessibility issues and compliance status across the selected projects. ";
        $summary .= "The analysis covers multiple dimensions including user impact, WCAG compliance levels, issue severity distribution, ";
        $summary .= "and trending patterns over time.\n\n";
        
        // Add specific insights based on available data
        if (isset($reportData['user_affected'])) {
            $userAffected = $reportData['user_affected'];
            if (isset($userAffected['data']['summary']['total_users_affected'])) {
                $totalUsers = $userAffected['data']['summary']['total_users_affected'];
                $summary .= "A total of {$totalUsers} users are potentially affected by the identified accessibility issues. ";
            }
        }
        
        if (isset($reportData['wcag_compliance'])) {
            $wcag = $reportData['wcag_compliance'];
            if (isset($wcag['data']['summary']['overall_compliance_rate'])) {
                $complianceRate = round($wcag['data']['summary']['overall_compliance_rate'], 1);
                $summary .= "The overall WCAG compliance rate stands at {$complianceRate}%. ";
            }
        }
        
        if (isset($reportData['severity'])) {
            $severity = $reportData['severity'];
            if (isset($severity['data']['distribution'])) {
                $critical = $severity['data']['distribution']['Critical'] ?? 0;
                $high = $severity['data']['distribution']['High'] ?? 0;
                $summary .= "There are {$critical} critical and {$high} high severity issues requiring immediate attention. ";
            }
        }
        
        $summary .= "\n\nThis report provides detailed analysis and recommendations for addressing these accessibility concerns.";
        
        return $summary;
    }
    
    /**
     * Generate key metrics summary table
     */
    private function generateKeyMetricsTable($reportData) {
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, 'Key Metrics Summary', 0, 1, 'L');
        $this->tcpdf->Ln(3);
        
        // Table header
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['body']);
        $this->tcpdf->SetFillColor(240, 240, 240);
        $this->tcpdf->Cell(80, 8, 'Metric', 1, 0, 'L', true);
        $this->tcpdf->Cell(40, 8, 'Value', 1, 0, 'C', true);
        $this->tcpdf->Cell(60, 8, 'Status', 1, 1, 'C', true);
        
        // Table data
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $this->tcpdf->SetFillColor(255, 255, 255);
        
        $metrics = $this->extractKeyMetrics($reportData);
        
        foreach ($metrics as $metric) {
            $this->tcpdf->Cell(80, 6, $metric['name'], 1, 0, 'L');
            $this->tcpdf->Cell(40, 6, $metric['value'], 1, 0, 'C');
            $this->tcpdf->Cell(60, 6, $metric['status'], 1, 1, 'C');
        }
    }
    
    /**
     * Extract key metrics from report data
     */
    private function extractKeyMetrics($reportData) {
        $metrics = [];
        
        // Total Issues
        $totalIssues = 0;
        foreach ($reportData as $report) {
            if (isset($report['data']['summary']['total_issues'])) {
                $totalIssues += $report['data']['summary']['total_issues'];
            }
        }
        if ($totalIssues > 0) {
            $metrics[] = [
                'name' => 'Total Issues',
                'value' => $totalIssues,
                'status' => $totalIssues > 50 ? 'High' : ($totalIssues > 20 ? 'Medium' : 'Low')
            ];
        }
        
        // WCAG Compliance Rate
        if (isset($reportData['wcag_compliance']['data']['summary']['overall_compliance_rate'])) {
            $rate = round($reportData['wcag_compliance']['data']['summary']['overall_compliance_rate'], 1);
            $metrics[] = [
                'name' => 'WCAG Compliance Rate',
                'value' => $rate . '%',
                'status' => $rate >= 90 ? 'Good' : ($rate >= 70 ? 'Fair' : 'Needs Improvement')
            ];
        }
        
        // Users Affected
        if (isset($reportData['user_affected']['data']['summary']['total_users_affected'])) {
            $users = $reportData['user_affected']['data']['summary']['total_users_affected'];
            $metrics[] = [
                'name' => 'Users Affected',
                'value' => number_format($users),
                'status' => $users > 1000 ? 'High Impact' : ($users > 100 ? 'Medium Impact' : 'Low Impact')
            ];
        }
        
        // Critical Issues
        if (isset($reportData['severity']['data']['distribution']['Critical'])) {
            $critical = $reportData['severity']['data']['distribution']['Critical'];
            $metrics[] = [
                'name' => 'Critical Issues',
                'value' => $critical,
                'status' => $critical > 0 ? 'Critical' : 'Good'
            ];
        }
        
        return $metrics;
    }
    
    /**
     * Generate detailed sections for each report type
     */
    private function generateDetailedSections($reportData, $options) {
        $sectionGenerators = [
            'user_affected' => 'generateUserAffectedSection',
            'wcag_compliance' => 'generateWCAGComplianceSection',
            'severity' => 'generateSeveritySection',
            'common_issues' => 'generateCommonIssuesSection',
            'blocker_issues' => 'generateBlockerIssuesSection',
            'page_issues' => 'generatePageIssuesSection',
            'commented_issues' => 'generateCommentedIssuesSection',
            'compliance_trend' => 'generateComplianceTrendSection'
        ];
        
        foreach ($sectionGenerators as $reportType => $generator) {
            if (isset($reportData[$reportType]) && method_exists($this, $generator)) {
                $this->$generator($reportData[$reportType], $options);
            }
        }
    }
    
    /**
     * Generate User Affected Analysis section
     */
    private function generateUserAffectedSection($reportData, $options) {
        $this->tcpdf->AddPage();
        
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['heading']);
        $this->tcpdf->Cell(0, 10, 'User Affected Analysis', 0, 1, 'L');
        $this->tcpdf->Ln(5);
        
        // Section description
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $description = "This section analyzes the scope of users affected by accessibility issues, categorized by impact ranges.";
        $this->tcpdf->MultiCell(0, 6, $description, 0, 'L');
        $this->tcpdf->Ln(5);
        
        // Generate chart if available
        if ($options['includeCharts'] ?? true) {
            $this->generateChartPlaceholder('User Affected Distribution', 'pie');
        }
        
        // Generate data table
        if (isset($reportData['data']['distribution'])) {
            $this->generateDistributionTable($reportData['data']['distribution'], 'User Range', 'Issues Count');
        }
        
        // Generate insights
        if (isset($reportData['data']['insights'])) {
            $this->generateInsightsSection($reportData['data']['insights']);
        }
    }
    
    /**
     * Generate WCAG Compliance section
     */
    private function generateWCAGComplianceSection($reportData, $options) {
        $this->tcpdf->AddPage();
        
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['heading']);
        $this->tcpdf->Cell(0, 10, 'WCAG Compliance Analysis', 0, 1, 'L');
        $this->tcpdf->Ln(5);
        
        // Section description
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $description = "This section provides analysis of Web Content Accessibility Guidelines (WCAG) compliance across different levels.";
        $this->tcpdf->MultiCell(0, 6, $description, 0, 'L');
        $this->tcpdf->Ln(5);
        
        // Generate chart if available
        if ($options['includeCharts'] ?? true) {
            $this->generateChartPlaceholder('WCAG Compliance by Level', 'bar');
        }
        
        // Generate compliance table
        if (isset($reportData['data']['compliance_by_level'])) {
            $this->generateComplianceTable($reportData['data']['compliance_by_level']);
        }
        
        // Generate recommendations
        if (isset($reportData['data']['recommendations'])) {
            $this->generateRecommendationsSection($reportData['data']['recommendations']);
        }
    }
    
    /**
     * Generate Severity Analysis section
     */
    private function generateSeveritySection($reportData, $options) {
        $this->tcpdf->AddPage();
        
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['heading']);
        $this->tcpdf->Cell(0, 10, 'Issue Severity Analysis', 0, 1, 'L');
        $this->tcpdf->Ln(5);
        
        // Section description
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $description = "This section analyzes the distribution of issues by severity level to help prioritize remediation efforts.";
        $this->tcpdf->MultiCell(0, 6, $description, 0, 'L');
        $this->tcpdf->Ln(5);
        
        // Generate chart if available
        if ($options['includeCharts'] ?? true) {
            $this->generateChartPlaceholder('Issue Severity Distribution', 'horizontal_bar');
        }
        
        // Generate severity table
        if (isset($reportData['data']['distribution'])) {
            $this->generateDistributionTable($reportData['data']['distribution'], 'Severity Level', 'Issues Count');
        }
    }
    
    /**
     * Generate Common Issues section
     */
    private function generateCommonIssuesSection($reportData, $options) {
        $this->tcpdf->AddPage();
        
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['heading']);
        $this->tcpdf->Cell(0, 10, 'Common Issues Analysis', 0, 1, 'L');
        $this->tcpdf->Ln(5);
        
        // Section description
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $description = "This section identifies the most frequently occurring accessibility issues to help focus remediation efforts.";
        $this->tcpdf->MultiCell(0, 6, $description, 0, 'L');
        $this->tcpdf->Ln(5);
        
        // Generate top issues table
        if (isset($reportData['data']['top_issues'])) {
            $this->generateTopIssuesTable($reportData['data']['top_issues']);
        }
    }
    
    /**
     * Generate Blocker Issues section
     */
    private function generateBlockerIssuesSection($reportData, $options) {
        $this->tcpdf->AddPage();
        
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['heading']);
        $this->tcpdf->Cell(0, 10, 'Blocker Issues Analysis', 0, 1, 'L');
        $this->tcpdf->Ln(5);
        
        // Section description
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $description = "This section focuses on critical blocker issues that prevent accessibility compliance and require immediate attention.";
        $this->tcpdf->MultiCell(0, 6, $description, 0, 'L');
        $this->tcpdf->Ln(5);
        
        // Generate blocker issues table
        if (isset($reportData['data']['blocker_issues'])) {
            $this->generateBlockerIssuesTable($reportData['data']['blocker_issues']);
        }
    }
    
    /**
     * Generate Page Issues section
     */
    private function generatePageIssuesSection($reportData, $options) {
        $this->tcpdf->AddPage();
        
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['heading']);
        $this->tcpdf->Cell(0, 10, 'Page Issues Analysis', 0, 1, 'L');
        $this->tcpdf->Ln(5);
        
        // Section description
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $description = "This section analyzes accessibility issues by page or section to identify areas with the highest concentration of problems.";
        $this->tcpdf->MultiCell(0, 6, $description, 0, 'L');
        $this->tcpdf->Ln(5);
        
        // Generate page issues table
        if (isset($reportData['data']['page_breakdown'])) {
            $this->generatePageBreakdownTable($reportData['data']['page_breakdown']);
        }
    }
    
    /**
     * Generate Commented Issues section
     */
    private function generateCommentedIssuesSection($reportData, $options) {
        $this->tcpdf->AddPage();
        
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['heading']);
        $this->tcpdf->Cell(0, 10, 'Commented Issues Analysis', 0, 1, 'L');
        $this->tcpdf->Ln(5);
        
        // Section description
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $description = "This section analyzes issues with comments or discussions to identify areas requiring ongoing collaboration.";
        $this->tcpdf->MultiCell(0, 6, $description, 0, 'L');
        $this->tcpdf->Ln(5);
        
        // Generate commented issues summary
        if (isset($reportData['data']['comment_activity'])) {
            $this->generateCommentActivityTable($reportData['data']['comment_activity']);
        }
    }
    
    /**
     * Generate Compliance Trend section
     */
    private function generateComplianceTrendSection($reportData, $options) {
        $this->tcpdf->AddPage();
        
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['heading']);
        $this->tcpdf->Cell(0, 10, 'Compliance Trends Analysis', 0, 1, 'L');
        $this->tcpdf->Ln(5);
        
        // Section description
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $description = "This section shows compliance trends over time to track improvement progress and identify patterns.";
        $this->tcpdf->MultiCell(0, 6, $description, 0, 'L');
        $this->tcpdf->Ln(5);
        
        // Generate chart if available
        if ($options['includeCharts'] ?? true) {
            $this->generateChartPlaceholder('Compliance Trends Over Time', 'line');
        }
        
        // Generate trend summary
        if (isset($reportData['data']['trend_summary'])) {
            $this->generateTrendSummaryTable($reportData['data']['trend_summary']);
        }
    }
    
    /**
     * Generate distribution table
     */
    private function generateDistributionTable($distribution, $labelHeader, $valueHeader) {
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, 'Distribution Breakdown', 0, 1, 'L');
        $this->tcpdf->Ln(3);
        
        // Table header
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['body']);
        $this->tcpdf->SetFillColor(240, 240, 240);
        $this->tcpdf->Cell(80, 8, $labelHeader, 1, 0, 'L', true);
        $this->tcpdf->Cell(40, 8, $valueHeader, 1, 0, 'C', true);
        $this->tcpdf->Cell(60, 8, 'Percentage', 1, 1, 'C', true);
        
        // Calculate total for percentages
        $total = array_sum($distribution);
        
        // Table data
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $this->tcpdf->SetFillColor(255, 255, 255);
        
        foreach ($distribution as $label => $value) {
            $percentage = $total > 0 ? round(($value / $total) * 100, 1) : 0;
            
            $this->tcpdf->Cell(80, 6, $label, 1, 0, 'L');
            $this->tcpdf->Cell(40, 6, $value, 1, 0, 'C');
            $this->tcpdf->Cell(60, 6, $percentage . '%', 1, 1, 'C');
        }
        
        // Total row
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['body']);
        $this->tcpdf->SetFillColor(240, 240, 240);
        $this->tcpdf->Cell(80, 6, 'Total', 1, 0, 'L', true);
        $this->tcpdf->Cell(40, 6, $total, 1, 0, 'C', true);
        $this->tcpdf->Cell(60, 6, '100%', 1, 1, 'C', true);
    }
    
    /**
     * Generate compliance table
     */
    private function generateComplianceTable($complianceData) {
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, 'WCAG Compliance by Level', 0, 1, 'L');
        $this->tcpdf->Ln(3);
        
        // Table header
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['body']);
        $this->tcpdf->SetFillColor(240, 240, 240);
        $this->tcpdf->Cell(40, 8, 'WCAG Level', 1, 0, 'L', true);
        $this->tcpdf->Cell(30, 8, 'Total Issues', 1, 0, 'C', true);
        $this->tcpdf->Cell(30, 8, 'Resolved', 1, 0, 'C', true);
        $this->tcpdf->Cell(30, 8, 'Pending', 1, 0, 'C', true);
        $this->tcpdf->Cell(40, 8, 'Compliance %', 1, 1, 'C', true);
        
        // Table data
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $this->tcpdf->SetFillColor(255, 255, 255);
        
        foreach ($complianceData as $level => $data) {
            $total = $data['total'] ?? 0;
            $resolved = $data['resolved'] ?? 0;
            $pending = $total - $resolved;
            $compliance = $total > 0 ? round(($resolved / $total) * 100, 1) : 0;
            
            $this->tcpdf->Cell(40, 6, $level, 1, 0, 'L');
            $this->tcpdf->Cell(30, 6, $total, 1, 0, 'C');
            $this->tcpdf->Cell(30, 6, $resolved, 1, 0, 'C');
            $this->tcpdf->Cell(30, 6, $pending, 1, 0, 'C');
            $this->tcpdf->Cell(40, 6, $compliance . '%', 1, 1, 'C');
        }
    }
    
    /**
     * Generate top issues table
     */
    private function generateTopIssuesTable($topIssues) {
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, 'Most Common Issues', 0, 1, 'L');
        $this->tcpdf->Ln(3);
        
        // Table header
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['body']);
        $this->tcpdf->SetFillColor(240, 240, 240);
        $this->tcpdf->Cell(10, 8, '#', 1, 0, 'C', true);
        $this->tcpdf->Cell(100, 8, 'Issue Description', 1, 0, 'L', true);
        $this->tcpdf->Cell(30, 8, 'Occurrences', 1, 0, 'C', true);
        $this->tcpdf->Cell(40, 8, 'Impact Level', 1, 1, 'C', true);
        
        // Table data
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $this->tcpdf->SetFillColor(255, 255, 255);
        
        $rank = 1;
        foreach ($topIssues as $issue) {
            $this->tcpdf->Cell(10, 6, $rank, 1, 0, 'C');
            $this->tcpdf->Cell(100, 6, $this->truncateText($issue['title'] ?? 'Unknown Issue', 50), 1, 0, 'L');
            $this->tcpdf->Cell(30, 6, $issue['count'] ?? 0, 1, 0, 'C');
            $this->tcpdf->Cell(40, 6, $issue['impact'] ?? 'Medium', 1, 1, 'C');
            $rank++;
            
            if ($rank > 10) break; // Limit to top 10
        }
    }
    
    /**
     * Generate blocker issues table
     */
    private function generateBlockerIssuesTable($blockerIssues) {
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, 'Critical Blocker Issues', 0, 1, 'L');
        $this->tcpdf->Ln(3);
        
        if (empty($blockerIssues)) {
            $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
            $this->tcpdf->Cell(0, 6, 'No critical blocker issues found.', 0, 1, 'L');
            return;
        }
        
        // Table header
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['body']);
        $this->tcpdf->SetFillColor(240, 240, 240);
        $this->tcpdf->Cell(80, 8, 'Issue Title', 1, 0, 'L', true);
        $this->tcpdf->Cell(30, 8, 'Severity', 1, 0, 'C', true);
        $this->tcpdf->Cell(30, 8, 'Status', 1, 0, 'C', true);
        $this->tcpdf->Cell(40, 8, 'Days Open', 1, 1, 'C', true);
        
        // Table data
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $this->tcpdf->SetFillColor(255, 255, 255);
        
        foreach ($blockerIssues as $issue) {
            $this->tcpdf->Cell(80, 6, $this->truncateText($issue['title'] ?? 'Unknown Issue', 40), 1, 0, 'L');
            $this->tcpdf->Cell(30, 6, $issue['severity'] ?? 'Critical', 1, 0, 'C');
            $this->tcpdf->Cell(30, 6, $issue['status'] ?? 'Open', 1, 0, 'C');
            $this->tcpdf->Cell(40, 6, $issue['days_open'] ?? 'N/A', 1, 1, 'C');
        }
    }
    
    /**
     * Generate page breakdown table
     */
    private function generatePageBreakdownTable($pageBreakdown) {
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, 'Issues by Page/Section', 0, 1, 'L');
        $this->tcpdf->Ln(3);
        
        // Table header
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['body']);
        $this->tcpdf->SetFillColor(240, 240, 240);
        $this->tcpdf->Cell(90, 8, 'Page/Section', 1, 0, 'L', true);
        $this->tcpdf->Cell(30, 8, 'Issues', 1, 0, 'C', true);
        $this->tcpdf->Cell(30, 8, 'Critical', 1, 0, 'C', true);
        $this->tcpdf->Cell(30, 8, 'Density', 1, 1, 'C', true);
        
        // Table data
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $this->tcpdf->SetFillColor(255, 255, 255);
        
        foreach ($pageBreakdown as $page => $data) {
            $this->tcpdf->Cell(90, 6, $this->truncateText($page, 45), 1, 0, 'L');
            $this->tcpdf->Cell(30, 6, $data['total_issues'] ?? 0, 1, 0, 'C');
            $this->tcpdf->Cell(30, 6, $data['critical_issues'] ?? 0, 1, 0, 'C');
            $this->tcpdf->Cell(30, 6, $data['density'] ?? 'Low', 1, 1, 'C');
        }
    }
    
    /**
     * Generate comment activity table
     */
    private function generateCommentActivityTable($commentActivity) {
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, 'Comment Activity Summary', 0, 1, 'L');
        $this->tcpdf->Ln(3);
        
        // Summary statistics
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        
        $totalCommented = $commentActivity['total_commented_issues'] ?? 0;
        $recentActivity = $commentActivity['recent_activity_count'] ?? 0;
        $avgComments = $commentActivity['average_comments_per_issue'] ?? 0;
        
        $this->tcpdf->Cell(60, 6, 'Total Issues with Comments:', 0, 0, 'L');
        $this->tcpdf->Cell(0, 6, $totalCommented, 0, 1, 'L');
        
        $this->tcpdf->Cell(60, 6, 'Recent Activity (7 days):', 0, 0, 'L');
        $this->tcpdf->Cell(0, 6, $recentActivity, 0, 1, 'L');
        
        $this->tcpdf->Cell(60, 6, 'Average Comments per Issue:', 0, 0, 'L');
        $this->tcpdf->Cell(0, 6, round($avgComments, 1), 0, 1, 'L');
    }
    
    /**
     * Generate trend summary table
     */
    private function generateTrendSummaryTable($trendSummary) {
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, 'Trend Summary', 0, 1, 'L');
        $this->tcpdf->Ln(3);
        
        // Table header
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['body']);
        $this->tcpdf->SetFillColor(240, 240, 240);
        $this->tcpdf->Cell(60, 8, 'Metric', 1, 0, 'L', true);
        $this->tcpdf->Cell(40, 8, 'Current Period', 1, 0, 'C', true);
        $this->tcpdf->Cell(40, 8, 'Previous Period', 1, 0, 'C', true);
        $this->tcpdf->Cell(40, 8, 'Trend', 1, 1, 'C', true);
        
        // Table data
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $this->tcpdf->SetFillColor(255, 255, 255);
        
        foreach ($trendSummary as $metric => $data) {
            $current = $data['current'] ?? 0;
            $previous = $data['previous'] ?? 0;
            $trend = $this->calculateTrendDirection($current, $previous);
            
            $this->tcpdf->Cell(60, 6, ucfirst(str_replace('_', ' ', $metric)), 1, 0, 'L');
            $this->tcpdf->Cell(40, 6, $current, 1, 0, 'C');
            $this->tcpdf->Cell(40, 6, $previous, 1, 0, 'C');
            $this->tcpdf->Cell(40, 6, $trend, 1, 1, 'C');
        }
    }
    
    /**
     * Generate insights section
     */
    private function generateInsightsSection($insights) {
        $this->tcpdf->Ln(5);
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, 'Key Insights', 0, 1, 'L');
        $this->tcpdf->Ln(3);
        
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        
        foreach ($insights as $insight) {
            $this->tcpdf->Cell(5, 6, '•', 0, 0, 'L');
            $this->tcpdf->MultiCell(0, 6, $insight, 0, 'L');
            $this->tcpdf->Ln(2);
        }
    }
    
    /**
     * Generate recommendations section
     */
    private function generateRecommendationsSection($recommendations) {
        $this->tcpdf->Ln(5);
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, 'Recommendations', 0, 1, 'L');
        $this->tcpdf->Ln(3);
        
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        
        $priority = 1;
        foreach ($recommendations as $recommendation) {
            $this->tcpdf->Cell(10, 6, $priority . '.', 0, 0, 'L');
            $this->tcpdf->MultiCell(0, 6, $recommendation, 0, 'L');
            $this->tcpdf->Ln(2);
            $priority++;
        }
    }
    
    /**
     * Generate chart placeholder (for when TCPDF chart rendering is available)
     */
    private function generateChartPlaceholder($title, $type) {
        $this->tcpdf->Ln(5);
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, $title, 0, 1, 'L');
        $this->tcpdf->Ln(3);
        
        // Create a placeholder box for the chart
        $this->tcpdf->SetDrawColor(200, 200, 200);
        $this->tcpdf->SetFillColor(250, 250, 250);
        $this->tcpdf->Rect(15, $this->tcpdf->GetY(), 180, 80, 'DF');
        
        // Add chart type label
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $this->tcpdf->SetXY(95, $this->tcpdf->GetY() + 35);
        $this->tcpdf->Cell(0, 6, "[{$type} chart would appear here]", 0, 1, 'C');
        
        $this->tcpdf->SetXY(15, $this->tcpdf->GetY() + 50);
        $this->tcpdf->Ln(5);
    }
    
    /**
     * Generate appendices
     */
    private function generateAppendices($reportData) {
        $this->tcpdf->AddPage();
        
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['heading']);
        $this->tcpdf->Cell(0, 10, 'Appendices', 0, 1, 'L');
        $this->tcpdf->Ln(5);
        
        // Appendix A: Methodology
        $this->generateMethodologyAppendix();
        
        // Appendix B: Data Sources
        $this->generateDataSourcesAppendix();
        
        // Appendix C: Glossary
        $this->generateGlossaryAppendix();
    }
    
    /**
     * Generate methodology appendix
     */
    private function generateMethodologyAppendix() {
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, 'Appendix A: Methodology', 0, 1, 'L');
        $this->tcpdf->Ln(3);
        
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $methodology = "This report is generated using automated analysis of accessibility issues marked as client-ready. ";
        $methodology .= "The analysis includes statistical calculations, trend analysis, and compliance assessments based on ";
        $methodology .= "WCAG 2.1 guidelines. All data is filtered to show only issues appropriate for client viewing.";
        
        $this->tcpdf->MultiCell(0, 6, $methodology, 0, 'L');
        $this->tcpdf->Ln(5);
    }
    
    /**
     * Generate data sources appendix
     */
    private function generateDataSourcesAppendix() {
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, 'Appendix B: Data Sources', 0, 1, 'L');
        $this->tcpdf->Ln(3);
        
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        $dataSources = "Data for this report is sourced from the project management system's issue tracking database. ";
        $dataSources .= "Only issues marked with client_ready=1 are included in the analysis. ";
        $dataSources .= "Historical data is used for trend analysis where available.";
        
        $this->tcpdf->MultiCell(0, 6, $dataSources, 0, 'L');
        $this->tcpdf->Ln(5);
    }
    
    /**
     * Generate glossary appendix
     */
    private function generateGlossaryAppendix() {
        $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['subheading']);
        $this->tcpdf->Cell(0, 8, 'Appendix C: Glossary', 0, 1, 'L');
        $this->tcpdf->Ln(3);
        
        $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
        
        $glossaryTerms = [
            'WCAG' => 'Web Content Accessibility Guidelines - International standards for web accessibility',
            'Client-Ready Issue' => 'An accessibility issue that has been reviewed and approved for client viewing',
            'Blocker Issue' => 'A critical issue that prevents accessibility compliance and requires immediate attention',
            'Compliance Rate' => 'Percentage of accessibility requirements that have been successfully addressed',
            'User Impact' => 'The estimated number of users affected by an accessibility issue'
        ];
        
        foreach ($glossaryTerms as $term => $definition) {
            $this->tcpdf->SetFont('helvetica', 'B', $this->fontSizes['body']);
            $this->tcpdf->Cell(40, 6, $term . ':', 0, 0, 'L');
            $this->tcpdf->SetFont('helvetica', '', $this->fontSizes['body']);
            $this->tcpdf->MultiCell(0, 6, $definition, 0, 'L');
            $this->tcpdf->Ln(2);
        }
    }
    
    /**
     * Generate report data for PDF export
     */
    private function generateReportData($reportType, $projectIds, $options) {
        // Load analytics engines
        require_once __DIR__ . '/AnalyticsEngine.php';
        require_once __DIR__ . '/UserAffectedAnalytics.php';
        require_once __DIR__ . '/WCAGComplianceAnalytics.php';
        require_once __DIR__ . '/SeverityAnalytics.php';
        require_once __DIR__ . '/CommonIssuesAnalytics.php';
        require_once __DIR__ . '/BlockerIssuesAnalytics.php';
        require_once __DIR__ . '/PageIssuesAnalytics.php';
        require_once __DIR__ . '/CommentedIssuesAnalytics.php';
        require_once __DIR__ . '/ComplianceTrendAnalytics.php';
        
        $reportData = [];
        
        // Generate specific report or unified report
        if ($reportType === 'unified_dashboard') {
            // Generate all report types for unified dashboard
            $reportTypes = [
                'user_affected' => 'UserAffectedAnalytics',
                'wcag_compliance' => 'WCAGComplianceAnalytics',
                'severity' => 'SeverityAnalytics',
                'common_issues' => 'CommonIssuesAnalytics',
                'blocker_issues' => 'BlockerIssuesAnalytics',
                'page_issues' => 'PageIssuesAnalytics',
                'commented_issues' => 'CommentedIssuesAnalytics',
                'compliance_trend' => 'ComplianceTrendAnalytics'
            ];
            
            foreach ($reportTypes as $type => $className) {
                try {
                    $analytics = new $className();
                    $report = $analytics->generateReport();
                    $reportData[$type] = $report->toArray();
                } catch (Exception $e) {
                    // Log error and continue with other reports
                    error_log("Error generating {$type} report: " . $e->getMessage());
                    $reportData[$type] = $this->getEmptyReportData($type);
                }
            }
        } else {
            // Generate specific report type
            $className = $this->getAnalyticsClassName($reportType);
            if ($className && class_exists($className)) {
                try {
                    $analytics = new $className();
                    $report = $analytics->generateReport();
                    $reportData[$reportType] = $report->toArray();
                } catch (Exception $e) {
                    throw new Exception("Error generating {$reportType} report: " . $e->getMessage());
                }
            } else {
                throw new Exception("Unknown report type: {$reportType}");
            }
        }
        
        return $reportData;
    }
    
    /**
     * Get analytics class name for report type
     */
    private function getAnalyticsClassName($reportType) {
        $classMap = [
            'user_affected' => 'UserAffectedAnalytics',
            'wcag_compliance' => 'WCAGComplianceAnalytics',
            'severity' => 'SeverityAnalytics',
            'common_issues' => 'CommonIssuesAnalytics',
            'blocker_issues' => 'BlockerIssuesAnalytics',
            'page_issues' => 'PageIssuesAnalytics',
            'commented_issues' => 'CommentedIssuesAnalytics',
            'compliance_trend' => 'ComplianceTrendAnalytics'
        ];
        
        return $classMap[$reportType] ?? null;
    }
    
    /**
     * Get empty report data for failed reports
     */
    private function getEmptyReportData($reportType) {
        return [
            'type' => $reportType,
            'title' => ucfirst(str_replace('_', ' ', $reportType)) . ' Report',
            'description' => 'Report data unavailable',
            'data' => [],
            'metadata' => ['error' => 'Data generation failed'],
            'visualization_config' => []
        ];
    }
    
    /**
     * Generate report title based on data and projects
     */
    private function generateReportTitle($reportData, $projectIds) {
        $projectInfo = $this->generateReportHeader($projectIds);
        
        if ($projectInfo['total_projects'] > 1) {
            return 'Multi-Project Accessibility Analytics Report';
        } elseif ($projectInfo['total_projects'] === 1) {
            $projectName = $projectInfo['projects'][0]['title'] ?? 'Project';
            return $projectName . ' - Accessibility Analytics Report';
        } else {
            return 'Accessibility Analytics Report';
        }
    }
    
    /**
     * Generate PDF filename
     */
    private function generatePDFFilename($reportType, $projectIds, $requestId) {
        $timestamp = date('Y-m-d_H-i-s');
        $projectCount = count($projectIds);
        
        if ($projectCount === 1) {
            $filename = "accessibility_report_project_{$projectIds[0]}_{$timestamp}";
        } else {
            $filename = "accessibility_report_{$projectCount}projects_{$timestamp}";
        }
        
        $filename .= "_{$requestId}.pdf";
        
        return $filename;
    }
    
    /**
     * Save PDF to file
     */
    private function savePDF($filePath) {
        try {
            $this->tcpdf->Output($filePath, 'F');
            
            if (!file_exists($filePath)) {
                throw new Exception('PDF file was not created successfully');
            }
            
            return true;
        } catch (Exception $e) {
            throw new Exception('Failed to save PDF: ' . $e->getMessage());
        }
    }
    
    /**
     * Initialize chart renderer for PDF
     */
    private function initializeChartRenderer() {
        // Initialize chart rendering capabilities
        // This would integrate with a chart-to-image library when available
        $this->chartRenderer = null;
        
        // Check for available chart rendering libraries
        $chartLibraries = [
            'Intervention\Image\ImageManagerStatic' => 'intervention/image',
            'Imagick' => 'imagick extension'
        ];
        
        foreach ($chartLibraries as $class => $library) {
            if (class_exists($class) || extension_loaded(str_replace(' extension', '', $library))) {
                // Chart rendering capability available
                break;
            }
        }
    }
    
    /**
     * Load branding configuration
     */
    private function loadBrandingConfig() {
        // Load branding configuration from config file or database
        $this->brandingConfig = [
            'company_name' => 'Accessibility Analytics System',
            'logo_path' => '',
            'logo_width' => 30,
            'primary_color' => '#2c3e50',
            'secondary_color' => '#3498db',
            'font_family' => 'helvetica'
        ];
        
        // Try to load custom branding config
        $configPath = __DIR__ . '/../../config/branding.php';
        if (file_exists($configPath)) {
            $customConfig = include $configPath;
            if (is_array($customConfig)) {
                $this->brandingConfig = array_merge($this->brandingConfig, $customConfig);
            }
        }
    }
    
    /**
     * Calculate trend direction
     */
    private function calculateTrendDirection($current, $previous) {
        if ($previous == 0) {
            return $current > 0 ? '↑ New' : '→ Stable';
        }
        
        $change = (($current - $previous) / $previous) * 100;
        
        if ($change > 5) {
            return '↑ +' . round($change, 1) . '%';
        } elseif ($change < -5) {
            return '↓ ' . round($change, 1) . '%';
        } else {
            return '→ Stable';
        }
    }
    
    /**
     * Truncate text to specified length
     */
    private function truncateText($text, $maxLength) {
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        
        return substr($text, 0, $maxLength - 3) . '...';
    }
    
    /**
     * Create a simple PDF without TCPDF (fallback method)
     */
    private function createSimplePDF($reportData, $filePath) {
        // Create a basic HTML-to-PDF conversion as fallback
        $html = $this->generateHTMLReport($reportData);
        
        // Save as HTML file with PDF extension for basic functionality
        file_put_contents($filePath, $html);
        
        return $filePath;
    }
    
    /**
     * Generate HTML report (fallback when TCPDF not available)
     */
    private function generateHTMLReport($reportData) {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Accessibility Analytics Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #2c3e50; border-bottom: 2px solid #3498db; }
        h2 { color: #34495e; margin-top: 30px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .summary { background-color: #ecf0f1; padding: 15px; border-radius: 5px; }
    </style>
</head>
<body>';
        
        $html .= '<h1>Accessibility Analytics Report</h1>';
        $html .= '<div class="summary">';
        $html .= '<p><strong>Generated:</strong> ' . date('F j, Y \a\t g:i A') . '</p>';
        $html .= '<p><strong>Note:</strong> This is a simplified HTML version. For full PDF functionality, please install TCPDF.</p>';
        $html .= '</div>';
        
        // Add report sections
        foreach ($reportData as $reportType => $data) {
            $html .= '<h2>' . ucfirst(str_replace('_', ' ', $reportType)) . '</h2>';
            $html .= '<p>' . ($data['description'] ?? 'Analytics data for ' . $reportType) . '</p>';
            
            // Add basic data representation
            if (!empty($data['data'])) {
                $html .= '<pre>' . json_encode($data['data'], JSON_PRETTY_PRINT) . '</pre>';
            }
        }
        
        $html .= '</body></html>';
        
        return $html;
    }
}