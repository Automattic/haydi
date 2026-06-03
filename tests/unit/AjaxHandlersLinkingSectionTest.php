<?php
/**
 * Unit tests for the file-linking section of the system prompt.
 *
 * The two helper methods build_linking_section() / build_rule_10() are private,
 * so we use reflection to call them directly with explicit cap booleans —
 * avoids needing to stub current_user_can() globally for each combination.
 */

use PHPUnit\Framework\TestCase;

class AjaxHandlersLinkingSectionTest extends TestCase {

	private \Haydi_Ajax_Handlers $handler;
	private \ReflectionMethod $linking;
	private \ReflectionMethod $visibility;
	private \ReflectionMethod $third_party;
	private \ReflectionMethod $approval;
	private \ReflectionMethod $rule10;

	protected function setUp(): void {
		parent::setUp();
		$ref               = new \ReflectionClass( Haydi_Ajax_Handlers::class );
		$this->handler     = $ref->newInstanceWithoutConstructor();
		$this->linking     = $ref->getMethod( 'build_linking_section' );
		$this->visibility  = $ref->getMethod( 'build_plugin_visibility_section' );
		$this->third_party = $ref->getMethod( 'build_third_party_plugin_section' );
		$this->approval    = $ref->getMethod( 'build_approval_workflow_section' );
		$this->rule10      = $ref->getMethod( 'build_rule_10' );
	}

	private function linking( bool $plugins, bool $themes ): string {
		return $this->linking->invoke( $this->handler, $plugins, $themes );
	}

	private function rule10( bool $plugins, bool $themes ): string {
		return $this->rule10->invoke( $this->handler, $plugins, $themes );
	}

	private function visibility(): string {
		return $this->visibility->invoke( $this->handler );
	}

	private function third_party(): string {
		return $this->third_party->invoke( $this->handler );
	}

	private function approval(): string {
		return $this->approval->invoke( $this->handler );
	}

	// -----------------------------------------------------------------------
	// build_linking_section()
	// -----------------------------------------------------------------------

	public function test_always_includes_wpc_view_scheme_documentation(): void {
		// wpc-view: is the universal fallback — present in every variant.
		foreach ( array( array( true, true ), array( true, false ), array( false, true ), array( false, false ) ) as $combo ) {
			$out = $this->linking( $combo[0], $combo[1] );
			$this->assertStringContainsString( 'wpc-view:<absolute-path>', $out, 'wpc-view scheme missing for ' . json_encode( $combo ) );
		}
	}

	public function test_both_editors_enabled_documents_plugin_and_theme_editor(): void {
		$out = $this->linking( true, true );
		$this->assertStringContainsString( '/wp-admin/plugin-editor.php', $out );
		$this->assertStringContainsString( '/wp-admin/theme-editor.php', $out );
		$this->assertStringNotContainsString( 'NOTE:', $out, 'No fallback note expected when both editors are available.' );
	}

	public function test_only_plugins_enabled_omits_theme_editor_and_warns(): void {
		$out = $this->linking( true, false );
		$this->assertStringContainsString( '/wp-admin/plugin-editor.php', $out );
		$this->assertStringNotContainsString( '/wp-admin/theme-editor.php?', $out, 'Theme editor should not be advertised.' );
		$this->assertStringContainsString( 'theme file editor is disabled', $out );
	}

	public function test_only_themes_enabled_omits_plugin_editor_and_warns(): void {
		$out = $this->linking( false, true );
		$this->assertStringContainsString( '/wp-admin/theme-editor.php', $out );
		$this->assertStringNotContainsString( '/wp-admin/plugin-editor.php?', $out, 'Plugin editor should not be advertised.' );
		$this->assertStringContainsString( 'plugin file editor is disabled', $out );
	}

	public function test_both_disabled_warns_and_steers_to_external_editing(): void {
		$out = $this->linking( false, false );
		// No core-editor URLs at all — they would 404 / fail capability checks.
		$this->assertStringNotContainsString( '/wp-admin/plugin-editor.php?', $out );
		$this->assertStringNotContainsString( '/wp-admin/theme-editor.php?', $out );
		$this->assertStringContainsString( 'DISALLOW_FILE_EDIT', $out );
		// User must be told how to actually edit on this host.
		$this->assertStringContainsString( 'SFTP', $out );
	}

	// -----------------------------------------------------------------------
	// build_rule_10()
	// -----------------------------------------------------------------------

	public function test_rule_10_uses_wpc_view_only_when_no_editor_is_available(): void {
		$out = $this->rule10( false, false );
		$this->assertStringContainsString( 'wpc-view:', $out );
		$this->assertStringNotContainsString( 'plugin-editor.php', $out );
		$this->assertStringNotContainsString( 'theme-editor.php', $out );
	}

	public function test_rule_10_mentions_both_options_when_an_editor_is_available(): void {
		foreach ( array( array( true, true ), array( true, false ), array( false, true ) ) as $combo ) {
			$out = $this->rule10( $combo[0], $combo[1] );
			$this->assertStringContainsString( 'plugin-editor.php', $out );
			$this->assertStringContainsString( 'theme-editor.php', $out );
			$this->assertStringContainsString( 'wpc-view:', $out );
		}
	}

	// -----------------------------------------------------------------------
	// build_plugin_visibility_section()
	// -----------------------------------------------------------------------

	public function test_generated_public_plugins_default_to_admin_only_preview(): void {
		$out = $this->visibility();

		$this->assertStringContainsString( 'admin-only preview', $out );
		$this->assertStringContainsString( 'front-end visitors', $out );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $out );
		$this->assertStringContainsString( 'visible to all visitors', $out );
		$this->assertStringContainsString( 'backend tools, safety fixes, or maintenance', $out, 'Skip-cases must remain documented so admin tools are not gated.' );
	}

	// -----------------------------------------------------------------------
	// build_third_party_plugin_section()
	// -----------------------------------------------------------------------

	public function test_third_party_plugin_section_requires_hooks_and_refuses_unsupported_patches(): void {
		$out = $this->third_party();

		// Inspection is allowed; modification is not.
		$this->assertStringContainsString( 'read-only dependencies', $out );
		$this->assertStringContainsString( 'action hooks', $out );
		$this->assertStringContainsString( 'filter hooks', $out );
		$this->assertMatchesRegularExpression( '/never modify|do not (write|modify)/i', $out, 'Must explicitly forbid modifying third-party files.' );

		// Extension-tool workarounds must be blocked.
		$this->assertStringContainsString( 'run_query', $out );
		$this->assertStringContainsString( 'run_php', $out );
		$this->assertStringContainsString( 'private internals', $out );

		// Refusal contract when no supported hook exists.
		$this->assertStringContainsString( 'take no action', $out );
		$this->assertStringContainsString( 'not possible within these limitations', $out );
	}

	// -----------------------------------------------------------------------
	// build_approval_workflow_section()
	// -----------------------------------------------------------------------

	public function test_approval_workflow_tells_models_to_call_tools_not_narrate(): void {
		$out = $this->approval();

		$this->assertStringContainsString( 'approval request, not direct execution', $out );
		$this->assertStringContainsString( 'call the tool', $out );
		$this->assertStringContainsString( 'shall I proceed?', $out );
		$this->assertStringContainsString( 'does nothing and is a failed response', $out );
		$this->assertStringContainsString( 'Use at most one approval tool call', $out );
	}

	public function test_approval_workflow_spells_out_install_then_activate_sequence(): void {
		$out = $this->approval();

		$this->assertStringContainsString( 'call list_plugins before install_plugin or activate_plugin', $out );
		$this->assertStringContainsString( 'install and activate a plugin', $out );
		$this->assertStringContainsString( 'returns a plugin_file', $out );
		$this->assertStringContainsString( 'call activate_plugin with that exact plugin_file', $out );
	}
}
