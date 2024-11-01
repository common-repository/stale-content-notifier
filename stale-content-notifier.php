<?php

/**
 * Plugin Name:     Stale Content Notifier
 * Description:     Automatically notify authors and administrators about posts that haven't been updated for a specified period.
 * Plugin URI:      https://plugin-bakery.com/stale-content-notifier
 * Author:          Plugin Bakery
 * Author URI:      https://plugin-bakery.com
 * Version:         1.0.0
 * License:         GPL2 or later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * 
 * @package         Stale_Content_Notifier
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Core Functions
 */
function scntfy_activate()
{
    // Check if the stale timeframe option exists, and if not, set a default
    if (!get_option('scntfy_stale_timeframe')) {
        update_option('scntfy_stale_timeframe', '90_days'); // Default to 90 days
    }
}
register_activation_hook(__FILE__, 'scntfy_activate');

function scntfy_deactivate()
{
    // Here, you can put any logic that needs to run when your plugin is deactivated.
    // For instance, if you wanted to delete the option upon deactivation (not recommended, just an example):
    // delete_option('scntfy_stale_timeframe');
}
register_deactivation_hook(__FILE__, 'scntfy_deactivate');

function scntfy_get_stale_content($timeframe = '90 days')
{

    // Set up the arguments for WP Query
    $args = array(
        'post_type'     => array('post', 'page'), // Check both posts and pages
        'post_status'   => 'publish', // Only published posts/pages
        'date_query'    => array(
            array(
                'before' => '-' . $timeframe
            ),
        ),
        'posts_per_page' => -1,
    );

    // Execute the query
    $stale_content_query = new WP_Query($args);

    // If there are matches, return them, otherwise return false
    if ($stale_content_query->have_posts()) {
        return $stale_content_query->posts;
    } else {
        return false;
    }
}

/**
 * Admin Notices
 */
function scntfy_display_admin_notice()
{
    // Use our previous function to get stale content
    $stale_posts = scntfy_get_stale_content(get_option('scntfy_days_until_stale') . ' days');

    // If there's stale content, display an admin notice
    if ($stale_posts && is_array($stale_posts) && count($stale_posts) > 0) {
        $count = count($stale_posts);

        $base_url = admin_url('tools.php');
        $params = array(
            'page' => 'scntfy_tools',
            'tab' => 'stale_content'
        );
        $link = add_query_arg($params, $base_url);

        // Compose the message
        $message = sprintf(
            _n( // Use _n() for singular/plural strings
                'You have %s post or page that hasn\'t been updated in a while.',
                'You have %s posts or pages that haven\'t been updated in a while.',
                $count,
                'stale-content-notifier' // Text domain for translations, if needed in the future
            ),
            number_format_i18n($count) // Format the number based on site settings
        );

        // Display the message in a notice
        echo '<div class="notice notice-warning is-dismissible"><p>'
            . esc_html($message) . '</p><p><a href='
            . esc_url($link) . '>View stale content</a></p></div>';
    }
}
add_action('admin_notices', 'scntfy_display_admin_notice');

/**
 * Email Notifications
 */
function scntfy_schedule_stale_content_check()
{
    if (!wp_next_scheduled('scntfy_daily_stale_content_check')) {
        wp_schedule_event(time(), 'daily', 'scntfy_daily_stale_content_check');
    }
}
add_action('wp', 'scntfy_schedule_stale_content_check');

function scntfy_send_stale_content_email()
{
    if (get_option('scntfy_enable_email_notifications') != '1') {
        return;
    }

    // Fetch the stale content
    $stale_posts = scntfy_get_stale_content(get_option('scntfy_days_until_stale') . ' days');

    if ($stale_posts && is_array($stale_posts) && count($stale_posts) > 0) {
        $count = count($stale_posts);

        // Define the email subject and body
        $to = get_option('scntfy_notification_email', get_option('admin_email'));
        $headers = 'From: WordPress <' . get_option('admin_email') . ">\r\n";
        $subject = sprintf(__('You have %s stale posts/pages on your website', 'stale-content-notifier'), $count);
        $body = "Hello,\n\n";
        $body .= "You have the following stale content on your website:\n\n";

        foreach ($stale_posts as $post) {
            $body .= get_the_title($post) . ": " . get_permalink($post) . "\n";
        }

        $body .= "\nPlease review and update them as necessary.\n";
        $body .= "Thank you!";

        // Send the email
        wp_mail($to, $subject, $body, $headers);
    }
}
add_action('scntfy_daily_stale_content_check', 'scntfy_send_stale_content_email');


/**
 * Table Display & Management
 */
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class SCNTFY_Stale_Content_Table extends WP_List_Table
{

    function __construct()
    {
        parent::__construct(array(
            'singular' => 'stale_content',
            'plural'   => 'stale_contents',
            'ajax'     => false
        ));
    }

    function get_columns()
    {
        return array(
            'title' => 'Title',
            'date'  => 'Date Published'
        );
    }

    function prepare_items()
    {
        $columns = $this->get_columns();
        $this->_column_headers = array($columns, array(), array());

        $args = array(
            'post_type'     => array('post', 'page'), // Check both posts and pages
            'post_status'   => 'publish', // Only published posts/pages
            'date_query'    => array(
                array(
                    'before' => sprintf('-%d days', get_option('scntfy_days_until_stale', 90))
                ),
            ),
            'posts_per_page' => -1,
        );
        $query = new WP_Query($args);
        $this->items = $query->posts;
    }

    function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'title':
                return sprintf('<a href="%s">%s</a>', get_edit_post_link($item->ID), $item->post_title);
            case 'date':
                return get_the_date('', $item);
            default:
                return print_r($item, true);
        }
    }
}

/**
 * Admin Pages (Settings & Tools)
 */
function scntfy_add_to_tools_menu()
{
    add_management_page(
        'Stale Content Notifier',       // Page title
        'Stale Content Notifier',       // Menu title
        'manage_options',               // Capability
        'scntfy_tools',                    // Menu slug
        'scntfy_display_tools_page'        // Callback function
    );
}
add_action('admin_menu', 'scntfy_add_to_tools_menu');

function scntfy_display_tools_page()
{
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }

    // Save settings if form is submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Use nonces for security
        check_admin_referer('scntfy_save_settings');

        // Validate and save the settings
        if (isset($_POST['scntfy_days_until_stale'])) {
            $days_until_stale = sanitize_text_field($_POST['scntfy_days_until_stale']);
            if (is_numeric($days_until_stale)) {
                update_option('scntfy_days_until_stale', intval($days_until_stale));
            }
        }

        $enable_email_notifications = isset($_POST['scntfy_enable_email_notifications']) ? '1' : '0';
        update_option('scntfy_enable_email_notifications', $enable_email_notifications);

        if (isset($_POST['scntfy_notification_email'])) {
            $notification_email = sanitize_email($_POST['scntfy_notification_email']);
            if (is_email($notification_email)) {
                update_option('scntfy_notification_email', $notification_email);
            }
        }

        // Output an admin notice on successful save
        echo '<div class="updated"><p><strong>Settings saved.</strong></p></div>';
    }

    // Load current setting values
    $days_until_stale = get_option('scntfy_days_until_stale', 90); // Default to 90 days
    $enable_email_notifications = get_option('scntfy_enable_email_notifications', '1'); // Default to enabled
    $notification_email = get_option('scntfy_notification_email', get_option('admin_email'));

    // Display the settings form
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'stale_content';
?>
    <div class="wrap">
        <h2>Stale Content Notifier</h2>
        <h2 class="nav-tab-wrapper">
            <a href="?page=scntfy_tools&tab=stale_content" class="nav-tab <?php echo $active_tab == 'stale_content' ? 'nav-tab-active' : ''; ?>">Stale Content</a>
            <a href="?page=scntfy_tools&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
        </h2>

        <?php if ($active_tab == 'stale_content') : ?>
            <!-- STALE CONTENT TAB CONTENT -->
            <?php
            $staleContentTable = new SCNTFY_Stale_Content_Table();
            $staleContentTable->prepare_items();
            $staleContentTable->display();
            ?>
        <?php endif; ?>

        <?php if ($active_tab == 'settings') : ?>
            <!-- SETTINGS TAB CONTENT -->
            <form method="post" action="">
                <?php wp_nonce_field('scntfy_save_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="scntfy_days_until_stale">Days Until Content is Stale:</label></th>
                        <td><input type="number" id="scntfy_days_until_stale" name="scntfy_days_until_stale" value="<?php echo esc_attr($days_until_stale); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Enable Email Notifications:</th>
                        <td>
                            <input type="checkbox" id="scntfy_enable_email_notifications" name="scntfy_enable_email_notifications" <?php checked($enable_email_notifications, '1'); ?> />
                            <label for="scntfy_enable_email_notifications">Enable</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="scntfy_notification_email">Notification Email Address:</label></th>
                        <td><input type="email" id="scntfy_notification_email" name="scntfy_notification_email" value="<?php echo esc_attr($notification_email); ?>" /></td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" class="button-primary" value="Save Changes" />
                </p>
            </form>
        <?php endif; ?>

    </div>
<?php
}
