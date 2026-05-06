<?php
/**
 * Assignment Notification Template
 * Variables: $userName, $projects (array), $adminName, $appUrl
 */
?>
<h2 style="color: #0755C6;">Hello <?php echo htmlspecialchars($userName); ?>,</h2>
<p>You have been granted access to new projects in the <strong><?php echo htmlspecialchars($app_name ?? 'Project Management System'); ?></strong>.</p>

<div class="highlight-box">
    <h3 style="margin-bottom: 10px; color: #0755C6;">📋 Project Access Summary</h3>
    <p style="margin: 0; color: #475569;">Administrator <strong><?php echo htmlspecialchars($adminName); ?></strong> has assigned you to the following:</p>
    
    <div style="margin-top: 15px;">
        <?php foreach ($projects as $project): ?>
            <div style="background-color: #ffffff; padding: 12px; border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 8px;">
                <strong style="color: #0f172a;"><?php echo htmlspecialchars($project['title']); ?></strong>
                <?php if (!empty($project['project_code'])): ?>
                    <span style="color: #64748b; font-size: 13px; font-weight: normal;"> - #<?php echo htmlspecialchars($project['project_code']); ?></span>
                <?php endif; ?>
                <?php if (!empty($project['role_name'])): ?>
                    <div style="color: #0755C6; font-size: 13px; margin-top: 4px; font-weight: 600;"><?php echo htmlspecialchars($project['role_name']); ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<p>You can now view detailed accessibility analytics and compliance reports for these projects on your dashboard.</p>

<div style="text-align: center;">
    <a href="<?php echo htmlspecialchars($appUrl); ?>" class="button">Access Project Dashboard</a>
</div>

<p style="margin-top: 30px;"><strong>What's ready for you:</strong></p>
<ul style="color: #475569; padding-left: 20px;">
    <li>Comprehensive accessibility metric overviews</li>
    <li>PDF & Excel export functionality</li>
    <li>Interactive issue tracking timelines</li>
</ul>

<p>If you have any questions regarding these assignments, please contact your project administrator.</p>
