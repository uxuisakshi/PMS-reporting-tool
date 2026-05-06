# Current Tab A11y Scanner

Independent Chrome/Edge extension for scanning the active tab with axe-core, downloading an Excel report, and optionally uploading the viewport screenshot plus scan metadata into PMS temporary storage.

## What It Does

- Scans only the currently active tab.
- Uses `axe-core` inside the page context.
- Exports a local `.xlsx` report with summary and findings sheets.
- Optionally uploads the captured viewport screenshot and raw findings JSON to:
  - `api/extension_temp_upload.php`
  - storage path: `uploads/temporary-extensions-testing/YYYYMMDD/{scanId}`

## Load In Browser

1. Open `chrome://extensions` or `edge://extensions`.
2. Enable `Developer mode`.
3. Choose `Load unpacked`.
4. Select this folder:
   - `browser-extension/current-tab-a11y-scanner`

## PMS Upload Setup

Default endpoint:

- `https://pms.athenaeumtransformation.com/api/extension_temp_upload.php`

Optional hardening:

- Set environment variable `EXTENSION_TEMP_UPLOAD_TOKEN` on PMS.
- Then fill the same token in the extension popup before uploading.

## Notes

- This extension is intentionally independent from PMS login and issue workflows.
- PMS is used only as an optional temporary screenshot sink for testing.
- Some pages like `chrome://`, extension pages, and browser internal tabs cannot be scanned.
- Full-site crawl is not included in this MVP. This version scans only the current tab.