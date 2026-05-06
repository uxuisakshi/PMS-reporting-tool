# PMS - Project Management System

A comprehensive web-based Project Management System for tracking issues, managing projects, and team collaboration.

## Features

- **Project Management**: Create and manage multiple projects with client assignments
- **Issue Tracking**: Detailed issue reporting with screenshots and status tracking
- **User Roles**: Multiple user roles (Admin, Project Lead, QA, AT Tester, FT Tester, Client)
- **Real-time Chat**: Project-based chat system for team communication
- **Reporting**: Export reports and analytics
- **Security**: 2FA authentication, encrypted vault, role-based access control
- **Accessibility**: Built-in accessibility scanning and compliance tracking

## Requirements

- PHP 8.0+
- MySQL/MariaDB
- Apache/Nginx web server
- Node.js (for accessibility scanning)
- Composer (recommended)

## Installation

### 1. Clone the Repository
```bash
git clone https://github.com/SangamNishad13/PMS-reporting-tool.git
cd PMS-reporting-tool
```

### 2. Database Setup
```sql
-- Create database
CREATE DATABASE pms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Import the schema
mysql -u username -p pms_db < database/schema.sql
```

### 3. Environment Configuration
```bash
# Copy environment template
cp .env.example .env.local

# Edit the configuration
nano .env.local
```

Configure the following variables in `.env.local`:

```env
# Database
DB_HOST=localhost
DB_NAME=pms_db
DB_USER=your_db_user
DB_PASS=your_db_password

# Migration tool password
MIGRATION_PASSWORD=choose-a-strong-password-here

# SMTP Email (optional)
SMTP_HOST=mail.yourdomain.com
SMTP_PORT=465
SMTP_SECURE=ssl
SMTP_USERNAME=noreply@yourdomain.com
SMTP_PASSWORD=your_smtp_password

# Security Keys (IMPORTANT: Generate unique values!)
# Generate with: php -r "echo base64_encode(random_bytes(32));"
VAULT_ENCRYPTION_KEY=your_generated_key_here

# Generate with: php -r "echo base64_encode(random_bytes(16));"
VAULT_KEY_SALT=your_generated_salt_here

# Generate with: php -r "echo base64_encode(random_bytes(32));"
APP_KEY=your_app_key_here

# Redis (optional)
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=0
```

### 4. File Permissions
```bash
# Set proper permissions
chmod 755 storage/ uploads/ tmp/
chmod 644 storage/* uploads/* tmp/*
```

### 5. Database Migration
```bash
# Run migrations (requires migration password)
php database/migrate.php
```

### 6. Install Node Dependencies
```bash
npm install
```

### 7. Web Server Configuration

#### Apache (.htaccess already included)
Ensure mod_rewrite is enabled:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### Nginx
Add to your server block:
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## Default User

After installation, you can create an admin user through the registration system or directly in the database.

## Directory Structure

```
PMS/
├── api/                    # API endpoints
├── assets/                 # CSS, JS, images
├── client/                 # Client-facing interface
├── config/                 # Configuration files
├── database/               # Database migrations and schema
├── includes/               # Core PHP classes and functions
├── modules/                # Application modules
│   ├── admin/             # Admin functions
│   ├── auth/              # Authentication
│   ├── projects/          # Project management
│   └── ...
├── scripts/               # Node.js scripts
├── storage/               # File storage
├── tmp/                   # Temporary files
└── uploads/               # User uploads
```

## Security Features

- **Two-Factor Authentication**: TOTP-based 2FA for enhanced security
- **Encrypted Vault**: Secure password storage with AES-256-GCM encryption
- **Role-Based Access Control**: Granular permissions for different user roles
- **CSRF Protection**: Built-in CSRF token validation
- **SQL Injection Prevention**: Prepared statements throughout
- **XSS Protection**: Input sanitization and output encoding

## User Roles

- **Admin**: Full system access
- **Project Lead**: Project management and team coordination
- **QA**: Quality assurance and issue verification
- **AT Tester**: Automated testing
- **FT Tester**: Functional testing
- **Client**: Limited access to assigned projects

## Support

For issues and questions:
- GitHub Issues: https://github.com/SangamNishad13/PMS-reporting-tool/issues
- Documentation: Check the inline code documentation

## License

ISC License
