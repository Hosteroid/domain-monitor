<?php

namespace App\Models;

use Core\Model;
use App\Services\Logger;

class Setting extends Model
{
    /** Choices for how long DNS history is kept, in days */
    public const DNS_HISTORY_RETENTION_DAYS = [30, 90, 180, 365, 730];

    protected static string $table = 'settings';
    private Logger $logger;

    public function __construct()
    {
        parent::__construct();
        $this->logger = new Logger('settings');
    }

    /**
     * Get setting by key
     */
    public function getByKey(string $key): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get setting value by key
     */
    public function getValue(string $key, $default = null)
    {
        $setting = $this->getByKey($key);
        return $setting ? $setting['setting_value'] : $default;
    }

    /**
     * Set or update setting value
     */
    public function setValue(string $key, $value): bool
    {
        $existing = $this->getByKey($key);
        
        if ($existing) {
            $stmt = $this->db->prepare("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
            return $stmt->execute([$value, $key]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
            return $stmt->execute([$key, $value]);
        }
    }

    /**
     * Get all settings as key-value pairs
     */
    public function getAllAsKeyValue(): array
    {
        $settings = $this->all();
        $result = [];
        
        foreach ($settings as $setting) {
            $result[$setting['setting_key']] = $setting['setting_value'];
        }
        
        return $result;
    }

    /**
     * Get notification days as array
     */
    public function getNotificationDays(): array
    {
        $value = $this->getValue('notification_days_before', '30,15,7,3,1');
        return array_map('intval', explode(',', $value));
    }

    /**
     * Get the days after a domain expired to send a reminder, smallest first (empty for none)
     */
    public function getPostExpiryNotificationDays(): array
    {
        $value = (string)$this->getValue('post_expiry_notification_days', '7,14,30');
        $days = array_filter(array_map('intval', explode(',', $value)), static fn(int $day): bool => $day > 0);
        $days = array_values(array_unique($days));
        sort($days, SORT_NUMERIC);

        return $days;
    }

    /**
     * Get SSL certificate reminder days, largest first
     */
    public function getSslNotificationDays(): array
    {
        $value = (string)$this->getValue('ssl_notification_days', '14,7,3,1');
        $days = array_filter(array_map('intval', explode(',', $value)), static fn(int $day): bool => $day > 0);
        $days = array_values(array_unique($days));
        rsort($days, SORT_NUMERIC);

        return $days ?: [14, 7, 3, 1];
    }

    /**
     * Whether new domains start with SSL monitoring turned on
     */
    public function isSslMonitoringOnForNewDomains(): bool
    {
        return $this->getValue('ssl_monitoring_new_domains', '0') === '1';
    }

    /**
     * Days left at which an SSL certificate counts as expiring soon (the first reminder day)
     */
    public function getSslExpiringSoonDays(): int
    {
        return max($this->getSslNotificationDays());
    }

    /**
     * Certificate transparency sources a DNS deep scan asks, in order (keys of DnsService::CT_LOG_SOURCES)
     */
    public function getCtLogSources(): array
    {
        $ids = array_map('trim', explode(',', (string)$this->getValue('ct_log_sources', 'crtsh,certspotter')));
        return array_values(array_intersect($ids, array_keys(\App\Services\DnsService::CT_LOG_SOURCES)));
    }

    /**
     * Cert Spotter API key, decrypted ('' when none is saved)
     */
    public function getCertSpotterApiKey(): string
    {
        $stored = (string)$this->getValue('certspotter_api_key', '');
        if ($stored === '') {
            return '';
        }

        try {
            return (new \Core\Encryption())->decrypt($stored);
        } catch (\Exception $e) {
            $this->logger->warning("Failed to decrypt certspotter_api_key", [
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Save the Cert Spotter API key encrypted ('' removes it)
     */
    public function setCertSpotterApiKey(string $apiKey): bool
    {
        return $this->setValue('certspotter_api_key', $apiKey === '' ? '' : (new \Core\Encryption())->encrypt($apiKey));
    }

    /**
     * Whether DNS history is saved. This is the global switch: each domain has its own as well
     * (domains.dns_history_enabled).
     */
    public function isDnsHistoryOn(): bool
    {
        return $this->getValue('dns_history_enabled', '1') === '1';
    }

    /**
     * Days DNS history is kept (one of DNS_HISTORY_RETENTION_DAYS)
     */
    public function getDnsHistoryRetentionDays(): int
    {
        $days = (int)$this->getValue('dns_history_retention_days', 90);
        return in_array($days, self::DNS_HISTORY_RETENTION_DAYS, true) ? $days : 90;
    }

    /**
     * Several settings in one query. A key that has no row is left out.
     */
    public function getValues(array $keys): array
    {
        if (!$keys) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('
            . implode(',', array_fill(0, count($keys), '?')) . ')'
        );
        $stmt->execute(array_values($keys));

        return array_column($stmt->fetchAll(), 'setting_value', 'setting_key');
    }

    /** Values of the setting api_access */
    public const API_ACCESS = ['off', 'read', 'read_write'];

    /** Requests a token may make per minute: the bounds of the setting and its default */
    public const API_RATE_LIMIT_MIN = 10;
    public const API_RATE_LIMIT_MAX = 6000;
    public const API_RATE_LIMIT_DEFAULT = 120;

    /**
     * The API settings: access (off, read, read_write), users (all, admins) and the requests a
     * token may make per minute. Off until the migration has added the rows.
     *
     * @return array{access: string, users: string, rate_limit: int}
     */
    public function getApiSettings(): array
    {
        return self::apiSettingsFrom($this->getValues(['api_access', 'api_users', 'api_rate_limit']));
    }

    /**
     * The API settings read from rows fetched with getValues(), for a caller that fetches them
     * together with others
     *
     * @return array{access: string, users: string, rate_limit: int}
     */
    public static function apiSettingsFrom(array $values): array
    {
        $access = (string)($values['api_access'] ?? 'off');
        $limit = $values['api_rate_limit'] ?? null;
        $limit = is_numeric($limit) ? (int)$limit : self::API_RATE_LIMIT_DEFAULT;

        return [
            'access' => in_array($access, self::API_ACCESS, true) ? $access : 'off',
            'users' => ($values['api_users'] ?? 'all') === 'admins' ? 'admins' : 'all',
            'rate_limit' => max(self::API_RATE_LIMIT_MIN, min(self::API_RATE_LIMIT_MAX, $limit)),
        ];
    }

    /**
     * Login attempt limit: default, lowest and highest value of each setting. The setting keys
     * are these names after "login_limit_".
     */
    public const LOGIN_LIMIT = [
        'per_address' => [5, 0, 100],
        'per_account' => [20, 0, 1000],
        'minutes' => [15, 1, 1440],
    ];

    /**
     * The login attempt limit: failed logins a name may have from one address and from all
     * addresses together (0 = not counted), and the minutes a failed login counts. The defaults
     * until the migration has added the rows.
     *
     * @return array{per_address: int, per_account: int, minutes: int}
     */
    public function getLoginLimitSettings(): array
    {
        $values = $this->getValues(array_map(
            static fn(string $name): string => 'login_limit_' . $name,
            array_keys(self::LOGIN_LIMIT)
        ));

        $settings = [];
        foreach (self::LOGIN_LIMIT as $name => [$default, $min, $max]) {
            $value = $values['login_limit_' . $name] ?? null;
            $settings[$name] = is_numeric($value) ? max($min, min($max, (int)$value)) : $default;
        }

        return $settings;
    }

    /**
     * Account email limit: default, lowest and highest value of each setting. The setting keys
     * are these names after "email_limit_". The window is never longer than a password reset
     * link is valid: while a count is full, the newest link in the inbox then still works until
     * the count starts again.
     */
    public const EMAIL_LIMIT = [
        'per_recipient' => [3, 0, 100],
        'minutes' => [60, 1, User::RESET_LINK_MINUTES],
    ];

    /**
     * The account email limit: password reset emails, and verification emails, one address may
     * get within the time window (0 = not counted), and the minutes the window lasts. The
     * defaults until the migration has added the rows.
     *
     * @return array{per_recipient: int, minutes: int}
     */
    public function getEmailLimitSettings(): array
    {
        $values = $this->getValues(array_map(
            static fn(string $name): string => 'email_limit_' . $name,
            array_keys(self::EMAIL_LIMIT)
        ));

        $settings = [];
        foreach (self::EMAIL_LIMIT as $name => [$default, $min, $max]) {
            $value = $values['email_limit_' . $name] ?? null;
            $settings[$name] = is_numeric($value) ? max($min, min($max, (int)$value)) : $default;
        }

        return $settings;
    }

    /**
     * Whether billing is switched on. Off until the migration has added the setting.
     */
    public function isBillingOn(): bool
    {
        return $this->getValue('billing_enabled', '0') === '1';
    }

    /**
     * Base currency of every billing total
     */
    public function getBillingCurrency(): string
    {
        $code = strtoupper((string)$this->getValue('billing_currency', 'USD'));
        return preg_match('/^[A-Z]{3}$/', $code) ? $code : 'USD';
    }

    /**
     * Whether a detected renewal is logged as a payment
     */
    public function isBillingAutoLogOn(): bool
    {
        return $this->getValue('billing_auto_log_renewals', '1') === '1';
    }

    /**
     * Whether expiry reminders carry the renewal cost
     */
    public function isBillingCostInReminders(): bool
    {
        return $this->getValue('billing_cost_in_reminders', '1') === '1';
    }

    /**
     * Get check interval hours
     */
    public function getCheckIntervalHours(): int
    {
        return (int)$this->getValue('check_interval_hours', 24);
    }

    /**
     * Update notification days
     */
    public function updateNotificationDays(array $days): bool
    {
        $value = implode(',', array_map('intval', $days));
        return $this->setValue('notification_days_before', $value);
    }

    /**
     * Update check interval
     */
    public function updateCheckInterval(int $hours): bool
    {
        return $this->setValue('check_interval_hours', $hours);
    }

    /**
     * Get last check run timestamp
     */
    public function getLastCheckRun(): ?string
    {
        return $this->getValue('last_check_run');
    }

    /**
     * Update last check run timestamp
     */
    public function updateLastCheckRun(): bool
    {
        return $this->setValue('last_check_run', date('Y-m-d H:i:s'));
    }

    /**
     * Get application version
     */
    public function getAppVersion(): string
    {
        return $this->getValue('app_version', '1.2.0');
    }

    /**
     * Get application settings
     */
    public function getAppSettings(): array
    {
        return [
            'app_name' => $this->getValue('app_name', 'Domain Monitor'),
            'app_url' => $this->getValue('app_url', 'http://localhost:8000'),
            'app_timezone' => $this->getValue('app_timezone', 'UTC'),
            'app_version' => $this->getAppVersion()
        ];
    }

    /**
     * Get email settings
     */
    public function getEmailSettings(): array
    {
        $encryptedPassword = $this->getValue('mail_password', '');
        
        // Decrypt password if it's encrypted
        $decryptedPassword = '';
        if (!empty($encryptedPassword)) {
            try {
                $encryption = new \Core\Encryption();
                $decryptedPassword = $encryption->decrypt($encryptedPassword);
            } catch (\Exception $e) {
                // If decryption fails, it might be plaintext (migration scenario)
                // Try to use as-is but log the issue
                $this->logger->warning("Failed to decrypt mail_password", [
                    'error' => $e->getMessage()
                ]);
                $decryptedPassword = $encryptedPassword;
            }
        }
        
        return [
            'mail_host' => $this->getValue('mail_host', 'smtp.mailtrap.io'),
            'mail_port' => $this->getValue('mail_port', '2525'),
            'mail_username' => $this->getValue('mail_username', ''),
            'mail_password' => $decryptedPassword,
            'mail_encryption' => $this->getValue('mail_encryption', 'tls'),
            'mail_from_address' => $this->getValue('mail_from_address', 'noreply@domainmonitor.com'),
            'mail_from_name' => $this->getValue('mail_from_name', 'Domain Monitor')
        ];
    }

    /**
     * Update application settings
     */
    public function updateAppSettings(array $settings): bool
    {
        $result = true;
        foreach ($settings as $key => $value) {
            if (!$this->setValue($key, $value)) {
                $result = false;
            }
        }
        return $result;
    }

    /**
     * Update email settings
     */
    public function updateEmailSettings(array $settings): bool
    {
        $result = true;
        
        foreach ($settings as $key => $value) {
            // Encrypt mail_password before storing
            if ($key === 'mail_password' && !empty($value)) {
                try {
                    $encryption = new \Core\Encryption();
                    $value = $encryption->encrypt($value);
                } catch (\Exception $e) {
                    $this->logger->error("Failed to encrypt mail_password", [
                        'error' => $e->getMessage()
                    ]);
                    return false;
                }
            }
            
            if (!$this->setValue($key, $value)) {
                $result = false;
            }
        }
        return $result;
    }

    /**
     * Get CAPTCHA settings
     */
    public function getCaptchaSettings(): array
    {
        $encryptedSecret = $this->getValue('captcha_secret_key', '');
        
        // Decrypt secret key if it's encrypted
        $decryptedSecret = '';
        if (!empty($encryptedSecret)) {
            try {
                $encryption = new \Core\Encryption();
                $decryptedSecret = $encryption->decrypt($encryptedSecret);
            } catch (\Exception $e) {
                // If decryption fails, it might be plaintext (migration scenario)
                $this->logger->warning("Failed to decrypt captcha_secret_key", [
                    'error' => $e->getMessage()
                ]);
                $decryptedSecret = $encryptedSecret;
            }
        }
        
        return [
            'provider' => $this->getValue('captcha_provider', 'disabled'),
            'site_key' => $this->getValue('captcha_site_key', ''),
            'secret_key' => $decryptedSecret,
            'score_threshold' => $this->getValue('recaptcha_v3_score_threshold', '0.5')
        ];
    }

    /**
     * Update CAPTCHA settings
     */
    public function updateCaptchaSettings(array $settings): bool
    {
        $result = true;
        
        // Encrypt secret key before storing
        if (isset($settings['captcha_secret_key']) && !empty($settings['captcha_secret_key'])) {
            try {
                $encryption = new \Core\Encryption();
                $settings['captcha_secret_key'] = $encryption->encrypt($settings['captcha_secret_key']);
            } catch (\Exception $e) {
                $this->logger->error("Failed to encrypt captcha_secret_key", [
                    'error' => $e->getMessage()
                ]);
                return false;
            }
        }
        
        foreach ($settings as $key => $value) {
            if (!$this->setValue($key, $value)) {
                $result = false;
            }
        }
        
        return $result;
    }

    /**
     * 2FA code limit: default, lowest and highest value of each setting. The setting keys are
     * these names after "two_factor_rate_limit_".
     */
    public const TWO_FACTOR_LIMIT = [
        'attempts' => [5, 3, 10],
        'minutes' => [15, 5, 60],
    ];

    /** Minutes an email code is valid: default, lowest and highest value */
    public const TWO_FACTOR_EMAIL_CODE_MINUTES = [10, 1, 30];

    /**
     * The 2FA code limit: wrong codes an account may enter, from all addresses together, and
     * the minutes a wrong code counts. As many email codes can be requested in that time. The
     * default where a row is missing or holds no number: the row for the wrong codes is only
     * there once the 2FA settings have been saved.
     *
     * @return array{attempts: int, minutes: int}
     */
    public function getTwoFactorLimitSettings(): array
    {
        $values = $this->getValues(array_map(
            static fn(string $name): string => 'two_factor_rate_limit_' . $name,
            array_keys(self::TWO_FACTOR_LIMIT)
        ));

        $settings = [];
        foreach (self::TWO_FACTOR_LIMIT as $name => [$default, $min, $max]) {
            $value = $values['two_factor_rate_limit_' . $name] ?? null;
            $settings[$name] = is_numeric($value) ? max($min, min($max, (int)$value)) : $default;
        }

        return $settings;
    }

    /**
     * Minutes an email code is valid
     */
    public function getTwoFactorEmailCodeMinutes(): int
    {
        [$default, $min, $max] = self::TWO_FACTOR_EMAIL_CODE_MINUTES;
        $value = $this->getValue('two_factor_email_code_expiry_minutes', $default);

        return is_numeric($value) ? max($min, min($max, (int)$value)) : $default;
    }

    /**
     * Get 2FA settings
     */
    public function getTwoFactorSettings(): array
    {
        $limit = $this->getTwoFactorLimitSettings();

        return [
            'policy' => $this->getValue('two_factor_policy', 'optional'),
            'rate_limit_attempts' => $limit['attempts'],
            'rate_limit_minutes' => $limit['minutes'],
            'limit_bounds' => self::TWO_FACTOR_LIMIT,
            'email_code_expiry_minutes' => $this->getTwoFactorEmailCodeMinutes()
        ];
    }

    /**
     * Update 2FA settings
     */
    public function updateTwoFactorSettings(array $settings): bool
    {
        $result = true;
        
        foreach ($settings as $key => $value) {
            if (!$this->setValue($key, $value)) {
                $result = false;
            }
        }
        
        return $result;
    }

    /**
     * Get notification status triggers as array
     * Returns which domain status changes should trigger notifications
     */
    public function getNotificationStatusTriggers(): array
    {
        $value = $this->getValue('notification_status_triggers', 'available,registered,expired,redemption_period,pending_delete,renewed');
        return array_map('trim', explode(',', $value));
    }

    /**
     * Update notification status triggers
     */
    public function updateNotificationStatusTriggers(array $triggers): bool
    {
        $validTriggers = ['available', 'registered', 'expired', 'redemption_period', 'pending_delete', 'renewed'];
        $triggers = array_intersect($triggers, $validTriggers);
        $value = implode(',', $triggers);
        return $this->setValue('notification_status_triggers', $value);
    }

    /**
     * Get update settings
     */
    public function getUpdateSettings(): array
    {
        return [
            'update_channel' => $this->getValue('update_channel', 'stable'),
            'last_update_check' => $this->getValue('last_update_check', null),
            'latest_available_version' => $this->getValue('latest_available_version', null),
            'latest_release_notes' => $this->getValue('latest_release_notes', ''),
            'latest_release_url' => $this->getValue('latest_release_url', ''),
            'latest_release_published_at' => $this->getValue('latest_release_published_at', ''),
            'installed_commit_sha' => $this->getValue('installed_commit_sha', null),
            'update_backup_path' => $this->getValue('update_backup_path', null),
            'update_db_backup_path' => $this->getValue('update_db_backup_path', null),
            'commits_behind_count' => (int) $this->getValue('commits_behind_count', 0),
            'latest_remote_sha' => $this->getValue('latest_remote_sha', ''),
            'update_badge_enabled' => $this->getValue('update_badge_enabled', '1'),
        ];
    }

    /**
     * Clear old notification logs
     */
    public function clearOldNotificationLogs(int $daysOld = 30): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM notification_logs WHERE sent_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$daysOld]);
        return $stmt->rowCount();
    }
}

