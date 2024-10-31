<?php
require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
add_filter('xmlrpc_prepare_post', 'nc_add_yoast_installation_status', 10, 3);

function nc_add_yoast_installation_status($_post, $post, $fields){
    $_post['is_yoast_installed'] = nc_is_yoast_plugin_installed();
    return $_post;
}

function nc_is_yoast_plugin_installed(){
    return is_plugin_active('wordpress-seo/wp-seo.php') || is_plugin_active('wordpress-seo-premium/wp-seo-premium.php');
}
