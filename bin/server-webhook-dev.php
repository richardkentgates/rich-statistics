<?php // phpcs:ignoreFile -- standalone server script, not a plugin file
/**
 * Rich Statistics — web app deploy webhook for DEV environment
 */
$secret_file = "/etc/rsa-webhook-token-dev";
$expected    = trim( (string) @file_get_contents( $secret_file ) );
$given       = $_SERVER["HTTP_X_DEPLOY_TOKEN"] ?? "";

if ( ! $expected || ! hash_equals( $expected, $given ) ) {
    http_response_code( 401 );
    exit;
}
if ( ( $_SERVER["REQUEST_METHOD"] ?? "" ) !== "POST" ) {
    http_response_code( 405 );
    exit;
}

$trigger = "/var/www/rs-app-dev/.deploy-trigger";
@file_put_contents( $trigger, time() . "\n" );

http_response_code( 202 );
header( "Content-Type: text/plain" );
echo "DEV update queued.";
