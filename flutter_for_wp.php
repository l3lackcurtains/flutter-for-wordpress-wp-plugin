<?php
/**
 * Plugin Name: Flutter for WordPress
 * Description: Custom REST API fields for the Flutter WordPress News App.
 * Version:     1.0.0
 * License:     GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class FlutterForWordpress
{
    function __construct()
    {
        // Add custom fields to the post REST response
        add_filter('rest_prepare_post', array($this, 'custom_rest_fields'), 10, 3);

        // Allow guest (anonymous) comments via REST API
        add_filter('rest_allow_anonymous_comments', '__return_true');

        // Expose category_image URL on the /wp/v2/categories endpoint
        add_action('rest_api_init', array($this, 'register_category_image_field'));

        // Register td_post_video meta so it is readable + writable via REST
        add_action('init', array($this, 'register_post_meta'));

        // Enrich OneSignal push notifications with excerpt, thumbnail and postId
        add_filter('onesignal_send_notification', array($this, 'onesignal_notification_filter'), 10, 4);

        // Enable post formats support for the default theme
        add_action('after_setup_theme', array($this, 'add_post_format_support'));
    }

    function register_post_meta()
    {
        register_post_meta('post', 'td_post_video', array(
            'type'          => 'string',
            'description'   => 'Video URL for video format posts',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function() { return current_user_can('edit_posts'); },
        ));
    }

    function add_post_format_support()
    {
        add_theme_support('post-formats', array(
            'aside', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio', 'chat',
        ));
    }

    function register_category_image_field()
    {
        register_term_meta('category', 'category_image_id', array(
            'type'          => 'string',
            'description'   => 'Attachment ID for the category featured image',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function() { return current_user_can('edit_posts'); },
        ));

        register_rest_field('category', 'category_image', array(
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
        ));
    }

    function custom_rest_fields($data, $post, $request)
    {
        $_data = $data->data;

        $_data['custom']['featured_image'] = get_the_post_thumbnail_url($post->ID, 'full') ?: '';
        $_data['custom']['td_video']       = get_post_meta($post->ID, 'td_post_video', true) ?: '';
        $_data['custom']['author'] = array(
            'id'     => (int) $_data['author'],
            'name'   => get_author_name($_data['author']),
            'avatar' => get_avatar_url($_data['author']),
        );
        $_data['custom']['categories']    = get_the_category($_data['id']);
        $_data['custom']['comment_count'] = (int) get_comments_number($post->ID);
        $_data['custom']['view_count']    = (int) get_post_meta($post->ID, 'post_views_count', true);

        // Post format — 'standard', 'gallery', 'video', 'quote', 'audio', 'image', 'link'
        $_data['custom']['format'] = get_post_format($post->ID) ?: 'standard';

        // Gallery: extract full-size image URLs from the [gallery] shortcode
        $_data['custom']['gallery_images'] = $this->get_gallery_images($post);

        $data->data = $_data;
        return $data;
    }

    /**
     * Extract full-size gallery image URLs from post content.
     * Handles both classic [gallery ids="..."] shortcode and
     * Gutenberg wp:image / wp:gallery blocks.
     */
    private function get_gallery_images($post)
    {
        $images = array();
        if (get_post_format($post->ID) !== 'gallery') return $images;

        $content = $post->post_content;
        $ids = array();

        // 1. Gutenberg block format: <!-- wp:image {"id":123,...} -->
        if (preg_match_all('/"id"\s*:\s*(\d+)/', $content, $block_matches)) {
            $ids = array_merge($ids, array_map('intval', $block_matches[1]));
        }

        // 2. Classic shortcode: [gallery ids="1,2,3"]
        if (preg_match('/\[gallery[^\]]*ids=["\']?([\d,]+)["\']?/i', $content, $sc_matches)) {
            $ids = array_merge($ids, array_map('intval', explode(',', $sc_matches[1])));
        }

        $ids = array_unique(array_filter($ids));

        foreach ($ids as $att_id) {
            // Only include image attachments
            if (get_post_type($att_id) !== 'attachment') continue;
            $url = wp_get_attachment_image_url($att_id, 'full');
            if (!$url) continue;
            $alt     = get_post_meta($att_id, '_wp_attachment_image_alt', true) ?: '';
            $caption = wp_get_attachment_caption($att_id) ?: '';
            $images[] = array(
                'id'      => $att_id,
                'url'     => $url,
                'alt'     => $alt,
                'caption' => $caption,
            );
        }
        return $images;
    }

    function onesignal_notification_filter($fields, $new_status, $old_status, $post)
    {
        // --- Excerpt as notification body ---
        $excerpt = '';
        if (!empty($post->post_excerpt)) {
            $excerpt = wp_strip_all_tags($post->post_excerpt);
        } else {
            $excerpt = wp_trim_words(wp_strip_all_tags($post->post_content), 20, '...');
        }
        if (!empty($excerpt)) {
            $fields['contents'] = array('en' => $excerpt);
        }

        // --- Featured image as big picture (Android) and large icon ---
        $thumb_id = get_post_thumbnail_id($post->ID);
        if ($thumb_id) {
            $full = wp_get_attachment_image_url($thumb_id, 'full');
            $medium = wp_get_attachment_image_url($thumb_id, 'medium');
            if ($full) {
                $fields['big_picture']     = $full;   // Android large image
                $fields['ios_attachments'] = array('id' => $full); // iOS rich media
            }
            if ($medium) {
                $fields['large_icon'] = $medium; // Android large icon (right side)
            }
        }

        // --- Pass postId for deep-linking in the Flutter app ---
        $fields['data'] = array(
            'postId' => $post->ID,
            'url'    => get_permalink($post->ID),
        );

        return $fields;
    }
}

new FlutterForWordpress();
