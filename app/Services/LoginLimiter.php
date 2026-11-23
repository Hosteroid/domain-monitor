<?php

namespace App\Services;

use App\Models\LoginAttempt;
use App\Models\Setting;

/**
 * Limit on wrong passwords at login.
 *
 * Failed logins are counted for the name that was typed, twice over: from one address, and from
 * all addresses together. Nothing is refused by address alone: behind a reverse proxy every
 * visitor has the proxy's address, and the wrong passwords of one would lock out all others.
 *
 * A name is refused before it is looked up, so the answer is the same whether it exists or not.
 * Only failures are kept. They are not wiped by a later success: that would hand anyone on the
 * same address fresh guesses each time the real user logs in.
 */
class LoginLimiter
{
    private ?LoginAttempt $attempts = null;

    /** Row of the attempt in progress, null when nothing is being counted */
    private ?int $rowId = null;

    private string $name = '';
    private string $address = '';

    /** @var array{per_address: int, per_account: int, minutes: int}|array{} */
    private array $limits = [];

    /** @var array{total: int, address: int}|array{} Attempts in the window, this one included */
    private array $counts = [];

    /**
     * Whether a name can be counted and looked up at all. One that is not valid UTF-8 or longer
     * than any username or email address is no account, and the table could not store it.
     */
    public static function validName(string $name): bool
    {
        return strlen($name) <= LoginAttempt::NAME_BYTES && mb_check_encoding($name, 'UTF-8');
    }

    /**
     * What a refused login is told
     */
    public static function message(int $seconds): string
    {
        $minutes = max(1, (int)ceil($seconds / 60));
        if ($minutes > 90) {
            $time = (int)ceil($minutes / 60) . ' hours';
        } else {
            $time = $minutes === 1 ? '1 minute' : "$minutes minutes";
        }

        return "Too many failed login attempts. Please try again in $time.";
    }

    /**
     * Count an attempt before its password is checked
     *
     * @return int Seconds until the name can be tried again, 0 when the attempt may go on
     */
    public function start(string $name, string $ip): int
    {
        try {
            $attempts = new LoginAttempt();
            if (!$attempts->isInstalled()) {
                // The update that adds the table has not run yet
                return 0;
            }

            $limits = (new Setting())->getLoginLimitSettings();
            $now = time();
            $since = $now - $limits['minutes'] * 60;

            try {
                $attempts->purge($since);
            } catch (\Throwable $e) {
                // Another request is cleaning up at the same moment: the next one tries again
            }

            if ($limits['per_address'] === 0 && $limits['per_account'] === 0) {
                return 0;
            }

            $this->attempts = $attempts;
            $this->limits = $limits;
            $this->name = $name;
            $this->address = self::addressKey($ip);

            // Read only: an attempt refused here leaves nothing behind, so trying again does not
            // make the wait longer
            if ($this->isFull($attempts->counts($name, $this->address, $since), 0)) {
                return $this->secondsLeft($now, $since);
            }

            // Noted before the password is checked and counted again: requests sent at the same
            // moment each see the rows of the others, so no more than the limit get through
            $this->rowId = $attempts->add($name, $this->address, $now);
            $this->counts = $attempts->counts($name, $this->address, $since);

            if ($this->isFull($this->counts, 1)) {
                $attempts->remove($this->rowId);
                $this->rowId = null;

                return $this->secondsLeft($now, $since);
            }

            return 0;
        } catch (\Throwable $e) {
            // A broken table must not lock every user out: the login goes on without the limit
            $this->log('ERROR', 'Login limit skipped after an error', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * The password was wrong or the name is not known: the attempt stays counted
     */
    public function failed(): void
    {
        if ($this->rowId === null) {
            return;
        }
        $this->rowId = null;

        // One line when this failure fills a count. Attempts refused from then on leave none:
        // anyone can send those, and each line would be theirs to add.
        $full = [];
        if ($this->limits['per_address'] > 0 && ($this->counts['address'] ?? 0) === $this->limits['per_address']) {
            $full[] = 'address';
        }
        if ($this->limits['per_account'] > 0 && ($this->counts['total'] ?? 0) === $this->limits['per_account']) {
            $full[] = 'account';
        }
        if ($full) {
            $this->log('WARNING', 'Login limit reached', [
                'username' => $this->name,
                'ip' => $this->address,
                'limit' => implode('+', $full),
                'minutes' => $this->limits['minutes'],
            ]);
        }
    }

    /**
     * The password was right: the attempt is not a failure
     */
    public function passed(): void
    {
        if ($this->rowId === null) {
            return;
        }

        try {
            $this->attempts->remove($this->rowId);
        } catch (\Throwable $e) {
            $this->log('ERROR', 'Login attempt not taken back', ['error' => $e->getMessage()]);
        }
        $this->rowId = null;
    }

    /**
     * Forget the failed logins of an account, from all addresses: its password was just reset by
     * email, which only the owner of the mailbox can do
     *
     * @param array $user Row of the users table
     */
    public function forget(array $user): void
    {
        try {
            $attempts = new LoginAttempt();
            if (!$attempts->isInstalled()) {
                return;
            }

            $names = [];
            foreach (['username', 'email'] as $field) {
                if (is_string($user[$field] ?? null) && $user[$field] !== '') {
                    $names[] = $user[$field];
                }
            }

            try {
                $attempts->forget($names);
            } catch (\PDOException $e) {
                // A clean-up at the same moment can make this the loser of a deadlock: once more
                $attempts->forget($names);
            }
        } catch (\Throwable $e) {
            $this->log('ERROR', 'Failed logins not cleared', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Whether a count is used up
     *
     * @param array{total: int, address: int} $counts
     * @param int $own 1 when the counts include the attempt in progress
     */
    private function isFull(array $counts, int $own): bool
    {
        return ($this->limits['per_address'] > 0 && $counts['address'] - $own >= $this->limits['per_address'])
            || ($this->limits['per_account'] > 0 && $counts['total'] - $own >= $this->limits['per_account']);
    }

    /**
     * Seconds until a used-up count has room again: until the attempt that fills it is older
     * than the window
     */
    private function secondsLeft(int $now, int $since): int
    {
        $freeAt = 0;
        foreach (['per_address' => $this->address, 'per_account' => null] as $limit => $ip) {
            if ($this->limits[$limit] > 0) {
                $time = $this->attempts->nthNewest($this->name, $ip, $since, $this->limits[$limit]);
                if ($time !== null) {
                    $freeAt = max($freeAt, $time + $this->limits['minutes'] * 60);
                }
            }
        }

        return max(1, $freeAt - $now);
    }

    /**
     * What an address is counted as. IPv6 is counted by its /64 network, because one machine can
     * use any address in it.
     */
    private static function addressKey(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $ip;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return 'unknown';
        }

        $packed = inet_pton($ip);
        // An IPv4 address written as IPv6 (::ffff:192.0.2.1) is that IPv4 address
        if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /**
     * A line in the auth log. A log folder that cannot be written must not turn a login into an
     * error page, so warnings are muted here.
     */
    private function log(string $level, string $message, array $context): void
    {
        try {
            @(new Logger('auth'))->log($level, $message, $context);
        } catch (\Throwable $e) {
            // The log is not worth failing a login for
        }
    }
}
