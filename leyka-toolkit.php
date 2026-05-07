<?php
/*
Plugin Name: Leyka Toolkit
Description: Extensions for Leyka. Плагин разработан при поддержке БФ <a href="https://menora-ural.ru">«Менора»</a>.
Version: 0.3.12
Requires at least: 4.5
Requires PHP: 5.4
Author: StudioAVP
Text Domain: leyka-toolkit
*/

if (!defined('ABSPATH')) {
    exit;
}

define('LEYKA_TOOLKIT_VERSION', '0.3.12');
define('LEYKA_TOOLKIT_MIN_WP_VERSION', '4.5');
define('LEYKA_TOOLKIT_MIN_PHP_VERSION', '5.4');
define('LEYKA_TOOLKIT_MIN_LEYKA_VERSION', '3.20');
define('LEYKA_TOOLKIT_FILE', __FILE__);
define('LEYKA_TOOLKIT_PATH', plugin_dir_path(__FILE__));

require_once LEYKA_TOOLKIT_PATH . 'includes/class-leyka-toolkit.php';

register_activation_hook(__FILE__, ['Leyka_Toolkit', 'activate']);

Leyka_Toolkit::get_instance();
