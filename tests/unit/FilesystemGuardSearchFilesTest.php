<?php
/**
 * Unit tests for Haydi_Filesystem_Guard::search_files().
 *
 * The scanner is pure PHP so it can run on shared hosting without shell grep.
 */

use PHPUnit\Framework\TestCase;

class FilesystemGuardSearchFilesTest extends TestCase {

	private \Haydi_Filesystem_Guard $guard;
	private static string $root;

	public static function setUpBeforeClass(): void {
		self::$root = sys_get_temp_dir() . '/haydi-search-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( self::$root, 0755, true );
	}

	public static function tearDownAfterClass(): void {
		if ( is_dir( self::$root ) ) {
			foreach ( new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( self::$root, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			) as $file ) {
				$file->isDir() ? rmdir( $file->getRealPath() ) : unlink( $file->getRealPath() );
			}
			rmdir( self::$root );
		}
	}

	protected function setUp(): void {
		$this->emptyRoot();

		$ref         = new \ReflectionClass( Haydi_Filesystem_Guard::class );
		$this->guard = $ref->newInstanceWithoutConstructor();

		$prop = $ref->getProperty( 'allowed_roots' );
		$prop->setValue( $this->guard, array( 'test' => realpath( self::$root ) ) );
	}

	private function emptyRoot(): void {
		if ( ! is_dir( self::$root ) ) {
			mkdir( self::$root, 0755, true );
			return;
		}

		foreach ( new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( self::$root, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		) as $file ) {
			$file->isDir() ? rmdir( $file->getRealPath() ) : unlink( $file->getRealPath() );
		}
	}

	private function writeFixture( string $relative, string $content ): string {
		$path = self::$root . '/' . ltrim( $relative, '/' );
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		file_put_contents( $path, $content );
		return $path;
	}

	public function test_literal_search_finds_case_insensitive_matches_with_line_numbers(): void {
		$file = $this->writeFixture(
			'plugin/includes/class-demo.php',
			"<?php\nadd_action( 'init', 'demo_boot' );\n"
		);

		$result = $this->guard->search_files( 'ADD_ACTION', self::$root, 'literal', 'php', 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( realpath( $file ), $result['matches'][0]['path'] );
		$this->assertSame( 2, $result['matches'][0]['line'] );
		$this->assertStringContainsString( 'add_action', $result['matches'][0]['snippet'] );
		$this->assertFalse( $result['truncated'] );
	}

	public function test_search_defaults_to_all_allowed_extensions_and_roots(): void {
		$this->writeFixture( 'one.php', "<?php\nregister_shortcode();\n" );
		$this->writeFixture( 'two.js', "const marker = 'register_shortcode';\n" );
		$this->writeFixture( 'three.png', 'register_shortcode' );

		$result = $this->guard->search_files( 'register_shortcode' );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( 2, $result['scanned_files'] );
	}

	public function test_search_respects_extension_filter(): void {
		$this->writeFixture( 'one.php', "<?php\nneedle();\n" );
		$this->writeFixture( 'two.js', "needle();\n" );

		$result = $this->guard->search_files( 'needle', self::$root, 'literal', 'js', 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertStringEndsWith( 'two.js', $result['matches'][0]['path'] );
	}

	public function test_regex_search_accepts_raw_regex_body(): void {
		$this->writeFixture( 'hooks.php', "<?php\nadd_filter( 'the_content', 'demo_filter' );\n" );

		$result = $this->guard->search_files( 'add_(action|filter)', self::$root, 'regex', 'php', 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'regex', $result['mode'] );
	}

	public function test_search_caps_results_and_marks_truncated(): void {
		$this->writeFixture( 'many.php', "<?php\nmatch_me();\nmatch_me();\nmatch_me();\n" );

		$result = $this->guard->search_files( 'match_me', self::$root, 'literal', 'php', 2 );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['count'] );
		$this->assertTrue( $result['truncated'] );
	}

	public function test_search_skips_generated_heavy_directories(): void {
		$this->writeFixture( 'vendor/package/ignored.php', "<?php\nhidden_marker();\n" );
		$this->writeFixture( 'src/found.php', "<?php\nhidden_marker();\n" );

		$result = $this->guard->search_files( 'hidden_marker', self::$root, 'literal', 'php', 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertStringEndsWith( 'src/found.php', $result['matches'][0]['path'] );
	}

	public function test_search_rejects_empty_query(): void {
		$result = $this->guard->search_files( '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'empty_query', $result->get_error_code() );
	}

	public function test_search_rejects_disallowed_extension_filter(): void {
		$result = $this->guard->search_files( 'needle', self::$root, 'literal', 'sh', 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_extension', $result->get_error_code() );
	}

	public function test_search_rejects_path_outside_allowed_roots(): void {
		$outside = sys_get_temp_dir() . '/haydi-outside-search.php';
		file_put_contents( $outside, "<?php\nneedle();\n" );

		try {
			$result = $this->guard->search_files( 'needle', $outside, 'literal', 'php', 10 );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'path_not_allowed', $result->get_error_code() );
		} finally {
			unlink( $outside );
		}
	}
}
