<?php
error_reporting(E_ALL); ini_set('display_errors', 1);
require_once 'loxberry_web.php';
LBWeb::lbheader("OIDC Debug", "", "");
echo "<h3>Request method: " . htmlspecialchars($_SERVER['REQUEST_METHOD']) . "</h3>";
echo "<pre>\nGET:\n" . htmlspecialchars(print_r($_GET, true)) . "\n\nPOST:\n" . htmlspecialchars(print_r($_POST, true)) . "</pre>";
LBWeb::lbfooter();
