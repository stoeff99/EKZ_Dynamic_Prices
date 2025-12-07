<?php
require_once "common.php";
$cfg = ekz_cfg();

$accessToken = ensure_access_token($cfg);
[$startIso, $endIso] = build_scheduled_window($cfg["timezone"]);

[$payload, $source] = fetch_window($cfg, $accessToken, $startIso, $endIso);
$rowsAll = normalize_prices($payload);
$startT  = new DateTime($startIso);
$endT    = new DateTime($endIso);
$rows = array_values(array_filter($rowsAll, function($r) use($startT,$endT){
    $s = new DateTime($r["start_timestamp"] ?? $r["end_timestamp"] ?? "");
    return $s >= $startT && $s <= $endT;
}));
usort($rows, fn($a,$b) => strcmp((string)$a["start_timestamp"], (string)$b["start_timestamp"]));

$topic = $cfg["mqtt_topic_summary"] ?: "ekz/ems/tariffs/now_plus_24h";
$summary = [
    "from" => $startIso,
    "to"   => $endIso,
    "interval_count" => count($rows),
    "rows" => $rows,
    "source" => $source
];
publish_mqtt_json($topic, $summary, 1, true);

// Also write to data JSON
$file = LBPDATADIR . "/".$cfg['output_base'].".json";
file_put_contents($file, json_encode($summary, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
