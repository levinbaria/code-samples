<?php
/**
 * Registers the code-sample/single-page-recent-posts block.
 *
 * @global array    $attrs   Block attributes passed to the render callback.
 * @global string   $content Block content from InnerBlocks passed to the render callback.
 * @global WP_Block $block   Block registration object.
 *
 * @package code-sample
 */

namespace Code_Sample\Blocks;

use Code_Sample\Includes\Block_Base;
use WP_Block;
use WP_Query;

/**
 *  Class for the code-sample/single-page-recent-posts block.
 */
class Single_Page_Recent_Posts extends Block_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->_block = 'single-page-recent-posts';
		$this->setup_hooks();
	}

	/**
	 * To register action/filter.
	 *
	 * @return void
	 */
	protected function setup_hooks() {
	}

	/**
	 * Render block.
	 *
	 * @param array    $attributes   Block attributes.
	 * @param string   $content      Block content.
	 * @param WP_Block $block        Block object.
	 * @return string
	 */
	public function render_callback(
		// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		array $attributes,
		string $content,
		WP_Block $block
		// phpcs:enable
	): string {

		$block_id         = isset( $attributes['blockID'] ) ? $attributes['blockID'] : '';
		$block_bg_color   = isset( $attributes['blockBgColor'] ) ? $attributes['blockBgColor'] : '';
		$section_padding  = isset( $attributes['sectionPadding'] ) ? $attributes['sectionPadding'] : array(
			'top'    => '20px',
			'right'  => '0px',
			'bottom' => '20px',
			'left'   => '0px',
		);
		$heading_tag      = isset( $attributes['headingTag'] ) ? $attributes['headingTag'] : 'h2';
		$heading          = isset( $attributes['heading'] ) ? $attributes['heading'] : '';
		$heading_color    = isset( $attributes['headingColor'] ) ? sanitize_hex_color( $attributes['headingColor'] ) : '';
		$heading_bg_color = isset( $attributes['headingBgColor'] ) ? sanitize_hex_color( $attributes['headingBgColor'] ) : '';
		$raw_post_type    = isset( $attributes['selectedPostType'] ) ? $attributes['selectedPostType'] : 'post';
		$type             = isset( $attributes['type'] ) ? $attributes['type'] : 'recent';
		$no_of_post       = isset( $attributes['noOfPost'] ) ? intval( $attributes['noOfPost'] ) : 5;
		$post_title_color = isset( $attributes['postTitleColor'] )
									? sanitize_hex_color( $attributes['postTitleColor'] )
									: '';
		$post_bg_color    = isset( $attributes['postBgColor'] ) ? sanitize_hex_color( $attributes['postBgColor'] ) : '';
		$remove_container = isset( $attributes['removeContainer'] ) ? $attributes['removeContainer'] : false;

		$selected_post_type = ( 'posts' === $raw_post_type ) ? 'post' : $raw_post_type;

		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'id'    => $block_id,
				'class' => 'post-lists ' . $block_id . '',
			)
		);

		// Build style declarations.
		$style_declarations = array();
		if ( $block_bg_color ) {
			$style_declarations[] = "background-color:{$block_bg_color}";
		}
		if ( ! empty( $section_padding ) ) {
			$style_declarations[] = sprintf(
				'padding: %s %s %s %s',
				esc_attr( $section_padding['top'] ),
				esc_attr( $section_padding['right'] ),
				esc_attr( $section_padding['bottom'] ),
				esc_attr( $section_padding['left'] )
			);
		}
		$block_style = implode( ';', $style_declarations );

		$current_id = is_singular( $selected_post_type ) ? get_the_ID() : 0;

		// Query posts.
		$args = array(
			'post_type'           => $selected_post_type,
			'posts_per_page'      => $no_of_post + 1,
			'orderby'             => 'date',
			'order'               => strtoupper( $type ),
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);

		$query = new WP_Query( $args );

		ob_start();
		?>
		<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
			<div 
				class="sp-recent-posts__wrapper"
			<?php if ( $block_style ) : ?>
					style="<?php echo esc_attr( $block_style ); ?>"
				<?php endif; ?>
			>
				<div class=<?php echo $remove_container ? 'sp-recent-posts-container' : 'container'; ?>>
					<div class="sp-recent-posts__main">
						<?php if ( $heading ) : ?>
							<div class="sp-recent-posts__header">
								<?php if ( $heading ) : ?>
									<<?php echo esc_html( $heading_tag ); ?> 
										class="sp-recent-posts__heading" 
										<?php if ( $heading_color || $heading_bg_color ) : ?>
											style="<?php echo esc_attr( ( $heading_color ? "color:{$heading_color};" : '' ) . ( $heading_bg_color ? "background-color:{$heading_bg_color};" : '' ) ); ?>"
										<?php endif; ?>
									>
										<?php echo wp_kses_post( $heading ); ?>
									</<?php echo esc_html( $heading_tag ); ?>>
								<?php endif; ?>
							</div><!-- .sp-recent-posts__header -->
						<?php endif; ?>
						<?php if ( $query->have_posts() ) : ?>
							<ul class="sp-recent-posts__posts-wrapper">
								<?php
								$shown = 0;
								while ( $query->have_posts() && $shown < $no_of_post ) :
									$query->the_post();

									if ( get_the_ID() === $current_id ) {
										continue;
									}
									++$shown;

									$post_id        = get_the_ID();
									$post_title     = get_the_title();
									$post_permalink = get_permalink( $post_id );
									?>
									<li 
										class="sp-recent-posts__individual-post"
										<?php if ( $post_bg_color ) : ?>
											style="<?php echo esc_attr( ( $post_bg_color ? "background-color:{$post_bg_color};" : '' ) ); ?>"
										<?php endif; ?>
									>
										<a
											class="sp-recent-posts__post-title"
											<?php if ( $post_title_color ) : ?>
												style="<?php echo esc_attr( "color:{$post_title_color}" ); ?>"
											<?php endif; ?>
											href="<?php echo esc_url( $post_permalink ); ?>"
										>
											<?php echo wp_kses_post( $post_title ) . '...'; ?>
										</a>
									</li>
								<?php endwhile; ?>
							</ul><!-- .sp-recent-posts__posts-wrapper -->
						<?php else : ?>
							<p class="sp-recent-posts__no-posts"><?php esc_html_e( 'No posts found.', 'code-sample' ); ?></p>
						<?php endif; ?>
						<?php wp_reset_postdata(); ?>
					</div><!-- .sp-recent-posts__main -->
				</div><!-- .container -->
			</div><!-- .sp-recent-posts__wrapper -->
		</div>
								<?php
								return ob_get_clean();
	}
}
