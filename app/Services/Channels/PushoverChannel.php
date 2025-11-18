<?php

namespace App\Services\Channels;

use GuzzleHttp\Client;
use App\Services\Logger;

class PushoverChannel implements NotificationChannelInterface
{
    private Client $client;
    private Logger $logger;
    private const API_URL = 'https://api.pushover.net/1/messages.json';

    public function __construct()
    {
        $this->client = new Client(['timeout' => 10]);
        $this->logger = new Logger('pushover_channel');
    }

    public function send(array $config, string $message, array $data = []): bool
    {
        // Required configuration
        if (!isset($config['api_token']) || !isset($config['user_key'])) {
            $this->logger->error('Pushover configuration incomplete', [
                'has_api_token' => isset($config['api_token']),
                'has_user_key' => isset($config['user_key'])
            ]);
            return false;
        }

        try {
            // Determine priority based on days left
            $priority = $this->getPriorityByDaysLeft($data['days_left'] ?? null);
            
            // Build request payload
            $payload = [
                'token' => $config['api_token'],      // Your application's API token
                'user' => $config['user_key'],        // User/group key
                'message' => $message,
                'priority' => $priority,
            ];

            // Optional: Add title
            if (isset($data['domain'])) {
                $payload['title'] = '🔔 Domain Expiration Alert: ' . $data['domain'];
            } else {
                $payload['title'] = '🔔 Domain Monitor Notification';
            }

            // Optional: Add device (if configured)
            if (!empty($config['device'])) {
                $payload['device'] = $config['device'];
            }

            // Optional: Add sound (if configured)
            if (!empty($config['sound'])) {
                $payload['sound'] = $config['sound'];
            } else {
                // Default sounds based on priority
                $payload['sound'] = $this->getSoundByPriority($priority);
            }

            // Optional: Add URL for domain link
            if (isset($data['domain_id'])) {
                $baseUrl = $_ENV['APP_URL'] ?? 'http://localhost';
                $payload['url'] = rtrim($baseUrl, '/') . '/domains/' . $data['domain_id'];
                $payload['url_title'] = 'View Domain Details';
            }

            // For emergency priority (2), add retry and expire parameters
            if ($priority === 2) {
                $payload['retry'] = 300;    // Retry every 5 minutes
                $payload['expire'] = 3600;  // Give up after 1 hour
            }

            // Add timestamp
            $payload['timestamp'] = time();

            // Optional: Add HTML formatting if message contains line breaks
            if (strpos($message, "\n") !== false) {
                $payload['html'] = 1;
                $payload['message'] = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
            }

            $response = $this->client->post(self::API_URL, [
                'form_params' => $payload
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            if ($statusCode === 200 && isset($body['status']) && $body['status'] === 1) {
                $this->logger->info('Pushover message sent successfully', [
                    'status' => $statusCode,
                    'request_id' => $body['request'] ?? null,
                    'priority' => $priority
                ]);
                return true;
            } else {
                $this->logger->error('Pushover API returned non-success status', [
                    'status' => $statusCode,
                    'response' => $body
                ]);
                return false;
            }

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // Handle 4xx errors (authentication, invalid parameters, etc.)
            $response = $e->getResponse();
            $body = json_decode($response->getBody()->getContents(), true);
            
            $this->logger->error('Pushover client error', [
                'status' => $response->getStatusCode(),
                'errors' => $body['errors'] ?? [],
                'message' => $e->getMessage()
            ]);
            return false;

        } catch (\Exception $e) {
            $this->logger->error('Pushover send failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Get priority based on days left until expiration
     * 
     * Pushover priorities:
     * -2 = Lowest (no notification/alert)
     * -1 = Low (no sound or vibration)
     *  0 = Normal (default)
     *  1 = High (bypasses quiet hours)
     *  2 = Emergency (requires acknowledgement)
     */
    private function getPriorityByDaysLeft(?int $daysLeft): int
    {
        if ($daysLeft === null) {
            return 0; // Normal priority for unknown
        }

        if ($daysLeft <= 0) {
            return 2; // Emergency - Domain expired!
        }

        if ($daysLeft <= 1) {
            return 2; // Emergency - Expires tomorrow or today
        }

        if ($daysLeft <= 3) {
            return 1; // High - Expires very soon
        }

        if ($daysLeft <= 7) {
            return 1; // High - Expires this week
        }

        if ($daysLeft <= 14) {
            return 0; // Normal - Expires within 2 weeks
        }

        return -1; // Low priority for longer timeframes
    }

    /**
     * Get appropriate sound based on priority
     * 
     * Available sounds: pushover, bike, bugle, cashregister, classical, cosmic,
     * falling, gamelan, incoming, intermission, magic, mechanical, pianobar,
     * siren, spacealarm, tugboat, alien, climb, persistent, echo, updown, vibrate, none
     */
    private function getSoundByPriority(int $priority): string
    {
        return match($priority) {
            2 => 'siren',           // Emergency
            1 => 'persistent',      // High
            0 => 'pushover',        // Normal (default)
            -1 => 'gamelan',        // Low
            -2 => 'none',           // Lowest
            default => 'pushover'   // Fallback
        };
    }
}

