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
		<?php the_content(); ?>
	</div><!-- .fl-post-content -->

	<?php if(has_post_thumbnail() && $show_thumbs == 'beside') : ?>
		</div>
	</div>
	<?php endif; ?>

	<?php FLTheme::post_bottom_meta(); ?>
	
	<?php echo do_shortcode( '[jetpack_subscription_form title="Never Miss a Post" subscribe_text="Get all of my new blog posts delivered straight to your inbox." subscribe_button="Sign Me Up"]' ); ?>

	<?php comments_template(); ?>

</article>
<!-- .fl-post -->
