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

        // Enrich OneSignal notification content (title, image, postId)
        add_filter('onesignal_send_notification', array($this, 'onesignal_notification_filter'), 10, 2);

        // Gutenberg publishes via REST — $_POST is empty so OneSignal skips sending.
        // We call onesignal_create_notification() directly, respecting dashboard settings.
        add_action('wp_after_insert_post', array($this, 'onesignal_gutenberg_fix'), 10, 4);

        // Post formats support for the active theme
        add_action('after_setup_theme', array($this, 'add_post_format_support'));
    }

    // -------------------------------------------------------------------------
    // Post meta registration
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
                $url = wp_get_attachment_image_url($att_id, 'full') ?: '';
                return $this->public_url($url);
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

        // Post format: standard | gallery | video | quote | audio | image | link
        $_data['custom']['format'] = get_post_format($post->ID) ?: 'standard';

        // Gallery images (full-size, public URLs)
        $_data['custom']['gallery_images'] = $this->get_gallery_images($post);

        $data->data = $_data;
        return $data;
    }

    // -------------------------------------------------------------------------
    // View count
    // -------------------------------------------------------------------------

    function increment_view_count($data, $post, $request)
    {
        // Only count single-post GET requests, not collection requests
        if ($request->get_method() !== 'GET') return $data;
        if (!isset($request->get_url_params()['id'])) return $data;

        $count = (int) get_post_meta($post->ID, 'post_views_count', true);
        update_post_meta($post->ID, 'post_views_count', $count + 1);

        // Also update the value we already put in the response
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
    // OneSignal: Gutenberg REST fix + notification enrichment
    // -------------------------------------------------------------------------

    /**
     * Gutenberg publishes via REST so $_POST is empty — OneSignal's
     * onesignal_schedule_notification() returns early because os_update is unset.
     *
     * We hook wp_after_insert_post, check the exact same dashboard settings
     * (notification_on_post / notification_on_post_update), remove OneSignal's
     * transition hook to prevent double-sending, then call their function directly.
     */
    function onesignal_gutenberg_fix($post_id, $post, $update, $post_before)
    {
        if (!defined('REST_REQUEST') || !REST_REQUEST) return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!function_exists('onesignal_create_notification')) return;

        $s           = get_option('OneSignalWPSetting', array());
        $is_new      = !$update || ($post_before && $post_before->post_status !== 'publish');
        $is_update   = $update && $post_before && $post_before->post_status === 'publish';
        $post_type   = $post->post_type;
        $post_status = $post->post_status;

        if ($post_status !== 'publish') return;

        $should_send = false;
        if ($post_type === 'post') {
            if ($is_new    && !empty($s['notification_on_post']))        $should_send = true;
            if ($is_update && !empty($s['notification_on_post_update'])) $should_send = true;
        }
        if ($post_type === 'page') {
            if ($is_new    && !empty($s['notification_on_page']))        $should_send = true;
            if ($is_update && !empty($s['notification_on_page_update'])) $should_send = true;
        }

        if (!$should_send) return;

        // Remove OneSignal's own hook to prevent double-send
        remove_action('transition_post_status', 'onesignal_schedule_notification', 10);
        onesignal_create_notification($post);
        add_action('transition_post_status', 'onesignal_schedule_notification', 10, 3);
    }

    function onesignal_notification_filter($fields, $post_id)
    {
        $post = get_post($post_id);
        if (!$post) return $fields;

        // Heading: site name  |  Body: post title
        $fields['headings'] = array('en' => get_bloginfo('name'));
        $fields['contents'] = array('en' => $post->post_title);

        // Featured image
        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id) {
            $full   = $this->public_url(wp_get_attachment_image_url($thumb_id, 'full')   ?: '');
            $medium = $this->public_url(wp_get_attachment_image_url($thumb_id, 'medium') ?: '');
            if ($full)   { $fields['big_picture'] = $full; $fields['ios_attachments'] = array('id' => $full); }
            if ($medium) { $fields['large_icon']  = $medium; }
        }

        // postId for Flutter deep-link; strip URL fields to avoid Android browser chooser
        unset($fields['url'], $fields['web_url'], $fields['app_url']);
        $fields['data'] = array('postId' => $post_id);

        return $fields;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Replace the internal WordPress siteurl (may be localhost in Docker) with
     * the public-facing URL defined in the WORDPRESS_SITE_URL env variable.
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
