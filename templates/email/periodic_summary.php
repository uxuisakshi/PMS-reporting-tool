<?php
/**
 * Periodic Summary Report Template
 * Variables: $userName, $summaryData, $appUrl
 */
$period = $summaryData['period'] ?? 'weekly';
$periodTitle = ucfirst($period);
$totalIssues = $summaryData['total_issues'] ?? 0;
$resolvedIssues = $summaryData['resolved_issues'] ?? 0;
$criticalIssues = $summaryData['critical_issues'] ?? 0;
$projectCount = count($summaryData['projects'] ?? []);
$resolutionRate = $totalIssues > 0 ? round(($resolvedIssues / $totalIssues) * 100, 1) : 0;
?>
<h2 style="color: #0755C6;"><?php echo $periodTitle; ?> Analytics Summary</h2>
<p>Hello <?php echo htmlspecialchars($userName); ?>,</p>
<p>Here is your <?php echo $period; ?> accessibility progress report for your <?php echo $projectCount; ?> assigned projects.</p>

<!-- Metrics Overview -->
<table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 25px 0; background-color: #f8fafc; border-radius: 8px;">
    <tr>
        <td align="center" style="padding: 20px;">
            <div style="color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 700; margin-bottom: 5px;">Total Issues</div>
            <div style="font-size: 24px; font-weight: 700; color: #0f172a;"><?php echo $totalIssues; ?></div>
        </td>
        <td align="center" style="padding: 20px;">
            <div style="color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 700; margin-bottom: 5px;">Resolved</div>
            <div style="font-size: 24px; font-weight: 700; color: #16a34a;"><?php echo $resolvedIssues; ?></div>
        </td>
        <td align="center" style="padding: 20px;">
            <div style="color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 700; margin-bottom: 5px;">Critical</div>
            <div style="font-size: 24px; font-weight: 700; color: #dc2626;"><?php echo $criticalIssues; ?></div>
        </td>
        <td align="center" style="padding: 20px;">
            <div style="color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 700; margin-bottom: 5px;">Success Rate</div>
            <div style="font-size: 24px; font-weight: 700; color: #0755C6;"><?php echo $resolutionRate; ?>%</div>
        </td>
    </tr>
</table>

<!-- Project Breakdown -->
<h3 style="color: #0f172a; margin-top: 30px;">📋 Project Performance Breakdown</h3>
<table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse; margin-top: 10px;">
    <thead>
        <tr>
            <th align="left" style="padding: 12px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">PROJECT</th>
            <th align="center" style="padding: 12px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">ISSUES</th>
            <th align="center" style="padding: 12px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">RESOLVED</th>
            <th align="center" style="padding: 12px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">RATE</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach (($summaryData['projects'] ?? []) as $project): 
            $pRate = ($project['issues'] ?? 0) > 0 ? round(($project['resolved'] / $project['issues']) * 100, 1) : 0;
        ?>
        <tr>
            <td style="padding: 12px; border-bottom: 1px solid #e2e8f0; font-size: 14px; font-weight: 600;"><?php echo htmlspecialchars($project['title']); ?></td>
            <td align="center" style="padding: 12px; border-bottom: 1px solid #e2e8f0; font-size: 14px;"><?php echo $project['issues'] ?? 0; ?></td>
            <td align="center" style="padding: 12px; border-bottom: 1px solid #e2e8f0; font-size: 14px;"><?php echo $project['resolved'] ?? 0; ?></td>
            <td align="center" style="padding: 12px; border-bottom: 1px solid #e2e8f0; font-size: 14px; font-weight: 700; color: #0755C6;"><?php echo $pRate; ?>%</td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div style="text-align: center; margin-top: 40px;">
    <a href="<?php echo htmlspecialchars($appUrl); ?>" class="button">Access Full Dashboard Reports</a>
</div>

<p style="margin-top: 30px; font-size: 14px; color: #64748b;">This summary highlights key movements in your projects over the last period. Detailed technical breakdowns for each issue can be found in the reporting system.</p>
