<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\TwoFactorAttempt;

/**
 * Limit on wrong 2FA codes.
 *
 * Wrong codes are counted per account, from all addresses together. A count per address would
 * give anyone with many addresses guesses without end, and the usual price of a count per
 * account, that any stranger can lock it, is not paid here: a code can only be entered by
 * someone who has just logged in with the account's current password (see
 * TwoFactorService::passwordMark()) or who is signed in to the account.
 *
 * Only failures are kept. They are not wiped by a later success, and a full count refuses a
 * correct code too: otherwise it would not limit guessing.
 *
 * Unlike LoginLimiter, start() does not go on without the limit after a database error. The
 * table is as old as 2FA itself, so there is no update to wait for, and a limit that an error can
 * switch off is none.
 */
class TwoFactorLimiter
{
    private ?TwoFactorAttempt $attempts = null;

    /** @var array{attempts: int, minutes: int}|null */
    private ?array $limits = null;

    /** Row of the code being checked, null when nothing is being counted */
    private ?int $rowId = null;

    private int $userId = 0;
    private string $address = '';

    /** Wrong codes in the window, the one being checked included */
    private int $count = 0;

    /**
     * What a refused code is told
     */
    public static function message(int $seconds): string
    {
        $minutes = max(1, (int)ceil($seconds / 60));

        return 'Too many failed attempts. Please try again in ' . ($minutes === 1 ? '1 minute' : "$minutes minutes") . '.';
    }

    /**
     * Count a code before it is checked
     *
     * @return int Seconds until the account can try again, 0 when the check may go on
     */
    public function start(int $userId, string $ip): int
    {
        $attempts = $this->attempts();
        $limit = $this->limits()['attempts'];
        $window = $this->limits()['minutes'] * 60;

        try {
            $attempts->purge($window);
        } catch (\Throwable $e) {
            // Another request is cleaning up at the same moment: the next one tries again
        }

        // Read only: an attempt refused here leaves nothing behind, so trying again does not
        // make the wait longer
        $wait = $attempts->secondsUntilBelow($userId, $window, $limit);
        if ($wait > 0) {
            return $wait;
        }

        // Noted before the code is checked and counted again: requests sent at the same moment
        // each see the rows of the others, so no more than the limit get through
        $rowId = $attempts->add($userId, $ip);
        $count = $attempts->failures($userId, $window);
        if ($count > $limit) {
            $attempts->remove($rowId);

            return max(1, $attempts->secondsUntilBelow($userId, $window, $limit));
        }

        $this->rowId = $rowId;
        $this->userId = $userId;
        $this->address = $ip;
        $this->count = $count;

        return 0;
    }

    /**
     * The code was wrong: the attempt stays counted
     */
    public function failed(): void
    {
        if ($this->rowId === null) {
            return;
        }
        $this->rowId = null;

        // One line when this failure fills the count. Attempts refused from then on leave none:
        // each line would be theirs to add.
        if ($this->count === $this->limits()['attempts']) {
            $this->log('WARNING', '2FA code limit reached', [
                'user_id' => $this->userId,
                'ip' => $this->address,
                'attempts' => $this->limits()['attempts'],
                'minutes' => $this->limits()['minutes'],
            ]);
        }
    }

    /**
     * The code was right: the attempt is not a failure. An error here must not undo the login:
     * an email or backup code is used up by now.
     */
    public function passed(): void
    {
        if ($this->rowId === null) {
            return;
        }

        try {
            $this->attempts()->remove($this->rowId);
        } catch (\Throwable $e) {
            $this->log('ERROR', '2FA attempt not taken back', ['error' => $e->getMessage()]);
        }
        $this->rowId = null;
    }

    /**
     * Seconds an account's codes are still refused, 0 when they are not. Counts nothing.
     */
    public function wait(int $userId): int
    {
        return $this->attempts()->secondsUntilBelow($userId, $this->limits()['minutes'] * 60, $this->limits()['attempts']);
    }

    /**
     * Forget the wrong codes of an account: its password was just reset by email, which only
     * the owner of the mailbox can do
     */
    public function forget(int $userId): void
    {
        try {
            try {
                $this->attempts()->forget($userId);
            } catch (\PDOException $e) {
                // A clean-up at the same moment can make this the loser of a deadlock: once more
                $this->attempts()->forget($userId);
            }
        } catch (\Throwable $e) {
            $this->log('ERROR', 'Wrong 2FA codes not cleared', ['error' => $e->getMessage()]);
        }
    }

    private function attempts(): TwoFactorAttempt
    {
        return $this->attempts ??= new TwoFactorAttempt();
    }

    /**
     * @return array{attempts: int, minutes: int}
     */
    private function limits(): array
    {
        return $this->limits ??= (new Setting())->getTwoFactorLimitSettings();
    }

    /**
     * A line in the 2fa log. A log folder that cannot be written must not turn a login into an
     * error page, so warnings are muted here.
     */
    private function log(string $level, string $message, array $context): void
    {
        try {
            @(new Logger('2fa'))->log($level, $message, $context);
        } catch (\Throwable $e) {
            // The log is not worth failing a login for
        }
    }
}
