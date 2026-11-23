<?php

namespace App\Services;

use App\Models\DomainSuffix;
use App\Models\Setting;

/**
 * The registry part of the Public Suffix List: the domain endings that take registrations, such
 * as .co.uk, .com.br or .my.id. TLD Registry imports it, and the subdomain check of a new domain
 * reads it to tell example.my.id, a domain, from blog.example.com, a subdomain (#112).
 */
class DomainSuffixService
{
    public const LIST_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';

    /** Fewer rules than this means a broken download, which must not replace a good list */
    private const MIN_RULES = 1000;

    /** Whether rules are kept, worked out once per request */
    private static ?bool $available = null;

    private DomainSuffix $model;

    public function __construct()
    {
        $this->model = new DomainSuffix();
    }

    /**
     * Whether there are rules to check against: the update has added the table and an import has
     * filled it. Until then the subdomain check gives way under two-letter country TLDs.
     */
    public function isAvailable(): bool
    {
        if (self::$available === null) {
            try {
                self::$available = $this->model->isInstalled() && $this->model->countAll() > 0;
            } catch (\Throwable $e) {
                self::$available = false;
            }
        }

        return self::$available;
    }

    /**
     * The ending of a domain name that takes registrations, by the rules of the Public Suffix List:
     * "example.co.uk" gives "co.uk", "example.com" gives "com". Null when no list is kept.
     *
     * @param string $domain Lower case ASCII name whose format has been checked
     */
    public function publicSuffix(string $domain): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $labels = explode('.', $domain);
        $count = count($labels);
        $endings = [];
        for ($i = 0; $i < $count; $i++) {
            $endings[] = implode('.', array_slice($labels, $i));
        }

        try {
            $rules = $this->model->findRules($endings);
        } catch (\Throwable $e) {
            return null;
        }

        // No rule at all: the last label is the ending
        $longest = 1;
        $exception = 0;
        foreach ($rules as $rule) {
            $size = substr_count($rule['suffix'], '.') + 1;
            if ($rule['rule_type'] === DomainSuffix::EXCEPTION) {
                $exception = max($exception, $size);
            } elseif ($rule['rule_type'] === DomainSuffix::WILDCARD) {
                // *.ck covers a label under ck, so it needs a name with more labels than ck has
                if ($count > $size) {
                    $longest = max($longest, $size + 1);
                }
            } else {
                $longest = max($longest, $size);
            }
        }

        // An exception (!www.ck) wins over every other rule; its ending is the rule without its first label
        $size = $exception > 0 ? max(1, $exception - 1) : $longest;

        return implode('.', array_slice($labels, -$size));
    }

    /**
     * Download the list and replace the kept rules with its registry part. The list stays as it
     * was when the download fails or looks cut off.
     *
     * @return array{rules: int, skipped: int, version: string}
     */
    public function import(): array
    {
        $logger = new Logger('tld_import');

        $client = new \GuzzleHttp\Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'headers' => ['User-Agent' => 'DomainMonitor/1.0 (TLD Registry)'],
        ]);
        $response = $client->get(self::LIST_URL);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('The Public Suffix List answered with HTTP ' . $response->getStatusCode());
        }

        $parsed = self::parse((string)$response->getBody());
        if (count($parsed['rules']) < self::MIN_RULES) {
            throw new \RuntimeException('The downloaded Public Suffix List holds only ' . count($parsed['rules'])
                . ' rules, so the kept list was not replaced');
        }

        $this->model->replaceAll($parsed['rules']);
        self::$available = null;

        (new Setting())->setValue('domain_suffix_list_imported_at', (string)time());

        $logger->info('Domain suffixes imported', [
            'rules' => count($parsed['rules']),
            'skipped' => $parsed['skipped'],
            'version' => $parsed['version'],
        ]);

        return ['rules' => count($parsed['rules']), 'skipped' => $parsed['skipped'], 'version' => $parsed['version']];
    }

    /**
     * The registry part of the list: the rules between its ICANN markers, each as [ending, type].
     * Rules in a non-Latin script are turned into their xn-- form when PHP's intl extension is
     * there, and skipped without it. Without the end marker the download was cut off, and no
     * rules are returned.
     *
     * @return array{rules: array<int, array{0: string, 1: int}>, skipped: int, version: string}
     */
    public static function parse(string $text): array
    {
        $rules = [];
        $skipped = 0;
        $version = '';
        $inside = false;
        $complete = false;

        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if (preg_match('#^//\s*VERSION:\s*(\S+)#', $line, $match)) {
                $version = substr($match[1], 0, 100);
                continue;
            }
            if (str_contains($line, '===BEGIN ICANN DOMAINS===')) {
                $inside = true;
                continue;
            }
            if (str_contains($line, '===END ICANN DOMAINS===')) {
                $complete = $inside;
                break;
            }
            if (!$inside || $line === '' || str_starts_with($line, '//')) {
                continue;
            }

            // A rule ends at the first white space
            $rule = strtolower(preg_split('/\s/', $line)[0]);
            $type = DomainSuffix::RULE;
            if (str_starts_with($rule, '!')) {
                $type = DomainSuffix::EXCEPTION;
                $rule = substr($rule, 1);
            } elseif (str_starts_with($rule, '*.')) {
                $type = DomainSuffix::WILDCARD;
                $rule = substr($rule, 2);
            }

            if (preg_match('/[^\x00-\x7F]/', $rule)) {
                $ascii = function_exists('idn_to_ascii')
                    ? idn_to_ascii($rule, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46)
                    : false;
                if (!is_string($ascii)) {
                    $skipped++;
                    continue;
                }
                $rule = strtolower($ascii);
            }

            if (strlen($rule) > 253 || !preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', $rule)) {
                $skipped++;
                continue;
            }

            $rules[] = [$rule, $type];
        }

        return ['rules' => $complete ? $rules : [], 'skipped' => $skipped, 'version' => $version];
    }
}
