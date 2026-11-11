<?php

namespace App\Services\Channels;

use GuzzleHttp\Client;
use App\Services\Logger;

class DiscordChannel implements NotificationChannelInterface
{
    private Client $client;
    private Logger $logger;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 10]);
        $this->logger = new Logger('discord_channel');
    }

    public function send(array $config, string $message, array $data = []): bool
    {
        if (!isset($config['webhook_url'])) {
            return false;
        }

        try {
            $embed = $this->createEmbed($message, $data);

            $response = $this->client->post($config['webhook_url'], [
                'json' => [
                    'embeds' => [$embed]
                ]
            ]);

            $ok = $response->getStatusCode() === 204;
            if ($ok) {
                $this->logger->info('Discord message sent', [
                    'status' => $response->getStatusCode()
                ]);
            } else {
                $this->logger->error('Discord non-204 status', [
                    'status' => $response->getStatusCode()
                ]);
            }
            return $ok;
        } catch (\Exception $e) {
            $this->logger->error('Discord send failed', [
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function createEmbed(string $message, array $data): array
    {
        $title = $data['subject'] ?? '🔔 Domain Monitor Alert';
        $color = match (true) {
            isset($data['hostname'], $data['new_status']) => $this->getColorBySslStatus($data['new_status'], $data['days_left'] ?? null),
            isset($data['new_status']) => $this->getColorByDomainStatus($data['new_status']),
            default => $this->getColorByDaysLeft($data['days_left'] ?? null),
        };

        $embed = [
            'title' => $title,
            'description' => $message,
            'color' => $color,
            'timestamp' => date('c'),
            'footer' => [
                'text' => 'Domain Monitor'
            ]
        ];

        if (isset($data['domain'])) {
            $fields = [
                ['name' => 'Domain', 'value' => $data['domain'], 'inline' => true]
            ];

            if (isset($data['hostname'])) {
                // SSL certificate alerts
                if ($data['hostname'] !== $data['domain']) {
                    $fields[] = ['name' => 'Hostname', 'value' => $data['hostname'], 'inline' => true];
                }
                if (isset($data['days_left'])) {
                    $fields[] = ['name' => 'Days Left', 'value' => (string) $data['days_left'], 'inline' => true];
                }
                if (!empty($data['valid_to'])) {
                    $fields[] = ['name' => 'Valid Until', 'value' => date('Y-m-d', strtotime($data['valid_to'])), 'inline' => true];
                }
            } elseif (array_key_exists('days_left', $data) || array_key_exists('expiration_date', $data)) {
                // Only add expiration fields for domain expiration alerts
                $fields[] = ['name' => 'Days Left', 'value' => (string) ($data['days_left'] ?? 'N/A'), 'inline' => true];
                $fields[] = ['name' => 'Expiration Date', 'value' => $data['expiration_date'] ?? 'N/A', 'inline' => true];
                if (!empty($data['previous_expiration_date'])) {
                    // Renewal alerts
                    $fields[] = ['name' => 'Previous Expiration', 'value' => $data['previous_expiration_date'], 'inline' => true];
                }
                if (!empty($data['renewal_cost'])) {
                    // Expiry reminders, when billing knows the price
                    $fields[] = ['name' => 'Renewal Cost', 'value' => $data['renewal_cost'], 'inline' => true];
                }
            }
            if (isset($data['new_status'])) {
                $fields[] = ['name' => 'Status', 'value' => $data['status_label'] ?? $data['new_status'], 'inline' => true];
            }

            $embed['fields'] = $fields;
        }

        return $embed;
    }

    private function getColorByDaysLeft(?int $daysLeft): int
    {
        if ($daysLeft === null) {
            return 0x808080; // Gray
        }

        if ($daysLeft <= 0) {
            return 0xFF0000; // Red
        }

        if ($daysLeft <= 3) {
            return 0xFF4500; // Orange Red
        }

        if ($daysLeft <= 7) {
            return 0xFFA500; // Orange
        }

        if ($daysLeft <= 30) {
            return 0xFFFF00; // Yellow
        }

        return 0x00FF00; // Green
    }

    private function getColorByDomainStatus(string $status): int
    {
        return match ($status) {
            'active' => 0x00FF00, // Green - registered
            'available' => 0x3498DB, // Blue
            'redemption_period' => 0xFFA500, // Orange
            'expired', 'pending_delete' => 0xFF0000, // Red
            default => 0x808080, // Gray
        };
    }

    private function getColorBySslStatus(string $status, ?int $daysLeft): int
    {
        return match ($status) {
            'valid' => 0x00FF00, // Green
            'expiring' => ($daysLeft !== null && $daysLeft <= 3) ? 0xFF4500 : 0xFFA500, // Orange Red / Orange
            default => 0xFF0000, // Red - expired or invalid
        };
    }
}

