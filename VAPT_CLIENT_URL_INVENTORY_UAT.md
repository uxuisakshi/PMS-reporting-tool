# VAPT Client URL Inventory (UAT)

This document lists the client-facing URLs for the UAT PMS instance:

- Base URL: `https://uat-pms.athenaeumtransformation.com`

This inventory is intended for VAPT review of the client-accessible surface on UAT.

## Scope Notes

- Most URLs below require authentication as a `client` user.
- Some URLs are dynamic and require a valid project token or project ID.
- For authenticated testing, provide the auditor with a dedicated client test account.
- For dynamic project routes, share at least one valid sample URL from an assigned project.

## 1. Authentication Pages

| URL | Type | Auth | Notes |
|---|---|---|---|
| `https://uat-pms.athenaeumtransformation.com/modules/auth/login.php` | Page | No | Client login page |
| `https://uat-pms.athenaeumtransformation.com/modules/auth/verify_2fa.php` | Page | Partial | 2FA verification page for enabled client accounts |
| `https://uat-pms.athenaeumtransformation.com/modules/profile.php` | Page | Yes | Client profile page (view and update your info; no task/project stats shown) |

## 2. Client Dashboard and Analytics Pages

| URL | Type | Auth | Notes |
|---|---|---|---|
| `https://uat-pms.athenaeumtransformation.com/client/dashboard` | Page | Yes | Main client analytics dashboard |
| `https://uat-pms.athenaeumtransformation.com/client/project/{project-token}` | Page | Yes | Individual digital asset analytics page using canonical tokenized route |
| `https://uat-pms.athenaeumtransformation.com/modules/client/projects.php` | Page | Yes | My Digital Assets listing page |
| `https://uat-pms.athenaeumtransformation.com/modules/client/issues_overview.php` | Page | Yes | Issue summary across assigned digital assets |
| `https://uat-pms.athenaeumtransformation.com/modules/client/issues_overview.php?project_id={project-id}` | Page | Yes | Project-specific issue summary |
| `https://uat-pms.athenaeumtransformation.com/modules/projects/issues_all.php?project_id={project-id}` | Page | Yes | Full client-visible issue list linked from Issue Overview |

## 3. Client Support and Preference Pages

| URL | Type | Auth | Notes |
|---|---|---|---|
| `https://uat-pms.athenaeumtransformation.com/modules/client/preferences.php` | Page | Yes | Client notification and email preferences |
| `https://uat-pms.athenaeumtransformation.com/modules/client/history.php` | Page | Yes | Export history page |
| `https://uat-pms.athenaeumtransformation.com/modules/client/help.php` | Page | Yes | Client help and documentation page |
