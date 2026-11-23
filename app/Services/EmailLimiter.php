<?php

namespace App\Services;

use App\Models\EmailCount;
use App\Models\Setting;

/**
 * Limit on password reset and verification emails.
 *
 * Emails are counted for the address they go to, whoever asks for them and from wherever. There
 * is no count per visitor address: behind a reverse proxy every visitor has the proxy's address,
 * so it would be one count for the whole install that anyone could use up for everybody.
 *
 * Each kind of email has its own count. Forgot password also mails accounts that are not
 * verified yet, so with one count a stranger's requests could use up the verification emails of
 * an account.
 *
 * Only an email that goes out is counted, and a refused request changes nothing: the links
 * already mailed keep working.
 */
class EmailLimiter
{
    /** Kinds of email, each with its own count per address */
    public const RESET = 'reset';
    public const VERIFY = 'verify';

    private ?EmailCount $counts = null;

    private string $address = '';
    private string $kind = '';

    /** Start of the window the email in progress was counted in, null when nothing was counted */
    private ?int $windowStart = null;

    /**
     * What a refused request is told. Not on the forgot password page: it says the same for
     * every address.
     */
    public static function message(int $seconds): string
    {
        $minutes = max(1, (int)ceil($seconds / 60));

        return 'Too many emails were sent to this address. Please try again in '
            . ($minutes === 1 ? '1 minute' : "$minutes minutes") . '.';
    }

    /**
     * Whether an address has used up its emails of a kind. Read only: nothing is counted.
     *
     * Forgot password asks this for every address, with or without an account. Both then take
     * the same steps up to here, so a full count answers like "no account".
     */
    public function isFull(string $address, string $kind): bool
    {
        try {
            $counts = new EmailCount();
            if (!$counts->isInstalled()) {
                return false;
            }

            $limits = (new Setting())->getEmailLimitSettings();
            $state = $counts->state($address, $kind);

            return $limits['per_recipient'] > 0
                && $state !== null
                && $state['window_start'] > time() - $limits['minutes'] * 60
                && $state['sent'] >= $limits['per_recipient'];
        } catch (\Throwable $e) {
            // take() decides, and reports what is wrong
            return false;
        }
    }

    /**
     * Count an email before it is sent
     *
     * @param string $address The address it goes to, as the users table has it
     * @param string $kind self::RESET or self::VERIFY
     * @return int Seconds until the address can get such an email again, 0 when this one may go out
     */
    public function take(string $address, string $kind): int
    {
        $this->windowStart = null;
        if ($address === '') {
            return 0;
        }

        try {
            $counts = new EmailCount();
            if (!$counts->isInstalled()) {
                // The update that adds the table has not run yet
                return 0;
            }

            $limits = (new Setting())->getEmailLimitSettings();
            $now = time();
            $cutoff = $now - $limits['minutes'] * 60;

            $wait = $limits['per_recipient'] > 0
                ? $this->count($counts, $address, $kind, $limits, $now, $cutoff)
                : 0;

            try {
                $counts->purge($cutoff);
            } catch (\Throwable $e) {
                // Another request is cleaning up at the same moment: the next one tries again
            }

            return $wait;
        } catch (\Throwable $e) {
            // A broken table must not stop password resets: the email goes out without the limit
            $this->windowStart = null;
            $this->log('ERROR', 'Email limit skipped after an error', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * The email was not sent after all: it does not count
     */
    public function giveBack(): void
    {
        if ($this->windowStart === null) {
            return;
        }

        try {
            $this->counts->giveBack($this->address, $this->kind, $this->windowStart);
        } catch (\Throwable $e) {
            $this->log('ERROR', 'Email count not taken back', ['error' => $e->getMessage()]);
        }
        $this->windowStart = null;
    }

    /**
     * @param array{per_recipient: int, minutes: int} $limits
     * @return int Seconds to wait, 0 when the email is counted and may go out
     */
    private function count(EmailCount $counts, string $address, string $kind, array $limits, int $now, int $cutoff): int
    {
        // Twice at most: the clean-up of another request can delete an ended count between the
        // two statements of take(), which then finds no row
        for ($try = 0; $try < 2; $try++) {
            $taken = $counts->take($address, $kind, $limits['per_recipient'], $now, $cutoff);
            $state = $counts->state($address, $kind);

            if ($taken) {
                $this->counts = $counts;
                $this->address = $address;
                $this->kind = $kind;
                $this->windowStart = $state['window_start'] ?? $now;

                // One line when an email fills the count. Requests refused from then on leave
                // none: anyone can send those, and each line would be theirs to add.
                if (($state['sent'] ?? 0) >= $limits['per_recipient']) {
                    $this->log('WARNING', 'Email limit reached', [
                        'email' => $address,
                        'kind' => $kind,
                        'limit' => $limits['per_recipient'],
                        'minutes' => $limits['minutes'],
                    ]);
                }

                return 0;
            }

            if ($state !== null) {
                return max(1, $state['window_start'] + $limits['minutes'] * 60 - $now);
            }
        }

        return 0;
    }

    /**
     * A line in the auth log. A log folder that cannot be written must not turn a request into
     * an error page, so warnings are muted here.
     */
    private function log(string $level, string $message, array $context): void
    {
        try {
            @(new Logger('auth'))->log($level, $message, $context);
        } catch (\Throwable $e) {
            // The log is not worth failing a request for
        }
    }
}
