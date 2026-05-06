<?php
/**
 * Project Revocation Notification Template
 * Variables: $userName, $projectTitle, $adminName, $appUrl, $reason
 */
?>
<h2 style="color: #dc2626;">Project Access Updated</h2>
<p>Hello <?php echo htmlspecialchars($userName); ?>,</p>

<p>This automated message is to inform you that your access to the following project has been modified:</p>

<div class="highlight-box" style="border-left-color: #dc2626;">
    <p style="margin: 0; font-weight: 600;">📁 Project: <?php echo htmlspecialchars($projectTitle); ?></p>
    <p style="margin: 5px 0 0 0; color: #475569;"><strong>Effective Date:</strong> <?php echo date('F j, Y'); ?></p>
</div>

<?php if (!empty($reason)): ?>
<div style="background-color: #fff1f2; border: 1px solid #fecdd3; padding: 15px; border-radius: 6px; margin: 20px 0;">
    <h4 style="margin: 0 0 5px 0; color: #991b1b;">Reason for Change:</h4>
    <p style="margin: 0; color: #991b1b; font-style: italic;"><?php echo htmlspecialchars($reason); ?></p>
</div>
<?php endif; ?>

<p>If you were expecting this change, no action is required. You can still access your other projects and reports by logging in to the dashboard.</p>

<div style="text-align: center;">
    <a href="<?php echo htmlspecialchars($appUrl); ?>" class="button" style="background-color: #475569;">View Remaining Projects</a>
</div>

<p style="margin-top: 30px; font-size: 14px; color: #64748b;">If you believe this revocation was made in error or if you require access again, please contact <strong><?php echo htmlspecialchars($adminName); ?></strong> or your system administrator.</p>
