<?php
/*
Plugin Name: Leyka Toolkit
Description: Extensions for Leyka.
Version: 0.2.0
Author: StudioAVP
Text Domain: leyka-toolkit
*/

if (!defined('ABSPATH')) {
    exit;
}

define('LEYKA_TOOLKIT_VERSION', '0.2.0');
define('LEYKA_TOOLKIT_FILE', __FILE__);
define('LEYKA_TOOLKIT_PATH', plugin_dir_path(__FILE__));

require_once LEYKA_TOOLKIT_PATH . 'includes/class-leyka-toolkit.php';

register_activation_hook(__FILE__, ['Leyka_Toolkit', 'activate']);

Leyka_Toolkit::get_instance();
