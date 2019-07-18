# Hiboutik plugin for Woocommerce

This repository contains the open source plugin that allows you to synchronize Hiboutik POS software and WooCommerce

## Requirements

* PHP 5.3.0 or newer
* PHP cURL extension

## Installation

Download Zip archive

On your Woocommerce admin interface : Plugins > Add new > Upload plugin

Then follow the guide : 

[FR] http://www.logiciel-caisse-gratuit.com/synchronisation-woocommerce-hiboutik/

[EN] http://www.pos-software-free.com/sync-woocommerce-hiboutik/

## Tips

### How to sync all your warehouses

In https://github.com/hiboutik/woocommerce-hiboutik/blob/master/hiboutik_page_sync.php, remplace :
```php
  $stock_available = $hiboutik->get("/stock_available/warehouse_id/{$config['HIBOUTIK_STORE_ID']}");
```
with this :
```php
  $stock_available = $hiboutik->get('/stock_available/all_wh/');
```

In https://github.com/hiboutik/woocommerce-hiboutik/blob/master/woocommerce-hiboutik.php, remplace :
```php
        foreach ($stocks_dispo as $stock) {
          if ($stock['warehouse_id'] == $config['HIBOUTIK_STORE_ID']) {
            $quantity = $stock['stock_available'];
            break;
          }
        }
```
with this :
```php
              $quantity = 0;
          foreach ($stocks_dispo as $stock) {
              $quantity = $quantity + $stock['stock_available'];
          }
```

## Credits

Many have contributed to this plugin effort, from direct contributions of code, to contributions of projects.

Contributors :
* [Murelh Ntyandi](http://www.murelh.info), _(contact@murelh.info)_
* [Salem Design](http://slmdesign.fr/)
* [Hiboutik dev team](https://www.hiboutik.com)

Hiboutik would like to extend its appreciation to [Murelh Ntyandi](http://www.murelh.info) which performed the initial development in 2017.
