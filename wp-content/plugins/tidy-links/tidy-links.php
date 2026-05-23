<?php
/**
 * Plugin Name: Tidy Links
 * Description: Simple affiliate link redirector. Create short slugs that redirect to any URL — update the destination in one place and every post stays current.
 * Version: 1.0.0
 * Author: Robby McCullough
 */

if ( ! defined( 'ABSPATH' ) ) die();

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, 'tidy_links_activate' );

function tidy_links_activate() {
    global $wpdb;
    $table   = $wpdb->prefix . 'tidy_links';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id            int(11)      NOT NULL AUTO_INCREMENT,
        name          varchar(255) NOT NULL DEFAULT '',
        slug          varchar(255) NOT NULL DEFAULT '',
        url           text         NOT NULL,
        redirect_type varchar(10)  NOT NULL DEFAULT '307',
        created_at    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// ---------------------------------------------------------------------------
// Redirect handler — fires on init before WP routes the request
// ---------------------------------------------------------------------------

add_action( 'init', 'tidy_links_redirect', 1 );

function tidy_links_redirect() {
    if ( is_admin() ) return;

    $path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
    if ( empty( $path ) ) return;

    global $wpdb;
    $table = $wpdb->prefix . 'tidy_links';
    $link  = $wpdb->get_row( $wpdb->prepare(
        "SELECT url, redirect_type FROM {$table} WHERE slug = %s LIMIT 1",
        $path
    ) );

    if ( $link ) {
        $code = in_array( $link->redirect_type, [ '301', '302', '307' ], true )
            ? (int) $link->redirect_type
            : 307;
        wp_redirect( $link->url, $code );
        exit;
    }
}

// ---------------------------------------------------------------------------
// Admin menu
// ---------------------------------------------------------------------------

add_action( 'admin_menu', 'tidy_links_admin_menu' );

function tidy_links_admin_menu() {
    add_menu_page(
        'Tidy Links',
        'Tidy Links',
        'manage_options',
        'tidy-links',
        'tidy_links_admin_page',
        'dashicons-admin-links',
        85
    );
}

// ---------------------------------------------------------------------------
// Admin page router
// ---------------------------------------------------------------------------

function tidy_links_admin_page() {
    global $wpdb;
    $table  = $wpdb->prefix . 'tidy_links';
    $action = sanitize_key( $_GET['action'] ?? 'list' );
    $id     = absint( $_GET['id'] ?? 0 );

    // --- Handle save ---
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer( 'tidy_links_save' ) ) {
        $save_id       = absint( $_POST['id'] ?? 0 );
        $name          = sanitize_text_field( $_POST['name'] );
        $slug          = trim( sanitize_text_field( $_POST['slug'] ), '/' );
        $url           = esc_url_raw( $_POST['url'] );
        $redirect_type = in_array( $_POST['redirect_type'], [ '301', '302', '307' ], true )
            ? $_POST['redirect_type']
            : '307';

        if ( empty( $name ) || empty( $slug ) || empty( $url ) ) {
            tidy_links_notice( 'Name, slug, and URL are all required.', 'error' );
        } else {
            $data = compact( 'name', 'slug', 'url', 'redirect_type' );
            if ( $save_id ) {
                $wpdb->update( $table, $data, [ 'id' => $save_id ] );
            } else {
                $wpdb->insert( $table, $data );
            }
            wp_redirect( admin_url( 'admin.php?page=tidy-links&saved=1' ) );
            exit;
        }
    }

    // --- Handle delete ---
    if ( $action === 'delete' && $id && check_admin_referer( 'tidy_links_delete_' . $id ) ) {
        $wpdb->delete( $table, [ 'id' => $id ] );
        wp_redirect( admin_url( 'admin.php?page=tidy-links&deleted=1' ) );
        exit;
    }

    // --- Render ---
    if ( $action === 'edit' || $action === 'add' ) {
        $link = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ) : null;
        tidy_links_render_form( $link );
    } else {
        tidy_links_render_list( $table );
    }
}

// ---------------------------------------------------------------------------
// List view
// ---------------------------------------------------------------------------

function tidy_links_render_list( $table ) {
    global $wpdb;
    $links = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name" );
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Tidy Links</h1>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tidy-links&action=add' ) ); ?>" class="page-title-action">Add New</a>

        <?php if ( ! empty( $_GET['saved'] ) )   tidy_links_notice( 'Link saved.' ); ?>
        <?php if ( ! empty( $_GET['deleted'] ) ) tidy_links_notice( 'Link deleted.' ); ?>

        <table class="wp-list-table widefat fixed striped" style="margin-top:1em;">
            <thead>
                <tr>
                    <th style="width:20%">Name</th>
                    <th style="width:28%">Short URL</th>
                    <th>Target URL</th>
                    <th style="width:6%">Type</th>
                    <th style="width:12%">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $links ) ) : ?>
                    <tr><td colspan="5">No links yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=tidy-links&action=add' ) ); ?>">Add your first link.</a></td></tr>
                <?php else : ?>
                    <?php foreach ( $links as $link ) :
                        $short = home_url( $link->slug );
                        $edit  = admin_url( 'admin.php?page=tidy-links&action=edit&id=' . $link->id );
                        $del   = wp_nonce_url( admin_url( 'admin.php?page=tidy-links&action=delete&id=' . $link->id ), 'tidy_links_delete_' . $link->id );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $link->name ); ?></td>
                        <td><a href="<?php echo esc_url( $short ); ?>" target="_blank"><?php echo esc_html( $short ); ?></a></td>
                        <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr( $link->url ); ?>">
                            <a href="<?php echo esc_url( $link->url ); ?>" target="_blank"><?php echo esc_html( $link->url ); ?></a>
                        </td>
                        <td><?php echo esc_html( $link->redirect_type ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $edit ); ?>">Edit</a>
                            &nbsp;|&nbsp;
                            <a href="<?php echo esc_url( $del ); ?>"
                               onclick="return confirm('Delete &quot;<?php echo esc_js( $link->name ); ?>&quot;?');"
                               style="color:#a00;">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// Add / Edit form
// ---------------------------------------------------------------------------

function tidy_links_render_form( $link = null ) {
    $is_edit = ! empty( $link );
    ?>
    <div class="wrap">
        <h1><?php echo $is_edit ? 'Edit Link' : 'Add New Link'; ?></h1>
        <form method="post" style="max-width:700px;">
            <?php wp_nonce_field( 'tidy_links_save' ); ?>
            <?php if ( $is_edit ) : ?>
                <input type="hidden" name="id" value="<?php echo absint( $link->id ); ?>">
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="tl-name">Name</label></th>
                    <td><input type="text" id="tl-name" name="name"
                               value="<?php echo esc_attr( $link->name ?? '' ); ?>"
                               class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="tl-slug">Slug</label></th>
                    <td>
                        <span style="color:#666;"><?php echo esc_html( trailingslashit( home_url() ) ); ?></span><input
                            type="text" id="tl-slug" name="slug"
                            value="<?php echo esc_attr( $link->slug ?? 'go/' ); ?>"
                            class="regular-text" required>
                        <p class="description">Example: <code>go/amazon-book</code></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="tl-url">Target URL</label></th>
                    <td><input type="url" id="tl-url" name="url"
                               value="<?php echo esc_attr( $link->url ?? '' ); ?>"
                               class="large-text" required></td>
                </tr>
                <tr>
                    <th><label for="tl-type">Redirect Type</label></th>
                    <td>
                        <select id="tl-type" name="redirect_type">
                            <option value="307" <?php selected( $link->redirect_type ?? '307', '307' ); ?>>307 — Temporary (recommended for affiliate links)</option>
                            <option value="301" <?php selected( $link->redirect_type ?? '307', '301' ); ?>>301 — Permanent</option>
                            <option value="302" <?php selected( $link->redirect_type ?? '307', '302' ); ?>>302 — Found</option>
                        </select>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php echo $is_edit ? 'Update Link' : 'Add Link'; ?>
                </button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tidy-links' ) ); ?>" class="button">Cancel</a>
            </p>
        </form>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function tidy_links_notice( $message, $type = 'success' ) {
    printf(
        '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
        esc_attr( $type ),
        esc_html( $message )
    );
}
