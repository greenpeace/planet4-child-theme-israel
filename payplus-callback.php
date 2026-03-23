<?php
/*
 * Template Name: payplus-callback
 */

set_time_limit(0);

require_once 'SalesForce.php';
// include 'DonationMonitor.php';

//    check and include // $donation_monitor = new DonationMonitor($SalesForce);

const SALESFORCE_LOGIN_URI = 'https://test.salesforce.com'; // 'https://login.salesforce.com';

debug_log('Panic', "payplus-callback.php  start .... 001 ");

/* $get_string = http_build_query($_GET);
debug_log('Panic', " payplus-callback.php get_string = {" . $get_string . "}"); // Log it to the error log
$post_string = http_build_query($_POST);
debug_log('Panic', " payplus-callback.php post_string = {" . $post_string . "}\n"); // Log it to the error log
debug_log('Panic', "payplus-callback.php  start .... 002 \n");  
*/

do_payplus_ipn_min();
debug_log('Panic', "payplus-callback.php  end .... 001 ");


function do_payplus_ipn_min() {
    $request_data = file_get_contents('php://input');
    $data = (object) json_decode($request_data);

	$logMessage = "payplus-callback.php in do_payplus_ipn_min() ofer debug 14-12-2025 data (1)";
    debug_log('Panic', "payplus-callback.php in do_payplus_ipn_min() ofer debug 14-12-2025 data (1): " . print_r($data, true) );

    if(!is_object($data) || empty($data) || !isset($data->data, $data->transaction)) {
        return false;
    }
    debug_log('Panic', "payplus-callback.php in do_payplus_ipn_min() ofer debug 14-12-2025 data (2)");

    $transaction = $data->transaction;
    $invoice = $data->invoice;
    $data = $data->data;

    debug_log('Panic', "payplus-callback.php in do_payplus_ipn_min() ofer debug 14-12-2025 data (3)");

    debug_log('Panic', "payplus-callback.php in do_payplus_ipn_min() ofer debug 14-12-2025 transaction (4): " . print_r($transaction, true) );
    debug_log('Panic', "payplus-callback.php in do_payplus_ipn_min() ofer debug 14-12-2025 invoice (4): " . print_r($invoice, true) );
    debug_log('Panic', "payplus-callback.php in do_payplus_ipn_min() ofer debug 14-12-2025 data (4): " . print_r($data, true) );


    if(!isset($transaction->uid)) {
        return false;
    }
	
    debug_log('Panic', "payplus-callback.php in do_payplus_ipn_min() ofer debug 14-12-2025 (5) ");

    $id = intval(trim( $transaction->more_info ));
    $amount = $transaction->amount;
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

    $table_name = $wpdb->prefix . 'green_donations';
    $transaction_exists = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT `sale_f_id` from $table_name WHERE id = %d",
            $id
        )
    );

    debug_log('Panic', " payplus-callback.php befor update DB: id = " . $id . " *****"); // Log it to the error log
    debug_log('Panic', " payplus-callback.php befor update DB: amount = " . $amount . " *****"); // Log it to the error log

    $test = $wpdb->query(
        $wpdb->prepare(
            "UPDATE $table_name SET amount = %d, exp = %s, cc_holder = %s, response = %s, token = %s, shovar = %s, card_type = %s, last_four = %s, tourist = %s, ccval = %s WHERE id = %d",
            $amount, $expiry, $ccHolder, $response, $token, $shovar, $cType, $digits, $tourist, $ccVal, $id
        )
    );

    // skip SalesForce update - Donation monitor will do that if needed - Ofer Or 16-Mar-2026
    /*    // Uptade SalesForce 
        if( empty($transaction_exists->sale_f_id) ) { //Transaction not transmitted to SalesForce yet
            debug_log('Panic', "ofer debug 13-12-2025 : transaction sent to sf right now. ");
            salesForce($id, $invoice_url, $invoice_id, $data, $transaction);
            echo ' transaction sent to sf right now. ';
        } else {
            debug_log('Panic', "ofer debug 13-12-2025 : transaction already transmitted to sf, ignoring.  ");
            echo ' transaction already transmitted to sf, ignoring. ';
        }
    */
	
}


function salesForce($rowId, $link, $invoiceNum, $data, $transaction) {

    $params = getSalesforceParams_new();
    debug_log('Panic', " payplus-callback.php salesForce 1 "); // Log it to the error log
    // Ensure it's a string
    if (!is_string($params)) {
        $type = gettype($params);
        debug_log('Error', "SF ERROR: Expected string, got: $type");
        return;
    }
    // Split into key=value pairs
    $pairs = explode("&", $params);
    foreach ($pairs as $pair) {
        list($key, $value) = explode("=", $pair, 2);
        $value = urldecode($value); // decode for readability
        $first3 = substr($value, 0, 3);
        $last3  = substr($value, -3);
        debug_log('Panic', "SF Param Preview - $key: {$first3}...{$last3}");
    }
    debug_log('Panic', " payplus-callback.php salesForce 2 "); // Log it to the error log
    $token_url = SALESFORCE_LOGIN_URI . "/services/oauth2/token";
    $curl = curl_init(SALESFORCE_LOGIN_URI . "/services/oauth2/token");
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $params);

    $json_response = curl_exec($curl);

    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ( $status != 200 ) {

        // 1. Build the detailed message
        $log_message = sprintf(
            "API Error: Token request failed.\nURL: %s\nStatus: %d\nResponse: %s\ncURL Error (%d): %s",
            $token_url,
            $status,
            $json_response,
            curl_errno($curl),
            curl_error($curl)
        );

        // 2. Send to the system logger
        debug_log('Error', 'ERROR: ' . $log_message);

        //die("Error: call to token URL $token_url failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl));
        //errAdmin("Error: couldn't get auth-url. response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl) . " ID:" . $_SESSION["donation"]->id);
        //echo '<script> window.top.location = "'.get_permalink(33). "?username=" .  $_SESSION["donation"]->first_name  .'"; </script>';
        exit();
    }
    debug_log('Panic', " payplus-callback.php salesForce  2 *****"); // Log it to the error log

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
    debug_log('Panic', " payplus-callback.php salesForce 3 *****"); // Log it to the error log

    if (!isset($instance_url) || $instance_url == "") {
        //die("Error - instance URL missing from response!");
        errAdmin("Error - access token missing from response!" . $_SESSION["donation"]->id);
        //echo '<script> window.top.location = "'.get_permalink(33). "?username=" .  $_SESSION["donation"]->first_name  .'"; </script>';
        exit();
    }


    //TODO -> FROM DB
    //sleep(10);
    global $wpdb;
    $table_name = $wpdb->prefix . 'green_donations';

    $arr = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $rowId
        )
    );
    debug_log('Panic', " payplus-callback.php salesForce 4 *****  id= " . $rowId ); // Log it to the error log

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

	send_donation_mail($content);

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

    debug_log('Panic', " payplus-callback.php salesForce 5 *****"); // Log it to the error log

    curl_close($curl);

    //dataAdmin($json_response);

    $response = json_decode($json_response, true);

    $salesForceId = (isset($response["id"] ) && $response["id"])? $response["id"] : 0;
    echo " s_f_id: " . $salesForceId;

    if(!$response["success"]) errAdmin("DB record ".$arr->{"id"}." not set. Raw response: " . $json_response);

    debug_log('Panic', " payplus-callback.php salesForce 10 *****  salesForceId= " . $salesForceId ); // Log it to the error log

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE $table_name SET sale_f_id = %s, icount_id = %s  WHERE id = %d",
            $salesForceId, $invoiceNum, $arr->{"id"}
        )
    );



    //echo '<script> window.top.location = "'.get_permalink(33). "?username=" . $arr->first_name  .'"; </script>';
}

function errAdmin($err){

    $email = "oferor@greenpeace.org";
    //$email = explode(",",$email);
    $subject = "SalesForce Integration Error";
    $HTML = $err;

    wp_mail( $email, $subject, $HTML, array("Content-type: text/html" ) );
}

function dataAdmin($err){

    $email = array("oferor@greenpeace.org");
    //$email = explode(",",$email);
    $subject = "SalesForce catch bug - object dump";
    $HTML = $err;

    wp_mail( $email, $subject, $HTML, array("Content-type: text/html" ) );
}

function sendDebugMail($err){

    $email = "oferor@greenpeace.org";
    //$email = explode(",",$email);
    $subject = "Debug: " . $err;
    $HTML = $err;

    wp_mail( $email, $subject, $HTML, array("Content-type: text/html" ) );
}
/**
 * Send donation details via email
 *
 * @param string $content JSON-encoded donation data
 * @param string|array $to Recipient email(s)
 * @return bool Whether the mail was successfully accepted for delivery
 */
function send_donation_mail($content) {

	$to = "oferor@greenpeace.org";

    // Decode JSON back to array
    $data = json_decode($content, true);

    // Subject line
    $subject = isset($data['Subject']) ? $data['Subject'] : 'New Donation Received';

    // Build HTML table
    $message  = "<h2>Donation Details</h2>";
    $message .= "<table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse;'>";
    foreach ($data as $key => $value) {
        $message .= "<tr>";
        $message .= "<td style='background:#f2f2f2;font-weight:bold;'>".esc_html($key)."</td>";
        $message .= "<td>".esc_html($value)."</td>";
        $message .= "</tr>";
    }
    $message .= "</table>";

    // Set headers to send HTML email
    $headers = array('Content-Type: text/html; charset=UTF-8');

    // Send email
    return wp_mail($to, $subject, $message, $headers);
}