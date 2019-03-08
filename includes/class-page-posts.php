<?php
/**
 * Page posts class, the main workhorse for the ic_add_posts shortcode.
 *
 * @package     Posts_in_Page
 * @author      Eric Amundson <eric@ivycat.com>
 * @copyright   Copyright (c) 2019, IvyCat, Inc.
 * @link        https://ivycat.com
 * @since       1.0.0
 * @license     GPL-2.0+
 */

if ( ! function_exists( 'add_action' ) ) {
	wp_die( 'You are trying to access this file in a manner not allowed.', 'Direct Access Forbidden', array( 'response' => '403' ) );
}

class ICPagePosts {

	protected $args = array();

	public function __construct( $atts ) {
		$this->set_default_args(); //set default args
		$this->set_args( $atts );
	}

	protected function set_default_args() {
		$this->args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'paginate'       => false,
			'template'       => false,
			'label_next'     => __( 'Next', 'posts-in-page' ),
			'label_previous' => __( 'Previous', 'posts-in-page' ),
			'date_query'     => '',
			'none_found'     => '',
			'paged'          => false,
		);
	}

	function ovation_get_paged_query_var() {
		if ( get_query_var( 'paged' ) ) {
			$paged = get_query_var( 'paged' );
		} elseif ( get_query_var( 'page' ) ) {
			$paged = get_query_var( 'page' );
		} else {
			$paged = 1;
		}

		return $paged;
	}

	/**
	 * Spits out the posts, in a gentlemanly way
	 *
	 * @return string output of template file
	 */
	public function output_posts() {
		if ( ! $this->args ) {
			return '';
		}
		if ( $this->args['paginate'] ) {
			$this->ovation_get_paged_query_var();
		}
		// commandeering wp_query for pagination quirkiness
		global $wp_query;
		$temp     = $wp_query;
		$wp_query = null;
		$wp_query = apply_filters( 'posts_in_page_results', new WP_Query( $this->args ) ); // New WP_Query object

		$output = '';
		if ( have_posts() ) {
			while ( have_posts() ):
				$output .= self::add_template_part( $wp_query );
			endwhile;

			if ( $this->args['paginate'] ) {
				$output .= apply_filters( 'posts_in_page_paginate', $this->paginate_links() );
			}
		} else {
			$output = '<div class="post hentry ivycat-post"><span class="pip-not-found">' . esc_html( $this->args['none_found'] ) . '</span></div>';
		}

		// restore wp_query
		$wp_query = null;
		$wp_query = $temp;
		wp_reset_query();
		remove_filter( 'excerpt_more', array( &$this, 'custom_excerpt_more' ) );

		return $output;
	}


	protected function paginate_links() {

		$prev = get_previous_posts_link( $this->args['label_previous'] );
		$next = get_next_posts_link( $this->args['label_next'] );

		if ( $prev || $next ) {
			$prev_link = $prev ? "<li class='pip-nav-prev'>$prev</li>" : '';
			$next_link = $next ? "<li class='pip-nav-next'>$next</li>" : '';

			return "<ul class='pip-nav'>$prev_link $next_link</ul>";
		}

		return '';

	}


	/**
	 *    Build additional Arguments for the WP_Query object
	 *
	 * @param array $atts Attributes for building the $args array.
	 */
	protected function set_args( $atts ) {
		global $wp_query;
		$this->args['posts_per_page'] = get_option( 'posts_per_page' );
		// parse the arguments using the defaults
		$this->args = wp_parse_args( $atts, $this->args );
		// multiple post types are indicated, pass as an array
		if ( strpos( $this->args['post_type'], ',' ) ) {
			$post_types              = explode( ',', $this->args['post_type'] );
			$this->args['post_type'] = $post_types;
		}

		// Show specific posts by ID
		if ( isset( $atts['ids'] ) ) {
			$post_ids                     = explode( ',', $atts['ids'] );
			$this->args['post__in']       = $post_ids;
			$this->args['posts_per_page'] = count( $post_ids );
		}

		// Use a specified template
		if ( isset( $atts['template'] ) ) {
			$this->args['template'] = $atts['template'];
		}

		// get posts in a certain category by name (slug)
		if ( isset( $atts['category'] ) ) {
			$this->args['category_name'] = $atts['category'];
		} elseif ( isset( $atts['cats'] ) ) {
			// get posts in a certain category by id
			$this->args['cat'] = $atts['cats'];
		}

		// Do a tax query, tax and term a required.
		if ( isset( $atts['tax'] ) ) {
			if ( isset( $atts['term'] ) ) {
				$terms                   = explode( ',', $atts['term'] );
				$this->args['tax_query'] = array(
					array(
						'taxonomy' => $atts['tax'],
						'field'    => 'slug',
						'terms'    => ( count( $terms ) > 1 ) ? $terms : $atts['term'],
					)
				);
			}
		}

		// get posts with a certain tag
		if ( isset( $atts['tag'] ) ) {
			$this->args['tag'] = $atts['tag'];
		}

		// override default post_type argument ('publish')
		if ( isset( $atts['post_status'] ) ) {
			$this->args['post_status'] = $atts['post_status'];
		}

		// exclude posts with certain category by name (slug)
		if ( isset( $atts['exclude_category'] ) ) {
			$category = $atts['exclude_category'];
			if ( strpos( $category, ',' ) ) {
				// multiple
				$category = explode( ',', $category );
				foreach ( $category AS $cat ) {
					$term      = get_category_by_slug( $cat );
					$exclude[] = '-' . $term->term_id;
				}
				$category = implode( ',', $exclude );
			} else {
				// single
				$term     = get_category_by_slug( $category );
				$category = '-' . $term->term_id;
			}
			if ( isset( $this->args['cat'] ) && ! is_null( $this->args['cat'] ) ) {
				// merge lists
				$this->args['cat'] .= ',' . $category;
			}
			$this->args['cat'] = $category;
			// unset our unneeded variables
			unset( $category, $term, $exclude );
		}

		// show number of posts (default is 10, showposts or posts_per_page are both valid, only one is needed)
		if ( isset( $atts['showposts'] ) ) {
			$this->args['posts_per_page'] = $atts['showposts'];
		}

		// handle pagination (for code, template pagination is in the template)
		if ( isset( $wp_query->query_vars['page'] ) && $wp_query->query_vars['page'] > 1 ) {
			$this->args['paged'] = $wp_query->query_vars['page'];
		}

		if ( ! ( isset( $this->args['ignore_sticky_posts'] ) &&
		         ( 'no' === strtolower( $this->args['ignore_sticky_posts'] ) ||
		           'false' === strtolower( $this->args['ignore_sticky_posts'] ) ) ) ) {

			$this->args['post__not_in'] = get_option( 'sticky_posts' );
		}

		$this->args['ignore_sticky_posts'] = isset( $this->args['ignore_sticky_posts'] ) ? $this->shortcode_bool( $this->args['ignore_sticky_posts'] ) : true;

		if ( isset( $this->args['more_tag'] ) ) {
			add_filter( 'excerpt_more', array( &$this, 'custom_excerpt_more' ), 11 );
		}

		if ( isset( $atts['exclude_ids'] ) ) {
			$exclude_posts = explode( ',', $atts['exclude_ids'] );
			if ( isset( $this->args['post__not_in'] ) ) {
				$this->args['post__not_in'] = array_merge( $this->args['post__not_in'], $exclude_posts );
			} else {
				$this->args['post__not_in'] = $exclude_posts;
			}
		}

		if ( isset( $atts['from_date'] ) && isset( $atts['to_date'] ) ) {
			$r_from                   = explode( '-', $atts['from_date'] );
			$r_to                     = explode( '-', $atts['to_date'] );
			$this->args['date_query'] = array(
				array(
					'after'     => array(
						'year'  => $r_from[2],
						'month' => $r_from[1],
						'day'   => $r_from[0],
					),
					'before'    => array(
						'year'  => $r_to[2],
						'month' => $r_to[1],
						'day'   => $r_to[0],
					),
					'inclusive' => true,
				),
			);
		} else if ( isset( $atts['from_date'] ) ) {
			$r_from                   = explode( '-', $atts['from_date'] );
			$r_to                     = explode( '-', $atts['to_date'] );
			$this->args['date_query'] = array(
				array(
					'after'     => array(
						'year'  => $r_from[2],
						'month' => $r_from[1],
						'day'   => $r_from[0],
					),
					'inclusive' => true,
				),
			);
		}

		$current_time_value = current_time( 'timestamp' );
		if ( isset( $atts['date'] ) ) {
			$date_data = explode( '-', $atts['date'] );
			if ( ! isset( $date_data[1] ) ) {
				$date_data[1] = 0;
			}
			switch ( $date_data[0] ) {
				case 'today':
					$today                    = getdate( $current_time_value - ( $date_data[1] * DAY_IN_SECONDS ) );
					$this->args['date_query'] = array(
						'year'  => $today['year'],
						'month' => $today['mon'],
						'day'   => $today['mday'],
					);
					break;
				case 'week':
					$week                     = date( 'W', $current_time_value - $date_data[1] * WEEK_IN_SECONDS );
					$year                     = date( 'Y', $current_time_value - $date_data[1] * WEEK_IN_SECONDS );
					$this->args['date_query'] = array(
						'year' => $year,
						'week' => $week,
					);
					break;
				case 'month':
					$month                    = date( 'm', strtotime( ( strval( - $date_data[1] ) . ' Months' ), $current_time_value ) );
					$year                     = date( 'Y', strtotime( ( strval( - $date_data[1] ) . ' Months' ), $current_time_value ) );
					$this->args['date_query'] = array(
						'monthnum' => $month,
						'year'     => $year,
					);
					break;
				case 'year':
					$year                     = date( 'Y', strtotime( ( strval( - $date_data[1] ) . ' Years' ), $current_time_value ) );
					$this->args['date_query'] = array(
						'year' => $year,
					);
					break;
			}
		}
		$this->args = apply_filters( 'posts_in_page_args', $this->args );

	}

	/**
	 * Sets a shortcode boolean value to a real boolean
	 *
	 * @return bool
	 */
	public function shortcode_bool( $var ) {

		$falsey = array( 'false', '0', 'no', 'n' );

		return ( ! $var || in_array( strtolower( $var ), $falsey ) ) ? false : true;

	}

	/**
	 *    Tests if a theme has a template file that exists in one of two locations
	 *    1- posts-in-page directory or 2- theme directory
	 *
	 * @return true if template exists, false otherwise.
	 */
	protected function has_theme_template() {

		// try default template filename if empty
		$filename = empty( $this->args['template'] ) ? 'posts_loop_template.php' : $this->args['template'];

		// Checking first of two locations - theme root
		$template_file = get_stylesheet_directory() . '/' . $filename;

		// check for traversal attack
		$path_parts = pathinfo( $template_file );
		if ( $template_file != get_stylesheet_directory() . '/' .
		                       $path_parts['filename'] . '.' . $path_parts['extension']
		) {
			// something fishy
			return false;
		}

		return ( file_exists( $template_file ) ) ? $template_file : false;

	}

	/**
	 *    Retrieves the post loop template and returns the output
	 *
	 * @return string results of the output
	 */
	protected function add_template_part( $ic_posts, $singles = false ) {
		if ( $singles ) {
			setup_postdata( $ic_posts );
		} else {
			$ic_posts->the_post();
		}
		/**
		 * Because legacy versions of pip forced users to echo content in the filter callback
		 * we are using both the filters and the output buffer to cover all bases of usage.
		 */
		ob_start();
		$output_start = apply_filters( 'posts_in_page_pre_loop', '' );
		require ( $file_path = self::has_theme_template() )
			? $file_path // use template file in theme
			: POSTSPAGE_DIR . '/templates/posts_loop_template.php'; // use default plugin template file
		$output_start .= ob_get_clean();
		/*
		 * Output buffering to handle legacy versions which forced filter callbacks to echo content rather than return it.
		 */
		ob_start();
		/**
		 * Standard use of filter
		 */
		$output = apply_filters( 'posts_in_page_post_loop', $output_start );
		/**
		 * Just in case someone has a legacy callback that doesn't return anything...
		 */
		if ( empty( $output ) ) {
			$output = $output_start;
		}
		/**
		 * Allow for legacy use of filter which forced echoing content
		 */
		$output .= ob_get_clean();

		return $output;
	}

	public function custom_excerpt_more( $more ) {
		$more_tag = $this->args['more_tag'];

		return ' <a class="read-more" href="' . get_permalink( get_the_ID() ) . '">' . $more_tag . '</a>';
	}


}
