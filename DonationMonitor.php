<?php

class DonationMonitor
{
    private $wpdb;
    private string $table_name;
    protected SalesForce $SalesForce;

    public function __construct($SalesForce) {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'green_donations';
        $this->SalesForce = $SalesForce;

        debug_log('Panic', "DonationMonitor __construct loaded at " . date('Y-m-d H:i:s'));
    }

    public function scheduler() {
        debug_log('Panic', "scheduler() triggered at " . date('Y-m-d H:i:s'));

        if (!wp_next_scheduled('send_incomplete_leads_to_sf')) {
            wp_schedule_event(time(), 'every_ten_minute', 'send_incomplete_leads_to_sf');
            debug_log('Panic', "Cron event 'send_incomplete_leads_to_sf' scheduled at " . date('Y-m-d H:i:s'));
        } else {
            debug_log('Panic', "Cron event already scheduled");
        }
    }

    public function add_custom_schedules($schedules) {
        debug_log('Panic', "add_custom_schedules() executed at " . date('Y-m-d H:i:s'));

        $schedules['every_ten_minute'] = [
            'interval' => 600,
            'display'  => __('Every ten minute'),
        ];
        return $schedules;
    }

    public function SendIncompleteLeadsToSF() {
        debug_log('Panic', "CRON RUN: SendIncompleteLeadsToSF started at " . date('Y-m-d H:i:s'));

        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE transmited_to_sf = %d
             AND date < DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
            0
        );  // was date < DATE_SUB(NOW(), INTERVAL 2 HOUR)",

        $donations = $this->wpdb->get_results($query);

        debug_log('Panic', "Found " . count($donations) . " incomplete donations");

        if (count($donations) < 1) {
            debug_log('Panic', "No donations to process");
            return false;
        }

        foreach ($donations as $donation) {
            debug_log('Panic', "Processing donation ID: {$donation->id}, email: {$donation->email}");
            // $this->MaybeSendLeadToSF($donation); // skip checking if person already donated - at least till Elad & Dana will decided otherwise
            debug_log('Panic', "Sending donation {$donation->id} to Salesforce and Google Tag manager");
            $this->gp_push_gtm_purchase_event($donation); //push event to Goggle Tag manager
            $this->SalesForce->SendLeadByDonation($donation->id, $donation, true);
        }
    }

    protected function MaybeSendLeadToSF($donation) {
        global $wpdb;
        if ($this->isPersonAlreadyDonated($donation)) {
            debug_log('Panic', "Donation {$donation->id} skipped — person already donated (in MaybeSendLeadToSF)");
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$this->table_name}` SET `transmited_to_sf` = 2  WHERE `id` = %d",
                    $donation->id
                )
            );
            return false;
        }

        debug_log('Panic', "Sending donation {$donation->id} to Salesforce");
        $this->SalesForce->SendLeadByDonation($donation->id, $donation, true);
    }

    public function isPersonAlreadyDonated($donation): bool {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE email = %s
             AND id != %d
             AND exp IS NOT NULL",
            $donation->email,
            $donation->id
        );

        $results = $this->wpdb->get_results($query);

        $already = count($results) > 0;
        debug_log('Panic', "Check existing donor for {$donation->email}: " . ($already ? "YES" : "NO"));

        return $already;
    }

    public function destroy() {
        wp_clear_scheduled_hook('send_incomplete_leads_to_sf');
        debug_log('Panic', "Cron event cleared at " . date('Y-m-d H:i:s'));
    }

    function gp_push_gtm_purchase_event( $donation ) {

        if ( empty( $donation ) ) {
            debug_log('Error', "[ERROR] GTM purchase event skipped: donation record empty");
            return;
        }
    
        // Normalize object/array access
        $email         = is_object($donation) ? $donation->email         : $donation['email'];
        $amount        = is_object($donation) ? $donation->amount        : $donation['amount'];
        $payment_type  = is_object($donation) ? $donation->payment_type  : $donation['payment_type'];
        $transaction_id= is_object($donation) ? $donation->icount_id     : $donation['icount_id'];
        $exp           = is_object($donation) ? $donation->exp           : $donation['exp'];
    
        // ❗ REQUIRED CHECK: if exp is null or empty → do NOT fire GTM
        if ( empty($exp) ) {
            debug_log('Panic', "GTM purchase event skipped: exp is NULL for donation icount_id {$transaction_id}");
            return;
        }
        ?>
    
        <script>
        window.dataLayer = window.dataLayer || [];
    
        function hashEmailToGpUserId(email) {
          var encoder = new TextEncoder();
          var data = encoder.encode(email);
    
          return crypto.subtle.digest('SHA-256', data).then(function(hashBuffer) {
            var hashArray = Array.from(new Uint8Array(hashBuffer));
            var base64String = btoa(String.fromCharCode.apply(null, hashArray));
            return base64String.replace(/\//g, '');
          });
        }
    
        function pushPurchase(email, purchaseData) {
          if (!email) {
            console.log("pushPurchase aborted: missing email");
            return;
          }
    
          hashEmailToGpUserId(email).then(function(gp_user_id) {
    
            console.log("Hashed email (gp_user_id):", gp_user_id);
            console.log("Purchase data:", purchaseData);
    
            window.dataLayer.push({
              event: 'purchase',
              gp_user_id: gp_user_id,
              ecommerce: {
                currency: purchaseData.currency,
                value: purchaseData.value,
                transaction_id: purchaseData.transaction_id,
                items: [{
                  item_id: purchaseData.item_id,
                  item_name: purchaseData.item_name
                }]
              }
            });
    
            console.log("GTM purchase event pushed successfully");
          });
        }
    
        // ---- FIRE THE PURCHASE EVENT ----
        pushPurchase("<?php echo esc_js($email); ?>", {
          currency: "ILS",
          value: <?php echo (int) $amount; ?>,
          transaction_id: "<?php echo esc_js($transaction_id); ?>",
          item_id: "donation",
          item_name: "<?php echo esc_js(strtoupper($payment_type)); ?>"
        });
        </script>
    
        <?php
    }
}