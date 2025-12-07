<?php
require_once "common.php";
$cfg = ekz_cfg();
$_SESSION["state"] = bin2hex(random_bytes(18));
$_SESSION["nonce"] = bin2hex(random_bytes(18));
$authUrl = $cfg['auth_server_base']."/realms/".$cfg['realm']."/protocol/openid-connect/auth".
          "?".http_build_query([
            "client_id"     => $cfg["client_id"],
            "response_type" => "code",
            "response_mode" => $cfg["response_mode"],
            "scope"         => $cfg["scope"],
            "redirect_uri"  => $cfg["redirect_uri"],
            "state"         => $_SESSION["state"],
            "nonce"         => $_SESSION["nonce"],
          ]);
header("Location: $authUrl", true, 302);
exit;
