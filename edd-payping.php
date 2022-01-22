<?php
/*
Plugin Name: Gateway PayPing Easy Digital Downloads
Version: 1.1.0
Description:  افزونه درگاه پرداخت پی‌پینگ برای Easy Digital Downloads
Plugin URI: https://www.payping.ir/
Author: mahdi sarani
Author URI: https://mahdisarani.ir
*/

define('EDD_GPPDIR', plugin_dir_path( __FILE__ ));
/* Toman Currency */
require 'includes/toman-currency.php';

require 'gateways/payping.php';