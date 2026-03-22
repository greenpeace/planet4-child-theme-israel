<?php
/*
Plugin Name: PayPlus Parameters
Description: Admin menu for PayPlus API parameters (api_key, secret_key, debug_level, payment_page_uid)
Version: 1.1
Author: Ofer Or
18-3-2026
*/

// ------------------------------------------------------
// 1. Create Settings Submenu Page
// ------------------------------------------------------
add_action('admin_menu', 'ppparams_create_settings_submenu');
function ppparams_create_settings_submenu() {
    add_options_page(
        'PayPlus Parameters',
        'PayPlus Parameters',
        'manage_options',
        'payplus-parameters',
        'ppparams_settings_page_html'
    );
}

// ------------------------------------------------------
// 2. Register Settings
// ------------------------------------------------------
add_action('admin_init', 'ppparams_register_settings');
function ppparams_register_settings() {

    register_setting('ppparams_settings_group', 'pp_api_key');

    register_setting('ppparams_settings_group', 'pp_secret_key', [
        'sanitize_callback' => 'ppparams_masked_password_sanitizer'
    ]);

    register_setting('ppparams_settings_group', 'pp_debug_level');

    // NEW: Payment Page UID
    register_setting('ppparams_settings_group', 'pp_payment_page_uid');
}

// ------------------------------------------------------
// 3. Sanitizer for masked passwords
// ------------------------------------------------------
function ppparams_masked_password_sanitizer($value) {

    $mask = '**************';
    $option = str_replace('sanitize_option_', '', current_filter());

    if ($value === $mask || empty($value)) {
        return get_option($option);
    }

    return $value;
}

// ------------------------------------------------------
// 4. Settings Page HTML
// ------------------------------------------------------
function ppparams_settings_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $mask = '**************';
    ?>

    <div class="wrap">
        <h1>PayPlus API Parameters</h1>

        <form method="post" action="options.php">
            <?php settings_fields('ppparams_settings_group'); ?>

            <table class="form-table">

                <tr>
                    <th><label for="pp_api_key">API Key</label></th>
                    <td>
                        <input type="text" id="pp_api_key" name="pp_api_key"
                               value="<?php echo esc_attr(get_option('pp_api_key')); ?>" />
                    </td>
                </tr>

                <tr>
                    <th><label for="pp_secret_key">Secret Key</label></th>
                    <td>
                        <input type="password" id="pp_secret_key" name="pp_secret_key"
                               value="<?php echo $mask; ?>" autocomplete="new-password" />
                    </td>
                </tr>

                <tr>
                    <th><label for="pp_debug_level">Debug Level</label></th>
                    <td>
                        <input type="text" id="pp_debug_level" name="pp_debug_level"
                               value="<?php echo esc_attr(get_option('pp_debug_level')); ?>" />
                    </td>
                </tr>

                <!-- NEW: Payment Page UID -->
                <tr>
                    <th><label for="pp_payment_page_uid">Payment Page UID</label></th>
                    <td>
                        <input type="text" id="pp_payment_page_uid" name="pp_payment_page_uid"
                               value="<?php echo esc_attr(get_option('pp_payment_page_uid')); ?>" />
                    </td>
                </tr>

            </table>

            <?php submit_button(); ?>
        </form>
    </div>

    <?php
}

// ------------------------------------------------------
// 5. Helper functions
// ------------------------------------------------------
function getPayPlusParams() {
    return [
        'api_key'    => get_option('pp_api_key'),
        'secret_key' => get_option('pp_secret_key'),
    ];
}

function getDebugLevelParam() {
    return (int) get_option('pp_debug_level');
}

// NEW: Payment Page UID getter
function getPaymentPageUidParam() {
    return get_option('pp_payment_page_uid');
}