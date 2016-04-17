<?php

$show_thumbs = FLTheme::get_setting('fl-archive-show-thumbs');
$show_full   = FLTheme::get_setting('fl-archive-show-full');
$more_text   = FLTheme::get_setting('fl-archive-readmore-text');

if (has_post_thumbnail( $post->ID ) ) {
	$featured_image = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'large' );
}

?>
<article <?php post_class( 'fl-post' ); ?> id="fl-post-<?php the_ID(); ?>" itemscope="itemscope" itemtype="http://schema.org/BlogPosting">
	
	<a href="<?php the_permalink(); ?>">
		<header class="fl-post-header" style="background-image: url(<?php echo $featured_image[0]; ?>);">
			<h1 class="fl-post-title" itemprop="headline">
				<?php the_title(); ?>
			</h1>

			<?php FLTheme::post_top_meta(); ?>
		</header><!-- .fl-post-header -->
	</a>

	<div class="fl-post-content clearfix" itemprop="text">
		<?php

		if(is_search() || !$show_full) {
			the_excerpt();
			echo '<a class="fl-post-more-link" href="'. get_permalink() .'">'. $more_text .'</a>';
		}
		else {
			the_content('<span class="fl-post-more-link">'. $more_text .'</span>');
		}

		?>
	</div><!-- .fl-post-content -->

	<?php FLTheme::post_bottom_meta(); ?>

	<?php if(has_post_thumbnail() && $show_thumbs == 'beside') : ?>
		</div>
	</div>
	<?php endif; ?>

</article>
<!-- .fl-post -->
