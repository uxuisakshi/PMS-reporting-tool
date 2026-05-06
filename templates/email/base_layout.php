<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $subject ?? 'Notification'; ?></title>
    <style>
        /* Email client-specific resets */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; }

        /* General styles */
        body {
            height: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            background-color: #f1f5f9;
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #0f172a;
        }

        .container {
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
        }

        .header {
            padding: 40px 20px;
            text-align: center;
            background-color: #0755C6;
            color: #ffffff;
            border-radius: 8px 8px 0 0;
        }

        .content {
            padding: 40px 30px;
            background-color: #ffffff;
            border-left: 1px solid #e1e1e1;
            border-right: 1px solid #e1e1e1;
        }

        .footer {
            padding: 30px 20px;
            text-align: center;
            background-color: #f8fafc;
            color: #64748b;
            font-size: 13px;
            border: 1px solid #e1e1e1;
            border-radius: 0 0 8px 8px;
        }

        /* Reusable components */
        .button {
            display: inline-block;
            padding: 14px 28px;
            background-color: #0755C6;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            margin-top: 25px;
            text-align: center;
        }

        .highlight-box {
            background-color: #f8fafc;
            border-left: 4px solid #0755C6;
            padding: 20px;
            margin: 20px 0;
            border-radius: 4px;
        }

        h1, h2, h3 { color: #0f172a; margin-top: 0; }
        p { margin: 15px 0; line-height: 1.6; }
        .text-muted { color: #64748b; font-size: 14px; }
        
        @media screen and (max-width: 600px) {
            .content { padding: 30px 20px !important; }
        }
    </style>
</head>
<body>
    <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" class="container" style="box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);">
                    <!-- Header -->
                    <tr>
                        <td class="header">
                            <?php if (!empty($companyLogo)): ?>
                                <img src="cid:company_logo" alt="<?php echo htmlspecialchars($companyName); ?>" style="max-height: 60px; margin-bottom: 10px;">
                            <?php endif; ?>
                            <h1 style="margin: 0; font-size: 28px; letter-spacing: -1px;"><?php echo htmlspecialchars($app_name ?? 'Sakshi PMS'); ?></h1>
                            <p style="margin: 5px 0 0 0; opacity: 0.8; font-size: 16px;"><?php echo htmlspecialchars($header_subtitle ?? ''); ?></p>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td class="content">
                            <?php echo $content ?? ''; ?>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td class="footer">
                            <p style="margin: 0 0 10px 0;">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($company_name ?? 'Sakshi Infotech Solutions LLP'); ?>. All rights reserved.</p>
                            <?php if (isset($unsubscribe_url)): ?>
                                <p style="margin: 0;"><a href="<?php echo htmlspecialchars($unsubscribe_url); ?>" style="color: #0755C6; text-decoration: underline;">Notification Preferences</a></p>
                            <?php endif; ?>
                            <p style="margin: 15px 0 0 0; font-size: 11px; color: #94a3b8;">This is an automated message from the Project Management System. Please do not reply directly to this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
