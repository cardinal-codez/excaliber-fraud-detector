<?php
/**
 * WordPress test-suite config.
 *
 * Runs against a SEPARATE database — the test suite drops and recreates every
 * table in it. Override any value with an environment variable.
 */

define( 'ABSPATH', rtrim( getenv( 'WP_CORE_DIR' ) ?: dirname( __DIR__, 4 ), '/' ) . '/' );

define( 'DB_NAME',     getenv( 'WP_TESTS_DB_NAME' ) ?: 'disham_tests' );
define( 'DB_USER',     getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASS' ) ?: 'root' );
// MAMP's MySQL listens on its own socket.
define( 'DB_HOST',     getenv( 'WP_TESTS_DB_HOST' ) ?: 'localhost:/Applications/MAMP/tmp/mysql/mysql.sock' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'DISHAM Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );
