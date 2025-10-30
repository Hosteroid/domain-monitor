<?php

namespace App\Helpers;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use App\Models\Setting;
use App\Services\Logger;

class EmailHelper
{
    private static ?Setting $settingModel = null;
    private static ?Logger $logger = null;
    
    /**
     * Get the Setting model instance
     */
    private static function getSettingModel(): Setting
    {
        if (self::$settingModel === null) {
            self::$settingModel = new Setting();
        }
        return self::$settingModel;
    }
    
    /**
     * Get the Logger instance
     */
    private static function getLogger(): Logger
    {
        if (self::$logger === null) {
            self::$logger = new Logger('email');
        }
        return self::$logger;
    }
    
    /**
     * Get email settings from database
     */
    public static function getEmailSettings(): array
    {
        return self::getSettingModel()->getEmailSettings();
    }
    
    /**
     * Get app settings from database
     */
    public static function getAppSettings(): array
    {
        return self::getSettingModel()->getAppSettings();
    }
    
    /**
     * Create and configure a PHPMailer instance with proper settings
     */
    public static function createMailer(): PHPMailer
    {
        $emailSettings = self::getEmailSettings();
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = $emailSettings['mail_host'];
        $mail->SMTPAuth = !empty($emailSettings['mail_username']);
        $mail->Username = $emailSettings['mail_username'];
        $mail->Password = $emailSettings['mail_password'];
        $mail->Port = (int)$emailSettings['mail_port'];
        
        // Configure encryption based on port
        $port = (int)$emailSettings['mail_port'];
        $encryption = $emailSettings['mail_encryption'];
        
        // Auto-detect encryption for common ports
        if ($port === 465) {
            // Port 465 typically uses SSL
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($port === 587) {
            // Port 587 typically uses TLS
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($port === 25 || $port === 2525) {
            // Port 25/2525 might use TLS or no encryption
            if ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
            }
        } else {
            // Use configured encryption for other ports
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
            }
        }
        
        // Configure timeouts and SSL options
        $mail->Timeout = 30; // 30 seconds total timeout
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        
        // Set character encoding
        $mail->CharSet = 'UTF-8';
        
        return $mail;
    }
    
    /**
     * Send a test email
     */
    public static function sendTestEmail(string $toEmail): array
    {
        try {
            $emailSettings = self::getEmailSettings();
            $appSettings = self::getAppSettings();
            $mail = self::createMailer();

            // Always capture SMTP dialog for test emails (no env flag needed)
            $mail->SMTPDebug = SMTP::DEBUG_SERVER; // verbose for diagnostics
            $mail->Debugoutput = function($str, $level) {
                $lvlMap = [1 => 'CLIENT', 2 => 'SERVER', 3 => 'CONN', 4 => 'LOW'];
                $label = $lvlMap[$level] ?? (string)$level;
                self::getLogger()->debug('SMTP ' . $label . ': ' . trim($str));
            };
            
            // Set sender
            $mail->setFrom($emailSettings['mail_from_address'], $emailSettings['mail_from_name']);
            $mail->addAddress($toEmail);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Test Email from ' . $appSettings['app_name'];
            
            $appName = htmlspecialchars($appSettings['app_name']);
            $appUrl = htmlspecialchars($appSettings['app_url']);
            $currentTime = date('F j, Y g:i A');
            
            $mail->Body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #4A90E2; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
                    .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                    .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px; margin: 15px 0; }
                    .info-table { width: 100%; margin-top: 15px; }
                    .info-table td { padding: 8px; border-bottom: 1px solid #ddd; }
                    .info-table td:first-child { font-weight: bold; width: 150px; }
                    .footer { background: #333; color: white; padding: 10px; text-align: center; font-size: 12px; border-radius: 0 0 5px 5px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>✅ Email Test Successful!</h2>
                    </div>
                    <div class='content'>
                        <div class='success'>
                            <strong>Success!</strong> Your email configuration is working correctly.
                        </div>
                        <p>This is a test email from <strong>{$appName}</strong>.</p>
                        <p>If you're seeing this message, it means your SMTP settings are configured properly and emails are being delivered successfully.</p>
                        
                        <table class='info-table'>
                            <tr>
                                <td>SMTP Host:</td>
                                <td>" . htmlspecialchars($emailSettings['mail_host']) . "</td>
                            </tr>
                            <tr>
                                <td>SMTP Port:</td>
                                <td>" . htmlspecialchars($emailSettings['mail_port']) . "</td>
                            </tr>
                            <tr>
                                <td>Encryption:</td>
                                <td>" . htmlspecialchars($emailSettings['mail_encryption'] ?: 'None') . "</td>
                            </tr>
                            <tr>
                                <td>From Address:</td>
                                <td>" . htmlspecialchars($emailSettings['mail_from_address']) . "</td>
                            </tr>
                            <tr>
                                <td>Test Time:</td>
                                <td>{$currentTime}</td>
                            </tr>
                        </table>
                    </div>
                    <div class='footer'>
                        <p>This is an automated test message from {$appName}</p>
                        <p style='margin-top: 5px;'><a href='{$appUrl}' style='color: #4A90E2;'>Visit Dashboard</a></p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $mail->AltBody = "Email Test Successful!\n\n" .
                           "This is a test email from {$appName}.\n" .
                           "Your SMTP configuration is working correctly.\n\n" .
                           "SMTP Host: {$emailSettings['mail_host']}\n" .
                           "SMTP Port: {$emailSettings['mail_port']}\n" .
                           "From: {$emailSettings['mail_from_address']}\n" .
                           "Test Time: {$currentTime}";
            
            $mail->send();
            
            return [
                'success' => true,
                'message' => "Test email sent successfully to {$toEmail}. Please check your inbox."
            ];
            
        } catch (Exception $e) {
            $errorMessage = "Failed to send test email: " . $e->getMessage();
            $debugInfo = $mail->ErrorInfo ?? 'No debug info available';
            
            // Log the error using the application's logger
            self::getLogger()->error($errorMessage, [
                'email' => $toEmail,
                'smtp_error' => $debugInfo,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => $errorMessage,
                'error' => $e->getMessage(),
                'debug_info' => $debugInfo
            ];
        }
    }
    
    /**
     * Send a notification email
     */
    public static function sendNotificationEmail(string $toEmail, string $subject, string $message, array $data = [], bool $isHtml = false): array
    {
        try {
            $emailSettings = self::getEmailSettings();
            $appSettings = self::getAppSettings();
            $mail = self::createMailer();
            
            // Set sender
            $mail->setFrom($emailSettings['mail_from_address'], $emailSettings['mail_from_name']);
            $mail->addAddress($toEmail);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            
            if ($isHtml) {
                // Message is already formatted HTML, use it directly
                $mail->Body = $message;
                $mail->AltBody = strip_tags($message);
            } else {
                // Message is plain text, format it with template
                $mail->Body = self::formatHtmlBody($message, $data, $appSettings);
                $mail->AltBody = strip_tags($message);
            }
            
            $mail->send();
            
            return [
                'success' => true,
                'message' => "Email sent successfully to {$toEmail}"
            ];
            
        } catch (Exception $e) {
            $errorMessage = "Failed to send email: " . $e->getMessage();
            $debugInfo = $mail->ErrorInfo ?? 'No debug info available';
            
            // Log the error using the application's logger
            self::getLogger()->error($errorMessage, [
                'email' => $toEmail,
                'subject' => $subject,
                'smtp_error' => $debugInfo,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => $errorMessage,
                'error' => $e->getMessage(),
                'debug_info' => $debugInfo
            ];
        }
    }
    
    /**
     * Format HTML email body
     */
    private static function formatHtmlBody(string $message, array $data, array $appSettings): string
    {
        $messageHtml = nl2br(htmlspecialchars($message));
        $appName = htmlspecialchars($appSettings['app_name']);
        $appUrl = htmlspecialchars($appSettings['app_url']);
        
        // Build domain link if domain ID is available
        $domainLink = '';
        if (isset($data['domain_id'])) {
            $domainUrl = rtrim($appUrl, '/') . '/domains/' . $data['domain_id'];
            $domainLink = "<p style='margin-top: 15px;'><a href='$domainUrl' class='button'>View Domain Details</a></p>";
        }

        return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #4A90E2; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
                .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                .footer { background: #333; color: white; padding: 10px; text-align: center; font-size: 12px; border-radius: 0 0 5px 5px; }
                .button { display: inline-block; padding: 10px 20px; background: #4A90E2; color: white; text-decoration: none; border-radius: 5px; margin-top: 10px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>🔔 {$appName} Alert</h2>
                </div>
                <div class='content'>
                    <p>$messageHtml</p>
                    $domainLink
                </div>
                <div class='footer'>
                    <p>This is an automated message from {$appName}</p>
                    <p style='margin-top: 5px;'><a href='$appUrl' style='color: #4A90E2;'>Visit Dashboard</a></p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    /**
     * Send verification email
     */
    public static function sendVerificationEmail(string $email, string $fullName, string $token): array
    {
        try {
            $appSettings = self::getAppSettings();
            $verifyUrl = $appSettings['app_url'] . '/verify-email?token=' . $token;
            
            $subject = 'Verify Your Email Address';
            
            // Create a properly formatted HTML email
            $htmlContent = "
            <div style='max-width: 600px; margin: 0 auto; font-family: Arial, sans-serif;'>
                <div style='background: #4A90E2; color: white; padding: 20px; border-radius: 5px 5px 0 0; text-align: center;'>
                    <h2 style='margin: 0; font-size: 24px;'>🔔 Welcome to {$appSettings['app_name']}!</h2>
                </div>
                
                <div style='background: #f9f9f9; padding: 30px; border: 1px solid #ddd;'>
                    <h3 style='color: #333; margin-top: 0;'>Hello {$fullName},</h3>
                    
                    <p style='color: #555; line-height: 1.6; margin-bottom: 20px;'>
                        Thank you for registering with {$appSettings['app_name']}! To complete your account setup, 
                        please verify your email address by clicking the button below:
                    </p>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='{$verifyUrl}' style='background: #4A90E2; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold; font-size: 16px;'>
                            ✅ Verify Email Address
                        </a>
                    </div>
                    
                    <div style='background: #fff; border: 1px solid #ddd; border-radius: 5px; padding: 15px; margin: 20px 0;'>
                        <p style='margin: 0 0 10px 0; color: #666; font-size: 14px;'>
                            <strong>Or copy and paste this URL into your browser:</strong>
                        </p>
                        <p style='margin: 0; word-break: break-all; color: #4A90E2; font-size: 14px;'>
                            {$verifyUrl}
                        </p>
                    </div>
                    
                    <div style='background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; padding: 15px; margin: 20px 0;'>
                        <p style='margin: 0; color: #856404; font-size: 14px;'>
                            <strong>⏰ Important:</strong> This verification link will expire in 24 hours for security reasons.
                        </p>
                    </div>
                    
                    <p style='color: #666; font-size: 14px; line-height: 1.6;'>
                        If you did not create an account with {$appSettings['app_name']}, please ignore this email. 
                        No further action is required.
                    </p>
                </div>
                
                <div style='background: #333; color: white; padding: 15px; text-align: center; font-size: 12px; border-radius: 0 0 5px 5px;'>
                    <p style='margin: 0 0 10px 0;'>This is an automated message from {$appSettings['app_name']}</p>
                    <a href='{$appSettings['app_url']}' style='color: #4A90E2; text-decoration: none;'>Visit Dashboard</a>
                </div>
            </div>
            ";
            
            return self::sendNotificationEmail($email, $subject, $htmlContent, [], true);
            
        } catch (\Exception $e) {
            $errorMessage = "Failed to send verification email: " . $e->getMessage();
            
            // Log the error using the application's logger
            self::getLogger()->error($errorMessage, [
                'email' => $email,
                'full_name' => $fullName,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => $errorMessage,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Send password reset email
     */
    public static function sendPasswordResetEmail(string $email, string $fullName, string $token): array
    {
        try {
            $appSettings = self::getAppSettings();
            $resetUrl = $appSettings['app_url'] . '/reset-password?token=' . $token;
            
            $subject = 'Reset Your Password';
            
            // Create a properly formatted HTML email
            $htmlContent = "
            <div style='max-width: 600px; margin: 0 auto; font-family: Arial, sans-serif;'>
                <div style='background: #dc3545; color: white; padding: 20px; border-radius: 5px 5px 0 0; text-align: center;'>
                    <h2 style='margin: 0; font-size: 24px;'>🔒 Password Reset Request</h2>
                </div>
                
                <div style='background: #f9f9f9; padding: 30px; border: 1px solid #ddd;'>
                    <h3 style='color: #333; margin-top: 0;'>Hello {$fullName},</h3>
                    
                    <p style='color: #555; line-height: 1.6; margin-bottom: 20px;'>
                        We received a request to reset your password for your {$appSettings['app_name']} account. 
                        If you made this request, click the button below to create a new password:
                    </p>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='{$resetUrl}' style='background: #dc3545; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold; font-size: 16px;'>
                            🔑 Reset Password
                        </a>
                    </div>
                    
                    <div style='background: #fff; border: 1px solid #ddd; border-radius: 5px; padding: 15px; margin: 20px 0;'>
                        <p style='margin: 0 0 10px 0; color: #666; font-size: 14px;'>
                            <strong>Or copy and paste this URL into your browser:</strong>
                        </p>
                        <p style='margin: 0; word-break: break-all; color: #dc3545; font-size: 14px;'>
                            {$resetUrl}
                        </p>
                    </div>
                    
                    <div style='background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; padding: 15px; margin: 20px 0;'>
                        <p style='margin: 0; color: #856404; font-size: 14px;'>
                            <strong>⏰ Important:</strong> This password reset link will expire in 1 hour for security reasons.
                        </p>
                    </div>
                    
                    <div style='background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px; padding: 15px; margin: 20px 0;'>
                        <p style='margin: 0; color: #721c24; font-size: 14px;'>
                            <strong>🔒 Security Notice:</strong> If you did not request a password reset, please ignore this email. 
                            Your password will remain unchanged and no action is required.
                        </p>
                    </div>
                </div>
                
                <div style='background: #333; color: white; padding: 15px; text-align: center; font-size: 12px; border-radius: 0 0 5px 5px;'>
                    <p style='margin: 0 0 10px 0;'>This is an automated message from {$appSettings['app_name']}</p>
                    <a href='{$appSettings['app_url']}' style='color: #4A90E2; text-decoration: none;'>Visit Dashboard</a>
                </div>
            </div>
            ";
            
            return self::sendNotificationEmail($email, $subject, $htmlContent, [], true);
            
        } catch (\Exception $e) {
            $errorMessage = "Failed to send password reset email: " . $e->getMessage();
            
            // Log the error using the application's logger
            self::getLogger()->error($errorMessage, [
                'email' => $email,
                'full_name' => $fullName,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => $errorMessage,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get email subject based on data
     */
    public static function getEmailSubject(array $data): string
    {
        if (isset($data['domain'])) {
            $daysLeft = $data['days_left'];
            if ($daysLeft <= 0) {
                return "🚨 URGENT: Domain {$data['domain']} has EXPIRED";
            }
            if ($daysLeft == 1) {
                return "⚠️ CRITICAL: Domain {$data['domain']} expires TOMORROW";
            }
            return "⚠️ Domain Expiration Alert: {$data['domain']} ({$daysLeft} days)";
        }

        return "Domain Monitor Alert";
    }

    /**
     * Send 2FA verification code email
     */
    public static function sendTwoFactorCode(string $email, string $fullName, string $code): array
    {
        try {
            $appSettings = self::getAppSettings();
            $subject = 'Your Two-Factor Authentication Code';
            
            // Create a properly formatted HTML email
            $htmlContent = "
            <div style='max-width: 600px; margin: 0 auto; font-family: Arial, sans-serif;'>
                <div style='background: #28a745; color: white; padding: 20px; border-radius: 5px 5px 0 0; text-align: center;'>
                    <h2 style='margin: 0; font-size: 24px;'>🔐 Two-Factor Authentication</h2>
                </div>
                
                <div style='background: #f9f9f9; padding: 30px; border: 1px solid #ddd;'>
                    <h3 style='color: #333; margin-top: 0;'>Your Verification Code</h3>
                    
                    <p style='color: #555; line-height: 1.6; margin-bottom: 20px;'>Hello {$fullName},</p>
                    
                    <p style='color: #555; line-height: 1.6; margin-bottom: 20px;'>
                        Use this code to complete your two-factor authentication:
                    </p>
                    
                    <div style='background: white; border: 2px solid #28a745; border-radius: 5px; padding: 25px; text-align: center; margin: 25px 0;'>
                        <div style='font-size: 32px; font-weight: bold; color: #28a745; letter-spacing: 8px; font-family: monospace;'>{$code}</div>
                    </div>
                    
                    <div style='background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; padding: 15px; margin: 20px 0;'>
                        <p style='margin: 0; color: #856404; font-size: 14px;'>
                            <strong>⏰ Important:</strong> This code expires in 10 minutes for security reasons.
                        </p>
                    </div>
                    
                    <p style='color: #666; font-size: 14px; line-height: 1.6;'>
                        If you did not request this code, please ignore this email. 
                        No further action is required.
                    </p>
                </div>
                
                <div style='background: #333; color: white; padding: 15px; text-align: center; font-size: 12px; border-radius: 0 0 5px 5px;'>
                    <p style='margin: 0 0 10px 0;'>This is an automated message from {$appSettings['app_name']}</p>
                    <a href='{$appSettings['app_url']}' style='color: #4A90E2; text-decoration: none;'>Visit Dashboard</a>
                </div>
            </div>
            ";
            
            return self::sendNotificationEmail($email, $subject, $htmlContent, [], true);
            
        } catch (\Exception $e) {
            $errorMessage = "Failed to send 2FA verification email: " . $e->getMessage();
            
            // Log the error using the application's logger
            self::getLogger()->error($errorMessage, [
                'email' => $email,
                'full_name' => $fullName,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => $errorMessage,
                'error' => $e->getMessage()
            ];
        }
    }
}
