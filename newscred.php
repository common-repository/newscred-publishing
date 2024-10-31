<?php
/*
Plugin Name: Welcome Software CMP Integration
Description: Supercharge your Welcome Software CMP experience with this plugin
Version: 0.0.31
Author: Welcome Software
Author URI: http://welcomesoftware.com
*/

define('NC_CF_PREVIEW_TOKEN', '_nc-preview-token');
define('NC_QP_PREVIEW_TOKEN', 'preview_token');
define('NC_CF_OG_IMAGE', '_yoast_wpseo_opengraph-image');

add_filter( 'xmlrpc_methods', 'nc_xmlrpc_methods' );

require_once ABSPATH . 'wp-admin/includes/post.php';
require_once(plugin_dir_path(__FILE__).'yoast.php');

include_once ABSPATH . 'wp-admin/includes/plugin.php';

add_action( 'init', 'nc_register_preview_status' );

add_action('wp_head', 'js_tracker_inserter');
add_action('wp_head', 'website_widget_js_inserter');
add_action('wp_head', 'nc_meta_tags');

add_filter( 'posts_results', 'nc_check_draft_query', null, 2 );

add_filter( 'user_has_cap', 'nc_allow_protected_meta_edit', 0, 3 );

if (is_plugin_active('search-everything/search-everything.php')) {
  // Search Everything uses search filters which are suppressed by default by
  // the XMLRPC search endpoint, so we need to un-suppress it
  add_action('parse_query', function ($wp_query) {
    $wp_query->query_vars['suppress_filters'] = false;
  });
}

function nc_xmlrpc_methods( $methods ) {
    $methods['wp.getPost'] = 'nc_getPost';
    $methods['nc.clonePost'] = 'nc_clonePost';
    $methods['nc.editImageMeta'] = 'nc_editImageMeta';
    $methods['nc.getOption'] = 'nc_getOption';
    $methods['nc.setOption'] = 'nc_setOption';
    $methods['nc.getPluginVersions'] = 'nc_getPluginVersions';
    return $methods;
}

function nc_getPost( $args ) {
    global $wp_xmlrpc_server;

    if ( ! isset( $args[4] ) ) {
        $args[4] = apply_filters( 'xmlrpc_default_post_fields', array( 'post', 'terms', 'custom_fields' ), 'nc_getPost' );
    }

    $post_obj = $wp_xmlrpc_server->wp_getPost( $args );

    if ($post_obj instanceof IXR_Error) {
      return $post_obj;
    }

    $post_obj['permalink'] = nc_getPermalinkPath( $post_obj['post_id'] );
    $post_obj['slug'] = nc_getSlug( $post_obj['post_id'] );

    $nc_token = get_post_meta($post_obj['post_id'], NC_CF_PREVIEW_TOKEN, true);
    if ($nc_token) {
      $post_obj['preview_link'] = add_query_arg(NC_QP_PREVIEW_TOKEN, $nc_token, $post_obj['link']);
    }

    return $post_obj;
}

function nc_clonePost( $args ) {
    /** @var wp_xmlrpc_server $wp_xmlrpc_server */
    global $wp_xmlrpc_server;
    $wp_xmlrpc_server->escape( $args );

    $username = $args[1];
    $password = $args[2];
    $original_post_id = (int) $args[3];

    if ( ! $wp_xmlrpc_server->login( $username, $password ) )
        return $wp_xmlrpc_server->error;

    $post = $wp_xmlrpc_server->wp_getPost( $args );
    if ($post instanceof IXR_Error) {
      return $post;
    }

    $new_post = array(
        'comment_status' => $post['comment_status'],
        'ping_status'    => $post['ping_status'],
        'post_author'    => $post['post_author'],
        'post_content'   => $post['post_content'],
        'post_excerpt'   => $post['post_excerpt'],
        'post_date'      => $post['post_date'],
        'post_name'      => 'nc-preview-' . $post['post_id'],
        'post_parent'    => $post['post_parent'],
        'post_password'  => $post['post_password'],
        'post_status'    => 'preview',
        'post_title'     => $post['post_title'],
        'sticky'         => $post['sticky'],
        'post_format'    => $post['post_format'],
        'post_type'      => $post['post_type'],
        'to_ping'        => $post['to_ping'],
        'menu_order'     => $post['menu_order'],
        'custom_fields'  => _nc_clone_custom_fields( $post['custom_fields'] )
    );

    $post_thumbnail = $post['post_thumbnail'];
    if (!empty($post_thumbnail['attachment_id'])) {
        $cloned_attachment = _nc_clone_featured_image( $args, get_post($post_thumbnail['attachment_id']) );
        if ($cloned_attachment instanceof IXR_Error) {
            return $cloned_attachment;
        }

        if (isset($cloned_attachment['id'])) {
            $new_post['post_thumbnail'] = $cloned_attachment['id'];
        }
    }

    $args[3] = $new_post;
    $cloned_post_id = $wp_xmlrpc_server->wp_newPost( $args );
    if ($cloned_post_id instanceof IXR_Error) {
        return $cloned_post_id;
    }

    _nc_clone_taxonomies( $post['post_type'], $original_post_id, $cloned_post_id );

    return (string) $cloned_post_id;
}

function _nc_clone_taxonomies( $post_type, $original_post_id, $new_post_id ) {
    $taxonomies = get_object_taxonomies($post_type);

    foreach ($taxonomies as $taxonomy) {
        $post_terms = wp_get_object_terms($original_post_id, $taxonomy, array('fields' => 'slugs'));
        wp_set_object_terms($new_post_id, $post_terms, $taxonomy, false);
    }
}

function _nc_clone_custom_fields( $original_post_fields ) {
    $new_fields_array = array();

    foreach ($original_post_fields as $field) {
        $field_array = array('key' => $field['key'], 'value' => $field['value']);
        array_push($new_fields_array, $field_array);
    }

    return $new_fields_array;
}

function _nc_clone_featured_image( $args, $media_item ) {
    /** @var wp_xmlrpc_server $wp_xmlrpc_server */
    global $wp_xmlrpc_server;

    $image_url = wp_get_attachment_url( $media_item->ID );
    $image_data = file_get_contents( $image_url );
    $filename = basename( $image_url );
    $wp_filetype = wp_check_filetype( $filename, null );
    $args[3] = array(
        'name' => sanitize_file_name($filename),
        'type' => $wp_filetype['type'],
        'bits' => $image_data,
        'overwrite' => false
    );
    $new_attachment = $wp_xmlrpc_server->mw_newMediaObject( $args );
    if ($new_attachment instanceof IXR_Error) {
        return $new_attachment;
    }

    $args[3] = $new_attachment['id'];
    $args[4] = array(
        'post_excerpt' => $media_item->post_excerpt,
        'image_alt' => get_post_meta( $media_item->ID, '_wp_attachment_image_alt', true)
    );
    nc_editImageMeta( $args );

    return $new_attachment;
}

function nc_getPermalinkPath ( $post_id ) {
    list( $permalink, $postname ) = get_sample_permalink( $post_id );

    return $permalink;
}

function nc_getSlug ( $post_id ) {
    list( $permalink, $postname ) = get_sample_permalink( $post_id );

    return $postname;
}

function nc_editImageMeta( $args ) {
    /** @var wp_xmlrpc_server $wp_xmlrpc_server */
    global $wp_xmlrpc_server;
    $wp_xmlrpc_server->escape( $args );

    $username = $args[1];
    $password = $args[2];
    $post_id  = (int) $args[3];
    $content_struct = $args[4];

    if ( ! $wp_xmlrpc_server->login( $username, $password ) )
        return $wp_xmlrpc_server->error;

    $my_post = array( 'ID' => $post_id );

    if (array_key_exists("post_excerpt", $content_struct)) {
      $my_post['post_excerpt'] = $content_struct['post_excerpt'];
    }

    if (array_key_exists("post_content", $content_struct)) {
        $my_post['post_content'] = $content_struct['post_content'];
    }

    if (count($my_post) > 1) {
      // Update the post into the database
      wp_update_post( $my_post );
    }

    if (array_key_exists("image_alt", $content_struct)) {
      update_post_meta( $post_id, '_wp_attachment_image_alt', $content_struct['image_alt']);
    }

    return $my_post;
}

function js_tracker_inserter( ) {
    $nc_tracker = get_option('_nc_tracker_script');
    if ($nc_tracker) {
      echo $nc_tracker;
    }
}

function website_widget_js_inserter( ) {
    $nc_website_widget_script = get_option('_nc_website_widget_script');
    if ($nc_website_widget_script) {
      echo $nc_website_widget_script;
    }
}

function nc_getOption( $args ) {
    global $wp_xmlrpc_server;
    $wp_xmlrpc_server->escape( $args );

    $username = $args[1];
    $password = $args[2];
    $option_name = $args[3];

    if ( ! $wp_xmlrpc_server->login( $username, $password ) )
        return $wp_xmlrpc_server->error;
    $data = array();
    $data[$option_name] = get_option($option_name);
    return $data;
}

function nc_setOption( $args ) {
    global $wp_xmlrpc_server;
    $wp_xmlrpc_server->escape( $args );

    $username = $args[1];
    $password = $args[2];
    $option_name = $args[3];
    $option_value = $args[4];

    $decoded_value = str_replace("\\\"", "\"", $option_value, $count);

    if ( ! $wp_xmlrpc_server->login( $username, $password ) )
        return $wp_xmlrpc_server->error;

    update_option($option_name, $decoded_value);

    return true;
}

function nc_getPluginVersions($args) {
    /** @var wp_xmlrpc_server $wp_xmlrpc_server */
    global $wp_xmlrpc_server;
    $wp_xmlrpc_server->escape( $args );

    $username = $args[1];
    $password = $args[2];

    if (!$wp_xmlrpc_server->login( $username, $password)) {
        return $wp_xmlrpc_server->error;
    }

    $plugins = array();
    foreach (get_plugins() as $plugin) {
        $plugins[$plugin['Name']] = $plugin['Version'];
    }

    return $plugins;
}

function nc_check_draft_query( $posts, $query ) {
    if ( sizeof( $posts ) !== 1 )
        return $posts;

    $nc_token = get_post_meta($posts[0]->ID, NC_CF_PREVIEW_TOKEN, true);
    $query_token = isset($_GET[NC_QP_PREVIEW_TOKEN]) ? $_GET[NC_QP_PREVIEW_TOKEN] : null;
    if ((isset($query_token)) && ($query_token === $nc_token)) {
        $posts[0]->post_status = 'publish';
    }

    return $posts;
}

function nc_allow_protected_meta_edit( $allcaps, $cap, $args ) {
    if (in_array($args[0], array( 'edit_post_meta', 'add_post_meta' ))) {
        $userCanEditPost = isset($args[2]) && current_user_can('edit_post', $args[2]);
        $metaKeyIsNotEmpty = isset($args[3]) && $args[3] !== '';
        $metaKeyIsValid = strpos($args[3], '_nc-') === 0 || strpos($args[3], '_yoast_wpseo_opengraph-') === 0;

        if ($userCanEditPost && $metaKeyIsNotEmpty && $metaKeyIsValid) {
            $allcaps[$args[0]] = true;
        }
    }

    return $allcaps;
}

function nc_register_preview_status(){
    register_post_status('preview', array('label' => _x( 'Preview', 'post' ), 'internal' => true));
}

function nc_meta_tags() {
    global $post;

    if (nc_is_yoast_plugin_installed()) {
        return;
    }

    if (!$post || !$post->ID) {
        return;
    }

    $image = get_post_meta($post->ID, NC_CF_OG_IMAGE, true);
    if (!$image) {
        return;
    }

    echo '<meta property="og:image" content="' . esc_attr(esc_url($image)) . '" />' . "\n";
}
