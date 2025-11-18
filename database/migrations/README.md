# Database Migrations

## Fresh Installation (v1.1.0+)

For new installations, use the consolidated schema:

- **`000_initial_schema_v1.1.0.sql`** - Complete database schema for v1.1.0

**Install via:** Web installer at `/install`

## Incremental Migrations (v1.0.0 → v1.1.0)

If upgrading from v1.0.0, these incremental migrations will be applied:

- `001_create_tables.sql` - Core tables (domains, groups, channels, logs)
- `002_create_users_table.sql` - Users table
- `003_add_whois_fields.sql` - WHOIS data fields
- `004_create_tld_registry_table.sql` - TLD registry
- `005_update_tld_import_logs.sql` - Import logs updates
- `006_add_complete_workflow_import_type.sql` - Workflow import type
- `007_add_app_and_email_settings.sql` - Application settings
- `008_add_notes_to_domains.sql` - Domain notes field
- `009_add_authentication_features.sql` - Authentication system
- `010_add_app_version_setting.sql` - Version setting
- `011_create_sessions_table.sql` - Session management table
- `012_link_remember_tokens_to_sessions.sql` - Remember token session linking
- `013_create_user_notifications_table.sql` - User notifications table
- `014_add_captcha_settings.sql` - CAPTCHA settings (v2, v3, Turnstile)
- `015_create_error_logs_table.sql` - Error logging and debugging system
- `016_add_tags_to_domains.sql` - Domain tags for organization
- `017_add_two_factor_authentication.sql` - Two-factor authentication (TOTP)
- `018_add_user_isolation.sql` - User isolation mode settings
- `019_add_webhook_channel_type.sql` - Webhook and Mattermost channel support
- `020_create_tags_system.sql` - Advanced tagging system for domains
- `021_add_avatar_field.sql` - User avatar field
- `022_add_pushover_channel_type.sql` - Pushover notification channel support
- `023_update_app_version_to_1.1.1.sql` - Update version to 1.1.1

**Upgrade via:** Web updater at `/install/update`

## Migration System

The installer automatically:
- Detects if this is a fresh install or upgrade
- Uses consolidated schema for fresh installs
- Uses incremental migrations for upgrades
- Tracks executed migrations in `migrations` table
- Prevents re-running completed migrations

