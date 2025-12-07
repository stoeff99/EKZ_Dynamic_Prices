<?php
// Temporary debugging
error_reporting(E_ALL); ini_set('display_errors', 1);

require_once('loxberry_web.php');
require_once('loxberry_log.php');

$template_title = 'EKZ Settings';
$helplink = '';
$helptemplate = 'help.html';

$log = LBLog::newLog(['name'=>'settings.php']);
LOGSTART('Settings opened');

// Save/read the config in LBPDATADIR so OIDC pages find it
$cfgfile = LBPDATADIR . '/ekz_config.json';

// Load current config or defaults
$defaults = [
  'auth_server_base'   => 'https://login-test.ekz.ch/auth',
  'realm'              => 'myEKZ', // NEW
  'client_id'          => 'ems-bowles',
  'client_secret'      => '',
  // Default redirect to this plugin's callback.php (you can override in the UI)
  'redirect_uri'       => 'http://' . $_SERVER['HTTP_HOST'] . '/plugins/' . basename(LBPPLUGINDIR) . '/callback.php',
  'api_base'           => 'https://test-api.tariffs.ekz.ch/v1',
  'ems_instance_id'    => 'ems-bowles',
  'scope'              => 'openid',
  'response_mode'      => 'query', // NEW (fixes "Missing setting: response_mode")
  'timezone'           => 'Europe/Zurich',
  'mqtt_enabled'       => true,
  'mqtt_topic_raw'     => 'ekz/tariffs/raw',
  'mqtt_topic_summary' => 'ekz/tariffs/summary',
  'cron_enabled'       => true
];

if (file_exists($cfgfile)) {
  $raw = file_get_contents($cfgfile);
  $cfg = json_decode($raw, true);
  if (!is_array($cfg)) $cfg = $defaults;
} else {
  $cfg = $defaults;
}

// Save if POSTed
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $fields = [
    'auth_server_base','realm','client_id','client_secret','redirect_uri',
    'api_base','ems_instance_id','scope','response_mode','timezone',
    'mqtt_topic_raw','mqtt_topic_summary'
  ];
  foreach ($fields as $f) {
    if (isset($_POST[$f])) $cfg[$f] = trim($_POST[$f]);
  }
  $cfg['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? true : false;
  $cfg['cron_enabled'] = true; // always enable 18:05 daily

  // Write config JSON (LBPDATADIR)
  @mkdir(dirname($cfgfile), 0775, true);
  file_put_contents($cfgfile, json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

  // Cron registration
  $pluginfolder = basename(LBPPLUGINDIR);
  $cronfile = '/etc/cron.d/ekz_dynamic_price_php';

  // Option A: run the PHP rolling publisher directly (recommended)
  $line = "5 18 * * * root /usr/bin/php /opt/loxberry/webfrontend/htmlauth/plugins/${pluginfolder}/run_rolling_fetch.php >/dev/null 2>&1\n";

  // Option B: keep your existing POST workflow (uncomment to use B, comment Option A)
  // $line = "5 18 * * * root curl -s -X POST http://127.0.0.1/plugins/${pluginfolder}/process.php -d 'action=fetch_rolling' >/dev/null 2>&1\n";

  @file_put_contents($cronfile, $line);
  @system('systemctl restart cron');
}

// Helper
function val($cfg,$k,$d=''){ return htmlspecialchars(isset($cfg[$k])?$cfg[$k]:$d); }
$chk_mqtt = !empty($cfg['mqtt_enabled']) ? 'checked' : '';

// Render LB header
LBWeb::lbheader($template_title, $helplink, $helptemplate);
?>
<h2>EKZ Settings</h2>
<form method="post">
  <div data-role="collapsible" data-collapsed="false">
    <h3>EKZ Connection</h3>
    <label>Auth server base<br><input name="auth_server_base" size="60" value="<?=val($cfg,'auth_server_base')?>"></label><br>
    <label>Realm<br><input name="realm" value="<?=val($cfg,'realm','myEKZ')?>"></label><br>
    <label>Client ID<br><input name="client_id" value="<?=val($cfg,'client_id')?>"></label><br>
    <label>Client secret<br><input type="password" name="client_secret" value="<?=val($cfg,'client_secret')?>"></label><br>
    <label>Redirect URI<br><input name="redirect_uri" size="60" value="<?=val($cfg,'redirect_uri')?>"></label><br>
    <label>API base<br><input name="api_base" size="60" value="<?=val($cfg,'api_base')?>"></label><br>
    <label>EMS instance ID<br><input name="ems_instance_id" value="<?=val($cfg,'ems_instance_id')?>"></label><br>
    <label>Scope<br><input name="scope" value="<?=val($cfg,'scope','openid')?>"></label><br>
    <label>Response mode<br><input name="response_mode" value="<?=val($cfg,'response_mode','query')?>"></label><br>
    <label>Timezone<br><input name="timezone" value="<?=val($cfg,'timezone','Europe/Zurich')?>"></label>
  </div>

  <div data-role="collapsible" data-collapsed="false">
    <h3>MQTT</h3>
    <label><input type="checkbox" name="mqtt_enabled" <?=$chk_mqtt?>> Enable MQTT</label><br>
    <label>Topic raw<br><input name="mqtt_topic_raw" size="40" value="<?=val($cfg,'mqtt_topic_raw','ekz/tariffs/raw')?>"></label><br>
    <label>Topic summary<br><input name="mqtt_topic_summary" size="40" value="<?=val($cfg,'mqtt_topic_summary','ekz/tariffs/summary')?>"></label>
  </div>

  <button class="ui-btn ui-btn-inline" type="submit">Save</button>
  index.phpBack</a>
</form>
<?php LBWeb::lbfooter(); LOGEND('Settings rendered'); ?>
