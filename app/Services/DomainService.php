<?php

namespace App\Services;

use App\Helpers\InputValidator;
use App\Models\Domain;
use App\Models\NotificationGroup;
use App\Models\Setting;
use App\Models\Tag;

/**
 * Adding, changing, deleting and refreshing one domain, for the pages and the API alike.
 *
 * Nothing here reads the request or the session: the user is passed in, and each method returns
 * what happened as an array. The caller words the message for whoever asked.
 */
class DomainService
{
    private Domain $domains;
    private NotificationGroup $groups;
    private WhoisService $whois;
    private ?DnsHistoryService $history = null;
    private ?BillingService $billing = null;
    private ?bool $isolated = null;

    private const TAGS_NOT_TEXT = 'Enter the tags as text, separated by commas';
    private const NOTES_NOT_TEXT = 'Enter the notes as text';

    public function __construct()
    {
        $this->domains = new Domain();
        $this->groups = new NotificationGroup();
        $this->whois = new WhoisService();
    }

    /**
     * The user id that limits what a user sees: their own in isolated mode, null in shared mode
     */
    public function scopeFor(int $userId): ?int
    {
        if ($this->isolated === null) {
            $this->isolated = (new Setting())->getValue('user_isolation_mode', 'shared') === 'isolated';
        }

        return $this->isolated ? $userId : null;
    }

    /**
     * A domain this user may see, by id
     */
    public function findById(int $id, int $userId): ?array
    {
        if ($this->scopeFor($userId) === null) {
            return $this->domains->find($id);
        }

        // The model reads a user id of 0 as "everyone's", so that never reaches it
        return $userId > 0 ? $this->domains->findWithIsolation($id, $userId) : null;
    }

    /**
     * A domain this user may see, by name
     */
    public function findByName(string $name, int $userId): ?array
    {
        if ($this->scopeFor($userId) === null) {
            return $this->domains->findByNameWithIsolation($name);
        }

        return $userId > 0 ? $this->domains->findByNameWithIsolation($name, $userId) : null;
    }

    /**
     * Add a domain: the checks of the Add Domain page, the lookup, the row and its tags.
     *
     * @param array $input domain_name, notification_group_id (int or null), tags (comma-separated
     *                     text) and, optionally, notes
     * @return array ok=true with id, domain_name, status and lookup_note,
     *               or ok=false with code (invalid, group, exists, lookup_failed) and error: the
     *               text for the user, which is null when a failed lookup gave no reason
     */
    public function add(array $input, int $userId): array
    {
        $name = is_string($input['domain_name'] ?? null) ? $input['domain_name'] : '';
        $check = InputValidator::validateRootDomain(InputValidator::sanitizeDomainInput(trim($name)));
        if (!$check['valid']) {
            return $this->fail('invalid', $check['error']);
        }
        $domainName = $check['domain'];

        // Text of the wrong type is refused, never read as "nothing": that would drop what is saved
        if (!is_string($input['tags'] ?? '')) {
            return $this->fail('invalid', self::TAGS_NOT_TEXT);
        }
        $tagCheck = InputValidator::validateTags($input['tags'] ?? '');
        if (!$tagCheck['valid']) {
            return $this->fail('invalid', $tagCheck['error']);
        }
        $tags = $tagCheck['tags'];

        $groupId = !empty($input['notification_group_id']) ? (int)$input['notification_group_id'] : null;
        $groupError = $this->groupError($groupId, $userId);
        if ($groupError !== null) {
            return $this->fail('group', $groupError);
        }

        $notes = $input['notes'] ?? '';
        if (!is_string($notes)) {
            return $this->fail('invalid', self::NOTES_NOT_TEXT);
        }
        $notesError = InputValidator::validateLength($notes, 5000, 'Notes');
        if ($notesError) {
            return $this->fail('invalid', $notesError);
        }

        if ($this->domains->existsByDomain($domainName)) {
            return $this->fail('exists', 'Domain already exists');
        }

        $whoisData = $this->whois->getDomainInfo($domainName);
        if (!$whoisData) {
            return $this->lookupFailure();
        }

        $status = $this->whois->getDomainStatus($whoisData['expiration_date'], $whoisData['status'] ?? [], $whoisData);

        $row = [
            'domain_name' => $domainName,
            'notification_group_id' => $groupId,
            'registrar' => $whoisData['registrar'],
            'registrar_url' => $whoisData['registrar_url'] ?? null,
            'expiration_date' => $whoisData['expiration_date'],
            'updated_date' => $whoisData['updated_date'] ?? null,
            'abuse_email' => $whoisData['abuse_email'] ?? null,
            'last_checked' => date('Y-m-d H:i:s'),
            'status' => $status,
            'whois_data' => json_encode($whoisData, JSON_INVALID_UTF8_SUBSTITUTE),
            'is_active' => 1,
            'ssl_monitoring_enabled' => (new Setting())->isSslMonitoringOnForNewDomains() ? 1 : 0,
            'user_id' => $userId,
        ];
        // Left out when empty, so the column stays NULL as it does for a domain added on the page
        if ($notes !== '') {
            $row['notes'] = $notes;
        }

        try {
            $id = $this->domains->create($row);
        } catch (\PDOException $e) {
            $driverCode = (int)($e->errorInfo[1] ?? 0);
            // 1062: someone added the same name between the check above and this insert
            if ($driverCode === 1062) {
                return $this->fail('exists', 'Domain already exists');
            }
            // 1452: the notification group was deleted in between
            if ($driverCode === 1452) {
                return $this->fail('group', 'Notification group not found');
            }
            throw $e;
        }

        // Payments saved under this name before the domain was added
        $this->billing()->relinkDomain($id, $domainName, $userId);

        if (!empty($tags)) {
            (new Tag())->updateDomainTags($id, $tags, $userId);
        }

        (new Logger())->info('Domain created', [
            'domain_id' => $id,
            'domain_name' => $domainName,
            'user_id' => $userId,
            'status' => $status,
            'expiration_date' => $whoisData['expiration_date'],
            'notification_group_id' => $groupId,
        ]);

        return [
            'ok' => true,
            'id' => $id,
            'domain_name' => $domainName,
            'status' => $status,
            'lookup_note' => $whoisData['lookup_note'] ?? null,
        ];
    }

    /**
     * Change a domain. Only the keys present in $changes are touched, so a caller that leaves a
     * key out leaves that part of the domain as it is:
     *
     *   notification_group_id   int, or null for no group
     *   is_active, dns_monitoring_enabled, ssl_monitoring_enabled, dns_history_enabled   0 or 1
     *   expiration_date         Y-m-d, or null to clear it
     *   tags                    comma-separated text, '' removes all tags
     *   notes                   text
     *   renewal_price, renewal_currency   as typed on the edit form, both together
     *
     * @param array $domain The row as it is now
     * @param bool  $notify Tell the notification group that monitoring or the group changed
     * @return array ok=true, or ok=false with code (invalid, group) and error
     */
    public function update(array $domain, array $changes, int $userId, bool $notify = true): array
    {
        $id = (int)$domain['id'];
        $has = static fn(string $key): bool => array_key_exists($key, $changes);

        // The checks run in the order the edit form has always shown their errors: date, tags, group, price
        $newDate = $changes['expiration_date'] ?? null;
        if ($newDate !== null && (!is_string($newDate) || !InputValidator::validateDate($newDate))) {
            return $this->fail('invalid', 'Enter a valid expiration date');
        }

        $tags = null;
        if ($has('tags')) {
            if (!is_string($changes['tags'])) {
                return $this->fail('invalid', self::TAGS_NOT_TEXT);
            }
            $tagCheck = InputValidator::validateTags($changes['tags']);
            if (!$tagCheck['valid']) {
                return $this->fail('invalid', $tagCheck['error']);
            }
            $tags = $tagCheck['tags'];
        }

        // The group in effect after this change: messages about the change go to it
        $groupId = !empty($domain['notification_group_id']) ? (int)$domain['notification_group_id'] : null;
        if ($has('notification_group_id')) {
            $groupId = !empty($changes['notification_group_id']) ? (int)$changes['notification_group_id'] : null;
            $groupError = $this->groupError($groupId, $userId);
            if ($groupError !== null) {
                return $this->fail('group', $groupError);
            }
        }

        if ($has('notes')) {
            if (!is_string($changes['notes'])) {
                return $this->fail('invalid', self::NOTES_NOT_TEXT);
            }
            $notesError = InputValidator::validateLength($changes['notes'], 5000, 'Notes');
            if ($notesError) {
                return $this->fail('invalid', $notesError);
            }
        }

        $fields = [];
        if ($has('notification_group_id')) {
            $fields['notification_group_id'] = $groupId;
        }

        // The three switches: the new value when given, otherwise what the domain has
        $isActive = $has('is_active') ? ($changes['is_active'] ? 1 : 0) : (int)!empty($domain['is_active']);
        $dnsMonitoring = $has('dns_monitoring_enabled')
            ? ($changes['dns_monitoring_enabled'] ? 1 : 0)
            : (int)!empty($domain['dns_monitoring_enabled'] ?? 1);
        $sslMonitoring = $has('ssl_monitoring_enabled')
            ? ($changes['ssl_monitoring_enabled'] ? 1 : 0)
            : (int)!empty($domain['ssl_monitoring_enabled'] ?? 0);
        if ($has('is_active')) {
            $fields['is_active'] = $isActive;
        }
        if ($has('dns_monitoring_enabled')) {
            $fields['dns_monitoring_enabled'] = $dnsMonitoring;
        }
        if ($has('ssl_monitoring_enabled')) {
            $fields['ssl_monitoring_enabled'] = $sslMonitoring;
        }
        if ($has('expiration_date')) {
            $fields['expiration_date'] = $newDate;
        }
        // Only once the update that adds the column has run
        if ($has('dns_history_enabled') && $this->domains->hasColumn('dns_history_enabled')) {
            $fields['dns_history_enabled'] = $changes['dns_history_enabled'] ? 1 : 0;
        }
        if ($has('notes')) {
            $fields['notes'] = $changes['notes'];
        }
        if ($has('renewal_price') || $has('renewal_currency')) {
            [$ownPrice, $ownCurrency, $priceError] = $this->billing()->readOwnPrice(
                $changes['renewal_price'] ?? '',
                $changes['renewal_currency'] ?? ''
            );
            if ($priceError !== null) {
                return $this->fail('invalid', $priceError);
            }
            $fields['renewal_price'] = $ownPrice;
            $fields['renewal_currency'] = $ownCurrency;
        }

        if ($fields) {
            $this->domains->update($id, $fields);
        }

        // An expiry date moved ahead by hand is a renewal no check can see, for TLDs whose lookup has no date
        if ($has('expiration_date')) {
            $savedLookup = json_decode((string)($domain['whois_data'] ?? ''), true);
            $oldTime = strtotime((string)($domain['expiration_date'] ?? ''));
            $newTime = is_string($newDate) ? strtotime($newDate) : false;
            if (empty($savedLookup['expiration_date']) && $oldTime && $newTime && $newTime > time()
                && $newTime - $oldTime >= WhoisService::RENEWAL_MIN_DAYS * 86400) {
                $this->billing()->logDetectedRenewal($domain, ['registrar' => $domain['registrar'] ?? null], [
                    'old_expiration' => $domain['expiration_date'],
                    'new_expiration' => date('Y-m-d', $newTime),
                    'auto_renew_period' => false,
                ]);
            }
        }

        if ($notify) {
            $this->sendChangeMessages($domain, $groupId, $isActive, $dnsMonitoring, $sslMonitoring);
        }

        if ($tags !== null) {
            $tagModel = new Tag();
            if (!empty($tags)) {
                $tagModel->updateDomainTags($id, $tags, $userId);
            } else {
                $tagModel->removeAllFromDomain($id);
            }
        }

        return ['ok' => true];
    }

    /**
     * Delete a domain. Its DNS records go to DNS History first, and its payments stay in Billing.
     *
     * @param array $domain The row as it is now
     * @return array{deleted: bool, history_kept: bool, payments_kept: bool}
     */
    public function delete(array $domain): array
    {
        // Both are read before the row goes: the delete empties what they look at
        $historyKept = $this->history()->recordDeletion($domain);
        $paymentsKept = $this->billing()->hasPayments($domain);

        return [
            'deleted' => $this->domains->delete((int)$domain['id']),
            'history_kept' => $historyKept,
            'payments_kept' => $paymentsKept,
        ];
    }

    /**
     * Look a domain up again and save what comes back, as the Refresh button does. A renewal found
     * here is logged in Billing, and a status change or renewal is alerted as the cron would.
     *
     * @param array    $domain The row as it is now
     * @param int|null $userId Who asked, for the log
     * @param bool     $log    Write the log lines of a single refresh. A bulk refresh writes its own.
     * @return array ok=true with status and renewal (null when none was found), or ok=false with
     *               error: the reason for the user, null when the lookup gave none, and rate_limited
     */
    public function refresh(array $domain, ?int $userId, bool $log = true): array
    {
        $id = (int)$domain['id'];
        $logger = new Logger();

        $whoisData = $this->whois->getDomainInfo($domain['domain_name']);

        if (!$whoisData) {
            $failure = $this->lookupFailure();
            if ($log) {
                $logger->error('WHOIS refresh failed', [
                    'domain_id' => $id,
                    'domain_name' => $domain['domain_name'],
                    'user_id' => $userId,
                ]);
            }
            return $failure;
        }

        // The order matters: the renewal check and the last known status need the row from before
        // the update, and the privacy value is carried over after the renewal is logged
        $expirationDate = $whoisData['expiration_date'] ?? $domain['expiration_date'];
        $status = $this->whois->getDomainStatus($expirationDate, $whoisData['status'] ?? [], $whoisData);
        $renewal = $this->whois->detectRenewal($domain, $whoisData, $status);
        if ($renewal) {
            // Billing logs the payment for it. This never throws.
            $this->billing()->logDetectedRenewal($domain, $whoisData, $renewal);
        }
        $whoisData = $this->whois->keepKnownPrivacy($domain, $whoisData);

        $this->domains->update($id, [
            'registrar' => $whoisData['registrar'],
            'registrar_url' => $whoisData['registrar_url'] ?? null,
            'expiration_date' => $expirationDate,
            'updated_date' => $whoisData['updated_date'] ?? null,
            'abuse_email' => $whoisData['abuse_email'] ?? null,
            'last_checked' => date('Y-m-d H:i:s'),
            'status' => $status,
            'whois_data' => json_encode($whoisData, JSON_INVALID_UTF8_SUBSTITUTE),
        ]);

        $this->sendRefreshAlerts($id, $this->whois->getLastKnownStatus($domain), $status, $renewal);

        if ($log) {
            $logger->info('WHOIS refresh completed', [
                'domain_id' => $id,
                'domain_name' => $domain['domain_name'],
                'new_status' => $status,
                'registrar' => $whoisData['registrar'],
                'expiration_date' => $whoisData['expiration_date'],
                'user_id' => $userId,
            ]);
        }

        return ['ok' => true, 'status' => $status, 'renewal' => $renewal];
    }

    /**
     * Send the status change and renewal alerts for a refresh. The cron compares against the
     * saved data, so a change found here would otherwise never be alerted.
     *
     * @param string $oldStatus WhoisService::getLastKnownStatus() of the domain before the refresh
     */
    private function sendRefreshAlerts(int $id, string $oldStatus, string $status, ?array $renewal): void
    {
        if ($oldStatus === $status && $renewal === null) {
            return;
        }

        $domain = $this->domains->find($id);
        if (!$domain || !Domain::isCheckedByCron($domain, 'whois')) {
            return;
        }

        $notificationService = new NotificationService();

        if ($oldStatus !== $status) {
            $notificationService->alertDomainStatusChange($domain, $oldStatus, $status, $renewal !== null);
        }
        if ($renewal !== null) {
            $notificationService->alertDomainRenewal($domain, $renewal);
        }
    }

    /**
     * Tell the notification group what an edit changed: monitoring, SSL monitoring, DNS
     * monitoring, or the group itself. The messages go to the group in effect after the edit.
     *
     * @param array    $domain  The row from before the edit
     * @param int|null $groupId The group after the edit
     */
    private function sendChangeMessages(array $domain, ?int $groupId, int $isActive, int $dnsMonitoring, int $sslMonitoring): void
    {
        $name = $domain['domain_name'];
        $statusChanged = ($domain['is_active'] != $isActive);
        $oldGroupId = $domain['notification_group_id'];

        if ($statusChanged && $groupId) {
            if ($isActive) {
                $message = "🟢 Domain monitoring has been ACTIVATED for {$name}\n\n" .
                          "The domain will now be monitored regularly and you'll receive expiration alerts.";
                $subject = "✅ Monitoring Activated: {$name}";
            } else {
                $message = "🔴 Domain monitoring has been DEACTIVATED for {$name}\n\n" .
                          "You will no longer receive alerts for this domain until monitoring is re-enabled.";
                $subject = "⏸️ Monitoring Paused: {$name}";
            }

            (new NotificationService())->sendToGroup($groupId, $subject, $message);
        }

        if ((($domain['ssl_monitoring_enabled'] ?? 0) != $sslMonitoring) && $groupId) {
            if ($sslMonitoring) {
                $message = "🟢 SSL monitoring has been ENABLED for {$name}\n\n" .
                          "The root certificate and monitored SSL endpoints will now be checked automatically.";
                $subject = "✅ SSL Monitoring Enabled: {$name}";
            } else {
                $message = "🔴 SSL monitoring has been DISABLED for {$name}\n\n" .
                          "SSL certificates will no longer be checked until monitoring is re-enabled.";
                $subject = "⏸️ SSL Monitoring Disabled: {$name}";
            }

            (new NotificationService())->sendToGroup($groupId, $subject, $message);
        }

        if ((($domain['dns_monitoring_enabled'] ?? 1) != $dnsMonitoring) && $groupId) {
            if ($dnsMonitoring) {
                $message = "🟢 DNS monitoring has been ENABLED for {$name}\n\n" .
                          "DNS records will be checked for changes and you'll receive alerts when they change.";
                $subject = "✅ DNS Monitoring Enabled: {$name}";
            } else {
                $message = "🔴 DNS monitoring has been DISABLED for {$name}\n\n" .
                          "DNS records will no longer be checked. You will not receive DNS change alerts.";
                $subject = "⏸️ DNS Monitoring Disabled: {$name}";
            }

            (new NotificationService())->sendToGroup($groupId, $subject, $message);
        }

        // A new group on a domain that is and stays monitored
        if (!$statusChanged && $isActive && $oldGroupId != $groupId && $groupId) {
            $group = $this->groups->find($groupId);
            $groupName = $group ? $group['name'] : 'Unknown Group';

            $message = "🔔 Notification group updated for {$name}\n\n" .
                      "This domain is now assigned to: {$groupName}\n" .
                      "You will receive expiration alerts through this notification group.";
            $subject = "📬 Group Changed: {$name}";

            (new NotificationService())->sendToGroup($groupId, $subject, $message);
        }
    }

    /**
     * Why a notification group cannot be given to a domain of this user, or null when it can: it
     * has to exist and, in isolated mode, to be the user's own. The domain import asks this for
     * the group picked on its form.
     */
    public function groupError(?int $groupId, int $userId): ?string
    {
        if (!$groupId) {
            return null;
        }

        $group = $this->groups->find($groupId);

        if ($this->scopeFor($userId) !== null) {
            return !$group || $group['user_id'] != $userId
                ? 'You can only assign domains to your own notification groups'
                : null;
        }

        return $group ? null : 'Notification group not found';
    }

    /**
     * The result for a lookup that failed. The reason is read at once: the next lookup clears it.
     */
    private function lookupFailure(): array
    {
        return [
            'ok' => false,
            'code' => 'lookup_failed',
            'error' => WhoisService::getLastErrorMessage(),
            'rate_limited' => WhoisService::wasLastErrorRateLimit(),
        ];
    }

    private function fail(string $code, string $error): array
    {
        return ['ok' => false, 'code' => $code, 'error' => $error];
    }

    private function billing(): BillingService
    {
        return $this->billing ??= new BillingService();
    }

    private function history(): DnsHistoryService
    {
        return $this->history ??= new DnsHistoryService();
    }
}
