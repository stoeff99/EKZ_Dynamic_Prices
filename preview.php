<?php
// Temporary debugging
error_reporting(E_ALL); ini_set('display_errors', 1);

require_once('loxberry_web.php');
require_once('loxberry_log.php');

$template_title = 'Preview';
LBWeb::lbheader($template_title, '', 'help.html');

$files = glob(LBPDATADIR . '/ekz_rolling_*.json');
if ($files) {
  rsort($files);
  $latest = $files[0];
  echo '<pre>'.htmlspecialchars(file_get_contents($latest)).'</pre>';
} else {
  echo '<p>No rolling JSON found yet. Click Fetch on the home page.</p>';
}

LBWeb::lbfooter();
