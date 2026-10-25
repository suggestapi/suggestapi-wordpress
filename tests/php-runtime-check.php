<?php
// php2shell gate. No WordPress. suggestapi.php returns before it boots when
// ABSPATH is unset, after defining suggestapi_php_is_patched().
require dirname( __DIR__ ) . '/suggestapi.php';

$fail = 0;
function sapi_rcheck( $cond, $label ) {
	global $fail;
	if ( $cond ) {
		echo "PASS: $label\n";
	} else {
		echo "FAIL: $label\n";
		$fail = 1;
	}
}

$cases = array(
	'8.0.30'  => false,
	'8.1.33'  => false,
	'8.2.0'   => false,
	'8.2.34'  => false,
	'8.2.35'  => true,
	'8.2.36'  => true,
	'8.3.0'   => false,
	'8.3.35'  => false,
	'8.3.36'  => true,
	'8.4.0'   => false,
	'8.4.26'  => false,
	'8.4.27'  => true,
	'8.5.0'   => false,
	'8.5.11'  => false,
	'8.5.12'  => true,
	'8.5.13'  => true,
	'8.6.0'   => true,
);
foreach ( $cases as $version => $ok ) {
	$got = suggestapi_php_is_patched( $version );
	sapi_rcheck( $got === $ok, $version . ( $ok ? ' allowed' : ' blocked' ) );
}

$running = suggestapi_php_is_patched();
sapi_rcheck( $running, 'this runtime ' . PHP_VERSION . ' is a patched release' );

exit( $fail ? 1 : 0 );
