<?php

$show_thumbs = FLTheme::get_setting('fl-posts-show-thumbs');

if (has_post_thumbnail( $post->ID ) ) {
	$featured_image = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'large' );
}

?>
<article <?php post_class( 'fl-post' ); ?> id="fl-post-<?php the_ID(); ?>" itemscope itemtype="http://schema.org/BlogPosting">

	<header class="fl-post-header" style="background-image: url(<?php echo $featured_image[0]; ?>);">
		<h1 class="fl-post-title" itemprop="headline">
			<?php the_title(); ?>
		</h1>

		<?php FLTheme::post_top_meta(); ?>
		
	</header><!-- .fl-post-header -->

	<div class="fl-post-content clearfix" itemprop="text">
		<?php

		the_content();

		wp_link_pages( array(
			'before'         => '<div class="fl-post-page-nav">' . _x( 'Pages:', 'Text before page links on paginated post.', 'fl-automator' ),
			'after'          => '</div>',
			'next_or_number' => 'number'
		) );

		?>
	</div><!-- .fl-post-content -->

	<?php if(has_post_thumbnail() && $show_thumbs == 'beside') : ?>
		</div>
	</div>
	<?php endif; ?>

	<?php FLTheme::post_bottom_meta(); ?>
	<?php FLTheme::post_navigation(); ?>
	<?php comments_template(); ?>

</article>
<!-- .fl-post -->
