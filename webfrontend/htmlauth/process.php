<?php
require_once("loxberry_system.php");
require_once("loxberry_io.php");
require_once("loxberry_log.php");
require_once("loxberry_json.php");
$has_mqtt = false;
$bundled = LBPPLUGINDIR . '/lib/phpMQTT/phpMQTT.php';
if (file_exists($bundled)) { $has_mqtt = @include_once($bundled); }
if (!$has_mqtt) { $has_mqtt = @include_once('phpMQTT/phpMQTT.php'); }

define('STATUS_FILE', LBPDATADIR . '/rolling_status.json');

define('TOKENS_FILE', LBPDATADIR . '/tokens.json');

$log = LBLog::newLog([ 'name' => 'process.php' ]);
LOGSTART('Process called');

$action = $_POST['action'] ?? ($argv[1] ?? '');
switch ($action) {
  case 'fetch_rolling': fetch_rolling(); LOGEND('Fetch completed'); break;
  case 'ems_link_status': ems_link_status(); LOGEND('EMS link status done'); break;
  case 'get_config': output_config_json(); LOGEND('Config output'); break;
  case 'save_config': $json = $_POST['config'] ?? ''; save_config_json($json); LOGEND('Config saved'); break;
  default: http_response_code(404); LOGERR('Unknown action');
}
exit;

function read_cfg() { $lbj = new LBJSON(LBPCONFIGDIR . '/ekz_config.json'); $cfg = $lbj->read(); return $cfg->slave; }
function output_config_json() { header('Content-Type: application/json'); echo json_encode(read_cfg()); }
function save_config_json($json) { if (!$json) { http_response_code(400); LOGERR('save_config: empty'); return; } $lbj = new LBJSON(LBPCONFIGDIR . '/ekz_config.json'); $cfg = $lbj->read(); $cfg->slave = json_decode($json); $lbj->write(); echo json_encode($cfg->slave); }
function load_tokens() { if (file_exists(TOKENS_FILE)) { $t = json_decode(file_get_contents(TOKENS_FILE), true); return is_array($t)?$t:[]; } return []; }
function save_tokens($tok) { file_put_contents(TOKENS_FILE, json_encode($tok, JSON_PRETTY_PRINT)); }
function ensure_access_token($cfg, $tok) { return !empty($tok['access_token']) ? $tok['access_token'] : ''; }
function refresh_access_token($cfg, $tok) {
  if (empty($tok['refresh_token'])) return false;
  $endpoint = rtrim($cfg->auth_server_base,'/') . '/realms/' . urlencode('myEKZ') . '/protocol/openid-connect/token';
  $fields = [ 'grant_type'=>'refresh_token', 'client_id'=>$cfg->client_id, 'client_secret'=>$cfg->client_secret, 'refresh_token'=>$tok['refresh_token'] ];
  $ch = curl_init($endpoint); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $fields); $resp = curl_exec($ch);
  if ($resp === false) { LOGERR('Refresh token call failed: '.curl_error($ch)); return false; }
  $nt = json_decode($resp, true); if (empty($nt['access_token'])) { LOGERR('Refresh did not return access_token'); return false; }
  save_tokens($nt); return $nt['access_token'];
}
function api_get($url, $bearer) { $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_HTTPHEADER, [ 'Authorization: Bearer ' . $bearer, 'accept: application/json' ]); $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); if ($body === false) { LOGERR('HTTP error: '.curl_error($ch)); return [0, null]; } $json = json_decode($body, true); return [$code, $json]; }
function publish_mqtt($cfg, $raw_payload, $summary_payload) {
  if (empty($cfg->mqtt_enabled)) return 'Disabled';
  $raw_topic = !empty($cfg->mqtt_topic_raw) ? $cfg->mqtt_topic_raw : 'ekz/tariffs/raw';
  $sum_topic = !empty($cfg->mqtt_topic_summary) ? $cfg->mqtt_topic_summary : 'ekz/tariffs/summary';
  if (class_exists('Bluerhinos\phpMQTT')) {
    $creds = mqtt_connectiondetails();
    $client_id = uniqid(gethostname().'_client');
    $mqtt = new Bluerhinos\phpMQTT($creds['brokerhost'], $creds['brokerport'], $client_id);
    if(!$mqtt->connect(true, NULL, $creds['brokeruser'], $creds['brokerpass'])) { LOGERR('MQTT connect failed'); return 'Failed connect'; }
    $mqtt->publish($raw_topic, json_encode($raw_payload), 0, 1);
    $mqtt->publish($sum_topic, json_encode($summary_payload), 0, 1);
    $mqtt->close();
    return 'Success';
  }
  if (function_exists('mqtt_publish')) {
    mqtt_publish($raw_topic, json_encode($raw_payload), 1);
    mqtt_publish($sum_topic, json_encode($summary_payload), 1);
    return 'Success (native)';
  }
  return 'Disabled (no library)';
}
function write_status($last_run, $interval_count, $mqtt_result) { $st = [ 'last_run'=>$last_run, 'interval_count'=>$interval_count, 'mqtt_result'=>$mqtt_result ]; file_put_contents(STATUS_FILE, json_encode($st, JSON_PRETTY_PRINT)); }
function rolling_window($tz_name) { $tz = new DateTimeZone($tz_name ?: 'Europe/Zurich'); $now = new DateTime('now', $tz); $today = new DateTime($now->format('Y-m-d').' 18:00:00', $tz); $today_end = new DateTime($now->format('Y-m-d').' 23:59:59', $tz); $tomorrow = (clone $now)->modify('+1 day'); $tomorrow_start = new DateTime($tomorrow->format('Y-m-d').' 00:00:00', $tz); $tomorrow_end = new DateTime($tomorrow->format('Y-m-d').' 17:59:59', $tz); $label = $now->format('Y-m-d'); return [ $label, $today, $today_end, $tomorrow_start, $tomorrow_end ]; }
function fetch_rolling() {
  $cfg = read_cfg(); $tok = load_tokens(); $last_run = date('Y-m-d H:i:s'); $access = ensure_access_token($cfg, $tok);
  if (!$access) { LOGERR('No access token - sign in via OIDC first'); write_status($last_run, 0, 'No token'); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'No token']); return; }
  list($label, $t18, $t23, $tmr00, $tmr1759) = rolling_window($cfg->timezone ?? 'Europe/Zurich'); $base = rtrim($cfg->api_base,'/'); $ems = urlencode($cfg->ems_instance_id);
  $u1 = $base . '/customerTariffs?ems_instance_id=' . $ems . '&start_timestamp=' . rawurlencode($t18->format('c')) . '&end_timestamp=' . rawurlencode($t23->format('c'));;
  list($code1, $p1) = api_get($u1, $access); if ($code1 == 401) { $access = refresh_access_token($cfg, $tok); if ($access) { list($code1, $p1) = api_get($u1, $access); } }
  if ($code1 >= 400) { LOGERR('today-part request failed: HTTP '.$code1); $p1 = [ 'prices'=>[] ]; }
  $u2 = $base . '/customerTariffs?ems_instance_id=' . $ems . '&start_timestamp=' . rawurlencode($tmr00->format('c')) . '&end_timestamp=' . rawurlencode($tmr1759->format('c'));;
  list($code2, $p2) = api_get($u2, $access ?: ''); if ($code2 == 401 && !$access) { $access = refresh_access_token($cfg, $tok); if ($access) { list($code2, $p2) = api_get($u2, $access); } }
  if ($code2 >= 400) { LOGERR('tomorrow-part request failed: HTTP '.$code2); $p2 = [ 'prices'=>[] ]; }
  $prices = array_merge($p1['prices'] ?? [], $p2['prices'] ?? []); $payload = [ 'prices' => $prices ]; file_put_contents(LBPDATADIR . '/ekz_rolling_' . $label . '.json', json_encode($payload, JSON_PRETTY_PRINT));
  $summary = [ 'date_label'=>$label, 'interval_count'=>count($prices), 'rows'=>$prices ]; $mqtt_res = publish_mqtt($cfg, $payload, $summary); write_status($last_run, count($prices), $mqtt_res); header('Content-Type: application/json'); echo json_encode([ 'ok'=>true, 'intervals'=>count($prices), 'mqtt'=>$mqtt_res ]);
}
function ems_link_status() { $cfg = read_cfg(); $tok = load_tokens(); $access = ensure_access_token($cfg, $tok); if (!$access) { http_response_code(401); echo 'No token'; return; } $url = rtrim($cfg->api_base,'/') . '/emsLinkStatus'; list($code, $json) = api_get($url, $access); if ($code == 401) { $access = refresh_access_token($cfg, $tok); if ($access) { list($code, $json) = api_get($url, $access); } } header('Content-Type: application/json'); http_response_code($code ?: 500); echo json_encode($json); }
?>
