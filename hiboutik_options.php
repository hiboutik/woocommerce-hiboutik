<?php
use Hiboutik\WooCommerce\WCUtil;


add_action('admin_init', function() {
  add_settings_section('hiboutik_credentials', 'Credentials', null, 'hiboutik');
  add_settings_field('hiboutik_account', 'Account:', function() {
    print '<input
      type="text"
      name="hiboutik_account"
      value="'.get_option('hiboutik_account', '').'"
      title="Your Hiboutik account name; eg: my_account.hiboutik.com -> my_account"
      placeholder="Hiboutik account">';
  }, 'hiboutik', 'hiboutik_credentials');
  add_settings_field('hiboutik_user', 'User:', function() {
    print '<input
      type="text"
      name="hiboutik_user"
      value="'.get_option('hiboutik_user', '').'"
      id="hiboutik_user"
      title="Your Hiboutik login email"
      placeholder="Hiboutik user"
      >';
  }, 'hiboutik', 'hiboutik_credentials');
  add_settings_field('hiboutik_key', 'Key:', function() {
    print '<input
      type="text"
      name="hiboutik_key"
      value="'.get_option('hiboutik_key', '').'"
      id="hiboutik_key"
      title="Your Hiboutik API key"
      placeholder="API key"
      >';
  }, 'hiboutik', 'hiboutik_credentials');
  add_settings_field('hiboutik_oauth_token', '<i>OAuth token:</i>', function() {
    print '<input
      type="text"
      name="hiboutik_oauth_token"
      value="'.get_option('hiboutik_oauth_token', '').'"
      id="hiboutik_oauth_token"
      title="Your Hiboutik OAuth token. Leave blank if basic authentication is used"
      placeholder="OAuth token"
      >';
  }, 'hiboutik', 'hiboutik_credentials');

  add_settings_section('hiboutik_store', 'Settings', null, 'hiboutik');
  add_settings_field('hiboutik_vendor_id', 'Vendor ID:', function() {
    print '<input
      type="number"
      name="hiboutik_vendor_id"
      value="'.get_option('hiboutik_vendor_id', 1).'"
      title="The vendor ID under which the synchronization will be made"
      placeholder="Vendor ID">';
  }, 'hiboutik', 'hiboutik_store');
  add_settings_field('hiboutik_store_id', 'Store ID:', function() {
    print '<input
      type="number"
      name="hiboutik_store_id"
      value="'.get_option('hiboutik_store_id', 1).'"
      title="Store ID"
      placeholder="Stock">';
  }, 'hiboutik', 'hiboutik_store');
  add_settings_field('hiboutik_shipping_product_id', 'Shipping Product ID:', function() {
    print '<input
      type="number"
      name="hiboutik_shipping_product_id"
      value="'.get_option('hiboutik_shipping_product_id', 0).'"
      title="The ID of the product in Hiboutik that designates shipping charges"
      placeholder="Shipping Product ID">';
  }, 'hiboutik', 'hiboutik_store');
  add_settings_field('hiboutik_sale_id_prefix', 'Sale ID Prefix:', function() {
    print '<input
      type="text"
      name="hiboutik_sale_id_prefix"
      value="'.get_option('hiboutik_sale_id_prefix', 'wc_').'"
      title="The sales from WooCommerce synchronized with Hiboutik will have this prefix added to them"
      placeholder="Sale ID Prefix">';
  }, 'hiboutik', 'hiboutik_store');


  add_settings_section('hiboutik_log', 'Logging', null, 'hiboutik');
  add_settings_field('hiboutik_logging', 'Log messages', function() {
    print '<input
      id="hiboutik_logging"
      type="checkbox"
      name="hiboutik_logging"
      '.(get_option('hiboutik_logging', 1) ? 'checked' : '').'
      title="Activate logs. Messages can be sent to a log file or an email address.">';
  }, 'hiboutik', 'hiboutik_log');
  add_settings_field('hiboutik_email_log', 'Email log to:', function() {
    print '<input
      id="hiboutik_email_log"
      type="text"
      name="hiboutik_email_log"
      value="'.get_option('hiboutik_email_log', '').'"
      title="Destination email address for log messages."
      placeholder="email">
      <span>If left empty, messages are logged to file. !!Warning: this option can cause sending a large volume of emails!</span>';
  }, 'hiboutik', 'hiboutik_log');


  if (isset($_POST['hiboutik_save_setings']) and $_POST['hiboutik_save_setings'] === '1') {
    if (!current_user_can('manage_options')) {
      add_settings_error('general', 'hiboutik_err_999', 'Unauthorized: you cannot modify the settings. Are you logged in?');
      return;
    }

    $hiboutik_account = trim(strtolower($_POST['hiboutik_account']));
    if (!preg_match('/^[a-z0-9_]+$/', $hiboutik_account)) {
      add_settings_error('hiboutik_account', 'hiboutik_err_100', 'Invalid account name');
    }

    $hiboutik_user = trim(strtolower($_POST['hiboutik_user']));
    if (filter_var($hiboutik_user, FILTER_VALIDATE_EMAIL) === false) {
      add_settings_error('hiboutik_user', 'hiboutik_err_101', 'Invalid user name');
    }

    $hiboutik_key = $_POST['hiboutik_key'];
    if (strlen($hiboutik_key) > 64 or !preg_match('/^[A-z0-9]+$/', $hiboutik_key)) {
      add_settings_error('hiboutik_key', 'hiboutik_err_102', 'Invalid key');
    }

    $hiboutik_oauth_token = $_POST['hiboutik_oauth_token'];
    if ($hiboutik_oauth_token != '' and (strlen($hiboutik_oauth_token) > 128 or !preg_match('/^[A-z0-9]+$/', $hiboutik_oauth_token))) {
      add_settings_error('hiboutik_oauth_token', 'hiboutik_err_103', 'Invalid token');
    }

    $hiboutik_vendor_id = $_POST['hiboutik_vendor_id'];
    if (!is_numeric($hiboutik_vendor_id) or $hiboutik_vendor_id >= 100000) {
      add_settings_error('hiboutik_vendor_id', 'hiboutik_err_104', 'Invalid vendor ID');
    }

    $hiboutik_store_id = $_POST['hiboutik_store_id'];
    if (!is_numeric($hiboutik_store_id) or $hiboutik_store_id >= 100000) {
      add_settings_error('hiboutik_store_id', 'hiboutik_err_105', 'Invalid store ID');
    }

    $hiboutik_shipping_product_id = $_POST['hiboutik_shipping_product_id'];
    if (!is_numeric($hiboutik_shipping_product_id) or $hiboutik_shipping_product_id >= 1e7) {
      add_settings_error('hiboutik_shipping_product_id', 'hiboutik_err_106', 'Invalid shipping product ID');
    }

    $hiboutik_sale_id_prefix = $_POST['hiboutik_sale_id_prefix'];
    if (strlen($hiboutik_sale_id_prefix) > 64 or !preg_match('/^[A-z0-9_]+$/', $hiboutik_sale_id_prefix)) {
      add_settings_error('hiboutik_sale_id_prefix', 'hiboutik_err_107', 'Invalid sale prefix');
    }

    $hiboutik_email_log = $_POST['hiboutik_email_log'];
    if ($hiboutik_email_log != '' and filter_var($hiboutik_email_log, FILTER_VALIDATE_EMAIL) === false) {
      add_settings_error('hiboutik_email_log', 'hiboutik_err_108', 'Invalid email address');
    }

    $errors = get_settings_errors();
    if (empty($errors)) {
      $r_u_account  = update_option('hiboutik_account', $hiboutik_account);
      $r_u_user     = update_option('hiboutik_user', $hiboutik_user);
      $r_u_key      = update_option('hiboutik_key', $hiboutik_key);
      $r_u_token    = update_option('hiboutik_oauth_token', $hiboutik_oauth_token);
      $r_u_vendor   = update_option('hiboutik_vendor_id', $hiboutik_vendor_id);
      $r_u_store_id = update_option('hiboutik_store_id', $hiboutik_store_id);
      $r_u_shipping = update_option('hiboutik_shipping_product_id', $hiboutik_shipping_product_id);
      $r_u_prefix   = update_option('hiboutik_sale_id_prefix', $hiboutik_sale_id_prefix);
      if (isset($_POST['hiboutik_logging'])) {
        $r_u_logging = update_option('hiboutik_logging', 1);
      } else {
        $r_u_logging = update_option('hiboutik_logging', 0);
      }
      $r_u_email_log = update_option('hiboutik_email_log', $hiboutik_email_log);

      add_settings_error('general', 'hiboutik_err_0', 'Settings successfully updated!', 'updated');
    }
  }
});


// Security
WCUtil::$webhook = (site_url()).'/'.WCUtil::ROUTE_SYNC_SALE.'/?'.(WCUtil::getSecurityToken());


add_action('admin_menu', function() {
  add_menu_page('Hiboutik', 'Hiboutik', 'manage_options', 'hiboutik', function() {
?>
  <div class="wrap">
    <h1><?= esc_html(get_admin_page_title());?></h1>
    <?= settings_errors()?>
    <form action="admin.php?page=<?= $_GET['page']?>" method="post">
      <input type="hidden" name="hiboutik_save_setings" value='1'>
      <?php
      settings_fields('hiboutik');
      do_settings_sections('hiboutik');
      submit_button('Save parameters');
      ?>
    </form>
  </div>

  <h2>Manually synchronize stock with Hiboutik</h2>
  <a class="button button-primary" href="/hiboutik-woocommerce-sync-stock/">Go!</a>

  <h2>Hiboutik webhook</h2>
  <span><?= WCUtil::$webhook?></span>

  <script>
    (function() {
      var hiboutik_logging = document.getElementById('hiboutik_logging');
      var hiboutik_email_log = document.getElementById('hiboutik_email_log');

      console.log(hiboutik_logging);
      if (hiboutik_logging.checked) {
        toggle_fields(true);
      } else {
        toggle_fields(false);
      }
      hiboutik_logging.addEventListener('click', function (e) {
        if (e.target.checked) {
          toggle_fields(true);
        } else {
          toggle_fields(false);
        }
      });

      function toggle_fields(state) {
        hiboutik_email_log.disabled = state ? false : true;
      }
    })();
  </script>
  <?php
  }, 'data:image/svg+xml;base64,'.base64_encode(file_get_contents(__DIR__.'/hibou-plain-white.svg'))/*Hiboutik logo*/, 58);
});

