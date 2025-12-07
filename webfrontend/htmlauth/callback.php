<?php
require_once "common.php";
$cfg = ekz_cfg();

$error = $_GET["error"] ?? null;
if ($error) { $_SESSION["flash"] = "OIDC error: $error"; header("Location: index.php"); exit; }
$state = $_GET["state"] ?? null;
$code  = $_GET["code"] ?? null;

if (!isset($_SESSION["state"]) || $state !== $_SESSION["state"]) {
    $_SESSION["flash"] = "State mismatch. Please sign in again.";
    header("Location: index.php"); exit;
}
if (!$code) { $_SESSION["flash"] = "Waiting for ?code=..."; header("Location: index.php"); exit; }

$token_endpoint = $cfg['auth_server_base']."/realms/".$cfg['realm']."/protocol/openid-connect/token";
$data = [
    "grant_type"   => "authorization_code",
    "client_id"    => $cfg["client_id"],
    "client_secret"=> $cfg["client_secret"],
    "code"         => $code,
    "redirect_uri" => $cfg["redirect_uri"],
];

$ch = curl_init($token_endpoint);
curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_POSTFIELDS=>$data, CURLOPT_TIMEOUT=>30]);
$resp = curl_exec($ch);
if ($resp === false) { $_SESSION["flash"] = "Token exchange failed: ".curl_error($ch); header("Location: index.php"); exit; }
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($status < 200 || $status >= 300) { $_SESSION["flash"] = "Token HTTP $status: $resp"; header("Location: index.php"); exit; }
$json = json_decode($resp, true);
if (empty($json["access_token"])) { $_SESSION["flash"] = "No access_token in token response"; header("Location: index.php"); exit; }

$_SESSION["access_token"] = $json["access_token"];
// Persist refresh_token for cron
$toks = [
  "access_token"  => $json["access_token"],
  "refresh_token" => $json["refresh_token"] ?? "",
  "expires_at"    => time() + (int)($json["expires_in"] ?? 300)
];
save_tokens($toks);

$_SESSION["flash"] = "Authorization code received. Access token obtained (refresh saved).";
header("Location: index.php");
