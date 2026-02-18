<?php

class SalesForce
{
    use Helpers;
    protected $params;
    const SALESFORCE_LOGIN_URI = 'https://test.salesforce.com'; // 'https://login.salesforce.com';

    public function __construct()
        $this->params = getSalesforceParams_new();
    }

    protected function auth() {
        $authResponse = $this->httpRequest(self::SALESFORCE_LOGIN_URI . "/services/oauth2/token", $this->params);
        $authParams = (object) json_decode($authResponse);

        return isset($authParams->access_token) ? $authParams : false;
    }

    public function SendLead($data) {
        $this->log([
            'step' => 'SendLead',
            'message' => 'init',
            'data' => $data,
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

        $salesforce_result = $this->httpRequest($authParams->instance_url . '/services/data/v54.0/sobjects/Case/', json_encode($data), [
            'Content-type: application/json',
            "Authorization: {$authParams->token_type} {$authParams->access_token}",
        ], true);

        if($salesforce_result) {
            $response->status = 'succeeded';
            $response->content = json_decode($salesforce_result);
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

        $this->log([
            'step' => 'SendLeadByDonation',
            'message' => 'init, $donationID: ' . $donationID,
        ]);

        if($donation == null) {
            $donation = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `green_donations` WHERE id = %d",
                    $donationID
                )
            );
        }

        if( !empty($donation->sale_f_id) ) {
            $this->log([
                'step' => 'SendLeadByDonation',
                'message' => 'transaction already transmitted to sf, ignoring. (352)',
            ]);

            return 'transaction already transmitted to sf, ignoring. (352)';
        }

        $PayPlusApi = new PayPlus();

        $return_only_successful_transactions = !$send_unsuccessful_transactions;

        $ipn_data = $PayPlusApi->getIpnData($donationID, $return_only_successful_transactions);

        if(isset($ipn_data->status) && $ipn_data->status === 'class-failed') {
            return false; //$PayPlusApi->getIpnData already logged this error
        }

        $data = $this->prepareData($ipn_data, $donation);

        $SalesForceResponse = $this->SendLead($data);

        if($SalesForceResponse->status !== 'succeeded') {
            $this->log([
                'step' => 'SendLeadByDonation',
                'message' => 'something went wrong. (354)',
                '$SalesForceResponse' => $SalesForceResponse,
            ]);

            return 'something went wrong. (354)';
        }

        $salesForceId = (isset($SalesForceResponse->content->id ) && $SalesForceResponse->content->id)? $SalesForceResponse->content->id : 0;

        if($salesForceId == 0) {
            $this->log([
                'step' => 'SendLeadByDonation',
                'message' => 'something went wrong. (6875)',
                '$SalesForceResponse' => $SalesForceResponse,
            ]);

            //return 'something went wrong. (6875)';
        }

        $this->log([
            'step' => 'SendLeadByDonation',
            'message' => 'Sent to Salesforce',
            '$salesForceId' => $salesForceId,
        ]);

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `green_donations` SET `sale_f_id` = %s, `icount_id` = %s, `transmited_to_sf` = 1  WHERE `id` = %d",
                $salesForceId, $data->invoice_docu_number ?? null, $donation->id
            )
        );

        return $SalesForceResponse;
    }

    protected function prepareData($transaction, $donation): array
    {
        $this->log([
            'step' => 'prepareData',
            'params' => [
                '$transaction' => $transaction,
                '$donation' => $donation,
            ],
        ]);

        $tourist = ( $donation->tourist == 1 );
        $debit = ( $donation->card_type == 3 );
		// adding igul letova - Ofer Or 13-1-2025 ( + PERSON_ID__c & Igul_letova__c in the return
        $igul_letova = ( $donation->igul_letova == 1 );

        return [
            'Page_Requset_Uid__c' => $transaction->page_request_uid ?? '',
            'recurring_payment_uid__c' => $transaction->uid ?? '',
            "customer_uid__c" => $transaction->customer_uid ?? '',
            "Web_Date__c" => date("Y-m-d\TH:i:s\Z", strtotime($transaction->date ?? '')),
            "Subject" => "תרומה חדשה באתר",
            "type" => "תרומה מהאתר",
            "Status" => "New",
            "Origin" => (isset($transaction->status) && $transaction->status === 'approved') ? "Web Donation" : 'Web Lead',
            "Web_ID__c" => $donation->id,
            "Web_Page_ID__c" => $donation->page_id,
            "Web_payment_type__c" => $donation->payment_type,
            "Web_first_name__c" => $donation->first_name,
            "Web_last_name__c" => $donation->last_name,
            "Web_Form_Email__c" => $donation->email,
            "Web_Form_Phone__c" => $donation->phone,
            "Web_amount__c" => $donation->amount,
            "Web_Token__c" => $donation->token,
            "Web_exp__c" => $donation->exp,
            "Web_response__c" => $donation->response,
            "CC_Last_4_Digits__c" => $donation->last_four,
            "CC_Card_Type__c" => $donation->ccval,
            "CC_Tourist__c" => $tourist,
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
    }
}