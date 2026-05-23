<?php get_header(); ?>

<main id="main" class="site-main" role="main">
<?php while ( have_posts() ) : the_post(); ?>

    <?php if ( has_post_thumbnail() ) : ?>

        <div class="post-hero">
            <?php the_post_thumbnail( 'full', [ 'class' => 'post-hero-img', 'alt' => get_the_title() ] ); ?>
            <div class="post-hero-header">
                <h1 class="entry-title"><?php the_title(); ?></h1>
                <p class="entry-meta">
                    <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
                        <?php echo esc_html( get_the_date( 'F j, Y' ) ); ?>
                    </time>
                </p>
            </div>
        </div>

    <?php else : ?>

        <div class="entry-header-no-image">
            <div class="content-column">
                <h1 class="entry-title"><?php the_title(); ?></h1>
                <p class="entry-meta">
                    <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
                        <?php echo esc_html( get_the_date( 'F j, Y' ) ); ?>
                    </time>
                </p>
            </div>
        </div>

    <?php endif; ?>

    <div class="content-column">
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <div class="entry-content">
                <?php the_content(); ?>
                <?php
                wp_link_pages( [
                    'before' => '<nav class="page-links"><span>' . __( 'Pages:', 'robbymccullough' ) . '</span>',
                    'after'  => '</nav>',
                ] );
                ?>
            </div>
        </article>

        <?php
        the_post_navigation( [
            'prev_text' => '<span class="nav-subtitle">' . __( 'Previous', 'robbymccullough' ) . '</span>%title',
            'next_text' => '<span class="nav-subtitle">' . __( 'Next', 'robbymccullough' ) . '</span>%title',
        ] );
        ?>

        <?php if ( comments_open() || get_comments_number() ) : ?>
            <div class="comments-area">
                <?php comments_template(); ?>
            </div>
        <?php endif; ?>

    </div>

<?php endwhile; ?>
</main>

<?php get_footer(); ?>
