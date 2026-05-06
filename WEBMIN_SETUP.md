# Webmin Setup Guide - Sakshi-Infotech Server

## Server Details
- **Server Name**: Sakshi-Infotech
- **Static IP**: 142.79.228.254
- **SSH Port**: 22925
- **Local IP**: 192.168.25.25
- **Username**: root / sakshi
- **Password**: Sakshi@2026#$%

## Installation Steps

### Step 1: Connect via SSH
```bash
ssh -p 22925 root@142.79.228.254
# Or use local IP if on same network
ssh -p 22925 root@192.168.25.25
```

### Step 2: Update System Packages
```bash
apt-get update
apt-get upgrade -y
```

### Step 3: Install Webmin

**Option A: Using Official Repository (Recommended)**
```bash
# Add GPG key
wget http://www.webmin.com/jcameron-key.asc
apt-key add jcameron-key.asc

# Add Webmin repository
echo "deb http://download.webmin.com/download/repository sarge contrib" >> /etc/apt/sources.list

# Update and install
apt-get update
apt-get install webmin -y
```

**Option B: Using curl (Faster)**
```bash
curl http://www.webmin.com/download/deb/install-webmin.sh | bash
```

### Step 4: Verify Installation
```bash
systemctl status webmin
systemctl enable webmin  # Enable on boot
```

### Step 5: Access Webmin
- **URL**: https://142.79.228.254:10000
- **Or Local**: https://192.168.25.25:10000
- **Username**: root (or sakshi)
- **Password**: Sakshi@2026#$%

### Step 6: Configure Webmin (Optional but Recommended)

1. Change default port (if needed):
   - Go to: Webmin → Webmin Configuration → Ports and Addresses
   - Change port from 10000 to your preferred port

2. Enable SSL properly:
   - Ensure HTTPS is enabled (usually default)
   - Install Let's Encrypt certificate for better security

3. Create backup user:
   - Webmin → System → Users and Groups
   - Create new admin user for safety

## First Time Login
- Use your root credentials
- Change password after first login (recommended)
- Set up 2FA if available
- Configure backup modules

## Common Issues

**Issue**: "Connection refused" on port 10000
- Check if Webmin service is running: `systemctl status webmin`
- Check firewall: `ufw allow 10000`

**Issue**: SSL certificate warning
- Accept the self-signed certificate for first login
- Install proper certificate later

**Issue**: Can't login with root
- Try with 'sakshi' user instead
- Check `/etc/webmin/webmin.users` file
