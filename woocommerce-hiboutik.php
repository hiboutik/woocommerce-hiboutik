<?php
/**
 * Plugin Name: Hiboutik
 * Plugin URI: https://www.hiboutik.com
 * Description: Synchronize Hiboutik POS software and WooCommerce
 * Version: 1.2.1
 * Author: Hiboutik & Murelh Ntyandi
 * License: GPLv3
 */

use Hiboutik\WooCommerce\WCUtil;
use Hiboutik\Utils\JsonMessage;


require __DIR__.'/includes/Hiboutik/HiboutikAPI/autoloader.php';


// If this file is called directly, abort.
if (!defined('WPINC')) {
  die;
}

define('PLUGIN_NAME_VERSION', '1.2.1');

/** @const int 1 if logging is enabled, 0 otherwise */
define('HIBOUTIK_LOG', get_option('hiboutik_logging', 1));

/** @const string If not empty the log messages are sent to an email address.
 *  If empty, log messages are written to file.
 */
define('HIBOUTIK_LOG_MAIL', get_option('hiboutik_email_log', ''));


WCUtil::$plugin_dir = __DIR__;
// Setup, check and cleanup the logs
if (HIBOUTIK_LOG != 0 and HIBOUTIK_LOG_MAIL == '') {
  WCUtil::checkLogs();
}


/**
 * Sync data from Hiboutik to WooCommerce
 *
 * Hiboutik tries to sync with WooCommerce when a sale is closed.
 * This function responds with a JSON encoded message which contains an HTML
 * snippet (uses Bootstrap). The snippet is displayed on users's Hiboutik
 * interface.
 *
 * @param string $query Request uri
 */
function fromHiboutik($query)
{
  $json_msg = new JsonMessage();

  $config = WCUtil::getHiboutikConfiguration();

  if ($query->request === WCUtil::ROUTE_SYNC_SALE) {
    if (!WCUtil::authenticate($config)) {
      WCUtil::writeLog("Warning: invalid authentication from ".$_SERVER['REMOTE_ADDR']);
      $json_msg->alert('warning', 'WooCommerce: invalid authentication')->show();
      exit();
    }
    if (empty($_POST) or !isset($_POST['sale_id'])) {
      WCUtil::writeLog("Warning: sync route has been accessed but no data was received.");
      $json_msg->alert('warning', "WooCommerce: sync route has been accessed but no data was received.")->show();
      exit();
    }

    // Abort if the sale closed in Hiboutik was created initially in WooCommerce
    if (isset($_POST['sale_ext_ref']) and strpos($_POST['sale_ext_ref'], $config['HIBOUTIK_SALE_ID_PREFIX']) === 0) {
      $sale_no = substr($_POST['sale_ext_ref'], strlen($config['HIBOUTIK_SALE_ID_PREFIX']));
      try {
        // If the sale exists in woocommerce I don't synchronize
        $wc_order = new WC_Order($sale_no);
        exit();
      } catch (\Exception $e) {
        // The sale does not exist in WooCommerce, I can synchronize
      }
    }

    if (isset($_POST['line_items'])) {
      // Get a Hiboutik API instance
      $hiboutik = WCUtil::apiConnect($config);

      foreach ($_POST['line_items'] as $item) {
        // Returns array with stocks for each store
        $stocks_dispo = $hiboutik->get("/stock_available/product_id_size/{$item['product_id']}/{$item['product_size']}");
        if (!$hiboutik->request_ok) {
          WCUtil::writeLog("Warning: Cannot find product in Hiboutik using id '{$item['product_id']}', size {$item['product_size']}. Skipping...");
          $json_msg->alert('warning', "WooCommerce: Cannot find product in Hiboutik using id '{$item['product_id']}', size {$item['product_size']}. Skipping...");
          continue;
        }
        foreach ($stocks_dispo as $stock) {
          if ($stock['warehouse_id'] == $config['HIBOUTIK_STORE_ID']) {// found the store
            $quantity = $stock['stock_available'];
            break;
          }
        }
        $wc_prod_id = (int) wc_get_product_id_by_sku($item['product_barcode']);
        if ($wc_prod_id == 0) {
          WCUtil::writeLog("Warning: Cannot find product in WooCommerce using barcode '{$item['product_model']}', id {$item['product_id']}. Skipping...");
          $json_msg->alert('warning', "WooCommerce: Cannot find product in WooCommerce using barcode: '{$item['product_model']}', id {$item['product_id']}. Skipping...");
          continue;
        }
        $wc_product = wc_get_product($wc_prod_id);
        if ($wc_product == false) {
          WCUtil::writeLog("Warning: Product '{$item['product_model']}', id {$item['product_id']} was not found in WooCommerce. Skipping...");
          $json_msg->alert('warning', "WooCommerce: Product '{$item['product_model']}', id {$item['product_id']} was not found in WooCommerce. Skipping...");
          continue;
        }
        wc_update_product_stock($wc_prod_id, $quantity);
      }
      if ($json_msg->message === '') {
        $json_msg->alert('success', 'WooCommerce: Synchronisation avec WooCommerce effectuée');
      }
    } else {
      WCUtil::writeLog('Warning: No products received from the Hiboutik webhook. Unable to synchronize sale '.$_POST['sale_id'].'.');
      $json_msg->alert('warning', 'WooCommerce: Warning: No products received from the Hiboutik webhook. Unable to synchronize sale '.$_POST['sale_id'].'.');
    }
    $json_msg->show();
    exit();
  }

  if ($query->request === 'hiboutik-woocommerce-sync-stock') {
    require('hiboutik_page_sync.php');
    exit();
  }

  if ($query->request === 'hiboutik-woocommerce-recup-vente') {
    //$order_id = $_GET['order_id'];
    fromWooCommerce($order_id);
    exit();
  }
}


/**
 * Sync data from WooCommerce to Hiboutik
 *
 * Synchronisation is made through the Hiboutik API.
 *
 * @param string $query Request uri
 */
function fromWooCommerce($order_id)
{
  $message_retour = array();

  $wc_order = wc_get_order($order_id);
  if (!($wc_order instanceof WC_Order)) {
    throw new \Exception("Impossible de recuperer l'instance de la commande WooCommerce $order_id", 4);
  }
  $wc_order_data = $wc_order->get_data();
  $wc_billing_address = $wc_order_data['billing'];
  $wc_shipping_address = $wc_order_data['shipping'];
  $customer_note = $wc_order_data['customer_note'];
  $wc_order_id = $wc_order_data['id'];

  // Get settings
  $config = WCUtil::getHiboutikConfiguration();

  $hiboutik = WCUtil::apiConnect($config);

/**
 * Unique sale id (avoids double entries)
 * Has the sale already been synchronized? Search if there is a sale with the
 * same reference $prefixe_vente$order_id in Hiboutik
 */
  $sale_already_sync = $hiboutik->get("/sales/search/ext_ref/{$config['HIBOUTIK_SALE_ID_PREFIX']}$order_id");

  if ($hiboutik->request_ok) {
    if (!empty($sale_already_sync)) {
      $message_retour[] = "Sale already synced -> Aborting";
    } else {
      // The client exists?
      $hibou_customer = 0;
      if ($wc_billing_address['email'] <> "") {
        $client_hiboutik = $hiboutik->get('/customers/search/', [
          'email' => $wc_billing_address['email']
        ]);

        if (empty($client_hiboutik)) {// The Hiboutik client does not exist
          // The client is created
          $hibou_create_customer = $hiboutik->post('/customers/', [
          'customers_first_name'   => $wc_billing_address['first_name'],
          'customers_last_name'    => $wc_billing_address['last_name'],
          'customers_email'        => $wc_billing_address['email'],
          'customers_country'      => $wc_billing_address['country'],
          'customers_tax_number'   => '',
          'customers_phone_number' => $wc_billing_address['phone'],
          'customers_birth_date'   => '0000-00-00',
          ]);
          $hibou_customer = $hibou_create_customer['customers_id'];
          $message_retour[] = "Client created : id $hibou_customer";
        } else {
          $hibou_customer = $client_hiboutik[0]['customers_id'];
          $message_retour[] = "Client found : id $hibou_customer";
        }
      }

      $prices_without_taxes = 1;
      if ($wc_order_data['prices_include_tax'] == "1") $prices_without_taxes = 0;

      $duty_free_sale = 1;
      if ($wc_order_data['total_tax'] > "0") $duty_free_sale = 0;

      // Create sale in Hiboutik
      $hibou_sale = $hiboutik->post('/sales/', [
      'store_id'             => $config['HIBOUTIK_STORE_ID'],
      'customer_id'          => $hibou_customer,
      'duty_free_sale'       => $duty_free_sale,
      'prices_without_taxes' => $prices_without_taxes,
      'currency_code'        => $wc_order_data['currency'],
      'vendor_id'            => $config['HIBOUTIK_VENDOR_ID'] ? $config['HIBOUTIK_VENDOR_ID'] : 1
      ]);


      if (isset($hibou_sale['error'])) {
        $message_retour[] = "Error : Unable to create sale on Hiboutik";
      } else {
        $hibou_sale_id = $hibou_sale['sale_id'];
        $message_retour[] = "Sale created : id $hibou_sale_id";
      }


      // For each item in the sale
      foreach ($wc_order_data['line_items'] as $item) {
        $my_product_id = $item['product_id'];
        $my_variation_id = $item['variation_id'];
        $my_quantity = $item['quantity'];
        $my_name = $item['name'];
        $my_product_price = ($item['total'] + $item['total_tax']) / $my_quantity;
        if ($prices_without_taxes == "1") $my_product_price = $item['total'] / $my_quantity;
        $commentaires = "";

        // Get barcode for the item being sold
        $wcProduct = new WC_Product($item['product_id']);
        $bcProduct = $wcProduct->get_sku();

/**
 * 2 cases: simple item | the item has variations (sizes).
 * If the item has a variation, we get its barcode.
 */
        if ($my_variation_id <> "0") {
          $sku = get_post_meta( $item['variation_id'], '_sku', true );
          if ($sku <> "") $bcProduct = $sku;
        }

        // We ask Hiboutik's API which is the product (Hiboutik id) associated with the barcode
        $product_hiboutik = $hiboutik->get("/products/search/barcode/$bcProduct/");

        // If a product was found with the barcode we get its id & size
        if (isset($product_hiboutik[0])) {
          $id_prod = $product_hiboutik[0]['product_id'];
          $id_taille = $product_hiboutik[0]['product_size'];
          $message_retour[] = "Product $my_product_id x$my_quantity ($my_variation_id) #$bcProduct added";
        } else {
          // If no product has been found with this barcode, we add an unknown item (product_id = 0)
          $id_prod = 0;
          $id_taille = 0;
          $commentaires = "Unknown product\nSKU : $bcProduct\nName : $my_name";
          $message_retour[] = "Product $my_product_id x$my_quantity ($my_variation_id) #$bcProduct unknown";
        }

        // Add item to the sale
        $hibou_add_product = $hiboutik->post('/sales/add_product/', [
        'sale_id'          => $hibou_sale_id,
        'product_id'       => $id_prod,
        'size_id'          => $id_taille,
        'quantity'         => $item['quantity'],
        'product_price'    => $my_product_price,
        'stock_withdrawal' => 1,
        'product_comments' => $commentaires
        ]);


        // If there's any error we add the item again without removing it from stock (stock managed product, but unavailable)
        if (isset($hibou_add_product['error'])) {
          $commentaires = "Results: " . print_r( $hibou_add_product, true );
          if ($hibou_add_product['details']['product_id'] == "This function does not handle packages") {
            $commentaires .= "\n\nid_prod : $id_prod & id_taille : $id_taille";
            $id_prod = 0;
            $id_taille = 0;
          }
          $hibou_add_product = $hiboutik->post('/sales/add_product/', [
          'sale_id'          => $hibou_sale_id,
          'product_id'       => $id_prod,
          'size_id'          => $id_taille,
          'quantity'         => $item['quantity'],
          'product_price'    => $my_product_price,
          'stock_withdrawal' => 0,
          'product_comments' => $commentaires
          ]);
          $message_retour[] = "Product $my_product_id x$my_quantity ($my_variation_id) #$bcProduct -> error";
        }
      }

      // Shipping management
      foreach ($wc_order_data['shipping_lines'] as $item) {
        $name_livraison = $item['name'];
        $method_id_livraison = $item['method_id'];
        $commentaires_livraison = "$name_livraison\n$method_id_livraison";

        $my_product_price = $item['total'] + $item['total_tax'];
        if ($prices_without_taxes == "1") $my_product_price = $item['total'];

        $message_retour[] = "Delivery $total_livraison added";

        // Add shipping
        $hibou_add_product = $hiboutik->post('/sales/add_product/', [
        'sale_id'          => $hibou_sale_id,
        'product_id'       => $config['HIBOUTIK_SHIPPING_PRODUCT_ID'],
        'size_id'          => 0,
        'quantity'         => 1,
        'product_price'    => $my_product_price,
        'stock_withdrawal' => 1,
        'product_comments' => $commentaires_livraison
        ]);
      }

      // Sale comments
      $hibou_add_product = $hiboutik->post('/sales/comments/', [
      'sale_id'         => $hibou_sale_id,
      'comments'         => "order_id : $wc_order_id\nComments : $customer_note"
      ]);

      // Unique sale id
      $hibou_update_sale_ext_ref = $hiboutik->put("/sale/$hibou_sale_id", [
      'sale_id' => $hibou_sale_id,
      'sale_attribute' => "ext_ref",
      'new_value'      => $config['HIBOUTIK_SALE_ID_PREFIX'].$order_id
      ]);
    }
  } else {
    $message_retour[] = "Error connecting to Hiboutik API";
  }

  return $message_retour;
}





// Run in a function to avoid name conflicts
function run_hiboutik()
{
  if (is_admin()) {// admin
    require 'hiboutik_options.php';
  } else {// public mode
    add_action('parse_request', 'fromHiboutik');
  }

  add_action('woocommerce_order_status_processing', 'fromWooCommerce');
  /*
  woocommerce_order_status_pending
  woocommerce_order_status_failed
  woocommerce_order_status_on-hold
  woocommerce_order_status_processing
  woocommerce_order_status_completed
  woocommerce_order_status_refunded
  woocommerce_order_status_cancelled
  */
}
run_hiboutik();




add_action('woocommerce_order_actions', 'custom_wc_order_action', 10, 1 );
function custom_wc_order_action( $actions )
{
  if ( is_array( $actions ) ) {
    $actions['custom_action_sync_hibou'] = __( 'Synchronize with Hiboutik' );
  }

  return $actions;
}

function sv_wc_process_order_meta_box_action( $order )
{
  $message = sprintf( __( 'Sync by %s with Hiboutik', 'my-textdomain' ), wp_get_current_user()->display_name );
  $order->add_order_note( $message );

  $wc_order_data = $order->get_data();
  $order_id = $wc_order_data['id'];
  $message_retour = fromWooCommerce($order_id);

  foreach ($message_retour as $ligne_retour) {
    $message = sprintf( __( "$ligne_retour", 'my-textdomain' ) );
    $order->add_order_note( $message );
  }
}

add_action( 'woocommerce_order_action_custom_action_sync_hibou', 'sv_wc_process_order_meta_box_action' );
