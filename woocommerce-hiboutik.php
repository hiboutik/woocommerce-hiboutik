<?php

/**
 * Plugin Name: Hiboutik
 * Plugin URI: https://www.hiboutik.com
 * Description: Synchronize Hiboutik POS software and WooCommerce
 * Version: 1.0.1
 * Author: Hiboutik & Murelh Ntyandi
 * License: GPLv3
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

define( 'PLUGIN_NAME_VERSION', '0.1.0' );

defined('HIBOUTIK_LOG') || define('HIBOUTIK_LOG',false);

function fromHiboutik( $query )
{
	if ( $query->request === 'hiboutik-woocommerce-sync' ) {
		if (!isset($_POST['line_items'])) {
			hiboutikLog('Pas des produits dans le POST d\'url de callback; synchronisation abandonée.');
			exit();
		}
		foreach ($_POST['line_items'] as $item) {
			$wc_prod_id = (int) wc_get_product_id_by_sku($item['product_barcode']);
			if ($wc_prod_id == 0) {
				hiboutikLog("Le produit {$item['product_id']} n'a pas du code des barres dans WooCommerce.");
				continue;
			}
			$wc_product = wc_get_product($wc_prod_id);
			if ($wc_product == false) {
				hiboutikLog("Le produit {$item['product_id']} n'a pas pu etre recuperé de WooCommerce.");
				continue;
			}
			$wc_stock = $wc_product->get_stock_quantity();
			if ($wc_stock === null) {
				hiboutikLog("La gestion de stock pour produit {$item['product_id']} est désactivée dans WooCommerce.");
			}
			wc_update_product_stock($wc_prod_id, $wc_stock - $item['quantity']);
			hiboutikLog("Le stock du produit {$wc_prod_id} reduit de {$item['quantity']} avec succès.");
		}
		exit();
	}

	if ( $query->request === 'hiboutik-woocommerce-sync-stock' ) {
		require('hiboutik_page_sync.php');
		exit();
	}

	if ( $query->request === 'hiboutik-woocommerce-recup-vente' ) {
		$order_id = $_GET['order_id'];
		$msgs = fromWooCommerce( $order_id );
		hiboutikLog( implode( PHP_EOL, $msgs ) );
		exit();
	}

}

function hiboutikLog( $msg = '' )
{
	if ( ! HIBOUTIK_LOG || ! $msg ) return;

	if ( defined('HIBOUTIK_LOG_MAIL') && HIBOUTIK_LOG_MAIL ) {
		$dest = is_email( HIBOUTIK_LOG_MAIL ) ? HIBOUTIK_LOG_MAIL : get_bloginfo('admin_email');
		$type = 1;
	} else {
		$dest = trailingslashit( WP_CONTENT_DIR ) . 'hiboutik.log';
		$type = 3;
	}

	error_log( PHP_EOL . '[' . date('d-M-Y H:i:s e') . '] ' . $msg, $type, $dest );
}

function fromWooCommerce($order_id)
{
$message_retour = array();

$wc_order = wc_get_order($order_id);
if ( ! ($wc_order instanceof WC_Order) || ! method_exists( $wc_order, 'get_data' ) ) {
	return array("Impossible de recuperer l'instance de la commande WooCommerce $order_id");
}
$wc_order_data = $wc_order->get_data();
$wc_billing_address = $wc_order_data['billing'];
$wc_shipping_address = $wc_order_data['shipping'];
$customer_note = $wc_order_data['customer_note'];
$wc_order_id = $wc_order_data['id'];

//print_r($wc_order_data);

$hiboutik_account = get_option('hiboutik_account', '');
$hiboutik_user = get_option('hiboutik_user', '');
$hiboutik_key = get_option('hiboutik_key', '');
$hiboutik_token = get_option('hiboutik_oauth_token', '');
$store_id = get_option('hiboutik_store_id', '');
$vendor_id = get_option('hiboutik_vendor_id', '');
$shipping_product_id = get_option('hiboutik_shipping_product_id', '');
$prefixe_vente = get_option('hiboutik_sale_id_prefix', '');


require __DIR__.'/includes/Hiboutik/HiboutikAPI/autoloader.php';
if ($hiboutik_token == '') {
  $hiboutik = new Hiboutik\HiboutikAPI($hiboutik_account, $hiboutik_user, $hiboutik_key);
} else {
  $hiboutik = new Hiboutik\HiboutikAPI($hiboutik_account);
  $hiboutik->oauth($hiboutik_token);
}

//identifiant unique de la vente (pour éviter les doublons)
// Est ce que la vente a déjà été synchronisée ? Recherche si il existe une vente avec la référence $prefixe_vente$order_id sur Hiboutik
$sale_already_sync = $hiboutik->get("/sales/search/ext_ref/$prefixe_vente$order_id");

if ($hiboutik->request_ok)
{
if (!empty($sale_already_sync))
{
$message_retour[] = "Sale already synced -> Aborting";
}
else
{

// Le client existe?
$hibou_customer = 0;
if ($wc_billing_address['email'] <> "")
{
$client_hiboutik = $hiboutik->get('/customers/search/', [
'email' => $wc_billing_address['email']
]);

if (empty($client_hiboutik)) {// Le client Hiboutik n'existe pas
//création du client
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
}
else
{
$hibou_customer = $client_hiboutik[0]['customers_id'];
$message_retour[] = "Client found : id $hibou_customer";
}
}

$prices_without_taxes = 1;
if ($wc_order_data['prices_include_tax'] == "1") $prices_without_taxes = 0;

if ($vendor_id == "") $vendor_id = 1;

$duty_free_sale = 1;
if ($wc_order_data['total_tax'] > "0") $duty_free_sale = 0;

//création de la vente sur Hiboutik
$hibou_sale = $hiboutik->post('/sales/', [
'store_id' 				=> $store_id,
'customer_id'  			=> $hibou_customer,
'duty_free_sale'        => $duty_free_sale,
'prices_without_taxes'  => $prices_without_taxes,
'currency_code'   		=> $wc_order_data['currency'],
'vendor_id' 			=> $vendor_id
]);

//print_r($wc_order);

if (isset($hibou_sale['error']))
{
$message_retour[] = "Error : Unable to create sale on Hiboutik";
}
else
{
$hibou_sale_id = $hibou_sale['sale_id'];
$message_retour[] = "Sale created : id $hibou_sale_id";
}


//pour chaque produit dans la vente
foreach ($wc_order_data['line_items'] as $item) {

$my_product_id = $item['product_id'];
$my_variation_id = $item['variation_id'];
$my_quantity = $item['quantity'];
$my_name = $item['name'];
$my_product_price = ($item['total'] + $item['total_tax']) / $my_quantity;
if ($prices_without_taxes == "1") $my_product_price = $item['total'] / $my_quantity;
$commentaires = "";

//récupération du code barre du produit vendu
$wcProduct = new WC_Product($item['product_id']);
$bcProduct = $wcProduct->get_sku();


//2 cas : le produit est simple | le produit comporte des variations (tailles)
//si le produit vendu est un produit avec une variation alors on récupère le code barre de la variation
if ($my_variation_id <> "0")
{
$sku = get_post_meta( $item['variation_id'], '_sku', true );
if ($sku <> "") $bcProduct = $sku;
}

//tweak si vieille version de php
if ($hiboutik_token == '') {
  $hiboutik = new Hiboutik\HiboutikAPI($hiboutik_account, $hiboutik_user, $hiboutik_key);
} else {
  $hiboutik = new Hiboutik\HiboutikAPI($hiboutik_account);
  $hiboutik->oauth($hiboutik_token);
}

//on interroge l'API Hiboutik pour savoir quel est le produit (id Hiboutik) qui correspond au code barre
$product_hiboutik = $hiboutik->get("/products/search/barcode/$bcProduct/");

//si on a trouvé un produit a partir du code barre alors on récupère product_id & product_size
if (isset($product_hiboutik[0]))
{
$id_prod = $product_hiboutik[0]['product_id'];
$id_taille = $product_hiboutik[0]['product_size'];
$message_retour[] = "Product $my_product_id x$my_quantity ($my_variation_id) #$bcProduct added";
}
else
{
//si aucun produit a été trouvé à partir du code barre alors on ajoute le produit inconnu (product_id = 0)
$id_prod = 0;
$id_taille = 0;
$commentaires = "Unknown product\nSKU : $bcProduct\nName : $my_name";
$message_retour[] = "Product $my_product_id x$my_quantity ($my_variation_id) #$bcProduct unknown";
}

//tweak si vieille version de php
if ($hiboutik_token == '') {
  $hiboutik = new Hiboutik\HiboutikAPI($hiboutik_account, $hiboutik_user, $hiboutik_key);
} else {
  $hiboutik = new Hiboutik\HiboutikAPI($hiboutik_account);
  $hiboutik->oauth($hiboutik_token);
}
//ajout du produit sur la vente
$hibou_add_product = $hiboutik->post('/sales/add_product/', [
'sale_id' 				=> $hibou_sale_id,
'product_id'  			=> $id_prod,
'size_id'   		    => $id_taille,
'quantity' 				=> $item['quantity'],
'product_price'   		=> $my_product_price,
'stock_withdrawal' 		=> 1,
'product_comments' 		=> $commentaires
]);


//si il y a une quelconque erreur alors on ajoute a nouveau le produit mais sans sortie stock (cas du produit géré en stock mais indisponible)
if (isset($hibou_add_product['error']))
{
$commentaires = "Results: " . print_r( $hibou_add_product, true );
if ($hibou_add_product['details']['product_id'] == "This function does not handle packages")
{
$commentaires .= "\n\nid_prod : $id_prod & id_taille : $id_taille";
$id_prod = 0;
$id_taille = 0;
}
$hibou_add_product = $hiboutik->post('/sales/add_product/', [
'sale_id' 				=> $hibou_sale_id,
'product_id'  			=> $id_prod,
'size_id'   		    => $id_taille,
'quantity' 				=> $item['quantity'],
'product_price'   		=> $my_product_price,
'stock_withdrawal' 		=> 0,
'product_comments' 		=> $commentaires
]);
$message_retour[] = "Product $my_product_id x$my_quantity ($my_variation_id) #$bcProduct -> error";
}


}


//gestion de la livraison
foreach ($wc_order_data['shipping_lines'] as $item) {
$name_livraison = $item['name'];
$method_id_livraison = $item['method_id'];
$commentaires_livraison = "$name_livraison\n$method_id_livraison";

$my_product_price = $item['total'] + $item['total_tax'];
if ($prices_without_taxes == "1") $my_product_price = $item['total'];

$message_retour[] = "Delivery $total_livraison added";
if ($hiboutik_token == '') {
  $hiboutik = new Hiboutik\HiboutikAPI($hiboutik_account, $hiboutik_user, $hiboutik_key);
} else {
  $hiboutik = new Hiboutik\HiboutikAPI($hiboutik_account);
  $hiboutik->oauth($hiboutik_token);
}
//ajout de la livraison
$hibou_add_product = $hiboutik->post('/sales/add_product/', [
'sale_id' 				=> $hibou_sale_id,
'product_id'  			=> $shipping_product_id,
'size_id'   		    => 0,
'quantity' 				=> 1,
'product_price'   		=> $my_product_price,
'stock_withdrawal' 		=> 1,
'product_comments' 		=> $commentaires_livraison
]);
}

//commentaires de la vente
if ($hiboutik_token == '') {
  $hiboutik = new Hiboutik\HiboutikAPI($hiboutik_account, $hiboutik_user, $hiboutik_key);
} else {
  $hiboutik = new Hiboutik\HiboutikAPI($hiboutik_account);
  $hiboutik->oauth($hiboutik_token);
}
$hibou_add_product = $hiboutik->post('/sales/comments/', [
'sale_id' 				=> $hibou_sale_id,
'comments' 				=> "order_id : $wc_order_id\nComments : $customer_note"
]);

//identifiant unique de la vente
if ($hiboutik_token == '') {
  $hiboutik = new Hiboutik\HiboutikAPI($hiboutik_account, $hiboutik_user, $hiboutik_key);
} else {
  $hiboutik = new Hiboutik\HiboutikAPI($hiboutik_account);
  $hiboutik->oauth($hiboutik_token);
}
$hibou_update_sale_ext_ref = $hiboutik->put("/sale/$hibou_sale_id", [
'sale_id' 				=> $hibou_sale_id,
'sale_attribute' 		=> "ext_ref",
'new_value' 			=> "$prefixe_vente$order_id"
]);

}
}
else
{
$message_retour[] = "Error connecting to Hiboutik API";
}

return $message_retour;
}





// Pour eviter le conflit potentiel des noms des variables
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
function custom_wc_order_action( $actions ) {

if ( is_array( $actions ) ) {
$actions['custom_action_sync_hibou'] = __( 'Synchronize with Hiboutik' );
}

return $actions;

}

function sv_wc_process_order_meta_box_action( $order ) {

$message = sprintf( __( 'Sync by %s with Hiboutik', 'my-textdomain' ), wp_get_current_user()->display_name );
$order->add_order_note( $message );

$wc_order_data = $order->get_data();
$order_id = $wc_order_data['id'];
$message_retour = fromWooCommerce($order_id);

foreach ($message_retour as $ligne_retour)
{
$message = sprintf( __( "$ligne_retour", 'my-textdomain' ) );
$order->add_order_note( $message );
}

}
add_action( 'woocommerce_order_action_custom_action_sync_hibou', 'sv_wc_process_order_meta_box_action' );


