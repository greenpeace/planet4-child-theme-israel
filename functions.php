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
/**
 * Gravity Forms: Change radio button options based on short code data - added by Ofer Or 12-01-2026
 */

add_filter( 'gform_pre_render_60', 'set_radio_choices_from_shortcode' );
add_filter( 'gform_pre_validation_60', 'set_radio_choices_from_shortcode' );

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
         'amount1' => get_post_meta( $post_id, 'donation_amout_1', true ),
         'amount2' => get_post_meta( $post_id, 'donation_amout_2', true ),
         'amount3' => get_post_meta( $post_id, 'donation_amout_3', true ),
     );
     
     // Debug logging
     error_log( "Custom field values - donation_amout_1: " . $values['amount1'] . " | donation_amout_2: " . $values['amount2'] . " | donation_amout_3: " . $values['amount3'] );
     error_log( "Post ID used: " . $post_id );
 
    error_log( "Shortcode values resolved: amount1={$values['amount1']} | amount2={$values['amount2']} | amount3={$values['amount3']}" );

    // Now set radio choices
    foreach ( $form['fields'] as &$field ) {
        if ( $field->id == $field_id_radio ) {

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

  
//Add “other” checks: 
add_filter( 'gform_field_validation_60_25', 'validate_other_choice', 10, 4 );
function validate_other_choice( $result, $value, $form, $field ) {

    error_log("********* validate_other_choice function called **********\n" );

    // Get DD field value (replace 8 with DD field ID)
    $dd_value = rgpost( 'input_30' );

    // If user selected "Other", check the entered value
    if ( $value == 'Other' ) {
        // Assume user provides "Other" value in a text field (ID 9)
        $other_value = rgpost( 'input_25' );

        if ( intval( $other_value ) <= intval( $dd_value ) ) {
            $result['is_valid'] = false;
            $result['message']  = 'The "Other" value must be greater than field DD.';
        }
    }

    return $result;
}

// end of Change radio button options functunality code (added by ofer or 12-01-2026)
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
// error_log("********* donation_gform_function added **********\n" );
add_action('gform_after_submission_60', 'donation_gform_function', 10, 2);

// Gravity Forms HubSpot feed pre-save hook to set language to Hebrew for new forms
add_filter( 'gform_hubspot_form_object_pre_save_feed', function ( $hs_form, $feed_meta, $form, $existing_form ) {
    if ( empty( $existing_form ) ) {
        $hs_form['configuration']['language'] = 'he-il';
    }

    return $hs_form;
}, 10, 4 );
