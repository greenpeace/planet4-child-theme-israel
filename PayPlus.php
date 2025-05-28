<?php

class PayPlus
{
    use Helpers;

    public function getIpnData($donationID, $return_only_successful_transactions = true) {
        $ipn_response = $this->apiRequest('/PaymentPages/ipn', [
            'more_info' => $donationID,
        ]);

        if( !isset($ipn_response->results, $ipn_response->results->status) || $ipn_response->results->status != 'success') {
            $this->log([
                'step' => 'getIpnData',
                'message' => 'auth failed! (347)',
                '$ipn_response->results' => $ipn_response->results,
            ]);

            if(!$return_only_successful_transactions) {
                return (object) [];
            }

            return (object) [
                'status' => 'class-failed',
                'error' => 'auth failed! (347)',
            ];
        }

        if($return_only_successful_transactions && $ipn_response->data->status != 'approved') {
            $this->log([
                'step' => 'getIpnData',
                'message' => 'skipping sf, unsuccessful transaction',
            ]);

            return (object) [
                'status' => 'class-failed',
                'error' => 'skipping sf, unsuccessful transaction',
            ];
        }

        //Don't be mad on me, but PayPlus was not smart enough to provide all data in one ipn call, so we need to do that again differently
        if( !isset($ipn_response->data->page_request_uid) ) {
            $this->log([
                'step' => 'getIpnData',
                'message' => 'page_request_uid is missing! (3499)',
            ]);

            return (object) [
                'status' => 'class-failed',
                'error' => 'page_request_uid is missing! (3499)',
            ];
        }

        $second_ipn_response = $this->apiRequest('/PaymentPages/ipn', [
            'payment_request_uid' => $ipn_response->data->page_request_uid,
        ]);

        return $this->prepareIpnData($ipn_response, $second_ipn_response);
    }

    protected function prepareIpnData($data, $data2) {
        $this->log([
            'step' => 'prepareIpnData',
            'params' => [
                '$data' => $data,
                '$data2' => $data2,
            ],
        ]);

        $transaction = $data->data;
        $transaction2 = $data2->data; //Again, stupid PayPlus (see above comment^)

        if(empty($transaction->uid) && !empty($transaction2->uid)) {
            $transaction->uid = $transaction2->uid;
        }

        return $transaction;
    }

    public function apiRequest($route, $data, $method = 'POST') {
        $auth = json_encode([
            'api_key' => '9c66e8ca-d62d-42d9-a2ec-859dd199381a', //test terminal
            'secret_key' => '64a113f0-34d1-4d16-bfd9-600a65a2f490', //test terminal
        ]);

        $response = $this->httpRequest('https://restapidev.payplus.co.il/api/v1.0' . $route, json_encode($data), [
            "Content-Type: application/json",
            "Authorization: " . $auth,
        ], true, null, $method);
        return json_decode($response);
    }
}