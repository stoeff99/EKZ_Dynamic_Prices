
<?php
// Temporary debugging
error_reporting(E_ALL); ini_set('display_errors', 1);

require_once('loxberry_web.php');
require_once('loxberry_log.php');

$template_title = 'EKZ Dynamic Price';
$helplink = '';
$helptemplate = 'help.html';

$log = LBLog::newLog(['name'=>'index.php']);
LOGSTART('Index opened');

// Render LB header
LBWeb::lbheader($template_title, $helplink, $helptemplate);
?>
<h2>EKZ Dynamic Price</h2>
<p>Enter settings, sign in (OIDC), then fetch and publish the rolling window to MQTT.</p>

<div class="ui-grid-a" style="margin:0 0 1em 0">
  <div class="ui-block-a">
    <form method="post" action="process.php">
      <input type="hidden" name="action" value="fetch_rolling">
      <a href="#" onclick="this.closest('form').submit();return false;" class="ui-btn ui-btn-inline ui-corner-all ui-shadow">
        Fetch now (rolling 24h)
      </a>
    </form>
  </div>
  <div class="ui-block-b">
    <form method="post" action="process.php">
      <input type="hidden" name="action" value="ems_link_status">
      <a href="#" onclick="this.closest('form').submit();return false;" class="ui-btn ui-btn-inline ui-corner-all ui-shadow">
        EMS Link Status (JSON)
      </a>
    </form>
  </div>
</div>

<div class="ui-grid-a" style="margin:0 0 1em 0">
  <div class="ui-block-a"><a class="ui-btn ui-btn-inline ui-corner-all ui-shadow" href="settings.php">Settings</a></div>
  <div class="ui-block-b"><a class="ui-btn ui-btn-inline ui-corner-all ui-shadow" href="auth.php">Sign in (OIDC)</a></div>
</div>
<div><a class="ui-btn ui-btn-inline ui-corner-all ui-shadow" href="preview.php">Preview</a></div>

<?php
// Status panel
$datadir = LBPDATADIR;
$statusfile = $datadir . '/rolling_status.json';
if (file_exists($statusfile)) {
  $raw = file_get_contents($statusfile);
  $st = json_decode($raw, true);
  echo '<div data-role="collapsible" data-collapsed="false">';
  echo '<h3>Status</h3>';
  echo '<p>Last cron run: ' . htmlspecialchars($st['last_run'] ?? 'n/a') . '</p>';
  echo '<p>Intervals published: ' . htmlspecialchars($st['interval_count'] ?? 'n/a') . '</p>';
  echo '<p>MQTT publish: ' . htmlspecialchars($st['mqtt_result'] ?? 'n/a') . '</p>';
  echo '</div>';
}

LBWeb::lbfooter();
LOGEND('Index rendered');


