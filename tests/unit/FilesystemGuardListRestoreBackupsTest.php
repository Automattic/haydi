<?php
/**
 * Unit tests for Haydi_Filesystem_Guard::list_backups() and
 * Haydi_Filesystem_Guard::restore_specific_backup().
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class FilesystemGuardListRestoreBackupsTest extends TestCase {

	private \Haydi_Filesystem_Guard $guard;

	private static string $root;
	private static string $backupDir;

	public static function setUpBeforeClass(): void {
		self::$root      = sys_get_temp_dir() . '/haydi-list-restore-test-' . bin2hex( random_bytes( 4 ) );
		self::$backupDir = WP_CONTENT_DIR . '/uploads/haydi-backups';

		mkdir( self::$root, 0755, true );
		mkdir( self::$backupDir, 0755, true );
	}

	public static function tearDownAfterClass(): void {
		foreach ( [ self::$root, self::$backupDir ] as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			) as $file ) {
				$file->isDir() ? rmdir( $file->getRealPath() ) : unlink( $file->getRealPath() );
			}
			rmdir( $dir );
		}
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_delete_file' )->alias( 'unlink' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$ref         = new \ReflectionClass( Haydi_Filesystem_Guard::class );
		$this->guard = $ref->newInstanceWithoutConstructor();

		// Point allowed_roots at the temp root so validate_path works.
		// Use realpath() to resolve symlinks (e.g. /tmp → /private/tmp on macOS).
		$prop = $ref->getProperty( 'allowed_roots' );
		$prop->setValue( $this->guard, [ 'test' => realpath( self::$root ) ] );

		foreach ( glob( self::$backupDir . '/*.bak' ) ?: [] as $f ) {
			unlink( $f );
		}
		foreach ( glob( self::$backupDir . '/*.json' ) ?: [] as $f ) {
			unlink( $f );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Helper
	// -----------------------------------------------------------------------

	private function createBackup( string $basename, string $timestamp, string $token, string $content, string $original_path = '' ): string {
		$filename = $basename . '.' . $timestamp . '.' . $token . '.bak';
		$path     = self::$backupDir . '/' . $filename;
		file_put_contents( $path, $content );
		if ( $original_path ) {
			file_put_contents( $path . '.json', json_encode( [ 'original_path' => $original_path ] ) );
		}
		return $filename;
	}

	// -----------------------------------------------------------------------
	// list_backups — empty state
	// -----------------------------------------------------------------------

	public function test_list_backups_returns_empty_when_no_backups(): void {
		$result = $this->guard->list_backups();
		$this->assertSame( [], $result );
	}

	// -----------------------------------------------------------------------
	// list_backups — entries
	// -----------------------------------------------------------------------

	public function test_list_backups_returns_entry_with_correct_fields(): void {
		$this->createBackup( 'functions.php', '2026-05-01_10-00-00', 'abc123def456789a', 'content', '/path/to/functions.php' );

		$result = $this->guard->list_backups();

		$this->assertCount( 1, $result );
		$this->assertSame( 'functions.php.2026-05-01_10-00-00.abc123def456789a.bak', $result[0]['backup_file'] );
		$this->assertSame( 'functions.php', $result[0]['original_basename'] );
		$this->assertSame( '/path/to/functions.php', $result[0]['original_path'] );
		$this->assertIsInt( $result[0]['timestamp'] );
	}

	public function test_list_backups_returns_empty_original_path_when_no_sidecar(): void {
		$this->createBackup( 'style.php', '2026-05-01_10-00-00', 'abc123def456789a', 'content' );

		$result = $this->guard->list_backups();

		$this->assertSame( '', $result[0]['original_path'] );
	}

	public function test_list_backups_returns_newest_first(): void {
		$this->createBackup( 'plugin.php', '2026-05-01_10-00-00', 'aaa111bbb222ccc3', 'older' );
		$this->createBackup( 'plugin.php', '2026-05-02_10-00-00', 'ddd444eee555fff6', 'newer' );

		$result = $this->guard->list_backups();

		$this->assertSame( 'plugin.php.2026-05-02_10-00-00.ddd444eee555fff6.bak', $result[0]['backup_file'] );
	}

	public function test_list_backups_filters_by_path_basename(): void {
		$this->createBackup( 'functions.php', '2026-05-01_10-00-00', 'aaa111bbb222ccc3', 'functions content' );
		$this->createBackup( 'style.css', '2026-05-01_10-00-00', 'ddd444eee555fff6', 'style content' );

		$result = $this->guard->list_backups( '/some/path/functions.php' );

		$this->assertCount( 1, $result );
		$this->assertSame( 'functions.php', $result[0]['original_basename'] );
	}

	public function test_list_backups_skips_malformed_filenames(): void {
		// Only one part after removing .bak — not enough to parse.
		file_put_contents( self::$backupDir . '/malformed.bak', 'x' );

		$result = $this->guard->list_backups();

		$this->assertSame( [], $result );
	}

	// -----------------------------------------------------------------------
	// restore_specific_backup
	// -----------------------------------------------------------------------

	public function test_restore_specific_backup_restores_content(): void {
		$file = self::$root . '/target.php';
		file_put_contents( $file, 'broken content' );

		$backup = $this->createBackup( 'target.php', '2026-05-01_10-00-00', 'aaa111bbb222ccc3', '<?php // original' );

		$result = $this->guard->restore_specific_backup( $file, $backup );

		$this->assertTrue( $result );
		$this->assertSame( '<?php // original', file_get_contents( $file ) );
	}

	public function test_restore_specific_backup_creates_pre_restore_backup(): void {
		$file = self::$root . '/pre-backup.php';
		file_put_contents( $file, 'current content' );

		$backup = $this->createBackup( 'pre-backup.php', '2026-05-01_10-00-00', 'aaa111bbb222ccc3', 'restored content' );

		$this->guard->restore_specific_backup( $file, $backup );

		$newBackups = glob( self::$backupDir . '/pre-backup.php.*.bak' ) ?: [];
		// The original backup + at least one new pre-restore backup.
		$this->assertGreaterThanOrEqual( 2, count( $newBackups ) );
	}

	public function test_restore_specific_backup_works_when_target_does_not_exist(): void {
		$file = self::$root . '/new-file.php';
		// Intentionally do NOT create $file — target is absent.

		$backup = $this->createBackup( 'new-file.php', '2026-05-01_10-00-00', 'aaa111bbb222ccc3', '<?php // restored' );

		$result = $this->guard->restore_specific_backup( $file, $backup );

		$this->assertTrue( $result );
		$this->assertSame( '<?php // restored', file_get_contents( $file ) );
		// No pre-restore backup should have been created (file did not exist).
		$preBackups = glob( self::$backupDir . '/new-file.php.*.bak' ) ?: [];
		$this->assertCount( 1, $preBackups ); // Only the original backup, not a pre-restore one.
	}

	public function test_restore_specific_backup_returns_error_when_backup_not_found(): void {
		$file   = self::$root . '/missing.php';
		$result = $this->guard->restore_specific_backup( $file, 'nonexistent.bak' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'backup_not_found', $result->get_error_code() );
	}

	public function test_restore_specific_backup_rejects_path_traversal(): void {
		$backup = $this->createBackup( 'traversal.php', '2026-05-01_10-00-00', 'aaa111bbb222ccc3', 'content' );
		$result = $this->guard->restore_specific_backup( self::$root . '/traversal.php', '../../../etc/passwd' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_restore_specific_backup_rejects_path_outside_allowed_roots(): void {
		$backup = $this->createBackup( 'outside.php', '2026-05-01_10-00-00', 'aaa111bbb222ccc3', 'content' );
		$result = $this->guard->restore_specific_backup( '/tmp/outside.php', $backup );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
