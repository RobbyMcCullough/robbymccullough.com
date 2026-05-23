<?php get_header(); ?>

<main id="main" class="site-main" role="main">
<?php while ( have_posts() ) : the_post();
    $hide_title = get_post_meta( get_the_ID(), '_hide_title', true );
?>

    <div class="content-column page-content">

        <?php if ( ! $hide_title ) : ?>
            <h1 class="entry-title"><?php the_title(); ?></h1>
        <?php endif; ?>

        <div class="entry-content">
            <?php the_content(); ?>
        </div>

    </div>

<?php endwhile; ?>
</main>

<?php get_footer(); ?>
