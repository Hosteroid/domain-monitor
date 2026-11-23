<?php

namespace App\Services\Channels;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * What a channel may write to its log about a request.
 *
 * The address of a webhook is its secret, and Telegram's address holds the bot token. The
 * message of a failed HTTP request names the full address, and a server may repeat the path it
 * was asked for in its answer. So nothing about a request is logged before it has been through
 * here.
 */
class ChannelLog
{
    /** Parts of an address shorter than this are not looked for: "v1" would only tear a text apart */
    private const SHORTEST_PART = 6;

    /** Bytes of a server's answer that are read, and that are kept for the log */
    private const ANSWER_READ = 8192;
    private const ANSWER_KEPT = 1000;

    /**
     * Log fields for a request that failed: what went wrong, and the status and the start of
     * the answer when the server gave one
     *
     * @param array $secrets Values that must not be logged: a token, or a webhook address
     */
    public static function error(\Throwable $e, array $secrets = []): array
    {
        // The pointer to the cURL manual says nothing about this request
        $message = (string)preg_replace('~ \(see https?://curl\.[^)]*\)~', '', $e->getMessage());
        $fields = [];

        if ($e instanceof RequestException && $e->hasResponse()) {
            $response = $e->getResponse();
            // The library's message goes on with the first characters of the answer, cut off
            // wherever its limit falls, which can leave half a secret. Only its first line is
            // used, and the answer is read here.
            $message = rtrim(explode("\n", $message, 2)[0], ':');
            $fields['status'] = $response->getStatusCode();
            $fields['response_body'] = self::answer($response, $secrets);
        }

        return ['exception' => mb_scrub(self::text($message, $secrets), 'UTF-8')] + $fields;
    }

    /**
     * A text without what must not be logged. Every address in it is cut down to scheme and
     * host. The given values are taken out, and of an address also each part of its path and
     * query string, however the text spells what is between them (a JSON answer writes "\/").
     *
     * @param array $secrets Values that must not be logged: a token, or a webhook address
     */
    public static function text(string $text, array $secrets = []): string
    {
        $text = (string)preg_replace(
            '~\b([a-z][a-z0-9+.-]*://)(?:[^/\s@]*@)?([^/\s?#`\'"<>]*)[^\s`\'"<>]*~i',
            '$1$2/...',
            $text
        );

        $hide = [];
        foreach ($secrets as $secret) {
            if (!is_string($secret) || $secret === '') {
                continue;
            }
            $hide[] = $secret;
            if (preg_match('~^[a-z][a-z0-9+.-]*://[^/?#]*(.*)$~is', $secret, $rest)) {
                foreach (preg_split('~[/?&=#]+~', $rest[1], -1, PREG_SPLIT_NO_EMPTY) as $part) {
                    if (strlen($part) >= self::SHORTEST_PART) {
                        $hide[] = $part;
                    }
                }
            }
        }
        // The longest first, so that a part does not break up a longer value before it is found
        usort($hide, fn(string $a, string $b) => strlen($b) <=> strlen($a));

        return str_replace($hide, '***', $text);
    }

    /**
     * The start of a server's answer for the log
     *
     * @param array $secrets Values that must not be logged: a token, or a webhook address
     */
    public static function answer(ResponseInterface $response, array $secrets = []): string
    {
        try {
            $body = $response->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $start = $body->read(self::ANSWER_READ);
        } catch (\Throwable $e) {
            return '';
        }

        // Valid UTF-8 and cut between two characters: a log line that cannot be encoded as JSON
        // loses all of its fields
        $text = mb_scrub(self::text($start, $secrets), 'UTF-8');
        if (strlen($text) > self::ANSWER_KEPT) {
            $text = mb_strcut($text, 0, self::ANSWER_KEPT, 'UTF-8') . '... [truncated]';
        }

        return $text;
    }
}
