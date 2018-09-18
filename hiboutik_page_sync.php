<?php
/*Template Name: Sync with Hiboutik*/
get_header();?>
<div id="primary" class="content-area">
<?php


$hiboutik_account = get_option('hiboutik_account', '');
$hiboutik_user    = get_option('hiboutik_user', '');
$hiboutik_key     = get_option('hiboutik_key', '');
$hiboutik_token   = get_option('hiboutik_oauth_token', '');
$store_id         = get_option('hiboutik_store_id', '');
require __DIR__.'/includes/Hiboutik/HiboutikAPI/autoloader.php';
if ($hiboutik_token == '') {
  $hiboutik = new Hiboutik\HiboutikAPI($hiboutik_account, $hiboutik_user, $hiboutik_key);
} else {
  $hiboutik = new Hiboutik\HiboutikAPI($hiboutik_account);
  $hiboutik->oauth($hiboutik_token);
}
$stock_available = $hiboutik->get("/stock_available/warehouse_id/$store_id");
if ($hiboutik->request_ok) {
print '<h1>Stock synchronization with Hiboutik</h1><br>
<table>
  <tr>
    <th>Product Id (WooCommerce)</th>
    <th>Product barcode</th>
    <th>Stock available</th>
  </tr>';
  foreach ($stock_available as $item) {
    $my_product_barcode = $item['product_barcode'];
    $my_product_stock_available = $item['stock_available'];
    $wc_prod_id = (int) wc_get_product_id_by_sku($my_product_barcode);
    if ($wc_prod_id <> 0) {
      wc_update_product_stock($wc_prod_id, $my_product_stock_available);
      print <<<HTML
  <tr>
    <td>$wc_prod_id</td>
    <td>$my_product_barcode</td>
    <td>$my_product_stock_available</td>
  </tr>
HTML;
    }
  }
print '</table>';
} else {
	print("Error connecting to Hiboutik API");
}


?>
<?php get_sidebar( 'content-bottom' ); ?>
</div><!-- .content-area -->
<?php get_sidebar(); ?>
<?php get_footer(); ?>
