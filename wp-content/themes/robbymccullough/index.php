<?php get_header(); ?>

<main id="main" class="site-main" role="main">
    <div class="content-column archive-list-wrap">

        <?php if ( is_home() && ! is_front_page() ) : ?>
            <div class="archive-header">
                <h1 class="archive-title"><?php single_post_title(); ?></h1>
            </div>
        <?php elseif ( is_archive() ) : ?>
            <div class="archive-header">
                <?php the_archive_title( '<h1 class="archive-title">', '</h1>' ); ?>
            </div>
        <?php endif; ?>

        <?php if ( have_posts() ) : ?>

            <div class="post-card-list">
                <?php while ( have_posts() ) : the_post(); ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class( 'post-card' ); ?>>

                    <?php if ( has_post_thumbnail() ) : ?>
                        <figure class="post-thumbnail">
                            <a class="post-thumbnail-inner" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
                                <?php the_post_thumbnail( 'large', [ 'alt' => '' ] ); ?>
                            </a>
                        </figure>
                    <?php endif; ?>

                    <header class="entry-header">
                        <h2 class="entry-title">
                            <a href="<?php the_permalink(); ?>" rel="bookmark"><?php the_title(); ?></a>
                        </h2>
                        <div class="entry-meta">
                            <time class="entry-date" datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
                                <?php echo esc_html( get_the_date( 'F j, Y' ) ); ?>
                            </time>
                            <?php $cats = get_the_category(); if ( $cats ) : ?>
                                <span class="meta-sep">&middot;</span>
                                <span class="cat-links">
                                    <?php echo esc_html( $cats[0]->name ); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </header>

                    <?php $excerpt = get_the_excerpt(); if ( $excerpt ) : ?>
                        <div class="entry-summary">
                            <p><?php echo esc_html( wp_trim_words( $excerpt, 30 ) ); ?></p>
                        </div>
                    <?php endif; ?>

                </article>
                <?php endwhile; ?>
            </div>

            <?php the_posts_pagination( [
                'prev_text' => '&larr;',
                'next_text' => '&rarr;',
            ] ); ?>

        <?php else : ?>
            <p><?php esc_html_e( 'No posts found.', 'robbymccullough' ); ?></p>
        <?php endif; ?>

    </div>
</main>

<?php get_footer(); ?>
