<?php
require_once "common.php";
$cfg = ekz_cfg();

try {
    $accessToken = !empty($_SESSION["access_token"]) ? $_SESSION["access_token"] : ensure_access_token($cfg);
} catch (Throwable $e) {
    $_SESSION["flash"] = "No access token. Please sign in first.";
    header("Location: index.php"); exit;
}

LBWeb::lbheader("EMS — Linking Status", "", "");
try {
    $headers = ["Authorization" => "Bearer $accessToken", "accept" => "application/json"];
    $endpoint = $cfg['api_base']."/emsLinkStatus";
    $params   = ["ems_instance_id"=>$cfg["ems_instance_id"], "redirect_uri"=>$cfg["redirect_uri"]];
    $status   = get_json($endpoint, $headers, $params, (int)$cfg["retries"]);

    echo "<h3>EMS Linking Status</h3><pre>" . htmlspecialchars(json_encode($status, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) . "</pre>";
    if (!empty($status["linking_process_redirect_uri"])) {
        echo "<p>Linking needed. Open this URL:</p><pre>" . htmlspecialchars($status["linking_process_redirect_uri"]) . "</pre>";
        echo "<p>(Open in browser, complete linking, then click “Check link status” again.)</p>";
    }
    echo "<p>index.phpBack</a></p>";
} catch (Throwable $e) {
    $_SESSION["flash"] = "/emsLinkStatus error: " . $e->getMessage();
    header("Location: index.php");
}
LBWeb::lbfooter();
