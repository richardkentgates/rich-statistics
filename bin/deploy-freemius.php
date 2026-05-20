#!/usr/bin/env php
<?php
/**
 * Deploy a WordPress plugin ZIP to Freemius using the official PHP SDK.
 *
 * Usage:
 *   php bin/deploy-freemius.php <file_name> <version> <release_mode> [sandbox]
 *
 * Environment variables required:
 *   DEV_ID, PUBLIC_KEY, SECRET_KEY, PLUGIN_SLUG, PLUGIN_ID
 *
 * release_mode: pending | beta | released
 * sandbox: true | false (default: false)
 */

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

if ($argc < 4) {
    fprintf(STDERR, "Usage: php bin/deploy-freemius.php <file_name> <version> <release_mode> [sandbox]\n");
    exit(1);
}

$file_name   = $argv[1];
$version     = $argv[2];
$release_mode = $argv[3];
$sandbox     = ($argv[4] ?? 'false') === 'true';

$required_env = ['DEV_ID', 'PUBLIC_KEY', 'SECRET_KEY', 'PLUGIN_SLUG', 'PLUGIN_ID'];
foreach ($required_env as $env) {
    if (empty(getenv($env))) {
        fprintf(STDERR, "Missing required environment variable: %s\n", $env);
        exit(1);
    }
}

$dev_id     = getenv('DEV_ID');
$public_key = getenv('PUBLIC_KEY');
$secret_key = getenv('SECRET_KEY');
$plugin_slug = getenv('PLUGIN_SLUG');
$plugin_id  = getenv('PLUGIN_ID');

if (!file_exists($file_name)) {
    fprintf(STDERR, "File not found: %s\n", $file_name);
    exit(1);
}

require_once __DIR__ . '/freemius-php-api/freemius/Freemius.php';

try {
    $api = new Freemius_Api('developer', $dev_id, $public_key, $secret_key, $sandbox);
} catch (Exception $e) {
    fprintf(STDERR, "Failed to initialize Freemius SDK: %s\n", $e->getMessage());
    exit(1);
}

// Verify API connectivity
echo "Testing Freemius API connection...\n";
if ($api->Test()) {
    echo "API connection successful.\n";
} else {
    fprintf(STDERR, "API connection test failed. Check credentials.\n");
    exit(1);
}

// Step 1: Check if version already exists
echo "Checking existing tags for version {$version}...\n";

$tags = $api->Api("plugins/{$plugin_id}/tags.json", 'GET');
$existing_tag_id = null;

if (is_object($tags) && isset($tags->tags) && is_array($tags->tags)) {
    foreach ($tags->tags as $tag) {
        if ($tag->version === $version) {
            $existing_tag_id = $tag->id;
            echo "Found existing tag ID {$existing_tag_id} for version {$version}\n";
            break;
        }
    }
}

if ($existing_tag_id) {
    $tag_id = $existing_tag_id;
    echo "Version {$version} already exists on Freemius (tag ID {$tag_id}). Skipping upload.\n";
} else {
    // Step 2: Upload the ZIP
    echo "Uploading {$file_name} as version {$version}...\n";

    $result = $api->Api(
        "plugins/{$plugin_id}/tags.json",
        'POST',
        ['add_contributor' => false],
        ['file' => $file_name]
    );

    if (!is_object($result) || !isset($result->id)) {
        fprintf(STDERR, "Upload failed.\n");
        fprintf(STDERR, "Result type: %s\n", gettype($result));
        if (is_string($result) && strlen($result) > 0) {
            fprintf(STDERR, "Raw response: %s\n", $result);
        } elseif (is_object($result) && isset($result->error)) {
            fprintf(STDERR, "Error: %s\n", json_encode($result->error));
        } elseif ($result === null || $result === '') {
            fprintf(STDERR, "Empty response from Freemius API. Check credentials and plugin ID.\n");
        } else {
            fprintf(STDERR, "Response: %s\n", var_export($result, true));
        }
        fprintf(STDERR, "File size: %d bytes\n", filesize($file_name));
        exit(1);
    }

    $tag_id = $result->id;
    echo "Uploaded successfully. Tag ID: {$tag_id}\n";
}

// Step 3: Set release mode
echo "Setting release_mode to {$release_mode}...\n";

$update = $api->Api(
    "plugins/{$plugin_id}/tags/{$tag_id}.json",
    'PUT',
    ['release_mode' => $release_mode]
);

if (!is_object($update)) {
    fprintf(STDERR, "Failed to set release_mode. Response:\n");
    print_r($update);
    exit(1);
}

echo "Done. Version {$version} (tag ID {$tag_id}) set to release_mode={$release_mode}\n";

// Optional: Output tag info for debugging
if (isset($update->version)) {
    $status = $update->status ?? 'unknown';
    echo "Version: {$update->version}, Status: {$status}\n";
}
