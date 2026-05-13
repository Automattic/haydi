<?php
/**
 * Unit tests for Haydi_Model_Limits.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class ModelLimitsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_fetches_limits_from_models_dev(): void {
		$registry = new Haydi_Test_Model_Limits_Registry(
			array( 'anthropic' ),
			array( 'anthropic' => true ),
			array()
		);

		$set_transient_calls = array();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $ttl ) use ( &$set_transient_calls ) {
				$set_transient_calls[] = compact( 'key', 'value', 'ttl' );
				return true;
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) {
				TestCase::assertSame( Haydi_Model_Limits::MODELS_DEV_URL, $url );
				return array( 'body' => 'ok' );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				array(
					'anthropic' => array(
						'id'     => 'anthropic',
						'name'   => 'Anthropic',
						'models' => array(
							'claude-sonnet-4-6' => array(
								'id'    => 'claude-sonnet-4-6',
								'name'  => 'Claude Sonnet 4.6',
								'limit' => array(
									'context' => 1000000,
									'output'  => 128000,
								),
							),
						),
					),
				)
			)
		);

		$limits = ( new Haydi_Model_Limits( $registry ) )->get_limits_for_configured_providers();

		$this->assertSame( 1000000, $limits['anthropic']['models']['claude-sonnet-4-6']['max_input_tokens'] );
		$this->assertSame( 128000, $limits['anthropic']['models']['claude-sonnet-4-6']['max_tokens'] );
		$this->assertSame( 'Claude Sonnet 4.6', $limits['anthropic']['models']['claude-sonnet-4-6']['name'] );

		// Two transient writes: raw models.dev data + per-provider processed limits.
		$this->assertCount( 2, $set_transient_calls );
		$keys = array_column( $set_transient_calls, 'key' );
		$this->assertContains( 'haydi_models_dev_raw', $keys );
		$this->assertContains( 'haydi_model_limits_anthropic', $keys );
		foreach ( $set_transient_calls as $call ) {
			$this->assertSame( Haydi_Model_Limits::CACHE_TTL, $call['ttl'] );
		}
	}

	public function test_uses_cached_limits_without_fetching_remote_api(): void {
		$cached = array(
			'provider' => 'anthropic',
			'models'   => array(
				'claude-opus-4-7' => array(
					'id'               => 'claude-opus-4-7',
					'name'             => 'Claude Opus 4.7',
					'max_input_tokens' => 1000000,
					'max_tokens'       => 128000,
				),
			),
			'fetched'  => 123,
		);
		$registry = new Haydi_Test_Model_Limits_Registry(
			array( 'anthropic' ),
			array( 'anthropic' => true ),
			array()
		);

		Functions\when( 'get_transient' )->justReturn( $cached );

		$limits = ( new Haydi_Model_Limits( $registry ) )->get_limits_for_configured_providers();

		$this->assertSame( $cached, $limits['anthropic'] );
	}

	public function test_skips_registered_but_unconfigured_providers(): void {
		$registry = new Haydi_Test_Model_Limits_Registry(
			array( 'anthropic', 'openai' ),
			array(
				'anthropic' => false,
				'openai'    => false,
			),
			array()
		);

		$limits = ( new Haydi_Model_Limits( $registry ) )->get_limits_for_configured_providers();

		$this->assertSame( array(), $limits );
	}

	public function test_provider_filter_can_supply_limits_for_future_connectors(): void {
		$registry = new Haydi_Test_Model_Limits_Registry(
			array( 'custom' ),
			array( 'custom' => true ),
			array()
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$args ) {
				if ( 'haydi_model_limits_for_provider' === $hook && 'custom' === $args[0] ) {
					return array(
						'provider' => 'custom',
						'models'   => array(
							'custom-large' => array(
								'id'               => 'custom-large',
								'name'             => 'Custom Large',
								'max_input_tokens' => 250000,
								'max_tokens'       => 16000,
							),
						),
					);
				}
				return $value;
			}
		);

		$limits = ( new Haydi_Model_Limits( $registry ) )->get_limits_for_configured_providers();

		$this->assertSame( 250000, $limits['custom']['models']['custom-large']['max_input_tokens'] );
	}

	public function test_model_choices_hide_default_legacy_models(): void {
		$registry = new Haydi_Test_Model_Limits_Registry(
			array( 'openai', 'anthropic' ),
			array(
				'openai'    => true,
				'anthropic' => true,
			),
			array(),
			array(
				'openai'    => Haydi_Test_OpenAI_Provider::class,
				'anthropic' => Haydi_Test_Anthropic_Provider::class,
			)
		);

		$choices = ( new Haydi_Model_Limits( $registry ) )->get_model_choices_for_configured_providers();

		$this->assertSame(
			array( 'gpt-5.2', 'gpt-4.1' ),
			array_column( $choices['openai']['models'], 'id' )
		);
		$this->assertSame(
			array( 'claude-sonnet-4-6' ),
			array_column( $choices['anthropic']['models'], 'id' )
		);
	}

	public function test_legacy_model_patterns_are_filterable(): void {
		$registry = new Haydi_Test_Model_Limits_Registry(
			array( 'openai' ),
			array( 'openai' => true ),
			array(),
			array( 'openai' => Haydi_Test_OpenAI_Provider::class )
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'haydi_legacy_model_patterns' === $hook ) {
					return array(
						'openai' => array( 'gpt-4.1' ),
					);
				}
				return $value;
			}
		);

		$choices = ( new Haydi_Model_Limits( $registry ) )->get_model_choices_for_configured_providers();

		$this->assertSame(
			array( 'gpt-5.2', 'gpt-4o', 'chatgpt-4o', 'o1-preview', 'gpt-3.5-turbo' ),
			array_column( $choices['openai']['models'], 'id' )
		);
	}
}

class Haydi_Test_Model_Limits_Registry extends WordPress\AiClient\Providers\ProviderRegistry {
	public function __construct(
		private array $provider_ids,
		private array $configured,
		private array $api_keys,
		private array $provider_classes = array()
	) {}

	public function getRegisteredProviderIds(): array {
		return $this->provider_ids;
	}

	public function isProviderConfigured( string $provider_id ): bool {
		return (bool) ( $this->configured[ $provider_id ] ?? false );
	}

	public function getProviderRequestAuthentication( string $provider_id ): object {
		return new Haydi_Test_Model_Limits_Auth( $this->api_keys[ $provider_id ] ?? '' );
	}

	public function getProviderClassName( string $provider_id ): string {
		return (string) ( $this->provider_classes[ $provider_id ] ?? '' );
	}
}

class Haydi_Test_Model_Limits_Auth {
	public function __construct( private string $api_key ) {}

	public function getApiKey(): string {
		return $this->api_key;
	}
}

class Haydi_Test_OpenAI_Provider {
	public static function metadata(): object {
		return new Haydi_Test_Provider_Metadata( 'OpenAI' );
	}

	public static function modelMetadataDirectory(): object {
		return new Haydi_Test_Model_Metadata_Directory(
			array(
				new Haydi_Test_Model_Metadata( 'gpt-5.2', 'GPT-5.2' ),
				new Haydi_Test_Model_Metadata( 'gpt-4.1', 'GPT-4.1' ),
				new Haydi_Test_Model_Metadata( 'gpt-4o', 'GPT-4o' ),
				new Haydi_Test_Model_Metadata( 'chatgpt-4o', 'ChatGPT-4o' ),
				new Haydi_Test_Model_Metadata( 'o1-preview', 'o1 Preview' ),
				new Haydi_Test_Model_Metadata( 'gpt-3.5-turbo', 'GPT-3.5 Turbo' ),
			)
		);
	}
}

class Haydi_Test_Anthropic_Provider {
	public static function metadata(): object {
		return new Haydi_Test_Provider_Metadata( 'Anthropic' );
	}

	public static function modelMetadataDirectory(): object {
		return new Haydi_Test_Model_Metadata_Directory(
			array(
				new Haydi_Test_Model_Metadata( 'claude-sonnet-4-6', 'Claude Sonnet 4.6' ),
				new Haydi_Test_Model_Metadata( 'claude-3-5-haiku-latest', 'Claude 3.5 Haiku' ),
				new Haydi_Test_Model_Metadata( 'claude-3-opus-20240229', 'Claude 3 Opus' ),
			)
		);
	}
}

class Haydi_Test_Provider_Metadata {
	public function __construct( private string $name ) {}

	public function getName(): string {
		return $this->name;
	}
}

class Haydi_Test_Model_Metadata_Directory {
	public function __construct( private array $models ) {}

	public function listModelMetadata(): array {
		return $this->models;
	}
}

class Haydi_Test_Model_Metadata {
	public function __construct(
		private string $id,
		private string $name,
		private array $capabilities = array( 'text_generation' )
	) {}

	public function getId(): string {
		return $this->id;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getSupportedCapabilities(): array {
		return $this->capabilities;
	}
}
