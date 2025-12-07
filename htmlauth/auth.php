<?php
require_once("loxberry_web.php");
require_once("loxberry_log.php");
require_once("loxberry_json.php");
$L = LBSystem::readlanguage("language.ini");
$template_title = $L['COMMON.TITLE'] ?? 'EKZ Dynamic Price';
$helplink = '';
$helptemplate = 'help.html';
$log = LBLog::newLog(["name" => "auth.php"]);
LOGSTART("Auth start");
$cfg = (new LBJSON(LBPCONFIGDIR.'/ekz_config.json'))->read();
$c = $cfg->slave;
$state = bin2hex(random_bytes(8));
$nonce = bin2hex(random_bytes(8));
file_put_contents(LBPDATADIR.'/oidc_state.json', json_encode([ 'state'=>$state, 'nonce'=>$nonce ]));
$auth_endpoint = rtrim($c->auth_server_base,'/') . '/realms/' . urlencode('myEKZ') . '/protocol/openid-connect/auth';
$params = http_build_query([
  'client_id' => $c->client_id,
  'response_type' => 'code',
  'response_mode' => 'query',
  'scope' => $c->scope ?? 'openid',
  'redirect_uri' => $c->redirect_uri,
  'state' => $state,
  'nonce' => $nonce
]);
LOGEND("Redirect to OIDC");
header('Location: ' . $auth_endpoint . '?' . $params);
exit;
?>
