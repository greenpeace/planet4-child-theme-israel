<?php
/*
 * Greenpeace Donation class
 * Built by DgCult - Eko Goren 3/2017
 * ekogoren@gmail.com
 * updated by Ofer Or 3/2026
 */

 class greenpeace_donation{

    protected $api;

    public function __construct() {
        $this->registerAjax();
        add_shortcode("greenpeace_username", array($this,'printUser'));
        //[gpf_username]
        add_action('wp_enqueue_scripts', array($this,'enqueue_script'));
        add_action( 'admin_menu', array($this,'register_my_custom_menu_page'));
        add_action('admin_post_donation_cleanup', array($this,'donationCleanupByDate'));
        add_action('admin_post_greenpeace_export', array($this,'exportCSV'));
        add_action('admin_post_greenpeace_import', array($this,'importCSV'));

        error_log("HOOK cleanupByDate REGISTERED: " . (is_callable([$this, 'donationCleanupByDate']) ? "YES" : "NO") . "\n");

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
                <h1>תרומות 16</h1>
            </header >
            <div style="
                margin-top:40px;
                display:flex;
                gap:20px;
                align-items:flex-start;
                flex-wrap:nowrap;
            ">

                <!-- Cleanup -->
                <div style="border:1px solid #ccc; padding:15px; background:#fafafa;">
                    <h3 style="margin-top:0;">Cleanup by Date</h3>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="donation_cleanup">
                        <input type="date" name="cleanup_date" required>
                        <button type="submit" class="button button-danger">Cleanup</button>
                    </form>
                </div>

                <!-- Export -->
                <div style="border:1px solid #ccc; padding:15px; background:#fafafa;">
                    <h3 style="margin-top:0;">Export</h3>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="greenpeace_export">
                        <button class="button button-primary">Export CSV</button>
                    </form>
                </div>

                <!-- Import -->
                <div style="border:1px solid #ccc; padding:15px; background:#fafafa;">
                    <h3 style="margin-top:0;">Import</h3>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="greenpeace_import">
                        <input type="file" name="csv_file" required>
                        <button class="button">Import CSV</button>
                    </form>
                </div>
                <br>
            </div>

			<?php
			$message = '***' . get_transient('donation_cleanup_message') . '***\n';
			if ($message) {
				$class = $message['type'] === 'success' ? 'notice-success' : 'notice-warning';
				echo "<div class='notice {$class}' style='padding:10px; margin:15px 0;'><p>{$message['text']}</p></div>";
				delete_transient('donation_cleanup_message');
			}
			?>

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
            </style>

		<?php
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
    

    // === ADDED: EXPORT CSV (HEBREW SAFE) ===
    public function exportCSV() {
        global $wpdb;
        $table = $wpdb->prefix . 'green_donations';
    
        // === 1. Create backup folder ===
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/greenpeace-backups';
    
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }
    
        // === 2. Create file path ===
        $timestamp = date('Y-m-d_H-i-s');
        $file_path = $backup_dir . "/donations_export_{$timestamp}.csv";
    
        // === 3. Fetch data ===
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC", ARRAY_A);
    
        // === 4. Write CSV to server ===
        $output = fopen($file_path, 'w');
    
        // UTF‑8 BOM for Hebrew
        fwrite($output, "\xEF\xBB\xBF");
    
        if (!empty($rows)) {
            fputcsv($output, array_keys($rows[0]));
    
            foreach ($rows as $row) {
                $row = array_map(function($value) {
                    return mb_convert_encoding($value, 'UTF-8');
                }, $row);
    
                fputcsv($output, $row);
            }
        }
    
        fclose($output);
    
        // === 5. Clean all output buffers BEFORE sending file ===
        while (ob_get_level()) {
            ob_end_clean();
        }
    
        // === 6. Force file download ===
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="donations_export_' . $timestamp . '.csv"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');
    
        readfile($file_path);
    
        // === 7. Stop WordPress from adding HTML ===
        exit;
    }
    

	// === ADDED: IMPORT CSV WITH DEFAULT VALUES (HEBREW SAFE) ===
	public function importCSV() {
		if (!isset($_FILES['csv_file'])) wp_die("No file uploaded");

		global $wpdb;
		$table = $wpdb->prefix . 'green_donations';

		$file = fopen($_FILES['csv_file']['tmp_name'], 'r');

		// Read header row
		$header = fgetcsv($file);

		// Remove ID column if exists
		if (strtolower($header[0]) === 'id') {
			array_shift($header);
		}

		// Default values for NOT NULL fields
		$defaults = array(
			"shovar" => "",
			"card_type" => "",
			"last_four" => "",
			"tourist" => 0,
			"ccval" => "",
			"sale_f_id" => "",
			"utm_campaign" => "",
			"utm_source" => "",
			"utm_medium" => "",
			"utm_content" => "",
			"utm_term" => "",
			"transmited_to_sf" => 0
		);

		while (($line = fgetcsv($file)) !== false) {

			// Convert each field to UTF‑8 (supports Hebrew)
			$line = array_map(function($value) {
				return mb_convert_encoding($value, 'UTF-8');
			}, $line);

			// Remove ID value if exists
			if (count($line) === count($header) + 1) {
				array_shift($line);
			}

			$data = array_combine($header, $line);

			// Apply defaults for missing NOT NULL fields
			foreach ($defaults as $key => $value) {
				if (!isset($data[$key]) || $data[$key] === "") {
					$data[$key] = $value;
				}
			}

			$wpdb->insert($table, $data);
		}

		fclose($file);

		wp_redirect(admin_url('admin.php?page=greenpeace/donations.php&import=success'));
		exit;
	}


    // === ADDED: CLEANUP BY DATE ===
	public function donationCleanupByDate() {
        error_log("donationCleanup Triggered 1\n");
		if (!isset($_POST['cleanup_date'])) wp_die("Missing date");

		global $wpdb;
		$table = $wpdb->prefix . 'green_donations';

		$date = sanitize_text_field($_POST['cleanup_date']);

		// Count rows to delete
		$count = $wpdb->get_var(
			$wpdb->prepare("SELECT COUNT(*) FROM $table WHERE `date` <= %s", $date)
		);

		if ($count == 0) {
            error_log("Cleanup Triggered - no records to delete 2\n");
			set_transient('donation_cleanup_message', [
				'type' => 'warning',
				'text' => 'לא נמצאו רשומות למחיקה.'
			], 30);
			wp_redirect($_SERVER['HTTP_REFERER']);
			exit;
		}

		// === CREATE BACKUP FOLDER ===
		$upload_dir = wp_upload_dir();
		$backup_dir = $upload_dir['basedir'] . '/greenpeace-backups';

		if (!file_exists($backup_dir)) {
			wp_mkdir_p($backup_dir);
		}

		// === BACKUP FILE NAME ===
		$timestamp = date('Y-m-d_H-i-s');
		$backup_file = $backup_dir . "/green_donations_BACKUP_{$timestamp}.csv";

		// === FETCH ROWS TO BE DELETED ===
		$rows = $wpdb->get_results(
			$wpdb->prepare("SELECT * FROM $table WHERE `date` <= %s", $date),
			ARRAY_A
		);

		// === WRITE BACKUP CSV TO SERVER ===
		if (!empty($rows)) {
            error_log("Cleanup Triggered - {$count} records to delete 3\n");
			$output = fopen($backup_file, 'w');
			fputcsv($output, array_keys($rows[0]));
			foreach ($rows as $row) fputcsv($output, $row);
			fclose($output);
		}

		// === SEND FILE TO BROWSER ===
		if (file_exists($backup_file)) {
            error_log("Cleanup Triggered - sending file to browser 4\n");
			header('Content-Type: text/csv; charset=utf-8');
			header('Content-Disposition: attachment; filename="green_donations_BACKUP_' . $timestamp . '.csv"');
			header('Content-Length: ' . filesize($backup_file));
			readfile($backup_file);
		}

		// === DELETE OLD ROWS ===
		$wpdb->query(
			$wpdb->prepare("DELETE FROM $table WHERE `date` <= %s", $date)
		);
		set_transient('greenpeace_cleanup_message', [
			'type' => 'success',
			'text' => "נמחקו {$count} רשומות בהצלחה."
		], 30);
        error_log("Cleanup Triggered - set message 5\n");
		wp_redirect($_SERVER['HTTP_REFERER']);
		exit;
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
        error_log("ofer debug 14-11-2025 data: " . print_r($data, true) . "\n");
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

    echo 'scrolling to iframe_top anchor & fix width for mobile 3<br> ';

    // present step 2 image and set anchor iframe_top
    echo <<<HTML
        <div id='iframe_top'>
            <br>
            <img src='https://www.greenpeace.org/static/planet4-israel-stateless-develop/2026/01/dbb1769d-stage2.jpg' alt='step 2'>
        </div>
    HTML;

    // present payplus iframe
    echo $iFrame;

    //exit;
}