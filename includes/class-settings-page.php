<?php
/**
 * Native WordPress settings page for administrator-owned Haydi policy.
 */

defined( 'ABSPATH' ) || exit;

final class Haydi_Settings_Page {

	const PAGE_SLUG     = 'haydi-settings';
	const OPTION_GROUP  = 'haydi_settings';
	const MODEL_SECTION = 'haydi_model_access';

	/** @var Haydi_Model_Limits Configured provider/model discovery service. */
	private Haydi_Model_Limits $model_service;

	public function __construct( ?Haydi_Model_Limits $model_service = null ) {
		$this->model_service = $model_service ?? new Haydi_Model_Limits();

		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the administrator-only page under WordPress's Settings menu.
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'Haydi Settings', 'haydi' ),
			__( 'Haydi', 'haydi' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings, sections, and fields through the WordPress Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Haydi_Model_Policy::OPTION_NAME,
			array(
				'type'              => 'string',
				'label'             => __( 'Chat model', 'haydi' ),
				'description'       => __( 'Optionally pin the Haydi chat interface to one model.', 'haydi' ),
				'sanitize_callback' => array( $this, 'sanitize_editor_model' ),
				'default'           => '',
			)
		);

		add_settings_section(
			self::MODEL_SECTION,
			__( 'Model access', 'haydi' ),
			array( $this, 'render_model_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			Haydi_Model_Policy::OPTION_NAME,
			__( 'Chat model', 'haydi' ),
			array( $this, 'render_editor_model_field' ),
			self::PAGE_SLUG,
			self::MODEL_SECTION
		);
	}

	/**
	 * Accept an unrestricted picker or one currently configured provider/model.
	 */
	public function sanitize_editor_model( mixed $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		$model = Haydi_Model_Policy::decode( $value );
		if ( null !== $model ) {
			$choices = $this->model_service->get_model_choices_for_configured_providers();
			if ( null !== Haydi_Model_Policy::find_choice( $choices, $model[0], $model[1] ) ) {
				return Haydi_Model_Policy::encode( $model[0], $model[1] );
			}
		}

		add_settings_error(
			Haydi_Model_Policy::OPTION_NAME,
			'haydi_editor_model_invalid',
			__( 'Choose a model from a currently configured AI provider.', 'haydi' )
		);

		return (string) get_option( Haydi_Model_Policy::OPTION_NAME, '' );
	}

	/**
	 * Explain the scope of the picker policy.
	 */
	public function render_model_section(): void {
		echo '<p>';
		esc_html_e( 'Choose whether Haydi users may select any configured model, or always use one pinned model — useful to keep chat on a model whose cost and behavior you have vetted.', 'haydi' );
		echo '</p>';
	}

	/**
	 * Render the configured providers and models as one native select field.
	 */
	public function render_editor_model_field(): void {
		$current = (string) get_option( Haydi_Model_Policy::OPTION_NAME, '' );
		$choices = $this->model_service->get_model_choices_for_configured_providers();
		$stored  = Haydi_Model_Policy::decode( $current );
		$found   = null !== $stored
			? Haydi_Model_Policy::find_choice( $choices, $stored[0], $stored[1] )
			: null;
		?>
		<select name="<?php echo esc_attr( Haydi_Model_Policy::OPTION_NAME ); ?>" id="<?php echo esc_attr( Haydi_Model_Policy::OPTION_NAME ); ?>">
			<option value=""<?php selected( '', $current ); ?>><?php esc_html_e( 'Allow any configured model', 'haydi' ); ?></option>
			<?php if ( null !== $stored && null === $found ) : ?>
				<option value="<?php echo esc_attr( $current ); ?>" selected>
					<?php /* translators: 1: AI provider ID, 2: AI model ID. */ ?>
					<?php echo esc_html( sprintf( __( 'Unavailable: %1$s / %2$s', 'haydi' ), $stored[0], $stored[1] ) ); ?>
				</option>
			<?php endif; ?>
			<?php foreach ( $choices as $provider_id => $provider ) : ?>
				<?php if ( empty( $provider['models'] ) || ! is_array( $provider['models'] ) ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<optgroup label="<?php echo esc_attr( (string) ( $provider['name'] ?? $provider_id ) ); ?>">
					<?php foreach ( $provider['models'] as $model ) : ?>
						<?php
						if ( ! is_array( $model ) || empty( $model['id'] ) ) {
							continue;
						}
						$value = Haydi_Model_Policy::encode( (string) $provider_id, (string) $model['id'] );
						?>
						<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $value, $current ); ?>>
							<?php echo esc_html( (string) ( $model['name'] ?? $model['id'] ) ); ?>
						</option>
					<?php endforeach; ?>
				</optgroup>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Administrators keep the full model picker.', 'haydi' ); ?>
		</p>
		<?php if ( empty( $choices ) ) : ?>
			<p class="description">
				<?php esc_html_e( 'Configure an AI provider under Settings → Connectors before selecting a model.', 'haydi' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the native Settings API form.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'haydi' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Haydi Settings', 'haydi' ); ?></h1>
			<?php settings_errors(); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
