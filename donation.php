<?php


/*
 * Greenpeace Donation class
 * Built by DgCult - Eko Goren 3/2017
 * ekogoren@gmail.com
 */

 class greenpeace_donation{

    public $fields = array(
        1 => array("name" => "first_name", "title" => "שם פרטי"),
        2 => array("name" => "last_name", "title" => "שם משפחה"),
        4 => array("name" => "phone", "title" => "טלפון"),
        3 => array("name" => "email", "title" => "אימייל")
    );

    public $idField = array("name" => "id_number", "title" => "מספר תעודת זהות");

    public $formStrings = array(
        "personal" => "פרטים אישיים:",
        "checkboxText" => "אני רוצה גם שתצרפו אותי לעיגול לטובה", // Added 2-Dec-2024 - Ofer Or
		"consentText" => "בלחיצה על המשך הריני מאשר את תנאי השימוש", // Added 2-Dec-2024 - Ofer Or
    );

    public $fieldsEn = array(
        1 => array("name" => "first_name", "title" => "First Name"),
        2 => array("name" => "last_name", "title" => "Last Name"),
        4 => array("name" => "phone", "title" => "Phone"),
        3 => array("name" => "email", "title" => "Email")
    );

    public $formStringsEn = array(
        "personal" => "Contact Details:",
        "checkboxText" => "Mark for", // Added 2-Dec-2024 - Ofer Or

    );

    public $minSum;

    protected $api;

    public function __construct() {
        $this->registerAjax();
        add_shortcode("greenpeace_donation_form_test", array($this,'shortCodeTest'));

        add_shortcode("greenpeace_donation_form", array($this,'shortCode'));
        // add_shortcode("greenpeace_donation_form_en", array($this,'shortCodeEn'));
        add_shortcode("greenpeace_username", array($this,'printUser'));
        //[gpf_username]
        add_action('wp_enqueue_scripts', array($this,'enqueue_script'));
        // add_action('wp_enqueue_scripts', array($this,'enqueue_scripten'));
        add_action('init', array($this,'redirectStageTwo'));
        //add_filter('show_admin_bar', '__return_false');
        add_action( 'admin_menu', array($this,'register_my_custom_menu_page') );
        // add_action('wp', array($this,'defrayal_operations'));
    // moved to be before insert    $this->ensureGreenDonationsTableExists(); // create the table if it doesn't exist        
        $this->api = new PayPlus();
    } 

    public function register_my_custom_menu_page() {
        add_menu_page( 'Donations', 'תרומות', 'manage_options', 'greenpeace/donations.php', array($this,'donateTable'), 'dashicons-thumbs-up', 10 );
    }

    public function donateTable(){
        global $wpdb;

        $show_all = isset( $_GET['showall'] );

        $current_url = esc_url_raw(add_query_arg(null, null)); // Get current URL
        $table_name = $wpdb->prefix . 'green_donations';

        error_log('green donation table name is: ' . $table_name );
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        error_log('green donation table has ' . $total_items . ' items');

        if ( $show_all ) {
            $donations = $wpdb->get_results( "SELECT * FROM $table_name" ); // Fetch all items
        } else {
            $items_per_page = 50;
            $page = isset($_GET['pagenum']) ? intval($_GET['pagenum']) : 1;
            (int)$offset = ($page - 1) * $items_per_page;
            $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            $total_pages = ceil($total_items / $items_per_page);

            $start_page = max($page - 5, 1); // Start page for pagination links
            $end_page = min($page + 5, $total_pages); // End page for pagination links

            $donations = $wpdb->get_results("SELECT * FROM $table_name ORDER BY `id` DESC LIMIT {$offset}, {$items_per_page}");
        }

        ?>

        <style>
            table{
                border-spacing: 0;
                border: 1px solid black;
                direction: ltr;
            }
            th, td {
                border: 1px solid black;
                padding: 5px 10px;
                margin: -1px;
            }
        </style>
        <div class="wrap about-wrap" >
            <header style="margin-bottom:40px;">
                <h1>תרומות</h1>
            </header >
            <div class="content">
                <table>
                    <thead>
                    <?php
                    foreach($donations[0] as $key => $value){
                        echo "<th>" . $key . "</th>";
                    }
                    ?>
                    </thead>
                    <tbody>
                    <?php
                    foreach($donations as $donation){
                        echo "<tr>";
                        foreach($donation as $field){
                            echo "<td>" . $field . "</td>";
                        }
                        echo "</tr>";
                    }
                    ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="donation-pagination"><?php
        if ( ! $show_all ) {

            if($page > 1) {
                echo "<a href='" . esc_url_raw(add_query_arg('pagenum', '1', $current_url)) . "'>&laquo;</a>"; // Double arrow to first page
                echo "<a href='" . esc_url_raw(add_query_arg('pagenum', ($page - 1), $current_url)) . "'>&lt;</a>"; // Arrow to previous 5 pages
            }
            for($i = $start_page; $i <= $end_page; $i++) {
                if($i == $page) {
                    echo "<span class='current'>$i</span>"; // Bold current page
                } else {
                    echo "<a href='" . esc_url_raw(add_query_arg('pagenum', $i, $current_url)) . "'>$i</a>";
                }
            }
            if($page < $total_pages) {
                echo "<a href='" . esc_url_raw(add_query_arg('pagenum', ($page + 1), $current_url)) . "'>&gt;</a>"; // Arrow to next 5 pages
                echo "<a href='" . esc_url_raw(add_query_arg('pagenum', $total_pages, $current_url)) . "'>&raquo;</a>"; // Double arrow to last page
                echo "<a href='" . esc_url_raw( add_query_arg( 'showall', '', $current_url ) ) . "'>Show All</a>"; // Link to show all items
            }
        } else {
            echo '<a href="' . esc_url_raw( remove_query_arg( 'showall', $current_url ) ) . '">Show last 50 donations</a>';
        }

            ?></div>
            <style>
                .donation-pagination {
                    direction: ltr;
                    font-size: 25px;
                    padding: 15px;
                }
                .donation-pagination > * {
                    padding: 1px;
                    text-decoration: none;
                }
            </style><?php
    }


    public function redirectStageTwo(){

        if(strpos($_SERVER['REQUEST_URI'], "stage-2")){
            //echo $_SERVER['HTTP_HOST']. str_replace("stage-2", "", $_SERVER['REQUEST_URI']);
            $newLocation = $_SERVER['HTTP_HOST']. str_replace("stage-2", "", $_SERVER['REQUEST_URI']);
            header("location://".$newLocation);
            exit();
        }
    }

    public function enqueue_script(){

        // Use unique handle to avoid overwriting the master theme's 'main' script
        $script_handle = 'gp-israel-donation';

        if ( is_page(564) ){
            wp_enqueue_script( $script_handle, get_stylesheet_directory_uri() ."/mainen.js", array('jquery'), filemtime(__DIR__ ."/main.js"), true );
        }
        else{
            wp_enqueue_script( $script_handle, get_stylesheet_directory_uri() ."/main.js", array('jquery'), filemtime(__DIR__ ."/main.js"), true );
        }


        wp_enqueue_style( "fonts", 'https://fonts.googleapis.com/earlyaccess/opensanshebrew.css');

        global $post;
        $postID = $post->ID;
        // temp $recurring = (!get_field("recurrent"))? "one-off" : "recurring";
        $recurring = "recurring"; // ignore the wp field
        $recurring = get_post_meta( $postID, 'p4_israel_donation_type', true );

        $params = array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'ajax_nonce' => wp_create_nonce('greenpeace_donation'),
            'id' => $postID,
            'minSum' => 50, //get_post_meta($postID, "min_sum" )[0],
            'payment_type' => $recurring
        );
        wp_localize_script( $script_handle, 'greenpeace_donation_object', $params );
    }

    public function printUser(){

        return (isset($_GET["username"]))? $_GET["username"] : "אורח";
    }

    public function getForm(){

        // $donation_sum_string = (!get_field("recurrent"))? "סכום התרומה החד פעמית:" : "סכום תרומה חודשי:";
        $donation_sum_string =  "סכום תרומה חודשי:"; // ignore the wp field
        $sums = array();

        //if( have_rows('sums') ):
        //    while ( have_rows('sums') ) : the_row();
        //        array_push($sums, get_sub_field('sum'));
        //    endwhile;
        //endif;
        array_push($sums, 100);
        array_push($sums, 200);

		$head = "<script> function toggleInputField() {
			const checkbox = document.getElementById('idCheckbox');
			const inputField = document.getElementById('gpf_{$this->idField["name"]}');
			inputField.style.display = checkbox.checked ? 'block' : 'none'; } </script>
		";

        $form = "<img class='steps-img' src='https://joinus.greenpeace.org.il/wp-content/uploads/2018/05/1-2-3.jpg' alt='step one'>";

        $utm_campaign = isset($_GET["utm_campaign"]) ? esc_attr($_GET["utm_campaign"]) : '';
        $utm_source   = isset($_GET["utm_source"]) ? esc_attr($_GET["utm_source"]) : '';
        $utm_medium   = isset($_GET["utm_medium"]) ? esc_attr($_GET["utm_medium"]) : '';
        $utm_content  = isset($_GET["utm_content"]) ? esc_attr($_GET["utm_content"]) : '';
        $utm_term     = isset($_GET["utm_term"]) ? esc_attr($_GET["utm_term"]) : '';


        $form .= "<div id='utm_info' data-campaign='{$utm_campaign}' data-source='{$utm_source}' data-medium='{$utm_medium}' data-content='{$utm_content}' data-term='{$utm_term}'></div>";
        $form .= "<form id='gpf_form'>";
        $form .= "<p class='gpf_title gpf_sum' tabindex='0'>{$this->formStrings["personal"]}</p>";

        foreach ($this->fields as $field){
            $form .= "<div><input type='text' id='gpf_{$field['name']}' name='{$field['name']}' placeholder='{$field['title']}'><p class='gpf_error' tabindex='0'></p></div>";
        }

        $form .= "<p class='gpf_title gpf_sum' tabindex='0'>$donation_sum_string</p>";

        $form .= "<fieldset id='gpf_radios'>";

        $i = 0;
        foreach ($sums as $sum){
            $selected = ($i === 0)? "checked" : "";
            $form .= "<div><input type='radio' $selected name='gpf_sum_radio' value='$sum'><label></label><span>$sum</span></div>";
            $i ++;
        }

        $form .= "<div class='gpf_other_cont'><input type='radio' name='gpf_sum_radio' value='other'><label></label>";
        $form .= "<input type='text' disabled name='gpf_other' placeholder='סכום אחר' id='gpf_other'><p class='gpf_error gpf_other_error' tabindex='0'></p></div>";

		$form .= "</fieldset>";

		// Added 2-Dec-2024 - Ofer Or  Start =============

		$form .= "<p class='gpf_checkbox'><input type='checkbox' id='gpf_igulLetovaCheckbox' name='igul_Letova_Checkbox' value='checked' >{$this->formStrings["checkboxText"]}</p>";
		$form .= "<div><input type='text' id='gpf_{$this->idField["name"]}' name='{$this->idField["name"]}' style='display: none;' placeholder='{$this->idField["title"]} '><p class='gpf_error' tabindex='0'></p></div>";
        $form .= "<p class='gpf_checkbox' tabindex='0'>{$this->formStrings["consentText"]}</p>";

		// Added 2-Dec-2024 - Ofer Or  End   =============


        $form .= "<input type='submit' value='המשך לשלב הבא'></form>";
        $form .= '<svg id="gpf_loader" width=\'120px\' height=\'120px\' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid" class="uil-default"><rect x="0" y="0" width="100" height="100" fill="none" class="bk"></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(0 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(30 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.08333333333333333s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(60 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.16666666666666666s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(90 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.25s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(120 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.3333333333333333s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(150 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.4166666666666667s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(180 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.5s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(210 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.5833333333333334s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(240 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.6666666666666666s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(270 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.75s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(300 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.8333333333333334s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(330 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.9166666666666666s\' repeatCount=\'indefinite\'/></rect></svg>';
        $form .= "<div id='grf_frame'></div>";

        return $form;
    }

    public function getFormTest(){

        $form = "<form id='test_form'>";
        $form .= "<div><input type='text' id='test_input' name='test_input' placeholder='Test line'></div>";
        $form .= "<input type='submit' value='Submit'></form>";

        return $form;
    }

    public function getFormEn(){

        $donation_sum_string = (!get_field("recurrent"))? "One time Donation Amount:" : "Monthly Donation:";
        $sums = array();

        if( have_rows('sums') ):
            while ( have_rows('sums') ) : the_row();
                array_push($sums, get_sub_field('sum'));
            endwhile;
        endif;
        $form = "<img class='steps-img' src='https://joinus.greenpeace.org.il/wp-content/uploads/2018/05/1-3-steps.png' alt='step one'>";
        $form .= "<div id='utm_info' data-campaign='".$_GET["utm_campaign"]."' data-source='".$_GET["utm_source"]."' data-medium='".$_GET["utm_medium"]."' data-content='".$_GET["utm_content"]."' data-term='".$_GET["utm_term"]."'></div>";
        $form .= "<form id='gpf_form'>";
        $form .= "<p class='gpf_title gpf_sum' tabindex='0'>{$this->formStringsEn["personal"]}</p>";

        foreach ($this->fieldsEn as $field){
            $form .= "<div><input type='text' id='gpf_{$field['name']}' name='{$field['name']}' placeholder='{$field['title']}'><p class='gpf_error' tabindex='0'></p></div>";
        }

        $form .= "<p class='gpf_title gpf_sum' tabindex='0'>$donation_sum_string</p>";

        $form .= "<fieldset id='gpf_radios'>";

        $i = 0;
        foreach ($sums as $sum){
            $selected = ($i === 0)? "checked" : "";
            $form .= "<div><input type='radio' $selected name='gpf_sum_radio' value='$sum'><label></label><span>$sum</span></div>";
            $i ++;
        }

        $form .= "<div class='gpf_other_cont'><input type='radio' name='gpf_sum_radio' value='other'><label></label>";
        $form .=  "<input type='text' disabled name='gpf_other' placeholder='Other' id='gpf_other'><p class='gpf_error gpf_other_error' tabindex='0'></p></div>";

        $form .= "</fieldset><input type='submit' value='Next Step'></form>";
        $form .= '<svg id="gpf_loader" width=\'120px\' height=\'120px\' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid" class="uil-default"><rect x="0" y="0" width="100" height="100" fill="none" class="bk"></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(0 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(30 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.08333333333333333s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(60 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.16666666666666666s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(90 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.25s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(120 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.3333333333333333s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(150 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.4166666666666667s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(180 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.5s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(210 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.5833333333333334s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(240 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.6666666666666666s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(270 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.75s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(300 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.8333333333333334s\' repeatCount=\'indefinite\'/></rect><rect  x=\'46.5\' y=\'40\' width=\'7\' height=\'20\' rx=\'5\' ry=\'5\' fill=\'white\' transform=\'rotate(330 50 50) translate(0 -30)\'>  <animate attributeName=\'opacity\' from=\'1\' to=\'0\' dur=\'1s\' begin=\'0.9166666666666666s\' repeatCount=\'indefinite\'/></rect></svg>';
        $form .= "<iframe id='grf_frame' width='800' height='1260' name='defrayal' frameBorder='0'></iframe>";

        return $form;
    }

    public function shortCode(){

        echo $this->getForm();
    }

    public function shortCodeTest(){
        echo $this->getFormTest();
    }

    public function shortCodeEn(){

        echo $this->getFormEn();
    }

    public function dbClient(){

        //Security
        if ( !wp_verify_nonce( $_POST['nonce'], "greenpeace_donation")) exit("No monkey business");

		// added 12-Jan-2025 Ofer Or //
		$igul_Letova_Checkbox = ($_POST["igul_Letova_Checkbox"] === "checked");

        global $wpdb;
        $table_name = $wpdb->prefix . 'green_donations';

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO $table_name (first_name, last_name, phone, email, igul_letova, id_number, page_id, payment_type, utm_campaign, utm_source, utm_medium, utm_content, utm_term) VALUES(%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
                $_POST["first_name"], $_POST["last_name"], $_POST["phone"], $_POST["email"], $igul_Letova_Checkbox, $_POST["id_number"], $_POST["page_id"], $_POST["payment_type"],
                $_POST["utm_campaign"], $_POST["utm_source"], $_POST["utm_medium"], $_POST["utm_content"], $_POST["utm_term"]
            )
        );
		// Change end -  12-Jan-2025 Ofer Or //

        $unique = $wpdb->insert_id;
        $sum = ($_POST["gpf_sum_radio"] !== "other")? $_POST["gpf_sum_radio"] : $_POST["gpf_other"];
        $email = $_POST["email"]; //change
        $name = $_POST["first_name"] ." ". $_POST["last_name"];//change
        $page = intval($_POST["page_id"]);
        $iFrame = $this->getIframe($unique, $sum, $name, $email, $_POST["phone"], $page);
        echo $iFrame;
        exit;
    }



    public function defrayal_operations(){  //TODO Thank you page operations;


        if(isset($_POST["p96"]) ){ //if page id and returned data then:


            $pfsAuthCode = '8bf832811e7f40b78b64287f3e522b38';
            $pfsAuthUrl = 'https://ws.payplus.co.il/Sites/_PayPlus/pfsAuth.aspx';
            $pfsPaymentUrl = 'https://ws.payplus.co.il/Sites/_PayPlus/payment.aspx';
            // if Use PayPlus IPN Check

            $pfs_voucher_id = $_POST["p96"];
            $order_number = $_POST["p120"];


            $pfs_post_variables = Array(
                'voucherId' 	=> 	$pfs_voucher_id,
                'uniqNum' 	=> 	$order_number,
                //'pfsAuthCode'	=> 	'2851500dbdf34ad3a21e3eb417ffef28'
                // 'pfsAuthCode'	=> 	'5c83022bde1b4d34b42e5fa6700c369a'
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


            if($pfs_curl_response !=''){
                $_ipn_response = substr($pfs_curl_response, 0 ,1);

                if($_ipn_response == 'Y'){
                    echo "<pre>";
                    var_dump($_POST);
                    echo "</pre>";

                }
                else{
                    echo "Error";
                }
            }
            else{
                echo "Connection Error";
            }
        }
    }

    public function registerAjax(){

        add_action("wp_ajax_gpf_dbclient", array($this,'dbClient'));
        add_action("wp_ajax_nopriv_gpf_dbclient", array($this,'dbClient'));
    }

    public function ensureGreenDonationsTableExists() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'green_donations';
 
    //    error_log('ensureGreenDonationsTableExists: green donation table name is: ' . $table_name . "\n");

        // Check if the table exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) != $table_name ) {
            error_log($table_name . ' table does not exist, creating it.' . "\n");
            // Table does not exist, so create it
            $sql = "CREATE TABLE $table_name (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `date` timestamp NOT NULL DEFAULT current_timestamp(),
                `page_id` int(30) DEFAULT NULL,
                `payment_type` char(10) DEFAULT NULL,
                `first_name` varchar(30) DEFAULT NULL,
                `last_name` varchar(30) DEFAULT NULL,
                `email` varchar(40) DEFAULT NULL,
                `phone` char(10) DEFAULT NULL,
                `igul_letova` int(3) DEFAULT NULL,
                `id_number` char(10) DEFAULT NULL,
                `amount` int(5) DEFAULT NULL,
                `token` varchar(40) DEFAULT NULL,
                `exp` varchar(30) DEFAULT NULL,
                `cc_holder` varchar(40) DEFAULT NULL,
                `response` int(3) DEFAULT NULL,
                `shovar` varchar(100) NOT NULL,
                `card_type` varchar(100) NOT NULL,
                `last_four` varchar(100) NOT NULL,
                `tourist` int(3) NOT NULL,
                `ccval` varchar(40) NOT NULL,
                `sale_f_id` varchar(100) NOT NULL,
                `icount_id` varchar(100) DEFAULT NULL,
                `utm_campaign` varchar(100) NOT NULL,
                `utm_source` varchar(100) NOT NULL,
                `utm_medium` varchar(100) NOT NULL,
                `utm_content` varchar(100) NOT NULL,
                `utm_term` varchar(100) NOT NULL,
                `payplus_callback_response` longtext DEFAULT NULL,
                `transmited_to_sf` int(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`)
              ) AUTO_INCREMENT=12540 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci ROW_FORMAT=DYNAMIC;";
    
            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            dbDelta( $sql );
    //    } else {
    //        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    //        error_log($table_name . ' exists and has ' . $total_items . ' items.' . "\n");
        }
    }
    
    // Insert to donation table
    public function insertToDonationTable($gform_entry_id, $first_name, $last_name, $phone, $email, $igul_letova, $id_number, $page, $payment_type, $utm_campaign, $utm_source, $utm_medium, $utm_content, $utm_term){

        $this->ensureGreenDonationsTableExists(); // create the table if it doesn't exist        

        global $wpdb;
        $table_name = $wpdb->prefix . 'green_donations';

        error_log("insertToDonationTable: green donation table name is: " . $table_name . "\n");

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO $table_name (icount_id, first_name, last_name, phone, email, igul_letova, id_number, page_id, payment_type, utm_campaign, utm_source, utm_medium, utm_content, utm_term) VALUES(%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
                $gform_entry_id, $first_name, $last_name, $phone, $email, $igul_letova, $id_number, $page, $payment_type,
                $utm_campaign, $utm_source, $utm_medium, $utm_content, $utm_term
            )
        );
        $unique = $wpdb->insert_id;
        return $unique;
    }


    public function getIframe($unique, $amount, $clientName, $email, $phone, $page, $payment_type){
        // echo("get Iframe start ....... <br>");
        // error_log("*** get Iframe start ......\n");

        $language_code = 'he-il';

        // if ($page == 564){    // not in use planet 4
        //     $language_code = 'en';
        // }

        // $recurring = (get_field("recurrent", $page)); // ignore the wp field - to be replaced by form field value
        $recurring = ($payment_type == "recurring");

        $data = [
            "payment_page_uid" => "0b06263c-bc1b-48e2-92f6-bf60cfd38951", //prod terminal: f01f5630-73f7-4955-a4a5-b408247056ca
            // "payment_page_uid" => "f01f5630-73f7-4955-a4a5-b408247056ca", //test terminal: da8dc348-aae3-43c2-ad9b-6b7a7785a8d2
            "expiry_datetime" => "30",
            "more_info" => $unique,
            "language_code" => $language_code,
            'create_token' => true,
            'charge_method' => $recurring ? 3 : 1,
            "refURL_success" => 'https://www-dev.greenpeace.org/israel/joinus-thankyou/',
            "refURL_failure" => 'https://www-dev.greenpeace.org/israel/joinus-thankyou/',
            "refURL_callback" => 'https://www-dev.greenpeace.org/israel/payplus-callback/',
            "customer" => [
                "customer_name" => $clientName,
                "email" => $email,
                "phone" => $phone,
                //"vat_number" => "036534683"
            ],
            "amount" => $amount,
            "payments" => 1, // ??
            "currency_code" => "ILS",
            "sendEmailApproval" => false,
            "sendEmailFailure" => false,
        ];

        if($recurring) {
            $data['recurring_settings'] = [
                'instant_first_payment' => true,
                'recurring_type' => 2,
                'recurring_range' => 1,
                'start_date_on_payment_date' => false,
                'start_date' => '03',
                'number_of_charges' => 0,
                'successful_invoice' => true,
                'customer_failure_email' => false,
                'send_customer_success_email' => false,
            ];

            $current_day_in_month = date('j');
            if($current_day_in_month <= 3) {
                $data['recurring_settings']['instant_first_payment'] = false;
            }
        }
// ofer debug 14-11-2025 - start
        error_log("ofer debug 14-11-2025data: " . print_r($data, true) . "\n");
//      echo "ofer debug 14-11-2025 data: " . print_r($data, true) . "<br>";
// ofer debug 14-11-2025 - end
        $iframe_url = $this->api->apiRequest('/PaymentPages/generateLink', $data);

        if(isset($iframe_url->results)) {
            // error_log("*** iframe_url_results......\n");

            if($iframe_url->results->status === 'success') {
                // error_log("*** iframe_url_results_status_success....\n");

                // Adjust iframe size based on screen size
                $screen_width = $_SESSION['viewport_width'] ?? 1024;
                if ($screen_width < 768) {
                    $iframe_width = '100%';
                    $iframe_height = '600';
                } else {
                    $iframe_width = '800';
                    $iframe_height = '750';
                }

                return <<<HTML
                <iframe id="payplus-new-iframe"
                    src="{$iframe_url->data->payment_page_link}"
                    width="{$iframe_width}"
                    height="{$iframe_height}"
                    name="defrayal"
                    frameBorder="0"
                    scrolling="no"
                    onload="document.getElementById('iframe_top').scrollIntoView({behavior: 'instant', block: 'start'})">
                </iframe>
                HTML;
            }
        }

        return $iframe_url;
    }
}



function donation_gform_function($entry, $form) {

    error_log("2******** donation_gform_function called **********\n" );
    // echo "2******** donation_gform_function called **********<br>";

    // Debug echo at function start
    // echo "*** donation_gform_function started \n";
    // echo "*** Entry data: " . print_r($entry, true) . " \n";
    // echo "*** Form data: " . print_r($form, true) . " \n";

    // Get the values from the entry
    $record_id = rgar($entry, 'id');
    $page = rgar($entry, 'source_id');
    $first_name = rgar($entry, '1');
    $last_name = rgar($entry, '3');
    $name = $first_name . " " . $last_name;
    $email = rgar($entry, '7');
    $phone = rgar($entry, '17');
    $amount = rgar($entry, '25');
    $igul_letova = rgar($entry, '27.1') ? 1 : 0;
    $id_number = rgar($entry, '28');
    $utm_campaign = rgar($entry, '19');
    $utm_source = rgar($entry, '18');
    $utm_medium = rgar($entry, '20');
    $utm_content = rgar($entry, '24');
    $utm_term = rgar($entry, '23');
     
    global $post;
    $postID = $post->ID;
    $recurring = get_post_meta( $postID, 'p4_israel_donation_type', true );
    $payment_type = ($recurring === "recurring") ? "recurring" : "one-off";

     error_log("donation_gform_function - payment type : " . $payment_type . "\n" );
     error_log('ENTRY: ' . print_r($entry, true));
     error_log('Field 27: ' . print_r(rgar($entry, '27'), true));
     error_log('Field 27.1: ' . print_r(rgar($entry, '27.1'), true));
     
    // for debug only
     // echo "*** Retrieved values:<br>";
     // echo "*** Record Id: " . $record_id . " <br>";
     // echo "*** First Name: " . $first_name . " <br>";
     // echo "*** Last Name: " . $last_name . " <br>";
     // echo "*** Full Name: " . $name . " <br>";
     // echo "*** Email: " . $email . " <br>";
     // echo "*** Phone: " . $phone . " <br>";
     // echo "*** Amount: " . $amount . " <br>";
     // echo "*** Page: " . $page . " <br>";
     // echo "***************************************** <br>";
     // ... end of debug echo

    $donation1 = new greenpeace_donation();

    $unique = $donation1->insertToDonationTable(
        $record_id, 
        $first_name,
        $last_name,
        $phone,
        $email,
        $igul_letova,
        $id_number,
        $page,
        $payment_type,
        $utm_campaign,
        $utm_source,
        $utm_medium,
        $utm_content,
        $utm_term
    );
    
    $iFrame = $donation1->getIframe($unique, $amount, $name, $email, $phone, $page, $payment_type);

    echo 'scrolling to iframe_top anchor & fix width for mobile 2<br> ';

    // present step 2 image and set anchor iframe_top
    echo <<<HTML
        <div id='iframe_top'>
            <br>
            <img src='https://www.greenpeace.org/static/planet4-israel-stateless-develop/20252526/01/dbb1769d-stage2.jpg' alt='step 2'>
        </div>
    HTML;

    // present payplus iframe
    echo $iFrame;

    //exit;
}
