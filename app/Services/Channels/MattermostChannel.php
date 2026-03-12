<?php

namespace App\Services\Channels;

use GuzzleHttp\Client;
use App\Services\Logger;

class MattermostChannel implements NotificationChannelInterface
{
    private Client $client;
    private Logger $logger;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 10]);
        $this->logger = new Logger('mattermost_channel');
    }

    public function send(array $config, string $message, array $data = []): bool
    {
        if (!isset($config['webhook_url'])) {
            return false;
        }

        try {
            // Mattermost expects a simple text payload or attachments
            $payload = [
                'text' => $message
            ];

            // Add green-bar attachment only for expiration/SSL/status alerts, not for DNS change (plain text only)
            $isRichAlert = isset($data['domain'])
                && (array_key_exists('days_left', $data)
                    || array_key_exists('expiration_date', $data)
                    || isset($data['new_status'])
                    || (isset($data['hostname']) && $data['hostname'] !== $data['domain']));
            if ($isRichAlert) {
                $color = $this->getColorByDaysLeft($data['days_left'] ?? null);
                $title = $data['subject'] ?? '🔔 Domain Monitor Alert';

                $fields = [
                    ['short' => true, 'title' => 'Domain', 'value' => $data['domain']]
                ];

                if (array_key_exists('days_left', $data) || array_key_exists('expiration_date', $data)) {
                    $fields[] = ['short' => true, 'title' => 'Days Left', 'value' => (string) ($data['days_left'] ?? 'N/A')];
                    $fields[] = ['short' => true, 'title' => 'Expiration Date', 'value' => $data['expiration_date'] ?? 'N/A'];
                    $fields[] = ['short' => true, 'title' => 'Registrar', 'value' => $data['registrar'] ?? 'N/A'];
                } elseif (isset($data['hostname']) && $data['hostname'] !== $data['domain']) {
                    $fields[] = ['short' => true, 'title' => 'Hostname', 'value' => $data['hostname']];
                }
                if (isset($data['new_status'])) {
                    $fields[] = ['short' => true, 'title' => 'Status', 'value' => $data['new_status']];
                }

                $payload['attachments'] = [
                    [
                        'color' => $color,
                        'title' => $title,
                        'fields' => $fields,
                        'footer' => 'Domain Monitor',
                        'ts' => time()
                    ]
                ];
            }

            $response = $this->client->post($config['webhook_url'], [
                'json' => $payload
            ]);

            $ok = $response->getStatusCode() === 200;
            if ($ok) {
                $this->logger->info('Mattermost message sent', [
                    'status' => $response->getStatusCode()
                ]);
            } else {
                $this->logger->error('Mattermost non-200 status', [
                    'status' => $response->getStatusCode()
                ]);
            }
            return $ok;
        } catch (\Exception $e) {
            $this->logger->error('Mattermost send failed', [
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function getColorByDaysLeft(?int $daysLeft): string
    {
        if ($daysLeft === null) {
            return '#36a64f'; // Green
        }

        if ($daysLeft <= 0) {
            return '#ff0000'; // Red - expired
        } elseif ($daysLeft <= 1) {
            return '#ff6600'; // Orange - critical
        } elseif ($daysLeft <= 7) {
            return '#ffaa00'; // Yellow - warning
        } else {
            return '#36a64f'; // Green - ok
        }
    }
}
