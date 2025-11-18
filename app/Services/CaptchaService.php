<?php

namespace App\Services;

use App\Models\Setting;

class CaptchaService
{
    private Setting $settingModel;
    private array $captchaSettings;

    // Verification endpoints
    private const RECAPTCHA_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    private const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct()
    {
        $this->settingModel = new Setting();
        $this->captchaSettings = $this->settingModel->getCaptchaSettings();
    }

    /**
     * Verify CAPTCHA response based on configured provider
     *
     * @param string|null $response CAPTCHA response token from client
     * @param string|null $remoteIp Remote IP address of the user
     * @return array ['success' => bool, 'error' => string|null, 'score' => float|null]
     */
    public function verifyCaptcha(?string $response, ?string $remoteIp = null): array
    {
        $provider = $this->captchaSettings['provider'] ?? 'disabled';

        // If CAPTCHA is disabled, always return success
        if ($provider === 'disabled') {
            return ['success' => true, 'error' => null, 'score' => null];
        }

        // Validate that response token is provided
        if (empty($response)) {
            return ['success' => false, 'error' => 'CAPTCHA verification failed. Please try again.', 'score' => null];
        }

        // Verify based on provider
        switch ($provider) {
            case 'recaptcha_v2':
                return $this->verifyRecaptchaV2($response, $remoteIp);
            
            case 'recaptcha_v3':
                return $this->verifyRecaptchaV3($response, $remoteIp);
            
            case 'turnstile':
                return $this->verifyTurnstile($response, $remoteIp);
            
            default:
                // Unknown provider - allow through but log
                $logger = new \App\Services\Logger();
                $logger->warning('Unknown CAPTCHA provider', ['provider' => $provider]);
                return ['success' => true, 'error' => null, 'score' => null];
        }
    }

    /**
     * Verify reCAPTCHA v2 response
     */
    private function verifyRecaptchaV2(string $response, ?string $remoteIp): array
    {
        $secretKey = $this->captchaSettings['secret_key'] ?? '';
        
        if (empty($secretKey)) {
            $logger = new \App\Services\Logger();
            $logger->error('reCAPTCHA v2 secret key is not configured');
            return ['success' => false, 'error' => 'CAPTCHA is misconfigured. Please contact administrator.', 'score' => null];
        }

        $data = [
            'secret' => $secretKey,
            'response' => $response
        ];

        if ($remoteIp) {
            $data['remoteip'] = $remoteIp;
        }

        $result = $this->sendVerificationRequest(self::RECAPTCHA_VERIFY_URL, $data);

        if ($result === null) {
            return ['success' => false, 'error' => 'CAPTCHA verification service unavailable. Please try again later.', 'score' => null];
        }

        if (!isset($result['success']) || !$result['success']) {
            $errorCodes = $result['error-codes'] ?? [];
            $logger = new \App\Services\Logger();
            $logger->warning('reCAPTCHA v2 verification failed', ['error_codes' => $errorCodes]);
            return ['success' => false, 'error' => 'CAPTCHA verification failed. Please try again.', 'score' => null];
        }

        return ['success' => true, 'error' => null, 'score' => null];
    }

    /**
     * Verify reCAPTCHA v3 response (score-based)
     */
    private function verifyRecaptchaV3(string $response, ?string $remoteIp): array
    {
        $secretKey = $this->captchaSettings['secret_key'] ?? '';
        $threshold = floatval($this->captchaSettings['score_threshold'] ?? 0.5);
        
        if (empty($secretKey)) {
            $logger = new \App\Services\Logger();
            $logger->error('reCAPTCHA v3 secret key is not configured');
            return ['success' => false, 'error' => 'CAPTCHA is misconfigured. Please contact administrator.', 'score' => null];
        }

        $data = [
            'secret' => $secretKey,
            'response' => $response
        ];

        if ($remoteIp) {
            $data['remoteip'] = $remoteIp;
        }

        $result = $this->sendVerificationRequest(self::RECAPTCHA_VERIFY_URL, $data);

        if ($result === null) {
            return ['success' => false, 'error' => 'CAPTCHA verification service unavailable. Please try again later.', 'score' => null];
        }

        if (!isset($result['success']) || !$result['success']) {
            $errorCodes = $result['error-codes'] ?? [];
            $logger = new \App\Services\Logger();
            $logger->warning('reCAPTCHA v3 verification failed', ['error_codes' => $errorCodes]);
            return ['success' => false, 'error' => 'CAPTCHA verification failed. Please try again.', 'score' => null];
        }

        // Check score
        $score = floatval($result['score'] ?? 0);
        
        if ($score < $threshold) {
            $logger = new \App\Services\Logger();
            $logger->warning('reCAPTCHA v3 score too low', [
                'score' => $score,
                'threshold' => $threshold,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            return ['success' => false, 'error' => 'Security verification failed. Please try again or contact support.', 'score' => $score];
        }

        return ['success' => true, 'error' => null, 'score' => $score];
    }

    /**
     * Verify Cloudflare Turnstile response
     */
    private function verifyTurnstile(string $response, ?string $remoteIp): array
    {
        $secretKey = $this->captchaSettings['secret_key'] ?? '';
        
        if (empty($secretKey)) {
            $logger = new \App\Services\Logger();
            $logger->error('Turnstile secret key is not configured');
            return ['success' => false, 'error' => 'CAPTCHA is misconfigured. Please contact administrator.', 'score' => null];
        }

        $data = [
            'secret' => $secretKey,
            'response' => $response
        ];

        if ($remoteIp) {
            $data['remoteip'] = $remoteIp;
        }

        $result = $this->sendVerificationRequest(self::TURNSTILE_VERIFY_URL, $data);

        if ($result === null) {
            return ['success' => false, 'error' => 'CAPTCHA verification service unavailable. Please try again later.', 'score' => null];
        }

        if (!isset($result['success']) || !$result['success']) {
            $errorCodes = $result['error-codes'] ?? [];
            $logger = new \App\Services\Logger();
            $logger->warning('Turnstile verification failed', ['error_codes' => $errorCodes]);
            return ['success' => false, 'error' => 'CAPTCHA verification failed. Please try again.', 'score' => null];
        }

        return ['success' => true, 'error' => null, 'score' => null];
    }

    /**
     * Send verification request to CAPTCHA provider API
     */
    private function sendVerificationRequest(string $url, array $data): ?array
    {
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query($data),
                'timeout' => 10
            ]
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $logger = new \App\Services\Logger();
            $logger->error('Failed to connect to CAPTCHA verification service', ['url' => $url]);
            return null;
        }

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $logger = new \App\Services\Logger();
            $logger->error('Failed to parse CAPTCHA verification response', [
                'error' => json_last_error_msg()
            ]);
            return null;
        }

        return $result;
    }

    /**
     * Get current CAPTCHA settings for view rendering
     */
    public function getCaptchaSettings(): array
    {
        return $this->captchaSettings;
    }

    /**
     * Check if CAPTCHA is enabled
     */
    public function isEnabled(): bool
    {
        $provider = $this->captchaSettings['provider'] ?? 'disabled';
        return $provider !== 'disabled';
    }
}

