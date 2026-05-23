<?php
/**
 * Template Name: Full Width
 * Template Post Type: page, post
 *
 * No content-width constraint. Use this with Beaver Builder.
 */

get_header();
?>

<main id="main" class="site-main full-width-main" role="main">
<?php while ( have_posts() ) : the_post();
    $hide_title = get_post_meta( get_the_ID(), '_hide_title', true );
?>

    <?php if ( ! $hide_title ) : ?>
        <div class="full-width-title">
            <h1 class="entry-title"><?php the_title(); ?></h1>
        </div>
    <?php endif; ?>

    <div class="entry-content">
        <?php the_content(); ?>
    </div>

<?php endwhile; ?>
</main>

<?php get_footer(); ?>
