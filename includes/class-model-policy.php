<?php
/**
 * Model-picker policy configured by site administrators.
 */

defined( 'ABSPATH' ) || exit;

final class Haydi_Model_Policy {

	/** Stored JSON tuple for the model shown to non-administrator users. */
	const OPTION_NAME = 'haydi_editor_model';

	/**
	 * Encode one provider/model pair for storage in a scalar WordPress option.
	 */
	public static function encode( string $provider_id, string $model_id ): string {
		return (string) wp_json_encode(
			array(
				'provider' => $provider_id,
				'model'    => $model_id,
			)
		);
	}

	/**
	 * Decode a stored provider/model pair.
	 *
	 * @return array{0:string,1:string}|null
	 */
	public static function decode( mixed $value ): ?array {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$provider_id = $decoded['provider'] ?? null;
		$model_id    = $decoded['model'] ?? null;
		if (
			! is_string( $provider_id )
			|| ! is_string( $model_id )
			|| '' === trim( $provider_id )
			|| '' === trim( $model_id )
		) {
			return null;
		}

		return array( $provider_id, $model_id );
	}

	/**
	 * Return the model configured for non-administrator users, if any.
	 *
	 * @return array{0:string,1:string}|null
	 */
	public static function get_editor_model(): ?array {
		return self::decode( get_option( self::OPTION_NAME, '' ) );
	}

	/**
	 * Find a provider/model pair in the current UI choices.
	 *
	 * @return array{provider:string,provider_label:string,model:string,label:string}|null
	 */
	public static function find_choice( array $choices, string $provider_id, string $model_id ): ?array {
		$provider = isset( $choices[ $provider_id ] ) && is_array( $choices[ $provider_id ] )
			? $choices[ $provider_id ]
			: null;
		if ( null === $provider || ! is_array( $provider['models'] ?? null ) ) {
			return null;
		}

		foreach ( $provider['models'] as $model ) {
			if ( ! is_array( $model ) || (string) ( $model['id'] ?? '' ) !== $model_id ) {
				continue;
			}

			return array(
				'provider'       => $provider_id,
				'provider_label' => (string) ( $provider['name'] ?? $provider_id ),
				'model'          => $model_id,
				'label'          => (string) ( $model['name'] ?? $model_id ),
			);
		}

		return null;
	}

	/**
	 * Build browser configuration for the current user's model picker.
	 */
	public static function picker_config( array $choices ): array {
		$editor_model = self::get_editor_model();
		$is_admin     = current_user_can( 'manage_options' );
		$restricted   = null !== $editor_model && ! $is_admin;

		$config = array(
			'restricted' => $restricted,
			'available'  => true,
			'provider'   => '',
			'model'      => '',
			'label'      => '',
		);

		if ( null === $editor_model ) {
			return $config;
		}

		$choice              = self::find_choice( $choices, $editor_model[0], $editor_model[1] );
		$config['provider']  = $editor_model[0];
		$config['model']     = $editor_model[1];
		$config['available'] = null !== $choice;
		$config['label']     = null !== $choice ? $choice['label'] : $editor_model[1];

		return $config;
	}
}
