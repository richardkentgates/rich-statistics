<?php
/**
 * Rich Statistics — web app deploy webhook
 *
 * Called by GitHub Actions on every release tag. Verifies a shared secret
 * token and kicks off the update script asynchronously so the HTTP response
 * is returned immediately.
 *
 * INSTALLATION (one-time, as root on the app server):
 * -------------------------------------------------------
 * 1. Copy this file into the web root at the webhook path:
 *      sudo cp bin/server-webhook.php /var/www/rs-app/_deploy/index.php
 *      sudo chown www-data:www-data /var/www/rs-app/_deploy/index.php
 *
 * 2. Generate a token and store it where only www-data can read it:
 *      sudo sh -c 'openssl rand -hex 32 > /etc/rsa-webhook-token'
 *      sudo chmod 640 /etc/rsa-webhook-token
 *      sudo chown root:www-data /etc/rsa-webhook-token
 *
 * 3. Copy the update script:
 *      sudo cp bin/server-update-webapp.sh /usr/local/bin/rsa-app-update
 *      sudo chmod +x /usr/local/bin/rsa-app-update
 *
 * 4. Allow www-data to run the update script without a password:
 *      echo "www-data ALL=(ALL) NOPASSWD: /usr/local/bin/rsa-app-update" \
 *        | sudo tee /etc/sudoers.d/rsa-app-update
 *
 * 5. Add the same token as a GitHub secret named DEPLOY_WEBHOOK_TOKEN:
 *      Repository → Settings → Secrets and variables → Actions → New secret
 *      Name:  DEPLOY_WEBHOOK_TOKEN
 *      Value: (contents of /etc/rsa-webhook-token)
 * -------------------------------------------------------
 */

// Read the expected token from a file outside the web root.
$secret_file = '/etc/rsa-webhook-token';
$expected    = trim( (string) @file_get_contents( $secret_file ) );
$given       = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';

// Reject missing or mismatched tokens (constant-time compare).
if ( ! $expected || ! hash_equals( $expected, $given ) ) {
    http_response_code( 401 );
    exit;
}

// Only accept POST.
if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
    http_response_code( 405 );
    exit;
}

// Fire the update script asynchronously so the webhook response is instant.
$log = '/var/log/rsa-deploy.log';
$cmd = 'sudo /usr/local/bin/rsa-app-update >> ' . escapeshellarg( $log ) . ' 2>&1';
exec( 'nohup ' . $cmd . ' </dev/null &' );

http_response_code( 202 );
header( 'Content-Type: text/plain' );
echo 'Update triggered.';
