<?php
/*
 * PayPlus callbacks for the donations table.
 *
 * Three things are kept apart, each with its own state, because each can be true
 * without the others:
 *
 *   Registration  PayPlus stored the card and created the monthly subscription.
 *                 No money moved. Recorded in registered_at, from a Token callback.
 *   Payment       PayPlus charged the card. Recorded in payment_verified_at and
 *                 transaction_uid, only from a Charge callback PayPlus confirms.
 *   Ledger        Salesforce's Payment record. The website never creates it for a
 *                 registration: what it sends is recorded in sf_origin, and
 *                 Salesforce records the charge when PayPlus tells it about one.
 *
 * A monthly sign-up on the 1st-3rd is registered first and charged on the 3rd;
 * any other donation is charged at once and needs no registration.
 *
 * Every callback must be signed with our PayPlus secret key (the `hash` header is
 * the base64 HMAC-SHA256 of the raw body, the scheme PayPlus's own WooCommerce
 * plugin verifies) and is first parked in its own table holding only the fields we
 * use -- never the raw body, which also carries the donor's ID number. Beyond that:
 *
 *   A Token is matched by its payment page alone and must name a subscription,
 *   card and customer. There is no payment to look up, so PayPlus is not asked:
 *   its lookup says nothing either way about a card that has not been charged.
 *
 *   A Charge must be for this donation, from this donation's payment page, in
 *   shekels, for the amount the donor chose, and PayPlus, asked directly, must
 *   confirm it approved. A failed or unclear answer is never taken as approval:
 *   the callback stays pending and the ten-minute job asks again.
 *
 * A signed callback that fails a check stays parked, an admin is emailed, and the
 * donation is held back from Salesforce for a while so that a fix (and the
 * "Re-check" button on the donations page) can still apply it. PayPlus sending the
 * same callback again finds the event it already made.
 */
class GreenDonationPayments
{
    const SCHEMA_VERSION = 4;
    const SCHEMA_OPTION = 'gp_il_donation_payments_schema';

    // Event states
    const PENDING = 'pending';       // waiting for PayPlus to confirm
    const APPLIED = 'applied';       // a confirmed charge, copied into the donation
    const REGISTERED = 'registered'; // a card and subscription with no charge yet, copied into the donation
    const REJECTED = 'rejected';     // failed a check: parked, admin emailed
    const RECORDED = 'recorded';     // genuine, but changes nothing (a decline, a refund, a later recurring charge)
    const DUPLICATE = 'duplicate';   // the payment already applied, sent again

    // A donation PayPlus's lookup calls approved with no signed callback to confirm it
    const LEAD_HELD = 'held';         // not sent to Salesforce, waiting for a person
    const LEAD_RELEASED = 'released'; // a person checked: not paid, and it may go as a lead
    const LEAD_PAID = 'paid';         // a person checked: paid, and recorded in Salesforce by hand. Never sent.
    const LEAD_CLOSED = 'closed';     // a person checked: no action needed. Never sent.

    // A payment page expires after 30 minutes (see getIframe), so an unpaid
    // donation is sent to Salesforce as a lead only once it can no longer be paid.
    const UNPAID_LEAD_AFTER_MINUTES = 35;
    // How long the ten-minute job keeps asking PayPlus about a pending callback.
    const PENDING_GIVE_UP_HOURS = 24;
    // How long a donation with a signed-but-rejected callback waits for a fix
    // before it goes to Salesforce without payment details.
    const HOLD_HOURS = 72;
    // How long a held donation waits before the daily report also goes to the
    // escalation contact.
    const QUEUE_ESCALATE_AFTER_DAYS = 7;
    const QUEUE_REPORT_HOOK = 'gp_il_donation_queue_report';

    const ISSUERS = array(
        "1" => "ישראכרד",
        "2" => "ויזה כ.א.ל",
        "3" => "דיינרס",
        "4" => "אמריקן אקספרס",
        "5" => "JCB",
        "6" => "לאומי כארד"
    );

    protected $api;

    public function __construct($api) {
        $this->api = $api;
    }

    public static function register() {
        // Handle /payplus-callback/ whichever template the page is set to: on
        // www-dev the page has the default template, so callbacks used to be
        // answered with an ordinary page and never processed.
        add_action('template_redirect', function () {
            if (is_page('payplus-callback')) {
                self::handleCallbackRequest();
            }
        });
        add_action('admin_init', array(self::class, 'ensureSchema'));
        add_action('admin_post_donation_payment_recheck', array(self::class, 'recheckFromAdmin'));
        add_action('admin_post_donation_resolve_lead', array(self::class, 'resolveLeadFromAdmin'));
        add_action('init', array(self::class, 'scheduleQueueReport'));
        add_action(self::QUEUE_REPORT_HOOK, array(self::class, 'sendQueueReport'));
    }

    public static function eventsTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'green_donation_payment_events';
    }

    public static function donationsTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'green_donations';
    }

    /**
     * Shekels in whatever form PayPlus or the form sends them ("50", 50.5,
     * "100.00") to whole agorot, so amounts are compared exactly.
     */
    public static function toAgorot($amount) {
        if (is_int($amount) || is_float($amount) || (is_string($amount) && is_numeric(trim($amount)))) {
            return (int) round(((float) $amount) * 100);
        }
        return null;
    }


    // ------------------------------------------------------------------
    // Schema
    // ------------------------------------------------------------------

    /**
     * Adds the payment columns to the donations table and creates the events
     * table, once. Runs from admin_init, the ten-minute job and every path
     * that needs it, so a callback never meets a table that is not ready.
     */
    public static function ensureSchema(): bool {
        if ((int) get_option(self::SCHEMA_OPTION, 0) >= self::SCHEMA_VERSION) {
            return true;
        }

        global $wpdb;
        $donations = self::donationsTable();

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $donations)) !== $donations) {
            return false;
        }

        if (!$wpdb->get_var("SELECT GET_LOCK('gp_il_donation_payments_schema', 10)")) {
            return false;
        }

        try {
            // Another request may have finished while this one waited for the lock.
            $current = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::SCHEMA_OPTION));
            if ($current >= self::SCHEMA_VERSION) {
                wp_cache_delete('alloptions', 'options');
                return true;
            }

            $ok = true;
            $columns = $wpdb->get_col("SHOW COLUMNS FROM `$donations`", 0);
            $add = array(
                'page_request_uid'       => "ADD COLUMN `page_request_uid` varchar(64) DEFAULT NULL",
                'expected_amount_agorot' => "ADD COLUMN `expected_amount_agorot` int(11) DEFAULT NULL",
                'first_charge_deferred'  => "ADD COLUMN `first_charge_deferred` tinyint(1) NOT NULL DEFAULT 0",
                'transaction_uid'        => "ADD COLUMN `transaction_uid` varchar(64) DEFAULT NULL",
                'payment_verified_at'    => "ADD COLUMN `payment_verified_at` datetime DEFAULT NULL",
                'recurring_uid'          => "ADD COLUMN `recurring_uid` varchar(64) DEFAULT NULL",
                'customer_uid'           => "ADD COLUMN `customer_uid` varchar(64) DEFAULT NULL",
                'registered_at'          => "ADD COLUMN `registered_at` datetime DEFAULT NULL",
                'sf_origin'              => "ADD COLUMN `sf_origin` varchar(40) DEFAULT NULL",
                'lead_review'            => "ADD COLUMN `lead_review` varchar(10) DEFAULT NULL",
                'lead_held_at'           => "ADD COLUMN `lead_held_at` datetime DEFAULT NULL",
                'lead_resolved_at'       => "ADD COLUMN `lead_resolved_at` datetime DEFAULT NULL",
                'lead_resolved_by'       => "ADD COLUMN `lead_resolved_by` varchar(60) DEFAULT NULL",
                'lead_resolution_note'   => "ADD COLUMN `lead_resolution_note` varchar(255) DEFAULT NULL",
            );
            foreach ($add as $column => $sql) {
                if (!in_array($column, $columns, true)) {
                    $ok = $ok && false !== $wpdb->query("ALTER TABLE `$donations` $sql");
                }
            }

            // Shekels and agorot: the column was an integer, so 50.50 was stored as 50.
            $amount = $wpdb->get_row("SHOW COLUMNS FROM `$donations` LIKE 'amount'");
            if ($amount && stripos($amount->Type, 'decimal') === false) {
                $ok = $ok && false !== $wpdb->query("ALTER TABLE `$donations` MODIFY `amount` decimal(10,2) DEFAULT NULL");
            }

            if (!$wpdb->get_row("SHOW INDEX FROM `$donations` WHERE Key_name = 'page_request_uid'")) {
                $ok = $ok && false !== $wpdb->query("ALTER TABLE `$donations` ADD KEY `page_request_uid` (`page_request_uid`)");
            }

            $events = self::eventsTable();
            $ok = $ok && false !== $wpdb->query("CREATE TABLE IF NOT EXISTS `$events` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `donation_id` int(11) NOT NULL DEFAULT 0,
                `received_at` datetime NOT NULL,
                `updated_at` datetime DEFAULT NULL,
                `state` varchar(20) NOT NULL,
                `reason` varchar(255) NOT NULL DEFAULT '',
                `attempts` int(11) NOT NULL DEFAULT 0,
                `signature_ok` tinyint(1) NOT NULL DEFAULT 0,
                `transaction_type` varchar(30) NOT NULL DEFAULT '',
                `transaction_uid` varchar(64) NOT NULL DEFAULT '',
                `page_request_uid` varchar(64) NOT NULL DEFAULT '',
                `status_code` varchar(10) NOT NULL DEFAULT '',
                `amount_agorot` int(11) DEFAULT NULL,
                `currency` varchar(3) NOT NULL DEFAULT '',
                `card_token` varchar(64) DEFAULT NULL,
                `card_expiry` varchar(10) DEFAULT NULL,
                `card_holder` varchar(100) DEFAULT NULL,
                `card_last_four` varchar(4) DEFAULT NULL,
                `card_issuer_id` varchar(10) DEFAULT NULL,
                `card_foreign` varchar(5) DEFAULT NULL,
                `credit_terms` varchar(10) DEFAULT NULL,
                `voucher_number` varchar(100) DEFAULT NULL,
                `payload_fields` varchar(500) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `donation_id` (`donation_id`),
                KEY `state` (`state`)
            ) DEFAULT CHARSET=utf8mb4");

            // dedupe_key is unique so that PayPlus sending a callback twice, even at the
            // same moment, makes one event. Unsigned callbacks have none (NULL repeats
            // freely), so a forged one can never take the real one's place.
            $eventColumns = $ok ? $wpdb->get_col("SHOW COLUMNS FROM `$events`", 0) : array();
            $addEvent = array(
                'payload_fields' => "ADD COLUMN `payload_fields` varchar(500) DEFAULT NULL",
                'recurring_uid'  => "ADD COLUMN `recurring_uid` varchar(64) NOT NULL DEFAULT ''",
                'customer_uid'   => "ADD COLUMN `customer_uid` varchar(64) NOT NULL DEFAULT ''",
                'dedupe_key'     => "ADD COLUMN `dedupe_key` varchar(190) DEFAULT NULL, ADD UNIQUE KEY `dedupe_key` (`dedupe_key`)",
            );
            foreach ($addEvent as $column => $sql) {
                if ($ok && !in_array($column, $eventColumns, true)) {
                    $ok = false !== $wpdb->query("ALTER TABLE `$events` $sql");
                }
            }

            if (!$ok) {
                debug_log('Error', 'ERROR: donation payment tables could not be updated: ' . $wpdb->last_error);
                return false;
            }

            update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
            return true;
        } finally {
            $wpdb->query("SELECT RELEASE_LOCK('gp_il_donation_payments_schema')");
        }
    }


    // ------------------------------------------------------------------
    // Payment page creation
    // ------------------------------------------------------------------

    /**
     * Remember which PayPlus payment page belongs to a donation and what it
     * was opened for, so its callback can be checked against both.
     */
    public static function recordPaymentPage(int $donationId, string $pageRequestUid, $amount, bool $firstChargeDeferred) {
        if ($donationId <= 0 || !self::ensureSchema()) {
            return;
        }

        global $wpdb;
        $wpdb->update(self::donationsTable(), array(
            'page_request_uid'       => $pageRequestUid !== '' ? $pageRequestUid : null,
            'expected_amount_agorot' => self::toAgorot($amount),
            'first_charge_deferred'  => $firstChargeDeferred ? 1 : 0,
        ), array('id' => $donationId));
    }


    // ------------------------------------------------------------------
    // Receiving a callback
    // ------------------------------------------------------------------

    public static function handleCallbackRequest() {
        $signature = isset($_SERVER['HTTP_HASH']) ? (string) wp_unslash($_SERVER['HTTP_HASH']) : '';
        $status = (new self(new PayPlus()))->receiveCallback((string) file_get_contents('php://input'), $signature);

        status_header($status);
        nocache_headers();
        echo $status === 200 ? 'ok' : 'error';
        exit;
    }

    /**
     * @return int the HTTP status to answer PayPlus with
     */
    public function receiveCallback(string $rawBody, string $signature): int {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !isset($payload['transaction']) || !is_array($payload['transaction'])) {
            return 400;
        }

        if (!self::ensureSchema()) {
            debug_log('Error', 'ERROR: PayPlus callback arrived before the payment tables were ready; asking PayPlus to retry.');
            return 503;
        }

        $signatureOk = $this->signatureIsValid($rawBody, $signature);
        $eventId = $this->park($payload, $signatureOk);

        if (!$eventId) {
            debug_log('Error', 'ERROR: PayPlus callback could not be stored: ' . $GLOBALS['wpdb']->last_error);
            return 500;
        }

        if (!$signatureOk) {
            $this->settle($eventId, self::REJECTED, 'not signed with our PayPlus secret key');
            return 403;
        }

        $this->verify($eventId);
        return 200;
    }

    protected function signatureIsValid(string $rawBody, string $signature): bool {
        $params = getPayPlusParams();
        $secret = (string) ($params['secret_key'] ?? '');

        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $rawBody, $secret, true)), $signature);
    }

    /**
     * Store the fields we use. Card details are kept only for callbacks that
     * PayPlus signed: an unsigned one is never applied, so they would be dead
     * weight that someone else typed in.
     */
    protected function park(array $payload, bool $signatureOk): int {
        global $wpdb;
        $events = self::eventsTable();

        $t = $payload['transaction'];
        $card = isset($payload['data']['card_information']) && is_array($payload['data']['card_information'])
            ? $payload['data']['card_information']
            : array();
        $text = function ($value, int $max) {
            return is_scalar($value) ? mb_substr(trim((string) $value), 0, $max) : '';
        };

        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
        $moreInfo = $t['more_info'] ?? ($data['more_info'] ?? ($payload['more_info'] ?? null));

        $row = array(
            'donation_id'      => is_scalar($moreInfo) ? (int) $moreInfo : 0,
            'received_at'      => current_time('mysql', true),
            'state'            => self::PENDING,
            'signature_ok'     => $signatureOk ? 1 : 0,
            'transaction_type' => $text($payload['transaction_type'] ?? '', 30),
            'transaction_uid'  => $text($t['uid'] ?? '', 64),
            'page_request_uid' => $text($t['payment_page_request_uid'] ?? ($t['payment_request_uid'] ?? ($t['page_request_uid'] ?? ($data['page_request_uid'] ?? ''))), 64),
            'status_code'      => $text($t['status_code'] ?? '', 10),
            'amount_agorot'    => self::toAgorot($t['amount'] ?? null),
            'currency'         => strtoupper($text($t['currency'] ?? '', 3)),
        );

        if ($signatureOk) {
            $charge = isset($t['recurring_charge_information']) && is_array($t['recurring_charge_information'])
                ? $t['recurring_charge_information']
                : array();
            $row += array(
                'recurring_uid'  => $text($t['recurring_payment_uid'] ?? ($charge['recurring_uid'] ?? ''), 64),
                'customer_uid'   => $text($data['customer_uid'] ?? '', 64),
                'card_token'     => $text($card['token'] ?? ($data['token'] ?? ($t['token'] ?? '')), 64),
                'card_expiry'    => $text(($card['expiry_month'] ?? '') . ($card['expiry_year'] ?? ''), 10),
                'card_holder'    => $text($card['card_holder_name'] ?? '', 100),
                'card_last_four' => $text($card['four_digits'] ?? '', 4),
                'card_issuer_id' => $text($card['issuer_id'] ?? '', 10),
                'card_foreign'   => $text($card['card_foreign'] ?? '', 5),
                'credit_terms'   => $text($t['credit_terms'] ?? '', 10),
                'voucher_number' => $text($t['voucher_number'] ?? '', 100),
                'payload_fields' => $text(self::fieldNames($payload), 500),
            );
        }

        if ($signatureOk) {
            $row['dedupe_key'] = self::dedupeKey($row);
            $existing = self::eventWithKey($row['dedupe_key']);
            if ($existing) {
                return $existing;
            }
        }

        if (false === $wpdb->insert($events, $row)) {
            // The same callback arriving twice at once: the other request got there first.
            return !empty($row['dedupe_key']) ? self::eventWithKey($row['dedupe_key']) : 0;
        }

        debug_log('Panic', "PayPlus callback parked as event {$wpdb->insert_id} for donation {$row['donation_id']} (signed: " . ($signatureOk ? 'yes' : 'no') . ")");
        return (int) $wpdb->insert_id;
    }


    /**
     * What makes this callback the same one if PayPlus sends it again. A Cancel
     * carries the id of the charge it cancels, so the type is part of the key, and
     * a decline and a later approval of one transaction are different news.
     */
    protected static function dedupeKey(array $row) {
        $type = strtolower($row['transaction_type']);

        if ($type === 'token') {
            return $row['page_request_uid'] !== '' && $row['recurring_uid'] !== ''
                ? "token:{$row['page_request_uid']}:{$row['recurring_uid']}"
                : null;
        }
        return $row['transaction_uid'] !== ''
            ? "{$type}:{$row['transaction_uid']}:{$row['status_code']}"
            : null;
    }

    protected static function eventWithKey($key): int {
        if (empty($key)) {
            return 0;
        }
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM `" . self::eventsTable() . "` WHERE dedupe_key = %s", $key));
    }

    /**
     * The names of the fields in a callback, never their values, so the
     * donations page can show what PayPlus sent when one cannot be matched.
     */
    protected static function fieldNames(array $payload): string {
        $parts = array();
        foreach (array('transaction' => $payload['transaction'] ?? null, 'data' => $payload['data'] ?? null, 'card' => $payload['data']['card_information'] ?? null, 'top' => $payload) as $label => $section) {
            if (is_array($section)) {
                $parts[] = $label . ': ' . implode(',', array_map('strval', array_keys($section)));
            }
        }
        return implode(' | ', $parts);
    }


    // ------------------------------------------------------------------
    // Verifying and applying
    // ------------------------------------------------------------------

    /**
     * The one donation a callback belongs to. A payment page is ours and unique,
     * so it decides; a number the callback also gives must agree with it. A
     * donation number alone identifies a donation whose page we never stored.
     *
     * @return array the donation or null, and why not
     */
    protected function matchDonation($event): array {
        global $wpdb;
        $table = self::donationsTable();
        $number = (int) $event->donation_id;
        $page = (string) $event->page_request_uid;

        if ($page !== '') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE page_request_uid = %s LIMIT 2", $page));
            if (count($rows) > 1) {
                return array(null, 'this payment page belongs to more than one donation');
            }
            if ($rows) {
                return $number > 0 && $number !== (int) $rows[0]->id
                    ? array(null, "callback says donation {$number}, its payment page belongs to donation {$rows[0]->id}")
                    : array($rows[0], '');
            }
        }

        if ($number > 0) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id = %d", $number));
            if (!$row) {
                return array(null, "no donation {$number}");
            }
            return $page !== '' && (string) $row->page_request_uid !== ''
                ? array(null, 'callback is for a different payment page')
                : array($row, '');
        }

        return array(null, 'no donation matched (payment page id: ' . ($page !== '' ? 'yes' : 'no') . ', transaction id: ' . ((string) $event->transaction_uid !== '' ? 'yes' : 'no') . ')');
    }

    /**
     * Record whose callback this is, so the donations page and the Salesforce
     * hold both see the right donation.
     */
    protected function attachToDonation($event, $donation) {
        if ((int) $event->donation_id !== (int) $donation->id) {
            $GLOBALS['wpdb']->update(self::eventsTable(), array('donation_id' => (int) $donation->id), array('id' => (int) $event->id));
            $event->donation_id = (int) $donation->id;
        }
    }

    /**
     * Check a parked callback and, if everything holds, copy it into the
     * donation. Safe to run again on the same event: the ten-minute job does
     * for pending ones, and admins can for rejected ones.
     *
     * @return string the event's new state
     */
    public function verify(int $eventId): string {
        global $wpdb;
        $events = self::eventsTable();
        $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$events` WHERE id = %d", $eventId));

        if (!$event) {
            return '';
        }
        if (!in_array($event->state, array(self::PENDING, self::REJECTED), true)) {
            return $event->state;
        }
        if (!(int) $event->signature_ok) {
            return $this->settle($eventId, self::REJECTED, 'not signed with our PayPlus secret key');
        }

        $type = strtolower(trim((string) $event->transaction_type));

        if ($type === 'token') {
            return $this->registerSignup($event);
        }
        if ($type === '' || $type === 'charge') {
            return $this->confirmCharge($event);
        }

        if ($type === 'cancel' || $type === 'refund') {
            return $this->reverseCharge($event, $type);
        }

        list($donation) = $this->matchDonation($event);
        if ($donation) {
            $this->attachToDonation($event, $donation);
        }
        return $this->settle($eventId, self::RECORDED, "{$event->transaction_type}; not a first payment");
    }

    /**
     * A Cancel or Refund carries the id of the charge it reverses, and usually
     * nothing else. Salesforce keeps the ledger, so this only matters for the
     * charge this donation is recorded as paid by: a Cancel that arrives before
     * Salesforce was told means it must not be told this donation was paid.
     *
     * Only status 000 counts as having happened, as it does for a charge. Any
     * other code changes nothing. No code at all proves nothing either way, so
     * it is parked, which also holds the donation (see notHeldSql) for a person.
     */
    protected function reverseCharge($event, string $type): string {
        global $wpdb;
        $table = self::donationsTable();
        $eventId = (int) $event->id;
        $uid = (string) $event->transaction_uid;
        $code = (string) $event->status_code;

        $donation = $uid === '' ? null : $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE transaction_uid = %s LIMIT 1", $uid));
        if ($donation) {
            $this->attachToDonation($event, $donation);
        } else {
            list($matched) = $this->matchDonation($event);
            if ($matched) {
                $this->attachToDonation($event, $matched);
            }
        }

        if ($code === '') {
            return $this->settle($eventId, self::REJECTED, "{$event->transaction_type} with no status code, so it may not have happened; nothing changed. Check it in PayPlus.");
        }
        if ($code !== '000') {
            if ($donation) {
                self::notifyAdmins("Donation {$donation->id}: PayPlus reports a {$event->transaction_type} of its charge that did not succeed (code {$code}). The charge stands and nothing changed here. Check in PayPlus whether it still needs reversing.");
            }
            return $this->settle($eventId, self::RECORDED, "{$event->transaction_type} did not succeed (code {$code}); nothing changed");
        }
        if (!$donation) {
            return $this->settle($eventId, self::RECORDED, "{$event->transaction_type} of a charge this site did not confirm");
        }

        // A charge a person recorded by hand is in the ledger, even though the job never sent it.
        $undone = $type === 'cancel' && $wpdb->query($wpdb->prepare(
            "UPDATE `$table`
                SET payment_verified_at = NULL, transaction_uid = NULL, amount = NULL, shovar = '', card_type = '',
                    token = IF(registered_at IS NULL, NULL, token), exp = IF(registered_at IS NULL, NULL, exp),
                    cc_holder = IF(registered_at IS NULL, NULL, cc_holder), last_four = IF(registered_at IS NULL, '', last_four),
                    tourist = IF(registered_at IS NULL, 0, tourist), ccval = IF(registered_at IS NULL, '', ccval)
              WHERE id = %d AND transaction_uid = %s AND sf_origin IS NULL AND transmited_to_sf <> 1 AND COALESCE(lead_review, '') <> %s",
            (int) $donation->id, $uid, self::LEAD_PAID
        ));

        // Either way a person should know: a cancelled first charge has left a monthly
        // subscription charging, with nothing in Salesforce for it to land on.
        self::notifyAdmins($undone
            ? "Donation {$donation->id}: PayPlus cancelled its charge before Salesforce was told about it, so it is no longer treated as paid. If it was monthly, check in PayPlus that the subscription stopped too."
            : "Donation {$donation->id}: PayPlus reports a {$event->transaction_type} of a charge Salesforce was already told about, so its Payment there needs checking.");

        return $this->settle($eventId, self::RECORDED, $undone ? 'Cancel before Salesforce was told; no longer treated as paid' : "{$event->transaction_type} of a charge Salesforce was told about");
    }

    /**
     * A Token callback: PayPlus stored the card and created the subscription.
     * Nothing has been charged, so this never touches the payment columns.
     */
    protected function registerSignup($event): string {
        global $wpdb;
        $eventId = (int) $event->id;

        if ((string) $event->status_code !== '000') {
            return $this->settle($eventId, self::RECORDED, 'PayPlus did not create the sign-up (code ' . ((string) $event->status_code !== '' ? $event->status_code : '-') . ')');
        }
        if ((string) $event->page_request_uid === '') {
            return $this->settle($eventId, self::REJECTED, 'Token callback without a payment page');
        }

        list($donation, $why) = $this->matchDonation($event);
        if (!$donation) {
            return $this->settle($eventId, self::REJECTED, $why);
        }
        $this->attachToDonation($event, $donation);

        // An immediate first payment also sends a Token; its Charge is what counts.
        if (empty($donation->first_charge_deferred)) {
            return $this->settle($eventId, self::RECORDED, 'Token on a donation charged at once; its Charge decides');
        }
        if ((string) $donation->page_request_uid !== (string) $event->page_request_uid) {
            return $this->settle($eventId, self::REJECTED, 'callback is for a different payment page');
        }
        if ((string) $donation->payment_type !== 'recurring') {
            return $this->settle($eventId, self::REJECTED, 'Token callback on a donation that is not monthly');
        }
        if (!empty($event->amount_agorot)) {
            return $this->settle($eventId, self::REJECTED, 'Token callback carrying an amount: ' . self::formatAgorot((int) $event->amount_agorot));
        }
        if ($donation->expected_amount_agorot === null || $donation->expected_amount_agorot === '') {
            return $this->settle($eventId, self::REJECTED, 'sign-up with no amount on record');
        }

        $required = array(
            'subscription id' => $event->recurring_uid,
            'card token'      => $event->card_token,
            'card expiry'     => $event->card_expiry,
            'customer id'     => $event->customer_uid,
        );
        $missing = array_keys(array_filter($required, function ($value) {
            return (string) $value === '';
        }));
        if ($missing) {
            return $this->settle($eventId, self::REJECTED, 'Token callback without ' . implode(', ', $missing));
        }

        $issuer = (string) $event->card_issuer_id;
        // Sized to the donation table's existing columns.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE `" . self::donationsTable() . "`
                SET token = %s, exp = %s, cc_holder = %s, last_four = %s, tourist = %d, ccval = %s,
                    recurring_uid = %s, customer_uid = %s, registered_at = %s
              WHERE id = %d AND registered_at IS NULL",
            (string) $event->card_token,
            mb_substr((string) $event->card_expiry, 0, 30),
            mb_substr((string) $event->card_holder, 0, 40),
            (string) $event->card_last_four,
            in_array(strtolower((string) $event->card_foreign), array('1', 'true'), true) ? 1 : 0,
            mb_substr((string) (self::ISSUERS[$issuer] ?? $issuer), 0, 40),
            (string) $event->recurring_uid,
            (string) $event->customer_uid,
            current_time('mysql', true),
            (int) $donation->id
        ));

        if ($updated === false) {
            return $this->settle($eventId, self::REJECTED, 'database error while registering: ' . $wpdb->last_error);
        }
        if ($updated === 0) {
            return (string) $donation->recurring_uid === (string) $event->recurring_uid
                ? $this->settle($eventId, self::DUPLICATE, 'sign-up already registered')
                : $this->settle($eventId, self::REJECTED, 'donation is already registered to a different subscription');
        }

        if (!empty($donation->sale_f_id) || (int) $donation->transmited_to_sf === 1) {
            self::notifyAdmins("donation {$donation->id}: the monthly sign-up registered after Salesforce was already told about this donation ({$donation->sf_origin}), so it needs recording there by hand");
        }
        self::noteLateCallback($donation, 'monthly sign-up');

        debug_log('Panic', "PayPlus sign-up registered on donation {$donation->id} (event {$eventId}); no charge yet");
        return $this->settle($eventId, self::REGISTERED, 'card and subscription stored; no charge yet');
    }

    /**
     * A Charge callback: money moved, if PayPlus says so when asked.
     */
    protected function confirmCharge($event): string {
        $eventId = (int) $event->id;
        $statusCode = (string) $event->status_code;

        list($donation, $why) = $this->matchDonation($event);

        // A declined card is not a fault to chase: the donor can try again on the same page.
        if ($statusCode !== '' && $statusCode !== '000') {
            if ($donation) {
                $this->attachToDonation($event, $donation);
            }
            return $this->settle($eventId, self::RECORDED, "declined (code {$statusCode})");
        }

        if (!$donation && (int) $event->donation_id <= 0 && (string) $event->page_request_uid === '' && (string) $event->transaction_uid !== '') {
            try {
                $answer = $this->api->apiRequest('/PaymentPages/ipn', array('transaction_uid' => (string) $event->transaction_uid));
            } catch (Throwable $e) {
                return $this->stillPending($event, 'PayPlus lookup failed: ' . $e->getMessage());
            }
            if (!self::answerHasData($answer)) {
                return $this->stillPending($event, 'PayPlus lookup: ' . self::answerProblem($answer));
            }
            list($donation, $why) = $this->matchDonation((object) array(
                'donation_id'      => isset($answer->data->more_info) && is_scalar($answer->data->more_info) ? (int) $answer->data->more_info : 0,
                'page_request_uid' => isset($answer->data->page_request_uid) ? (string) $answer->data->page_request_uid : '',
                'transaction_uid'  => (string) $event->transaction_uid,
            ));
        }

        if (!$donation) {
            return $this->settle($eventId, self::REJECTED, $why);
        }
        $this->attachToDonation($event, $donation);

        // Callbacks can cross: a successful Cancel parked first means this charge never
        // stood. A failed one means nothing. One with no status code does not stop the
        // charge, but joins this donation so that it holds it (see notHeldSql).
        if ((string) $event->transaction_uid !== '') {
            $wpdb = $GLOBALS['wpdb'];
            if ($wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `" . self::eventsTable() . "` WHERE transaction_uid = %s AND signature_ok = 1 AND LOWER(transaction_type) = 'cancel' AND status_code = '000' LIMIT 1",
                (string) $event->transaction_uid
            ))) {
                return $this->settle($eventId, self::RECORDED, 'already cancelled');
            }
            $wpdb->query($wpdb->prepare(
                "UPDATE `" . self::eventsTable() . "` SET donation_id = %d WHERE transaction_uid = %s AND signature_ok = 1 AND LOWER(transaction_type) = 'cancel' AND status_code = ''",
                (int) $donation->id, (string) $event->transaction_uid
            ));
        }

        // Already paid: this is a repeat, or a later recurring charge. Rows from
        // before payments were verified have no transaction_uid but do have a
        // token, which on a registered donation only means the card was stored.
        if (!empty($donation->transaction_uid)) {
            return $donation->transaction_uid === $event->transaction_uid
                ? $this->settle($eventId, self::DUPLICATE, 'payment already applied')
                : $this->settle($eventId, self::RECORDED, 'later charge on an already-paid donation');
        }
        if (empty($donation->payment_verified_at) && empty($donation->registered_at) && !empty($donation->token)) {
            return $this->settle($eventId, self::RECORDED, 'later charge on an already-paid donation');
        }

        if ($statusCode === '') {
            return $this->settle($eventId, self::REJECTED, 'Charge callback without a status code');
        }
        if ((string) $event->transaction_uid === '') {
            return $this->settle($eventId, self::REJECTED, 'Charge callback without a transaction id');
        }
        if ($event->currency !== '' && $event->currency !== 'ILS') {
            return $this->settle($eventId, self::REJECTED, "currency {$event->currency}, expected ILS");
        }

        $got = $event->amount_agorot === null ? null : (int) $event->amount_agorot;
        if (empty($got)) {
            return $this->settle($eventId, self::REJECTED, 'Charge callback without an amount');
        }
        $expected = $donation->expected_amount_agorot;
        if ($expected !== null && $expected !== '' && $got !== (int) $expected) {
            return $this->settle($eventId, self::REJECTED, 'amount ' . self::formatAgorot($got) . ', expected ' . self::formatAgorot((int) $expected));
        }

        // Ask PayPlus. By our own stored page when that can only mean this charge, so
        // the answer is about this donation by construction. A monthly sign-up's page
        // goes on to have many charges, so that one is asked about this transaction.
        $page = (string) $donation->page_request_uid !== '' ? (string) $donation->page_request_uid : (string) $event->page_request_uid;
        $query = $page !== '' && empty($donation->first_charge_deferred)
            ? array('payment_request_uid' => $page)
            : array('transaction_uid' => (string) $event->transaction_uid);

        try {
            $answer = $this->api->apiRequest('/PaymentPages/ipn', $query);
        } catch (Throwable $e) {
            return $this->stillPending($event, 'PayPlus lookup failed: ' . $e->getMessage());
        }

        if (!self::answerHasData($answer)) {
            return $this->stillPending($event, 'PayPlus lookup: ' . self::answerProblem($answer));
        }

        $d = $answer->data;
        $answerCode = isset($d->status_code) ? (string) $d->status_code : '';
        $answerStatus = isset($d->status) ? strtolower((string) $d->status) : '';

        if ($answerCode === '' && $answerStatus === '') {
            return $this->settle($eventId, self::REJECTED, 'PayPlus answered without a payment status');
        }
        if (($answerCode !== '' && $answerCode !== '000') || ($answerStatus !== '' && $answerStatus !== 'approved')) {
            return $this->settle($eventId, self::REJECTED, 'PayPlus says the payment is not approved (status ' . ($answerStatus !== '' ? $answerStatus : '-') . ', code ' . ($answerCode !== '' ? $answerCode : '-') . ')');
        }
        if (!empty($d->page_request_uid) && $page !== '' && (string) $d->page_request_uid !== $page) {
            return $this->settle($eventId, self::REJECTED, 'PayPlus has this payment under a different payment page');
        }
        if (!empty($d->transaction_uid) && (string) $d->transaction_uid !== (string) $event->transaction_uid) {
            return $this->settle($eventId, self::REJECTED, 'PayPlus has a different transaction for this payment page');
        }
        // A monthly sign-up's page has many charges, so the page says nothing about this one.
        if (!empty($donation->first_charge_deferred) && empty($d->transaction_uid)) {
            return $this->settle($eventId, self::REJECTED, 'PayPlus did not confirm this exact transaction');
        }
        if (empty($d->transaction_uid) && empty($d->page_request_uid)) {
            return $this->settle($eventId, self::REJECTED, 'PayPlus answered without saying which payment it means');
        }
        if (isset($d->more_info) && is_scalar($d->more_info) && (string) $d->more_info !== '' && (int) $d->more_info !== (int) $donation->id) {
            return $this->settle($eventId, self::REJECTED, 'PayPlus has this payment under a different donation');
        }
        if (isset($d->amount) && self::toAgorot($d->amount) !== null && self::toAgorot($d->amount) !== $got) {
            return $this->settle($eventId, self::REJECTED, 'PayPlus has amount ' . self::formatAgorot(self::toAgorot($d->amount)) . ', callback says ' . self::formatAgorot($got));
        }

        return $this->apply($event, $donation);
    }

    protected static function answerHasData($answer): bool {
        return is_object($answer) && isset($answer->results->status) && $answer->results->status === 'success' && isset($answer->data) && is_object($answer->data);
    }

    protected static function answerProblem($answer): string {
        return is_object($answer) && isset($answer->results->description) ? (string) $answer->results->description : 'no usable answer';
    }

    protected function apply($event, $donation): string {
        global $wpdb;

        $issuer = (string) $event->card_issuer_id;

        // Sized to the donation table's existing columns.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE `" . self::donationsTable() . "`
                SET amount = %s, exp = IF(%s = '', exp, %s), cc_holder = IF(%s = '', cc_holder, %s), token = IF(%s = '', token, %s),
                    shovar = %s, card_type = %s, last_four = IF(%s = '', last_four, %s), tourist = %d, ccval = IF(%s = '', ccval, %s),
                    recurring_uid = IF(%s = '', recurring_uid, %s), customer_uid = IF(%s = '', customer_uid, %s),
                    transaction_uid = %s, payment_verified_at = %s
              WHERE id = %d AND (transaction_uid IS NULL OR transaction_uid = '')",
            sprintf('%.2f', ((int) $event->amount_agorot) / 100),
            mb_substr((string) $event->card_expiry, 0, 30),
            mb_substr((string) $event->card_expiry, 0, 30),
            mb_substr((string) $event->card_holder, 0, 40),
            mb_substr((string) $event->card_holder, 0, 40),
            (string) $event->card_token,
            (string) $event->card_token,
            mb_substr((string) $event->voucher_number, 0, 100),
            mb_substr((string) $event->credit_terms, 0, 100),
            (string) $event->card_last_four,
            (string) $event->card_last_four,
            in_array(strtolower((string) $event->card_foreign), array('1', 'true'), true) ? 1 : 0,
            $issuer,
            mb_substr((string) (self::ISSUERS[$issuer] ?? $issuer), 0, 40),
            (string) $event->recurring_uid,
            (string) $event->recurring_uid,
            (string) $event->customer_uid,
            (string) $event->customer_uid,
            (string) $event->transaction_uid,
            current_time('mysql', true),
            (int) $donation->id
        ));

        if ($updated === false) {
            return $this->settle((int) $event->id, self::REJECTED, 'database error while applying: ' . $wpdb->last_error);
        }
        if ($updated === 0) {
            return $this->settle((int) $event->id, self::DUPLICATE, 'another callback applied first');
        }

        // A sign-up already sent needs nothing more: Salesforce hears about the charge
        // from PayPlus directly. A lead has no subscription for that charge to land on.
        if ((string) $donation->sf_origin === 'Web Lead') {
            self::notifyAdmins("donation {$donation->id}: a charge was confirmed after Salesforce was sent a lead for it, so it needs recording there by hand");
        }
        self::noteLateCallback($donation, 'charge');

        debug_log('Panic', "PayPlus payment verified and applied to donation {$donation->id} (event {$event->id})");
        return $this->settle((int) $event->id, self::APPLIED, 'verified');
    }

    /**
     * PayPlus could not be asked, or did not answer usefully: leave it pending
     * for the ten-minute job, and stop asking after a day.
     */
    protected function stillPending($event, string $reason): string {
        $age = time() - strtotime($event->received_at . ' UTC');
        if ($age > self::PENDING_GIVE_UP_HOURS * HOUR_IN_SECONDS) {
            return $this->settle((int) $event->id, self::REJECTED, 'PayPlus could not confirm within ' . self::PENDING_GIVE_UP_HOURS . ' hours; last: ' . $reason);
        }
        return $this->settle((int) $event->id, self::PENDING, $reason);
    }

    protected function settle(int $eventId, string $state, string $reason): string {
        global $wpdb;
        $events = self::eventsTable();

        $wpdb->query($wpdb->prepare(
            "UPDATE `$events` SET state = %s, reason = %s, updated_at = %s, attempts = attempts + 1 WHERE id = %d",
            $state, mb_substr($reason, 0, 255), current_time('mysql', true), $eventId
        ));

        // Once an event is settled for good its card details live in the
        // donation (or nowhere), not in a second copy here.
        if (in_array($state, array(self::APPLIED, self::REGISTERED, self::DUPLICATE, self::RECORDED), true)) {
            $wpdb->query($wpdb->prepare(
                "UPDATE `$events` SET card_token = NULL, card_expiry = NULL, card_holder = NULL, card_last_four = NULL, card_issuer_id = NULL, card_foreign = NULL WHERE id = %d",
                $eventId
            ));
        }

        if ($state === self::REJECTED) {
            $event = $wpdb->get_row($wpdb->prepare("SELECT donation_id, signature_ok FROM `$events` WHERE id = %d", $eventId));
            $message = "donation {$event->donation_id}, event {$eventId}: {$reason}";

            // Only callbacks PayPlus signed are emailed: those are the ones a person
            // can act on. Unsigned ones are listed on the donations page; emailing
            // them would let anyone posting junk use up the hourly alert.
            if ((int) $event->signature_ok) {
                self::alert($message);
            } else {
                debug_log('Error', 'Refused an unsigned PayPlus callback: ' . $message);
            }
        } else {
            debug_log('Panic', "PayPlus callback event {$eventId}: {$state} ({$reason})");
        }

        return $state;
    }

    /**
     * Tell the site admins about a callback, at most once an hour so a burst of
     * bad ones cannot flood the inbox. The page lists every event anyway.
     */
    protected static function alert(string $message) {
        debug_log('Error', 'PayPlus callback needs attention: ' . $message);

        if (get_transient('gp_il_payment_alert_sent')) {
            return;
        }
        set_transient('gp_il_payment_alert_sent', 1, HOUR_IN_SECONDS);

        wp_mail(
            get_option('admin_email'),
            'Donations: a PayPlus payment callback needs checking',
            "A PayPlus callback needs checking:\n\n{$message}\n\n"
            . "Callbacks that need attention are listed on the donations page, where a signed one can be re-checked:\n"
            . admin_url('admin.php?page=greenpeace/donations.php') . "\n\n"
            . "Further callback problems in the next hour are listed there but not emailed."
        );
    }

    /**
     * Tell the site admins about a donation someone has to deal with by hand. These
     * come from our own job rather than from anyone posting to us, and nothing else
     * lists them, so each one is sent.
     */
    public static function notifyAdmins(string $message) {
        debug_log('Error', 'Donation needs attention: ' . $message);

        wp_mail(get_option('admin_email'), 'Donations: a donation needs checking in Salesforce', $message);
    }

    public static function formatAgorot($agorot): string {
        return $agorot === null ? 'none' : number_format($agorot / 100, 2, '.', '');
    }


    // ------------------------------------------------------------------
    // The ten-minute job and the admin page
    // ------------------------------------------------------------------

    /**
     * Ask PayPlus again about callbacks it could not confirm earlier.
     */
    public function resolvePending() {
        if (!self::ensureSchema()) {
            return;
        }

        global $wpdb;
        $events = self::eventsTable();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM `$events` WHERE state = %s AND signature_ok = 1 ORDER BY id LIMIT 50", self::PENDING));

        foreach ($ids as $id) {
            $this->verify((int) $id);
        }
    }

    /**
     * SQL condition for the Salesforce job: hold a donation while a signed
     * callback for it is pending or rejected, for up to HOLD_HOURS, so it is
     * not sent without its payment details while a fix can still apply them.
     */
    public static function notHeldSql(string $donationAlias): string {
        global $wpdb;
        return $wpdb->prepare(
            "NOT EXISTS (SELECT 1 FROM `" . self::eventsTable() . "` e
                          WHERE e.donation_id = {$donationAlias}.id AND e.signature_ok = 1
                            AND e.state IN (%s, %s)
                            AND e.received_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR))",
            self::PENDING, self::REJECTED, self::HOLD_HOURS
        );
    }

    /**
     * Callbacks from the last 30 days that were not applied and may need a look,
     * and registered sign-ups that have, or are about to get, only a lead in
     * Salesforce, so someone has to record them there by hand.
     */
    public static function eventsNeedingAttention(): array {
        if (!self::ensureSchema()) {
            return array();
        }

        global $wpdb;
        $unsent = salesforceHandlesSignups() ? '' : 'OR d.sf_origin IS NULL';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.id, e.donation_id, e.received_at, e.state, e.reason, e.signature_ok, e.transaction_type, e.amount_agorot, e.payload_fields
               FROM `" . self::eventsTable() . "` e
               LEFT JOIN `" . self::donationsTable() . "` d ON d.id = e.donation_id
              WHERE e.received_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
                AND ( e.state IN (%s, %s)
                      OR ( e.state = %s AND d.payment_verified_at IS NULL AND ( d.sf_origin = 'Web Lead' $unsent ) ) )
              ORDER BY e.id DESC LIMIT 50",
            self::PENDING, self::REJECTED, self::REGISTERED
        ));
    }

    /**
     * Keep a donation out of Salesforce until a person has looked at it, and tell
     * the queue's owner. The Salesforce job stops selecting it, so this happens once.
     */
    public static function holdForLeadReview(int $donationId) {
        global $wpdb;

        $held = $wpdb->query($wpdb->prepare(
            "UPDATE `" . self::donationsTable() . "` SET lead_review = %s, lead_held_at = %s WHERE id = %d AND lead_review IS NULL",
            self::LEAD_HELD, current_time('mysql', true), $donationId
        ));

        if ($held) {
            debug_log('Error', "Donation {$donationId} held for a person to review");
            wp_mail(
                donationQueueOwner(),
                'Donations: a donation needs checking in Salesforce',
                "Donation {$donationId}: PayPlus's lookup says approved, but no signed callback confirmed a payment. It has not been sent to Salesforce.\n\n"
                . "Check it in PayPlus, then decide on the donations page:\n" . admin_url('admin.php?page=greenpeace/donations.php')
            );
        }
    }

    /**
     * Every held donation still waiting, oldest first, however old. Each stays until
     * a person decides or a signed callback settles it.
     */
    public static function leadsAwaitingReview(): array {
        if (!self::ensureSchema()) {
            return array();
        }

        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, `date`, payment_type, expected_amount_agorot, lead_held_at
               FROM `" . self::donationsTable() . "`
              WHERE lead_review = %s AND payment_verified_at IS NULL AND registered_at IS NULL
              ORDER BY lead_held_at, id",
            self::LEAD_HELD
        ));
    }

    /**
     * The latest that left the queue, by a person's decision or by a callback.
     */
    public static function leadsRecentlyResolved(): array {
        if (!self::ensureSchema()) {
            return array();
        }

        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, lead_review, lead_resolved_by, lead_resolution_note, payment_verified_at, registered_at,
                    COALESCE(lead_resolved_at, payment_verified_at, registered_at) AS resolved_at
               FROM `" . self::donationsTable() . "`
              WHERE lead_held_at IS NOT NULL
                AND ( lead_review <> %s OR payment_verified_at IS NOT NULL OR registered_at IS NOT NULL )
              ORDER BY resolved_at DESC LIMIT 20",
            self::LEAD_HELD
        ));
    }

    public static function leadResolutionLabel($row): string {
        switch ($row->lead_review) {
            case self::LEAD_RELEASED:
                return 'Not paid: send as a lead';
            case self::LEAD_PAID:
                return 'Paid: recorded in Salesforce by hand';
            case self::LEAD_CLOSED:
                return 'Closed: no action needed';
        }
        return !empty($row->payment_verified_at) ? 'A signed charge arrived and settled it' : 'A signed sign-up arrived and settled it';
    }

    public static function leadDaysWaiting($row): int {
        return (int) floor((time() - strtotime($row->lead_held_at . ' UTC')) / DAY_IN_SECONDS);
    }

    /**
     * Record a person's decision on a held donation. A decision that says it needs
     * no sending must say why, because that note is the only trace it leaves here.
     */
    public static function resolveLead(int $donationId, string $resolution, string $note, string $who): bool {
        $note = trim($note);
        if (!in_array($resolution, array(self::LEAD_RELEASED, self::LEAD_PAID, self::LEAD_CLOSED), true)
            || ($resolution !== self::LEAD_RELEASED && $note === '')) {
            return false;
        }

        global $wpdb;
        return (bool) $wpdb->query($wpdb->prepare(
            "UPDATE `" . self::donationsTable() . "`
                SET lead_review = %s, lead_resolved_at = %s, lead_resolved_by = %s, lead_resolution_note = %s
              WHERE id = %d AND lead_review = %s AND payment_verified_at IS NULL AND registered_at IS NULL",
            $resolution, current_time('mysql', true), mb_substr($who, 0, 60), mb_substr($note, 0, 255), $donationId, self::LEAD_HELD
        ));
    }

    public static function resolveLeadFromAdmin() {
        if (!isset($_POST['donation_resolve_lead_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['donation_resolve_lead_nonce'])), 'donation_resolve_lead_action')) {
            wp_die(__('Invalid request.', 'planet4-child-theme-israel'));
        }
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to do this.', 'planet4-child-theme-israel'));
        }

        $donationId = isset($_POST['donation_id']) ? absint($_POST['donation_id']) : 0;
        $resolution = isset($_POST['resolution']) ? sanitize_key(wp_unslash($_POST['resolution'])) : '';
        $note = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';
        $resolved = $donationId && self::resolveLead($donationId, $resolution, $note, wp_get_current_user()->user_login);

        if ($resolved) {
            $message = $resolution === self::LEAD_RELEASED
                ? "Donation {$donationId} will go to Salesforce as a lead on the next ten-minute run."
                : "Donation {$donationId} is resolved and will not be sent to Salesforce.";
        } elseif ($resolution !== self::LEAD_RELEASED && trim($note) === '') {
            $message = "Donation {$donationId}: nothing changed. Say what you found in the note, then save again.";
        } else {
            $message = "Donation {$donationId} was not waiting for a decision, so nothing changed.";
        }
        set_transient('gp_il_donations_notice', array('type' => $resolved ? 'success' : 'warning', 'message' => $message), 30);

        wp_safe_redirect(admin_url('admin.php?page=greenpeace/donations.php'));
        exit;
    }

    /**
     * A signed callback for a donation a person already resolved as needing no
     * sending. It stays unsent, so someone has to compare it with their decision.
     */
    protected static function noteLateCallback($donation, string $what) {
        if (in_array($donation->lead_review ?? '', array(self::LEAD_PAID, self::LEAD_CLOSED), true)) {
            self::notifyAdmins("Donation {$donation->id}: PayPlus sent a signed {$what} after it was resolved by hand (" . self::leadResolutionLabel($donation) . "). It will not be sent to Salesforce, so check that the decision still holds.");
        }
    }

    public static function scheduleQueueReport() {
        if (!wp_next_scheduled(self::QUEUE_REPORT_HOOK)) {
            wp_schedule_event(time(), 'daily', self::QUEUE_REPORT_HOOK);
        }
    }

    /**
     * Once a day, while anything is waiting: the owner gets the list, and once
     * something has waited too long the escalation contact gets it as well. Only
     * donation numbers, types and amounts: the list goes to a mailbox.
     */
    public static function sendQueueReport() {
        $open = self::leadsAwaitingReview();
        if (!$open || get_transient('gp_il_queue_report_sent')) {
            return;
        }

        $overdue = 0;
        $lines = array();
        foreach ($open as $lead) {
            $days = self::leadDaysWaiting($lead);
            $late = $days >= self::QUEUE_ESCALATE_AFTER_DAYS;
            $overdue += $late ? 1 : 0;
            $amount = self::formatAgorot($lead->expected_amount_agorot === null ? null : (int) $lead->expected_amount_agorot);
            $lines[] = "Donation {$lead->id}: waiting {$days} day(s)" . ($late ? ' (overdue)' : '') . ", {$lead->payment_type}, amount {$amount}";
        }

        $subject = 'Donations: ' . count($open) . ' waiting for a decision' . ($overdue ? ", {$overdue} overdue" : '');
        $body = "These were not sent to Salesforce, and stay here until someone decides. Check each in PayPlus, then decide on the donations page:\n"
            . admin_url('admin.php?page=greenpeace/donations.php') . "\n\n"
            . implode("\n", $lines) . "\n\n"
            . 'Owner: ' . donationQueueOwner() . '. Overdue means waiting ' . self::QUEUE_ESCALATE_AFTER_DAYS . ' days or more; those also go to ' . donationQueueEscalation() . '.';

        set_transient('gp_il_queue_report_sent', 1, 20 * HOUR_IN_SECONDS);
        wp_mail(array_unique($overdue ? array(donationQueueOwner(), donationQueueEscalation()) : array(donationQueueOwner())), $subject, $body);
    }

    public static function recheckFromAdmin() {
        if (!isset($_POST['donation_payment_recheck_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['donation_payment_recheck_nonce'])), 'donation_payment_recheck_action')) {
            wp_die(__('Invalid request.', 'planet4-child-theme-israel'));
        }
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to do this.', 'planet4-child-theme-israel'));
        }

        $eventId = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
        $state = $eventId ? (new self(new PayPlus()))->verify($eventId) : '';

        set_transient('gp_il_donations_notice', array(
            'type'    => in_array($state, array(self::APPLIED, self::REGISTERED), true) ? 'success' : 'warning',
            'message' => "Event {$eventId}: {$state}",
        ), 30);

        wp_safe_redirect(admin_url('admin.php?page=greenpeace/donations.php'));
        exit;
    }
}
