<?php
namespace Bluerhinos;
class phpMQTT {
    private $host; private $port; private $clientid; private $connected = false;
    public function __construct($host, $port, $clientid) { $this->host=$host; $this->port=$port; $this->clientid=$clientid; }
    public function connect($clean=true, $will=NULL, $username=NULL, $password=NULL) { $this->connected = true; return true; }
    public function publish($topic, $content, $qos=0, $retain=0) { if (function_exists('mqtt_publish')) { @mqtt_publish($topic, $content, $retain ? 1 : 0); return true; } return false; }
    public function close() { $this->connected = false; }
}
?>
