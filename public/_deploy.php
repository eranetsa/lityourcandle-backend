<?php
declare(strict_types=1);

/**
 * GitHub webhook receiver.
 *
 * Expects a `push` event signed with HMAC-SHA256 using the secret stored at
 * /etc/lityourcandle/deploy.secret. On a push to refs/heads/main, spawns the
 * deploy script in the background and returns 202 immediately so GitHub's
 * webhook timer doesn't time out.
 *
 * Apache → /_deploy.php  (no .php extension hidden — direct file)
 */

header('Content-Type: text/plain; charset=utf-8');

$event   = $_SERVER['HTTP_X_GITHUB_EVENT']        ?? '';
$sig     = $_SERVER['HTTP_X_HUB_SIGNATURE_256']   ?? '';
$body    = file_get_contents('php://input') ?: '';
$secret  = trim((string)@file_get_contents('/etc/lityourcandle/deploy.secret'));

if ($secret === '' || strlen($sig) < 71 /* "sha256=" + 64 */) {
    http_response_code(401);
    echo "unauthorized\n";
    exit;
}

$expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    echo "bad signature\n";
    exit;
}

if ($event === 'ping') {
    echo "pong\n";
    exit;
}

if ($event !== 'push') {
    http_response_code(202);
    echo "ignored ($event)\n";
    exit;
}

$payload = json_decode($body, true) ?: [];
$ref     = (string)($payload['ref'] ?? '');
if ($ref !== 'refs/heads/main') {
    http_response_code(202);
    echo "ignored ref $ref\n";
    exit;
}

// Spawn deploy in background. www-data is allowed to sudo this one binary
// via /etc/sudoers.d/lityc-deploy. setsid + nohup + & ensures Apache can
// close the connection while the script keeps running.
$cmd = '/usr/bin/sudo -n /usr/local/sbin/lityc-deploy.sh';
@shell_exec("setsid nohup $cmd >/dev/null 2>&1 &");

http_response_code(202);
echo "deploy queued for " . substr((string)($payload['after'] ?? ''), 0, 12) . "\n";
