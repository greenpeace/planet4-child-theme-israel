<?php

/**
 * Additional code for the child theme goes in here.
 */

// *******************************************************
// Donation functunality code  - add by Ofer Or
// 06-Jun-2025
// aduped from old donation site code 
// *******************************************************
include 'Helpers.php';
include 'PayPlus.php';
include 'donation.php';
 
$donation = new greenpeace_donation();

define("REDIRECT_URI", "https://www-dev.greenpeace.org/israel/receive-defrayal/");

 
// end of donation functunality code (added by ofer or 06-Jun-2025)
// *******************************************************


add_action( 'wp_enqueue_scripts', 'enqueue_child_styles', 99);

function enqueue_child_styles() {
	$css_creation = filectime(get_stylesheet_directory() . '/style.css');

	wp_enqueue_style( 'child-style', get_stylesheet_directory_uri() . '/style.css', [], $css_creation );
}

// Gravity Forms pre-submission hook
// add_action('gform_pre_submission', 'my_pre_submission_cleanup'); 
function my_pre_submission_cleanup($form) {

    // Loop through each field in the form
    foreach ($form['fields'] as $field) {
        
        // 1) If it's a phone field, remove all non-digit characters (including +)
        if ($field->type === 'phone') {
            $field_id    = $field->id;
            $phone_value = rgpost("input_{$field_id}");
            // Keep only digits
            $phone_value = preg_replace('/[^0-9]/', '', $phone_value);

            // Set it back to $_POST so GF saves the modified value
            $_POST["input_{$field_id}"] = $phone_value;
        }

        // 2) If it's an email field, do your auto-correct logic
        if ($field->type === 'email') {
            $field_id    = $field->id;
            $email_value = rgpost("input_{$field_id}");

            // Trim spaces
            $email_value = trim($email_value);

            if (strpos($email_value, '@') !== false) {
                list($local_part, $domain_part) = explode('@', $email_value, 2);

                // Remove Hebrew letters, dots, '*' from local part
                $local_part = preg_replace('/[א-ת.*]/u', '', $local_part);

                // Convert the domain to lowercase
                $domain_part = strtolower($domain_part);

                // Possibly auto-correct known domain typos
                $domain_part_corrected = my_autocorrect_domain($domain_part);
                if ($domain_part_corrected) {
                    $domain_part = $domain_part_corrected;
                }

                // Reconstruct the email
                $email_value = $local_part . '@' . $domain_part;
            }

            $_POST["input_{$field_id}"] = $email_value;
        }
    }
}

function my_autocorrect_domain($domain) {
    $known_domains = ['gmail.com','yahoo.com','hotmail.com'];
    // Simple example or use Levenshtein, etc.
    foreach ($known_domains as $d) {
        if (similar_text($domain, $d) >= (strlen($d) * 0.8)) {
            return $d;
        }
    }
    return $domain; // No change by default
}

// Gravity Forms after-submission hook for form id 60 - added by Ofer Or 13-6-2025
error_log("********* donation_gform_function added **********\n" );
add_action('gform_after_submission_60', 'donation_gform_function', 10, 2);
 