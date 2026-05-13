<?php
/**
 * Unit tests for Haydi_Filesystem_Guard::restore_latest_backup().
 *
 * Uses real filesystem operations so glob(), copy(), and unlink() behave
 * authentically without mocking.  Backup files are placed under the same
 * WP_CONTENT_DIR path the method reads, and cleaned up after the suite.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class FilesystemGuardRestoreBackupTest extends TestCase {

	private \Haydi_Filesystem_Guard $guard;

	/** Directory that acts as the "live" filesystem root for the file under test. */
	private static string $root;

	/** The backup directory the method writes into (mirrors WP_CONTENT_DIR layout). */
	private static string $backupDir;

	public static function setUpBeforeClass(): void {
		self::$root      = sys_get_temp_dir() . '/haydi-restore-test-' . bin2hex( random_bytes( 4 ) );
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

		// wp_delete_file() is a WP wrapper around unlink(); alias it to the real thing
		// so restore_latest_backup() can delete new files without a live WordPress.
		Functions\when( 'wp_delete_file' )->alias( 'unlink' );

		// Instantiate without constructor so build_allowed_roots() is not invoked;
		// restore_latest_backup() does not use allowed_roots at all.
		$ref         = new \ReflectionClass( Haydi_Filesystem_Guard::class );
		$this->guard = $ref->newInstanceWithoutConstructor();

		// Clean up any leftover backup files from previous tests.
		foreach ( glob( self::$backupDir . '/*.bak' ) ?: [] as $f ) {
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

	/** Create a backup stub in the backup directory. */
	private function createBackup( string $basename, string $timestamp, string $content ): string {
		$path = self::$backupDir . '/' . $basename . '.' . $timestamp . '.bak';
		file_put_contents( $path, $content );
		return $path;
	}

	// -----------------------------------------------------------------------
	// Restore from backup
	// -----------------------------------------------------------------------

	public function test_restores_content_from_single_backup(): void {
		$file = self::$root . '/functions.php';
		file_put_contents( $file, 'broken content' );

		$this->createBackup( 'functions.php', '2024-01-01_12-00-00', '<?php // original' );

		$result = $this->guard->restore_latest_backup( $file );

		$this->assertTrue( $result );
		$this->assertSame( '<?php // original', file_get_contents( $file ) );
	}

	public function test_restores_most_recent_when_multiple_backups_exist(): void {
		$file = self::$root . '/style.php';
		file_put_contents( $file, 'broken' );

		$this->createBackup( 'style.php', '2024-01-01_10-00-00', 'older backup' );
		$this->createBackup( 'style.php', '2024-01-01_12-00-00', 'newest backup' );
		$this->createBackup( 'style.php', '2024-01-01_11-00-00', 'middle backup' );

		$this->guard->restore_latest_backup( $file );

		$this->assertSame( 'newest backup', file_get_contents( $file ) );
	}

	public function test_returns_true_on_successful_restore(): void {
		$file = self::$root . '/plugin.php';
		file_put_contents( $file, 'current' );
		$this->createBackup( 'plugin.php', '2024-06-01_09-00-00', 'backed up' );

		$result = $this->guard->restore_latest_backup( $file );

		$this->assertTrue( $result );
	}

	// -----------------------------------------------------------------------
	// No backup — new-file rollback
	// -----------------------------------------------------------------------

	public function test_deletes_file_when_no_backup_exists(): void {
		$file = self::$root . '/new-file.php';
		file_put_contents( $file, '<?php // newly written' );

		$result = $this->guard->restore_latest_backup( $file );

		$this->assertTrue( $result );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_returns_true_when_no_backup_and_file_already_absent(): void {
		$file = self::$root . '/already-gone.php';
		// File does not exist on disk; no backups either.

		$result = $this->guard->restore_latest_backup( $file );

		$this->assertTrue( $result );
	}

	// -----------------------------------------------------------------------
	// Backup scoping — only matches the correct basename
	// -----------------------------------------------------------------------

	public function test_does_not_use_backup_belonging_to_different_file(): void {
		$file = self::$root . '/target.php';
		file_put_contents( $file, 'current target content' );

		// Backup exists only for a different file.
		$this->createBackup( 'other.php', '2024-01-01_00-00-00', 'other file backup' );

		$result = $this->guard->restore_latest_backup( $file );

		// No matching backup → treated as new file → deleted.
		$this->assertTrue( $result );
		$this->assertFileDoesNotExist( $file );
	}
}
