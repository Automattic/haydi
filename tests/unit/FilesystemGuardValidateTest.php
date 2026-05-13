<?php
/**
 * Unit tests for Haydi_Filesystem_Guard's path validation.
 *
 * Uses Reflection to:
 *  - Instantiate the guard without its constructor.
 *  - Inject a real temp directory as the sole allowed root.
 *  - Invoke the private validate_path() and validate_new_path() methods.
 *
 * Real filesystem operations are used so realpath(), is_dir(), and is_file()
 * behave authentically without mocking.
 */

use PHPUnit\Framework\TestCase;

class FilesystemGuardValidateTest extends TestCase {

    private \Haydi_Filesystem_Guard $guard;
    private \ReflectionMethod $validatePath;
    private \ReflectionMethod $validateNewPath;
    private static string $root;

    public static function setUpBeforeClass(): void {
        self::$root = sys_get_temp_dir() . '/haydi-guard-test-' . bin2hex( random_bytes( 4 ) );
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
        $ref         = new \ReflectionClass( Haydi_Filesystem_Guard::class );
        $this->guard = $ref->newInstanceWithoutConstructor();

        // Inject the temp directory as the sole allowed root.
        $prop = $ref->getProperty( 'allowed_roots' );
        $prop->setValue( $this->guard, [ 'test' => realpath( self::$root ) ] );

        $this->validatePath    = $ref->getMethod( 'validate_path' );
        $this->validateNewPath = $ref->getMethod( 'validate_new_path' );
    }

    // -----------------------------------------------------------------------
    // validate_path — accepted paths
    // -----------------------------------------------------------------------

    public function test_validate_path_accepts_root_directory_itself(): void {
        $result = $this->validatePath->invoke( $this->guard, self::$root );
        $this->assertEquals( realpath( self::$root ), $result );
    }

    public function test_validate_path_accepts_php_file(): void {
        $file = self::$root . '/hello.php';
        file_put_contents( $file, '<?php' );

        $result = $this->validatePath->invoke( $this->guard, $file );

        $this->assertEquals( realpath( $file ), $result );
    }

    public function test_validate_path_accepts_all_allowed_extensions(): void {
        foreach ( Haydi_Filesystem_Guard::ALLOWED_EXTENSIONS as $ext ) {
            $file = self::$root . "/file.{$ext}";
            file_put_contents( $file, 'content' );
            $result = $this->validatePath->invoke( $this->guard, $file );
            $this->assertIsString( $result, "Extension '{$ext}' should be accepted" );
        }
    }

    // -----------------------------------------------------------------------
    // validate_path — rejected paths
    // -----------------------------------------------------------------------

    public function test_validate_path_rejects_nonexistent_path(): void {
        $result = $this->validatePath->invoke( $this->guard, self::$root . '/no-such-file.php' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'invalid_path', $result->get_error_code() );
    }

    public function test_validate_path_rejects_disallowed_extension(): void {
        $file = self::$root . '/script.sh';
        file_put_contents( $file, '#!/bin/bash' );

        $result = $this->validatePath->invoke( $this->guard, $file );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'invalid_extension', $result->get_error_code() );
    }

    public function test_validate_path_rejects_dotfile(): void {
        // Must use an allowed extension (.php) so the extension check passes
        // and the hidden-file check triggers — the extension check fires first.
        $file = self::$root . '/.dotfile.php';
        file_put_contents( $file, '<?php' );

        $result = $this->validatePath->invoke( $this->guard, $file );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'hidden_file', $result->get_error_code() );
    }

    public function test_validate_path_rejects_path_outside_roots(): void {
        // /etc/passwd exists on Linux/macOS test hosts; /etc itself resolves cleanly.
        $result = $this->validatePath->invoke( $this->guard, sys_get_temp_dir() . '/outside.php' );

        // Either the file doesn't exist (invalid_path) or it's outside the root (path_not_allowed).
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    // -----------------------------------------------------------------------
    // validate_new_path — accepted paths
    // -----------------------------------------------------------------------

    public function test_validate_new_path_accepts_new_file_in_root(): void {
        $path   = self::$root . '/brand-new.php';
        $result = $this->validateNewPath->invoke( $this->guard, $path );

        $this->assertIsString( $result );
        $this->assertStringEndsWith( 'brand-new.php', $result );
    }

    public function test_validate_new_path_accepts_new_file_in_new_subdirectory(): void {
        $path   = self::$root . '/newdir/nested.js';
        $result = $this->validateNewPath->invoke( $this->guard, $path );

        $this->assertIsString( $result );
        $this->assertStringEndsWith( 'nested.js', $result );
    }

    // -----------------------------------------------------------------------
    // validate_new_path — rejected paths
    // -----------------------------------------------------------------------

    public function test_validate_new_path_rejects_disallowed_extension(): void {
        $result = $this->validateNewPath->invoke( $this->guard, self::$root . '/evil.exe' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'invalid_extension', $result->get_error_code() );
    }

    public function test_validate_new_path_rejects_dotfile(): void {
        // .dotfile.php has an allowed extension but a dot-prefixed basename,
        // so hidden_file fires (extension check passes, then hidden check runs).
        $result = $this->validateNewPath->invoke( $this->guard, self::$root . '/.dotfile.php' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'hidden_file', $result->get_error_code() );
    }

    public function test_validate_new_path_rejects_path_outside_roots(): void {
        // Parent exists (/tmp) but is not an allowed root.
        $result = $this->validateNewPath->invoke( $this->guard, sys_get_temp_dir() . '/outside.php' );

        $this->assertInstanceOf( WP_Error::class, $result );
    }
}
