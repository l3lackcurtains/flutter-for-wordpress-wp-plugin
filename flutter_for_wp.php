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
		$_data['custom']["author"]["name"]   = get_author_name($_data['author']);
		$_data['custom']["author"]["avatar"] = get_avatar_url($_data['author']);

		$_data['custom']["categories"] = get_the_category($_data["id"]);

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
