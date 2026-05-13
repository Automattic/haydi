<?php
/**
 * Jetpack Context — collects available Jetpack/WPCOM data to enrich AI prompts.
 *
 * All collectors are fail-safe: if Jetpack is not installed, a module is inactive,
 * or a remote API call fails, that section is silently omitted.
 */

defined( 'ABSPATH' ) || exit;

class Haydi_Jetpack_Context {

	/** Transient key + TTL for the rendered prompt section. */
	const PROMPT_CACHE_KEY = 'haydi_jp_prompt_section';
	const PROMPT_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Returns true if Jetpack is installed and active (any version/variant).
	 */
	public static function is_available(): bool {
		return class_exists( 'Jetpack' )
			|| class_exists( 'Jetpack_Options' )
			|| class_exists( 'Automattic\Jetpack\Connection\Manager' );
	}

	/**
	 * Returns true if Jetpack is installed AND connected to WordPress.com.
	 * A WPCOM blog ID is only present after a successful connection.
	 */
	public static function is_connected(): bool {
		if ( ! self::is_available() ) {
			return false;
		}
		if ( class_exists( 'Jetpack_Options' ) ) {
			return (bool) \Jetpack_Options::get_option( 'id' );
		}
		return false;
	}

	/**
	 * Collect all available Jetpack context as a structured array.
	 * Returns an empty array if Jetpack is not available.
	 */
	public function collect(): array {
		if ( ! self::is_available() ) {
			return array();
		}

		$ctx = array();

		$site_id = $this->get_site_id();
		if ( $site_id ) {
			$ctx['site_id'] = $site_id;
		}

		$plan = $this->get_plan();
		if ( $plan ) {
			$ctx['plan'] = $plan;
		}

		$modules = $this->get_active_modules();
		if ( ! empty( $modules ) ) {
			$ctx['active_modules'] = $modules;
		}

		$stats = $this->get_stats_summary();
		if ( $stats ) {
			$ctx['stats'] = $stats;
		}

		$top_posts = $this->get_top_posts();
		if ( ! empty( $top_posts ) ) {
			$ctx['top_posts'] = $top_posts;
		}

		$referrers = $this->get_top_referrers();
		if ( ! empty( $referrers ) ) {
			$ctx['top_referrers'] = $referrers;
		}

		$search_terms = $this->get_search_terms();
		if ( ! empty( $search_terms ) ) {
			$ctx['search_terms'] = $search_terms;
		}

		$social = $this->get_social_connections();
		if ( ! empty( $social ) ) {
			$ctx['social_connections'] = $social;
		}

		$blocked = $this->get_blocked_attempts();
		if ( null !== $blocked ) {
			$ctx['blocked_login_attempts'] = $blocked;
		}

		$speed = $this->get_speed_scores();
		if ( $speed ) {
			$ctx['boost_speed_scores'] = $speed;
		}

		$sync_health = $this->get_sync_health();
		if ( $sync_health ) {
			$ctx['sync_health'] = $sync_health;
		}

		return $ctx;
	}

	/**
	 * Format the collected context as a Markdown section for the AI system prompt.
	 * Returns an empty string if no Jetpack data is available.
	 *
	 * Cached in a transient because collect() can fan out to several WPCOM REST
	 * calls (stats, top posts, referrers, search terms, speed scores). Without
	 * this every chat turn paid that latency on the critical path.
	 */
	public function to_prompt_section(): string {
		$cached = get_transient( self::PROMPT_CACHE_KEY );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$rendered = $this->render_prompt_section();
		// Cache empty results too so we do not re-probe Jetpack on every chat turn
		// when it is uninstalled or disconnected.
		set_transient( self::PROMPT_CACHE_KEY, $rendered, self::PROMPT_CACHE_TTL );
		return $rendered;
	}

	private function render_prompt_section(): string {
		$ctx = $this->collect();
		if ( empty( $ctx ) ) {
			return '';
		}

		$lines = array( '## Jetpack Site Intelligence' );

		if ( isset( $ctx['plan'] ) ) {
			$lines[] = sprintf( '- **Plan**: %s (%s)', $ctx['plan']['class'], $ctx['plan']['product_slug'] );
		}

		if ( ! empty( $ctx['active_modules'] ) ) {
			$lines[] = '- **Active Jetpack modules**: ' . implode( ', ', $ctx['active_modules'] );
		}

		if ( isset( $ctx['stats'] ) ) {
			$s       = $ctx['stats'];
			$lines[] = sprintf(
				'- **Traffic (last 30 days)**: %s views, %s visitors, %s likes, %s comments',
				number_format_i18n( $s['views'] ),
				number_format_i18n( $s['visitors'] ),
				number_format_i18n( $s['likes'] ),
				number_format_i18n( $s['comments'] )
			);
		}

		if ( ! empty( $ctx['top_posts'] ) ) {
			$lines[] = '- **Top content (last 30 days)**:';
			foreach ( $ctx['top_posts'] as $i => $post ) {
				$lines[] = sprintf( '  %d. "%s" — %s views', $i + 1, $post['title'], number_format_i18n( $post['views'] ) );
			}
		}

		if ( ! empty( $ctx['top_referrers'] ) ) {
			$names   = array_column( $ctx['top_referrers'], 'name' );
			$lines[] = '- **Top traffic sources**: ' . implode( ', ', $names );
		}

		if ( ! empty( $ctx['search_terms'] ) ) {
			$terms   = array_map( static fn( $t ) => '"' . $t['term'] . '"', $ctx['search_terms'] );
			$lines[] = '- **Top visitor search terms**: ' . implode( ', ', $terms );
		}

		if ( ! empty( $ctx['social_connections'] ) ) {
			$lines[] = '- **Connected social accounts**: ' . implode( ', ', $ctx['social_connections'] );
		}

		if ( isset( $ctx['blocked_login_attempts'] ) && $ctx['blocked_login_attempts'] > 0 ) {
			$lines[] = sprintf(
				'- **Security**: %s blocked login attempts (Jetpack Protect active)',
				number_format_i18n( $ctx['blocked_login_attempts'] )
			);
		}

		if ( isset( $ctx['boost_speed_scores'] ) ) {
			$parts = array();
			if ( null !== $ctx['boost_speed_scores']['mobile'] ) {
				$parts[] = 'mobile ' . $ctx['boost_speed_scores']['mobile'] . '/100';
			}
			if ( null !== $ctx['boost_speed_scores']['desktop'] ) {
				$parts[] = 'desktop ' . $ctx['boost_speed_scores']['desktop'] . '/100';
			}
			if ( $parts ) {
				$lines[] = '- **Boost speed scores**: ' . implode( ', ', $parts );
			}
		}

		if ( isset( $ctx['sync_health'] ) ) {
			$lines[] = '- **Jetpack sync health**: ' . $ctx['sync_health'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * Returns the WordPress.com blog ID for use in Tracks analytics, or null.
	 */
	public function get_wpcom_blog_id(): ?int {
		return $this->get_site_id();
	}

	/**
	 * Returns the WordPress.com user ID for the given WP user ID, or null.
	 */
	public static function get_wpcom_user_id( int $wp_user_id ): ?int {
		$wpcom_id = get_user_meta( $wp_user_id, 'wpcom_user_id', true );
		return $wpcom_id ? (int) $wpcom_id : null;
	}

	// -------------------------------------------------------------------------
	// Private data collectors — each returns null / empty on any failure.
	// -------------------------------------------------------------------------

	/**
	 * Returns the WordPress.com blog ID, or null if unavailable.
	 */
	private function get_site_id(): ?int {
		if ( class_exists( 'Jetpack_Options' ) ) {
			$id = \Jetpack_Options::get_option( 'id' );
			return $id ? (int) $id : null;
		}
		return null;
	}

	private function get_plan(): ?array {
		if ( class_exists( 'Automattic\Jetpack\Current_Plan' ) ) {
			try {
				$plan = \Automattic\Jetpack\Current_Plan::get();
				if ( ! empty( $plan ) ) {
					return array(
						'class'        => $plan['class'] ?? 'free',
						'product_slug' => $plan['product_slug'] ?? '',
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e ); // Fall through to option-based fallback.
			}
		}
		$option = get_option( 'jetpack_active_plan' );
		if ( is_array( $option ) && isset( $option['product_slug'] ) ) {
			return array(
				'class'        => $option['class'] ?? 'unknown',
				'product_slug' => $option['product_slug'],
			);
		}
		return null;
	}

	private function get_active_modules(): array {
		if ( class_exists( 'Jetpack_Options' ) ) {
			$modules = \Jetpack_Options::get_option( 'active_modules' );
			if ( is_array( $modules ) ) {
				return $modules;
			}
		}
		if ( class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'get_active_modules' ) ) {
			$modules = \Jetpack::get_active_modules();
			return is_array( $modules ) ? $modules : array();
		}
		return array();
	}

	private function get_stats_summary(): ?array {
		if ( ! class_exists( 'Automattic\Jetpack\Stats\WPCOM_Stats' ) ) {
			return null;
		}
		try {
			$wpcom = new \Automattic\Jetpack\Stats\WPCOM_Stats();
			$data  = $wpcom->get_stats_summary(
				array(
					'num'    => 30,
					'period' => 'day',
				)
			);
			if ( is_wp_error( $data ) || empty( $data ) ) {
				return null;
			}
			return array(
				'views'    => (int) ( $data['views'] ?? 0 ),
				'visitors' => (int) ( $data['visitors'] ?? 0 ),
				'likes'    => (int) ( $data['likes'] ?? 0 ),
				'comments' => (int) ( $data['comments'] ?? 0 ),
			);
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function get_top_posts( int $limit = 5 ): array {
		if ( ! class_exists( 'Automattic\Jetpack\Stats\WPCOM_Stats' ) ) {
			return array();
		}
		try {
			$wpcom = new \Automattic\Jetpack\Stats\WPCOM_Stats();
			$data  = $wpcom->get_top_posts(
				array(
					'num'    => 30,
					'period' => 'day',
				)
			);
			if ( is_wp_error( $data ) || empty( $data['days'] ) ) {
				return array();
			}
			$day = reset( $data['days'] );
			if ( empty( $day['postviews'] ) ) {
				return array();
			}
			$posts = array();
			foreach ( array_slice( $day['postviews'], 0, $limit ) as $pv ) {
				$posts[] = array(
					'title' => $pv['title'] ?? '',
					'views' => (int) ( $pv['views'] ?? 0 ),
				);
			}
			return $posts;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	private function get_top_referrers( int $limit = 5 ): array {
		if ( ! class_exists( 'Automattic\Jetpack\Stats\WPCOM_Stats' ) ) {
			return array();
		}
		try {
			$wpcom = new \Automattic\Jetpack\Stats\WPCOM_Stats();
			$data  = $wpcom->get_referrers(
				array(
					'num'    => 30,
					'period' => 'day',
				)
			);
			if ( is_wp_error( $data ) || empty( $data['days'] ) ) {
				return array();
			}
			$day = reset( $data['days'] );
			if ( empty( $day['groups'] ) ) {
				return array();
			}
			$refs = array();
			foreach ( array_slice( $day['groups'], 0, $limit ) as $g ) {
				$refs[] = array(
					'name'  => $g['name'] ?? '',
					'views' => (int) ( $g['total'] ?? 0 ),
				);
			}
			return $refs;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	private function get_search_terms( int $limit = 5 ): array {
		if ( ! class_exists( 'Automattic\Jetpack\Stats\WPCOM_Stats' ) ) {
			return array();
		}
		try {
			$wpcom = new \Automattic\Jetpack\Stats\WPCOM_Stats();
			$data  = $wpcom->get_search_terms(
				array(
					'num'    => 30,
					'period' => 'day',
				)
			);
			if ( is_wp_error( $data ) || empty( $data['days'] ) ) {
				return array();
			}
			$day = reset( $data['days'] );
			if ( empty( $day['search_terms'] ) ) {
				return array();
			}
			$terms = array();
			foreach ( array_slice( $day['search_terms'], 0, $limit ) as $t ) {
				$terms[] = array(
					'term'  => $t['term'] ?? '',
					'views' => (int) ( $t['views'] ?? 0 ),
				);
			}
			return $terms;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	private function get_social_connections(): array {
		if ( class_exists( 'Jetpack_Options' ) ) {
			$connections = \Jetpack_Options::get_option( 'publicize_connections' );
			if ( is_array( $connections ) ) {
				return array_values( array_keys( $connections ) );
			}
		}
		return array();
	}

	private function get_blocked_attempts(): ?int {
		$blocked = get_option( 'jetpack_protect_blocked_attempts' );
		return ( false !== $blocked ) ? (int) $blocked : null;
	}

	private function get_speed_scores(): ?array {
		if ( ! class_exists( 'Automattic\Jetpack\Boost_Speed_Score\Speed_Score_History' ) ) {
			return null;
		}
		try {
			$history = new \Automattic\Jetpack\Boost_Speed_Score\Speed_Score_History( get_site_url() );
			$scores  = $history->latest_scores();
			if ( ! $scores ) {
				return null;
			}
			return array(
				'mobile'  => isset( $scores['mobile'] ) ? (int) $scores['mobile'] : null,
				'desktop' => isset( $scores['desktop'] ) ? (int) $scores['desktop'] : null,
			);
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function get_sync_health(): ?string {
		if ( class_exists( 'Jetpack_Options' ) ) {
			$health = \Jetpack_Options::get_option( 'sync_health_status' );
			return $health ? (string) $health : null;
		}
		return null;
	}
}
