<?php
/*
 * Template Name: payplus-callback
 */

set_time_limit(0);

require_once 'SalesForce.php';

// const SALESFORCE_LOGIN_URI = 'https://test.salesforce.com'; // 'https://login.salesforce.com';

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
    // $invoice = $data->invoice; // not used
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
    $invoice_url = ''; //isset($invoice->original_url) ? $invoice->original_url : '';
    $invoice_id = ''; // isset($invoice->docu_number) ? $invoice->docu_number : '';
    $response = 0; // default to 0 - not really used

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
