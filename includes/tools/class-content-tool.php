<?php
/**
 * WordPress content inventory tools shared by chat and MCP.
 */

defined( 'ABSPATH' ) || exit;

final class Haydi_Content_Tool {

	/** @var Haydi_Audit_Logger Tool execution audit logger. */
	private Haydi_Audit_Logger $logger;

	public function __construct( Haydi_Audit_Logger $logger ) {
		$this->logger = $logger;
	}

	public function register_tools( Haydi_Tool_Catalog $catalog ): void {
		$catalog->register(
			array(
				'name'           => 'list_posts',
				'description'    => 'List WordPress posts and pages with their content, ID, title, status, type, modified date. Defaults to 50 most recently modified of any status across posts and pages.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array(
							'type'        => 'string',
							'description' => 'Post status filter (publish, draft, any, etc.). Defaults to any.',
						),
						'type'   => array(
							'type'        => 'string',
							'description' => 'Post type filter (post, page, etc.). Defaults to post and page.',
						),
						'limit'  => array(
							'type'        => 'string',
							'description' => 'Maximum results to return (1-200). Defaults to 50.',
						),
					),
					'required'   => array(),
				),
				'effect'         => 'automatic',
				'activity_label' => 'Listed posts',
				'projections'    => array(
					'chat' => true,
					'mcp'  => array(
						'name'               => 'haydi_list_posts',
						'description'        => 'List WordPress posts/pages with ID, title, status, type, date, and content.',
						'property_overrides' => array(
							'limit' => array( 'type' => 'number' ),
						),
					),
				),
				'presenters'     => array(
					// Keep full post bodies available to MCP without flooding chat context.
					'chat' => static function ( array $result ): array {
						foreach ( $result['posts'] as &$row ) {
							unset( $row['content'] );
						}
						unset( $row );
						return $result;
					},
				),
			),
			fn( array $arguments ): array => array( 'posts' => $this->list_posts( $arguments ) )
		);

		$catalog->register(
			array(
				'name'           => 'list_users',
				'description'    => 'List WordPress users with their ID, login, email, display name, and roles. Defaults to 50 most recently registered.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'role'  => array(
							'type'        => 'string',
							'description' => 'Role filter (administrator, editor, etc.). Leave empty for all roles.',
						),
						'limit' => array(
							'type'        => 'string',
							'description' => 'Maximum results to return (1-200). Defaults to 50.',
						),
					),
					'required'   => array(),
				),
				'effect'         => 'automatic',
				'activity_label' => 'Listed users',
				'projections'    => array(
					'chat' => true,
					'mcp'  => array(
						'name'               => 'haydi_list_users',
						'description'        => 'List WordPress users with ID, login, email, display_name, and roles.',
						'property_overrides' => array(
							'limit' => array( 'type' => 'number' ),
						),
					),
				),
			),
			fn( array $arguments ): array => array( 'users' => $this->list_users( $arguments ) )
		);

		$catalog->register(
			array(
				'name'           => 'list_options',
				'description'    => 'List WordPress site options. Without a search term returns autoloaded options; with a search term filters option_name by substring. Capped at 100 rows.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array(
							'type'        => 'string',
							'description' => 'Substring to filter option_name by. Leave empty to list autoloaded options.',
						),
					),
					'required'   => array(),
				),
				'effect'         => 'automatic',
				'activity_label' => 'Listed options',
				'projections'    => array(
					'chat' => true,
					'mcp'  => array(
						'name'        => 'haydi_list_options',
						'description' => 'List WordPress options. Without search returns autoloaded options; with search filters by option_name substring.',
					),
				),
			),
			fn( array $arguments ): array => array( 'options' => $this->list_options( $arguments ) )
		);
	}

	private function list_posts( array $arguments ): array {
		$status = (string) ( $arguments['status'] ?? '' );
		$type   = (string) ( $arguments['type'] ?? '' );
		$limit  = (string) ( $arguments['limit'] ?? '' );
		$query  = array(
			'post_status'    => '' !== $status ? sanitize_text_field( $status ) : 'any',
			'post_type'      => '' !== $type ? sanitize_text_field( $type ) : array( 'post', 'page' ),
			'posts_per_page' => '' !== $limit ? min( 200, (int) $limit ) : 50,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		$rows   = array();
		foreach ( get_posts( $query ) as $post ) {
			$rows[] = array(
				'ID'      => $post->ID,
				'title'   => $post->post_title,
				'status'  => $post->post_status,
				'type'    => $post->post_type,
				'date'    => $post->post_date,
				'content' => $post->post_content,
			);
		}
		$this->logger->log( 'list_posts', '' );
		return $rows;
	}

	private function list_users( array $arguments ): array {
		$role  = (string) ( $arguments['role'] ?? '' );
		$limit = (string) ( $arguments['limit'] ?? '' );
		$query = array(
			'number'  => '' !== $limit ? min( 200, (int) $limit ) : 50,
			'orderby' => 'user_registered',
			'order'   => 'DESC',
		);
		if ( '' !== $role ) {
			$query['role'] = sanitize_text_field( $role );
		}

		$rows = array();
		foreach ( get_users( $query ) as $user ) {
			$rows[] = array(
				'ID'           => $user->ID,
				'login'        => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
				'roles'        => $user->roles,
			);
		}
		$this->logger->log( 'list_users', '' );
		return $rows;
	}

	private function list_options( array $arguments ): array {
		global $wpdb;
		$search = (string) ( $arguments['search'] ?? '' );
		if ( '' !== $search ) {
			$like = $wpdb->esc_like( sanitize_text_field( $search ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 100", '%' . $like . '%' ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE autoload = 'yes' ORDER BY option_name LIMIT 100",
				ARRAY_A
			);
		}

		foreach ( $rows as &$row ) {
			if ( strlen( $row['option_value'] ) > 500 ) {
				$row['option_value'] = substr( $row['option_value'], 0, 500 ) . '...(truncated)';
			}
		}
		unset( $row );
		$this->logger->log( 'list_options', '', $search );
		return $rows;
	}
}
