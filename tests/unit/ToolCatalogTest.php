<?php
/**
 * Tests for the public Haydi Tool Catalog interface.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class Haydi_Test_Fetch_Url_Tool extends Haydi_Fetch_Url_Tool {

	public int $fetch_count = 0;

	/** @var string[] */
	public array $fetched_urls = array();

	public function fetch_for_ai( string $url ): array|WP_Error {
		++$this->fetch_count;
		$this->fetched_urls[] = $url;

		return array(
			'url'     => $url,
			'content' => 'Fixture content.',
		);
	}
}

final class ToolCatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value, ...$args ) {
				unset( $hook, $args );
				return $value;
			}
		);
		Functions\when( 'wp_is_file_mod_allowed' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$GLOBALS['haydi_action_proposals'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['haydi_action_proposals'] = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_default_catalog_exposes_expected_chat_and_mcp_declarations(): void {
		$catalog = Haydi_Tool_Catalog::create_default();

		$chat_names = array_column( $catalog->declarations( Haydi_Tool_Catalog::CHAT ), 'name' );
		$mcp_names  = array_column( $catalog->declarations( Haydi_Tool_Catalog::MCP ), 'name' );

		$this->assertCount( 21, $chat_names );
		$this->assertSame(
			array(
				'list_files',
				'read_file',
				'search_files',
				'list_backups',
				'list_plugins',
				'install_plugin',
				'activate_plugin',
				'deactivate_plugin',
				'fetch_url',
				'list_posts',
				'list_users',
				'list_options',
				'write_file',
				'edit',
				'delete_file',
				'move_file',
				'copy_file',
				'delete_dir',
				'restore_backup',
				'run_query',
				'run_php',
			),
			$chat_names
		);

		$this->assertCount( 22, $mcp_names );
		$this->assertSame(
			array(
				'haydi_list_files',
				'haydi_read_file',
				'haydi_search_files',
				'haydi_list_backups',
				'haydi_get_allowed_roots',
				'haydi_list_plugins',
				'haydi_install_plugin',
				'haydi_activate_plugin',
				'haydi_deactivate_plugin',
				'haydi_fetch_url',
				'haydi_list_posts',
				'haydi_list_users',
				'haydi_list_options',
				'haydi_write_file',
				'haydi_edit_file',
				'haydi_delete_file',
				'haydi_move_file',
				'haydi_copy_file',
				'haydi_delete_directory',
				'haydi_restore_backup',
				'haydi_run_query',
				'haydi_run_php',
			),
			$mcp_names
		);
	}

	public function test_automatic_tool_executes_and_returns_a_result(): void {
		$received = null;
		$catalog  = new Haydi_Tool_Catalog();
		$catalog->register(
			$this->automatic_definition( 'echo_value' ),
			static function ( array $arguments ) use ( &$received ): array {
				$received = $arguments;
				return array( 'echo' => $arguments['value'] );
			}
		);

		$outcome = $catalog->dispatch(
			Haydi_Tool_Catalog::CHAT,
			'echo_value',
			array( 'value' => 'hello' )
		);

		$this->assertIsArray( $outcome );
		$this->assertSame( array( 'value' => 'hello' ), $received );
		$this->assertSame( 'result', $outcome['kind'] );
		$this->assertSame( 'echo_value', $outcome['name'] );
		$this->assertSame( array( 'echo' => 'hello' ), $outcome['result'] );
		$this->assertArrayNotHasKey( 'content', $outcome );
		$this->assertArrayNotHasKey( 'data', $outcome );
	}

	public function test_scalar_results_remain_native(): void {
		$catalog = new Haydi_Tool_Catalog();
		$catalog->register( $this->automatic_definition( 'false_value' ), static fn(): bool => false );

		$outcome = $catalog->dispatch(
			Haydi_Tool_Catalog::CHAT,
			'false_value',
			array( 'value' => 'ignored' )
		);

		$this->assertFalse( $outcome['result'] );
	}

	public function test_presenter_returns_a_native_structured_result(): void {
		$catalog    = new Haydi_Tool_Catalog();
		$definition = $this->automatic_definition( 'presented_value' );
		$definition['presenters'] = array(
			'chat' => static fn( array $result ): array => array(
				'value' => $result['value'],
				'meta'  => array( 'presented' => true ),
			),
		);
		$catalog->register(
			$definition,
			static fn(): array => array(
				'value'   => 'hello',
				'private' => 'not presented',
			)
		);

		$outcome = $catalog->dispatch(
			Haydi_Tool_Catalog::CHAT,
			'presented_value',
			array( 'value' => 'ignored' )
		);

		$this->assertSame(
			array(
				'value' => 'hello',
				'meta'  => array( 'presented' => true ),
			),
			$outcome['result']
		);
	}

	public function test_chat_returns_action_proposal_without_executing_approval_tool(): void {
		$execution_count = 0;
		$catalog         = new Haydi_Tool_Catalog();
		$catalog->register(
			array(
				'name'         => 'clear_cache',
				'description'  => 'Clear the site cache after approval.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'reason' => array( 'type' => 'string', 'description' => 'Reason for clearing the cache.' ),
					),
					'required'   => array( 'reason' ),
				),
				'effect'       => 'approval',
				'proposal'     => array(
					'label'          => 'Clear Cache',
					'log_action'     => 'cache_clear_proposed',
					'log_path_field' => '',
				),
				'projections'  => array(
					'chat' => true,
					'mcp'  => array( 'name' => 'haydi_clear_cache' ),
				),
			),
			static function () use ( &$execution_count ): string {
				++$execution_count;
				return 'cleared';
			}
		);

		$outcome = $catalog->dispatch(
			Haydi_Tool_Catalog::CHAT,
			'clear_cache',
			array( 'reason' => 'Refresh stale pages.' )
		);

		$this->assertSame( 0, $execution_count, 'Chat must never execute an approval-gated Tool Implementation.' );
		$this->assertIsArray( $outcome );
		$this->assertSame( 'action_proposal', $outcome['kind'] );
		$this->assertSame(
			array(
				'tool_name' => 'clear_cache',
				'label'     => 'Clear Cache',
				'arguments' => array( 'reason' => 'Refresh stale pages.' ),
			),
			$outcome['payload']
		);
	}

	public function test_execute_approved_runs_the_registered_implementation_and_returns_structured_result(): void {
		$received = null;
		$catalog  = new Haydi_Tool_Catalog();
		$catalog->register(
			array(
				'name'         => 'clear_cache_internal',
				'description'  => 'Clear the site cache after approval.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'reason' => array( 'type' => 'string', 'description' => 'Reason.' ),
					),
					'required'   => array( 'reason' ),
				),
				'effect'       => 'approval',
				'proposal'     => array(
					'label'      => 'Clear Cache',
					'log_action' => 'cache_clear_proposed',
				),
				'projections'  => array(
					'chat' => array(
						'name'          => 'clear_cache',
						'input_aliases' => array( 'why' => 'reason' ),
					),
				),
			),
			static function ( array $arguments ) use ( &$received ): array {
				$received = $arguments;
				return array( 'cleared' => true );
			}
		);

		$outcome = $catalog->execute_approved( 'clear_cache', array( 'why' => 'Refresh pages.' ) );

		$this->assertSame( array( 'reason' => 'Refresh pages.' ), $received );
		$this->assertSame( 'result', $outcome['kind'] );
		$this->assertSame( 'clear_cache_internal', $outcome['name'] );
		$this->assertSame( array( 'cleared' => true ), $outcome['result'] );
		$this->assertArrayNotHasKey( 'content', $outcome );
		$this->assertArrayNotHasKey( 'data', $outcome );
	}

	public function test_execute_approved_rejects_automatic_and_unknown_tools(): void {
		$catalog = new Haydi_Tool_Catalog();
		$catalog->register( $this->automatic_definition( 'read_cache' ), static fn(): string => 'read' );

		$automatic = $catalog->execute_approved( 'read_cache', array( 'value' => 'x' ) );
		$unknown   = $catalog->execute_approved( 'missing_tool', array() );

		$this->assertInstanceOf( WP_Error::class, $automatic );
		$this->assertSame( 'tool_not_approvable', $automatic->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $unknown );
		$this->assertSame( 'unknown_tool', $unknown->get_error_code() );
	}

	public function test_fetch_url_chat_returns_exact_url_proposal_without_fetching(): void {
		$catalog = new Haydi_Tool_Catalog();
		$tool    = new Haydi_Test_Fetch_Url_Tool( $this->createStub( Haydi_Audit_Logger::class ) );
		$tool->register_tools( $catalog );
		$url = 'https://example.com/reference?section=security';

		$outcome = $catalog->dispatch(
			Haydi_Tool_Catalog::CHAT,
			'fetch_url',
			array( 'url' => $url )
		);

		$this->assertSame( 0, $tool->fetch_count, 'Browser chat must not issue an HTTP request before approval.' );
		$this->assertSame( 'action_proposal', $outcome['kind'] );
		$this->assertSame(
			array(
				'tool_name' => 'fetch_url',
				'label'     => 'Fetch URL',
				'arguments' => array( 'url' => $url ),
			),
			$outcome['payload']
		);
		$this->assertSame(
			array(
				'action' => 'fetch_url_proposed',
				'path'   => $url,
				'reason' => '',
			),
			$outcome['audit']
		);

		$proposal = $catalog->action_proposals()['fetch_url'];
		$this->assertSame( array( 'url' ), $proposal['fields'] );
		$this->assertSame( 'haydi_execute_approved_tool', $proposal['ajax_action'] );
		$this->assertSame( 'url', $proposal['log_path_field'] );
	}

	public function test_execute_approved_fetch_url_issues_the_fetch(): void {
		$catalog = new Haydi_Tool_Catalog();
		$tool    = new Haydi_Test_Fetch_Url_Tool( $this->createStub( Haydi_Audit_Logger::class ) );
		$tool->register_tools( $catalog );
		$url = 'https://example.com/approved-reference';

		$outcome = $catalog->execute_approved( 'fetch_url', array( 'url' => $url ) );

		$this->assertSame( 1, $tool->fetch_count );
		$this->assertSame( array( $url ), $tool->fetched_urls );
		$this->assertSame( 'result', $outcome['kind'] );
		$this->assertSame(
			array(
				'url'     => $url,
				'content' => 'Fixture content.',
			),
			$outcome['result']
		);
	}

	public function test_fetch_url_mcp_dispatch_remains_immediate(): void {
		$catalog = new Haydi_Tool_Catalog();
		$tool    = new Haydi_Test_Fetch_Url_Tool( $this->createStub( Haydi_Audit_Logger::class ) );
		$tool->register_tools( $catalog );
		$url = 'https://example.com/mcp-reference';

		$outcome = $catalog->dispatch(
			Haydi_Tool_Catalog::MCP,
			'haydi_fetch_url',
			array( 'url' => $url )
		);

		$this->assertSame( 1, $tool->fetch_count );
		$this->assertSame( array( $url ), $tool->fetched_urls );
		$this->assertSame( 'result', $outcome['kind'] );
		$this->assertSame( $url, $outcome['result']['url'] );
	}

	public function test_chat_action_proposal_responds_with_the_projected_tool_name(): void {
		$catalog = new Haydi_Tool_Catalog();
		$catalog->register(
			array(
				'name'         => 'clear_cache_internal',
				'description'  => 'Clear the cache after approval.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'effect'       => 'approval',
				'proposal'     => array(
					'label'       => 'Clear Cache',
					'log_action'  => 'clear_cache_proposed',
				),
				'projections'  => array(
					'chat' => array( 'name' => 'clear_cache' ),
				),
			),
			static fn(): string => 'must not execute'
		);

		$outcome = $catalog->dispatch( Haydi_Tool_Catalog::CHAT, 'clear_cache', array() );
		$proposals = $catalog->action_proposals();

		$this->assertSame( 'clear_cache_internal', $outcome['name'] );
		$this->assertSame( 'clear_cache', $outcome['payload']['tool_name'] );
		$this->assertArrayHasKey( 'clear_cache', $proposals );
		$this->assertArrayNotHasKey( 'clear_cache_internal', $proposals );
		$this->assertSame( 'haydi_execute_approved_tool', $proposals['clear_cache']['ajax_action'] );
	}

	public function test_mcp_projection_aliases_name_and_arguments_for_one_implementation(): void {
		$received = null;
		$catalog  = new Haydi_Tool_Catalog();
		$catalog->register(
			array(
				'name'         => 'edit',
				'description'  => 'Edit a file.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'filePath' => array( 'type' => 'string', 'description' => 'File path.' ),
						'oldString' => array( 'type' => 'string', 'description' => 'Existing text.' ),
					),
					'required'   => array( 'filePath', 'oldString' ),
				),
				'effect'       => 'automatic',
				'projections'  => array(
					'chat' => true,
					'mcp'  => array(
						'name'          => 'haydi_edit_file',
						'input_aliases' => array(
							'path'       => 'filePath',
							'old_string' => 'oldString',
						),
					),
				),
			),
			static function ( array $arguments ) use ( &$received ): string {
				$received = $arguments;
				return 'edited';
			}
		);

		$mcp_declarations = $catalog->declarations( Haydi_Tool_Catalog::MCP );
		$outcome          = $catalog->dispatch(
			Haydi_Tool_Catalog::MCP,
			'haydi_edit_file',
			array(
				'path'       => '/tmp/example.php',
				'old_string' => 'old',
			)
		);

		$this->assertSame( 'haydi_edit_file', $mcp_declarations[0]['name'] );
		$this->assertSame(
			array( 'path', 'old_string' ),
			array_keys( $mcp_declarations[0]['inputSchema']['properties'] )
		);
		$this->assertSame( array( 'path', 'old_string' ), $mcp_declarations[0]['inputSchema']['required'] );
		$this->assertSame(
			array(
				'filePath' => '/tmp/example.php',
				'oldString' => 'old',
			),
			$received
		);
		$this->assertSame( 'result', $outcome['kind'] );
		$this->assertSame( 'edit', $outcome['name'] );
	}

	public function test_duplicate_canonical_name_is_rejected(): void {
		$catalog    = new Haydi_Tool_Catalog();
		$definition = $this->automatic_definition( 'same_name' );
		$catalog->register( $definition, static fn(): string => 'first' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Duplicate Haydi tool name: same_name' );
		$catalog->register( $definition, static fn(): string => 'second' );
	}

	public function test_provider_portable_tool_names_are_enforced(): void {
		foreach ( array( '9starts_with_digit', '-starts-with-hyphen', str_repeat( 'a', 65 ), 123 ) as $invalid_name ) {
			$catalog               = new Haydi_Tool_Catalog();
			$definition            = $this->automatic_definition( 'valid_name' );
			$definition['name']    = $invalid_name;
			$definition['projections']['mcp']['name'] = 'haydi_valid_name';

			try {
				$catalog->register( $definition, static fn(): string => 'invalid' );
				$this->fail( 'Expected a provider-incompatible Tool name to be rejected.' );
			} catch ( InvalidArgumentException $exception ) {
				$this->assertStringContainsString( 'must start with a letter or underscore', $exception->getMessage() );
			}
		}
	}

	public function test_provider_portable_projected_names_are_enforced(): void {
		$catalog    = new Haydi_Tool_Catalog();
		$definition = $this->automatic_definition( 'valid_name' );
		$definition['projections']['mcp']['name'] = '9_invalid_projection';

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid mcp public name' );
		$catalog->register( $definition, static fn(): string => 'invalid' );
	}

	public function test_duplicate_surface_name_is_rejected(): void {
		$catalog = new Haydi_Tool_Catalog();
		$catalog->register( $this->automatic_definition( 'first', 'shared_alias' ), static fn(): string => 'first' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Duplicate Haydi mcp tool name: shared_alias' );
		$catalog->register( $this->automatic_definition( 'second', 'shared_alias' ), static fn(): string => 'second' );
	}

	public function test_projection_rejects_property_name_collisions(): void {
		$catalog    = new Haydi_Tool_Catalog();
		$definition = $this->automatic_definition( 'colliding_properties' );
		$definition['input_schema']['properties']['alias'] = array( 'type' => 'string' );
		$definition['projections']['mcp']['input_aliases']  = array( 'alias' => 'value' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( "projects more than one property as 'alias'" );
		$catalog->register( $definition, static fn(): string => 'invalid' );
	}

	public function test_projection_rejects_unknown_required_and_override_properties(): void {
		$catalog    = new Haydi_Tool_Catalog();
		$definition = $this->automatic_definition( 'invalid_required' );
		$definition['projections']['mcp']['required'] = array( 'missing' );

		try {
			$catalog->register( $definition, static fn(): string => 'invalid' );
			$this->fail( 'Expected an unknown projected required property to be rejected.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertStringContainsString( 'requires unknown mcp property', $exception->getMessage() );
		}

		$definition = $this->automatic_definition( 'invalid_override' );
		$definition['projections']['mcp']['property_overrides'] = array(
			'missing' => array( 'type' => 'number' ),
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'overrides unknown mcp property' );
		$catalog->register( $definition, static fn(): string => 'invalid' );
	}

	public function test_chat_approval_projection_requires_complete_proposal_metadata(): void {
		$catalog    = new Haydi_Tool_Catalog();
		$definition = array(
			'name'         => 'incomplete_approval',
			'description'  => 'Require approval.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'effect'       => 'approval',
			'proposal'     => array(
				'label' => 'Incomplete',
			),
			'projections'  => array( 'chat' => true ),
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'requires proposal log_action' );
		$catalog->register( $definition, static fn(): string => 'invalid' );
	}

	public function test_legacy_mcp_declarations_are_additive_and_cannot_replace_catalog_tools(): void {
		$catalog = new Haydi_Tool_Catalog();
		$catalog->register( $this->automatic_definition( 'catalog_tool' ), static fn(): string => 'catalog result' );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value, ...$args ) {
				if ( 'haydi_mcp_tools' === $hook ) {
					return array(
						array(
							'name'        => 'haydi_catalog_tool',
							'description' => 'Attempted replacement.',
							'inputSchema' => array( 'type' => 'object', 'properties' => array() ),
						),
						array(
							'name'        => 'legacy_extra',
							'description' => 'A genuinely additive Tool.',
							'inputSchema' => array( 'type' => 'object', 'properties' => array() ),
						),
					);
				}
				if ( 'haydi_mcp_execute_tool' === $hook && 'legacy_extra' === ( $args[0] ?? '' ) ) {
					return 'legacy result';
				}
				return $value;
			}
		);

		$declarations = $catalog->declarations( Haydi_Tool_Catalog::MCP );
		$catalog_call = $catalog->dispatch( Haydi_Tool_Catalog::MCP, 'haydi_catalog_tool', array( 'value' => 'x' ) );
		$legacy_call  = $catalog->dispatch( Haydi_Tool_Catalog::MCP, 'legacy_extra', array() );

		$this->assertSame( array( 'haydi_catalog_tool', 'legacy_extra' ), array_column( $declarations, 'name' ) );
		$this->assertSame( 'Return the supplied value.', $declarations[0]['description'] );
		$this->assertSame( 'catalog result', $catalog_call['result'] );
		$this->assertSame( 'legacy result', $legacy_call['result'] );
	}

	public function test_unknown_tool_returns_wp_error(): void {
		$catalog = new Haydi_Tool_Catalog();
		$outcome = $catalog->dispatch( Haydi_Tool_Catalog::CHAT, 'missing_tool', array() );

		$this->assertInstanceOf( WP_Error::class, $outcome );
		$this->assertSame( 'unknown_tool', $outcome->get_error_code() );
	}

	public function test_implementation_exception_is_logged_without_leaking_to_the_caller(): void {
		$logger = $this->createMock( Haydi_Audit_Logger::class );
		$logger->expects( $this->once() )
			->method( 'log' )
			->with(
				'tool_execution_failed',
				'failing_tool',
				$this->stringContains( 'secret-path' )
			);
		$catalog = new Haydi_Tool_Catalog( $logger );
		$catalog->register(
			$this->automatic_definition( 'failing_tool' ),
			static function (): never {
				throw new RuntimeException( 'Failure at /secret-path with API_KEY=hidden.' );
			}
		);

		$outcome = $catalog->dispatch(
			Haydi_Tool_Catalog::CHAT,
			'failing_tool',
			array( 'value' => 'ignored' )
		);

		$this->assertInstanceOf( WP_Error::class, $outcome );
		$this->assertSame( 'tool_execution_failed', $outcome->get_error_code() );
		$this->assertSame( 'Tool execution failed.', $outcome->get_error_message() );
		$this->assertStringNotContainsString( 'secret', $outcome->get_error_message() );
	}

	public function test_unavailable_tool_is_not_declared_or_executed(): void {
		$execution_count          = 0;
		$catalog                  = new Haydi_Tool_Catalog();
		$definition               = $this->automatic_definition( 'temporarily_disabled' );
		$definition['available']  = static fn(): bool => false;
		$catalog->register(
			$definition,
			static function () use ( &$execution_count ): string {
				++$execution_count;
				return 'should not run';
			}
		);

		$this->assertSame( array(), $catalog->declarations( Haydi_Tool_Catalog::CHAT ) );

		$outcome = $catalog->dispatch( Haydi_Tool_Catalog::CHAT, 'temporarily_disabled', array() );

		$this->assertSame( 0, $execution_count );
		$this->assertInstanceOf( WP_Error::class, $outcome );
		$this->assertSame( 'tool_unavailable', $outcome->get_error_code() );
	}

	public function test_legacy_action_proposal_takes_precedence_over_read_filter(): void {
		haydi_register_action_proposal(
			'legacy_delete',
			array(
				'label'          => 'Legacy Delete',
				'fields'         => array( 'path', 'reason' ),
				'ajax_action'    => 'legacy_delete',
				'log_action'     => 'legacy_delete_proposed',
				'log_path_field' => 'path',
			)
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'haydi_execute_read_tool' === $hook ? 'unsafe override' : $value;
			}
		);

		$catalog = new Haydi_Tool_Catalog();
		$outcome = $catalog->dispatch(
			Haydi_Tool_Catalog::CHAT,
			'legacy_delete',
			array( 'path' => '/tmp/example.php', 'reason' => 'Remove it.' )
		);

		$this->assertSame( 'action_proposal', $outcome['kind'] );
		$this->assertTrue( $outcome['payload']['legacy_adapter'] );
		$this->assertSame( '/tmp/example.php', $outcome['payload']['path'] );
	}

	public function test_legacy_action_proposal_can_gate_a_catalog_automatic_tool(): void {
		$execution_count = 0;
		$catalog         = new Haydi_Tool_Catalog();
		$catalog->register(
			$this->automatic_definition( 'fetch_value' ),
			static function () use ( &$execution_count ): string {
				++$execution_count;
				return 'unsafe execution';
			}
		);
		haydi_register_action_proposal(
			'fetch_value',
			array(
				'label'          => 'Fetch Value',
				'fields'         => array( 'value' ),
				'ajax_action'    => 'haydi_fetch_value',
				'log_action'     => 'fetch_value_proposed',
				'log_path_field' => '',
			)
		);

		$outcome = $catalog->dispatch(
			Haydi_Tool_Catalog::CHAT,
			'fetch_value',
			array( 'value' => 'hello' )
		);

		$this->assertSame( 0, $execution_count, 'A legacy approval policy must prevent automatic execution.' );
		$this->assertSame( 'action_proposal', $outcome['kind'] );
		$this->assertSame( 'hello', $outcome['payload']['value'] );
	}

	public function test_legacy_read_override_is_final_and_preserves_native_result(): void {
		$catalog    = new Haydi_Tool_Catalog();
		$definition = $this->automatic_definition( 'overridden_tool' );
		$definition['presenters'] = array(
			'chat' => static fn( array $result ): string => $result['value'],
		);
		$catalog->register( $definition, static fn(): array => array( 'value' => 'implementation' ) );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'haydi_execute_read_tool' === $hook ? false : $value;
			}
		);

		$outcome = $catalog->dispatch(
			Haydi_Tool_Catalog::CHAT,
			'overridden_tool',
			array( 'value' => 'ignored' )
		);

		$this->assertSame( 'result', $outcome['kind'] );
		$this->assertFalse( $outcome['result'] );
	}

	public function test_failed_registration_does_not_leave_partial_aliases(): void {
		$catalog = new Haydi_Tool_Catalog();
		$catalog->register( $this->automatic_definition( 'first', 'shared_alias' ), static fn(): string => 'first' );

		try {
			$catalog->register( $this->automatic_definition( 'second', 'shared_alias' ), static fn(): string => 'second' );
			$this->fail( 'Expected the duplicate MCP alias to be rejected.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertStringContainsString( 'Duplicate Haydi mcp tool name', $exception->getMessage() );
		}

		$missing = $catalog->dispatch( Haydi_Tool_Catalog::CHAT, 'second', array( 'value' => 'x' ) );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 'unknown_tool', $missing->get_error_code() );

		$catalog->register( $this->automatic_definition( 'second', 'unique_alias' ), static fn(): string => 'second' );
		$outcome = $catalog->dispatch( Haydi_Tool_Catalog::CHAT, 'second', array( 'value' => 'x' ) );
		$this->assertSame( 'result', $outcome['kind'] );
	}

	private function automatic_definition( string $name, ?string $mcp_name = null ): array {
		return array(
			'name'         => $name,
			'description'  => 'Return the supplied value.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'value' => array( 'type' => 'string', 'description' => 'Value to return.' ),
				),
				'required'   => array( 'value' ),
			),
			'effect'       => 'automatic',
			'projections'  => array(
				'chat' => true,
				'mcp'  => array( 'name' => $mcp_name ?? 'haydi_' . $name ),
			),
		);
	}
}
