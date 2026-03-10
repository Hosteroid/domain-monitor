<?php

namespace App\Services\Channels;

use GuzzleHttp\Client;
use App\Services\Logger;

class SlackChannel implements NotificationChannelInterface
{
    private Client $client;
    private Logger $logger;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 10]);
        $this->logger = new Logger('slack_channel');
    }

    public function send(array $config, string $message, array $data = []): bool
    {
        if (!isset($config['webhook_url'])) {
            return false;
        }

        try {
            $payload = [
                'text' => $message,
                'blocks' => $this->createBlocks($message, $data)
            ];

            $response = $this->client->post($config['webhook_url'], [
                'json' => $payload
            ]);

            $ok = $response->getStatusCode() === 200;
            if ($ok) {
                $this->logger->info('Slack message sent', [
                    'status' => $response->getStatusCode()
                ]);
            } else {
                $this->logger->error('Slack non-200 status', [
                    'status' => $response->getStatusCode()
                ]);
            }
            return $ok;
        } catch (\Exception $e) {
            $this->logger->error('Slack send failed', [
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function createBlocks(string $message, array $data): array
    {
        $headerText = $data['subject'] ?? '🔔 Domain Monitor Alert';

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => $headerText
                ]
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $message
                ]
            ]
        ];

        if (isset($data['domain'])) {
            $fields = [
                ['type' => 'mrkdwn', 'text' => "*Domain:*\n{$data['domain']}"]
            ];

            // Only add expiration fields for domain expiration alerts
            if (array_key_exists('days_left', $data) || array_key_exists('expiration_date', $data)) {
                $fields[] = ['type' => 'mrkdwn', 'text' => "*Days Left:*\n" . ($data['days_left'] ?? 'N/A')];
                $fields[] = ['type' => 'mrkdwn', 'text' => "*Expiration:*\n" . ($data['expiration_date'] ?? 'N/A')];
                $fields[] = ['type' => 'mrkdwn', 'text' => "*Registrar:*\n" . ($data['registrar'] ?? 'N/A')];
            } elseif (isset($data['hostname']) && $data['hostname'] !== $data['domain']) {
                $fields[] = ['type' => 'mrkdwn', 'text' => "*Hostname:*\n{$data['hostname']}"];
            }
            if (isset($data['new_status'])) {
                $fields[] = ['type' => 'mrkdwn', 'text' => "*Status:*\n{$data['new_status']}"];
            }

            $blocks[] = [
                'type' => 'section',
                'fields' => $fields
            ];
        }

        return $blocks;
    }
}

