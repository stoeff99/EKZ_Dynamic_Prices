<?php
// Minimal OIDC callback for EKZ Dynamic Price plugin
error_reporting(E_ALL); ini_set('display_errors', 1);

require_once 'loxberry_web.php';
require_once 'loxberry_system.php';

session_start();

// Load config
$cfgpath = LBPDATADIR . '/ekz_config.json';
if (!file_exists($cfgpath)) {
    LBWeb::lbheader("EKZ Dynamic Price", "", "");
    echo "<p><strong>Config missing:</strong> Please open settings.phpSettings</a> and save your EKZ/Keycloak details first.</p>";
    LBWeb::lbfooter();
    exit;
}

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

$err   = $_GET['error'] ?? null;
$state = $_GET['state'] ?? null;
$code  = $_GET['code']  ?? null;

if ($err) {
    LBWeb::lbheader("EKZ Dynamic Price", "", "");
    echo "<p><strong>OIDC Error:</strong> " . htmlspecialchars($err) . "</p>";
    LBWeb::lbfooter();
    exit;
}

if (!isset($_SESSION['state']) || $state !== $_SESSION['state']) {
    LBWeb::lbheader("EKZ Dynamic Price", "", "");
    echo "<p><strong>State mismatch</strong>. Please try start.phpSign in</a> again.</p>";
    LBWeb::lbfooter();
    exit;
}
if (!$code) {
    LBWeb::lbheader("EKZ Dynamic Price", "", "");
    echo "<p><strong>Missing code</strong>. Please start.phpSign in</a> again.</p>";
    LBWeb::lbfooter();
    exit;
}

// Token endpoint — exchange the authorization code
$token_endpoint = rtrim($cfg['auth_server_base'], '/') . "/realms/" . $cfg['realm'] . "/protocol/openid-connect/token";
$postdata = [
    'grant_type'   => 'authorization_code',
    'client_id'    => $cfg['client_id'],
    'client_secret'=> $cfg['client_secret'] ?? '',
    'code'         => $code,
    'redirect_uri' => $cfg['redirect_uri'],
];

$ch = curl_init($token_endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS     => $postdata,
    CURLOPT_TIMEOUT        => 30
]);
$resp = curl_exec($ch);
if ($resp === false) {
    $err = curl_error($ch); curl_close($ch);
    LBWeb::lbheader("EKZ Dynamic Price", "", "");
    echo "<p><strong>Token exchange failed:</strong> " . htmlspecialchars($err) . "</p>";
    LBWeb::lbfooter(); exit;
}
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($status < 200 || $status >= 300) {
    LBWeb::lbheader("EKZ Dynamic Price", "", "");
    echo "<p><strong>Token HTTP $status:</strong> " . htmlspecialchars($resp) . "</p>";
    LBWeb::lbfooter(); exit;
}

$tok = json_decode($resp, true);
if (empty($tok['access_token'])) {
    LBWeb::lbheader("EKZ Dynamic Price", "", "");
    echo "<p><strong>No access_token</strong> found in response.</p>";
    LBWeb::lbfooter(); exit;
}

// Persist tokens for cron runs and UI
$datadir = LBPDATADIR;
@mkdir($datadir, 0775, true);
file_put_contents($datadir . '/tokens.json', json_encode([
    'access_token'  => $tok['access_token'],
    'refresh_token' => $tok['refresh_token'] ?? '',
    'expires_at'    => time() + (int)($tok['expires_in'] ?? 300)
], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

LBWeb::lbheader("EKZ Dynamic Price", "", "");
echo "<p><strong>Signed in successfully.</strong> Tokens stored.</p>";
echo "<p>index.phpBack</a></p>";
LBWeb::lbfooter();

header("Location: /admin/plugins/ekz_dynamic_price_php/index.php"); exit;

