<?php
/*
Plugin Name: SalesForce Parameters
Description: Admin menu for Salesforce API parameters (client_id, client_secret, username, password)
Version: 1.0
Author: Ofer Or
16-2-2026
*/

// ------------------------------------------------------
// 1. Create Settings Submenu Page
// ------------------------------------------------------
add_action('admin_menu', 'sfparams_create_settings_submenu');
function sfparams_create_settings_submenu() {
    add_options_page(
        'SalesForce Parameters',     // Page title
        'SalesForce Parameters',     // Menu title under Settings
        'manage_options',            // Capability
        'salesforce-parameters',     // Menu slug
        'sfparams_settings_page_html'// Callback
    );
}

// ------------------------------------------------------
// 2. Register Settings
// ------------------------------------------------------
add_action('admin_init', 'sfparams_register_settings');
function sfparams_register_settings() {

    // Text fields
    register_setting('sfparams_settings_group', 'sf_client_id');
    register_setting('sfparams_settings_group', 'sf_username');

    // Masked password fields
    register_setting('sfparams_settings_group', 'sf_client_secret', [
        'sanitize_callback' => 'sfparams_masked_password_sanitizer'
    ]);

    register_setting('sfparams_settings_group', 'sf_password', [
        'sanitize_callback' => 'sfparams_masked_password_sanitizer'
    ]);
}

// ------------------------------------------------------
// 3. Sanitizer for masked passwords
// ------------------------------------------------------
function sfparams_masked_password_sanitizer($value) {

    $mask = '**************';

    // Determine which option is being sanitized
    $option = str_replace('sanitize_option_', '', current_filter());

    // If unchanged mask → keep old value
    if ($value === $mask || empty($value)) {
        return get_option($option);
    }

    // Save new value
    return $value;
}

// ------------------------------------------------------
// 4. Settings Page HTML
// ------------------------------------------------------
function sfparams_settings_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $mask = '**************';
    ?>

    <div class="wrap">
        <h1>SalesForce API Parameters</h1>

        <form method="post" action="options.php">
            <?php settings_fields('sfparams_settings_group'); ?>

            <table class="form-table">

                <tr>
                    <th><label for="sf_client_id">Client ID</label></th>
                    <td>
                        <input type="text" id="sf_client_id" name="sf_client_id"
                               value="<?php echo esc_attr(get_option('sf_client_id')); ?>" />
                    </td>
                </tr>

                <tr>
                    <th><label for="sf_client_secret">Client Secret</label></th>
                    <td>
                        <input type="password" id="sf_client_secret" name="sf_client_secret"
                               value="<?php echo $mask; ?>" autocomplete="new-password" />
                    </td>
                </tr>

                <tr>
                    <th><label for="sf_username">Username</label></th>
                    <td>
                        <input type="text" id="sf_username" name="sf_username"
                               value="<?php echo esc_attr(get_option('sf_username')); ?>" />
                    </td>
                </tr>

                <tr>
                    <th><label for="sf_password">Password</label></th>
                    <td>
                        <input type="password" id="sf_password" name="sf_password"
                               value="<?php echo $mask; ?>" autocomplete="new-password" />
                    </td>
                </tr>

            </table>

            <?php submit_button(); ?>
        </form>
    </div>

    <?php
}


function getSalesforceParams_new() {

    $client_id     = get_option('sf_client_id');
    $client_secret = get_option('sf_client_secret');
    $username      = get_option('sf_username');
    $password      = get_option('sf_password');

    return "grant_type=password"
        . "&client_id=" . urlencode($client_id)
        . "&client_secret=" . urlencode($client_secret)
        . "&username=" . urlencode($username)
        . "&password=" . urlencode($password);
}