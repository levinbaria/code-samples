<?php
/**
 * Class for the core function for the Content Import.
 *
 * @package migrate-ct
 */

/**
 * Core Migrate class having shared function used in the Admin and CLI.
 */
class Ct_Migrate {
	/**
	* Number of items to process per batch.
	*
	* @var int
	*/
	const BATCH_SIZE = 10;
	/**
	* Prefix for transients and options used by this importer.
	*
	* @var string
	*/
	const TRANSIENT_PREFIX = 'ct_importer_';

	/**
	 * OAuth client ID for DTN API.
	 *
	 * @var string
	 */
	protected $client_id = 'xxxxxxxxxxxxxxxxxxxxxxxxxxx';
	/**
	 * OAuth client secret for DTN API.
	 *
	 * @var string
	 */
	protected $client_secret = 'xxxxxxx';
	/**
	 * Maximum number of retry attempts for API calls.
	 *
	 * @var int
	 */
	protected $max_retries = 3;

	/**
	 * Mapping lookup service.
	 *
	 * @var Content_Mappings
	 */
	private $mappings;

	/**
	 * Constructor method.
	 */
	public function __construct() {
		$this->mappings = new Content_Mappings();
	}

	/**
	 * Retrieve an OAuth access token from the DTN API.
	 *
	 * @return string Access token.
	 *
	 * @throws \Exception If authentication fails after retries.
	 */
	public function get_api_token() {
		$attempt = 0;
		do {
			$response = wp_remote_post(
				'https://example.com/auth/token',
				array(
					'body'    => array(
						'client_id'     => $this->client_id,
						'client_secret' => $this->client_secret,
						'grant_type'    => 'client_credentials',
					),
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'timeout' => 60,
				)
			);

			if ( is_wp_error( $response ) ) {
				if ( ++$attempt < $this->max_retries ) {
					sleep( 2 );
					continue;
				}
				throw new \Exception( 'Authentication failed: ' . esc_html( $response->get_error_message() ) );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! isset( $body['access_token'] ) ) {
				throw new \Exception( 'Invalid authentication response' );
			}
			return $body['access_token'];

		} while ( $attempt < $this->max_retries );

		throw new \Exception( 'Authentication failed after multiple attempts' );
	}

	/**
	 * Fetch news item JSON data from the DTN API by UUID.
	 *
	 * @param string $uuid  UUID of the news item.
	 * @param string $token OAuth access token.
	 *
	 * @return array Parsed JSON response.
	 *
	 * @throws \Exception If the request fails or returns a non-200 status.
	 */
	public function fetch_news_data( $uuid, $token ) {
		$attempt = 0;
		do {
			$response = wp_remote_get(
				"https://example.com/api/api-content/$uuid",
				array(
					'headers' => array(
						'Accept'        => 'application/vnd.ct.news.v1+json',
						'Authorization' => 'Bearer ' . $token,
					),
					'timeout' => 60,
				)
			);

			if ( is_wp_error( $response ) ) {
				if ( ++$attempt < $this->max_retries ) {
					sleep( 2 );
					continue;
				}
				throw new \Exception( esc_html( $response->get_error_message() ) );
			}

			if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
				throw new \Exception( 'API Error: ' . esc_html( wp_remote_retrieve_response_code( $response ) ) );
			}

			return json_decode( wp_remote_retrieve_body( $response ), true );

		} while ( $attempt < $this->max_retries );

		throw new \Exception(
			sprintf(
				'Failed to fetch data for %s after multiple attempts',
				esc_html( sanitize_text_field( $uuid ) )
			)
		);
	}

	/**
	 * Create or update a news post from API data.
	 *
	 * @param array $data API response array.
	 * @return int|WP_Error The post ID on success, or WP_Error on failure.
	 */
	public function create_post( $data ) {

		$uuid          = sanitize_text_field( $data['head']['newsIdentifier']['uuid'] );
		$raw_content   = $this->generate_post_content( $data );
		$converter     = new Convert_HTML_To_GB_Blocks();
		$block_content = $converter->convert_html_to_gb_blocks( $raw_content );

		// Case for some uuids--special-case-starts.
		$special = array(
			'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx4',
			'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
		);

		// Special branch:  One of the UUIDs case handling.
		if ( in_array( $uuid, $special, true ) ) {
			$post_args = array(
				'post_title'   => wp_strip_all_tags( $data['bodyHead']['subHead'] ?? '' ),
				'post_content' => $block_content,
				'post_excerpt' => wp_kses_post( $data['bodyHead']['summary'] ?? '' ),
				'post_date'    => $this->parse_date( $data['head']['newsIdentifier']['created'] ?? '' ),
				'post_status'  => 'publish',
				'post_type'    => 'news',
				'meta_input'   => array( '_ct_uuid' => $uuid ),
			);

			// Insert or update.
			$existing = get_posts(
				array(
					'post_type'      => 'news',
					'meta_key'       => '_ct_uuid', // phpcs:ignore
					'meta_value'     => $uuid, // phpcs:ignore
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);

			if ( $existing ) {
				$post_args['ID'] = $existing[0];
				$post_id         = wp_update_post( $post_args );
			} else {
				$post_id = wp_insert_post( $post_args );
			}

			// Bail if there was an error.
			if ( is_wp_error( $post_id ) || 0 === $post_id ) {
				error_log( "Error processing special-UUID $uuid: " . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : 'Invalid post ID' ) );
				return $post_id;
			}

			$term_id = $this->ensure_term_exists( 'ag-news', 'news-category' );
			wp_set_post_terms( $post_id, array( $term_id ), 'news-category', true );
			$this->handle_acf_fields( $post_id, $data );
			$this->handle_featured_image( $post_id, $data );
			$this->handle_author( $post_id, $data );

			return $post_id;
		}
		// special-case ends.
		// Determine all applicable post types.
		$post_types = $this->get_post_types_for_data( $data );

		foreach ( $post_types as $post_type ) {
			// Check for existing post in this specific CPT.
			$existing = get_posts(
				array(
					'post_type'      => $post_type,
					'meta_key'       => '_ct_uuid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);

			$post_title = '';
			if ( ! empty( $data['bodyHead']['subHead'] ) ) {
				$post_title = wp_strip_all_tags( (string) $data['bodyHead']['subHead'] );
			} elseif ( ! empty( $data['head']['title'] ) ) {
				$post_title = wp_strip_all_tags( (string) $data['head']['title'] );
			}

			$post_args = array(
				'post_title'   => $post_title,
				'post_content' => $block_content,
				'post_excerpt' => wp_kses_post( $data['bodyHead']['summary'] ?? '' ),
				'post_date'    => $this->parse_date( $data['head']['newsIdentifier']['created'] ),
				'post_status'  => 'publish',
				'post_type'    => $post_type,
				'meta_input'   => array( '_ct_uuid' => $uuid ),
			);

			if ( ! empty( $existing ) ) {
				$post_args['ID'] = $existing[0];
				$post_id         = wp_update_post( $post_args );
			} else {
				$post_id = wp_insert_post( $post_args );
			}

			// Check for valid post ID or WP_Error.
			if ( is_wp_error( $post_id ) || 0 === $post_id ) {
				error_log( "Error processing $uuid: " . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : 'Invalid post ID' ) );
				continue;
			}

			$this->handle_acf_fields( $post_id, $data );
			$this->handle_author( $post_id, $data );
			$this->handle_taxonomy( $post_id, $data['routing'], $post_type, $data );
			$this->handle_featured_image( $post_id, $data );
		}

		return $post_id;
	}

	/**
	 * Determine applicable post types based on routing data.
	 *
	 * @param array $data API response array.
	 * @return string[] List of post type slugs.
	 */
	private function get_post_types_for_data( $data ) {
		$post_types = array();

		foreach ( $data['routing'] as $route ) {
			$handle = isset( $route['handle'] ) ? $route['handle'] : '';

			// magazine pages: PFMag/<section>/section_page_## → CPT 'magazine'.
			if ( 1 === preg_match(
				'#^/api-content/PFMag/(' . implode( '|', array_map( 'preg_quote', $this->mappings->magazine_sections, array( '#' ) ) ) . ')/[^/]+_page_\d{2}$#',
				$handle
			) ) {
				$post_types[] = 'magazine';
			}

			// Check for news post types.
			if ( in_array(
				$handle,
				array(
					'/api-content/ct/en/ct_news/washington_insider',
					'/api-content/ct/en/ct_news/market_impact_weather',
					'/api-content/ct/en/ct_news/breaking_news',
				),
				true
			) || ( is_string( $handle ) && preg_match( '#/ct_news/ct_news_page_#', $handle ) )
			|| ( is_string( $handle ) && preg_match( '#^/api-content/ct/en/blog_folder/ct_ag_policy_blog_composite/agpolicy_blog_\d{2}$#', $handle ) )
			|| ( is_string( $handle ) && preg_match( '#^/api-content/ct/en/columns/(vintage_iron|cash_market)$#', $handle ) )
			|| ( is_string( $handle ) && preg_match( '#^/api-content/ct/en/blog_folder/ct_an_urbans_rural/an_urbans_rural_view(?:\d{1,2})?$#', $handle ) )
			) {
				$post_types[] = 'news';
			}

			// Check for market post types.
			if ( isset( $this->mappings->market_mappings[ $handle ] ) || isset( $this->mappings->strategy_handles[ $handle ] ) ||
			'/api-content/ct/en/special/ct_fertilizer_index' === $handle ||
			'/api-content/ct/en/columns/fertilizer_weekly' === $handle ) {
				$post_types[] = 'markets';
			}

			// Check pageMembers for fuel/fertilize.
			if ( ! empty( $route['pageMembers'] ) ) {
				foreach ( $route['pageMembers'] as $member ) {
					if ( isset( $this->mappings->fuel_fertilizer_mappings[ $member ] ) || isset( $this->mappings->strategy_page_members[ $member ] ) ) {
						$post_types[] = 'markets';
					}
				}
			}
		}

		return array_unique( $post_types );
	}

	/**
	 * Generate post content by concatenating paragraphs.
	 *
	 * @param array $data API response array.
	 * @return string Sanitized post content.
	 */
	public function generate_post_content( $data ) {
		$post_content = '';
		if ( ! empty( $data['body']['contentParagraphs'] ) && is_array( $data['body']['contentParagraphs'] ) ) {
			foreach ( $data['body']['contentParagraphs'] as $paragraph ) {
				if ( ! empty( $paragraph['content'] ) ) {
					$content       = isset( $paragraph['content'] ) ? (string) $paragraph['content'] : '';
					$post_content .= wp_kses_post( $content ) . "\n\n";
				}
			}
		}
		return trim( $post_content );
	}

	/**
	 * Determine if a post should be imported based on routing.
	 *
	 * @param array $data API response array.
	 * @return bool True if importable, false otherwise.
	 */
	public function should_import_post( $data ) {
		// special-case-start.
		$uuid = sanitize_text_field( $data['head']['newsIdentifier']['uuid'] ?? '' );

		$special = array(
			'axxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx1',
			'91xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'73xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'98xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'8axxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'78xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'92xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'27xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'53xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
		);

		if ( in_array( $uuid, $special, true ) ) {
			return true;
		} //special-case-ends.

		foreach ( $data['routing'] as $route ) {
			$handle = $route['handle'];

			// Check exact matches.
			if ( in_array(
				$handle,
				array(
					'/api-content/ct/en/ct_news/washington_insider',
					'/api-content/ct/en/ct_news/market_impact_weather',
					'/api-content/ct/en/ct_news/breaking_news',
				),
				true
			) ) {
				return true;
			}

			// Check page pattern.
			if ( ! empty( $handle ) && is_string( $handle )
				&& preg_match( '#/ct_news/ct_news_page_(6|7|8|9|10|11|12|13|14|15|16|17|18|19|20|21)$#', $handle )
			) {
				return true;
			}

			// New: AG Policy composite blogs.
			if ( ! empty( $handle ) && is_string( $handle )
			&& ( preg_match(
				'#^/api-content/ct/en/blog_folder/ct_ag_policy_blog_composite/agpolicy_blog_\d{2}$#',
				$handle
			)
			|| preg_match(
				'#^/api-content/ct/en/blog_folder/ct_an_urbans_rural/an_urbans_rural_view(?:\d{1,2})?$#',
				$handle
			)
			)
			) {
				return true;
			}

			if ( preg_match(
				'#^/api-content/ct/en/columns/(vintage_iron|cash_market)$#',
				$handle
			) ) {
				return true;
			}

			// PFMag → magazine.
			$sections = implode(
				'|',
				array_map( fn( $s ) => preg_quote( $s, '#' ), $this->mappings->magazine_sections )
			);
			$pattern  = "#^/api-content/PFMag/($sections)/[^/]+_page_\\d{2}$#";

			if ( is_string( $handle ) && preg_match( $pattern, $handle ) ) {
				return true;
			}

			// market checks.
			if ( isset( $this->mappings->market_mappings[ $handle ] ) ) {
				return true;
			}

			if ( isset( $this->mappings->strategy_handles[ $handle ] ) ) {
				return true;
			}

			if ( ! empty( $route['pageMembers'] ) ) {
				foreach ( $route['pageMembers'] as $member ) {
					if ( isset( $this->mappings->strategy_page_members[ $member ] ) ) {
						return true;
					}
				}
			}

			// Fuel & Fertilizer checks.
			if ( '/api-content/ct/en/special/ct_fertilizer_index' === $handle ||
			'/api-content/ct/en/columns/fertilizer_weekly' === $handle ) {
				return true;
			}

			// Check pageMembers for fuel/fertilizer.
			if ( ! empty( $route['pageMembers'] ) ) {
				foreach ( $route['pageMembers'] as $member ) {
					if ( isset( $this->mappings->fuel_fertilizer_mappings[ $member ] ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Assign taxonomy terms based on routing handles.
	 *
	 * @param int    $post_id Post ID being updated.
	 * @param array  $routing Routing metadata.
	 * @param string $post_type The CPT slug.
	 * @param array  $data API response array.
	 */
	public function handle_taxonomy( $post_id, $routing, $post_type, $data = array() ) {
		$terms = array();

		foreach ( $routing as $route ) {
			$handle = isset( $route['handle'] ) && is_string( $route['handle'] )
					? $route['handle']
					: '';

			if ( 'news' === $post_type ) {
				// Handle news taxonomy.
				if (
				'' !== $handle && preg_match(
					'#^/api-content/ct/en/ct_news/(ct_news_page_\d{1,2}|market_impact_weather|washington_insider)$#',
					$handle
				)
				) {
					$terms[] = $this->ensure_term_exists( 'ag-news', 'news-category' );
				} elseif ( '' !== $handle && preg_match(
					'#^/api-content/ct/en/ct_news/breaking_news$#',
					$handle
				)
				) {
					$terms[] = $this->ensure_term_exists( 'breaking-news', 'news-category' );
				} elseif ( '' !== $handle && preg_match(
					'#^/api-content/ct/en/blog_folder/ct_ag_policy_blog_composite/agpolicy_blog_\d{2}$#',
					$handle
				)
				) {
					$terms[] = $this->ensure_term_exists( 'ag-policy', 'news-category' );
				} elseif ( '' !== $handle && preg_match(
					'#^/api-content/ct/en/blog_folder/ct_an_urbans_rural/an_urbans_rural_view(?:\d{1,2})?$#',
					$handle
				) ) {
					$terms[] = $this->ensure_term_exists( 'opinion', 'news-category' );
				} elseif ( '' !== $handle && preg_match(
					'#^/api-content/ct/en/columns/(vintage_iron|cash_market)$#',
					$handle,
					$m
				) ) {
					$parent_slug = 'columns';
					$this->ensure_term_exists( $parent_slug, 'news-category' );
					// map the slug in code.
					$map = array(
						'vintage_iron' => 'russ-vintage-iron',
						'cash_market'  => 'cash-market-moves',
					);

					$key        = $m[1];
					$child_slug = $map[ $key ] ?? str_replace( '_', '-', $key );
					$terms[]    = $this->ensure_term_exists(
						$child_slug,
						'news-category',
						$parent_slug
					);
				}
			}

			if ( 'markets' === $post_type ) {
				// Handle market taxonomy.
				if ( isset( $this->mappings->market_mappings[ $handle ] ) ) {
					$parent_slug = 'ct-market-commentary';
					$this->ensure_term_exists( $parent_slug, 'markets-category' );
					$terms[] = $this->ensure_term_exists(
						$this->mappings->market_mappings[ $handle ],
						'markets-category',
						$parent_slug
					);
				}

				if ( '/api-content/ct/en/special/ct_fertilizer_index' === $handle ||
				'/api-content/ct/en/columns/fertilizer_weekly' === $handle ) {
					$parent_slug = 'fuel-and-fertilizer';
					$this->ensure_term_exists( $parent_slug, 'markets-category' );
					$terms[] = $this->ensure_term_exists(
						$this->handle_to_subcategory( $handle ),
						'markets-category',
						$parent_slug
					);
				}

				// Handle strategies.
				if ( isset( $this->mappings->strategy_handles [ $handle ] ) ) {
					$parent_slug = 'market-strategies';
					$this->ensure_term_exists( $parent_slug, 'markets-category' );
					$terms[] = $this->ensure_term_exists(
						$this->mappings->strategy_handles[ $handle ],
						'markets-category',
						$parent_slug
					);
				}

				// Handle pageMembers.
				if ( ! empty( $route['pageMembers'] ) ) {
					foreach ( $route['pageMembers'] as $member ) {
						if ( isset( $this->mappings->fuel_fertilizer_mappings[ $member ] ) ) {
							$parent_slug = 'fuel-and-fertilizer';
							$this->ensure_term_exists( $parent_slug, 'markets-category' );
							$terms[] = $this->ensure_term_exists(
								$this->mappings->fuel_fertilizer_mappings[ $member ],
								'markets-category',
								$parent_slug
							);
						}
						if ( isset( $this->mappings->strategy_page_members[ $member ] ) ) {
							$parent_slug = 'market-strategies';
							$this->ensure_term_exists( $parent_slug, 'markets-category' );
							$terms[] = $this->ensure_term_exists(
								$this->mappings->strategy_page_members[ $member ],
								'markets-category',
								$parent_slug
							);
						}
					}
				}
			}

			if ( 'magazine' === $post_type ) {
				if ( preg_match( '#^/api-content/PFMag/([^/]+)/#', $handle, $m ) ) {
					$parent_slug = sanitize_title( str_replace( '_', ' ', $m[1] ) );
					$this->ensure_term_exists( $parent_slug, 'magazine-category' );
					// Use the article title as the sub-term.
					$child_slug = sanitize_title( wp_strip_all_tags( $data['head']['shortTitle'] ?? '' ) );
					if ( $child_slug ) {
						$terms[] = $this->ensure_term_exists(
							$child_slug,
							'magazine-category',
							$parent_slug
						);
					}
				}
			}
		}

		wp_set_post_terms( $post_id, array_unique( $terms ), $post_type . '-category', true );
	}

	/**
	 * Convert a route handle to a markets subcategory slug.
	 *
	 * @param string $handle Route handle string.
	 * @return string Subcategory slug.
	 */
	protected function handle_to_subcategory( $handle ) {
		// Convert handle to subcategory slug.
		$parts     = explode( '/', $handle );
		$last_part = end( $parts );
		return str_replace( '_', '-', strtolower( $last_part ) );
	}

	/**
	 * Map a routing handle to a news subterm.
	 *
	 * @param string $handle Route handle string.
	 * @return int|null Term ID or null if not found.
	 */
	public function map_subterm( $handle ) {
		$map = array(
			'/washington_insider' => 'washington-insider',
		);

		foreach ( $map as $path => $slug ) {
			if ( strpos( $handle, $path ) !== false ) {
				return $this->ensure_term_exists( $slug, 'news-category', 'ag-news' );
			}
		}
		return null;
	}

	/**
	 * Ensure a term exists, creating it if necessary.
	 *
	 * @param string $slug        Term slug.
	 * @param string $taxonomy    Taxonomy name.
	 * @param string $parent_slug Optional parent term slug.
	 * @return int|null Term ID or null on failure.
	 */
	public function ensure_term_exists( $slug, $taxonomy, $parent_slug = '' ) {
		$slug         = (string) $slug;
		static $cache = array();

		$key = "{$taxonomy}|{$slug}";
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term ) {
			$args = array( 'slug' => $slug );
			if ( ! empty( $parent_slug ) ) {
				if ( $parent = get_term_by( 'slug', $parent_slug, $taxonomy ) ) {
					$args['parent'] = $parent->term_id;
				}
			}
			$term = wp_insert_term( ucwords( str_replace( '-', ' ', $slug ) ), $taxonomy, $args );
			if ( is_wp_error( $term ) ) {
				error_log( 'Term creation error: ' . $term->get_error_message() );
				return null;
			}
			$term_id = $term['term_id'];
		} else {
			$term_id = $term->term_id;
		}

		$cache[ $key ] = $term_id;
		return $term_id;
	}

	/**
	 * Populate ACF fields from API data.
	 *
	 * @param int   $post_id Post ID being updated.
	 * @param array $data    API response array.
	 */
	public function handle_acf_fields( $post_id, $data ) {
		// Routing repeater.
		$routing_rows = array();
		foreach ( $data['routing'] as $route ) {
			$routing_rows[] = array(
				'routing_id'                  => sanitize_text_field( $route['id'] ?? '' ),
				'routing_page'                => sanitize_text_field( $route['page'] ?? '' ),
				'routing_page_members'        => isset( $route['pageMembers'] )
				? ( is_array( $route['pageMembers'] )
				? implode( ', ', array_map( 'sanitize_text_field', $route['pageMembers'] ) )
				: sanitize_text_field( $route['pageMembers'] ) )
				: '',
				'routing_handle'              => esc_url_raw( $route['handle'] ?? '' ),
				'routing_slash_command'       => sanitize_text_field( $route['slashCommand'] ?? '' ),
				'routing_page_member_summary' => sanitize_text_field( $route['pageMemberSummary'] ?? '' ),
			);
		}
		update_field( 'post_routing', $routing_rows, $post_id );

		// Head group.
		update_field(
			'post_head',
			array(
				'priority'             => absint( $data['head']['priority'] ),
				'title'                => sanitize_text_field( $data['head']['title'] ?? '' ),
				'long_title'           => sanitize_text_field( $data['head']['longTitle'] ?? '' ),
				'short_title'          => sanitize_text_field( $data['head']['shortTitle'] ?? '' ),
				'story_category'       => sanitize_text_field( $data['head']['storyCategory'] ?? '' ),
				'uuid'                 => sanitize_text_field( $data['head']['newsIdentifier']['uuid'] ?? '' ),
				'revision_id'          => sanitize_text_field( $data['head']['newsIdentifier']['revisionID'] ?? '' ),
				'previous_revision_id' => sanitize_text_field( $data['head']['newsIdentifier']['previousRevisionId'] ?? '' ),
				'last_mod_user'        => sanitize_text_field( $data['head']['newsIdentifier']['lastModUser'] ?? '' ),
				'correction'           => sanitize_text_field( $data['head']['newsIdentifier']['correction'] ?? '' ),

			),
			$post_id
		);

		// Post Body Head.
		update_field(
			'post_body_head',
			array(
				'summary'      => wp_kses_post( $data['bodyHead']['summary'] ?? '' ),
				'teaser'       => sanitize_text_field( $data['bodyHead']['teaser'] ?? '' ),
				'story_source' => sanitize_text_field( $data['bodyHead']['storySource'] ?? '' ),
				'access_type'  => sanitize_text_field( $data['bodyHead']['accessType'] ?? '' ),
			),
			$post_id
		);

		// Post Body.
		$author_title = '';
		if ( ! empty( $data['body']['contentParagraphs'] ) && is_array( $data['body']['contentParagraphs'] ) ) {
			foreach ( $data['body']['contentParagraphs'] as $block ) {
				if ( ! empty( $block['type'] ) && 'authorList' === $block['type'] && ! empty( $block['authorData'] ) ) {

					if ( ! empty( $block['authorData']['authorDefault'][0]['authorTitle'] ) ) {
						$author_title = sanitize_text_field(
							$block['authorData']['authorDefault'][0]['authorTitle']
						);
					} elseif ( ! empty( $block['authorData']['alternateAuthorAndTitle'] )
					&& is_array( $block['authorData']['alternateAuthorAndTitle'] ) ) {
						$author_title = wp_strip_all_tags(
							implode( ' ', $block['authorData']['alternateAuthorAndTitle'] )
						);
					}

					break;
				}
			}
		}
		update_field(
			'post_body',
			array(
				'primary_image_url'     => esc_url_raw( $data['body']['primaryImageUrl'] ?? '' ),
				'primary_image_summary' => sanitize_text_field( $data['body']['primaryImageSummary'] ?? '' ),
				'author_title'          => $author_title,
			),
			$post_id
		);

		// Post Body End.
		update_field(
			'post_body_end',
			array(
				'primary_topics' => sanitize_text_field( $data['bodyEnd']['primaryTopic'] ?? '' ),
				'topics'         => isset( $data['bodyEnd']['topics'] )
				? ( is_array( $data['bodyEnd']['topics'] )
				? implode( ', ', array_map( 'sanitize_text_field', $data['bodyEnd']['topics'] ) )
				: sanitize_text_field( $data['bodyEnd']['topics'] ) )
				: '',

				'copyright'      => sanitize_text_field( $data['bodyEnd']['copyRight'] ?? '' ),
			),
			$post_id
		);

		// Post JSON Data.
		$json_data = wp_json_encode( $data );
		update_field(
			'post_json_data',
			$json_data,
			$post_id
		);
	}

	/**
	 * Download and set the featured image.
	 *
	 * @param int   $post_id Post ID being updated.
	 * @param array $data    API response array.
	 */
	public function handle_featured_image( $post_id, $data ) {
		if ( empty( $data['body']['primaryImageUrl'] ) ) {
			return;
		}

		$image_url = esc_url_raw( $data['body']['primaryImageUrl'] ?? '' );
		$clean_url = strtok( $image_url, '?' );
		$alt_text  = sanitize_text_field( $data['body']['primaryImageSummary'] ?? '' );

		$previous_url = get_post_meta( $post_id, '_featured_image_remote_url', true );
		if ( $previous_url === $clean_url ) {
			return;
		}
		$remote_file = basename( $clean_url );
		$remote_name = pathinfo( $remote_file, PATHINFO_FILENAME );
		$remote_base = preg_replace( '/(?:\.[^\.]+$)|(?:-[^-]+$)/', '', $remote_name );

		$existing_image_id = get_post_thumbnail_id( $post_id );
		if ( $existing_image_id ) {
			$attachment = get_post( $existing_image_id );
			if ( $attachment && ! empty( $attachment->guid ) ) {
				$local_file = basename( wp_parse_url( $attachment->guid, PHP_URL_PATH ) );
				$local_name = pathinfo( $local_file, PATHINFO_FILENAME );
				$local_base = preg_replace( '/(?:\.[^\.]+$)|(?:-[^-]+$)/', '', $local_name );

				if ( $local_base === $remote_base ) {
					update_post_meta( $post_id, '_featured_image_remote_url', $clean_url );
					return;
				}
			}
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		try {
			$image_id = media_sideload_image( $image_url, $post_id, $alt_text, 'id' );
			if ( ! is_wp_error( $image_id ) ) {
				set_post_thumbnail( $post_id, $image_id );
				update_post_meta( $image_id, '_wp_attachment_image_alt', $alt_text );
				update_post_meta( $post_id, '_featured_image_remote_url', $clean_url );
			}
		} catch ( \Exception $e ) {
			error_log( 'Image upload failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Ensure the JSON‐provided author exists in WP and assign to post_author.
	 *
	 * @param int   $post_id The post ID being created.
	 * @param array $data    The API response array.
	 */
	public function handle_author( $post_id, $data ) {
		$author_name  = '';
		$author_email = '';
		$author_title = '';

		if ( ! empty( $data['body']['contentParagraphs'] ) && is_array( $data['body']['contentParagraphs'] ) ) {
			foreach ( $data['body']['contentParagraphs'] as $block ) {
				if ( isset( $block['type'] ) && 'authorList' === $block['type']
				&& ! empty( $block['authorData']['authorDefault'][0] ) ) {

					$info         = $block['authorData']['authorDefault'][0];
					$author_name  = sanitize_text_field( $info['author'] ?? '' );
					$author_title = sanitize_text_field( $info['authorTitle'] ?? '' );
					break;
				}
			}
		}

		if ( empty( $info ) ) {
			foreach ( $data['body']['contentParagraphs'] as $con ) {
				if ( isset( $con['type'] ) && 'authorList' === $con['type']
				&& ! empty( $con['authorData']['alternateAuthorAndTitle'][0] ) ) {

					$alternate_title = $con['authorData']['alternateAuthorAndTitle'];
					$author_title    = wp_strip_all_tags( implode( ' ', $alternate_title ) );

				}
			}
		}

		if ( empty( $author_email ) && ! empty( $data['body']['contentParagraphs'] ) ) {
			foreach ( $data['body']['contentParagraphs'] as $block ) {
				if ( ! empty( $block['content'] ) ) {
					// Use DOMDocument to safely parse the HTML.
					libxml_use_internal_errors( true );
					$doc = new \DOMDocument();
					$doc->loadHTML( '<html><body>' . $block['content'] . '</body></html>' );
					libxml_clear_errors();

					$anchors = $doc->getElementsByTagName( 'a' );
					foreach ( $anchors as $a ) {
						$href = $a->getAttribute( 'href' );
						if ( strpos( $href, 'mailto:' ) === 0 ) {
							$email        = substr( $href, 7 );
							$author_email = sanitize_email( $email );
							break 2;
						}
					}
				}
			}
		}

		if ( empty( $author_name ) && empty( $author_email ) ) {
			$author_name  = 'DTN';
			$author_email = 'snapshoteditors@ct.com';
		}

		// Split full name into first & last.
		$name_parts = preg_split( '/\s+/', trim( $author_name ) );
		$first_name = sanitize_text_field( $name_parts[0] ?? '' );
		$last_name  = sanitize_text_field( end( $name_parts ) !== $first_name ? end( $name_parts ) : '' );

		$login = $author_email
		? sanitize_user( current( explode( '@', $author_email ) ), true )
		: sanitize_user( strtolower( str_replace( ' ', '.', $author_name ) ), true );

		if ( $author_email ) {
			$user = get_user_by( 'email', $author_email );
		}
		if ( empty( $user ) && $login ) {
			$user = get_user_by( 'login', $login );
		}

		if ( empty( $user ) ) {
			$password  = wp_generate_password();
			$user_id   = wp_insert_user(
				array(
					'user_login'   => $login ? $login : $author_name,
					'user_pass'    => $password,
					'user_email'   => $author_email,
					'display_name' => $author_name,
					'first_name'   => $first_name,
					'last_name'    => $last_name,
					'role'         => 'author',
				)
			);
			$author_id = is_wp_error( $user_id ) ? 1 : (int) $user_id;
		} else {
			$author_id      = (int) $user->ID;
			$existing_first = get_user_meta( $author_id, 'first_name', true );
			$existing_last  = get_user_meta( $author_id, 'last_name', true );
			$update_fields  = array( 'ID' => $author_id );

			if ( empty( $existing_first ) ) {
				$update_fields['first_name'] = $first_name;
			}
			if ( empty( $existing_last ) ) {
				$update_fields['last_name'] = $last_name;
			}
			if ( count( $update_fields ) > 1 ) {
				wp_update_user( $update_fields );
			}
		}

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_author' => $author_id,
			)
		);

		$existing_title = get_user_meta( $author_id, 'author_title', true );
		if ( $author_title !== $existing_title ) {
			update_user_meta( $author_id, 'author_title', $author_title );
		}
	}

	/**
	 * Parse a date string into WP MySQL format.
	 *
	 * @param string $date_string Date like 'm:d:Y H:i'.
	 * @return string Formatted 'Y-m-d H:i:s' or current time on failure.
	 * @throws \Exception Return the mysql date an dtime.
	 */
	public function parse_date( $date_string ) {
		try {
			$date = \DateTime::createFromFormat( 'm:d:Y H:i', $date_string );
			if ( ! $date ) {
				throw new \Exception( 'Invalid date format' );
			}
			return $date->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return current_time( 'mysql' );
		}
	}

	/**
	 * Clean up importer state: transients and options.
	 */
	public function cleanup() {
		delete_transient( self::TRANSIENT_PREFIX . 'queue' );
		delete_option( self::TRANSIENT_PREFIX . 'total' );
		delete_option( self::TRANSIENT_PREFIX . 'processed' );
	}
}
