<?php
/**
 * Welcome Email Template
 * Variables: $userName, $appUrl
 */
?>
<h2 style="color: #0755C6;">Hello <?php echo htmlspecialchars($userName); ?>,</h2>
<p>Welcome to <strong><?php echo htmlspecialchars($app_name ?? 'Project Management System'); ?></strong>! We're excited to have you on board.</p>

<div class="highlight-box">
    <p style="margin: 0; font-weight: 600;">Your account has been successfully created.</p>
    <p style="margin: 5px 0 0 0; color: #475569;">You can now log in to the system to access your project dashboard, view detailed accessibility reports, and collaborate with your team.</p>
</div>

<p>To get started, please click the button below to log in and set up your profile:</p>

<div style="text-align: center;">
    <a href="<?php echo $appUrl; ?>" class="button">Log In to Your Account</a>
</div>

<p style="margin-top: 30px;"><strong>Next Steps:</strong></p>
<ul style="color: #475569; padding-left: 20px;">
    <li>Complete your profile settings</li>
    <li>Enable Two-Factor Authentication for enhanced security</li>
    <li>Explore your assigned projects</li>
</ul>

<p>If you have any questions or need assistance, please reach out to your system administrator.</p>

<p>Best regards,<br>The <?php echo htmlspecialchars($app_name ?? 'PMS'); ?> Team</p>
