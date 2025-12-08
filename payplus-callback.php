<?php
/*
 * Template Name: payplus-callback
 */

set_time_limit(0);

do_payplus_ipn_min();

function do_payplus_ipn_min() {
    $request_data = file_get_contents('php://input');
    $data = (object) json_decode($request_data);

    error_log("ofer debug 08-12-2025 data (1): \n" . print_r($data, true) . "\n");

    if(!is_object($data) || empty($data) || !isset($data->data, $data->transaction)) {
        return false;
    }

    // ofer debug 08-12-2025 - start
    error_log("ofer debug 08-12-2025 data (2): \n" . print_r($data, true) . "\n");
    // ofer debug 08-12-2025 - end
    
    $transaction = $data->transaction;
    $invoice = $data->invoice;
    $data = $data->data;

    if(!isset($transaction->uid)) {
        return false;
    }

    $id = intval(trim( $transaction->more_info ));
    $expiry = $data->card_information->expiry_month . $data->card_information->expiry_year;
    $ccHolder = $data->card_information->card_holder_name;
    $digits = $data->card_information->four_digits;
    $cc = $data->card_information->issuer_id;
    $token = $data->card_information->token;
    $tourist = $data->card_information->card_foreign;
    $cType = $transaction->credit_terms;
    $shovar = $transaction->voucher_number;
    $invoice_url = isset($invoice->original_url) ? $invoice->original_url : '';
    $invoice_id = isset($invoice->docu_number) ? $invoice->docu_number : '';

    $ccArr = array(
        "1" => "ישראכרד",
        "2" => "ויזה כ.א.ל",
        "3" => "דיינרס",
        "4" => "אמריקן אקספרס", //TODO
        "5" => "JCB",
        "6" => "לאומי כארד"
    );

    $ccVal = (isset($ccArr[$cc])) ? $ccArr[$cc] : $cc;

    //var_dump($ipn_response); exit;
    
/*
    global $wpdb;
    $transaction_exists = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT `sale_f_id` from `green_donations` WHERE id = %d",
            $id
        )
    );

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE green_donations SET exp = %s, cc_holder = %s, token = %s, shovar = %s, card_type = %s, last_four = %s, tourist = %s, ccval = %s, payplus_callback_response = %s  WHERE id = %d",
            $expiry, $ccHolder, $token, $shovar, $cType, $digits, $tourist, $ccVal, $id, $request_data
        )
    );

    if( empty($transaction_exists->sale_f_id) ) { //Transaction not transmitted to SalesForce yet
        salesForce($id, $invoice_url, $invoice_id, $data, $transaction);
        echo ' transaction sent to sf right now. ';

        httpRequest('https://91114809e55279db528139e72539b9b2.m.pipedream.net', [
            'step' => 'callback',
            'status' => 'transaction sent to sf right now.',
        ]);
    } else {
        echo ' transaction already transmitted to sf, ignoring. ';

        httpRequest('https://91114809e55279db528139e72539b9b2.m.pipedream.net', [
            'step' => 'callback',
            'status' => 'transaction already transmitted to sf, ignoring.',
        ]);
    }
	*/
}

/* -----------------------------------------------------------------------------------------------------------------
function httpRequest($url, $data, $headers = null, $raw = false, $auth = null, $method = 'POST') {
    try {
        $curl = curl_init($url);
        if (FALSE === $curl)
            throw new Exception('failed to initialize');

        if($raw != true)
            $data = http_build_query($data);

        if(null !== $headers) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }

        if(null != $auth) {
            curl_setopt($curl, CURLOPT_USERPWD, $auth['username'] . ":" . $auth['password']);
        }

        //curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($curl);
        if (FALSE === $response)
            throw new Exception(curl_error($curl), curl_errno($curl));

        curl_close($curl);
        return $response;
    } catch(Exception $e) {

        trigger_error(sprintf(
            'Curl failed with error #%d: %s',
            $e->getCode(), $e->getMessage()),
            E_USER_ERROR);

    }
}

httpRequest('https://91114809e55279db528139e72539b9b2.m.pipedream.net', ['callback' => 'init']);
httpRequest('https://91114809e55279db528139e72539b9b2.m.pipedream.net', [
    'post' => $_POST,
    'get'  => $_GET,
    'json' => file_get_contents('php://input'),
]);


function do_payplus_ipn() {
    $request_data = file_get_contents('php://input');
    $data = (object) json_decode($request_data);

    if(!is_object($data) || empty($data) || !isset($data->data, $data->transaction)) {
        return false;
    }

    $transaction = $data->transaction;
    $invoice = $data->invoice;
    $data = $data->data;

    if(!isset($transaction->uid)) {
        return false;
    }

    $id = intval(trim( $transaction->more_info ));
    $expiry = $data->card_information->expiry_month . $data->card_information->expiry_year;
    $ccHolder = $data->card_information->card_holder_name;
    $digits = $data->card_information->four_digits;
    $cc = $data->card_information->issuer_id;
    $token = $data->card_information->token;
    $tourist = $data->card_information->card_foreign;
    $cType = $transaction->credit_terms;
    $shovar = $transaction->voucher_number;
    $invoice_url = isset($invoice->original_url) ? $invoice->original_url : '';
    $invoice_id = isset($invoice->docu_number) ? $invoice->docu_number : '';

    $ccArr = array(
        "1" => "ישראכרד",
        "2" => "ויזה כ.א.ל",
        "3" => "דיינרס",
        "4" => "אמריקן אקספרס", //TODO
        "5" => "JCB",
        "6" => "לאומי כארד"
    );

    $ccVal = (isset($ccArr[$cc])) ? $ccArr[$cc] : $cc;

    //var_dump($ipn_response); exit;

    global $wpdb;
    $transaction_exists = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT `sale_f_id` from `green_donations` WHERE id = %d",
            $id
        )
    );

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE green_donations SET exp = %s, cc_holder = %s, token = %s, shovar = %s, card_type = %s, last_four = %s, tourist = %s, ccval = %s, payplus_callback_response = %s  WHERE id = %d",
            $expiry, $ccHolder, $token, $shovar, $cType, $digits, $tourist, $ccVal, $id, $request_data
        )
    );

    if( empty($transaction_exists->sale_f_id) ) { //Transaction not transmitted to SalesForce yet
        salesForce($id, $invoice_url, $invoice_id, $data, $transaction);
        echo ' transaction sent to sf right now. ';

        httpRequest('https://91114809e55279db528139e72539b9b2.m.pipedream.net', [
            'step' => 'callback',
            'status' => 'transaction sent to sf right now.',
        ]);
    } else {
        echo ' transaction already transmitted to sf, ignoring. ';

        httpRequest('https://91114809e55279db528139e72539b9b2.m.pipedream.net', [
            'step' => 'callback',
            'status' => 'transaction already transmitted to sf, ignoring.',
        ]);
    }
}

function salesForce($rowId, $link, $invoiceNum, $data, $transaction) {

    $params = getSalesforceParams();

    $curl = curl_init(SALESFORCE_LOGIN_URI. "/services/oauth2/token");
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $params);

    $json_response = curl_exec($curl);

    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ( $status != 200 ) {
        //die("Error: call to token URL $token_url failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl));
        errAdmin("Error: couldn't get auth-url. response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl) . " ID:" . $_SESSION["donation"]->id);
        //echo '<script> window.top.location = "'.get_permalink(33). "?username=" .  $_SESSION["donation"]->first_name  .'"; </script>';
        exit();
    }

    curl_close($curl);

    $response = json_decode($json_response, true);

    $access_token = $response['access_token'];
    $instance_url = $response['instance_url'];

    if (!isset($access_token) || $access_token == "") {
        //die("Error - access token missing from response!");
        errAdmin("Error - access token missing from response!" . $_SESSION["donation"]->id);
        // echo '<script> window.top.location = "'.get_permalink(33). "?username=" .  $_SESSION["donation"]->first_name  .'"; </script>';
        exit();
    }

    if (!isset($instance_url) || $instance_url == "") {
        //die("Error - instance URL missing from response!");
        errAdmin("Error - access token missing from response!" . $_SESSION["donation"]->id);
        //echo '<script> window.top.location = "'.get_permalink(33). "?username=" .  $_SESSION["donation"]->first_name  .'"; </script>';
        exit();
    }


    //TODO -> FROM DB
    //sleep(10);
    global $wpdb;

    $arr = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM `green_donations` WHERE id = %d",
            $rowId
        )
    );

    $url = "$instance_url/services/data/v54.0/sobjects/Case/";

    $tourist = ( $arr->{"tourist"} == 1 );
    $debit = ( $arr->{"card_type"} == 3 );

    $recurring_payment_uid = isset($transaction->recurring_charge_information->recurring_uid) ? $transaction->recurring_charge_information->recurring_uid : '';
    $customer_uid = isset($data->customer_uid) ? $data->customer_uid : '';

    $content = json_encode(array(
        'Page_Requset_Uid__c' => $transaction->payment_page_request_uid,
        'recurring_payment_uid__c' => $recurring_payment_uid,
        "customer_uid__c" => $customer_uid,
        "Web_Date__c" => date("Y-m-d\TH:i:s\Z", strtotime($transaction->date)),
        "Subject" => "תרומה חדשה באתר",
        "type" => "תרומה מהאתר",
        "Status" => "New",
        "Origin" => "Web Donation",
        "Web_ID__c" => $arr->id,
        "Web_Page_ID__c" => $arr->{"page_id"},
        "Web_payment_type__c" => $arr->{"payment_type"},
        "Web_first_name__c" => $arr->{"first_name"},
        "Web_last_name__c" => $arr->{"last_name"},
        "Web_Form_Email__c" => $arr->{"email"},
        "Web_Form_Phone__c" => $arr->{"phone"},
        "Web_amount__c" => $arr->{"amount"},
        "Web_Token__c" => $arr->{"token"},
        "Web_exp__c" => $arr->{"exp"},
        "Web_response__c" => $arr->{"response"},
        "CC_Last_4_Digits__c" => $arr->{"last_four"},
        "CC_Card_Type__c" => $arr->{"ccval"},
        "CC_Tourist__c" => $tourist,
        "CC_Debit__c" => $debit,
        "Web_Shovar__c" => $arr->{"shovar"},
        "Web_Receipt_Number__c" => $invoiceNum,
        "Web_Receipt__c" => $link,
        "utm_campaign__c" => $arr->{"utm_campaign"},
        "utm_content__c" => $arr->{"utm_content"},
        "utm_medium__c" => $arr->{"utm_medium"},
        "utm_source__c" => $arr->{"utm_source"},
        "utm_term__c" => $arr->{"utm_term"}
    ));

    httpRequest('https://91114809e55279db528139e72539b9b2.m.pipedream.net', ['content' => $content]);

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER,
        array("Authorization: Bearer $access_token",
            "Content-type: application/json"));
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $content);

    $json_response = curl_exec($curl);//$info = curl_getinfo($curl);var_dump($info);

    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ( $status != 201 ) {
        //die("Error: call to URL $url failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl));
        errAdmin("Error: call to URL $url failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl) . $_SESSION["donation"]->id);
        //echo '<script> window.top.location = "'.get_permalink(33). "?username=" .  $_SESSION["donation"]->first_name  .'"; </script>';
        exit();
    } else {
        echo 'success??';
    }

    curl_close($curl);

    //dataAdmin($json_response);

    $response = json_decode($json_response, true);

    $salesForceId = (isset($response["id"] ) && $response["id"])? $response["id"] : 0;
    echo " s_f_id: " . $salesForceId;

    if(!$response["success"]) errAdmin("DB record ".$arr->{"id"}." not set. Raw response: " . $json_response);

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE green_donations SET sale_f_id = %s, icount_id = %s  WHERE id = %d",
            $salesForceId, $invoiceNum, $arr->{"id"}
        )
    );



    //echo '<script> window.top.location = "'.get_permalink(33). "?username=" . $arr->first_name  .'"; </script>';
}

function errAdmin($err){

    $email = "gpmed-il-admin-group@greenpeace.org";
    //$email = explode(",",$email);
    $subject = "SalesForce Integration Error";
    $HTML = $err;

    wp_mail( $email, $subject, $HTML, array("Content-type: text/html" ) );
}

function dataAdmin($err){

    $email = array("gpmed-il-admin-group@greenpeace.org", "ekogoren@gmail.com");
    //$email = explode(",",$email);
    $subject = "SalesForce catch bug - object dump";
    $HTML = $err;

    wp_mail( $email, $subject, $HTML, array("Content-type: text/html" ) );
}

 -----------------------------------------------------------------------------------------------------------------
 */
