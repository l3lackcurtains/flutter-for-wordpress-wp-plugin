<?php
/**
 * The flutterwp Plugin
 * @package Flutter WP
 * @subpackage Main
 */
/**
 * Plugin Name: Flutter for wordpress (wp plugin)
 * Plugin URI:  http://crumet.com
 * Description: The wordpress plugin required for flutter for wordpress app
 * Author:      Madhav Poudel
 * Author URI:  http://crumet.com
 * Version:     0.0.1
 * Text Domain: flutterwp
 * License:     GPLv2 or later (license.txt)
 */
if (!defined('FLUTTERWP_URL')) {
	define('FLUTTERWP_URL', plugin_dir_url(__FILE__));
}

class FlutterForWordpress
{
    // Constructor
	function __construct()
	{
		add_filter(
			'rest_prepare_post',
			array(
				$this,
				'flutter_for_wordpress_custom_rest_api'
			),
			10,
			3
		);
		add_filter(
			'rest_allow_anonymous_comments',
			array(
				$this,
				'flutter_for_wp_rest_allow_anonymous_comments'
			)
		);

		add_filter(
			'onesignal_send_notification',
			array(
				$this,
				'onesignal_send_notification_modified_filter'
			),
			10,
			4
		);

		// Expose category_image on the /wp/v2/categories REST endpoint
		add_action('rest_api_init', array($this, 'flutter_register_category_image_field'));

		// Allow Application Passwords over plain HTTP (dev/local only)
		add_filter('wp_is_application_passwords_available', '__return_true');
	}

	function flutter_register_category_image_field()
	{
		// Register the term meta so it is readable AND writable via REST
		register_term_meta('category', 'category_image_id', array(
			'type'              => 'string',
			'description'       => 'Attachment ID for the category featured image',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => function() { return current_user_can('edit_posts'); },
		));

		// Expose a resolved image URL as a read-only REST field
		register_rest_field(
			'category',
			'category_image',
			array(
				'get_callback' => function($term) {
					$att_id = get_term_meta($term['id'], 'category_image_id', true);
					if (!$att_id) return '';
					return wp_get_attachment_image_url((int)$att_id, 'full') ?: '';
				},
				'schema' => array(
					'description' => 'Featured image URL for the category',
					'type'        => 'string',
					'context'     => array('view', 'embed'),
				),
			)
		);
	}

	function flutter_for_wp_rest_allow_anonymous_comments()
	{
		return true;
	}

	function flutter_for_wordpress_custom_rest_api($data, $post, $request)
	{
		$_data = $data->data;
		$_data["custom"]["td_video"] = get_post_meta($post->ID, 'td_post_video', true) ?? '';
		$_data['custom']["featured_image"] = get_the_post_thumbnail_url($post->ID, "original") ?? '';
		$_data['custom']["author"]["id"]     = (int) $_data['author'];
		$_data['custom']["author"]["name"]   = get_author_name($_data['author']);
		$_data['custom']["author"]["avatar"] = get_avatar_url($_data['author']);
		$_data['custom']["categories"]    = get_the_category($_data["id"]);
		$_data['custom']["comment_count"] = (int) get_comments_number($post->ID);
		$_data['custom']["view_count"]    = (int) get_post_meta($post->ID, 'post_views_count', true);

		$data->data = $_data;

		return $data;
	}

	function onesignal_send_notification_modified_filter($fields, $new_status, $old_status, $post)
	{
		$fields['isAndroid'] = true;
		$fields['isIos']     = true;

		$fields['web_url'] = $fields['url'];

		$fields['big_picture'] = wp_get_attachment_image_url(get_post_thumbnail_id($post->ID), "original");
		unset($fields['url']);

		$fields['data'] = array(
			"postId" => $post->ID,
			"url" => $fields['web_url']
		);

		$fields['buttons'] = array(
			array(
				"id" => "open",
				"text" => "Open",
				"icon" => "ic_menu_send"
			),
			array(
				"id" => "openbrowser",
				"text" => "Open in Browser",
				"icon" => "ic_menu_send"
			),
			array(
				"id" => "share",
				"text" => "Share",
				"icon" => "ic_menu_share"
			)
		);

		return $fields;
	}
}

new FlutterForWordpress();
