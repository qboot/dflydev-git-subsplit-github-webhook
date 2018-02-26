<?php

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();

$configFilename = file_exists(__DIR__ . '/../config.json')
    ? __DIR__ . '/../config.json'
    : __DIR__ . '/../config.json.dist';

$config = json_decode(file_get_contents($configFilename), true);
$config['default_user'] = isset($_SERVER['SUBSPLIT_DEFAULT_USER']) ? $_SERVER['SUBSPLIT_DEFAULT_USER'] : 'debian';

$socket_path = '/var/run/subsplit_worker.sock';
$socket_address = "unix://$socket_path";
$socket_user = isset($_SERVER['SUBSPLIT_SOCKET_USER']) ? $_SERVER['SUBSPLIT_SOCKET_USER'] : 'www-data';

@unlink($socket_path);

if (!$socket = stream_socket_server($socket_address, $errNo, $errMsg)) {
    echo "Couldn't create stream_socket_server: [$errNo] $errMsg";
    exit(1);
}

chown($socket_path, $socket_user);
chgrp($socket_path, $socket_user);

while (true) {
    $client = stream_socket_accept($socket, -1);

    while (true) {
        if (stream_get_meta_data($client)['eof']) {
            break;
        }
        if (!$payload = fgets($client)) {
            continue;
        }

        processPayload($payload, $config);
    }

    fclose($client);
}

fclose($socket);

function processPayload($payload, $config) {
    $data = json_decode($payload, true);
    $name = null;
    $project = null;

    foreach ($config['projects'] as $testName => $testProject) {
        if ($testProject['url'] === $data['repository']['ssh_url']) {
            $name = $testName;
            $project = $testProject;
            break;
        }
    }

    if (null === $name) {
        echo sprintf('Skipping request for URL %s (not configured)', $data['repository']['ssh_url']) . "\n";
        return;
    }

    $ref = $data['ref'];

    $publishCommand = [
        'git subsplit publish',
        escapeshellarg(implode(' ', $project['splits'])),
    ];

    if (preg_match('/refs\/tags\/(.+)$/', $ref, $matches)) {
        if (isset($project['options']['no-tags']) && $project['options']['no-tags'] === true) {
            echo sprintf("Skipping request for URL %s ('no-tags' option enabled)", $data['repository']['ssh_url']) . "\n";
            return;
        }

        $publishCommand[] = escapeshellarg('--rebuild-tags');
        $publishCommand[] = escapeshellarg('--no-heads');
        $publishCommand[] = isset($project['tags'])
            ? escapeshellarg(sprintf('--tags=%s', implode(' ', $project['tags'])))
            : escapeshellarg(sprintf('--tags=%s', $matches[1]));
    } elseif (preg_match('/refs\/heads\/(.+)$/', $ref, $matches)) {
        if (isset($project['options']['no-heads']) && $project['options']['no-heads'] === true) {
            echo sprintf("Skipping request for URL %s ('no-heads' option enabled)", $data['repository']['ssh_url']) . "\n";
            return;
        }

        $publishCommand[] = escapeshellarg('--no-tags');
        $publishCommand[] = isset($project['heads'])
            ? escapeshellarg(sprintf('--heads=%s', implode(' ', $project['heads'])))
            : escapeshellarg(sprintf('--heads=%s', $matches[1]));
    } else {
        echo sprintf('Skipping request for URL %s (unexpected reference detected: %s)', $data['repository']['ssh_url'], $ref) . "\n";
        return;
    }

    $repositoryUrl = $project['url'];

    echo sprintf('Processing subsplit for %s (%s)', $name, $ref) . "\n";

    $workingDirectory = $config['working-directory'] . '/' . $name;
    if (!file_exists($workingDirectory)) {
        echo sprintf('Creating working directory for project %s (%s)', $name, $workingDirectory) . "\n";
        mkdir($workingDirectory, 0750, true);
    }

    $become = sprintf('sudo -u %s ', $config['default_user']);
    $command = implode(' && ', [
        sprintf('cd %s', $workingDirectory),
        sprintf('(%s git subsplit init %s || true )', $become, $repositoryUrl),
        $become . 'git subsplit update',
        $become . implode(' ', $publishCommand),
    ]);

    passthru($command, $exitCode);

    if (0 !== $exitCode) {
        echo sprintf('Command %s had a problem, exit code %s', $command, $exitCode) . "\n";
        return;
    }
}
