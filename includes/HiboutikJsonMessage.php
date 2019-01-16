<?php
namespace Hiboutik;


/**
 * @package Hiboutik\HiboutikJsonMessage
 *
 * @version 1.0.0
 * @author  Hiboutik
 *
 * @license GPLv3
 * @license https://gnu.org/licenses/gpl.html
 *
 * Manage responses to Hiboutik webhooks
 */
class HiboutikJsonMessage
{
  /** @var string $message */
  public $message = '';


/**
 * Default constructor
 *
 * @param void
 */
  public function __construct()
  {}


/**
 * Create alert message
 *
 * @param string $type Alert type
 * @param string $message Message
 * @return Hiboutik\HiboutikJsonMessage
 */
  public function alert($type, $message)
  {
    switch ($type) {
      case 'success':
        $class_type = 'alert-success';
        $icon = "fa-check-circle";
        break;
      case 'info':
        $class_type = 'alert-info';
        $icon = "fa-info-circle";
        break;
      case 'warning':
        $class_type = 'alert-warning';
        $icon = "fa-exclamation-circle";
        break;
      case 'danger':
        $class_type = 'alert-danger';
        $icon = "fa-ban";
        break;
      default:
        $class_type = 'alert-default';
        $icon = "fa-info-circle";
    }
    $this->message .= <<<HTML
<div class="alert {$class_type} alert-dismissable m-t-xs m-r-xs m-l-xs m-b-xs">
  <button aria-hidden="true" data-dismiss="alert" class="close" type="button">×</button>
  <strong><span class="fa {$icon} fa-lg"></span></strong> $message
</div>
HTML;
    return $this;
  }


/**
 * Print alert message(s)
 *
 * @param void
 * @return void
 */
  public function show()
  {
    header('Content-type: application/json; charset=utf-8');
    print json_encode(['alerte' => $this->message]);
  }
}

