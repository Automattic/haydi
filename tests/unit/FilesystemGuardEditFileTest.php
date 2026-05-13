<?php
/**
 * Unit tests for Haydi_Filesystem_Guard::prepare_edit_file().
 */

use PHPUnit\Framework\TestCase;

class FilesystemGuardEditFileTest extends TestCase {

	private \Haydi_Filesystem_Guard $guard;
	private static string $root;

	public static function setUpBeforeClass(): void {
		self::$root = sys_get_temp_dir() . '/haydi-edit-test-' . bin2hex( random_bytes( 4 ) );
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

	public function test_prepare_edit_replaces_exact_string_once(): void {
		$file = $this->writeFixture( 'demo.php', "<?php\n\$label = 'Old';\n" );

		$result = $this->guard->prepare_edit_file( $file, "\$label = 'Old';", "\$label = 'New';" );

		$this->assertIsArray( $result );
		$this->assertSame( realpath( $file ), $result['path'] );
		$this->assertSame( 1, $result['matches'] );
		$this->assertSame( "<?php\n\$label = 'New';\n", $result['content'] );
		$this->assertSame( "<?php\n\$label = 'Old';\n", file_get_contents( $file ), 'prepare_edit_file must not write.' );
	}

	public function test_prepare_edit_can_delete_by_replacing_with_empty_string(): void {
		$file = $this->writeFixture( 'demo.php', "<?php\nremove_me();\nkeep_me();\n" );

		$result = $this->guard->prepare_edit_file( $file, "remove_me();\n", '' );

		$this->assertIsArray( $result );
		$this->assertSame( "<?php\nkeep_me();\n", $result['content'] );
	}

	public function test_prepare_edit_rejects_missing_old_string(): void {
		$file = $this->writeFixture( 'demo.php', "<?php\nkeep_me();\n" );

		$result = $this->guard->prepare_edit_file( $file, 'missing();', 'new();' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'edit_no_match', $result->get_error_code() );
	}

	public function test_prepare_edit_rejects_ambiguous_match_by_default(): void {
		$file = $this->writeFixture( 'demo.php', "<?php\nsame();\nsame();\n" );

		$result = $this->guard->prepare_edit_file( $file, 'same();', 'changed();' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'edit_ambiguous_match', $result->get_error_code() );
	}

	public function test_prepare_edit_replace_all_updates_every_match(): void {
		$file = $this->writeFixture( 'demo.php', "<?php\nsame();\nsame();\n" );

		$result = $this->guard->prepare_edit_file( $file, 'same();', 'changed();', true );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['matches'] );
		$this->assertSame( "<?php\nchanged();\nchanged();\n", $result['content'] );
	}

	public function test_prepare_edit_rejects_empty_old_string(): void {
		$file = $this->writeFixture( 'demo.php', "<?php\nsame();\n" );

		$result = $this->guard->prepare_edit_file( $file, '', 'changed();' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'empty_old_string', $result->get_error_code() );
	}

	public function test_prepare_edit_rejects_path_outside_allowed_roots(): void {
		$outside = sys_get_temp_dir() . '/haydi-outside-edit.php';
		file_put_contents( $outside, "<?php\nsame();\n" );

		try {
			$result = $this->guard->prepare_edit_file( $outside, 'same();', 'changed();' );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'path_not_allowed', $result->get_error_code() );
		} finally {
			unlink( $outside );
		}
	}
}
