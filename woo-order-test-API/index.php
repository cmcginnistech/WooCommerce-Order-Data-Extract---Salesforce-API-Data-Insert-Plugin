<?php
/**
 * Plugin Name: WooCommerce Order Data SF API Example
 * Description: Extracts WooCommerce order data after a successful payment and sends to 3rd party vendor VIA API like SalesForce.
 * Version: 1.0
 * Author: Chris McGinnis
 * Author URI: https://cmcginnis.tech
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// set our SalesForce API Credentials
define('CLIENT_ID', 'your_salesforce_client_id');
define('CLIENT_SECRET', 'your_salesforce_client_secret');
define('SF_USER', 'your_salesforce_username');
define('SF_PASSWORD', 'your_salesforce_password_with_security_token');


// Create needed stuff upon plugin activation
register_activation_hook( __FILE__, 'create_woo_orders_test_api_directory' );

// create log directory
function create_woo_orders_test_api_directory() {
    $dir = WP_CONTENT_DIR . '/woo-orders-test-API';

    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }
}


// Salesforce oAuth Generate
function sf_oauth() {
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://login.salesforce.com/services/oauth2/token',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => 'grant_type=password&client_id='.CLIENT_ID.'&client_secret='.CLIENT_SECRET.'&username='.SF_USER.'&password='.SF_PASSWORD,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded'
        ),
    ));

    $tokenresponse = curl_exec($curl);
	
	// something went wrong..
    if (curl_errno($curl)) {
        error_log('Salesforce OAuth Error:' . curl_error($curl));
        curl_close($curl);
        return false; 
    }
    curl_close($curl);

    $decoded = json_decode($tokenresponse, true);
    if (isset($decoded['access_token'])) {
		// return our token
        return $decoded['access_token'];
    } else {
        error_log('Salesforce OAuth Error: Invalid response');
        return false;
    }
}


// Trigger our Order Log/Send action on WooCommerce Confirmation Page
add_action( 'woocommerce_thankyou', 'extract_order_data' );

function extract_order_data( $order_id ) {

    // log file path
	$dir = WP_CONTENT_DIR . '/woo-orders-test-API';
    $log_file_path = $dir . '/order_data_log_' . $order_id . '.txt';
	
	// Check if the directory exists if not... add it
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    // order object
    $order = wc_get_order( $order_id );
    
	
    // order details
    $full_name = $order->get_formatted_billing_full_name(); // or use get_shipping_first_name() and get_shipping_last_name() for shipping name
    $shipping_address = $order->get_formatted_shipping_address();
    $products_purchased = array();

	// get products
    foreach ( $order->get_items() as $item_id => $item ) {
        $products_purchased[] = $item->get_name();
    }

    // Format the data into a string
    $log_entry = "Order ID: $order_id\n" .
                 "Full Name: $full_name\n" .
                 "Shipping Address: $shipping_address\n" .
                 "Products Purchased: " . implode(', ', $products_purchased) . "\n" .
                 "-----------------------------------\n";

    // log the order info
    file_put_contents( $log_file_path, $log_entry, FILE_APPEND | LOCK_EX );
	
	
	// Get OAuth token for SalesForce
    $access_token = sf_oauth();
    if ( ! $access_token ) {
        // Handle the error, maybe log it or send an admin notification
        return;
    }

    // Here our API endpoint would be set
    $api_url = 'https://example.com/api/endpoint';
    $order_data = [
		'orderID' => $order_id,
        'full_name' => $full_name,
		'shipping_addr' => $shipping_address
    ];

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $api_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($order_data),
        CURLOPT_HTTPHEADER => array(
            "Authorization: Bearer $access_token",
            'Content-Type: application/json'
        ),
    ));
	//send the data
    $response = curl_exec($curl);
    if (curl_errno($curl)) {
        // Something went wrong... could log here
    }
	//close our connection
    curl_close($curl);

}




/* 

SNIPPET EXAMPLE OF GETTING DATA FROM SALESFORCE USING QUERY I HAVE CODED..

*/

/*

function sf_event_import() {

$access_token = sf_oauth();
$query = '/services/data/v50.0/queryAll/?q=SELECT+id,name,Subject__c,Description__c,Remove_From_Web__c,(SELECT+name,id,Start_Date_Time__c,End_Date_Time__c,Delivery__c,Event_Type__c,Event_Location__r.Web_Location__c,Member_Only__c,CLE_Credits__c,CPE_Credits__c,PDH_Credits__c,SHRM_PDC__c,HRCI_Credit_Type__c+FROM+Events__r+WHERE+Start_Date_Time__c+>+TODAY+OR+End_Date_Time__c+>+TODAY+order+by+Start_Date_Time__c)+from+Event_Profile__c+WHERE+Remove_From_Web__c+>+TODAY+OR+Remove_From_Web__c+=+NULL';

$allResults = [];
$loop = false;

while ($loop === false){
	$result = sf_event_query($access_token, $query);
	$d_result = json_decode($result);
	if ($d_result->records === false || $d_result->records === null ){
		error_log('Error:' . $d_result[0]->message . ':' .$d_result[0]->errorCode);
		exit();
	}
	$allResults = array_merge($allResults, $d_result->records);
	$loop = $d_result->done;
	if ($loop === false){
		$query = $d_result->nextRecordsUrl;
	}
}

// Identify the upload directory path.
$uploads  = wp_upload_dir();

// Generate full file path and set extension to $type.
$filename = $uploads['basedir'] . '/wpallimport/files/sf_Events_upload.json';

// If the file exists locally, mark it for deletion.
if ( file_exists( $filename ) ) {
	@unlink( $filename );
}

// Save the new file retrieved from FTP.
file_put_contents( $filename, json_encode($allResults) );

// Return the URL to the newly created file.


}
add_action( 'sf_event_import', 'sf_event_import' );

*/