# VPS Deployment Notes

## What changed

- `modules/profile.php`
  - Client profile page is now client-focused.
  - Client users see a simplified profile view, with internal stats hidden.
  - Labels changed from `Project` to `Digital Assets`.

- `VAPT_CLIENT_URL_INVENTORY.xls`
  - Added `modules/auth/login.php`.
  - Added `modules/auth/verify_2fa.php`.
  - Updated profile page notes.

- `VAPT_CLIENT_URL_INVENTORY.md`
  - Added 2FA URL entry.

- `VAPT_CLIENT_URL_INVENTORY_UAT.md`
  - Added 2FA URL entry.

## Deploy to VPS

1. Commit the changes in this repository.
2. Push the commit to the branch used for VPS deployment.
3. Deploy the same commit to:
   - Production VPS
   - UAT VPS

## Notes

- The code changes are already in the workspace and ready for deployment.
- Actual server-side deployment must be performed on the VPS hosts via your normal deploy process (SSH, Git pull, FTP, or CI/CD).
