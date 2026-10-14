<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed config -> WP_Query args. Kept apart from the renderer so the admin
 * ("Подходит записей сейчас"), the background tick and the CLI all count and
 * walk exactly the same set of posts the feed would serve.
 */
class WPPF_Query {

	/** Per-post opt-out, set in the post metabox (WPPF_Post_Meta). */
	const EXCLUDE_META = '_wppf_exclude';

	public static function args( array $config, $limit = null ) {
		$args = array(
			'post_type'              => $config['post_types'],
			'post_status'            => 'publish',
			'posts_per_page'         => null === $limit ? (int) $config['limit'] : (int) $limit,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'has_password'           => false,
			'suppress_filters'       => false,
			'update_post_term_cache' => true,
			'update_post_meta_cache' => true,
			'meta_query'             => array(
				'relation' => 'OR',
				array(
					'key'     => self::EXCLUDE_META,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::EXCLUDE_META,
					'value'   => '1',
					'compare' => '!=',
				),
			),
		);

		$tax_query = self::tax_query( $config );
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		switch ( $config['orderby'] ) {
			case 'modified':
				$args['orderby'] = 'modified';
				$args['order']   = 'DESC';
				break;
			case 'rand':
				$args['orderby'] = 'rand';
				break;
			case 'comment_count':
				$args['orderby'] = array(
					'comment_count' => 'DESC',
					'date'          => 'DESC',
				);
				break;
			case 'meta_num':
				if ( '' !== $config['order_meta_key'] ) {
					// A named meta clause instead of meta_key, so posts lacking
					// the field are not silently dropped from the feed.
					$args['meta_query'] = array(
						'relation' => 'AND',
						$args['meta_query'],
						array(
							'relation'  => 'OR',
							'wppf_sort' => array(
								'key'  => $config['order_meta_key'],
								'type' => 'NUMERIC',
							),
							array(
								'key'     => $config['order_meta_key'],
								'compare' => 'NOT EXISTS',
							),
						),
					);
					$args['orderby'] = array(
						'wppf_sort' => 'DESC',
						'date'      => 'DESC',
					);
					break;
				}
				// No key given — fall through to date.
			default:
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
		}

		$date_query = array();
		if ( (int) $config['max_age_days'] > 0 ) {
			$date_query[] = array(
				'after'     => gmdate( 'Y-m-d H:i:s', time() - (int) $config['max_age_days'] * DAY_IN_SECONDS ),
				'column'    => 'post_date_gmt',
				'inclusive' => true,
			);
		}
		if ( (int) $config['min_age_minutes'] > 0 ) {
			$date_query[] = array(
				'before'    => gmdate( 'Y-m-d H:i:s', time() - (int) $config['min_age_minutes'] * MINUTE_IN_SECONDS ),
				'column'    => 'post_date_gmt',
				'inclusive' => true,
			);
		}
		if ( $date_query ) {
			$args['date_query'] = $date_query;
		}

		$exclude = array_filter( array_map( 'intval', explode( ',', (string) $config['exclude_ids'] ) ) );
		if ( $exclude ) {
			$args['post__not_in'] = $exclude; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams
		}

		/**
		 * Last word on the query of one feed.
		 *
		 * @param array $args   WP_Query args.
		 * @param array $config Normalised feed config.
		 */
		return (array) apply_filters( 'wppf_query_args', $args, $config );
	}

	public static function tax_query( array $config ) {
		$clauses = array();

		foreach ( $config['tax_rules'] as $rule ) {
			if ( ! taxonomy_exists( $rule['taxonomy'] ) ) {
				continue;
			}

			$clause = array(
				'taxonomy'         => $rule['taxonomy'],
				'operator'         => $rule['operator'],
				'include_children' => (bool) $rule['children'],
			);

			if ( ! in_array( $rule['operator'], array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
				$terms = array_values( array_filter( array_map( 'trim', explode( ',', $rule['terms'] ) ), 'strlen' ) );
				if ( ! $terms ) {
					continue;
				}
				// Slugs by default (portable between sites); numeric entries are IDs.
				$all_numeric       = count( array_filter( $terms, 'ctype_digit' ) ) === count( $terms );
				$clause['field']   = $all_numeric ? 'term_id' : 'slug';
				$clause['terms']   = $all_numeric ? array_map( 'intval', $terms ) : array_map( 'sanitize_title', $terms );
			}

			$clauses[] = $clause;
		}

		if ( ! $clauses ) {
			return array();
		}

		$clauses['relation'] = $config['tax_relation'];
		return $clauses;
	}

	/** @return WP_Post[] */
	public static function posts( array $config, $limit = null ) {
		$query = new WP_Query( self::args( $config, $limit ) );
		return $query->posts;
	}

	public static function count( array $config ) {
		$args                   = self::args( $config, 1 );
		$args['no_found_rows']  = false;
		$args['fields']         = 'ids';
		$query                  = new WP_Query( $args );
		return (int) $query->found_posts;
	}
}
