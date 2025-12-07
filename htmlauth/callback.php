<?php
require_once("loxberry_web.php");
require_once("loxberry_log.php");
require_once("loxberry_json.php");
$log = LBLog::newLog(["name" => "callback.php"]);
LOGSTART("Auth callback");
$code = $_GET['code'] ?? null; $state = $_GET['state'] ?? null; $error = $_GET['error'] ?? null;
$cfg = (new LBJSON(LBPCONFIGDIR.'/ekz_config.json'))->read(); $c = $cfg->slave;
$stfile = LBPDATADIR.'/oidc_state.json'; $st = file_exists($stfile) ? json_decode(file_get_contents($stfile), true) : [];
if ($error) { LOGERR("OIDC error: $error"); header('Location: index.php'); exit; }
if (!empty($st['state']) && $state !== $st['state']) { LOGERR('State mismatch'); header('Location: index.php'); exit; }
$token_endpoint = rtrim($c->auth_server_base,'/') . '/realms/' . urlencode('myEKZ') . '/protocol/openid-connect/token';
$fields = [ 'grant_type'=>'authorization_code', 'client_id'=>$c->client_id, 'client_secret'=>$c->client_secret, 'code'=>$code, 'redirect_uri'=>$c->redirect_uri ];
$ch = curl_init($token_endpoint); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $fields); $resp = curl_exec($ch);
if ($resp === false) { LOGERR('Token exchange failed: '.curl_error($ch)); header('Location: index.php'); exit; }
$tok = json_decode($resp, true); if (!isset($tok['access_token'])) { LOGERR('No access_token in response'); header('Location: index.php'); exit; }
file_put_contents(LBPDATADIR.'/tokens.json', json_encode($tok, JSON_PRETTY_PRINT));
LOGOK('Access token persisted');
header('Location: index.php');
?>
