<?php

error_reporting(E_ALL);

$configFilename = file_exists(__DIR__.'/../config.json')
    ? __DIR__.'/../config.json'
    : __DIR__.'/../config.json.dist';

$config = json_decode(file_get_contents($configFilename), true);

$address = 'unix:///var/run/subsplit_worker.sock';

if (!isset($config['webhook-secret'])) {
    header('HTTP/1.1 403 Forbidden');
    echo '"webhook-secret" key is missing in your configuration.';
    exit;
}

if (!isset($_SERVER['HTTP_X_HUB_SIGNATURE'])) {
    header('HTTP/1.1 403 Forbidden');
    echo 'HTTP header "X-Hub-Signature" is missing. Please provide a secret token to secure your webhook.';
    exit;
}

list($algo, $hash) = explode('=', $_SERVER['HTTP_X_HUB_SIGNATURE']);

$rawPost = file_get_contents('php://input');
$signature = trim(hash_hmac($algo, $rawPost, $config['webhook-secret']));

if (!hash_equals($signature, $hash)) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Hook secret does not match.';
    exit;
}

if (!$client = stream_socket_client($address, $errNo, $errMsg)) {
    echo "Couldn't create stream_socket_client: [$errNo] $errMsg";
    exit(1);
}

fwrite($client, $_POST['payload']);
fclose($client);

echo "Thanks.\n";
