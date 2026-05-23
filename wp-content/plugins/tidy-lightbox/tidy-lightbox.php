<?php
/**
 * Plugin Name: Tidy Lightbox
 * Description: Lightweight lightbox for WordPress image and gallery blocks. No dependencies, no upsells.
 * Version: 1.0.0
 * Author: Robby McCullough
 */

if ( ! defined( 'ABSPATH' ) ) die();

// ---------------------------------------------------------------------------
// Enqueue assets only on singular posts/pages that have images
// ---------------------------------------------------------------------------

add_action( 'wp_enqueue_scripts', 'tidy_lightbox_enqueue' );

function tidy_lightbox_enqueue() {
    if ( ! is_singular() ) return;

    $base = plugin_dir_url( __FILE__ ) . 'assets/';
    $v    = '1.0.0';

    wp_enqueue_style(  'tidy-lightbox', $base . 'lightbox.css', [], $v );
    wp_enqueue_script( 'tidy-lightbox', $base . 'lightbox.js',  [], $v, true );
}

// ---------------------------------------------------------------------------
// Content filter — wrap block images with lightbox anchor tags
// ---------------------------------------------------------------------------

add_filter( 'the_content', 'tidy_lightbox_process', 20 );

function tidy_lightbox_process( $content ) {
    // Only run on singular views in the main loop
    if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) return $content;
    if ( strpos( $content, 'wp-image-' ) === false ) return $content;

    global $post;
    $post_id       = $post->ID;
    $gallery_count = 0;

    // --- 1. Gallery blocks: images in the same gallery share a group ---
    $content = preg_replace_callback(
        '/<figure\b[^>]*\bwp-block-gallery\b[^>]*>(.+?)<\/figure>/s',
        function ( $m ) use ( $post_id, &$gallery_count ) {
            $gallery_count++;
            $group    = 'post-' . $post_id . '-gallery-' . $gallery_count;
            $inner    = tidy_lightbox_wrap_images_in( $m[1], $group );
            return str_replace( $m[1], $inner, $m[0] );
        },
        $content
    );

    // --- 2. Standalone image blocks (not inside a gallery) ---
    //    All standalone images in a post share one group so you can arrow
    //    through all of them.
    $standalone_group = 'post-' . $post_id . '-images';
    $content = preg_replace_callback(
        '/<figure\b[^>]*\bwp-block-image\b[^>]*>(.+?)<\/figure>/s',
        function ( $m ) use ( $standalone_group ) {
            // Skip if already processed by the gallery pass
            if ( preg_match( '/<a\b[^>]*class="[^"]*tidy-lb/s', $m[1] ) ) return $m[0];
            $inner = tidy_lightbox_wrap_images_in( $m[1], $standalone_group );
            return str_replace( $m[1], $inner, $m[0] );
        },
        $content
    );

    return $content;
}

/**
 * Wrap every <img class="wp-image-{id}"> inside $html with a lightbox <a>.
 * Handles two cases:
 *   1. Image already inside a non-lightbox <a> (linkDestination:media) — replaces the anchor.
 *   2. Bare image with no anchor (linkDestination:none) — wraps it.
 */
function tidy_lightbox_wrap_images_in( $html, $group ) {
    // Pass 1: images inside an existing non-lightbox <a> — swap the anchor for ours.
    $html = preg_replace_callback(
        '/<a\b(?![^>]*class="[^"]*tidy-lb)[^>]*>\s*(<img\b[^>]*\bwp-image-(\d+)\b[^>]*>)\s*<\/a>/s',
        function ( $m ) use ( $group ) {
            $attachment_id = (int) $m[2];
            $full_url      = wp_get_attachment_url( $attachment_id );
            if ( ! $full_url ) return $m[0];
            $caption = wp_get_attachment_caption( $attachment_id );
            $alt     = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
            $label   = $caption ?: $alt;
            return sprintf(
                '<a href="%s" class="tidy-lb" data-gallery="%s" data-caption="%s">%s</a>',
                esc_url( $full_url ),
                esc_attr( $group ),
                esc_attr( $label ),
                $m[1]
            );
        },
        $html
    );

    // Pass 2: bare images not inside any anchor — wrap them.
    // The alternation skips over already-processed tidy-lb anchors so images
    // inside them are not double-wrapped.
    $html = preg_replace_callback(
        '/<a\b[^>]*class="[^"]*tidy-lb[^"]*"[^>]*>.*?<\/a>|(<img\b[^>]*\bwp-image-(\d+)\b[^>]*>)/s',
        function ( $m ) use ( $group ) {
            if ( empty( $m[1] ) ) return $m[0]; // matched a tidy-lb anchor — leave it alone
            $attachment_id = (int) $m[2];
            $full_url      = wp_get_attachment_url( $attachment_id );
            if ( ! $full_url ) return $m[0];
            $caption = wp_get_attachment_caption( $attachment_id );
            $alt     = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
            $label   = $caption ?: $alt;
            return sprintf(
                '<a href="%s" class="tidy-lb" data-gallery="%s" data-caption="%s">%s</a>',
                esc_url( $full_url ),
                esc_attr( $group ),
                esc_attr( $label ),
                $m[1]
            );
        },
        $html
    );

    return $html;
}
