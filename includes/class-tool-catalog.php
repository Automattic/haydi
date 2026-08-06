<?php
/**
 * Canonical registry, projection, approval policy, and dispatch for Haydi tools.
 */

defined( 'ABSPATH' ) || exit;

final class Haydi_Tool_Catalog {

	public const CHAT = 'chat';
	public const MCP  = 'mcp';

	private const EFFECT_AUTOMATIC = 'automatic';
	private const EFFECT_APPROVAL  = 'approval';

	/** @var array<string, array> Tool definitions keyed by canonical name. */
	private array $tools = array();

	/** @var array<string, array<string, string>> External name to canonical name by surface. */
	private array $aliases = array(
		self::CHAT => array(),
		self::MCP  => array(),
	);

	/** @var bool Once frozen, registrations are rejected so every caller sees the same catalog. */
	private bool $frozen = false;

	/** @var Haydi_Audit_Logger|null Internal sink for Tool Implementation failures. */
	private ?Haydi_Audit_Logger $logger;

	public function __construct( ?Haydi_Audit_Logger $logger = null ) {
		$this->logger = $logger;
	}

	/**
	 * Build the production catalog and register all built-in tools.
	 */
	public static function create_default(): self {
		$logger  = new Haydi_Audit_Logger();
		$catalog = new self( $logger );
		$guard   = new Haydi_Filesystem_Guard();
		$health  = new Haydi_Health_Check();

		$file_tool    = new Haydi_File_Tool( $logger, $guard );
		$plugin_tool  = new Haydi_Plugin_Tool( $logger, $health );
		$url_tool     = new Haydi_Fetch_Url_Tool( $logger );
		$content_tool = new Haydi_Content_Tool( $logger );

		$file_tool->register_tools( $catalog );
		$plugin_tool->register_tools( $catalog );
		$url_tool->register_tools( $catalog );
		$content_tool->register_tools( $catalog );
		haydi_register_file_action_tools( $catalog, $guard, $health, $logger );
		haydi_register_query_tool( $catalog, $logger, $health );
		haydi_register_php_tool( $catalog, $logger, $health );

		// Primary extension seam. It runs lazily, after every active plugin has loaded.
		if ( function_exists( 'do_action' ) ) {
			do_action( 'haydi_register_tools', $catalog );
		}

		$catalog->frozen = true;
		return $catalog;
	}

	/**
	 * Register one canonical Tool Declaration and its Tool Implementation.
	 *
	 * @param array    $definition     Canonical declaration plus surface projections.
	 * @param callable $implementation fn( array $canonical_arguments ): mixed.
	 * @throws LogicException If registration happens after the catalog is frozen.
	 * @throws InvalidArgumentException If the Tool Declaration is invalid or duplicates a public name.
	 */
	public function register( array $definition, callable $implementation ): void {
		if ( $this->frozen ) {
			throw new LogicException( 'The Haydi Tool Catalog is frozen.' );
		}

		$this->validate_definition( $definition );

		$name = $definition['name'];
		if ( isset( $this->tools[ $name ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
			throw new InvalidArgumentException( "Duplicate Haydi tool name: {$name}" );
		}

		$definition['implementation'] = $implementation;
		$projected_aliases            = array();
		foreach ( array( self::CHAT, self::MCP ) as $surface ) {
			if ( ! $this->has_projection( $definition, $surface ) ) {
				continue;
			}

			$projection = true === $definition['projections'][ $surface ]
				? array()
				: $definition['projections'][ $surface ];
			$external   = (string) ( $projection['name'] ?? $name );

			if ( isset( $this->aliases[ $surface ][ $external ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
				throw new InvalidArgumentException( "Duplicate Haydi {$surface} tool name: {$external}" );
			}

			$definition['projections'][ $surface ] = $projection;
			$projected_aliases[ $surface ]         = $external;
		}

		foreach ( $projected_aliases as $surface => $external ) {
			$this->aliases[ $surface ][ $external ] = $name;
		}
		$this->tools[ $name ] = $definition;
	}

	/**
	 * Return transport-neutral declarations for a model-facing surface.
	 *
	 * @return array<int, array{name:string, description:string, inputSchema:array}>
	 */
	public function declarations( string $surface ): array {
		$this->assert_surface( $surface );

		$declarations = array();
		foreach ( $this->tools as $definition ) {
			if ( ! $this->has_projection( $definition, $surface ) || ! $this->is_available( $definition, $surface ) ) {
				continue;
			}
			$declarations[] = $this->project_declaration( $definition, $surface );
		}

		if ( self::CHAT === $surface ) {
			return $this->apply_legacy_chat_schema_filter( $declarations );
		}

		return $this->append_legacy_mcp_declarations( $declarations );
	}

	/**
	 * Resolve, normalize, approval-gate, and optionally execute one tool call.
	 *
	 * Chat may execute automatic tools but only returns an Action Proposal for
	 * approval tools. MCP is already authenticated by its Adapter and executes
	 * either kind immediately.
	 *
	 * @param string        $surface      Model-facing surface.
	 * @param string        $external_name Public Tool name on that surface.
	 * @param array         $arguments    Public Tool arguments.
	 * @param callable|null $before       Optional callback receiving safe activity metadata before execution.
	 * @return array|WP_Error Tool Execution or Action Proposal outcome.
	 */
	public function dispatch(
		string $surface,
		string $external_name,
		array $arguments,
		?callable $before = null
	): array|WP_Error {
		$this->assert_surface( $surface );

		$canonical  = $this->aliases[ $surface ][ $external_name ] ?? null;
		$definition = null !== $canonical ? $this->tools[ $canonical ] : null;

		// Historical Action Proposals took precedence over automatic/read Tools.
		// A catalog-native approval Tool remains authoritative for its own name.
		if (
			self::CHAT === $surface
			&& ( null === $definition || self::EFFECT_AUTOMATIC === $definition['effect'] )
		) {
			$legacy = function_exists( 'haydi_get_legacy_action_proposals' )
				? haydi_get_legacy_action_proposals()
				: array();
			if ( isset( $legacy[ $external_name ] ) ) {
				return $this->legacy_action_proposal( $external_name, $arguments, $legacy[ $external_name ], $before );
			}
		}

		// The legacy chat execution hook intentionally runs before built-in reads,
		// so host integrations can override them. Approval tools remain unoverrideable.
		if ( self::CHAT === $surface && ( null === $definition || self::EFFECT_AUTOMATIC === $definition['effect'] ) ) {
			$filtered = function_exists( 'apply_filters' )
				? apply_filters( 'haydi_execute_read_tool', null, $external_name, $arguments )
				: null;
			if ( null !== $filtered ) {
				$activity = array(
					'name'   => $canonical ?? $external_name,
					'label'  => null !== $definition
						? (string) ( $definition['activity_label'] ?? $this->humanize_name( $canonical ) )
						: $this->humanize_name( $external_name ),
					'effect' => self::EFFECT_AUTOMATIC,
				);
				if ( null !== $before ) {
					$before( $activity );
				}
				return $this->legacy_chat_result_outcome(
					$canonical ?? $external_name,
					$filtered,
					$activity
				);
			}
		}

		if ( null === $definition ) {
			if ( self::MCP === $surface ) {
				$filtered = function_exists( 'apply_filters' )
					? apply_filters( 'haydi_mcp_execute_tool', null, $external_name, $arguments )
					: null;
				if ( null !== $filtered ) {
					return $this->result_outcome( $external_name, null, $surface, $filtered );
				}
			}

			return new WP_Error( 'unknown_tool', "Unknown tool: {$external_name}" );
		}

		if ( ! $this->is_available( $definition, $surface ) ) {
			return new WP_Error( 'tool_unavailable', "Tool is unavailable: {$external_name}" );
		}

		$canonical_arguments = $this->normalize_arguments( $definition, $surface, $arguments );
		$activity            = array(
			'name'   => $canonical,
			'label'  => (string) ( $definition['activity_label'] ?? $this->humanize_name( $canonical ) ),
			'effect' => $definition['effect'],
		);
		if ( null !== $before ) {
			$before( $activity );
		}

		if ( self::CHAT === $surface && self::EFFECT_APPROVAL === $definition['effect'] ) {
			return $this->action_proposal( $definition, $external_name, $canonical_arguments, $activity );
		}

		try {
			$result = ( $definition['implementation'] )( $canonical_arguments );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->result_outcome( $canonical, $definition, $surface, $result, $activity );
		} catch ( Throwable $throwable ) {
			if ( null !== $this->logger ) {
				try {
					$this->logger->log(
						'tool_execution_failed',
						$canonical,
						get_class( $throwable ) . ': ' . $throwable->getMessage()
					);
				} catch ( Throwable $logging_failure ) {
					// A logging failure must not replace or expose the original error.
					unset( $logging_failure );
				}
			}
			return new WP_Error( 'tool_execution_failed', 'Tool execution failed.' );
		}
	}

	/**
	 * Return built-in approval metadata in the historical registry shape.
	 *
	 * @return array<string, array>
	 */
	public function action_proposals(): array {
		$proposals = array();
		foreach ( $this->tools as $name => $definition ) {
			if (
				self::EFFECT_APPROVAL !== $definition['effect']
				|| ! $this->has_projection( $definition, self::CHAT )
				|| ! $this->is_available( $definition, self::CHAT )
			) {
				continue;
			}
			$proposal                  = $definition['proposal'];
			$declaration               = $this->project_declaration( $definition, self::CHAT );
			$public_name               = $declaration['name'];
			$proposals[ $public_name ] = array(
				'label'            => (string) ( $proposal['label'] ?? $this->humanize_name( $name ) ),
				'fields'           => array_keys( (array) $declaration['inputSchema']['properties'] ),
				'ajax_action'      => (string) ( $proposal['ajax_action'] ?? '' ),
				'log_action'       => (string) ( $proposal['log_action'] ?? '' ),
				'log_path_field'   => (string) ( $proposal['log_path_field'] ?? '' ),
				'tool_description' => sprintf(
					'%s(%s) — %s',
					$public_name,
					implode( ', ', array_keys( (array) $declaration['inputSchema']['properties'] ) ),
					$declaration['description']
				),
			);
		}
		return $proposals;
	}

	private function validate_definition( array $definition ): void {
		$name = $definition['name'] ?? '';
		if ( ! is_string( $name ) || ! $this->is_valid_tool_name( $name ) ) {
			throw new InvalidArgumentException( 'Haydi tool names must start with a letter or underscore and contain at most 64 letters, numbers, underscores, or hyphens.' );
		}
		if ( '' === (string) ( $definition['description'] ?? '' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
			throw new InvalidArgumentException( "Haydi tool '{$name}' requires a description." );
		}

		$schema = $definition['input_schema'] ?? null;
		if ( ! is_array( $schema ) || 'object' !== ( $schema['type'] ?? '' ) || ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
			throw new InvalidArgumentException( "Haydi tool '{$name}' requires an object JSON Schema with properties." );
		}
		foreach ( $schema['properties'] as $property_name => $property_schema ) {
			if ( ! is_string( $property_name ) || '' === $property_name || ! is_array( $property_schema ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
				throw new InvalidArgumentException( "Haydi tool '{$name}' has an invalid property schema." );
			}
		}
		if ( isset( $schema['required'] ) && ! is_array( $schema['required'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
			throw new InvalidArgumentException( "Haydi tool '{$name}' has an invalid required-property list." );
		}
		$seen_required = array();
		foreach ( $schema['required'] ?? array() as $required ) {
			if ( ! is_string( $required ) || ! array_key_exists( $required, $schema['properties'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
				throw new InvalidArgumentException( "Haydi tool '{$name}' requires unknown property '{$required}'." );
			}
			if ( isset( $seen_required[ $required ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
				throw new InvalidArgumentException( "Haydi tool '{$name}' requires property '{$required}' more than once." );
			}
			$seen_required[ $required ] = true;
		}

		$effect = $definition['effect'] ?? '';
		if ( ! in_array( $effect, array( self::EFFECT_AUTOMATIC, self::EFFECT_APPROVAL ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
			throw new InvalidArgumentException( "Haydi tool '{$name}' must declare automatic or approval execution." );
		}
		if ( self::EFFECT_APPROVAL === $effect && ( empty( $definition['proposal'] ) || ! is_array( $definition['proposal'] ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
			throw new InvalidArgumentException( "Haydi approval tool '{$name}' requires Action Proposal metadata." );
		}
		if ( isset( $definition['available'] ) && ! is_callable( $definition['available'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
			throw new InvalidArgumentException( "Haydi tool '{$name}' has an invalid availability callback." );
		}
		if ( isset( $definition['presenters'] ) && ! is_array( $definition['presenters'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
			throw new InvalidArgumentException( "Haydi tool '{$name}' has invalid presenters." );
		}
		foreach ( $definition['presenters'] ?? array() as $surface => $presenter ) {
			if ( ! in_array( $surface, array( self::CHAT, self::MCP ), true ) || ! is_callable( $presenter ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
				throw new InvalidArgumentException( "Haydi tool '{$name}' has an invalid {$surface} presenter." );
			}
		}

		$projections = $definition['projections'] ?? array();
		if ( ! is_array( $projections ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
			throw new InvalidArgumentException( "Haydi tool '{$name}' has invalid projections." );
		}
		if (
			( ! array_key_exists( self::CHAT, $projections ) || false === $projections[ self::CHAT ] )
			&& ( ! array_key_exists( self::MCP, $projections ) || false === $projections[ self::MCP ] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
			throw new InvalidArgumentException( "Haydi tool '{$name}' must be exposed to chat or MCP." );
		}

		foreach ( array( self::CHAT, self::MCP ) as $surface ) {
			$projection = $projections[ $surface ] ?? false;
			if ( false === $projection ) {
				continue;
			}
			if ( true === $projection ) {
				$projection = array();
			} elseif ( ! is_array( $projection ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
				throw new InvalidArgumentException( "Haydi tool '{$name}' has an invalid {$surface} projection." );
			}

			$public_name = $projection['name'] ?? $name;
			if ( ! is_string( $public_name ) || ! $this->is_valid_tool_name( $public_name ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
				throw new InvalidArgumentException( "Haydi tool '{$name}' has an invalid {$surface} public name." );
			}
			if ( isset( $projection['description'] ) && '' === (string) $projection['description'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
				throw new InvalidArgumentException( "Haydi tool '{$name}' has an empty {$surface} description." );
			}
			if ( isset( $projection['available'] ) && ! is_callable( $projection['available'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
				throw new InvalidArgumentException( "Haydi tool '{$name}' has an invalid {$surface} availability callback." );
			}
			if ( isset( $projection['input_aliases'] ) && ! is_array( $projection['input_aliases'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
				throw new InvalidArgumentException( "Haydi tool '{$name}' has invalid {$surface} input aliases." );
			}
			$canonical_to_external = array();
			foreach ( $projection['input_aliases'] ?? array() as $external => $canonical ) {
				if (
					! is_string( $external )
					|| '' === $external
					|| ! is_string( $canonical )
					|| ! array_key_exists( $canonical, $schema['properties'] )
				) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
					throw new InvalidArgumentException( "Haydi tool '{$name}' aliases '{$external}' to unknown property '{$canonical}'." );
				}
				if ( isset( $canonical_to_external[ $canonical ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
					throw new InvalidArgumentException( "Haydi tool '{$name}' aliases property '{$canonical}' more than once for {$surface}." );
				}
				$canonical_to_external[ $canonical ] = $external;
			}

			$projected_properties = array();
			foreach ( array_keys( $schema['properties'] ) as $canonical ) {
				$external = $canonical_to_external[ $canonical ] ?? $canonical;
				if ( isset( $projected_properties[ $external ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
					throw new InvalidArgumentException( "Haydi tool '{$name}' projects more than one property as '{$external}' for {$surface}." );
				}
				$projected_properties[ $external ] = true;
			}

			if ( isset( $projection['property_overrides'] ) && ! is_array( $projection['property_overrides'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
				throw new InvalidArgumentException( "Haydi tool '{$name}' has invalid {$surface} property overrides." );
			}
			foreach ( $projection['property_overrides'] ?? array() as $external => $override ) {
				if ( ! isset( $projected_properties[ $external ] ) || ! is_array( $override ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
					throw new InvalidArgumentException( "Haydi tool '{$name}' overrides unknown {$surface} property '{$external}'." );
				}
			}

			if ( isset( $projection['required'] ) && ! is_array( $projection['required'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
				throw new InvalidArgumentException( "Haydi tool '{$name}' has an invalid {$surface} required-property list." );
			}
			$projected_required = array();
			foreach ( $projection['required'] ?? array() as $external ) {
				if ( ! is_string( $external ) || ! isset( $projected_properties[ $external ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
					throw new InvalidArgumentException( "Haydi tool '{$name}' requires unknown {$surface} property '{$external}'." );
				}
				if ( isset( $projected_required[ $external ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
					throw new InvalidArgumentException( "Haydi tool '{$name}' requires {$surface} property '{$external}' more than once." );
				}
				$projected_required[ $external ] = true;
			}
		}

		if ( self::EFFECT_APPROVAL === $effect && false !== ( $projections[ self::CHAT ] ?? false ) ) {
			$proposal = $definition['proposal'];
			foreach ( array( 'label', 'ajax_action', 'log_action' ) as $field ) {
				if ( '' === (string) ( $proposal[ $field ] ?? '' ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
					throw new InvalidArgumentException( "Haydi approval tool '{$name}' requires proposal {$field}." );
				}
			}
			if ( ! $this->is_valid_identifier( (string) $proposal['ajax_action'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
				throw new InvalidArgumentException( "Haydi approval tool '{$name}' has an invalid AJAX action." );
			}
			if ( isset( $proposal['response_key'] ) && ! $this->is_valid_identifier( (string) $proposal['response_key'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
				throw new InvalidArgumentException( "Haydi approval tool '{$name}' has an invalid response key." );
			}
			$path_field = (string) ( $proposal['log_path_field'] ?? '' );
			if ( '' !== $path_field && ! array_key_exists( $path_field, $schema['properties'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
				throw new InvalidArgumentException( "Haydi approval tool '{$name}' logs unknown property '{$path_field}'." );
			}
		}
	}

	private function is_valid_tool_name( string $name ): bool {
		return 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $name );
	}

	private function is_valid_identifier( string $name ): bool {
		return '' !== $name && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $name );
	}

	private function project_declaration( array $definition, string $surface ): array {
		$projection = $definition['projections'][ $surface ];
		$schema     = $definition['input_schema'];
		$aliases    = (array) ( $projection['input_aliases'] ?? array() );
		$inverse    = array_flip( $aliases );
		$properties = array();

		foreach ( $schema['properties'] as $canonical => $property ) {
			$external                = $inverse[ $canonical ] ?? $canonical;
			$override                = $projection['property_overrides'][ $external ] ?? array();
			$properties[ $external ] = array_merge( $property, $override );
		}

		$required = array_key_exists( 'required', $projection )
			? (array) $projection['required']
			: array_map(
				static fn( $canonical ) => $inverse[ $canonical ] ?? $canonical,
				(array) ( $schema['required'] ?? array() )
			);

		return array(
			'name'        => (string) ( $projection['name'] ?? $definition['name'] ),
			'description' => (string) ( $projection['description'] ?? $definition['description'] ),
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => empty( $properties ) ? new stdClass() : $properties,
				'required'   => $required,
			),
		);
	}

	private function normalize_arguments( array $definition, string $surface, array $arguments ): array {
		$projection = $definition['projections'][ $surface ];
		$aliases    = (array) ( $projection['input_aliases'] ?? array() );
		$properties = $definition['input_schema']['properties'];
		$normalized = array();

		foreach ( $arguments as $external => $value ) {
			$canonical = $aliases[ $external ] ?? $external;
			if ( array_key_exists( $canonical, $properties ) ) {
				$normalized[ $canonical ] = $value;
			}
		}
		return $normalized;
	}

	private function action_proposal(
		array $definition,
		string $external_name,
		array $arguments,
		array $activity
	): array {
		$proposal = $definition['proposal'];
		$payload  = array(
			// The provider transcript must answer the exact projected name that
			// appeared in the model's Tool call, not the canonical catalog name.
			'tool_name' => $external_name,
		);
		foreach ( array_keys( $definition['input_schema']['properties'] ) as $field ) {
			$payload[ $field ] = $arguments[ $field ] ?? '';
		}

		$response_key = (string) ( $proposal['response_key'] ?? 'pending_action' );
		if ( 'pending_action' === $response_key ) {
			$payload['label']        = (string) ( $proposal['label'] ?? $this->humanize_name( $definition['name'] ) );
			$payload['ajax_action']  = (string) ( $proposal['ajax_action'] ?? '' );
			$payload['payload_keys'] = array_keys( $definition['input_schema']['properties'] );
		}

		$path_field = (string) ( $proposal['log_path_field'] ?? '' );
		return array(
			'kind'         => 'action_proposal',
			'name'         => $definition['name'],
			'response_key' => $response_key,
			'payload'      => $payload,
			'activity'     => $activity,
			'audit'        => array(
				'action' => (string) ( $proposal['log_action'] ?? '' ),
				'path'   => '' !== $path_field ? (string) ( $payload[ $path_field ] ?? '' ) : '',
				'reason' => (string) ( $payload['reason'] ?? '' ),
			),
		);
	}

	private function legacy_action_proposal( string $name, array $arguments, array $proposal, ?callable $before ): array {
		$activity = array(
			'name'   => $name,
			'label'  => 'Prepared ' . strtolower( (string) ( $proposal['label'] ?? $this->humanize_name( $name ) ) ),
			'effect' => self::EFFECT_APPROVAL,
		);
		if ( null !== $before ) {
			$before( $activity );
		}

		$payload = array( 'tool_name' => $name );
		foreach ( (array) ( $proposal['fields'] ?? array() ) as $field ) {
			$payload[ $field ] = $arguments[ $field ] ?? '';
		}
		$payload['label']        = (string) ( $proposal['label'] ?? $this->humanize_name( $name ) );
		$payload['ajax_action']  = (string) ( $proposal['ajax_action'] ?? '' );
		$payload['payload_keys'] = (array) ( $proposal['fields'] ?? array() );

		$path_field = (string) ( $proposal['log_path_field'] ?? '' );
		return array(
			'kind'         => 'action_proposal',
			'name'         => $name,
			'response_key' => 'pending_action',
			'payload'      => $payload,
			'activity'     => $activity,
			'audit'        => array(
				'action' => (string) ( $proposal['log_action'] ?? '' ),
				'path'   => '' !== $path_field ? (string) ( $payload[ $path_field ] ?? '' ) : '',
				'reason' => (string) ( $payload['reason'] ?? '' ),
			),
		);
	}

	private function result_outcome(
		string $canonical,
		?array $definition,
		string $surface,
		mixed $result,
		?array $activity = null
	): array|WP_Error {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( null !== $definition && isset( $definition['presenters'][ $surface ] ) ) {
			$result = ( $definition['presenters'][ $surface ] )( $result );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( is_string( $result ) ) {
			$content = $result;
		} else {
			$options = self::MCP === $surface ? JSON_PRETTY_PRINT : 0;
			$content = wp_json_encode( $result, $options );
			if ( ! is_string( $content ) ) {
				return new WP_Error( 'tool_result_encoding_failed', "Could not encode tool result: {$canonical}" );
			}
		}

		if ( null === $activity ) {
			$activity = array(
				'name'   => $canonical,
				'label'  => null !== $definition
					? (string) ( $definition['activity_label'] ?? $this->humanize_name( $canonical ) )
					: $this->humanize_name( $canonical ),
				'effect' => self::EFFECT_AUTOMATIC,
			);
		}

		return array(
			'kind'     => 'result',
			'name'     => $canonical,
			'content'  => $content,
			'activity' => $activity,
		);
	}

	private function legacy_chat_result_outcome(
		string $canonical,
		mixed $result,
		array $activity
	): array|WP_Error {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$content = is_string( $result ) ? $result : wp_json_encode( $result );
		if ( ! is_string( $content ) ) {
			return new WP_Error( 'tool_result_encoding_failed', 'Tool result could not be encoded.' );
		}

		return array(
			'kind'     => 'result',
			'name'     => $canonical,
			'content'  => $content,
			'activity' => $activity,
		);
	}

	private function is_available( array $definition, string $surface ): bool {
		try {
			if ( isset( $definition['available'] ) && ! ( $definition['available'] )() ) {
				return false;
			}
			$projection = $definition['projections'][ $surface ] ?? array();
			return ! isset( $projection['available'] ) || (bool) ( $projection['available'] )();
		} catch ( Throwable ) {
			return false;
		}
	}

	private function has_projection( array $definition, string $surface ): bool {
		return array_key_exists( $surface, $definition['projections'] )
			&& false !== $definition['projections'][ $surface ];
	}

	private function apply_legacy_chat_schema_filter( array $declarations ): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $declarations;
		}

		$legacy = array();
		foreach ( $declarations as $declaration ) {
			$schema     = $declaration['inputSchema'];
			$properties = is_array( $schema['properties'] ) ? $schema['properties'] : array();
			$required   = (array) ( $schema['required'] ?? array() );
			$fields     = array();
			foreach ( $properties as $field => $property ) {
				$is_required = in_array( $field, $required, true );
				if ( $is_required && 'string' === ( $property['type'] ?? 'string' ) && count( $property ) <= 2 ) {
					$fields[ $field ] = (string) ( $property['description'] ?? '' );
				} else {
					$fields[ $field ] = array_merge(
						$property,
						array( 'required' => $is_required )
					);
				}
			}
			$legacy[ $declaration['name'] ] = array(
				'description' => $declaration['description'],
				'fields'      => $fields,
			);
		}

		$filtered = apply_filters( 'haydi_tool_schemas', $legacy );
		$output   = array();
		foreach ( (array) $filtered as $name => $spec ) {
			$properties = array();
			$required   = array();
			foreach ( (array) ( $spec['fields'] ?? array() ) as $field => $field_spec ) {
				$is_required = true;
				if ( is_array( $field_spec ) ) {
					$property    = $field_spec;
					$is_required = $property['required'] ?? true;
					unset( $property['required'] );
					$property['type']        = $property['type'] ?? 'string';
					$property['description'] = $property['description'] ?? '';
				} else {
					$property = array(
						'type'        => 'string',
						'description' => (string) $field_spec,
					);
				}
				$properties[ $field ] = $property;
				if ( $is_required ) {
					$required[] = $field;
				}
			}
			$output[] = array(
				'name'        => (string) $name,
				'description' => (string) ( $spec['description'] ?? '' ),
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => empty( $properties ) ? new stdClass() : $properties,
					'required'   => $required,
				),
			);
		}
		return $output;
	}

	/**
	 * Keep the historical MCP declaration filter additive.
	 *
	 * Legacy integrations receive the built-in declarations so their existing
	 * append callbacks continue to work. The canonical entries are then restored
	 * unchanged, and only valid, non-colliding additions are retained. This keeps
	 * tools/list aligned with dispatch(), where catalog Tools also win collisions.
	 *
	 * @param array<int, array{name:string, description:string, inputSchema:array}> $declarations Canonical declarations.
	 * @return array<int, array{name:string, description:string, inputSchema:array}>
	 */
	private function append_legacy_mcp_declarations( array $declarations ): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $declarations;
		}

		$known = array_fill_keys( array_column( $declarations, 'name' ), true );
		foreach ( (array) apply_filters( 'haydi_mcp_tools', $declarations ) as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$name         = $candidate['name'] ?? '';
			$input_schema = $candidate['inputSchema'] ?? null;
			if (
				! is_string( $name )
				|| ! $this->is_valid_tool_name( $name )
				|| isset( $known[ $name ] )
				|| '' === (string) ( $candidate['description'] ?? '' )
				|| ! is_array( $input_schema )
				|| 'object' !== ( $input_schema['type'] ?? '' )
				|| ! isset( $input_schema['properties'] )
				|| ( ! is_array( $input_schema['properties'] ) && ! is_object( $input_schema['properties'] ) )
				|| ( isset( $input_schema['required'] ) && ! is_array( $input_schema['required'] ) )
			) {
				continue;
			}

			$known[ $name ] = true;
			$declarations[] = $candidate;
		}

		return $declarations;
	}

	private function assert_surface( string $surface ): void {
		if ( ! in_array( $surface, array( self::CHAT, self::MCP ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is never rendered.
			throw new InvalidArgumentException( "Unknown Haydi tool surface: {$surface}" );
		}
	}

	private function humanize_name( string $name ): string {
		return ucfirst( str_replace( array( 'haydi_', '_', '-' ), array( '', ' ', ' ' ), $name ) );
	}
}
