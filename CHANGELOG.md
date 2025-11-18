# Changelog

All notable changes to Domain Monitor will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2025-11-18

### Added
- **Pushover Notification Channel** - Send domain expiration alerts via Pushover (iOS, Android, Desktop)
  - Priority-based notifications (Emergency, High, Normal, Low) based on days until expiration
  - Emergency alerts (expired or expiring in ≤1 day) with auto-retry every 5 minutes for 1 hour
  - 23 custom notification sounds to choose from
  - Device targeting - send to specific devices or all devices
  - Rich notifications with title, message, and clickable URL to domain details
  - Optional custom sound and device configuration
  - Database migration `022_add_pushover_channel_type.sql` to add Pushover support

### Fixed
- **Security: PHP 8.x URI Injection Vulnerability** - Fixed deprecated `strpos()` null parameter warning
  - Added early request validation in `public/index.php` to block malformed URIs
  - Enhanced `core/Auth.php` to handle null values from `parse_url()` gracefully
  - Malformed requests are now logged and return 400 Bad Request
  - Prevents attackers from causing PHP warnings via malformed URI probes
- **PHP 8.x Compatibility: strtotime() Null Parameter** - Fixed deprecated warnings for null expiration dates
  - Added null checks before calling `strtotime()` in all domain view templates
  - Displays "Unknown" for domains without expiration dates (e.g., .nl domains)
  - Updated 9 view files: groups/edit, domains/index, domains/view, domains/edit, dashboard/index, tags/view, search/results
  - Also fixed `NotificationService::formatExpirationMessage()` to handle null dates
- **Domain Status Detection for .nl Domains** - Fixed incorrect "available" status for registered .nl domains
  - `.nl` WHOIS/RDAP doesn't always provide expiration dates or explicit status flags
  - Improved `WhoisService::getDomainStatus()` to detect registered domains via nameservers and valid registrar
  - Cron job now preserves existing expiration dates when WHOIS doesn't return one
  - Prevents false positives for domain availability
- **Domain Status Detection for .eu Domains** - Fixed incorrect status and registrar parsing for .eu domains
  - Added specific `.eu` registrar format parsing (`Name: Registrar Name`)
  - Fixed RDAP vCard parsing to strip "Name:" prefix from registrar field  
  - Fixed WHOIS parsing to handle "Name: Company" format in registrar sections
  - Enhanced status detection logic to recognize registered domains without explicit status flags
  - Consistent behavior between manual refresh and automated cron checks
- **Logging Consistency** - Replaced all remaining `error_log()` calls with custom Logger service
  - Updated `WhoisService.php`, `NotificationService.php`, `AuthController.php`, `UserController.php`
  - Centralized structured logging throughout the application
  - Better debugging and audit trail capabilities

### Changed
- **Status Detection** - Unified `DomainHelper::determineStatus()` to use `WhoisService::getDomainStatus()` for consistency
- **Documentation** - Updated README.md to reflect all available notification channels including Pushover

## [1.1.0] - 2025-10-09

### Added
- **User Notifications System** - In-app notification center with filtering and pagination
- **Welcome Notifications** - Automatically sent to new users on registration or fresh install
- **System Upgrade Notifications** - Admins notified when system is upgraded with migration details
- **Notification Types**:
  - System: Welcome, Upgrade notifications
  - Domain: Expiring, Expired, Updated
  - Security: New login detection
  - WHOIS: Lookup failures
- **Notification Features**:
  - Unread notification count in top navigation
  - Dropdown preview of recent notifications
  - Full notification page with filtering (status, type, date range)
  - Pagination and sorting
  - Mark as read / Mark all as read
  - Delete individual / Clear all notifications
- **Database-Backed Sessions** - Full session management stored in database
- **Active Session Management** - View, monitor, and control all logged-in devices
- **Geolocation Tracking** - IP-based location detection (country, city, region, ISP)
- **Session Details Display**:
  - Country flags with flag-icons library
  - City and country name
  - ISP/Network provider
  - Device type detection (Desktop/Mobile/Tablet)
  - Browser detection (Chrome/Firefox/Safari/Edge/Opera)
  - Session age and last activity timestamps
  - Remember me indicator (cookie badge)
- **Remote Session Control**:
  - Terminate individual sessions with delete button
  - Logout all other sessions with one click
  - Immediate logout validation (deleted sessions can't access anything)
- **Enhanced Profile Page**:
  - Sidebar navigation layout
  - Four sections: Profile Information, Security, Active Sessions, Danger Zone
  - URL hash navigation (#profile, #security, #sessions, #danger)
  - Clean design matching application theme
- **Remember Token Security**:
  - Remember tokens linked to specific sessions
  - Deleting session also invalidates remember token
  - Prevents auto-login after remote logout
- **Session Validator Middleware** - Validates sessions on every request
- **Auto-Detected Cron Paths** - Settings page shows actual installation paths (thanks @jadeops)
- **Automatic Session Cleanup** - Multiple cleanup triggers (no cron job needed)
- User registration with email verification
- Password reset via email
- Remember me functionality (30-day cookies)
- User profile management
- Change password
- Email verification with token expiry (24h)
- Password reset tokens (1h expiry)
- Registration enable/disable toggle
- User CRUD management (admin-only)
- Role-based access control (admin/user)
- Centralized app version in database
- Web-based installer (replaces CLI migrate.php)
- Web-based updater for new migrations
- Auto-detection of installation status
- Migration tracking system
- Consolidated database schema for v1.1.0 fresh installs
- Smart migration system (consolidated for new, incremental for upgrades)
- **Two-Factor Authentication (2FA) System**:
  - TOTP (Time-based One-Time Password) implementation
  - Email backup codes for 2FA recovery
  - 2FA verification attempts tracking with rate limiting
  - 2FA policy settings (optional/required/disabled)
  - Complete 2FA setup, verification, and management flow
  - Backup codes generation and verification system
- **CAPTCHA Security System**:
  - Support for reCAPTCHA v2, reCAPTCHA v3, and Cloudflare Turnstile
  - Configurable CAPTCHA settings in admin panel
  - Score-based verification for reCAPTCHA v3
  - Integration with login and registration forms
  - CAPTCHA provider selection and configuration
- **Domain Tags System**:
  - Domain tagging for organization and categorization
  - Comma-separated tags field in domains table
  - Tag-based domain filtering and organization
  - Indexed tag searches for performance
- **Advanced Error Logging System**:
  - Database-backed error logging and tracking
  - Error deduplication and occurrence counting
  - Request context capture (method, URI, data)
  - User context (IP, user agent, session data)
  - System context (PHP version, memory usage)
  - Error resolution tracking and management
  - Admin error log interface for debugging
- **Enhanced Logger Service**:
  - Structured logging with context arrays
  - Multiple log levels (debug, info, warning, error, critical)
  - Date-based log file rotation
  - Context-aware logging throughout the application
  - JSON-formatted log entries with timestamps
- **User Avatar System**:
  - Avatar upload and deletion functionality
  - Gravatar integration with fallback to user initials
  - Dynamic web root detection for file uploads
  - Avatar display in profile, navigation, and user listings
  - File validation and security measures
- **WHOIS Parsing Improvements**:
  - Enhanced WHOIS data parsing and processing
  - Better referral server handling and following
  - Improved domain availability detection
  - Status parsing cleanup and consistency
  - WHOIS server display improvements

### Changed
- Profile page completely redesigned with sidebar layout
- Session system migrated from file-based to database-backed
- Top navigation dropdown links updated with hash navigation
- Settings → System tab now shows auto-detected cron paths
- Help & Support menu links to GitHub repository
- Auth views refactored with base layout
- System section (Settings/Users) restricted to admins
- TLD Registry read-only for regular users
- Sidebar shows role-based links
- Profile integrated with dashboard layout
- Installation now via web UI instead of CLI
- Auto-redirect to installer on first run
- Domain management enhanced with tagging system
- Error handling improved with comprehensive logging
- WHOIS parsing enhanced with better data extraction
- User interface updated with avatar display throughout

### Security
- **Database Session Storage** - True session control with remote termination
- **Session Validation** - Every request validates session exists in database
- **Geolocation Logging** - Track suspicious login locations
- **Remember Token Linking** - Tokens tied to sessions, deleted together
- **Immediate Logout** - Deleted sessions invalidated within seconds
- Bcrypt password hashing
- Secure 32-byte tokens
- Time-limited tokens
- One-time use reset tokens
- HttpOnly secure cookies
- Email enumeration protection
- Session-based verification resend
- Admin-only route protection
- **Two-Factor Authentication** - TOTP and email backup codes for enhanced security
- **CAPTCHA Protection** - Anti-bot protection for login and registration
- **Advanced Error Logging** - Comprehensive error tracking and debugging
- **File Upload Security** - Avatar upload validation and secure file handling

### Technical
- **MVC Architecture Refactoring** - Complete separation of concerns
  - `LayoutHelper` - Global layout data (notifications, stats, settings)
  - `DomainHelper` - Domain formatting and business logic
  - `SessionHelper` - Session display formatting
  - `NotificationService` - Notification creation and management
  - All business logic removed from views (~265 lines cleaned)
- Database session handler implementing SessionHandlerInterface
- IP geolocation via ip-api.com (free, 45 req/min)
- Session validator middleware for real-time validation
- Automatic session cleanup (no cron needed for sessions)
- Flag-icons library integration for country flags
- User-agent parsing for device and browser detection
- Remember token cascade deletion on session termination
- Notification system with 7 notification types
- Welcome notifications on user creation and fresh install
- Upgrade notifications for admins with version tracking
- **TwoFactorService** - Complete 2FA implementation with TOTP and backup codes
- **CaptchaService** - Multi-provider CAPTCHA verification system
- **ErrorHandler** - Centralized error handling with database logging
- **Logger** - Enhanced logging service with structured context
- **AvatarHelper** - User avatar management with Gravatar integration
- **Tag Model** - Domain tagging system with user isolation
- **ErrorLog Model** - Error tracking and deduplication system

### Contributors
- Special thanks to @jadeops for auto-detected cron path improvement & XSS protection enhancement (PR #1)

## [1.0.0] - 2024-10-08

### Added
- Initial release of Domain Monitor
- Modern PHP 8.1+ MVC architecture
- Domain management system with CRUD operations
- Automatic WHOIS lookup for domain information
- Multi-channel notification system:
  - Email notifications via PHPMailer
  - Telegram bot integration
  - Discord webhook support
  - Slack webhook support
- Notification groups feature
- Assign domains to notification groups
- Dashboard with real-time statistics
- Domain status tracking (active, expiring_soon, expired, error)
- Notification logging system
- Customizable notification intervals
- Cron job for automated domain checks
- Test notification script
- Responsive, modern UI design
- Database migration system
- Comprehensive documentation
- Installation guide
- Basic login/logout authentication
- Security features (prepared statements, session management)
- **TLD Registry System with IANA integration**
  - Import and manage TLD data (RDAP servers, WHOIS servers, registry URLs)
  - Progressive import workflow with real-time progress tracking
  - Support for 1,400+ TLDs with automatic updates
  - Import logs and history tracking
- Advanced domain verification using TLD registry data
- RDAP protocol support for modern domain queries
- Automatic WHOIS server discovery per TLD
- Monitoring status change notifications (activated/deactivated alerts)
- Notification group assignment change alerts
- Enhanced domain detail view with channel status indicators
- Comprehensive notification threshold configuration
- Debug logging for notification thresholds

### Changed
- Unified design system across all views
  - Consistent header styles (bordered instead of gradients)
  - Standardized button sizes and padding
  - Consistent form input styling
  - Unified empty state designs
  - Removed emojis from UI elements
- Improved navigation flow (edit page returns to detail view)
- Enhanced cron job logging with threshold display
- Streamlined installation process
  - Encryption key auto-generation during migration
  - No separate script needed for encryption key setup

### Fixed
- Notification channel type display error in domain view
- Navigation redirect after domain update
- Cancel button redirect in domain edit page
- Design inconsistencies in notification group views

### Security
- Random secure password generation on installation
- One-time password display during migration
- Removed hardcoded default credentials
- 16-character cryptographically secure admin passwords

### Features
- ✅ Add, edit, delete, and view domains
- ✅ Automatic expiration date detection via WHOIS
- ✅ Support for multiple notification channels per group
- ✅ Flexible notification scheduling (60, 30, 21, 14, 7, 5, 3, 2, 1 days before)
- ✅ Email notifications with HTML templates
- ✅ Rich Discord embeds with color coding
- ✅ Telegram messages with formatting
- ✅ Slack blocks for structured messages
- ✅ Notification deduplication (prevent spam)
- ✅ Manual domain refresh
- ✅ Active/inactive domain toggle
- ✅ Comprehensive logging
- ✅ Statistics dashboard
- ✅ Recent notifications view
- ✅ Domain details with WHOIS data
- ✅ Nameserver display
- ✅ Notification history per domain

### Technical
- PHP 8.1+ with modern features (match expressions, typed properties)
- MySQL/MariaDB database
- PSR-4 autoloading
- Environment-based configuration
- MVC pattern implementation
- Service layer architecture
- Repository pattern for data access
- Interface-based notification channels
- JSON configuration storage
- Prepared statements for SQL injection prevention
- CSRF token support ready
- Responsive CSS with CSS variables
- No JavaScript framework dependencies (vanilla JS where needed)

### Documentation
- README.md with comprehensive guide
- Inline code documentation
- Configuration examples
- Troubleshooting guide

---

## Roadmap - Future Enhancements

- [x] User authentication system (completed - v1.1.0)
- [x] Session management with geolocation (completed - v1.1.0)
- [x] TLD Registry System (completed - v1.0.0)
- [x] Remote session termination (completed - v1.1.0)
- [x] In-app user notifications (completed - v1.1.0)
- [ ] Multi-user support with advanced permissions and roles
- [ ] API for external integrations
- [ ] Domain grouping/tagging
- [ ] Custom notification templates
- [ ] SMS notifications (Twilio)
- [ ] WhatsApp notifications
- [ ] Export functionality (CSV, PDF)
- [ ] Import domains from CSV
- [ ] Domain transfer tracking
- [ ] DNS record monitoring
- [ ] SSL certificate monitoring
- [ ] Downtime monitoring
- [ ] 2FA for login
- [ ] Mobile app
- [ ] Docker support
- [ ] Redis caching
- [ ] Rate limiting
- [ ] Webhook support for third-party integrations
- [ ] Dark mode UI toggle
- [ ] Multi-language support
- [ ] Advanced filtering and search
- [ ] Bulk operations
- [ ] Scheduled reports
- [ ] Integration with domain registrars

---

## Version History

### 1.1.0 (2025-10-09)
- **User Notifications System** - In-app notification center with 7 notification types, filtering, pagination
- **Advanced Session Management** - Database-backed sessions with geolocation (country, city, ISP)
- **Remote Session Control** - Terminate any device instantly with immediate logout validation
- **Enhanced Profile Page** - Sidebar navigation with 4 tabs, hash-based routing (#profile, #security, #sessions)
- **Two-Factor Authentication** - Complete TOTP implementation with email backup codes and rate limiting
- **CAPTCHA Security System** - Support for reCAPTCHA v2/v3 and Cloudflare Turnstile with admin configuration
- **Domain Tags System** - Organize domains with custom tags for better categorization and filtering
- **Advanced Error Logging** - Database-backed error tracking with deduplication, context capture, and admin interface
- **User Avatar System** - Avatar upload with Gravatar integration and fallback to user initials
- **Enhanced Logger Service** - Structured logging with context arrays and multiple log levels
- **WHOIS Parsing Improvements** - Enhanced domain data parsing, referral handling, and availability detection
- **MVC Architecture Refactoring** - 3 new Helpers (Layout, Domain, Session), ~265 lines cleaned from views
- **Geolocation Tracking** - IP-based location detection using ip-api.com, country flags with flag-icons
- **Device Detection** - Browser & device type parsing (Chrome/Firefox/Safari, Desktop/Mobile/Tablet)
- **Auto-Detected Cron Paths** - Settings show actual installation paths (thanks @jadeops)
- **Welcome Notifications** - Sent to new users on registration or fresh install
- **Upgrade Notifications** - Admins notified on system updates with version & migration count
- **Web-Based Installer** - Replaces CLI, auto-generates encryption key, one-time password display
- **Web-Based Updater** - `/install/update` for running new migrations with smart detection
- **User Registration** - Full signup flow with email verification, password reset, resend verification
- **User Management** - CRUD for users with filtering, sorting, pagination (admin-only)
- **Remember Me** - 30-day secure tokens linked to sessions, cascade deletion on logout
- **Session Validator** - Middleware validates sessions on every request for instant remote logout
- **Consistent UI/UX** - Unified filtering, sorting, pagination across Domains, Users, Notifications, TLD Registry
- **Smart Migrations** - Consolidated schema for fresh installs, incremental for upgrades
- **XSS Protection** - htmlspecialchars() applied across all user-facing data (thanks @jadeops)

### 1.0.0 (2024-10-08)
- Initial public release
- Created by [Hosteroid](https://www.hosteroid.uk) - Premium Hosting Solutions

---

## 🙏 Special Thanks

### Contributors
- **@jadeops** - Auto-detected cron path improvement & XSS protection enhancement (PR #1)

