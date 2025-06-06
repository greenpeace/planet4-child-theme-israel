<?php
/*
 * Template Name: receive defrayal
 */

session_start();

get_header();
// ofer: 6.6.2025 remove SF code     global $SalesForce;
ob_start();                // Start output buffering
var_dump($_POST);          // Dump your variable
$output = ob_get_clean();  // Get the output and clean the buffer
error_log($output);        // Log it to the error log
//var_dump($_POST);exit;

echo "<p style='text-align: center; margin-top: 20px; width:100%' class='gpf_wait'>...נא להמתין</p>";
error_log("recieve-payment.php  start .... ");


if(isset($_GET["initsalesforce"])){
    error_log("recieve-payment.php  part 1");

// ofer: 6.6.2025 remove SF code    salesForce();

} elseif(isset($_GET["96"])) { //if page id and returned data then:   ////was $_GET["p96"]
    error_log("recieve-payment.php  part 2");

    //$pfsAuthCode = '5c83022bde1b4d34b42e5fa6700c369a';
    $pfsAuthCode = '8bf832811e7f40b78b64287f3e522b38';
    //$pfsAuthUrl = 'https://ws.payplus.co.il/Sites/greenpeacecrm/pfsAuth.aspx';
    $pfsAuthUrl = 'https://ws.payplus.co.il/pp/cc/pfsAuth.aspx';
    //$pfsPaymentUrl = 'https://ws.payplus.co.il/Sites/greenpeacecrm/payment.aspx';
    $pfsPaymentUrl = 'https://ws.payplus.co.il/pp/cc/payment.aspx';

    $pfs_voucher_id = $_GET["p96"];
    $order_number = $_GET["more_info"]; //was p120

    $pfs_post_variables = Array(
        'voucherId' 	=> 	$pfs_voucher_id,
        'uniqNum' 	=> 	$order_number,
        //'pfsAuthCode'	=> 	'fff01f25f8874afabbdc195e1f348f26'
        'pfsAuthCode'	=> 	'8bf832811e7f40b78b64287f3e522b38'
    );

    $pfs_post_str = '';
    foreach ($pfs_post_variables as $name => $value) {
        if( $pfs_post_str != '') $pfs_post_str .= '&';
        $pfs_post_str .= $name . '=' . $value ;
    }

    // curl open connection
    $pfs_ch = curl_init();
    // curl settings
    curl_setopt($pfs_ch, CURLOPT_URL, "https://ws.payplus.co.il/pp/cc/ipn.aspx");
    curl_setopt($pfs_ch, CURLOPT_POST, 3);
    curl_setopt($pfs_ch, CURLOPT_POSTFIELDS, $pfs_post_str);
    curl_setopt($pfs_ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($pfs_ch, CURLOPT_SSL_VERIFYPEER, false);
    // curl execute post
    $pfs_curl_response = curl_exec($pfs_ch);
    // curl close connection
    curl_close($pfs_ch);


    if($pfs_curl_response !='') {
        $_ipn_response = substr($pfs_curl_response, 0 ,1);


        if($_ipn_response == 'Y'){   // APPROVED !

            $id = intval(trim($_GET["more_info"])); //was p120
            $title =
            $amount = intval($_GET["p36"]);
            $amount = $amount / 100;
            $expiry = $_GET["p30"];
            $ccHolder = $_GET["p201"];
            $response =  $_GET["p1"];
            $token = $_GET["key"];

            $digits = $_GET["p5"];
            $cc = $_GET["p24"];
            $cType = $_GET["p63"];
            $shovar = $_GET["p96"];



            $ccArr = array(
                "1" => "ישראכרד",
                "2" => "ויזה כ.א.ל",
                "3" => "דיינרס",
                "4" => "אמריקן אקספרס", //TODO
                "5" => "JCB",
                "6" => "לאומי כארד"
            );

            $ccVal = (isset($ccArr[$cc]))? $ccArr[$cc] : $cc;
            $tourist = $_GET["p119"];


            global $wpdb;
            $table_name = $wpdb->prefix . 'green_donations';
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table_name SET amount = %d, exp = %s, cc_holder = %s, response = %s, token = %s, shovar = %s, card_type = %s, last_four = %s, tourist = %s, ccval = %s  WHERE id = %d",
                    $amount, $expiry,$ccHolder,$response, $token, $shovar, $cType, $digits, $tourist, $ccVal, $id
                )
            );



            //Send email to admin

            $dbRow = $wpdb->get_row( "SELECT * FROM green_donations WHERE id =" . $id );

			var_dump($id);
            $email = get_field("admin_email", "option");
            $email = explode(",",$email);
            $subject = "תרומה חדשה באתר";
            $HTML = "";
            foreach($dbRow as $fieldName => $field){
                $HTML .= $fieldName . ": " . $field. "<br>";
                if($fieldName === "page_id"){
                    $id = intval($field);
                    $title = get_the_title($id);
                    $HTML .= "page_title" . ": " . $title. "<br>";
                }
            }
            $HTML .= "<br> More info:". "<br>";
            $HTML .= "-------------------". "<br>";
            $HTML .= "4 digits : " . $digits. "<br>";
            $HTML .= "cc type : " . $ccVal. "<br>";
            $HTML .= "card type : " . $cType. "<br>";

            wp_mail( $email, $subject, $HTML, array("Content-type: text/html" ) );

            //var_dump($_GET);

            $dbRow->{"last_four"} = $_GET["p5"];
            $dbRow->{"tourist"} = $_GET["p119"];
            $dbRow->{"debit"} = $_GET["p63"];
            $dbRow->{"shovar"} = $_GET["p96"];
            $dbRow->{"ccVal"} = $ccVal;


            $_SESSION["donation"] = $dbRow;

			$came_from = ( $id == 3680 ) ? get_permalink(591) :  get_permalink(66268);
            //echo '<script> window.top.location = "'.get_permalink(366268). "?username=" . $dbRow->first_name  .'"; </script>';
            echo '<script> window.top.location = "'. $came_from . "?username=" . $dbRow->first_name  .'"; </script>';
            //salesForceInit();

        } else{
            echo "<p style='text-align: center; margin-top: 20px; width:100%' class='gpf_wait'>משהו השתבש</p>";
        }
    } else {
        echo "<p style='text-align: center; margin-top: 20px; width:100%' class='gpf_wait'>בעיית חיבור למערכת הסליקה</p>";
    }

    error_log("recieve-payment.php  part 2.5");

    //Insert data

} elseif(isset($_POST["status"]) && $_POST["status"] === 'approved') {
    error_log("recieve-payment.php  part 3");

    $api = new PayPlus();

    if(isset($_POST['transaction_uid'])) {
        error_log("recieve-payment.php  part 4");

        $ipn_response = $api->apiRequest('/PaymentPages/ipn', [
            'transaction_uid' => $_POST['transaction_uid'],
        ]);

        $id = intval(trim( $ipn_response->data->more_info ));
        $expiry = $ipn_response->data->expiry_month . $ipn_response->data->expiry_year;
        $ccHolder = $ipn_response->data->card_holder_name;
        $digits = $ipn_response->data->four_digits;
        $cc = $ipn_response->data->issuer_id;
        $shovar = $ipn_response->data->voucher_num;
        $tourist = $ipn_response->data->card_foreign;
        $token = $ipn_response->data->token_uid;
        $cType = $ipn_response->data->credit_terms;
    } elseif(isset($_POST['page_request_uid'])) {
        error_log("recieve-payment.php  part 5");

        $ipn_response = $api->apiRequest('/PaymentPages/ipn', [
            'payment_request_uid' => $_POST['page_request_uid'],
            'related_transactions' => true,
        ]);

        $id = intval(trim( $ipn_response->data->extra_info ));
        $token = $ipn_response->data->card_token;

        $expiry = '';
        $ccHolder = '';
        $digits = '';
        $cc = '';
        $shovar = '';
        $tourist = '';
        $cType = '';
    } else {
        error_log("recieve-payment.php  part 6");

        echo 'משהו השתבש (err 11)';
        exit;
    }

    if(!isset($ipn_response->results) || !isset($ipn_response->data)) { //Incorrect data received from ipn
        error_log("recieve-payment.php  part 7");

        echo 'משהו השתבש (err 22)';exit;
    }

    if($ipn_response->results->status !== 'success') { //Transaction Failed
        error_log("recieve-payment.php  part 8");

        echo 'משהו השתבש (err 45)';
        var_dump($ipn_response);
        echo $ipn_response->results->description;
        exit;
    }
    error_log("recieve-payment.php  part 9");

    $response = $ipn_response->results->status;
    $title = $amount = $ipn_response->data->amount;

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
    error_log("recieve-payment.php  part 10");

    global $wpdb;
    $table_name = $wpdb->prefix . 'green_donations';

    $test = $wpdb->query(
        $wpdb->prepare(
            "UPDATE $table_name SET amount = %d, exp = %s, cc_holder = %s, response = %s, token = %s, shovar = %s, card_type = %s, last_four = %s, tourist = %s, ccval = %s WHERE id = %d",
            $amount, $expiry, $ccHolder, $response, $token, $shovar, $cType, $digits, $tourist, $ccVal, $id
        )
    );



    //Send email to admin and lead to salesforce

    $dbRow = $wpdb->get_row( "SELECT * FROM $table_name WHERE id =" . $id );

    global $SalesForce;
    $sf_response = $SalesForce->SendLeadByDonation($id, $dbRow);

    var_dump($id);
    $email = get_field("admin_email", "option");
    $email = explode(",",$email);
    $subject = "תרומה חדשה באתר";
    $HTML = "";
    foreach($dbRow as $fieldName => $field){
        $HTML .= $fieldName . ": " . $field. "<br>";
        if($fieldName === "page_id"){
            $id = intval($field);
            $title = get_the_title($id);
            $HTML .= "page_title" . ": " . $title. "<br>";
        }
    }
    $HTML .= "<br> More info:". "<br>";
    $HTML .= "-------------------". "<br>";
    $HTML .= "4 digits : " . $digits. "<br>";
    $HTML .= "cc type : " . $ccVal. "<br>";
    $HTML .= "card type : " . $cType. "<br>";

    wp_mail( $email, $subject, $HTML, array("Content-type: text/html" ) );

    //var_dump($_POST);

    $dbRow->{"last_four"} = $digits;
    $dbRow->{"tourist"} = $tourist;
    $dbRow->{"debit"} = $cType;
    $dbRow->{"shovar"} = $shovar;
    $dbRow->{"ccVal"} = $ccVal;


    $_SESSION["donation"] = $dbRow;

    $came_from = ( $id == 3680 ) ? get_permalink(591) :  get_permalink(33);
    //echo '<script> window.top.location = "'.get_permalink(33). "?username=" . $dbRow->first_name  .'"; </script>';
    echo '<script> window.top.location = "'. $came_from . "?username=" . $dbRow->first_name  .'"; </script>';
}

//TODO
function salesForceInit(){

    echo '<script> window.top.location = "'.REDIRECT_URI. '?initsalesforce=true"; </script>';

}


function salesForce(){

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
        echo '<script> window.top.location = "'.get_permalink(33). "?username=" .  $_SESSION["donation"]->first_name  .'"; </script>';
        exit();
    }

    curl_close($curl);

    $response = json_decode($json_response, true);

    $access_token = $response['access_token'];
    $instance_url = $response['instance_url'];

    if (!isset($access_token) || $access_token == "") {
        //die("Error - access token missing from response!");
        errAdmin("Error - access token missing from response!" . $_SESSION["donation"]->id);
        echo '<script> window.top.location = "'.get_permalink(33). "?username=" .  $_SESSION["donation"]->first_name  .'"; </script>';
        exit();
    }

    if (!isset($instance_url) || $instance_url == "") {
        //die("Error - instance URL missing from response!");
        errAdmin("Error - access token missing from response!" . $_SESSION["donation"]->id);
        echo '<script> window.top.location = "'.get_permalink(33). "?username=" .  $_SESSION["donation"]->first_name  .'"; </script>';
        exit();
    }


    $arr = $_SESSION["donation"];
    unset($_SESSION["donation"]);

    $url = "$instance_url/services/data/v54.0/sobjects/Case/";

    $tourist = ( $arr->{"tourist"} == 1 );
    $debit = ( $arr->{"debit"} == 3 );

    $content = json_encode(array(
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
        "CC_Card_Type__c" => $arr->{"ccVal"},
        "CC_Tourist__c" => $tourist,
        "CC_Debit__c" => $debit,
        "Web_Shovar__c" => $arr->{"shovar"},
        "Web_Receipt_Number__c" => "",
        "Web_Receipt__c" => "",
    ));


    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER,
        array("Authorization: OAuth $access_token",
            "Content-type: application/json"));
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $content);

    $json_response = curl_exec($curl);

    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ( $status != 201 ) {
        //die("Error: call to URL $url failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl));
        errAdmin("Error: call to URL $url failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl) . $_SESSION["donation"]->id);
        echo '<script> window.top.location = "'.get_permalink(33). "?username=" .  $_SESSION["donation"]->first_name  .'"; </script>';
        exit();
    }

    curl_close($curl);

    $response = json_decode($json_response, true);

    //$id = $response["id"];

    echo '<script> window.top.location = "'.get_permalink(33). "?username=" . $arr->first_name  .'"; </script>';
}

function errAdmin($err){

    $email = "gpmed-il-admin-group@greenpeace.org";
    //$email = explode(",",$email);
    $subject = "SalesForce Integration Error";
    $HTML = $err;

    wp_mail( $email, $subject, $HTML, array("Content-type: text/html" ) );
}
get_footer();





