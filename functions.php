<?php

/**
 * Additional code for the child theme goes in here.
 */

// *************************************************************************************************************************
// Donation functunality code  - add by Ofer Or
// Mar-2026
// adupted from old donation site code
// *******************************************************
include 'Helpers.php';
include 'salesforce-payplus-api-parameters.php';
include 'PayPlus.php';
include 'donation.php'; // build and handle donations table and UI
require_once 'SalesForce.php';
require_once 'DonationMonitor.php';

// יצירת האובייקטים
$donation = new greenpeace_donation();
$SalesForce = new SalesForce();
$donation_monitor = new DonationMonitor($SalesForce);

// רישום ה-hooks פעם אחת בכל טעינה
add_action('init', [$donation_monitor, 'scheduler']);
add_action('send_incomplete_leads_to_sf', [$donation_monitor, 'SendIncompleteLeadsToSF']);
add_filter('cron_schedules', [$donation_monitor, 'add_custom_schedules']);

// Gravity Forms: Change radio button options based on native custom fields value - added by Ofer Or 12-01-2026
add_filter( 'gform_pre_render_60', 'set_radio_choices_from_shortcode' );
add_filter( 'gform_pre_validation_60', 'set_radio_choices_from_shortcode' );
// Gravity Forms: donation amount validation based on native custom fields value - added by Ofer Or 12-01-2026
add_filter( 'gform_field_validation_60_25', 'validate_other_choice', 10, 4 );

// Gravity Forms after-submission hook for form id 60 - added by Ofer Or 13-6-2025
add_action('gform_after_submission_60', 'donation_gform_function', 10, 2);

// *******************************************************
//
// end of Change for donation form functunality code (added by ofer or Mar-2026)
//
// *********************************************************************************************************************************



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

// Gravity Forms HubSpot feed pre-save hook to set language to Hebrew for new forms
add_filter( 'gform_hubspot_form_object_pre_save_feed', function ( $hs_form, $feed_meta, $form, $existing_form ) {
    if ( empty( $existing_form ) ) {
        $hs_form['configuration']['language'] = 'he-il';
    }

    return $hs_form;
}, 10, 4 );