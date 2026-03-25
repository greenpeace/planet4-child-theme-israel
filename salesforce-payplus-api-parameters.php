<?php
/*
Plugin Name: API Parameters (SalesForce + PayPlus)
Description: Unified admin settings page for Salesforce and PayPlus API parameters.
Version: 2.0
Author: Ofer Or
25-3-2026
*/

/* ------------------------------------------------------
 * 1. Create Settings Submenu Page
 * ------------------------------------------------------ */
add_action('admin_menu', 'api_params_create_settings_submenu');
function api_params_create_settings_submenu() {
    add_options_page(
        'SalesForce & PayPlus Parameters',
        'SalesForce & PayPlus Parameters',
        'manage_options',
        'api-parameters',
        'api_params_settings_page_html'
    );
}

/* ------------------------------------------------------
 * 2. Register Settings
 * ------------------------------------------------------ */
add_action('admin_init', 'api_params_register_settings');
function api_params_register_settings() {

    /* Salesforce */
    register_setting('api_params_settings_group', 'sf_client_id');
    register_setting('api_params_settings_group', 'sf_username');

    register_setting('api_params_settings_group', 'sf_client_secret', [
        'sanitize_callback' => 'api_params_masked_password_sanitizer'
    ]);

    register_setting('api_params_settings_group', 'sf_password', [
        'sanitize_callback' => 'api_params_masked_password_sanitizer'
    ]);

    /* PayPlus */
    register_setting('api_params_settings_group', 'pp_api_key');

    register_setting('api_params_settings_group', 'pp_secret_key', [
        'sanitize_callback' => 'api_params_masked_password_sanitizer'
    ]);

    register_setting('api_params_settings_group', 'pp_debug_level', [
        'sanitize_callback' => 'api_params_sanitize_debug_level',
        'default'           => 'none',
    ]);

    register_setting('api_params_settings_group', 'pp_payment_page_uid');
	
	register_setting('api_params_settings_group', 'pp_env_type', [
		'sanitize_callback' => 'api_params_sanitize_env_type',
		'default'           => 'testing',
	]);
}

/* ------------------------------------------------------
 * 3. Sanitizers
 * ------------------------------------------------------ */
function api_params_masked_password_sanitizer($value) {
    $mask = '**************';
    $option = str_replace('sanitize_option_', '', current_filter());

    if ($value === $mask || empty($value)) {
        return get_option($option);
    }
    return $value;
}

function api_params_sanitize_debug_level($value) {
    $allowed = ['none', 'debug', 'panic'];
    $value = strtolower(trim((string)$value));
    return in_array($value, $allowed, true) ? $value : 'none';
}

function api_params_sanitize_env_type($value) {
    $allowed = ['production', 'testing'];
    $value = strtolower(trim((string)$value));
    return in_array($value, $allowed, true) ? $value : 'testing';
}

/* ------------------------------------------------------
 * 4. Settings Page HTML
 * ------------------------------------------------------ */
function api_params_settings_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $mask = '**************';
    ?>

    <div class="wrap">
        <h1>SalesForce & PayPlus Parameters</h1>

        <form method="post" action="options.php">
            <?php settings_fields('api_params_settings_group'); ?>

            <h2>SalesForce Parameters</h2>
            <table class="form-table">

                <tr>
                    <th><label for="sf_client_id">Client ID</label></th>
                    <td><input type="text" id="sf_client_id" name="sf_client_id"
                               value="<?php echo esc_attr(get_option('sf_client_id')); ?>"></td>
                </tr>

                <tr>
                    <th><label for="sf_client_secret">Client Secret</label></th>
                    <td><input type="password" id="sf_client_secret" name="sf_client_secret"
                               value="<?php echo $mask; ?>" autocomplete="new-password"></td>
                </tr>

                <tr>
                    <th><label for="sf_username">Username</label></th>
                    <td><input type="text" id="sf_username" name="sf_username"
                               value="<?php echo esc_attr(get_option('sf_username')); ?>"></td>
                </tr>

                <tr>
                    <th><label for="sf_password">Password</label></th>
                    <td><input type="password" id="sf_password" name="sf_password"
                               value="<?php echo $mask; ?>" autocomplete="new-password"></td>
                </tr>

            </table>

            <hr>

            <h2>PayPlus Parameters</h2>
            <table class="form-table">

                <tr>
                    <th><label for="pp_api_key">API Key</label></th>
                    <td><input type="text" id="pp_api_key" name="pp_api_key"
                               value="<?php echo esc_attr(get_option('pp_api_key')); ?>"></td>
                </tr>

                <tr>
                    <th><label for="pp_secret_key">Secret Key</label></th>
                    <td><input type="password" id="pp_secret_key" name="pp_secret_key"
                               value="<?php echo $mask; ?>" autocomplete="new-password"></td>
                </tr>

                <tr>
                    <th><label for="pp_payment_page_uid">Payment Page UID</label></th>
                    <td><input type="text" id="pp_payment_page_uid" name="pp_payment_page_uid"
                               value="<?php echo esc_attr(get_option('pp_payment_page_uid')); ?>"></td>
                </tr>

            </table>

            <hr>

            <h2>Other Parameters</h2>
            <table class="form-table">

				<tr>
					<th><label for="pp_env_type">Environment Type</label></th>
					<td>
						<?php $env = get_option('pp_env_type', 'testing'); ?>
						<select id="pp_env_type" name="pp_env_type">
							<option value="production" <?php selected($env, 'production'); ?>>Production</option>
							<option value="testing" <?php selected($env, 'testing'); ?>>Testing</option>
						</select>
					</td>
				</tr>

                <tr>
                    <th><label for="pp_debug_level">Debug Level</label></th>
                    <td>
                        <?php $pp_debug_level = get_option('pp_debug_level', 'none'); ?>
                        <select id="pp_debug_level" name="pp_debug_level">
                            <option value="none"  <?php selected($pp_debug_level, 'none'); ?>>None</option>
                            <option value="debug" <?php selected($pp_debug_level, 'debug'); ?>>Debug</option>
                            <option value="panic" <?php selected($pp_debug_level, 'panic'); ?>>Panic</option>
                        </select>
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
    $v = get_option('pp_debug_level', 'none');
    return in_array($v, array('none', 'debug', 'panic'), true) ? $v : 'none';
}

// NEW: Payment Page UID getter
function getPaymentPageUidParam() {
    return get_option('pp_payment_page_uid');
}

/**
 * Global logger used to replace legacy error_log(...) calls.
 *
 * Rules (based on pp_debug_level):
 * - level=panic -> log only when setting=panic
 * - level=debug -> log when setting=debug OR setting=panic
 * - level other -> always log
 *
 * @param string $level
 * @param mixed  $message
 */
function debug_log($level, $message) {
    $setting = getDebugLevelParam();
    $level_key = strtolower(is_string($level) ? sanitize_key($level) : '');

    $log_it = false;
    if ($level_key === 'panic') {
        $log_it = ($setting === 'panic');
    } elseif ($level_key === 'debug') {
        $log_it = ($setting === 'debug' || $setting === 'panic');
    } elseif ($level_key !== 'panic' && $level_key !== 'debug') {
        $log_it = true;
    }

    if ($log_it) {
        $line = is_string($message) ? $message : print_r($message, true);
        error_log($line . PHP_EOL);
    }
}


function getSalesforceParams() {

    return [
        'grant_type'    => 'password',
        'client_id'     => get_option('sf_client_id'),
        'client_secret' => get_option('sf_client_secret'),
        'username'      => get_option('sf_username'),
        'password'      => get_option('sf_password'),
    ];
}

function getSalesforceEnvType() {
    return get_option('pp_env_type', 'testing'); // default: testing
}