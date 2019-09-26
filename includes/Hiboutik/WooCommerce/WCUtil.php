<?php
namespace Hiboutik\WooCommerce;

use Hiboutik\HiboutikAPI;


/**
 * @package Hiboutik\WooCommerce\WCUtil
 */
class WCUtil
{
  /** @const string */
  const MODULE_NAME = 'hiboutik';
  /** @const string Security parameter */
  const SECURITY_GET_PARAM = 'k';
  /** @const string */
  const ROUTE_SYNC_SALE = 'hiboutik-woocommerce-sync';

  /** @var string Hiboutik webhook */
  public static $webhook;
  /** @var string Plugin directory */
  public static $plugin_dir;

  /** @var array Config */
  protected static $config;
  /** @var string */
  protected static $security_token;


/**
 * Get Hiboutik settings
 *
 * @param void
 * @return array
 */
  public static function getHiboutikConfiguration()
  {
    if (is_null(self::$config)) {
      return self::$config = [
        'HIBOUTIK_ACCOUNT'             => get_option('hiboutik_account', ''),
        'HIBOUTIK_USER'                => get_option('hiboutik_user', ''),
        'HIBOUTIK_KEY'                 => get_option('hiboutik_key', ''),
        'HIBOUTIK_OAUTH_TOKEN'         => get_option('hiboutik_oauth_token', ''),
        'HIBOUTIK_STORE_ID'            => get_option('hiboutik_store_id', ''),
        'HIBOUTIK_VENDOR_ID'           => get_option('hiboutik_vendor_id', ''),
        'HIBOUTIK_SHIPPING_PRODUCT_ID' => get_option('hiboutik_shipping_product_id', ''),
        'HIBOUTIK_SALE_ID_PREFIX'      => get_option('hiboutik_sale_id_prefix', ''),
      ];
    } else {
      return self::$config;
    }
  }


/**
 * Connect to Hiboutik API
 *
 * Returns a configured instance of the HiboutikAPI class
 *
 * @param array $config Configuration array
 * @returns Hiboutik\HiboutikAPI
 */
  public static function apiConnect($config)
  {
    if ($config['HIBOUTIK_OAUTH_TOKEN'] == '') {
      $hiboutik = new HiboutikAPI($config['HIBOUTIK_ACCOUNT'], $config['HIBOUTIK_USER'], $config['HIBOUTIK_KEY']);
    } else {
      $hiboutik = new HiboutikAPI($config['HIBOUTIK_ACCOUNT']);
      $hiboutik->oauth($config['HIBOUTIK_OAUTH_TOKEN']);
    }
    return $hiboutik;
  }


/**
 * Generate and check hashes
 *
 * @param string $string Value to hash
 * @param string $key Shared secret key used to generate the HMAC
 * @param string $check_hash Optional; hash to compare
 *
 * @return string|bool
 */
  public static function myHash($string, $key, $check_hash = null)
  {
    $hmac = hash_hmac('sha256', $string, $key);

    if ($check_hash === null) {
      return $hmac;
    }

    // Preventing timing attacks
    if (function_exists('\hash_equals'/* notice the namespace */)) {
      return hash_equals($check_hash, $hmac);
    }

    // Preventing timing attacks for PHP < v5.6.0
    $len_hash = strlen($hmac);
    $len_hash_rcv = strlen($check_hash);
    if ($len_hash !== $len_hash_rcv) {
      return false;
    }
    $equal = true;
    for ($i = $len_hash - 1; $i !== -1; $i--) {
      if ($hmac[$i] !== $check_hash[$i]) {
        $equal = false;
      }
    }
    return $equal;
  }

/**
 * Generate token for authentication
 *
 */
  public static function getSecurityToken()
  {
    if (self::$security_token !== null) {
      return self::$security_token;
    }
    $config = self::getHiboutikConfiguration();
    return self::$security_token = self::SECURITY_GET_PARAM.'='.self::myHash($config['HIBOUTIK_ACCOUNT'], $config['HIBOUTIK_KEY']);
  }


/**
 * Authentication
 *
 * @param array|null $config
 * @return bool
 */
  public static function authenticate($config = null)
  {
    $config = $config ? $config : (self::getHiboutikConfiguration());
    $key = $config['HIBOUTIK_KEY'];
    if (!$key) {
      $key = $config['HIBOUTIK_OAUTH_TOKEN'];
    }
    $hash = isset($_GET[self::SECURITY_GET_PARAM]) ? $_GET[self::SECURITY_GET_PARAM] : null;
    if ($hash === null or !self::myHash($config['HIBOUTIK_ACCOUNT'], $key, $hash)) {
      return false;
    }
    return true;
  }


/**
 * Manage logs
 *
 * Creates log directory and files. Performs logrotate.
 *
 * @param void
 * @throws \Exception If the directory cannot be created or it is not writable
 * @return void
 */
  public static function checkLogs()
  {
    $dest_dir = self::$plugin_dir.'/log';
    $dest = "$dest_dir/hiboutik.log";
    if (!file_exists($dest_dir) and !mkdir($dest_dir)) {
      throw new \Exception('Plugin WooCommerce-Hiboutik: Cannot create log directory -> '.$dest_dir.'. Check permissions or disable logging in Options to stop this message.', 4);
    }
    if (!file_exists($dest) and !touch($dest)) {
      throw new \Exception('Plugin WooCommerce-Hiboutik: Cannot create log file -> '.$dest.'. Check permissions or disable logging in Options to stop this message.', 5);
    }
    // Rudimentary logrotate
    $n_logs = 10;// number of old log files to keep
    if (filesize($dest) > 1e6) {
      for ($i = $n_logs; $i !== 1; $i--) {
        if (!file_exists("$dest.".($i - 1))) {
          continue;
        }
        if (!rename("$dest.".($i - 1), "$dest.$i")) {
          throw new \Exception('Plugin WooCommerce-Hiboutik: Cannot manipulate log files in '.$dest_dir.'. Check permissions or disable logging in Options to stop this message.', 6);
        }
      }
      $old_log_file = "$dest_dir/hiboutik.log.1";
      if (rename($dest, $old_log_file)) {
        touch($dest);
      } else {
        throw new \Exception('Plugin WooCommerce-Hiboutik: Cannot manipulate log files in '.$dest_dir.'. Check permissions or disable logging in Options to stop this message.', 7);
      }
    }
  }


/**
 * Write or email log messages
 *
 * @param string $msg Message to log
 * @return void
 */
  public static function writeLog($msg = null)
  {
    if (is_null($msg) or HIBOUTIK_LOG == 0) {
      return;
    }

    $line = PHP_EOL.'['.date('d-M-Y H:i:s e').'] '.$msg;
    if (HIBOUTIK_LOG_MAIL != '') {
      /* Cannot modify the email's default subject so I modify the 'From:' header
      to know where it originated */
      error_log($line, 1, HIBOUTIK_LOG_MAIL, "From: WooCommerce-Hiboutik\n");
    } else {
      error_log($line, 3, self::$plugin_dir.'/log/hiboutik.log');
    }
  }
}
