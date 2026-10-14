<?php
/*
 * PayPlus payment callbacks for the donations table.
 *
 * A callback is PayPlus telling us a payment happened. Nothing it says reaches
 * the donation row until it has been checked:
 *
 *   1. it is signed with our PayPlus secret key: the `hash` header is the
 *      base64 HMAC-SHA256 of the raw body, the scheme PayPlus's own WooCommerce
 *      plugin verifies;
 *   2. it is for this donation, from this donation's payment page, in shekels,
 *      for the amount the donor chose;
 *   3. PayPlus, asked directly, confirms the payment was approved.
 *
 * Every callback is first parked in its own table holding only the fields we
 * use -- never the raw body, which also carries the donor's ID number -- and is
 * copied into the donation only once all three hold. If PayPlus cannot be
 * reached the callback stays pending and the ten-minute job asks again. If a
 * signed callback fails a check it stays parked, an admin is emailed, and the
 * donation is held back from Salesforce for a while so that a fix (and the
 * "Re-check" button on the donations page) can still apply it.
 */
class GreenDonationPayments
{
    const SCHEMA_VERSION = 2;
    const SCHEMA_OPTION = 'gp_il_donation_payments_schema';

    // Event states
    const PENDING = 'pending';       // waiting for PayPlus to confirm
    const APPLIED = 'applied';       // copied into the donation
    const REJECTED = 'rejected';     // failed a check: parked, admin emailed
    const RECORDED = 'recorded';     // genuine, but not the donation's payment (a refund, a later recurring charge)
    const DUPLICATE = 'duplicate';   // the payment already applied, sent again

    // A payment page expires after 30 minutes (see getIframe), so an unpaid
    // donation is sent to Salesforce as a lead only once it can no longer be paid.
    const UNPAID_LEAD_AFTER_MINUTES = 35;
    // How long the ten-minute job keeps asking PayPlus about a pending callback.
    const PENDING_GIVE_UP_HOURS = 24;
    // How long a donation with a signed-but-rejected callback waits for a fix
    // before it goes to Salesforce without payment details.
    const HOLD_HOURS = 72;

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
                PRIMARY KEY (`id`),
                KEY `donation_id` (`donation_id`),
                KEY `state` (`state`)
            ) DEFAULT CHARSET=utf8mb4");

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

        $t = $payload['transaction'];
        $card = isset($payload['data']['card_information']) && is_array($payload['data']['card_information'])
            ? $payload['data']['card_information']
            : array();
        $text = function ($value, int $max) {
            return is_scalar($value) ? mb_substr(trim((string) $value), 0, $max) : '';
        };

        $row = array(
            'donation_id'      => isset($t['more_info']) && is_scalar($t['more_info']) ? (int) $t['more_info'] : 0,
            'received_at'      => current_time('mysql', true),
            'state'            => self::PENDING,
            'signature_ok'     => $signatureOk ? 1 : 0,
            'transaction_type' => $text($payload['transaction_type'] ?? '', 30),
            'transaction_uid'  => $text($t['uid'] ?? '', 64),
            'page_request_uid' => $text($t['payment_page_request_uid'] ?? ($t['payment_request_uid'] ?? ''), 64),
            'status_code'      => $text($t['status_code'] ?? '', 10),
            'amount_agorot'    => self::toAgorot($t['amount'] ?? null),
            'currency'         => strtoupper($text($t['currency'] ?? '', 3)),
        );

        if ($signatureOk) {
            $row += array(
                'card_token'     => $text($card['token'] ?? '', 64),
                'card_expiry'    => $text(($card['expiry_month'] ?? '') . ($card['expiry_year'] ?? ''), 10),
                'card_holder'    => $text($card['card_holder_name'] ?? '', 100),
                'card_last_four' => $text($card['four_digits'] ?? '', 4),
                'card_issuer_id' => $text($card['issuer_id'] ?? '', 10),
                'card_foreign'   => $text($card['card_foreign'] ?? '', 5),
                'credit_terms'   => $text($t['credit_terms'] ?? '', 10),
                'voucher_number' => $text($t['voucher_number'] ?? '', 100),
            );
        }

        if (false === $wpdb->insert(self::eventsTable(), $row)) {
            return 0;
        }

        debug_log('Panic', "PayPlus callback parked as event {$wpdb->insert_id} for donation {$row['donation_id']} (signed: " . ($signatureOk ? 'yes' : 'no') . ")");
        return (int) $wpdb->insert_id;
    }


    // ------------------------------------------------------------------
    // Verifying and applying
    // ------------------------------------------------------------------

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

        $donation = $wpdb->get_row($wpdb->prepare("SELECT * FROM `" . self::donationsTable() . "` WHERE id = %d", (int) $event->donation_id));
        if (!$donation) {
            return $this->settle($eventId, self::REJECTED, "no donation {$event->donation_id}");
        }

        $type = strtolower(trim((string) $event->transaction_type));
        $isCharge = ($type === '' || $type === 'charge');
        $deferred = !empty($donation->first_charge_deferred);

        // Already paid: this is a repeat, a refund, or a later recurring charge.
        // Rows from before this change have no transaction_uid but do have a token.
        if (!empty($donation->transaction_uid) || (empty($donation->payment_verified_at) && !empty($donation->token))) {
            if (!empty($donation->transaction_uid) && $donation->transaction_uid === $event->transaction_uid) {
                return $this->settle($eventId, self::DUPLICATE, 'payment already applied');
            }
            return $this->settle($eventId, self::RECORDED, $isCharge ? 'later charge on an already-paid donation' : "{$event->transaction_type} on a paid donation");
        }

        if (!$isCharge && !$deferred) {
            return $this->settle($eventId, self::RECORDED, "{$event->transaction_type} before any payment; not applied");
        }

        $storedPage = (string) $donation->page_request_uid;
        if ($storedPage !== '' && $event->page_request_uid !== '' && $event->page_request_uid !== $storedPage) {
            return $this->settle($eventId, self::REJECTED, 'callback is for a different payment page');
        }
        if ($event->currency !== '' && $event->currency !== 'ILS') {
            return $this->settle($eventId, self::REJECTED, "currency {$event->currency}, expected ILS");
        }

        $expected = $donation->expected_amount_agorot;
        if ($expected !== null && $expected !== '') {
            $got = $event->amount_agorot === null ? null : (int) $event->amount_agorot;
            // A recurring sign-up on the 1st-3rd has no immediate first charge,
            // so its callback may carry 0 instead of the monthly amount.
            if ($got !== (int) $expected && !($deferred && $got === 0)) {
                return $this->settle($eventId, self::REJECTED, 'amount ' . self::formatAgorot($got) . ', expected ' . self::formatAgorot((int) $expected));
            }
        }

        // Ask PayPlus. By our own stored page when we have it, so the answer
        // is about this donation's payment page by construction.
        $page = $storedPage !== '' ? $storedPage : (string) $event->page_request_uid;
        $query = $page !== '' ? array('payment_request_uid' => $page) : array('transaction_uid' => (string) $event->transaction_uid);

        try {
            $answer = $this->api->apiRequest('/PaymentPages/ipn', $query);
        } catch (Throwable $e) {
            return $this->stillPending($event, 'PayPlus lookup failed: ' . $e->getMessage());
        }

        if (!is_object($answer) || !isset($answer->results->status) || $answer->results->status !== 'success' || !isset($answer->data) || !is_object($answer->data)) {
            $why = is_object($answer) && isset($answer->results->description) ? (string) $answer->results->description : 'no usable answer';
            return $this->stillPending($event, 'PayPlus lookup: ' . $why);
        }

        $d = $answer->data;
        $statusCode = isset($d->status_code) ? (string) $d->status_code : '';
        $status = isset($d->status) ? strtolower((string) $d->status) : '';

        if ($statusCode === '' && $status === '') {
            return $this->settle($eventId, self::REJECTED, 'PayPlus answered without a payment status');
        }
        if (($statusCode !== '' && $statusCode !== '000') || ($status !== '' && $status !== 'approved')) {
            return $this->settle($eventId, self::REJECTED, 'PayPlus says the payment is not approved (status ' . ($status !== '' ? $status : '-') . ', code ' . ($statusCode !== '' ? $statusCode : '-') . ')');
        }
        if (!empty($d->page_request_uid) && $page !== '' && (string) $d->page_request_uid !== $page) {
            return $this->settle($eventId, self::REJECTED, 'PayPlus has this payment under a different payment page');
        }
        if (!empty($d->transaction_uid) && $event->transaction_uid !== '' && (string) $d->transaction_uid !== $event->transaction_uid) {
            return $this->settle($eventId, self::REJECTED, 'PayPlus has a different transaction for this payment page');
        }
        if (isset($d->more_info) && is_scalar($d->more_info) && (string) $d->more_info !== '' && (int) $d->more_info !== (int) $donation->id) {
            return $this->settle($eventId, self::REJECTED, 'PayPlus has this payment under a different donation');
        }
        if (!$deferred && isset($d->amount) && $event->amount_agorot !== null && self::toAgorot($d->amount) !== null && self::toAgorot($d->amount) !== (int) $event->amount_agorot) {
            return $this->settle($eventId, self::REJECTED, 'PayPlus has amount ' . self::formatAgorot(self::toAgorot($d->amount)) . ', callback says ' . self::formatAgorot((int) $event->amount_agorot));
        }

        return $this->apply($event, $donation);
    }

    protected function apply($event, $donation): string {
        global $wpdb;

        $issuer = (string) $event->card_issuer_id;
        // prepare() would turn a missing amount into '' rather than NULL.
        $amountSql = $event->amount_agorot === null
            ? 'NULL'
            : $wpdb->prepare('%s', sprintf('%.2f', ((int) $event->amount_agorot) / 100));

        // Sized to the donation table's existing columns.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE `" . self::donationsTable() . "`
                SET amount = $amountSql, exp = %s, cc_holder = %s, token = %s, shovar = %s, card_type = %s,
                    last_four = %s, tourist = %d, ccval = %s, transaction_uid = %s, payment_verified_at = %s
              WHERE id = %d AND (transaction_uid IS NULL OR transaction_uid = '')",
            mb_substr((string) $event->card_expiry, 0, 30),
            mb_substr((string) $event->card_holder, 0, 40),
            (string) $event->card_token,
            mb_substr((string) $event->voucher_number, 0, 100),
            mb_substr((string) $event->credit_terms, 0, 100),
            (string) $event->card_last_four,
            in_array(strtolower((string) $event->card_foreign), array('1', 'true'), true) ? 1 : 0,
            mb_substr((string) (self::ISSUERS[$issuer] ?? $issuer), 0, 40),
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
        if (in_array($state, array(self::APPLIED, self::DUPLICATE, self::RECORDED), true)) {
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
     * Tell the site admins, at most once an hour so a burst of forged
     * requests cannot flood the inbox. The page lists every event anyway.
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
            "A PayPlus callback was not applied to its donation:\n\n{$message}\n\n"
            . "Callbacks that need attention are listed on the donations page, where a signed one can be re-checked:\n"
            . admin_url('admin.php?page=greenpeace/donations.php') . "\n\n"
            . "Further problems in the next hour are listed there but not emailed."
        );
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
     * Callbacks from the last 30 days that were not applied and may need a look.
     */
    public static function eventsNeedingAttention(): array {
        if (!self::ensureSchema()) {
            return array();
        }

        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, donation_id, received_at, state, reason, signature_ok, transaction_type, amount_agorot
               FROM `" . self::eventsTable() . "`
              WHERE state IN (%s, %s) AND received_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
              ORDER BY id DESC LIMIT 50",
            self::PENDING, self::REJECTED
        ));
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
            'type'    => $state === self::APPLIED ? 'success' : 'warning',
            'message' => "Event {$eventId}: {$state}",
        ), 30);

        wp_safe_redirect(admin_url('admin.php?page=greenpeace/donations.php'));
        exit;
    }
}
