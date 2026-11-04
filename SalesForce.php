<?php

class SalesForce
{
    use Helpers;

    // A card and subscription with no charge yet. Salesforce must create the
    // Donation for it but no Payment: its Payment comes with PayPlus's first charge.
    const SIGNUP_ORIGIN = 'Web Recurring Signup';

    protected $params;

	protected function getSalesforceLoginUri() {
		$env = getSalesforceEnvType();

		if ($env === 'production') {
			return 'https://login.salesforce.com';
		}

		// default: testing
		return 'https://test.salesforce.com';
	}
    public function __construct() {
        $this->params = getSalesforceParams();
    }

	protected function auth() {

		$loginUri = $this->getSalesforceLoginUri(); // dynamic based on env type

		try {
			$authResponse = $this->httpRequest(
				$loginUri . "/services/oauth2/token",
				$this->params
			);
		} catch (Exception $e) {
			return false;
		}

		$authParams = (object) json_decode($authResponse);

		return isset($authParams->access_token) ? $authParams : false;
	}

    public function SendLead($data) {
        // Identified by donation id only: the Case payload holds the donor's
        // name, email, phone, ID number and card token.
        $this->log([
            'step' => 'SendLead',
            'message' => 'init',
            'Web_ID__c' => $data['Web_ID__c'] ?? null,
            'Origin' => $data['Origin'] ?? null,
        ]);

        $response = (object) [
            'status' => 'failed',
        ];

        $authParams = $this->auth();

        if($authParams === false) {
            $response->error = 'auth failed! (345)';

            $this->log([
                'step' => 'SendLead',
                'message' => $response->error,
            ]);

            return $response;
        }

        try {
            $salesforce_result = $this->httpRequest($authParams->instance_url . '/services/data/v54.0/sobjects/Case/', json_encode($data), [
                'Content-type: application/json',
                "Authorization: {$authParams->token_type} {$authParams->access_token}",
            ], true);
        } catch (Exception $e) {
            $response->error = $e->getMessage();

            $this->log([
                'step' => 'SendLead',
                'message' => $response->error,
            ]);

            return $response;
        }

        $decoded = json_decode((string) $salesforce_result);

        // Salesforce answers an error with a non-empty body too, so only a record id counts.
        if (is_object($decoded) && !empty($decoded->id)) {
            $response->status = 'succeeded';
            $response->content = $decoded;
        } elseif (!empty($data['Web_Event_Key__c']) && is_array($decoded) && ($decoded[0]->errorCode ?? '') === 'DUPLICATE_VALUE') {
            // An earlier attempt got through and only its answer was lost.
            $response->status = 'succeeded';
            $response->content = (object) [
                'id' => preg_match('/record with id: (\w+)/', (string) ($decoded[0]->message ?? ''), $match) ? $match[1] : 0,
            ];
        } else {
            $response->error = 'unexpected answer from Salesforce';
        }

        $this->log([
            'step' => 'SendLead',
            'message' => 'Raw SalesForce Response: ' . $salesforce_result,
        ]);

        if (strpos($salesforce_result, 'Error ID:') !== false) {
            wp_mail('ilona.b@cloudtech-apps.com', 'SalesForce returned error to wordpress-571309-4806948.cloudwaysapps.com', $salesforce_result);
        }

        return $response;
    }

    public function SendLeadByDonation($donationID, $donation = null, $send_unsuccessful_transactions = false) {
        global $wpdb;
        
        $SUCCESS_STATE = 1;
        $FINAL_FAILURE_STATE = 2;
        $MAX_RETRY_STATE = 4;
        
        // FIX: use prefixed table name
        $table_name = $wpdb->prefix . 'green_donations';
    
    
        $this->log([
            'step' => 'SendLeadByDonation',
            'message' => 'init, $donationID: ' . $donationID,
        ]);
    
        // Always the current row: a callback may have landed since the job selected it,
        // and what Salesforce is told depends on what is true now.
        $donation = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `$table_name` WHERE id = %d",
                $donationID
            )
        );
    
        if (!$donation) {
            $this->log([
                'step' => 'SendLeadByDonation',
                'message' => "Donation not found: {$donationID}",
            ]);
    
            return 'Donation not found';
        }
    
        // Already successful
        if (!empty($donation->sale_f_id)) {
            $this->log([
                'step' => 'SendLeadByDonation',
                'message' => 'transaction already transmitted to sf, ignoring. (352)',
            ]);
    
            $wpdb->update(
                $table_name,
                ['transmited_to_sf' => $SUCCESS_STATE],
                ['id' => $donationID]
            );
    
            return 'transaction already transmitted to sf, ignoring. (352)';
        }
    
        $state = (int) $donation->transmited_to_sf;
    
        // Permanently failed
        if ($state === $FINAL_FAILURE_STATE) {
            $this->log([
                'step' => 'SendLeadByDonation',
                'message' => 'Max attempts reached, skipping',
            ]);
    
            return 'Max attempts reached';
        }
    
        // Invalid state -> reset to new
        if ($state < 0 || $state > $MAX_RETRY_STATE) {
            $state = 0;
        }
    
        $PayPlusApi = new PayPlus();
    
        $return_only_successful_transactions = !$send_unsuccessful_transactions;
    
        try {
            $ipn_data = $PayPlusApi->getIpnData(
                $donationID,
                $return_only_successful_transactions
            );
        } catch (Exception $e) {
            debug_log('Error', 'ERROR: PayPlus lookup failed for donation ' . (int) $donationID . ': ' . $e->getMessage());
            $ipn_data = (object) [];
        }
    
        debug_log('Panic', "In SendLeadByDonation before ipn_data check");
        if (isset($ipn_data->status) && $ipn_data->status === 'class-failed') {
            debug_log('Panic', "In SendLeadByDonation ipn_data check return false");
            //return false; //$PayPlusApi->getIpnData already logged this error
            // instead, we will set the ipn_data to an empty object
            $ipn_data = (object) [];    
        }
        debug_log('Panic', "In SendLeadByDonation after ipn_data check");
    
        $data = $this->prepareData($ipn_data, $donation);

        // "approved" from this lookup is not proof of a payment: it has also come back
        // for donors with no charge at all. Without a signed callback it stays a lead,
        // and someone should look.
        if (
            $data['Origin'] === 'Web Lead'
            && empty($donation->registered_at)
            && isset($ipn_data->status)
            && $ipn_data->status === 'approved'
        ) {
            GreenDonationPayments::notifyAdmins("donation {$donationID}: PayPlus's lookup says approved but no signed callback confirmed a payment, so it went to Salesforce as a lead");
        }

        $SalesForceResponse = $this->SendLead($data);
    
        // -----------------------------
        // HANDLE FAILURE
        // -----------------------------
        if (
            !$SalesForceResponse ||
            !isset($SalesForceResponse->status) ||
            $SalesForceResponse->status !== 'succeeded'
        ) {
    
            if ($state === 0) {
                // First failure
                $nextState = $MAX_RETRY_STATE;
            } else {
                // Count down toward final failure state
                $nextState = max(
                    $FINAL_FAILURE_STATE,
                    $state - 1
                );
            }

            if ($nextState === $FINAL_FAILURE_STATE) {
                GreenDonationPayments::notifyAdmins("donation {$donationID}: every attempt to send it to Salesforce failed, so it is not recorded there");
            }
    
            $wpdb->update(
                $table_name,
                ['transmited_to_sf' => $nextState],
                ['id' => $donationID]
            );
    
            $this->log([
                'step' => 'SendLeadByDonation',
                'message' => "Salesforce failed, new state: {$nextState}",
                '$SalesForceResponse' => $SalesForceResponse,
            ]);
    
            return "Salesforce failed, new state: {$nextState}";
        }
    
        // -----------------------------
        // SUCCESS
        // -----------------------------
        $salesForceId = !empty($SalesForceResponse->content->id) ? $SalesForceResponse->content->id : 0;
    
        $this->log([
            'step' => 'SendLeadByDonation',
            'message' => 'Sent to Salesforce',
            '$salesForceId' => $salesForceId,
        ]);
    
        $wpdb->update(
            $table_name,
            [
                'sale_f_id' => $salesForceId,
                'transmited_to_sf' => $SUCCESS_STATE,
                'sf_origin' => $data['Origin'],
            ],
            ['id' => $donationID]
        );
    
        return $SalesForceResponse;
    }
    
    /**
     * The amount column holds shekels and agorot ("50.00") since payments are
     * verified. Whole amounts go to Salesforce exactly as before ("50"); only a
     * donation with agorot sends them ("50.5").
     */
    protected function amountForSalesforce($amount) {
        if ($amount === null || $amount === '' || !is_numeric($amount)) {
            return $amount;
        }

        return rtrim(rtrim(sprintf('%.2f', (float) $amount), '0'), '.');
    }

    /**
     * What a Case says this donation is. Only what the website itself confirmed
     * counts: PayPlus's lookup says "approved" for donors who were never charged.
     */
    protected function caseOrigin($donation): string {
        if (!empty($donation->payment_verified_at)) {
            return 'Web Donation';
        }
        if (!empty($donation->registered_at) && salesforceHandlesSignups()) {
            return self::SIGNUP_ORIGIN;
        }
        return 'Web Lead';
    }

    /**
     * The same key on every attempt, so Salesforce's unique field refuses a repeat
     * rather than opening a second Case, and a second Donation with it.
     */
    protected function caseKey($donation): string {
        return 'donation-' . (!empty($donation->page_request_uid)
            ? $donation->page_request_uid
            : $donation->id . '-' . strtotime($donation->date));
    }

    protected function prepareData($transaction, $donation): array
    {
        $this->log([
            'step' => 'prepareData',
            'donation id' => $donation->id ?? null,
            'transaction status' => $transaction->status ?? null,
        ]);

        $origin = $this->caseOrigin($donation);
        $paid = $origin === 'Web Donation';
        $signup = $origin === self::SIGNUP_ORIGIN;

        // Salesforce's lead flow puts whatever it is given on a placeholder Donation, and
        // a payment ID there would attach the donor's real charges to it. So a lead says
        // nothing about any card or subscription, even for a sign-up PayPlus has registered.
        $hasCard = $paid || $signup;

        // PayPlus's lookup is keyed by a number that has been reused: only a confirmed
        // payment's own page is taken from it.
        $lookupPage = (string) ($transaction->page_request_uid ?? '');
        if (!$paid || ($lookupPage !== '' && !empty($donation->page_request_uid) && $lookupPage !== $donation->page_request_uid)) {
            $transaction = (object) [];
        }

        // Salesforce finds a donor's charges by this id, so it must be the subscription's:
        // never a transaction's, which is only ever one charge.
        $subscription = !empty($donation->recurring_uid) ? $donation->recurring_uid : ($transaction->uid ?? '');
        $customer = !empty($donation->customer_uid) ? $donation->customer_uid : ($transaction->customer_uid ?? '');
        $page = !empty($donation->page_request_uid) ? $donation->page_request_uid : ($transaction->page_request_uid ?? '');
        // A sign-up's amount is what the donor chose. Nothing has been paid, so the
        // amount column, which holds what was paid, stays empty.
        $amount = $signup ? $donation->expected_amount_agorot / 100 : $donation->amount;

        $tourist = ( $donation->tourist == 1 );
        $debit = ( $donation->card_type == 3 );
		// adding igul letova - Ofer Or 13-1-2025 ( + PERSON_ID__c & Igul_letova__c in the return
        $igul_letova = ( $donation->igul_letova == 1 );
        $timestamp = strtotime($donation->date);
        $sfDate = $timestamp ? date("Y-m-d\TH:i:s\Z", $timestamp) : null;

        $case = [
            'Page_Requset_Uid__c' => $hasCard ? $page : '',
            'recurring_payment_uid__c' => $hasCard ? $subscription : '',
            "customer_uid__c" => $hasCard ? $customer : '',
            "Web_Date__c" => $sfDate,
            "Subject" => "תרומה חדשה באתר",
            "type" => "תרומה מהאתר",
            "Status" => "New",
            "Origin" => $origin,
            "Web_ID__c" => $donation->id,
            "Web_Page_ID__c" => $donation->page_id,
            "Web_payment_type__c" => $donation->payment_type,
            "Web_first_name__c" => $donation->first_name,
            "Web_last_name__c" => $donation->last_name,
            "Web_Form_Email__c" => $donation->email,
            "Web_Form_Phone__c" => $donation->phone,
            "Web_amount__c" => $this->amountForSalesforce($amount),
            "Web_Token__c" => $hasCard ? $donation->token : '',
            "Web_exp__c" => $hasCard ? $donation->exp : '',
            "Web_response__c" => $donation->response,
            "CC_Last_4_Digits__c" => $hasCard ? $donation->last_four : '',
            "CC_Card_Type__c" => $hasCard ? $donation->ccval : '',
            "CC_Tourist__c" => $hasCard && $tourist,
            "CC_Debit__c" => $debit,
            "PERSON_ID__c" => $donation->id_number,
            "Igul_letova__c" => $igul_letova,
            "Web_Shovar__c" => $donation->shovar,
            "Web_Receipt_Number__c" => $transaction->invoice_docu_number ?? '',
            "Web_Receipt__c" => $transaction->invoice_original_url ?? '',
            "utm_campaign__c" => $donation->utm_campaign,
            "utm_content__c" => $donation->utm_content,
            "utm_medium__c" => $donation->utm_medium,
            "utm_source__c" => $donation->utm_source,
            "utm_term__c" => $donation->utm_term,
        ];

        if (salesforceHandlesSignups()) {
            $case['Web_Event_Key__c'] = $this->caseKey($donation);
        }

        return $case;
    }
}