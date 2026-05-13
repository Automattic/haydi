<?php
/**
 * Unit tests for Haydi_Audit_Logger.
 *
 * WordPress functions (get_option, update_option, etc.) are stubbed via
 * Brain\Monkey so no live WordPress is required.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class AuditLoggerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Stub all WP functions called by AuditLogger::log().
        Functions\when( 'current_time' )->justReturn( '2024-01-01 00:00:00' );
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        $user             = new \stdClass();
        $user->ID         = 1;
        $user->user_login = 'admin';
        Functions\when( 'wp_get_current_user' )->justReturn( $user );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // log()
    // -----------------------------------------------------------------------

    public function test_log_stores_entry_with_expected_fields(): void {
        Functions\when( 'get_option' )->justReturn( [] );

        $captured = null;
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured ) {
            $captured = [ 'key' => $key, 'value' => $value ];
            return true;
        } );

        ( new Haydi_Audit_Logger() )->log( 'write_applied', '/path/to/file.php', 'Human-approved.' );

        $this->assertNotNull( $captured );
        $this->assertSame( Haydi_Audit_Logger::OPTION_KEY, $captured['key'] );
        $entry = $captured['value'][0];
        $this->assertSame( 'write_applied',          $entry['action'] );
        $this->assertSame( '/path/to/file.php',       $entry['path'] );
        $this->assertSame( 'Human-approved.',         $entry['details'] );
        $this->assertSame( '2024-01-01 00:00:00',     $entry['time'] );
        $this->assertSame( 1,                         $entry['user_id'] );
        $this->assertSame( 'admin',                   $entry['user'] );
    }

    public function test_log_prepends_new_entry_newest_first(): void {
        $existing = [
            [
                'action'  => 'old_action',
                'time'    => '2023-12-31 00:00:00',
                'user_id' => 1,
                'user'    => 'admin',
                'path'    => '',
                'details' => '',
            ],
        ];
        Functions\when( 'get_option' )->justReturn( $existing );

        $captured = null;
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured ) {
            $captured = $value;
            return true;
        } );

        ( new Haydi_Audit_Logger() )->log( 'new_action', '' );

        $this->assertSame( 'new_action', $captured[0]['action'] );
        $this->assertSame( 'old_action', $captured[1]['action'] );
    }

    public function test_log_trims_to_max_entries_cap(): void {
        // Pre-fill the log to exactly MAX_ENTRIES.
        $existing = array_fill(
            0,
            Haydi_Audit_Logger::MAX_ENTRIES,
            [ 'action' => 'old', 'time' => '', 'user_id' => 1, 'user' => 'admin', 'path' => '', 'details' => '' ]
        );
        Functions\when( 'get_option' )->justReturn( $existing );

        $captured = null;
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured ) {
            $captured = $value;
            return true;
        } );

        ( new Haydi_Audit_Logger() )->log( 'newest', '' );

        // Adding one more must keep the count at MAX_ENTRIES, not MAX_ENTRIES + 1.
        $this->assertCount( Haydi_Audit_Logger::MAX_ENTRIES, $captured );
        $this->assertSame( 'newest', $captured[0]['action'] );
    }

    // -----------------------------------------------------------------------
    // get_log()
    // -----------------------------------------------------------------------

    public function test_get_log_returns_stored_array(): void {
        $stored = [
            [ 'action' => 'list_files', 'time' => '', 'user_id' => 1, 'user' => 'admin', 'path' => '/x', 'details' => '' ],
        ];
        Functions\when( 'get_option' )->justReturn( $stored );

        $this->assertSame( $stored, ( new Haydi_Audit_Logger() )->get_log() );
    }

    public function test_get_log_returns_empty_array_for_corrupted_option(): void {
        Functions\when( 'get_option' )->justReturn( 'corrupted-scalar-data' );

        $this->assertSame( [], ( new Haydi_Audit_Logger() )->get_log() );
    }

    // -----------------------------------------------------------------------
    // clear_log()
    // -----------------------------------------------------------------------

    public function test_clear_log_deletes_option(): void {
        $deleted = false;
        Functions\when( 'delete_option' )->alias( function () use ( &$deleted ) {
            $deleted = true;
            return true;
        } );

        ( new Haydi_Audit_Logger() )->clear_log();

        $this->assertTrue( $deleted );
    }
}
