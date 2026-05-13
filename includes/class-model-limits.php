<?php
/**
 * Model limits — discovers provider-specific context/output token limits.
 *
 * Fetches https://models.dev/api.json (no auth required) which covers 100+
 * providers including Anthropic, OpenAI, Google, etc. The raw response is
 * cached once and sliced per provider on demand.
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Providers\ProviderRegistry;

class Haydi_Model_Limits {

	/** Cache model limits long enough to avoid adding latency to every new chat. */
	const CACHE_TTL = 6 * 60 * MINUTE_IN_SECONDS;

	const MODELS_DEV_URL = 'https://models.dev/api.json';

	/**
	 * Models that should not be offered in Haydi's picker by default.
	 *
	 * Keep this conservative: model execution still goes through the WordPress
	 * AI Client, but the UI should avoid old, deprecated, or low-end defaults.
	 * Patterns are shell-style globs matched case-insensitively.
	 */
	const DEFAULT_LEGACY_MODEL_PATTERNS = array(
		'openai'    => array(
			'chatgpt-*',
			'gpt-3.5*',
			'gpt-4',
			'gpt-4-*',
			'gpt-4o*',
			'gpt-4.5*',
			'o1*',
			'o3-mini*',
			'codex-mini-latest',
		),
		'anthropic' => array(
			'claude-3*',
		),
		'google'    => array(
			'gemini-1*',
			'gemini-2.0-flash-lite*',
		),
	);

	/** @var ProviderRegistry|null Optional provider registry override for tests. */
	private ?ProviderRegistry $registry;

	public function __construct( ?ProviderRegistry $registry = null ) {
		$this->registry = $registry;
	}

	/**
	 * Fetch limits for every configured provider.
	 *
	 * @param  bool $force_refresh Skip cached results.
	 * @return array Provider ID => provider/model limits.
	 */
	public function get_limits_for_configured_providers( bool $force_refresh = false ): array {
		$registry = $this->get_registry();
		if ( ! $registry || ! method_exists( $registry, 'getRegisteredProviderIds' ) ) {
			return array();
		}

		$limits = array();
		foreach ( $registry->getRegisteredProviderIds() as $provider_id ) {
			if (
				method_exists( $registry, 'isProviderConfigured' )
				&& ! $registry->isProviderConfigured( $provider_id )
			) {
				continue;
			}

			$provider_limits = $this->get_provider_limits( (string) $provider_id, $force_refresh );
			if ( ! empty( $provider_limits ) ) {
				$limits[ $provider_id ] = $provider_limits;
			}
		}

		/**
		 * Filters discovered AI model limits.
		 *
		 * This is the extension point for future connectors whose model limits
		 * cannot be inferred by Haydi. Providers should return a shape like:
		 * provider_id => [ 'models' => [ model_id => [ 'max_input_tokens' => ... ] ] ].
		 *
		 * @param array                 $limits        Discovered provider/model limits.
		 * @param ProviderRegistry|null $registry      AI Client provider registry.
		 * @param bool                  $force_refresh Whether cached values were skipped.
		 */
		return (array) apply_filters( 'haydi_model_limits', $limits, $registry, $force_refresh );
	}

	/**
	 * Fetch UI-ready model choices for every configured provider.
	 *
	 * @return array Provider ID => provider/model choices.
	 */
	public function get_model_choices_for_configured_providers(): array {
		$registry = $this->get_registry();
		if ( ! $registry || ! method_exists( $registry, 'getRegisteredProviderIds' ) ) {
			return array();
		}

		$choices = array();
		foreach ( $registry->getRegisteredProviderIds() as $provider_id ) {
			if (
				method_exists( $registry, 'isProviderConfigured' )
				&& ! $registry->isProviderConfigured( $provider_id )
			) {
				continue;
			}

			$provider_choice = $this->get_provider_model_choices( (string) $provider_id, $registry );
			if ( ! empty( $provider_choice['models'] ) ) {
				$choices[ $provider_id ] = $provider_choice;
			}
		}

		/**
		 * Filters model choices shown in the Haydi model picker.
		 *
		 * Expected shape:
		 * provider_id => [
		 *   'id' => provider_id,
		 *   'name' => provider display name,
		 *   'models' => [ [ 'id' => model_id, 'name' => model display name ] ],
		 * ].
		 *
		 * @param array                 $choices  Discovered provider/model choices.
		 * @param ProviderRegistry|null $registry AI Client provider registry.
		 */
		return (array) apply_filters( 'haydi_model_choices', $choices, $registry );
	}

	/**
	 * Fetch limits for a single configured provider, with per-provider caching.
	 */
	private function get_provider_limits( string $provider_id, bool $force_refresh ): array {
		$cache_key = 'haydi_model_limits_' . $provider_id;

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$limits = $this->fetch_models_dev_limits( $provider_id );

		/**
		 * Filters model limits for a single provider.
		 *
		 * @param array  $limits        Provider limits.
		 * @param string $provider_id   AI provider ID, e.g. "anthropic".
		 * @param bool   $force_refresh Whether cached values were skipped.
		 */
		$limits = (array) apply_filters(
			'haydi_model_limits_for_provider',
			$limits,
			$provider_id,
			$force_refresh
		);

		if ( ! empty( $limits ) ) {
			set_transient( $cache_key, $limits, self::CACHE_TTL );
		}

		return $limits;
	}

	/**
	 * Slice models.dev data for a single provider and map to our shape.
	 */
	private function fetch_models_dev_limits( string $provider_id ): array {
		$raw = $this->fetch_models_dev_raw();
		if ( empty( $raw[ $provider_id ] ) || ! is_array( $raw[ $provider_id ] ) ) {
			return array();
		}

		$raw_models = isset( $raw[ $provider_id ]['models'] ) && is_array( $raw[ $provider_id ]['models'] )
			? $raw[ $provider_id ]['models']
			: array();

		$models = array();
		foreach ( $raw_models as $model_id => $model ) {
			if ( ! is_array( $model ) ) {
				continue;
			}
			$id            = isset( $model['id'] ) ? (string) $model['id'] : (string) $model_id;
			$name          = isset( $model['name'] ) ? (string) $model['name'] : $id;
			$max_input     = isset( $model['limit']['context'] ) ? (int) $model['limit']['context'] : null;
			$max_output    = isset( $model['limit']['output'] ) ? (int) $model['limit']['output'] : null;
			$models[ $id ] = array(
				'id'               => $id,
				'name'             => $name,
				'max_input_tokens' => $max_input,
				'max_tokens'       => $max_output,
			);
		}

		if ( empty( $models ) ) {
			return array();
		}

		return array(
			'provider' => $provider_id,
			'models'   => $models,
			'fetched'  => time(),
		);
	}

	/**
	 * Fetch and cache the full models.dev JSON. Shared across all providers.
	 */
	private function fetch_models_dev_raw(): array {
		$cache_key = 'haydi_models_dev_raw';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::MODELS_DEV_URL,
			array( 'timeout' => 8 )
		); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return array();
		}

		set_transient( $cache_key, $body, self::CACHE_TTL );
		return $body;
	}

	/**
	 * Build model picker choices for a single configured provider.
	 */
	private function get_provider_model_choices( string $provider_id, ProviderRegistry $registry ): array {
		try {
			if ( ! method_exists( $registry, 'getProviderClassName' ) ) {
				return array();
			}

			$provider_class = $registry->getProviderClassName( $provider_id );
			if ( ! is_string( $provider_class ) || ! class_exists( $provider_class ) ) {
				return array();
			}

			$provider_name = $this->humanize_identifier( $provider_id );
			if ( method_exists( $provider_class, 'metadata' ) ) {
				$provider_metadata = $provider_class::metadata();
				if ( is_object( $provider_metadata ) && method_exists( $provider_metadata, 'getName' ) ) {
					$provider_name = (string) $provider_metadata->getName();
				}
			}

			if ( ! method_exists( $provider_class, 'modelMetadataDirectory' ) ) {
				return array();
			}

			$directory = $provider_class::modelMetadataDirectory();
			if ( ! is_object( $directory ) || ! method_exists( $directory, 'listModelMetadata' ) ) {
				return array();
			}

			$models = array();
			foreach ( $directory->listModelMetadata() as $model_metadata ) {
				if ( ! is_object( $model_metadata ) || ! method_exists( $model_metadata, 'getId' ) ) {
					continue;
				}

				if ( ! $this->model_supports_text_generation( $model_metadata ) ) {
					continue;
				}

				$model_id   = (string) $model_metadata->getId();
				$model_name = method_exists( $model_metadata, 'getName' ) ? (string) $model_metadata->getName() : $model_id;
				if ( $this->is_legacy_model_choice( $provider_id, $model_id, $model_name ) ) {
					continue;
				}

				$models[] = array(
					'id'   => $model_id,
					'name' => '' !== $model_name ? $model_name : $model_id,
				);
			}

			return array(
				'id'     => $provider_id,
				'name'   => $provider_name,
				'models' => $models,
			);
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Determine whether a model is suitable for text chat.
	 */
	private function model_supports_text_generation( object $model_metadata ): bool {
		if ( ! method_exists( $model_metadata, 'getSupportedCapabilities' ) ) {
			return true;
		}

		foreach ( $model_metadata->getSupportedCapabilities() as $capability ) {
			$value = is_object( $capability ) && isset( $capability->value )
				? (string) $capability->value
				: (string) $capability;
			if ( 'text_generation' === $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether a provider model should be hidden from the picker.
	 */
	private function is_legacy_model_choice( string $provider_id, string $model_id, string $model_name ): bool {
		/**
		 * Filters legacy model patterns hidden from the Haydi model picker.
		 *
		 * Shape: provider_id => [ shell-style glob patterns ]. Use "*" as a
		 * global provider key. Match checks both model ID and display name.
		 *
		 * @param array  $patterns    Provider-scoped legacy model patterns.
		 * @param string $provider_id AI provider ID, e.g. "openai".
		 */
		$patterns = (array) apply_filters(
			'haydi_legacy_model_patterns',
			self::DEFAULT_LEGACY_MODEL_PATTERNS,
			$provider_id
		);

		$provider_patterns = array();
		if ( isset( $patterns['*'] ) && is_array( $patterns['*'] ) ) {
			$provider_patterns = array_merge( $provider_patterns, $patterns['*'] );
		}
		if ( isset( $patterns[ $provider_id ] ) && is_array( $patterns[ $provider_id ] ) ) {
			$provider_patterns = array_merge( $provider_patterns, $patterns[ $provider_id ] );
		}

		foreach ( $provider_patterns as $pattern ) {
			if ( ! is_string( $pattern ) || '' === $pattern ) {
				continue;
			}
			if (
				$this->model_glob_matches( $pattern, $model_id )
				|| $this->model_glob_matches( $pattern, $model_name )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Case-insensitive glob match for model IDs/names.
	 */
	private function model_glob_matches( string $pattern, string $value ): bool {
		$regex = '/^' . str_replace( '\\*', '.*', preg_quote( $pattern, '/' ) ) . '$/i';
		return 1 === preg_match( $regex, $value );
	}

	/**
	 * Convert provider/model IDs into a readable fallback label.
	 */
	private function humanize_identifier( string $identifier ): string {
		$label = str_replace( array( '-', '_' ), ' ', $identifier );
		return ucwords( $label );
	}

	/**
	 * Return the WordPress AI Client provider registry when available.
	 */
	private function get_registry(): ?ProviderRegistry {
		if ( null !== $this->registry ) {
			return $this->registry;
		}
		if ( ! class_exists( 'WordPress\AiClient\AiClient' ) ) {
			return null;
		}

		try {
			$this->registry = WordPress\AiClient\AiClient::defaultRegistry();
		} catch ( \Throwable $e ) {
			$this->registry = null;
		}

		return $this->registry;
	}
}
