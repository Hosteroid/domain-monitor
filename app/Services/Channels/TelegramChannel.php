<?php

namespace App\Services\Channels;

use GuzzleHttp\Client;
use App\Services\Logger;

class TelegramChannel implements NotificationChannelInterface
{
    private Client $client;
    private Logger $logger;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://api.telegram.org',
            'timeout' => 10,
        ]);
        $this->logger = new Logger('telegram_channel');
    }

    public function send(array $config, string $message, array $data = []): bool
    {
        if (!isset($config['bot_token']) || !isset($config['chat_id'])) {
            return false;
        }

        try {
            $response = $this->client->post("/bot{$config['bot_token']}/sendMessage", [
                'json' => [
                    'chat_id' => $config['chat_id'],
                    'text' => $message,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]
            ]);

            $ok = $response->getStatusCode() === 200;
            if ($ok) {
                $this->logger->info('Telegram message sent', [
                    'chat_id' => $config['chat_id'],
                    'status' => $response->getStatusCode()
                ]);
            } else {
                $this->logger->error('Telegram non-200 status', [
                    'chat_id' => $config['chat_id'],
                    'status' => $response->getStatusCode()
                ]);
            }
            return $ok;
        } catch (\Exception $e) {
            // The message names the address that was asked, and the bot token is part of it
            $this->logger->error('Telegram send failed', [
                'chat_id' => $config['chat_id'] ?? null
            ] + ChannelLog::error($e, [$config['bot_token']]));
            return false;
        }
    }
}

