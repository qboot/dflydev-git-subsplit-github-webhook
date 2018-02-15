<?php

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();

$configFilename = file_exists(__DIR__.'/../config.json')
    ? __DIR__.'/../config.json'
    : __DIR__.'/../config.json.dist';

$config = json_decode(file_get_contents($configFilename), true);

$socket_path = sys_get_temp_dir() . '/subsplit_worker.sock';
$socket_address = "unix://$socket_path";
$user = 'www-data';

@unlink($socket_path);

if (!$socket = stream_socket_server($socket_address, $errNo, $errMsg)) {
    echo "Couldn't create stream_socket_server: [$errNo] $errMsg";
    exit(1);
}

chown($socket_path, $user);
chgrp($socket_path, $user);

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
        $publishCommand[] = escapeshellarg('--rebuild-tags');
        $publishCommand[] = escapeshellarg('--no-heads');
        $publishCommand[] = escapeshellarg(sprintf('--tags=%s', $matches[1]));
    } elseif (preg_match('/refs\/heads\/(.+)$/', $ref, $matches)) {
        $publishCommand[] = escapeshellarg('--no-tags');
        $publishCommand[] = escapeshellarg(sprintf('--heads=%s', $matches[1]));
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

    $command = implode(' && ', [
        sprintf('cd %s', $workingDirectory),
        sprintf('( git subsplit init %s || true )', $repositoryUrl),
        'git subsplit update',
        implode(' ', $publishCommand)
    ]);

    passthru($command, $exitCode);

    if (0 !== $exitCode) {
        echo sprintf('Command %s had a problem, exit code %s', $command, $exitCode) . "\n";
        return;
    }
}
