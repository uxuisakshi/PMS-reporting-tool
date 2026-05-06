# UAT IP Restriction Guide

This file documents how to manage IP-based access for:

- `https://uat.pms.athenaeumtransformation.com`

Current Apache setup uses these files on the VPS:

- Allowlist file: `/etc/apache2/conf-available/uat-ip-allowlist.conf`
- HTTP vhost: `/etc/apache2/sites-available/uat.pms.athenaeumtransformation.com.conf`
- HTTPS vhost: `/etc/apache2/sites-available/uat.pms.athenaeumtransformation.com-le-ssl.conf`

Both UAT vhosts include the shared allowlist file, so in normal cases you only need to edit one file.

## Current Restriction Format

The main line that controls access looks like this:

```apache
Require ip 127.0.0.1 ::1 103.133.169.216
```

Add more IPs on the same line separated by spaces.

Example:

```apache
Require ip 127.0.0.1 ::1 103.133.169.216 49.36.88.120 106.219.55.10
```

## Option 1: Add a New IP to Whitelist Using Webmin

1. Open Webmin.
2. Go to `Servers` -> `Apache Webserver` -> `Edit Config Files`.
3. Open this file:

   ```
   /etc/apache2/conf-available/uat-ip-allowlist.conf
   ```

4. Find this line:

   ```apache
   Require ip 127.0.0.1 ::1 103.133.169.216
   ```

5. Add the new public IP at the end of the same line.
6. Save the file.
7. Apply Apache changes or reload Apache from Webmin.

## If Webmin Does Not Show Apache Webserver

If under `Servers` you only see options like:

- `Read User Mail`
- `SSH Server`

then one of these is true:

- Apache Webserver module is not available in that Webmin install.
- Your current Webmin login does not have permission to manage Apache.
- You may be logged into a limited user account instead of the full admin/root Webmin account.

In that case, use the SSH method from this document instead.

For your current VPS setup, SSH is the safer and more direct method.

## Option 2: Add a New IP Using SSH

1. SSH into the VPS.
2. Open the allowlist file:

   ```bash
   nano /etc/apache2/conf-available/uat-ip-allowlist.conf
   ```

3. Update the `Require ip` line.
4. Save the file.
5. Validate Apache config:

   ```bash
   apache2ctl configtest
   ```

6. Reload Apache:

   ```bash
   systemctl reload apache2
   ```

## Option 3: Remove IP Restriction Completely Using Webmin

If you want UAT to become publicly accessible again, change the shared allowlist file to allow everyone.

1. Open Webmin.
2. Go to `Servers` -> `Apache Webserver` -> `Edit Config Files`.
3. Open:

   ```
   /etc/apache2/conf-available/uat-ip-allowlist.conf
   ```

4. Replace the `<Directory /var/www/html/PMS-UAT>` block with this:

   ```apache
   <Directory /var/www/html/PMS-UAT>
       Options -Indexes +FollowSymLinks
       AllowOverride All
       Require all granted
   </Directory>
   ```

5. Save the file.
6. Apply Apache changes or reload Apache.

## Option 4: Remove IP Restriction Completely Using SSH

1. SSH into the VPS.
2. Open:

   ```bash
   nano /etc/apache2/conf-available/uat-ip-allowlist.conf
   ```

3. Replace the directory block with:

   ```apache
   <Directory /var/www/html/PMS-UAT>
       Options -Indexes +FollowSymLinks
       AllowOverride All
       Require all granted
   </Directory>
   ```

4. Save the file.
5. Run:

   ```bash
   apache2ctl configtest
   systemctl reload apache2
   ```

## Quick Rollback Back to IP Restriction

If you removed restriction and want it back later, restore the directory block like this:

```apache
<Directory /var/www/html/PMS-UAT>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require ip 127.0.0.1 ::1 103.133.169.216
</Directory>
```

Then run:

```bash
apache2ctl configtest
systemctl reload apache2
```

## Important Notes

- Always whitelist the public IP, not local Wi-Fi/LAN IP like `192.168.x.x`.
- If someone's internet uses dynamic IP, access can break after their IP changes.
- SSL renewal is safe because `/.well-known/acme-challenge/` is still allowed publicly.
- If `apache2ctl configtest` fails, do not reload Apache until the config is fixed.