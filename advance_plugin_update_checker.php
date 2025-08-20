<?php
/*
Plugin Name: WP-Plugin Update Checker
Description: Custom plugin to check WordPress plugin updates manually, weekly, or monthly, and send reports to selected emails.
Version: 1.0
Author: Nimesh
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ============================
 * 1. Add Admin Menu in Dashboard
 * ============================
 */
add_action('admin_menu', 'pucr_add_admin_menu');
function pucr_add_admin_menu() {
    add_menu_page(
        'Plugin Update Checker',   // Page title
        'WP-Update Checker',          // Menu title in dashboard
        'manage_options',          // Capability
        'plugin-update-checker',   // Menu slug
        'pucr_settings_page',      // Callback to render page
        'dashicons-update',        // Icon
        100                        // Position
    );
}

/**
 * ============================
 * 2. Register Settings (store emails & frequency)
 * ============================
 */
add_action('admin_init', 'pucr_register_settings');
function pucr_register_settings() {
    register_setting('pucr_settings_group', 'pucr_emails');      // store emails
    register_setting('pucr_settings_group', 'pucr_frequency');   // store frequency
}

/**
 * ============================
 * 3. Admin Settings Page
 * ============================
 */
function pucr_settings_page() {
    ?>
    <div class="wrap">
        <h1>Plugin Update Checker</h1>
        <form method="post" action="options.php">
            <?php settings_fields('pucr_settings_group'); ?>
            <?php do_settings_sections('pucr_settings_group'); ?>

            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Report Emails</th>
                    <td>
                        <input type="text" name="pucr_emails" 
                               value="<?php echo esc_attr(get_option('pucr_emails', 'your@email.com')); ?>" 
                               class="regular-text" />
                        <p class="description">Enter one or more email addresses, separated by commas.</p>
                    </td>
                </tr>

                <tr valign="top">
                    <th scope="row">Check Frequency</th>
                    <td>
                        <?php $frequency = get_option('pucr_frequency', 'weekly'); ?>
                        <select name="pucr_frequency">
                            <option value="weekly" <?php selected($frequency, 'weekly'); ?>>Weekly</option>
                            <option value="monthly" <?php selected($frequency, 'monthly'); ?>>Monthly</option>
                            <option value="manual" <?php selected($frequency, 'manual'); ?>>Manual Only</option>
                        </select>
                    </td>
                </tr>
            </table>

            <?php submit_button('Save Settings'); ?>
        </form>

        <hr>

        <h2>Manual Update Report</h2>
        <form method="post">
            <input type="hidden" name="pucr_manual_check" value="1" />
            <?php submit_button('Run Update Check Now'); ?>
        </form>
    </div>
    <?php
}

/**
 * ============================
 * 4. Run Manual Check from UI
 * ============================
 */
add_action('admin_init', 'pucr_manual_check_handler');
function pucr_manual_check_handler() {
    if (isset($_POST['pucr_manual_check'])) {
        pucr_send_update_report();
        add_action('admin_notices', function() {
            echo '<div class="updated"><p>Update report sent successfully!</p></div>';
        });
    }
}

/**
 * ============================
 * 5. Cron Jobs (Weekly/Monthly)
 * ============================
 */
add_action('pucr_cron_event', 'pucr_send_update_report');

// Schedule events on activation
register_activation_hook(__FILE__, 'pucr_activation');
function pucr_activation() {
    if (!wp_next_scheduled('pucr_cron_event')) {
        wp_schedule_event(time(), 'weekly', 'pucr_cron_event');
    }
}

// Clear events on deactivation
register_deactivation_hook(__FILE__, 'pucr_deactivation');
function pucr_deactivation() {
    wp_clear_scheduled_hook('pucr_cron_event');
}

// Adjust frequency dynamically
add_action('init', 'pucr_adjust_schedule');
function pucr_adjust_schedule() {
    $frequency = get_option('pucr_frequency', 'weekly');
    wp_clear_scheduled_hook('pucr_cron_event');

    if ($frequency === 'weekly') {
        wp_schedule_event(time(), 'weekly', 'pucr_cron_event');
    } elseif ($frequency === 'monthly') {
        wp_schedule_event(time(), 'monthly', 'pucr_cron_event');
    }
}

/**
 * ============================
 * 6. Send Update Report
 * ============================
 */
function pucr_send_update_report() {
    // Load plugin data
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    $all_plugins = get_plugins();
    $update_plugins = get_site_transient('update_plugins');
    $emails = explode(',', get_option('pucr_emails', get_option('admin_email')));

    $updates = [];
    if (!empty($update_plugins->response)) {
        foreach ($update_plugins->response as $plugin_file => $plugin_data) {
            if (isset($all_plugins[$plugin_file])) {
                $updates[] = [
                    'name'         => $all_plugins[$plugin_file]['Name'],
                    'current'      => $all_plugins[$plugin_file]['Version'],
                    'new'          => $plugin_data->new_version,
                ];
            }
        }
    }

    // Build HTML Email
    $subject = 'WordPress Plugin Update Report';
    $message = '<h2>WordPress Plugin Update Report</h2>';
    if ($updates) {
        $message .= '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse; width:100%;">';
        $message .= '<tr><th align="left">Plugin</th><th>Current Version</th><th>New Version</th></tr>';
        foreach ($updates as $plugin) {
            $message .= "<tr>
                            <td>{$plugin['name']}</td>
                            <td>{$plugin['current']}</td>
                            <td><strong>{$plugin['new']}</strong></td>
                         </tr>";
        }
        $message .= '</table>';
    } else {
        $message .= '<p>✅ All plugins are up to date.</p>';
    }

    // Send to each email
    foreach ($emails as $email) {
        wp_mail(trim($email), $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
    }
}

/**
 * ============================
 * 7. Add Monthly Interval for WP-Cron
 * ============================
 */
add_filter('cron_schedules', function($schedules) {
    if (!isset($schedules['monthly'])) {
        $schedules['monthly'] = [
            'interval' => 2592000, // 30 days
            'display'  => __('Once Monthly')
        ];
    }
    return $schedules;
});