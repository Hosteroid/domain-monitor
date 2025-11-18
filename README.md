# 🌐 Domain Monitor

> A powerful, self-hosted domain expiration monitoring system with multi-channel notifications

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](CONTRIBUTING.md)

A modern PHP MVC application for monitoring domain expiration dates and sending notifications through multiple channels (Email, Telegram, Discord, Slack, Mattermost, Pushover, Webhook). Never lose a domain again with automated monitoring and timely alerts.

## ✨ Features

### Core Features
- 📋 **Domain Management** - Add, edit, and monitor unlimited domains
- 🔍 **Smart WHOIS/RDAP Lookup** - Automatically fetches expiration dates and registrar information
- 🗂️ **TLD Registry System** - Built-in support for 1,400+ TLDs with IANA integration
- 🔔 **Multi-Channel Notifications** - Email, Telegram, Discord, Slack, Mattermost, Pushover, and Webhook support
- 👥 **Notification Groups** - Organize channels and assign domains flexibly
- ⚡ **Real-time Dashboard** - Overview of all domains and their status
- 📊 **Notification Logs** - Complete history of all sent notifications
- 🤖 **Automated Monitoring** - Cron-based checks with configurable intervals
- 🎨 **Modern UI** - Clean, responsive design with intuitive interface

### Advanced Features
- 🔐 **Secure by Default** - Random passwords, database-backed sessions, prepared statements
- 🔔 **User Notifications System** - In-app notification center with real-time updates
- 📬 **Smart Notifications** - Welcome messages, upgrade alerts, domain warnings
- 🌐 **Advanced Session Management** - View all active sessions with geolocation and device tracking
- 🚨 **Remote Session Termination** - Logout any device immediately from anywhere
- 📈 **Bulk Operations** - Import, refresh, and manage multiple domains at once
- 🎯 **Flexible Alerts** - Customizable notification thresholds (60, 30, 21, 14, 7, 5, 3, 2, 1 days)
- 🔄 **Auto WHOIS Refresh** - Keep domain data up-to-date automatically
- 📱 **Monitoring Controls** - Enable/disable notifications per domain with alerts
- 🌍 **RDAP Support** - Modern protocol for faster, structured domain data
- 🏴 **Geolocation Tracking** - See location, ISP, and device info for all sessions

## 📋 Requirements

- PHP 8.1 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Composer
- Apache/Nginx with mod_rewrite enabled
- Cron support for automated checks
- SMTP server for email notifications (optional)

## 🔐 Security

The application includes built-in authentication with secure practices:

- 🔑 **Random Password Generation** - Unique secure password created on installation
- 🛡️ **Database-Backed Sessions** - True session management with immediate remote logout
- 🌍 **Session Tracking** - Monitor all active sessions with location and device info
- 🚨 **Remote Session Control** - Terminate suspicious sessions from any device
- 💉 **SQL Injection Protection** - All queries use prepared statements
- 🔒 **One-time Credentials** - Admin password shown only once during setup
- 🍪 **Secure Remember Me** - Cryptographically secure 30-day tokens linked to sessions

⚠️ **Important:** Save your admin password during installation - it won't be shown again!

## 🚀 Quick Start

### 1. Clone the Repository

```bash
git clone https://github.com/Hosteroid/domain-monitor.git
cd domain-monitor
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Configure Environment

Copy the example environment file:

```bash
# Linux/Mac
cp env.example.txt .env

# Windows
copy env.example.txt .env
```

Edit `.env` and configure your settings:

```ini
# Database
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=domain_monitor
DB_USERNAME=root
DB_PASSWORD=your_password
```

**Note:** 
- The encryption key (APP_ENCRYPTION_KEY) will be automatically generated during web installation
- Application name, URL, timezone, email settings, and monitoring schedules are configured through the web interface in **Settings** (not .env)

### 4. Create Database

Create a MySQL database:

```sql
CREATE DATABASE domain_monitor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. Run Web Installer

#### Option A: Apache/Nginx (Recommended)

Configure your web server (see step 7 below), then visit your domain in a browser:

```
http://your-domain.com
```

You'll be automatically redirected to the installer.

#### Option B: PHP Built-in Server

```bash
php -S localhost:8000 -t public
```

Then visit: `http://localhost:8000`

The web installer will:
1. ✅ Create all database tables
2. ✅ Generate encryption key and save to `.env`
3. ✅ Let you set admin email and password
4. ✅ Show credentials on completion (save them!)

**⚠️ IMPORTANT:** The installer will display your admin credentials **only once**. Save them to a secure password manager!

### 6. Import TLD Registry Data (Optional but Recommended)

After logging in, go to **TLD Registry** page and click **"Import TLDs"** to download RDAP and WHOIS server data for 1,400+ TLDs from IANA.

Alternatively, use the CLI:

```bash
php cron/import_tld_registry.php
```

### 7. Configure Web Server

#### Apache

Make sure `.htaccess` is enabled. Your virtual host should point to the `public` directory.

Example configuration:

```apache
<VirtualHost *:80>
    ServerName domainmonitor.local
    DocumentRoot "/path/to/domain-monitor/public"
    
    <Directory "/path/to/domain-monitor/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

#### PHP Built-in Server (Development)

```bash
php -S localhost:8000 -t public
```

Then visit: `http://localhost:8000`

## 🔧 Configuration

### Application & Email Settings

All application and email settings are now managed through the **Settings** page in the web interface:

1. Navigate to **Settings** → **Application** tab
   - Set application name, URL, and timezone

2. Navigate to **Settings** → **Email** tab
   - Configure SMTP server (host, port, encryption)
   - Set authentication credentials
   - Configure from address and name

**Example Email Settings:**
- SMTP Host: `smtp.gmail.com`
- SMTP Port: `587`
- Encryption: `TLS`
- Username: `your-email@gmail.com`
- Password: `your-app-password`

### Notification Channels

#### ✈️ Telegram

1. Create a bot using [@BotFather](https://t.me/BotFather)
2. Get your Chat ID using [@userinfobot](https://t.me/userinfobot)
3. Add the channel in the notification group settings

#### 💬 Discord

1. Go to Server Settings → Integrations → Webhooks
2. Create a new webhook
3. Copy the webhook URL
4. Add it in the notification group settings

#### 💼 Slack

1. Go to Slack App Settings
2. Enable Incoming Webhooks
3. Create a new webhook
4. Copy the webhook URL
5. Add it in the notification group settings

#### 🌐 Webhook (Custom)

Send JSON payloads to any HTTP endpoint (e.g., n8n, Zapier, Make, your own API):

1. Go to Notification Groups → Edit → Add Channel
2. Choose "Webhook (Custom)"
3. Paste your endpoint URL (HTTPS recommended)
4. Click "Test Channel" to verify

Payload example sent on domain alerts:

```json
{
  "event": "domain_expiration_alert",
  "message": "⚠️ WARNING: Domain 'example.com' expires in 7 days (January 30, 2026)!\n\nRegistrar: Example Registrar\nPlease renew soon.",
  "data": {
    "domain": "example.com",
    "domain_id": 123,
    "days_left": 7,
    "expiration_date": "2026-01-30",
    "registrar": "Example Registrar"
  },
  "sent_at": "2025-10-17T12:34:56Z"
}
```

Use this with n8n's "Webhook" trigger to start flows.

## 📅 Setting Up Cron Jobs

The application requires a cron job to check domains periodically.

**💡 Pro Tip:** The cron path is automatically detected! Go to **Settings → System** to copy the exact command for your installation.

### Linux/Mac

```bash
crontab -e
```

Add this line (or copy from Settings → System):

```cron
0 9 * * * /usr/bin/php /your/actual/path/cron/check_domains.php
```

### Windows

Use Task Scheduler:

1. Open Task Scheduler
2. Create Basic Task
3. Set trigger (e.g., Daily at 9:00 AM)
4. Action: Start a program
5. Program: `C:\php\php.exe`
6. Arguments: Copy from **Settings → System** tab (auto-detected path)

## 🧪 Testing Notifications

Before setting up the cron job, test your notification channels through the web interface:

1. Go to **Settings → Email** tab
2. Enter a test email address
3. Click **"Send Test Email"** to verify SMTP configuration
4. For Telegram/Discord/Slack, send a test from the notification group settings

## 📖 Usage Guide

### Adding Domains

1. Navigate to **Domains** → **Add Domain**
2. Enter the domain name (e.g., `example.com`)
3. Optionally assign to a notification group
4. Click **Add Domain**

The system will automatically fetch WHOIS information.

### Creating Notification Groups

1. Navigate to **Notification Groups** → **Create Group**
2. Enter a name and description
3. Click **Create Group**
4. Add notification channels (Email, Telegram, Discord, Slack)
5. Assign domains to the group

### Monitoring

The **Dashboard** shows:
- Total domains and their status
- Domains expiring soon
- Recent notifications sent

### Notification Schedule

By default, notifications are sent at these intervals before expiration:
- 60 days (2 months)
- 30 days (1 month)
- 21 days (3 weeks)
- 14 days (2 weeks)
- 7 days (1 week)
- 5 days
- 3 days
- 2 days
- 1 day (tomorrow!)
- When expired (immediate alert)

### Configure Settings

All system settings are managed through the **Settings** page (`/settings`) in your browser:

#### Application Settings
- **Application Name**: Customize the display name
- **Application URL**: Base URL for links in emails
- **Timezone**: Set your preferred timezone
- **User Registration**: Enable/disable new user signups
- **Email Verification**: Require email verification for new users

#### Email Settings
- **SMTP Configuration**: Host, port, encryption
- **Authentication**: Username and password
- **From Address**: Email sender details

#### Monitoring Settings
- **Notification Schedule**: Choose from presets or create custom
  - **Minimal**: 30, 7, 1 days
  - **Standard**: 60, 30, 21, 14, 7, 5, 3, 2, 1 days
  - **Frequent**: 90, 60, 45, 30, 21, 14, 10, 7, 5, 3, 2, 1 days
  - **Business Focused**: 60, 30, 14, 7, 3, 1 days
  - **Custom**: Enter your own comma-separated days

- **Check Interval**: How often to check domains
  - Every 6 hours
  - Every 12 hours  
  - Daily (24 hours)
  - Every 2 days
  - Weekly

#### System Settings
- **Auto-Detected Cron Path**: Copy-paste ready cron commands with your actual installation path
- **Log File Locations**: Find logs for troubleshooting

### User Notifications

Stay informed with the in-app notification system:

#### Notification Center
- **Bell Icon**: Top navigation shows unread count with animated indicator
- **Dropdown Preview**: Quick view of 5 most recent unread notifications
- **Full Page**: `/notifications` with complete history and management

#### Notification Types
- 📬 **Welcome** - Sent when you create an account or system is installed
- ⬆️ **System Upgrade** - Admins notified when system is updated (includes version & migration count)
- 🔴 **Domain Expiring** - Alerts based on your configured thresholds
- ⚠️ **Domain Expired** - Critical alerts for expired domains
- 🔄 **Domain Updated** - WHOIS data changes detected
- 🔐 **New Login** - Security alerts for new device logins
- ❌ **WHOIS Failed** - Lookup errors and issues

#### Notification Features
- **Filter by Status**: Unread, Read, or All
- **Filter by Type**: Domain, System, or Security notifications
- **Date Ranges**: Today, This Week, This Month, All Time
- **Pagination**: View 10, 25, 50, or 100 per page
- **Quick Actions**: Mark as read, Delete, Mark all read, Clear all

### Profile Management

Access your profile settings via the top-right user menu:

#### My Profile
- Update full name and email
- View account creation date
- Check last login timestamp
- Email verification status

#### Security
- Change password securely
- Password strength requirements
- Security best practices

#### Active Sessions
- **View All Sessions**: See every device where you're logged in
- **Session Details**: Location (country, city), ISP, device type, browser
- **Country Flags**: Visual indicators for each session location
- **Session Age**: See when each session was created
- **Last Activity**: Monitor recent activity per session
- **Remember Me Indicator**: See which sessions have "remember me" enabled
- **Remote Logout**: Terminate individual sessions or all other sessions
- **Instant Termination**: Deleted sessions are logged out immediately

All settings are stored in the database and can be updated at any time through the web interface.

## 📁 Project Structure

```
Domain Monitor/
├── app/
│   ├── Controllers/        # Application controllers
│   ├── Models/            # Database models (User, Domain, SessionManager, etc.)
│   ├── Services/          # Business logic & services
│   │   ├── Channels/      # Notification channel implementations
│   │   └── NotificationService.php  # Notification creation & management
│   ├── Helpers/           # Helper classes for formatting & display logic
│   │   ├── LayoutHelper.php     # Global layout data (notifications, stats)
│   │   ├── DomainHelper.php     # Domain formatting & calculations
│   │   └── SessionHelper.php    # Session display formatting
│   └── Views/             # HTML views (pure display, no business logic)
├── core/                  # Core MVC framework
│   ├── DatabaseSessionHandler.php  # Database session storage
│   ├── SessionValidator.php        # Session validation middleware
│   ├── Auth.php           # Authentication helpers
│   └── ...
├── cron/                  # Cron job scripts
├── database/
│   └── migrations/        # Database migrations
├── public/                # Web root (index.php, assets)
├── routes/                # Route definitions
├── vendor/                # Composer dependencies
└── .env                   # Environment configuration
```

## 🔐 Security Considerations

1. **Never commit `.env`** - Contains sensitive credentials
2. **Secure your web server** - Point only the `public` directory to the web
3. **Use strong database passwords**
4. **Enable HTTPS** in production
5. **Protect cron endpoints** - Ensure cron scripts aren't web-accessible
6. **Regular updates** - Keep dependencies updated

## 🐛 Troubleshooting

### WHOIS Lookup Fails

- Some domain TLDs may not be supported
- Check if the domain is valid and registered
- Verify your server can make outbound connections

### Notifications Not Sending

1. Check logs: `logs/cron.log`
2. Verify notification channel configuration in **Settings → Email**
3. Test email using the built-in test function in Settings
4. Check SMTP/API credentials in Settings

### Database Connection Error

- Verify database credentials in `.env`
- Ensure MySQL service is running
- Check if database exists

### Cron Job Not Running

- Verify cron syntax and paths
- Check server logs
- Test manually: `php cron/check_domains.php`

## 🐛 Bug Reports & Feature Requests

We welcome bug reports and feature requests! Please use GitHub Issues:

### 🐞 Report a Bug
Found a bug? [Open an issue](https://github.com/Hosteroid/domain-monitor/issues/new?template=bug_report.md) with:
- Clear description of the issue
- Steps to reproduce
- Expected vs actual behavior
- Environment details (PHP version, OS, etc.)

### 💡 Request a Feature
Have an idea? [Submit a feature request](https://github.com/Hosteroid/domain-monitor/issues/new?template=feature_request.md) with:
- Clear description of the feature
- Use case and benefits
- Any implementation ideas

## 🤝 Contributing

Contributions are welcome and appreciated! Here's how you can help:

### How to Contribute

1. **Fork the repository**
2. **Create a feature branch** (`git checkout -b feature/AmazingFeature`)
3. **Make your changes**
4. **Test thoroughly**
5. **Commit your changes** (`git commit -m 'Add some AmazingFeature'`)
6. **Push to the branch** (`git push origin feature/AmazingFeature`)
7. **Open a Pull Request**

### Development Guidelines

- Follow PSR-12 coding standards
- Write clear commit messages
- Add comments for complex logic
- Test your changes before submitting
- Update documentation as needed

### Areas for Contribution

- 🐛 Bug fixes
- ✨ New features
- 📝 Documentation improvements
- 🌍 Translations
- 🎨 UI/UX enhancements
- ⚡ Performance optimizations

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

**TL;DR:** Free to use for personal and commercial projects. Attribution appreciated but not required.

## 📧 Support & Community

- 💬 **Discussions:** [GitHub Discussions](https://github.com/Hosteroid/domain-monitor/discussions)
- 🐛 **Issues:** [Bug Tracker](https://github.com/Hosteroid/domain-monitor/issues)
- 📖 **Documentation:** [Wiki](https://github.com/Hosteroid/domain-monitor/wiki)
- ⭐ **Star the project** if you find it useful!

## 💼 Created & Sponsored By

<div align="center">

### [Hosteroid - Premium Hosting Solutions](https://www.hosteroid.uk)

This project is proudly created and maintained by **Hosteroid**, a leading provider of premium hosting solutions.

[![Hosteroid](https://img.shields.io/badge/Powered%20by-Hosteroid-blue?style=for-the-badge)](https://www.hosteroid.uk)

**Services:** Web Hosting • VPS • Dedicated Servers • Domain Registration

🌐 **Website:** [hosteroid.uk](https://www.hosteroid.uk)  
📧 **Contact:** [support@hosteroid.uk](mailto:support@hosteroid.uk)

</div>

---

## 🙏 Acknowledgments

- Created by [Hosteroid](https://www.hosteroid.uk)
- WHOIS/RDAP data from [IANA](https://www.iana.org/)
- Built with modern PHP and love ❤️

## 📊 Project Stats

![GitHub stars](https://img.shields.io/github/stars/Hosteroid/domain-monitor?style=social)
![GitHub forks](https://img.shields.io/github/forks/Hosteroid/domain-monitor?style=social)
![GitHub issues](https://img.shields.io/github/issues/Hosteroid/domain-monitor)
![GitHub pull requests](https://img.shields.io/github/issues-pr/Hosteroid/domain-monitor)

---

<div align="center">

**Made with ❤️ by [Hosteroid](https://www.hosteroid.uk)**

[Report Bug](https://github.com/Hosteroid/domain-monitor/issues) • [Request Feature](https://github.com/Hosteroid/domain-monitor/issues) • [Visit Hosteroid](https://www.hosteroid.uk)

</div>

