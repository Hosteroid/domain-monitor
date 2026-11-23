<?php

namespace App\Helpers;

/**
 * HTTP requests made through PHP's own stream wrapper
 */
class HttpHelper
{
    /**
     * Fetch a URL with a stream context, and return its body together with the response headers.
     *
     * The headers are read from the stream. PHP 8.5 deprecates the $http_response_header variable
     * that file_get_contents() fills, and http_get_last_response_headers() only exists from PHP 8.4,
     * so this works the same on every PHP version the app runs on. After a redirect the headers of
     * every response are listed in order, as they were in that variable.
     *
     * @param resource $context From stream_context_create(). Pass 'ignore_errors' => true to read
     *                          the body and headers of an error status too.
     * @return array{body: string|false, headers: string[]} The body is false when nothing could be
     *         read, and the headers are empty when no answer came, such as after a timeout
     */
    public static function fetch(string $url, $context): array
    {
        $stream = @fopen($url, 'r', false, $context);
        if ($stream === false) {
            return ['body' => false, 'headers' => []];
        }

        $body = @stream_get_contents($stream);
        $wrapperData = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        fclose($stream);

        return [
            'body' => $body,
            'headers' => is_array($wrapperData) ? array_values(array_filter($wrapperData, 'is_string')) : [],
        ];
    }
}
