<?php
// Minimal OIDC start for EKZ Dynamic Price plugin
error_reporting(E_ALL); ini_set('display_errors', 1);

require_once 'loxberry_web.php';
require_once 'loxberry_system.php';

// Load config — if not present, guide the user to Settings
$cfgpath = LBPDATADIR . '/ekz_config.json';
if (!file_exists($cfgpath)) {
    LBWeb::lbheader("EKZ Dynamic Price", "", "");
    echo "<p><strong>Config missing:</strong> Please open settings.phpSettings</a> and save your EKZ/Keycloak details first.</p>";
    LBWeb::lbfooter();
    exit;
}

// Accept both JSON and key/value formats
$raw = trim(file_get_contents($cfgpath));
if (strlen($raw) && $raw[0] === '{') {
    $cfg = json_decode($raw, true);
} else {
    $cfg = [];
    $tokens = preg_split('/\s+/', $raw);
    for ($i=0; $i<count($tokens); $i+=2) {
        if (!isset($tokens[$i+1])) break;
        $cfg[$tokens[$i]] = $tokens[$i+1];
    }
}

foreach (['realm','auth_server_base','client_id','redirect_uri','scope','response_mode'] as $k) {
    if (empty($cfg[$k])) {
        LBWeb::lbheader("EKZ Dynamic Price", "", "");
        echo "<p><strong>Missing setting:</strong> <code>$k</code>. Please complete settings.phpSettings</a>.</p>";
        LBWeb::lbfooter();
        exit;
    }
}

// Create state/nonce and build the authorization URL
session_start();
$_SESSION['state'] = bin2hex(random_bytes(18));
$_SESSION['nonce'] = bin2hex(random_bytes(18));

$authUrl = rtrim($cfg['auth_server_base'], '/') . "/realms/" . $cfg['realm'] . "/protocol/openid-connect/auth?" .
    http_build_query([
        'client_id'     => $cfg['client_id'],
        'response_type' => 'code',
        'response_mode' => $cfg['response_mode'],  // e.g., query
        'scope'         => $cfg['scope'],          // e.g., openid
        'redirect_uri'  => $cfg['redirect_uri'],   // MUST match Keycloak client
        'state'         => $_SESSION['state'],
        'nonce'         => $_SESSION['nonce'],
    ], '', '&');

header("Location: $authUrl", true, 302);
exit;
