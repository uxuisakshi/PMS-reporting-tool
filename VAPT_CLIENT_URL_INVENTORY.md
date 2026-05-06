# VAPT Client URL Inventory

This document lists the client-facing URLs for the production PMS instance:

- Base URL: `https://pms.athenaeumtransformation.com`

This inventory is intended for VAPT review of the client-accessible surface.

## Scope Notes

- Most URLs below require authentication as a `client` user.
- Some URLs are dynamic and require a valid project token or project ID.
- For authenticated testing, provide the auditor with a dedicated client test account.
- For dynamic project routes, share at least one valid sample URL from an assigned project.

## 1. Authentication Pages

| URL | Type | Auth | Notes |
|---|---|---|---|
| `https://pms.athenaeumtransformation.com/modules/auth/login.php` | Page | No | Client login page |
| `https://pms.athenaeumtransformation.com/modules/auth/verify_2fa.php` | Page | Partial | 2FA verification page for enabled client accounts |
| `https://pms.athenaeumtransformation.com/modules/auth/force_reset.php` | Page | Yes | Forced password reset flow if enabled on account |
| `https://pms.athenaeumtransformation.com/client/logout` | Route | Yes | Client logout route |

## 2. Client Dashboard and Analytics Pages

| URL | Type | Auth | Notes |
|---|---|---|---|
| `https://pms.athenaeumtransformation.com/client/dashboard` | Page | Yes | Main client analytics dashboard |
| `https://pms.athenaeumtransformation.com/client/project/{project-token}` | Page | Yes | Individual digital asset analytics page using canonical tokenized route |
| `https://pms.athenaeumtransformation.com/modules/client/projects.php` | Page | Yes | My Digital Assets listing page |
| `https://pms.athenaeumtransformation.com/modules/client/compliance_overview.php` | Page | Yes | Compliance overview across assigned digital assets |
| `https://pms.athenaeumtransformation.com/modules/client/issues_overview.php` | Page | Yes | Issue summary across assigned digital assets |
| `https://pms.athenaeumtransformation.com/modules/client/issues_overview.php?project_id={project-id}` | Page | Yes | Project-specific issue summary |

## 3. Client Support and Preference Pages

| URL | Type | Auth | Notes |
|---|---|---|---|
| `https://pms.athenaeumtransformation.com/modules/client/preferences.php` | Page | Yes | Client notification and email preferences |
| `https://pms.athenaeumtransformation.com/modules/client/history.php` | Page | Yes | Export history page |
| `https://pms.athenaeumtransformation.com/modules/client/help.php` | Page | Yes | Client help and documentation page |

## 4. Legacy Client URLs Still Reachable

These URLs are legacy entry points or redirects that may still be relevant to a VAPT auditor.

| URL | Type | Auth | Notes |
|---|---|---|---|
| `https://pms.athenaeumtransformation.com/modules/client/dashboard.php` | Redirect/Page | Yes | Legacy dashboard entry point redirecting to `/client/dashboard` |
| `https://pms.athenaeumtransformation.com/modules/projects/my_client_projects.php` | Redirect/Page | Yes | Legacy projects entry point redirecting to `/modules/client/projects.php` |

## 5. Client Data, Export, and File Access Endpoints

These endpoints are part of the client-accessible surface and should be considered in VAPT scope.

| URL | Type | Auth | Notes |
|---|---|---|---|
| `https://pms.athenaeumtransformation.com/api/client_dashboard.php` | API | Yes | Dashboard data JSON endpoint |
| `https://pms.athenaeumtransformation.com/api/client_export.php` | API | Yes | Client export initiation endpoint |
| `https://pms.athenaeumtransformation.com/api/export_client_report.php` | API | Yes | Client report generation/export endpoint |
| `https://pms.athenaeumtransformation.com/client/download?id={request-id}` | Download Route | Yes | Download of generated client export |
| `https://pms.athenaeumtransformation.com/api/email_preferences.php` | API | Yes | Client email preference update endpoint |
| `https://pms.athenaeumtransformation.com/api/secure_file.php?path={encoded-path}` | File Endpoint | Yes | Authenticated secure file access |
| `https://pms.athenaeumtransformation.com/api/public_image.php?t={signed-token}` | File Endpoint | Signed URL | Signed public image access |
| `https://pms.athenaeumtransformation.com/api/download_screenshots.php?project_id={project-id}` | Download API | Yes | Screenshot ZIP download |
| `https://pms.athenaeumtransformation.com/api/serve_template.php` | File Endpoint | Yes | Report template file endpoint |

## 6. Client Chat and Interaction Endpoints

| URL | Type | Auth | Notes |
|---|---|---|---|
| `https://pms.athenaeumtransformation.com/api/chat.php` | API | Yes | Chat read/post endpoint |
| `https://pms.athenaeumtransformation.com/api/chat_actions.php` | API | Yes | Chat action endpoint |
| `https://pms.athenaeumtransformation.com/api/chat_unread_count.php` | API | Yes | Unread chat count endpoint |
| `https://pms.athenaeumtransformation.com/api/chat_upload_image.php` | API | Yes | Chat image upload endpoint |
| `https://pms.athenaeumtransformation.com/api/feedback.php` | API | Yes | Feedback submission endpoint |

## 7. Recommended Dynamic Test URLs To Share With Auditor

Provide real values for at least one assigned client project.

Examples:

- `https://pms.athenaeumtransformation.com/client/project/{project-token}`
- `https://pms.athenaeumtransformation.com/modules/client/issues_overview.php?project_id={project-id}`
- `https://pms.athenaeumtransformation.com/client/download?id={request-id}`

## 8. Suggested Auditor Access Package

Recommended items to share with the VAPT auditor:

1. One dedicated client username/password.
2. 2FA instructions if enabled for that account.
3. One valid client project tokenized URL.
4. One valid project ID for issue summary testing.
5. Confirmation that testing scope is limited to the client-facing surface listed in this document.

## 9. Out of Scope Unless Explicitly Required

The following areas are not part of the normal client-facing surface unless you intentionally want them included in testing:

- Admin module URLs
- QA/internal project management URLs
- Internal-only project edit or issue management flows
- Background cron jobs
- Server administration interfaces
