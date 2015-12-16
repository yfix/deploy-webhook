<?php

$config = require __DIR__.'/config.php';
require_once __DIR__.'/lib.php';

$payload = get_payload();
$app_conf = get_app_conf($payload, $config);
if (!$app_conf) {
	exit(_404());
}
$path = rtrim($app_conf['path'], '/').'/';

# Github API v3
# https://developer.github.com/v3/activity/events/types/#pushevent
$event = strtolower($_SERVER['HTTP_X_GITHUB_EVENT']);
if (in_array($event, array('create', 'push'))) {
	$output = deploy_git($payload['ref'], $path, $payload['repository']['clone_url'], $app_conf);
	echo strtoupper($event).' OK';
} elseif ($event == 'ping') {
	echo 'PONG';
} else {
	echo 'EVENT NOT SUPPORTED';
}
