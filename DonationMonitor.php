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
            debug_log('Panic', "Sending donation {$donation->id} to Salesforce");
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
}