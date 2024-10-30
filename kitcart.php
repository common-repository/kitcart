<?php
/**
 * @package Kitcart
 */
/*
Plugin Name: Kitcart
Plugin URI: https://kitcart.net/
Description: Kitcart is a cloud-based ecommerce platform for online stores and retail point-of-sale systems that helps modern businesses sell anything to anyone anywhere.
Version: 2.1.1
Author: Kitcart
Contributors: Elon Jobs, Bill Tracy, John Wills, Josh Tracy, Dan Smith, Joseph Victor
Author URI: https://kitcart.net/plugins/
License: GPLv3
Text Domain: kitcart
 */

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

Copyright 2020-2022 Kitcart Technology, Inc.
 */

// Make sure we don't expose any info if called directly
if (!function_exists('add_action')) {
    $items[] = 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
    exit;
}

define('KITCART_VERSION', '2.1.1');
define('KITCART__MINIMUM_WP_VERSION', '5.0');
define('KITCART__PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KITCART_DELETE_LIMIT', 100000);
define('KITCART_API_URI', 'https://kitcart.net/api/wordpress/');

// add settings link
add_filter('plugin_action_links_kitcart/kitcart.php', 'kitcart_settings_link');
function kitcart_settings_link($links)
{
    // Build and escape the URL.
    $url = esc_url(add_query_arg(
        ['page' =>
        'wc-settings', 'tab' => 'kitcart'],
        get_admin_url() . 'admin.php'
    ));
    // Create the link.
    $settings_link = "<a href='$url'>" . __('Settings') . '</a>';
    // Adds the link to the end of the array.
    array_push(
        $links,
        $settings_link
    );
    return $links;
}

//check if woocommerce is installed
add_action('admin_init', 'kitcart_wc_check');
function kitcart_wc_check()
{

    if (class_exists('woocommerce')) {
        global $kitcart_wc_active;
        $kitcart_wc_active = 'yes';
    } else {

        global $kitcart_wc_active;
        $kitcart_wc_active = 'no';
    }
}

// show admin notice if WooCommerce is not activated
add_action('admin_notices', 'kitcart_wc_admin_notice');
function kitcart_wc_admin_notice()
{

    global $kitcart_wc_active;

    if ($kitcart_wc_active == 'no') {
?>

        <div class="notice notice-error is-dismissible">
            <p>WooCommerce is not activated, please activate it to use <b>Kitcart Plugin</b></p>
        </div>
    <?php

    }
}

//hookings
register_activation_hook(__FILE__, 'activate_kitcart');
add_action('activated_plugin', 'kitcart_activated_action');
register_uninstall_hook(__FILE__, 'uninstall_kitcart');
register_deactivation_hook(__FILE__, 'deactivate_kitcart');

// kitcart plugin action function to report plugin activation
function kitcart_plugin_action($action)
{
    $route = 'action';
    $body = array(

        'public_key' => get_option('kitcart_public_key'),
        'secret_key' => get_option('kitcart_secret_key'),
        'action' => $action,
    );

    $req_args = array(
        'body'        => $body,
        'timeout'     => '60',
        'redirection' => '5',
        'httpversion' => '1.0',
        'blocking'    => true,
        'headers'     => array(
            'accept' => "application/json",
        ),
        'cookies'     => array(
            'XSRF-TOKEN' => 'woocommerce-api-request',
            'kitcart_session' => 'woocommerce'
        ),
    );
    try {
        $response = wp_remote_post(KITCART_API_URI . $route, $req_args);
        $body = wp_remote_retrieve_body($response);
        $body = json_decode($body);
        if ($body->status == 'success') {
            return true;
        } else {
            return false;
        }
    } catch (\Throwable $th) {
        return false;
    }
}

function submit_all_successful_orders()
{
    // empty array to store all successful orders
    $all_order_data = array();
    //route
    $route = 'submit-all-successful-orders';
    // get all orders from database -- completed and processing
    $args = array(
        'post_type' => 'shop_order',
        'post_status' => array('wc-completed', 'wc-processing'),
        'posts_per_page' => -1,
    );
    $orders = get_posts($args);
    // loop through orders
    foreach ($orders as $order) {
        $order_id = $order->ID;
        $order = wc_get_order($order_id);
        //get order created date
        $items = $order->get_items();
        $items_data = [];
        foreach ($items as $item_key => $item) {
            $items_data[] = $item->get_data();
        }

        $order_data = array(
            'order_id' => $order_id,
            'product_data' => $items_data,
            'order_data' => $order->get_data(),
            'created_at' => $order->get_date_created()->date('Y-m-d H:i:s'),
            'updated_at' => $order->get_date_modified()->date('Y-m-d H:i:s'),
        );
        $all_order_data[] = $order_data;
    }

    $body = array(
        'public_key' => get_option('kitcart_public_key'),
        'secret_key' => get_option('kitcart_secret_key'),
        'orders' => $all_order_data,
        'action' => 'submit-all-successful-orders',
        'status' => 'success',
    );
    $req_args = array(
        'body'        => $body,
        'timeout'     => '300',
        'redirection' => '5',
        'httpversion' => '1.0',
        'blocking'    => true,
        'headers'     => array(
            'accept' => "application/json",
        ),
        'cookies'  => array(
            'XSRF-TOKEN' => 'woocommerce-api-request',
            'kitcart_session' => 'woocommerce'
        ),
    );
    $response = wp_remote_post(KITCART_API_URI . $route, $req_args);
}

// action functions and datas
function activate_kitcart()
{
    //value to be change on first update
    if (get_option('kitcart_secret_key') && get_option('kitcart_public_key')) {
        submit_all_successful_orders();
    } else {
        add_option('kitcart_redirect_to_settings', 'yes');
    }
}

//redirect after activation to settings page
function kitcart_activated_action($plugin)
{
    //if the user has updated secret keys before, the value will be NO
    if (class_exists('woocommerce')) {
        if (get_option('kitcart_redirect_to_settings') === 'yes') {
            if ($plugin == plugin_basename(__FILE__)) {
                exit(wp_redirect(admin_url('/admin.php?page=wc-settings&tab=kitcart')));
            }
        }
    }
}

function deactivate_kitcart()
{
    // let kitcart know that the plugin is deactivated
    kitcart_plugin_action('deactivate');
}

//Delete all the values when user uninstall plugin 
function uninstall_kitcart()
{
    if (get_option('kitcart_secret_key')) {
        // delete all the values
        if (kitcart_plugin_action('uninstall')) {
            delete_option('kitcart_public_key');
            delete_option('kitcart_secret_key');
            delete_option('kitcart_redirect_to_settings');
        } else {
            return;
        }
    }
}

// Add a custom setting tab to Woocommerce > Settings section
add_action('woocommerce_settings_tabs', 'wc_settings_tabs_kitcart_tab');
function wc_settings_tabs_kitcart_tab()
{
    $current_tab = (isset($_GET['tab']) && $_GET['tab'] === 'kitcart') ? 'nav-tab-active' : '';
    echo '<a href="admin.php?page=wc-settings&tab=kitcart" class="nav-tab ' . esc_attr($current_tab) . '">' . __("Kitcart", "woocommerce") . '</a>';
}


// The setting tab content
add_action('woocommerce_settings_kitcart', 'display_kitcart_api_form');
function display_kitcart_api_form()
{

    if (
        isset($_POST['kitcart_public_key']) && isset($_POST['kitcart_secret_key']) &&
        is_string($_POST['kitcart_public_key']) && is_string($_POST['kitcart_secret_key'])
    ) {

        if (get_option('kitcart_secret_key')) {
            update_option('kitcart_public_key', sanitize_text_field($_POST['kitcart_public_key']));
            update_option('kitcart_secret_key', sanitize_text_field($_POST['kitcart_secret_key']));
            //make kitcart api call to update the keys
            if (kitcart_plugin_action('activate')) {
                $message = '<div class="notice notice-success is-dismissible"><p>' . __('Your keys have been updated successfully', 'woocommerce') . '</p></div>';
            } else {
                $message = '<div class="notice notice-error is-dismissible"><p>' . __('Your keys could not be updated', 'woocommerce') . '</p></div>';
            }
        } else {
            update_option('kitcart_redirect_to_settings', 'no');
            add_option('kitcart_public_key', sanitize_text_field($_POST['kitcart_public_key']));
            add_option('kitcart_secret_key', sanitize_text_field($_POST['kitcart_secret_key']));
            //make kitcart api call to create record of all successful orders
            submit_all_successful_orders();
            // make kitcart aware of the activation
            if (kitcart_plugin_action('activate')) {
                $message = '<div class="notice notice-success is-dismissible"><p>' . __('Your API keys have been updated successfully', 'woocommerce') . '</p></div>';
            } else {
                $message = '<div class="notice notice-error is-dismissible"><p>' . __('Your API keys could not be updated.', 'woocommerce') . '</p></div>';
            }
        }
    } else {
        $message = '<div class="notice notice-error is-dismissible"><p>' . __('Please enter valid keys', 'woocommerce') . '</p></div>';
    }
    $public_key = get_option('kitcart_public_key') ?? "";
    $secret_key = get_option('kitcart_secret_key') ?? '';
    echo $message

    ?>
    <h2>Kitcart API Keys</h2>
    <table class="form-table">
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="kitcart_public_key">Public Key <span class="woocommerce-help-tip"></span></label>
            </th>
            <td class="forminp forminp-text">
                <input name="kitcart_public_key" id="kitcart_public_key" type="text" value="<?php echo esc_attr($public_key); ?>" class="" placeholder="Enter your Kitcart API public key">
            </td>
        </tr>

        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="kitcart_secret_key">Private Key<span class="woocommerce-help-tip"></span></label>
            </th>
            <td class="forminp forminp-text">
                <input name="kitcart_secret_key" id="kitcart_secret_key" type="text" value="<?php echo esc_attr($secret_key); ?>" class="" placeholder="Enter Kitcart API private key">
            </td>
        </tr>
    </table>
<?php
}

// The Final Order Sending part
add_action('woocommerce_payment_complete', 'create_new_order_on_kitcart', 10);
add_action('woocommerce_order_status_completed', 'create_new_order_on_kitcart', 10);
add_action('woocommerce_order_status_processing', 'create_new_order_on_kitcart', 10);
add_action('woocommerce_order_status_on-hold', 'create_new_order_on_kitcart', 10);
add_action('woocommerce_order_status_pending', 'create_new_order_on_kitcart', 10);
add_action('woocommerce_order_status_failed', 'create_new_order_on_kitcart', 10);
add_action('woocommerce_order_status_cancelled', 'create_new_order_on_kitcart', 10);
add_action('woocommerce_order_status_refunded', 'create_new_order_on_kitcart', 10);
add_action('woocommerce_order_status_processing_to_completed', 'create_new_order_on_kitcart', 10);
add_action('woocommerce_order_status_completed_to_processing', 'create_new_order_on_kitcart', 10);
add_action('woocommerce_order_status_completed_to_on-hold', 'create_new_order_on_kitcart', 10);
add_action('woocommerce_order_status_completed_to_pending', 'create_new_order_on_kitcart', 10);
function create_new_order_on_kitcart($order_id)
{
    $route = "create-order";

    if (class_exists('woocommerce')) {
        $order = wc_get_order($order_id);
        $items = $order->get_items();
        $items_data = [];

        foreach ($items as $item_key => $item) {
            $items_data[] = $item->get_data();
        }
        $body = array(

            'public_key' => get_option('kitcart_public_key'),
            'secret_key' => get_option('kitcart_secret_key'),
            'order_data' => $order->get_data(),
            'product_data' => $items_data,
            'order_id' => $order_id,
            'created_at' => $order->get_date_created()->date('Y-m-d H:i:s'),
            'updated_at' => $order->get_date_modified()->date('Y-m-d H:i:s'),

        );

        $req_args = array(
            'body'        => $body,
            'timeout'     => '5',
            'redirection' => '5',
            'httpversion' => '1.0',
            'blocking'    => true,
            'headers'     => array(
                'accept' => "application/json",
            ),
            'cookies'     => array(
                'XSRF-TOKEN' => 'woocommerce-api-request',
                'kitcart_session' => 'woocommerce'
            ),
        );

        $response = wp_remote_post(KITCART_API_URI . $route, $req_args);
    }
}
