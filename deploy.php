<?php

$ts = microtime(true);
$config = require __DIR__.'/config.php';
require_once __DIR__.'/lib.php';

$payload = get_payload();

$access_log = date('Y-m-d H:i:s'). PHP_EOL
	. 'GET: '.print_r($_GET, 1). PHP_EOL
	. 'POST: '.print_r($_POST, 1). PHP_EOL
	. 'SERVER: '.print_r($_SERVER, 1). PHP_EOL
	. 'PAYLOAD: '.print_r($payload, 1). PHP_EOL
;
_log($access_log, __DIR__.'/log/access.log');

!$payload && exit(_404());

$provider = get_git_provider();
!$provider && exit(_404());

$app_conf = get_app_conf($config);
!$app_conf && exit(_403());

$path = rtrim($app_conf['path'], '/').'/';

if ($provider === 'github') {
	// Github API v3
	// https://developer.github.com/v3/activity/events/types/#pushevent
	//
	// you should put deploy keys inside /var/www/.ssh/id_rsa
	// also verify if user www-data has access to private repo:
	// sudo -u www-data ssh -T git@github.com
	//
	$event = strtolower($_SERVER['HTTP_X_GITHUB_EVENT']);
	if (in_array($event, array('create', 'push'))) {
		$clone_url = $payload['repository'][$app_conf['is_private'] ? 'ssh_url' : 'clone_url'];
		$ref = $payload['ref'];
		$git_hash = $payload['head_commit']['id'];
		if ($clone_url && $ref) {
			$ok = deploy_git($ref, $path, $clone_url, $app_conf);
		}
		!$ok && _503();
		echo strtoupper($event).' '.$app_conf['name'].' '.basename($ref).' '.($ok ? 'OK' : 'ERROR');
	} elseif ($event == 'ping') {
		echo 'PONG';
	} else {
		echo 'EVENT NOT SUPPORTED';
	}
} elseif ($provider === 'bitbucket') {
	$event = strtolower($_SERVER['HTTP_X_EVENT_KEY']);
	if (in_array($event, array('repo:push'))) {
		$clone_url = 'git@bitbucket.org:'.$payload['repository']['full_name'].'.git';
		$ref = $payload['push']['changes'][0]['new']['name'];
		$git_hash = $payload['push']['changes'][0]['new']['target']['hash'];
		if ($clone_url && $ref) {
			$ok = deploy_git($ref, $path, $clone_url, $app_conf);
		}
		!$ok && _503();
		echo strtoupper($event).' '.$app_conf['name'].' '.basename($ref).' '.($ok ? 'OK' : 'ERROR');
	} else {
		echo 'EVENT NOT SUPPORTED';
	}
}

$msg = implode(PHP_EOL, [
	'['.date('Y-m-d H:i:s').']',
	'deployed in: '.round(microtime(true) - $ts, 3).' seconds',
	'provider: '.$provider,
	'event: '.$event,
	'branch: '.basename($ref),
	'hash: '.$git_hash,
	'clone_url: '.$clone_url,
	'path: '.$path,
	'host: '.$_SERVER['HTTP_HOST'],
]);

echo PHP_EOL. $msg;
send_to_slack($app_conf, $msg, '#github');
