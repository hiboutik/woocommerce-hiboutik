<?php
use Hiboutik\HiboutikAPI;
use Hiboutik\WooCommerce\WCUtil;

/*Template Name: Sync with Hiboutik*/
$back_link = '';
if (current_user_can('activate_plugins')) {
  $config = WCUtil::getHiboutikConfiguration();
  $hiboutik = WCUtil::apiConnect($config);
  $stock_available = $hiboutik->get("/stock_available/warehouse_id/{$config['HIBOUTIK_STORE_ID']}");
  $alert = '';
  $updated_products = [];
  if ($hiboutik->request_ok) {
    foreach ($stock_available as $item) {
      $my_product_barcode = $item['product_barcode'];
      $my_product_stock_available = $item['stock_available'];
      $wc_prod_id = (int) wc_get_product_id_by_sku($my_product_barcode);
      $wc_product = wc_get_product($wc_prod_id);
      if ($wc_prod_id <> 0) {
        wc_update_product_stock($wc_prod_id, $my_product_stock_available);
        $updated_products[$wc_prod_id] = [
          'wc_barcode' => $my_product_barcode,
          'stock' => $my_product_stock_available,
          'name' => $wc_product->get_name()
        ];
      }
    }
    $alert = "Product(s) synchronized";
  } else {
    $alert = "Error connecting to Hiboutik API: {$stock_available['error']} {$stock_available['error_description']}";
  }
  $back_link = admin_url('admin.php?page=hiboutik');
} else {
  $alert = "Please log in as Administrator to execute this operation";
}

?>
<!DOCTYPE HTML>
<html>
  <head>
    <meta charset="UTF-8">
    <title>Hiboutik Sync</title>
    <style>
      table {
        border-collapse: collapse;
        font-family: sans;
      }
      table td,
      table th {
        padding: 4px;
        border: solid 1px #ccc;
      }
      table tbody tr:nth-child(even) {
        background-color: #e2e2e2;
      }
      table tbody tr:hover {
        background-color: #c8d6e7;
      }
      .text-right {
        text-align: right;
      }
    </style>
  </head>
  <body>
    <h1>Stock synchronization with Hiboutik</h1>

    <div>
    <?= $alert?>
    </div><br>

    <?php if ($back_link) { ?>
    <a href="<?= $back_link?>">&lt; Back</a>
    <?php } ?>
    <table>
      <thead>
        <tr>
          <th>Product Id<br>(WooCommerce)</th>
          <th>Product barcode</th>
          <th>Stock available</th>
          <th>Product name</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($updated_products)) { ?>
        <tr>
          <td colspan="4">No products found</td>
        </tr>
        <?php } else { ?>
          <?php foreach ($updated_products as $id => $item) { ?>
        <tr>
          <td class="text-right"><?= $id?></td>
          <td><?= $item['wc_barcode']?></td>
          <td class="text-right"><?= $item['stock']?></td>
          <td><?= $item['name']?></td>
        </tr>
          <?php } ?>
        <?php } ?>
      </tbody>
    </table>
    <?php if ($back_link) { ?>
    <a href="<?= $back_link?>">&lt; Back</a>
    <?php } ?>
  </body>
</html>
