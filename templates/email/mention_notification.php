<?php
/**
 * Mention Notification Template
 * Variables: $userName, $mentionedBy, $message, $link, $appUrl
 */
?>
<h2 style="color: #0755C6;">New Mention</h2>
<p>Hello <?php echo htmlspecialchars($userName); ?>,</p>

<p>User <strong><?php echo htmlspecialchars($mentionedBy); ?></strong> mentioned you in a conversation:</p>

<div class="highlight-box">
    <p style="margin: 0; font-style: italic; color: #475569;">"<?php echo htmlspecialchars($message); ?>"</p>
</div>

<p>To view the full context and reply, please click the button below:</p>

<div style="text-align: center;">
    <a href="<?php echo htmlspecialchars($link); ?>" class="button">View Conversation</a>
</div>

<p style="margin-top: 30px; font-size: 13px; color: #64748b;">If the button doesn't work, you can copy and paste this link into your browser:<br>
<a href="<?php echo htmlspecialchars($link); ?>" style="color: #0755C6;"><?php echo htmlspecialchars($link); ?></a></p>
