
<?php
// callback.php — EKZ OIDC token exchange and token persistence
// Works with response_mode=query (GET) and response_mode=form_post (POST)

error_reporting(E_ALL); ini_set('display_errors', 1);

require_once 'loxberry_web.php';
require_once 'loxberry_system.php';

// --- Load config from LBPDATADIR/ekz_config.json (JSON or key/value) ---
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
// sanity defaults
$cfg += [
    'realm'         => 'myEKZ',
    'response_mode' => 'query',
    'scope'         => 'openid'
];

// --- Read OIDC response (GET or POST) ---
session_start();
$error = $_REQUEST['error'] ?? null;
$state = $_REQUEST['state'] ?? null;
$code  = $_REQUEST['code']  ?? null;

if ($error) {
    LBWeb::lbheader("EKZ Dynamic Price", "", "");
    echo "<p><strong>OIDC Error:</strong> " . htmlspecialchars($error) . "</p>";
    echo "<p><adex.phpBack</a></p>";
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

// --- Exchange authorization code for tokens at Keycloak ---
try {
    $token_endpoint = rtrim($cfg['auth_server_base'] ?? '', '/') .
                      "/realms/" . ($cfg['realm'] ?? 'myEKZ') .
                      "/protocol/openid-connect/token";

    $data = [
        'grant_type'    => 'authorization_code',
        'client_id'     => $cfg['client_id'] ?? '',
        'client_secret' => $cfg['client_secret'] ?? '',
        'code'          => $code,
        // MUST match the one used in the /auth request (EKZ requires exact match)
        'redirect_uri'  => $cfg['redirect_uri'] ?? '',
    ];

    $ch = curl_init($token_endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_TIMEOUT        => 30
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch); curl_close($ch);
        throw new RuntimeException("Token exchange failed: $err");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Token HTTP $status: $resp");
    }

    $tok = json_decode($resp, true);
    if (empty($tok['access_token'])) {
        throw new RuntimeException("No access_token in token response");
    }

    // --- Persist tokens for cron and UI ---
    @mkdir(LBPDATADIR, 0775, true);
    $persist = [
        'access_token'  => $tok['access_token'],
        'refresh_token' => $tok['refresh_token'] ?? '',
        'expires_at'    => time() + (int)($tok['expires_in'] ?? 300),
    ];
    file_put_contents(LBPDATADIR . '/tokens.json', json_encode($persist, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

    // Optional: write a one-line server log for quick verification
    error_log('[EKZ] tokens.json written: keys=' . implode(',', array_keys($persist)));

    // --- Redirect user back to the admin UI (the one you know works) ---
    header("Location: /admin/plugins/ekz_dynamic_price_php/index.php");
    exit;

} catch (Throwable $e) {
    LBWeb::lbheader("EKZ Dynamic Price", "", "");
    echo "<p><strong>Token exchange error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a class='ui-btn ui-btn-inline' href='index();
    exit;
}
