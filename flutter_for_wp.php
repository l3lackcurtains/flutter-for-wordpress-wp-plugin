<?php
/**
 * Plugin Name: Flutter for WordPress
 * Description: Custom REST API fields for the Flutter WordPress News App.
 * Version:     1.1.0
 * License:     GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class FlutterForWordpress
{
    function __construct()
    {
        // Custom fields on post REST response
        add_filter('rest_prepare_post', array($this, 'custom_rest_fields'), 10, 3);

        // Allow guest (anonymous) comments via REST API
        add_filter('rest_allow_anonymous_comments', '__return_true');

        // category_image field on /wp/v2/categories
        add_action('rest_api_init', array($this, 'register_category_image_field'));

        // td_post_video meta readable + writable via REST
        add_action('init', array($this, 'register_post_meta'));

        // Increment view count on each REST post fetch
        add_filter('rest_prepare_post', array($this, 'increment_view_count'), 20, 3);

        // Post formats support for the active theme
        add_action('after_setup_theme', array($this, 'add_post_format_support'));

        // Add featured image + postId to OneSignal notifications
        // Uses the official onesignal_send_notification filter (v3 signature)
        // See: https://documentation.onesignal.com/docs/wordpress#can-i-modify-the-notification-parameters-before-sending
        add_filter('onesignal_send_notification', array($this, 'onesignal_notification_filter'), 10, 2);
    }

    // -------------------------------------------------------------------------
    // Post meta
    // -------------------------------------------------------------------------

    function register_post_meta()
    {
        register_post_meta('post', 'td_post_video', array(
            'type'          => 'string',
            'description'   => 'Video URL for video format posts',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function() { return current_user_can('edit_posts'); },
        ));

        register_post_meta('post', 'post_views_count', array(
            'type'          => 'integer',
            'description'   => 'Number of times this post has been viewed via REST',
            'single'        => true,
            'show_in_rest'  => false,
            'auth_callback' => '__return_false',
        ));
    }

    // -------------------------------------------------------------------------
    // Post formats
    // -------------------------------------------------------------------------

    function add_post_format_support()
    {
        add_theme_support('post-formats', array(
            'aside', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio', 'chat',
        ));
    }

    // -------------------------------------------------------------------------
    // Category image field
    // -------------------------------------------------------------------------

    function register_category_image_field()
    {
        register_term_meta('category', 'category_image_id', array(
            'type'          => 'integer',
            'description'   => 'Attachment ID for the category featured image',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function() { return current_user_can('edit_posts'); },
        ));

        register_rest_field('category', 'category_image', array(
            'get_callback' => function($term) {
                $att_id = (int) get_term_meta($term['id'], 'category_image_id', true);
                if (!$att_id) return '';
                return $this->public_url(wp_get_attachment_image_url($att_id, 'full') ?: '');
            },
            'schema' => array(
                'description' => 'Featured image URL for the category',
                'type'        => 'string',
                'context'     => array('view', 'embed'),
            ),
        ));
    }

    // -------------------------------------------------------------------------
    // Custom REST fields on posts
    // -------------------------------------------------------------------------

    function custom_rest_fields($data, $post, $request)
    {
        $_data = $data->data;

        $_data['custom']['featured_image'] = $this->public_url(
            get_the_post_thumbnail_url($post->ID, 'full') ?: ''
        );

        $_data['custom']['td_video'] = get_post_meta($post->ID, 'td_post_video', true) ?: '';

        $author_id = (int) $_data['author'];
        $_data['custom']['author'] = array(
            'id'     => $author_id,
            'name'   => get_the_author_meta('display_name', $author_id),
            'avatar' => $this->public_url(get_avatar_url($author_id, array('size' => 96))),
        );

        $_data['custom']['categories']    = get_the_category($post->ID);
        $_data['custom']['comment_count'] = (int) get_comments_number($post->ID);
        $_data['custom']['view_count']    = (int) get_post_meta($post->ID, 'post_views_count', true);
        $_data['custom']['format']        = get_post_format($post->ID) ?: 'standard';
        $_data['custom']['gallery_images'] = $this->get_gallery_images($post);

        $data->data = $_data;
        return $data;
    }

    // -------------------------------------------------------------------------
    // View count
    // -------------------------------------------------------------------------

    function increment_view_count($data, $post, $request)
    {
        if ($request->get_method() !== 'GET') return $data;
        if (!isset($request->get_url_params()['id'])) return $data;

        $count = (int) get_post_meta($post->ID, 'post_views_count', true);
        update_post_meta($post->ID, 'post_views_count', $count + 1);
        $data->data['custom']['view_count'] = $count + 1;

        return $data;
    }

    // -------------------------------------------------------------------------
    // Gallery images
    // -------------------------------------------------------------------------

    private function get_gallery_images($post)
    {
        $images = array();
        if (get_post_format($post->ID) !== 'gallery') return $images;

        $content = $post->post_content;
        $ids     = array();

        // Gutenberg block: <!-- wp:image {"id":123,...} -->
        if (preg_match_all('/"id"\s*:\s*(\d+)/', $content, $m)) {
            $ids = array_merge($ids, array_map('intval', $m[1]));
        }

        // Classic shortcode: [gallery ids="1,2,3"]
        if (preg_match('/\[gallery[^\]]*ids=["\']?([\d,]+)["\']?/i', $content, $m)) {
            $ids = array_merge($ids, array_map('intval', explode(',', $m[1])));
        }

        foreach (array_unique(array_filter($ids)) as $att_id) {
            if (get_post_type($att_id) !== 'attachment') continue;
            $url = wp_get_attachment_image_url($att_id, 'full');
            if (!$url) continue;
            $images[] = array(
                'id'      => $att_id,
                'url'     => $this->public_url($url),
                'alt'     => get_post_meta($att_id, '_wp_attachment_image_alt', true) ?: '',
                'caption' => wp_get_attachment_caption($att_id) ?: '',
            );
        }

        return $images;
    }

    // -------------------------------------------------------------------------
    // OneSignal notification enrichment
    // -------------------------------------------------------------------------

    /**
     * Adds featured image to the notification.
     * Title and body are already set by OneSignal using the site name and post title.
     * When/whether to send is fully controlled by the OneSignal dashboard settings.
     *
     * @see https://documentation.onesignal.com/docs/wordpress#can-i-modify-the-notification-parameters-before-sending
     */
    function onesignal_notification_filter($fields, $post_id)
    {
        $thumb_id = get_post_thumbnail_id($post_id);
        if (!$thumb_id) return $fields;

        $full   = $this->public_url(wp_get_attachment_image_url($thumb_id, 'full')   ?: '');
        $medium = $this->public_url(wp_get_attachment_image_url($thumb_id, 'medium') ?: '');

        if ($full)   { $fields['big_picture'] = $full; $fields['ios_attachments'] = array('id' => $full); }
        if ($medium) { $fields['large_icon']  = $medium; }

        // Pass postId so Flutter app can deep-link to the article
        $fields['data'] = array('postId' => $post_id);

        return $fields;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Replace internal siteurl (may be localhost in Docker) with the
     * public-facing URL from the WORDPRESS_SITE_URL environment variable.
     */
    private function public_url($url)
    {
        if (empty($url)) return $url;
        $public   = rtrim(getenv('WORDPRESS_SITE_URL') ?: get_option('siteurl'), '/');
        $internal = rtrim(get_option('siteurl'), '/');
        if ($public !== $internal) {
            $url = str_replace($internal, $public, $url);
        }
        return $url;
    }
}

new FlutterForWordpress();
