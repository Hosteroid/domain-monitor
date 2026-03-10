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
        $color = $this->getColorByDaysLeft($data['days_left'] ?? null);

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

            // Only add expiration fields for domain expiration alerts
            if (array_key_exists('days_left', $data) || array_key_exists('expiration_date', $data)) {
                $fields[] = ['name' => 'Days Left', 'value' => (string) ($data['days_left'] ?? 'N/A'), 'inline' => true];
                $fields[] = ['name' => 'Expiration Date', 'value' => $data['expiration_date'] ?? 'N/A', 'inline' => true];
            } elseif (isset($data['hostname']) && $data['hostname'] !== $data['domain']) {
                $fields[] = ['name' => 'Hostname', 'value' => $data['hostname'], 'inline' => true];
            }
            if (isset($data['new_status'])) {
                $fields[] = ['name' => 'Status', 'value' => $data['new_status'], 'inline' => true];
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
}

