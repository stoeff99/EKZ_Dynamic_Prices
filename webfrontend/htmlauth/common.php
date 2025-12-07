<?php
declare(strict_types=1);

require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "loxberry_io.php"; // MQTT helpers and Bluerhinos\phpMQTT
session_start();

/** Load config – accepts JSON or simple "key value" lines (your attached format) */
function ekz_cfg(): array {
    $path = LBPDATADIR . "/ekz_config.json";
    if (!file_exists($path)) throw new RuntimeException("Config not found: $path");
    $raw = trim(file_get_contents($path));
    if (strlen($raw) && $raw[0] === '{') {
        $cfg = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } else {
        // Parse "key value" tokens (multi-line allowed)
        $tokens = preg_split('/\s+/', $raw);
        $cfg = [];
        for ($i=0; $i<count($tokens); $i+=2) {
            if (!isset($tokens[$i+1])) break;
            $cfg[$tokens[$i]] = $tokens[$i+1];
        }
    }
    $defaults = [
        "timezone" => "Europe/Zurich",
        "output_base" => "ekz_customer_tariffs_now_plus_24h",
        "response_mode" => "query",
        "retries" => 3,
        "mqtt_enabled" => true,
        "mqtt_topic_summary" => "ekz/ems/tariffs/now_plus_24h",
    ];
    $cfg = array_merge($defaults, $cfg);
    foreach (["realm","auth_server_base","client_id","client_secret","redirect_uri","api_base","ems_instance_id","scope"] as $k) {
        if (empty($cfg[$k])) throw new RuntimeException("Missing cfg key: $k");
    }
    return $cfg;
}

/** Token storage (for cron refresh) */
function tokens_path(): string { return LBPDATADIR . "/tokens.json"; }
function load_tokens(): array {
    if (!file_exists(tokens_path())) return [];
    $d=json_decode(file_get_contents(tokens_path()),true);
    return is_array($d)?$d:[];
}
function save_tokens(array $t): void {
    file_put_contents(tokens_path(), json_encode($t, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

/** Ensure valid access_token: use refresh_token if needed */
function ensure_access_token(array $cfg): string {
    $t = load_tokens();
    if (!empty($t["access_token"]) && !empty($t["expires_at"]) && time() < $t["expires_at"] - 30) {
        return $t["access_token"];
    }
    if (empty($t["refresh_token"])) {
        if (!empty($_SESSION["access_token"])) return $_SESSION["access_token"]; // UI session
        throw new RuntimeException("No refresh_token; please sign in via UI once.");
    }
    // Refresh via Keycloak token endpoint (OIDC)
    $token_endpoint = $cfg['auth_server_base']."/realms/".$cfg['realm']."/protocol/openid-connect/token";
    $data = [
        "grant_type"    => "refresh_token",
        "client_id"     => $cfg["client_id"],
        "client_secret" => $cfg["client_secret"],
        "refresh_token" => $t["refresh_token"],
    ];
    $ch = curl_init($token_endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $data, CURLOPT_TIMEOUT => 30
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) throw new RuntimeException("Token refresh failed: ".curl_error($ch));
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) throw new RuntimeException("Token refresh HTTP $status: $resp");
    $json = json_decode($resp, true, 512, JSON_THROW_ON_ERROR);

    $t["access_token"]  = $json["access_token"] ?? "";
    $t["refresh_token"] = $json["refresh_token"] ?? $t["refresh_token"];
    $t["expires_at"]    = time() + (int)($json["expires_in"] ?? 300);
    save_tokens($t);
    return $t["access_token"];
}

/** GET with retries */
function get_json(string $url, array $headers, array $params, int $attempts = 3): array {
    $attempts=max(1,$attempts); $lastErr=null;
    for($i=0;$i<$attempts;$i++){
        $q=$url."?".http_build_query($params);
        $ch=curl_init($q);
        $hdrs=[]; foreach($headers as $k=>$v)$hdrs[]="$k: $v";
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>$hdrs,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);
        $resp=curl_exec($ch);
        if($resp!==false){
            $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
            curl_close($ch);
            if($status>=200&&$status<300) return json_decode($resp,true,512,JSON_THROW_ON_ERROR);
            $lastErr="HTTP $status: $resp";
        } else { $lastErr=curl_error($ch); curl_close($ch); }
        sleep($i==0?1:(2**$i));
    }
    throw new RuntimeException("GET failed: ".($lastErr??"unknown"));
}

/** Normalize EKZ payload -> rows array */
function normalize_prices(array $payload): array {
    $rows=[];
    foreach(($payload["prices"]??[]) as $p){
        $pick=function($entries,$unit){
            foreach($entries??[] as $e){ if(($e["unit"]??null)===$unit) return $e["value"]??null; }
            return null;
        };
        $rows[]=[
            "start_timestamp"        => $p["start_timestamp"] ?? null,
            "end_timestamp"          => $p["end_timestamp"] ?? null,
            "electricity_CHF_kWh"    => $pick($p["electricity"] ?? null, "CHF/kWh"),
            "grid_CHF_kWh"           => $pick($p["grid"] ?? null, "CHF/kWh"),
            "integrated_CHF_kWh"     => $pick($p["integrated"] ?? null, "CHF/kWh"),
            "regional_fees_CHF_kWh"  => $pick($p["regional_fees"] ?? null, "CHF/kWh"),
            "electricity_CHF_M"      => $pick($p["electricity"] ?? null, "CHF/M"),
            "grid_CHF_M"             => $pick($p["grid"] ?? null, "CHF/M"),
            "integrated_CHF_M"       => $pick($p["integrated"] ?? null, "CHF/M"),
            "regional_fees_CHF_M"    => $pick($p["regional_fees"] ?? null, "CHF/M"),
        ];
    }
    return $rows;
}

/** Prefer customerTariffs, fallback to public tariffs */
function fetch_window(array $cfg, string $accessToken, string $startIso, string $endIso): array {
    $headers = ["Authorization"=>"Bearer $accessToken", "accept"=>"application/json"];
    try {
        $endpoint = $cfg['api_base']."/customerTariffs";
        $params   = ["ems_instance_id"=>$cfg["ems_instance_id"],"start_timestamp"=>$startIso,"end_timestamp"=>$endIso];
        $payload  = get_json($endpoint, $headers, $params, (int)$cfg["retries"]);
        return [$payload, "customer"];
    } catch(Throwable $e) {
        try {
            $endpoint = $cfg['api_base']."/tariffs";
            $params   = ["tariff_name"=>"electricity_standard"];
            $payload  = get_json($endpoint, $headers, $params, (int)$cfg["retries"]);
            return [$payload, "public"];
        } catch(Throwable $e2) {
            return [["prices"=>[]], "standard"];
        }
    }
}

/** Publish JSON via MQTT using LoxBerry SDK broker settings */
function publish_mqtt_json(string $topic, array $payload, int $qos=1, bool $retain=true): void {
    if (function_exists('mqtt_publish')) {
        mqtt_publish($topic, json_encode($payload, JSON_UNESCAPED_UNICODE), $retain, $qos);
        return;
    }
    $creds = mqtt_connectiondetails();
    $client_id = uniqid(gethostname()."_ekz");
    $mqtt = new Bluerhinos\phpMQTT($creds['brokerhost'], (int)$creds['brokerport'], $client_id);
    if (!$mqtt->connect(true, NULL, $creds['brokeruser'], $creds['brokerpass'])) throw new RuntimeException("MQTT connect failed");
    $mqtt->publish($topic, json_encode($payload, JSON_UNESCAPED_UNICODE), $qos, $retain);
    $mqtt->close();
}

/** Build 24h window for scheduled run (today 18:00 → tomorrow 17:59:59) */
function build_scheduled_window(string $tz="Europe/Zurich"): array {
    $tzObj = new DateTimeZone($tz);
    $today18 = (new DateTime("today 18:00:00", $tzObj));
    $tomo1759 = (clone $today18)->modify("+23 hours 59 minutes 59 seconds");
    return [$today18->format("c"), $tomo1759->format("c")];
}
