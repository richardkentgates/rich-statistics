<?php // phpcs:ignoreFile -- standalone server script, not a plugin file
/**
 * Rich Statistics — web app deploy webhook
 *
 * Called by GitHub Actions on every push. Verifies a shared secret token
 * and writes a trigger file that a cron job picks up within 60 seconds.
 *
 * This approach is more reliable than nohup/exec under Apache because
 * cron guarantees the background process will run to completion.
 */

$secret_file = '/etc/rsa-webhook-token';
$expected    = trim( (string) @file_get_contents( $secret_file ) );
$given       = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';

if ( ! $expected || ! hash_equals( $expected, $given ) ) {
	http_response_code( 401 );
	exit;
}

if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
	http_response_code( 405 );
	exit;
}

// Write a trigger file — the cron job at * * * * * picks it up.
$trigger_dir = '/var/www/rs-app';
$trigger     = $trigger_dir . '/.deploy-trigger';
@file_put_contents( $trigger, time() . "\n" );

http_response_code( 202 );
header( 'Content-Type: text/plain' );
echo 'Update queued.';
