<?php
/**
 * Starts a public Cloudflare quick tunnel so phone QR login works off-LAN.
 * Invoked in the background by scrumEnsureRemoteJoin().
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 404 Not Found');
    exit;
}

require_once __DIR__ . '/lib.php';

$port = isset($argv[1]) ? (int)$argv[1] : 8000;
scrumRunTunnelWorker($port);
