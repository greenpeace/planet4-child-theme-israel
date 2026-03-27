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
        add_action('admin_menu', array($this,'register_my_custom_menu_page'));
        add_action('admin_post_donation_cleanup', array($this,'donationCleanupByDate'));
        add_action('admin_post_greenpeace_export', array($this,'exportCSV'));
        add_action('admin_post_greenpeace_import', array($this,'importCSV'));

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

        debug_log('Panic', 'green donation table name is: ' . $table_name );
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        debug_log('Panic', 'green donation table has ' . $total_items . ' items');

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
        <!-- Do not use class "about-wrap": core admin CSS hides .about-wrap .notice (Trac #32625), so feedback never appears. -->
        <div class="wrap gp-donations-admin">
            <header style="margin-bottom:20px;">
                <h1>תרומות</h1>
            </header >
            <div style="
                margin-top:10px;
                margin-bottom:10px;
                display:flex;
                gap: 30px;px;
                align-items:flex-start;
                flex-wrap:nowrap;
            ">

                <!-- Export -->
                <div style="border:1px solid #ccc; padding:10px; background:#fafafa;">
                    <h3 style="margin-top:0;">Export</h3>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="greenpeace_export">
                        <button class="button button-primary">Export CSV</button>
                    </form>
                </div>

                <!-- Cleanup -->
                <div style="border:1px solid #ccc; padding:10px; background:#fafafa;">
                    <h3 style="margin-top:0;">Cleanup by Date</h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('donation_cleanup_action', 'donation_cleanup_nonce'); ?>
                        <input type="hidden" name="action" value="donation_cleanup">
                        <input type="date" name="cleanup_date" required>
                        <button type="submit" class="button button-primary">Cleanup</button>
                    </form>
                </div>

                <!-- Import -->
                <div style="border:1px solid #ccc; padding:10px; background:#fafafa;">
                    <h3 style="margin-top:0;">Import</h3>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="greenpeace_import">
                        <input type="file" name="csv_file" required>
                        <button class="button button-primary">Import CSV</button>
                    </form>
                </div>

            </div>

            <?php
                // URL flag (works even when transients/object cache fail); values are whitelisted, not echoed from user input.
                $gp_don_notice_key = isset($_GET['gp_don_notice']) ? sanitize_key(wp_unslash($_GET['gp_don_notice'])) : '';
                $gp_don_notice_map = array(
                    'cleanup_none' => array('type' => 'warning', 'message' => 'לא נמצאו רשומות למחיקה.'),
                );
                if ($gp_don_notice_key !== '' && isset($gp_don_notice_map[$gp_don_notice_key])) {
                    $gp_nm = $gp_don_notice_map[$gp_don_notice_key];
                    printf(
                        '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                        esc_attr($gp_nm['type']),
                        esc_html($gp_nm['message'])
                    );
                }

                // Post-redirect notices (backup if redirect used transient only)
                $gp_notice = get_transient('gp_il_donations_notice');
                if (is_array($gp_notice) && !empty($gp_notice['message'])) {
                    delete_transient('gp_il_donations_notice');
                    $n_type = isset($gp_notice['type']) ? $gp_notice['type'] : 'info';
                    if (!in_array($n_type, array('error', 'warning', 'success', 'info'), true)) {
                        $n_type = 'info';
                    }
                    printf(
                        '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                        esc_attr($n_type),
                        esc_html($gp_notice['message'])
                    );
                }

                // read and display the massage from the last action is exists
                // Load saved settings errors after redirect and display them
                if ($saved = get_transient('settings_errors')) {
                    foreach ($saved as $error) {
                        add_settings_error(
                            'donation_cleanup', // slug should be donation_cleanup
                            $error['code'],
                            $error['message'],
                            $error['type']
                        );
                    }
                    delete_transient('settings_errors');
                }

                // Display the message
                settings_errors('donation_cleanup');
                settings_errors('donation_import');
            ?>

            <div class="content">
                <?php if (empty($donations)) : ?>
                    <p><?php echo esc_html__('No donation records in the table.', 'planet4-child-theme-israel'); ?></p>
                <?php else : ?>
                <table>
                    <thead>
                    <?php
                    foreach ($donations[0] as $key => $value) {
                        echo "<th>" . esc_html($key) . "</th>";
                    }
                    ?>
                    </thead>
                    <tbody>
                    <?php
                    foreach ($donations as $donation) {
                        echo "<tr>";
                        foreach ($donation as $field) {
                            echo "<td>" . esc_html((string) $field) . "</td>";
                        }
                        echo "</tr>";
                    }
                    ?>
                    </tbody>
                </table>
                <?php endif; ?>
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
            debug_log('Info', 'INFO: ' . $table_name . ' table does not exist, creating it.');
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

		$imported_count = 0;

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

			if (false !== $wpdb->insert($table, $data)) {
				$imported_count++;
			}
		}

		fclose($file);

		//wp_redirect(admin_url('admin.php?page=greenpeace/donations.php&import=success'));
        add_settings_error(
            'donation_import',          // slug
            'donation_import',     // code
            sprintf(
                /* translators: %s: number of CSV rows successfully inserted */
                __('התווספו %s רשומות לטבלה.', 'planet4-child-theme-israel'),
                number_format_i18n($imported_count)
            ),
            'warning'                    // type: 'error', 'warning', 'success', 'info'
        );
    
        // שמירת ההודעות לסשן הבא (אחרי רידיירקט)
        set_transient('settings_errors', get_settings_errors(), 30);
    
        wp_redirect($_SERVER['HTTP_REFERER']);
        exit;

		exit;
	}


    // === ADDED: CLEANUP BY DATE ===
	public function donationCleanupByDate() {
        debug_log('Panic', "donationCleanup Triggered 1");
		if (!isset($_POST['cleanup_date'])) {
			wp_die(__('Missing date.', 'planet4-child-theme-israel'));
		}
		if (!isset($_POST['donation_cleanup_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['donation_cleanup_nonce'])), 'donation_cleanup_action')) {
			wp_die(__('Invalid cleanup request.', 'planet4-child-theme-israel'));
		}
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have permission to run cleanup.', 'planet4-child-theme-israel'));
		}

		global $wpdb;
		$table = $wpdb->prefix . 'green_donations';

		$date = sanitize_text_field($_POST['cleanup_date']);
        $date = $date . ' 23:59:59';

		// Count rows to delete
		$count = $wpdb->get_var(
			$wpdb->prepare("SELECT COUNT(*) FROM $table WHERE `date` <= %s", $date)
		);

        if ((int) $count === 0) {
            debug_log('Panic', "Cleanup Triggered - no records to delete 2");
            $redirect = add_query_arg(
                'gp_don_notice',
                'cleanup_none',
                admin_url('admin.php?page=greenpeace/donations.php')
            );
            wp_safe_redirect($redirect);
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
            debug_log('Panic', "Cleanup Triggered - write {$count} records to backup CSV 3");
			$output = fopen($backup_file, 'w');
			fputcsv($output, array_keys($rows[0]));
			foreach ($rows as $row) fputcsv($output, $row);
			fclose($output);
		}

   		// === DELETE OLD ROWS ===
		$wpdb->query(
			$wpdb->prepare("DELETE FROM $table WHERE `date` <= %s", $date)
		);
        debug_log('Panic', "Cleanup Triggered - {$count} records deleted 4");

		// === SEND FILE TO BROWSER ===
		if (file_exists($backup_file)) {
            debug_log('Panic', "Cleanup Triggered - sending file to browser 5");
            // === 5. Clean all output buffers BEFORE sending file ===
            while (ob_get_level()) {
                ob_end_clean();
            }
        
            // === 6. Force file download ===
            header('Content-Type: text/csv; charset=UTF-8');
			header('Content-Disposition: attachment; filename="green_donations_BACKUP_' . $timestamp . '.csv"');
			header('Content-Length: ' . filesize($backup_file));
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: 0');
        
			readfile($backup_file);
        
            // === 7. Stop WordPress from adding HTML ===
            exit;
		}

        // if somehow no file exists (rare), fallback to redirect
        set_transient('greenpeace_cleanup_message', [
			'type' => 'success',
			'text' => "נמחקו {$count} רשומות בהצלחה."
		], 30);

		wp_redirect($_SERVER['HTTP_REFERER']);
		exit;
	}

    
    // Insert to donation table
    public function insertToDonationTable($gform_entry_id, $first_name, $last_name, $phone, $email, $igul_letova, $id_number, $page, $payment_type, $utm_campaign, $utm_source, $utm_medium, $utm_content, $utm_term){

        $this->ensureGreenDonationsTableExists(); // create the table if it doesn't exist        

        global $wpdb;
        $table_name = $wpdb->prefix . 'green_donations';

        debug_log('Panic', "insertToDonationTable: green donation table name is: " . $table_name );

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
        debug_log('Panic', "*** get Iframe start ......");

        $language_code = 'he-il';

        $recurring = ($payment_type == "recurring");

        $payment_page_uid = getPaymentPageUidParam();
        if (empty($payment_page_uid)) {
            debug_log('Error', 'ERROR: PayPlus payment_page_uid is missing (pp_payment_page_uid option is empty).');
            throw new Exception('ERROR: PayPlus payment_page_uid missing (pp_payment_page_uid).');
        }

        $post_id = (int) $page;
        $thank_you_page_url = esc_url_raw(
            get_post_meta($post_id, 'p4_israel_donation_thank_you_page_url', true)
        );

        $data = [
            "payment_page_uid" => $payment_page_uid,
            "expiry_datetime" => "30",
            "more_info" => $unique,
            "language_code" => $language_code,
            'create_token' => true,
            'charge_method' => $recurring ? 3 : 1,
            "refURL_success" => $thank_you_page_url,
            "refURL_failure" => $thank_you_page_url,
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
        debug_log('Panic', "ofer debug 14-11-2025 data: " . print_r($data, true) );
        //      echo "ofer debug 14-11-2025 data: " . print_r($data, true) . "<br>";
        // ofer debug 14-11-2025 - end
        $iframe_url = $this->api->apiRequest('/PaymentPages/generateLink', $data);

        if(isset($iframe_url->results)) {
            // error_log("*** iframe_url_results......\n");

            if($iframe_url->results->status === 'success') {
                // error_log("*** iframe_url_results_status_success....\n");

                return '
                        <div style="width:100%; max-width:800px; margin:0 auto;">
                            <iframe id="payplus-new-iframe"
                                src="' . $iframe_url->data->payment_page_link . '"
                                style="width:100%; height:750px; border:0;"
                                name="defrayal"
                                scrolling="no">
                            </iframe>
                        </div>';
            }
        }
        return $iframe_url;
    }
}

function set_radio_choices_from_shortcode( $form ) {

    $field_id_radio = 25;
     // Get current post/page ID
     global $post;
     $post_id = $post ? $post->ID : get_the_ID();
     
     // If no post ID, try to get it from the form's page ID
     if ( empty( $post_id ) && isset( $form['pageId'] ) ) {
         $post_id = $form['pageId'];
     }
     
     // Read native WordPress custom fields
     $values = array(
         'amount1' => get_post_meta( $post_id, 'p4_israel_donation_amount_1', true ),
         'amount2' => get_post_meta( $post_id, 'p4_israel_donation_amount_2', true ),
         'amount3' => get_post_meta( $post_id, 'p4_israel_donation_amount_3', true ),
     );
     
     // Read donation type custom field
     $donation_type = get_post_meta( $post_id, 'p4_israel_donation_type', true );
     
     // Debug logging
    debug_log('Panic', "Post ID used: " . $post_id );
    debug_log('Panic', "Radio button values : amount1={$values['amount1']} | amount2={$values['amount2']} | amount3={$values['amount3']}" );
    debug_log('Panic', "Donation type: " . $donation_type );

    // Now set radio choices
    foreach ( $form['fields'] as &$field ) {
        if ( $field->id == $field_id_radio ) {
            
            // Set field title based on donation type
            if ( $donation_type === 'recurring' ) {
                $field->label = 'סכום תרומה חודשי:';
            } else {
                $field->label = 'סכום התרומה החד פעמית:';
            }

            $choices = array();

            if ( ! empty( $values['amount1'] ) ) $choices[] = array( 'text' => $values['amount1'], 'value' => $values['amount1'] );
            if ( ! empty( $values['amount2'] ) ) $choices[] = array( 'text' => $values['amount2'], 'value' => $values['amount2'] );
            if ( ! empty( $values['amount3'] ) ) $choices[] = array( 'text' => $values['amount3'], 'value' => $values['amount3'] );

            // fallback defaults if nothing provided
            if ( empty( $choices ) ) {
                $choices = array(
                    array( 'text' => '50', 'value' => '50' ),
                    array( 'text' => '100', 'value' => '100' ),
                    array( 'text' => '200', 'value' => '200' ),
                );
            }

            $field->choices = $choices;
        }
    }

    return $form;
}
  
/**
 * Gravity Forms: donation amount validation based on native custom fields value - added by Ofer Or 12-01-2026
 */ 
function validate_other_choice( $result, $value, $form, $field ) {

    debug_log('Panic', "********* validate_other_choice function called **********" );

    $field_id_radio = 25;
    // Get current post/page ID
    global $post;
    $post_id = $post ? $post->ID : get_the_ID();
    
    // If no post ID, try to get it from the form's page ID
    if ( empty( $post_id ) && isset( $form['pageId'] ) ) {
        $post_id = $form['pageId'];
    }
    
    // Read native WordPress custom fields
    $min_amount = get_post_meta( $post_id, 'p4_israel_donation_min_amount', true );
    debug_log('Panic', "********* Min amount: " . $min_amount . " **********" );

    if ( intval( $value ) < intval( $min_amount ) ) {
        $result['is_valid'] = false;
        $result['message']  = 'The donation amount must be greater than the minimum amount.';
        $result['message']  = 'סכום המינימום לתרומה הוא ' . $min_amount . ' שח.';
    }

    return $result;
}


// Gravity Forms after-submission function for form id 60 - added by Ofer Or 13-6-2025
function donation_gform_function($entry, $form) {

    debug_log('Panic', "********* donation_gform_function called **********" );

    // Output JS to scroll to the top of the form after submission
    add_action( 'wp_footer', function() use ( $form ) {
        ?>
        <style>
            /* Fade-in animation */
            #iframe_top {
                opacity: 0;
                transition: opacity 0.8s ease-in-out;
            }
            #iframe_top.visible {
                opacity: 1;
            }
        </style>
    
        <script>
            jQuery(document).on('gform_confirmation_loaded', function(event, formId){
                if (formId == <?php echo $form['id']; ?>) {
    
                    // Delay to allow iframe to render
                    setTimeout(function() {
    
                        var topBlock = document.getElementById('iframe_top');
    
                        if (topBlock) {
    
                            // Add fade-in class
                            topBlock.classList.add('visible');
    
                            // Scroll with offset (e.g., sticky header height)
                            var offset = 120; // adjust as needed
    
                            var elementPosition = topBlock.getBoundingClientRect().top + window.pageYOffset;
                            var scrollTo = elementPosition - offset;
    
                            window.scrollTo({
                                top: scrollTo,
                                behavior: 'smooth'
                            });
                        }
    
                    }, 400); // delay in ms
                }
            });
        </script>
        <?php
    });

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

    debug_log('Panic', "donation_gform_function - payment type : " . $payment_type );
    debug_log('Panic', 'ENTRY: ' . print_r($entry, true));
    debug_log('Panic', 'Field 27: ' . print_r(rgar($entry, '27'), true));
    debug_log('Panic', 'Field 27.1: ' . print_r(rgar($entry, '27.1'), true));
     
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

    echo <<<HTML
<style>
    /* Wrapper ONLY for the iframe area */
    .donation-iframe-wrapper {
        width: 100%;
        max-width: 800px;
        margin: 20px auto 0 auto; /* space under the image */
    }

    .donation-iframe-wrapper iframe {
        width: 100%;
        border: 0;
        display: block;
    }

    .donation-thanks {
        text-align: center;
        margin: 15px 0;
        font-size: 1.2em;
    }
</style>

<div id="iframe_top">

    <!-- Image stays as-is, theme controls it -->
    <img src="https://www.greenpeace.org/static/planet4-israel-stateless-develop/2026/03/8fc58e66-stage2.jpg" alt="step 2">

    <div class="donation-thanks">
        תודה רבה על התמיכה 17!
    </div>

    <!-- Iframe wrapper with max-width -->
    <div class="donation-iframe-wrapper">
        $iFrame
    </div>

</div>


<!-- <script>
window.addEventListener("load", function() {

    const iframe = document.querySelector("#iframe_top iframe");

    function scrollUpByIframeHeight() {
        if (!iframe) return;

        // Get the iframe height
        const iframeHeight = iframe.offsetHeight || 0;

        // Scroll up by iframe height + 100px
        window.scrollBy({
            top: -(iframeHeight + 10),
            behavior: "smooth"
        });
    }

    // Wait for iframe to load fully
    if (iframe) {
        iframe.addEventListener("load", function() {
            setTimeout(scrollUpByIframeHeight, 50);
        });
    }
});
</script> -->
HTML;
}
