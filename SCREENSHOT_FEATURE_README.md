# Issue Page Screenshots Feature

## Overview
This feature allows users to upload multiple screenshots for issues with reference to the grouped URLs (pages) they belong to. This helps provide visual context and reference for issues during testing and quality assurance.

## Setup Instructions

### 1. Database Migration
Run the database migration to create the `issue_page_screenshots` table:

**Option A: Using the Setup Script**
```
Visit: http://yoursite.com/setup_issue_screenshots.php
```
(You must be logged in as an admin)

**Option B: Manual SQL Execution**
Run the SQL from `database/migrations/20260409_add_issue_page_screenshots.sql` in your database:
```sql
CREATE TABLE IF NOT EXISTS `issue_page_screenshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `issue_id` int(11) DEFAULT NULL,
  `page_id` int(11) NOT NULL,
  `grouped_url_id` int(11) DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_size` bigint DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT 'image/png',
  `description` text DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_issue_id` (`issue_id`),
  KEY `idx_page_id` (`page_id`),
  KEY `idx_grouped_url_id` (`grouped_url_id`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_ips_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ips_page` FOREIGN KEY (`page_id`) REFERENCES `project_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ips_grouped_url` FOREIGN KEY (`grouped_url_id`) REFERENCES `grouped_urls` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ips_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Alter existing table if issue_id is NOT NULL
ALTER TABLE `issue_page_screenshots` MODIFY COLUMN `issue_id` int(11) DEFAULT NULL;
```

### 2. Create Upload Directory
Ensure the upload directory exists:
```bash
mkdir -p assets/uploads/issue_screenshots
chmod 755 assets/uploads/issue_screenshots
```

## Features

### Upload Screenshots
1. Navigate to **Issues Page Detail** → **Page Issues** tab
2. Click the **Camera Icon** (📷) button next to any issue
3. A modal will open titled "Page Screenshots"

### Upload Form
- **Select Images**: Upload JPG, PNG, GIF, or WebP files (max 10MB each)
- **Associated URL**: Select which grouped URL the screenshot is from (optional but recommended)
- **Description**: Add notes about what the screenshot shows (optional)
- **Upload**: Click to upload multiple files at once

### View Existing Screenshots
- Below the upload form, all uploaded screenshots are displayed
- Each screenshot shows:
  - Thumbnail preview
  - Associated URL (with link)
  - Description (if provided)
  - Uploader name and date
  - View and Delete buttons

### Delete Screenshots
- Click the **Delete** button on any screenshot card
- Confirm deletion when prompted
- Deleted screenshots are removed immediately

## API Endpoints

### Upload Screenshots
```http
POST /api/issue_screenshot_upload.php
```
**Parameters:**
- `action`: "upload"
- `issue_id`: Issue ID (optional, for backward compatibility)
- `page_id`: Page ID (required)
- `grouped_url_id`: Grouped URL ID (optional)
- `description`: Description text (optional)
- `screenshots`: File(s) input (multiple files)

**Response:**
```json
{
  "success": true,
  "uploaded": [
    {
      "id": 1,
      "filename": "screenshot.png",
      "path": "assets/uploads/issue_screenshots/...",
      "size": 1024000
    }
  ],
  "errors": [],
  "message": "1 file(s) uploaded"
}
```

### List Screenshots
```http
GET /api/issue_screenshot_upload.php?action=list&page_id=PAGE_ID
```

**Response:**
```json
{
  "success": true,
  "screenshots": [
    {
      "id": 1,
      "issue_id": null,
      "page_id": 10,
      "grouped_url_id": 25,
      "file_path": "assets/uploads/issue_screenshots/...",
      "original_filename": "screenshot.png",
      "file_size": 1024000,
      "mime_type": "image/png",
      "description": "Home page header issue",
      "uploaded_by": 2,
      "created_at": "2026-04-09 10:30:00",
      "full_name": "John Doe",
      "grouped_url": "https://example.com"
    }
  ]
}
```

### Delete Screenshot
```http
POST /api/issue_screenshot_upload.php
```
**Parameters:**
- `action`: "delete"
- `screenshot_id`: Screenshot ID (required)

**Response:**
```json
{
  "success": true,
  "message": "Screenshot deleted"
}
```

## File Structure

- **Migration**: `database/migrations/20260409_add_issue_page_screenshots.sql`
- **API Handler**: `api/issue_screenshot_upload.php`
- **JavaScript Manager**: `assets/js/issue-screenshot-manager.js`
- **Upload Directory**: `assets/uploads/issue_screenshots/`

## Permissions

Only admin, project_lead, qa, at_tester, and ft_tester roles can upload screenshots.
Screenshots are tied to specific issues and pages within projects.

## Limitations

- Maximum file size: 10MB per image
- Supported formats: JPEG, PNG, GIF, WebP
- Screenshots are deleted automatically when the issue is deleted

## Troubleshooting

### Upload Directory Permission Error
**Problem**: "Failed to save file"
**Solution**: 
```bash
chmod -R 755 assets/uploads/issue_screenshots
chown -R www-data:www-data assets/uploads/issue_screenshots
```

### Database Table Not Found
**Problem**: "Table issue_page_screenshots doesn't exist"
**Solution**: Run the setup script or manually execute the migration SQL

### Screenshot Not Displaying
**Problem**: Thumbnail not showing in modal
**Solution**: Verify the file exists in `assets/uploads/issue_screenshots/` directory

## Security Considerations

- Only authenticated users with appropriate roles can upload
- Files are validated by MIME type
- File size is limited to prevent abuse
- Original filenames are not used; unique names are generated
- Access is checked before listing/deleting screenshots

## Future Enhancements

Potential improvements:
- Bulk download screenshots for an issue
- Cropping/annotation tools
- Screenshot comparison view
- Auto-tagging with issue metadata
- Integration with issue comments/history
