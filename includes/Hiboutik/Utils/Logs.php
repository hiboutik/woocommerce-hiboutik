<?php
namespace Hiboutik\Utils;


class Logs
{
  /** @var string Destination */
  public static $destination = __DIR__.'default.log';


/**
 * Log data
 *
 * @param mixed $data
 * @return void
 */
  public static function write($data)
  {
    $log_file = fopen(self::$destination, 'a');
    if (is_array($data) or is_object($data)) {
      $data = print_r($data, true);
    }
    fwrite($log_file, PHP_EOL.'['.date('d-M-Y H:i:s e').'] '.$data);
    fclose($log_file);
  }
}
