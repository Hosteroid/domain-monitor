<?php

namespace App\Models;

use Core\Model;
use App\Services\Logger;

class Setting extends Model
{
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
        return $this->getValue('app_version', '1.1.2');
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
     * Get 2FA settings
     */
    public function getTwoFactorSettings(): array
    {
        return [
            'policy' => $this->getValue('two_factor_policy', 'optional'),
            'rate_limit_minutes' => (int)$this->getValue('two_factor_rate_limit_minutes', 15),
            'email_code_expiry_minutes' => (int)$this->getValue('two_factor_email_code_expiry_minutes', 10)
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
        $value = $this->getValue('notification_status_triggers', 'available,registered,expired,redemption_period,pending_delete');
        return array_map('trim', explode(',', $value));
    }

    /**
     * Update notification status triggers
     */
    public function updateNotificationStatusTriggers(array $triggers): bool
    {
        $validTriggers = ['available', 'registered', 'expired', 'redemption_period', 'pending_delete'];
        $triggers = array_intersect($triggers, $validTriggers);
        $value = implode(',', $triggers);
        return $this->setValue('notification_status_triggers', $value);
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

