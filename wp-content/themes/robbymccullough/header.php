<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div class="site-wrap">

    <header class="site-header" role="banner">
        <div class="site-header-inner">

            <div class="site-logo">
                <?php if ( has_custom_logo() ) :
                    the_custom_logo();
                else :
                    $admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
                    if ( ! empty( $admins ) ) : ?>
                        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="site-avatar-link" aria-label="<?php bloginfo( 'name' ); ?>">
                            <?php echo get_avatar( $admins[0]->ID, 52, '', get_bloginfo( 'name' ), [ 'class' => 'site-avatar' ] ); ?>
                        </a>
                    <?php endif;
                endif; ?>
            </div>

            <div class="site-brand">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="site-brand-link" rel="home">
                    <strong class="site-name"><?php bloginfo( 'name' ); ?></strong>
                    <?php $desc = get_bloginfo( 'description' ); if ( $desc ) : ?>
                        <span class="site-tagline"><?php echo esc_html( $desc ); ?></span>
                    <?php endif; ?>
                </a>
                <?php wp_nav_menu( [
                    'theme_location'       => 'social',
                    'menu_class'           => 'social-nav',
                    'container'            => 'nav',
                    'container_class'      => 'site-social',
                    'container_aria_label' => 'Social links',
                    'walker'               => new RM_Social_Walker(),
                    'depth'                => 1,
                    'fallback_cb'          => false,
                ] ); ?>
            </div>

            <?php wp_nav_menu( [
                'theme_location'       => 'primary',
                'menu_class'           => 'primary-nav',
                'container'            => 'nav',
                'container_class'      => 'site-nav',
                'container_aria_label' => 'Primary navigation',
                'depth'                => 1,
                'fallback_cb'          => false,
            ] ); ?>

        </div>
    </header>
