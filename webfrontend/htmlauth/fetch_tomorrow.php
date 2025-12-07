<?php
require_once "common.php";
$cfg = ekz_cfg();

try { $accessToken = !empty($_SESSION["access_token"]) ? $_SESSION["access_token"] : ensure_access_token($cfg); }
catch (Throwable $e) { $_SESSION["flash"] = "No access token. Please sign in first."; header("Location: index.php"); exit; }

// Tomorrow 00:00 → 23:59:59
$tz = new DateTimeZone($cfg["timezone"]);
$now = new DateTime("now", $tz);
$tomorrow = (clone $now)->modify("+1 day")->setTime(0,0,0);
$startIso = $tomorrow->format("c");
$endIso   = (clone $tomorrow)->modify("+1 day -1 second")->format("c");

[$payload, $source] = fetch_window($cfg, $accessToken, $startIso, $endIso);
$rows = normalize_prices($payload);
$rows = array_values(array_filter($rows, function($r) use($startIso,$endIso){
    $s = new DateTime($r["start_timestamp"] ?? $r["end_timestamp"] ?? $startIso);
    return $s >= new DateTime($startIso) && $s <= new DateTime($endIso);
}));
usort($rows, fn($a,$b) => strcmp((string)$a["start_timestamp"], (string)$b["start_timestamp"]));

$summary = ["date_label"=>substr($startIso,0,10), "interval_count"=>count($rows), "rows"=>$rows, "source"=>$source];
if (!empty($cfg["mqtt_enabled"])) publish_mqtt_json($cfg["mqtt_topic_summary"], $summary, 1, true);

// Simple browser preview
LBWeb::lbheader("EMS — Day-ahead Preview", "", "");
echo "<h3>Day-ahead tariffs for " . htmlspecialchars(substr($startIso,0,10)) . "</h3>";
echo "<h4>Preview (first 10 rows)</h4>";
$preview = array_slice($rows, 0, 10);
if (!empty($preview)) {
    $cols = array_keys($preview[0]);
    echo "<table><tr>"; foreach ($cols as $c) echo "<th>".htmlspecialchars($c)."</th>"; echo "</tr>";
    foreach ($preview as $r) { echo "<tr>"; foreach ($cols as $c) echo "<td>".htmlspecialchars((string)($r[$c] ?? ""))."</td>"; echo "</tr>"; }
    echo "</table><p>Intervals received: ".count($rows)."</p>";
} else { echo "<p>No data to preview.</p>"; }
echo "<p>index.phpBack</a></p>";
LBWeb::lbfooter();
