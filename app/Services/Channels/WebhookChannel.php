<?php

namespace App\Services\Channels;

use GuzzleHttp\Client;
use App\Services\Logger;

class WebhookChannel implements NotificationChannelInterface
{
    private Client $httpClient;
    private Logger $logger;

    // Supported webhook formats
    public const FORMAT_GENERIC = 'generic';
    public const FORMAT_GOOGLE_CHAT = 'google_chat';
    public const FORMAT_SIMPLE_TEXT = 'simple_text';

    public function __construct()
    {
        $this->httpClient = new Client(['timeout' => 10]);
        $this->logger = new Logger('webhook_channel');
    }

    public function send(array $config, string $message, array $data = []): bool
    {
        $url = trim($config['webhook_url'] ?? '');
        if (empty($url)) {
            return false;
        }

        $format = $config['format'] ?? self::FORMAT_GENERIC;
        $payload = $this->buildPayload($format, $message, $data);

        try {
            $response = $this->httpClient->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF-8'
                ],
                'json' => $payload
            ]);

            $status = $response->getStatusCode();
            $ok = $status >= 200 && $status < 300;
            if ($ok) {
                $this->logger->info('Webhook sent successfully', [
                    'url' => $this->maskUrl($url),
                    'format' => $format,
                    'status' => $status
                ]);
            } else {
                $responseBody = (string) $response->getBody();
                $this->logger->error('Webhook responded with non-2xx', [
                    'url' => $this->maskUrl($url),
                    'format' => $format,
                    'status' => $status,
                    'response_body' => $this->truncate($responseBody, 1000),
                    'payload_preview' => $this->getPayloadPreview($payload)
                ]);
            }
            return $ok;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // Guzzle request exception - may have response details
            $errorDetails = [
                'url' => $this->maskUrl($url),
                'format' => $format,
                'exception' => $e->getMessage(),
                'payload_preview' => $this->getPayloadPreview($payload)
            ];
            
            // Include response body if available (e.g., 4xx/5xx errors)
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $errorDetails['status'] = $response->getStatusCode();
                $errorDetails['response_body'] = $this->truncate((string) $response->getBody(), 1000);
            }
            
            $this->logger->error('Webhook request failed', $errorDetails);
            return false;
        } catch (\Exception $e) {
            $this->logger->error('Webhook send failed', [
                'url' => $this->maskUrl($url),
                'format' => $format,
                'exception' => $e->getMessage(),
                'payload_preview' => $this->getPayloadPreview($payload)
            ]);
            return false;
        }
    }

    /**
     * Build payload based on format type
     */
    private function buildPayload(string $format, string $message, array $data): array
    {
        return match ($format) {
            self::FORMAT_GOOGLE_CHAT => $this->buildGoogleChatPayload($message, $data),
            self::FORMAT_SIMPLE_TEXT => ['text' => $message],
            default => $this->buildGenericPayload($message, $data),
        };
    }

    /**
     * Build generic payload for automation tools (n8n, Zapier, Make, etc.)
     */
    private function buildGenericPayload(string $message, array $data): array
    {
        return [
            'event' => 'domain_expiration_alert',
            'message' => $message,
            'data' => $data,
            'sent_at' => date('c')
        ];
    }

    /**
     * Build Google Chat payload with rich card formatting
     * Uses the official Google Chat webhook format
     */
    private function buildGoogleChatPayload(string $message, array $data): array
    {
        // If we have domain data, create a rich card message
        if (isset($data['domain']) && isset($data['expiration_date'])) {
            return $this->buildGoogleChatCard($message, $data);
        }

        // Simple text message for test messages and other notifications
        return ['text' => $message];
    }

    /**
     * Build a rich card payload for Google Chat domain expiration alerts
     */
    private function buildGoogleChatCard(string $message, array $data): array
    {
        $domain = $data['domain'] ?? 'Unknown';
        $daysLeft = $data['days_left'] ?? 'N/A';
        $expirationDate = $data['expiration_date'] ?? 'Unknown';
        $registrar = $data['registrar'] ?? 'Unknown';

        return [
            'cardsV2' => [
                [
                    'cardId' => 'domain-alert-' . time(),
                    'card' => [
                        'header' => [
                            'title' => 'Domain Expiration Alert',
                            'subtitle' => $domain,
                            'imageUrl' => 'https://www.gstatic.com/images/branding/product/2x/hats_notification_96dp.png',
                            'imageType' => 'CIRCLE'
                        ],
                        'sections' => [
                            [
                                'header' => 'Alert Details',
                                'collapsible' => false,
                                'widgets' => [
                                    [
                                        'decoratedText' => [
                                            'topLabel' => 'Domain',
                                            'text' => $domain,
                                            'startIcon' => [
                                                'knownIcon' => 'BOOKMARK'
                                            ]
                                        ]
                                    ],
                                    [
                                        'decoratedText' => [
                                            'topLabel' => 'Days Remaining',
                                            'text' => (string) $daysLeft,
                                            'startIcon' => [
                                                'knownIcon' => 'CLOCK'
                                            ]
                                        ]
                                    ],
                                    [
                                        'decoratedText' => [
                                            'topLabel' => 'Expiration Date',
                                            'text' => $expirationDate,
                                            'startIcon' => [
                                                'knownIcon' => 'EVENT_SEAT'
                                            ]
                                        ]
                                    ],
                                    [
                                        'decoratedText' => [
                                            'topLabel' => 'Registrar',
                                            'text' => $registrar,
                                            'startIcon' => [
                                                'knownIcon' => 'STORE'
                                            ]
                                        ]
                                    ]
                                ]
                            ],
                            [
                                'widgets' => [
                                    [
                                        'textParagraph' => [
                                            'text' => $message
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Mask sensitive parts of URL for logging (hide keys/tokens)
     */
    private function maskUrl(string $url): string
    {
        // Mask query parameters that might contain keys/tokens
        return preg_replace('/([?&](key|token|secret|password|auth)=)[^&]+/i', '$1***MASKED***', $url);
    }

    /**
     * Truncate string to max length for logging
     */
    private function truncate(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        return substr($text, 0, $maxLength) . '... [truncated]';
    }

    /**
     * Get a preview of the payload for debugging (without sensitive data)
     */
    private function getPayloadPreview(array $payload): array
    {
        // For simple text payloads, show the text (truncated)
        if (isset($payload['text'])) {
            return [
                'type' => 'text',
                'text_preview' => $this->truncate($payload['text'], 200)
            ];
        }

        // For card payloads (Google Chat), show structure info
        if (isset($payload['cardsV2'])) {
            return [
                'type' => 'google_chat_card',
                'card_count' => count($payload['cardsV2'])
            ];
        }

        // For generic payloads, show event and truncated message
        if (isset($payload['event'])) {
            return [
                'type' => 'generic',
                'event' => $payload['event'],
                'message_preview' => $this->truncate($payload['message'] ?? '', 200),
                'data_keys' => isset($payload['data']) ? array_keys($payload['data']) : []
            ];
        }

        return ['type' => 'unknown', 'keys' => array_keys($payload)];
    }
}


