<?php
/**
 * 2FA Security Reminder Template
 * Variables: $userName, $profileUrl, $appUrl
 */
?>
<div style="text-align: center; margin-bottom: 20px;">
    <div style="font-size: 48px; margin-bottom: 10px;">🛡️</div>
    <h2 style="color: #0755C6; margin: 0;">Secure Your Account</h2>
</div>

<p>Hello <?php echo htmlspecialchars($userName); ?>,</p>

<p>At <strong><?php echo htmlspecialchars($app_name ?? 'Athenaeum PMS'); ?></strong>, security is our top priority. To keep your account and project data safe, we highly recommend enabling <strong>Two-Factor Authentication (2FA)</strong>.</p>

<div class="highlight-box">
    <p style="margin: 0; font-weight: 600; color: #0f172a;">Why enable 2FA?</p>
    <p style="margin: 5px 0 0 0; color: #475569;">It adds an essential layer of protection. Even if someone obtains your password, they won't be able to access your account without a unique verification code from your mobile device.</p>
</div>

<p>It only takes 60 seconds to set up using Google Authenticator, Authy, or any standard TOTP app.</p>

<div style="text-align: center;">
    <a href="<?php echo htmlspecialchars($profileUrl); ?>" class="button">Enable 2FA Now</a>
</div>

<p style="margin-top: 30px;"><strong>Simple Setup Steps:</strong></p>
<ol style="color: #475569; line-height: 1.8;">
    <li>Go to your <strong>Profile Settings</strong></li>
    <li>Locate the <strong>Security</strong> section</li>
    <li>Click <strong>Setup 2FA</strong> and scan the QR code with your app</li>
    <li>Enter the verification code to confirm</li>
</ol>

<p style="margin-top: 30px; font-size: 14px; color: #64748b;">Protecting our clients' sensitive project information is a shared responsibility. Thank you for doing your part.</p>
