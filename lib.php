<?php

/***/
if (!function_exists('getallheaders')) { 
	function getallheaders() {
		$headers = []; 
		$prefixes = ['HTTP_' => 1, 'CONTENT_' => 0, 'REMOTE_ADDR' => 0, 'REQUEST_METHOD' => 0, 'REQUEST_URi' => 0];
		foreach ($_SERVER as $name => $value) { 
			if (!$value) {
				continue;
			}
			foreach ($prefixes as $prefix => $cut) {
				$plen = strlen($prefix);
				if (substr($name, 0, $plen) !== $prefix) {
					continue;
				}
				$key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $cut ? substr($name, $plen) : $name))));
				$headers[$key] = $value;
			} 
		} 
		ksort($headers);
		return $headers; 
	} 
}
/***/
function _404() {
	header(($_SERVER['SERVER_PROTOCOL'] ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1').' 404 Not Found');
}
/***/
function _403() {
	header(($_SERVER['SERVER_PROTOCOL'] ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1').' 403 Forbidden');
}
/***/
function _503() {
	header(($_SERVER['SERVER_PROTOCOL'] ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1').' 503 Service Temporarily Unavailable');
}
/***/
function _log($msg = '', $log_path = '') {
	if (!$msg) {
		return false;
	}
	if (is_array($msg)) {
		$msg = trim(substr(trim(print_r($msg, 1)), 7, -1));
	}
	if (!$log_path) {
		$log_path = __DIR__.'/log/deploy.log';
		$log_dir = dirname($log_path);
		if (!file_exists($log_dir)) {
			mkdir($log_dir, 0755, true);
		}
	}
	file_put_contents($log_path, '['.date('Y-m-d H:i:s').'] '. $msg. PHP_EOL, FILE_APPEND);
}
/***/
function get_payload($raw = false) {
	$input = file_get_contents('php://input');
	if ($raw) {
		return $input;
	}
	$payload = $_SERVER['CONTENT_TYPE'] === 'application/json' ? json_decode($input, true) : $_POST['payload'];
	_log($payload);
	return $payload;
}
/***/
function get_webhook_id() {
	return strtolower(substr(ltrim($_SERVER['REQUEST_URI'], '/'), 0, 32));
}
/***/
function get_git_provider() {
	$lower_ua = strtolower($_SERVER['HTTP_USER_AGENT']);
	// Example: GitHub-Hookshot/cd33156
	if (false !== strpos($lower_ua, 'github')) {
		return 'github';
	// Example: Bitbucket-Webhooks/2.0
	} elseif (false !== strpos($lower_ua, 'bitbucket')) {
		return 'bitbucket';
	}
	return false;
}
/***/
function get_app_conf($config) {
	$provider = get_git_provider();
	if ($provider === 'github') {
		return github_get_app_conf($config);
	} elseif ($provider === 'bitbucket') {
		return bitbucket_get_app_conf($config);
	}
	return false;
}
/***/
function github_get_app_conf($config) {
	$raw_payload = get_payload($raw = true);
	if (!$raw_payload) {
		return false;
	}
	$webhook_id = get_webhook_id();
	if (!preg_match('~^[a-z0-9]{32}$~ims', $webhook_id) || !isset($config['webhooks'][$webhook_id])) {
		_log('404: wrong webhook');
		return false;
	}
	$headers = getallheaders();
	$webhook_conf = $config['webhooks'][$webhook_id];
	$secret = $webhook_conf['secret'];
	if (!isset($webhook_conf['secret']) || !github_validate_payload($raw_payload, $headers, $secret)) {
		_log('403: secret not valid');
		return false;
	}
	if (!isset($webhook_conf['app']) || !isset($config['apps'][$webhook_conf['app']])) {
		_log('404: no such app');
		return false;
	}
	return $config['apps'][$webhook_conf['app']] + array('name' => $webhook_conf['app']);
}
/***/
function github_validate_payload($raw_payload, $headers, $secret) {
	if (empty($secret)) {
		return true;
	}
	$signature = $headers['X-Hub-Signature'];
	if (!isset($signature)) {
		return false;
	}
	list($algo, $hash) = explode('=', $signature, 2);
	$payload_hash = hash_hmac($algo, $raw_payload, $secret);
	if ($hash !== $payload_hash) {
		return false;
	}
	return true;
}
/***/
function bitbucket_get_app_conf($config) {
	$raw_payload = get_payload($raw = true);
	if (!$raw_payload) {
		return false;
	}
	$webhook_id = get_webhook_id();
	if (!preg_match('~^[a-z0-9]{32}$~ims', $webhook_id) || !isset($config['webhooks'][$webhook_id])) {
		_log('404: wrong webhook');
		return false;
	}
	$headers = getallheaders();
	$webhook_conf = $config['webhooks'][$webhook_id];
	$secret = $webhook_conf['secret'];
	if (!bitbucket_validate_payload($raw_payload, $headers, $secret)) {
		_log('403: secret not valid');
		return false;
	}
	if (!isset($webhook_conf['app']) || !isset($config['apps'][$webhook_conf['app']])) {
		_log('404: no such app');
		return false;
	}
	return $config['apps'][$webhook_conf['app']] + array('name' => $webhook_conf['app']);
}
/***/
function bitbucket_validate_payload($raw_payload, $headers, $secret) {

// TODO: check bitbucket incoming IPs as validation
//
// https://confluence.atlassian.com/bitbucket/manage-webhooks-735643732.html
//
// If you want your server to check that the payloads it receives are from Bitbucket, whitelist these IP ranges:
// 131.103.20.160/27
// 165.254.145.0/26
// 104.192.143.0/24

	return true;
}
/***/
function copy_dir($from, $to) {
	if (!file_exists($from)) {
		return false;
	}
	if (!file_exists($to)) {
		mkdir($to, 0755, true);
	}
	foreach ($iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
		$to_path = rtrim($to, '/'). '/'. $iterator->getSubPathName();
		if (is_link($item) && is_dir($to_path) && file_exists($to_path)) {
			exec('rm -rfd "'.$to_path.'" && cp --no-dereference --preserve=links '.$item.' '.$to_path);
		} elseif (file_exists($to_path)) {
			continue;
		} elseif ($item->isDir()) {
			mkdir($to_path);
		} else {
			copy($item, $to_path);
		}
	}
	return true;
}
/***/
function deploy_git($ref, $path, $clone_url, $app_conf) {
	$ref = basename($ref) ?: 'master';
	if (!preg_match('~^[a-z0-9\._-]+$~ims', $ref)) {
		_log('wrong ref: '.$ref);
		return false;
	}
	if (!$path) {
		_log('empty path: '.$path);
		return false;
	}
	if (!is_readable($path)) {
		_log('deploy target dir is not readable: '.$path);
		return false;
	}
	if (!$app_conf['name']) {
		_log('empty app name: '.$app_conf['name']);
		return false;
	}
	$path = rtrim($path, '/').'/'.$ref.'/';
	if (!file_exists($path)) {
		mkdir($path, 0755, true);
		if (!file_exists($path)) {
			_log('cannot create deploy target dir: '.$path);
			return false;
		}
	}
	$cmd = [];
	// http://superuser.com/questions/232373/how-to-tell-git-which-private-key-to-use
	$ssh_key = __DIR__.'/ssh_keys/'.$app_conf['name'].'.pem';
	if (file_exists($ssh_key) && is_readable($ssh_key) && strlen(file_get_contents($ssh_key)) >= 512) {
		$cmd[] = 'export GIT_SSH_COMMAND="ssh -i '.$ssh_key.' -F /dev/null -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null"';
	}
	if (!file_exists($path.'.git') || !file_exists($path.'.git/config')) {
		if (!$clone_url) {
			_log('empty clone url: '.$clone_url);
			return false;
		}
		$cmd[] = 'git clone --recursive '.$clone_url.' '.$path;
	}
	$cmd[] = 'cd '.$path;
	$cmd[] = 'git reset --hard HEAD'; //  'origin/'.$ref;
	$cmd[] = 'git pull origin';
	$cmd[] = 'git checkout '.$ref;
	$cmd[] = '(git submodule init && git submodule update && git submodule foreach --recursive "git submodule init && git submodule update" ; true)';
	$cmd[] = 'chown -R www-data:www-data .';
	_log(implode(' && '.PHP_EOL, $cmd));

	$output = array();
	exec('('.implode(' && ', $cmd).') 2>&1', $output, $exec_status);
	_log(implode(PHP_EOL, $output));

	if (!$output || $exec_status !== 0) {
		print_r($cmd);
		print_r($output);
		_log('Error: exec exit status not 0');
		return false;
	}

	// Override some files after event processing (configs, links, etc)
	// Ability to override skel for specific branch
	$skel_dir = __DIR__.'/skels/'.$app_conf['name'].'|'.$ref;
	if (!file_exists($skel_dir)) {
		$skel_dir = __DIR__.'/skels/'.$app_conf['name'];
	}
	if (file_exists($skel_dir)) {
		copy_dir($skel_dir, $path);
		exec('chown -R www-data:www-data '.$path);
		_log('skel dir contents: '.$skel_dir.' copied to: '.$path);
	}

	_log('deployed: '.$ref, __DIR__.'/log/'.$app_conf['name'].'.log');
	return $output;
}
/***/
function send_to_slack($app_conf, $message, $channel = '', $icon = '') {
	$url = $app_conf['slack_webhook_url'];
	if (!$url) {
		return false;
	}
    $data = http_build_query([
        'payload' => json_encode([
            'channel'   => $channel ?: '#general',
            'username'  => 'yf-deploy-bot',
            'text'      => $message,
            'icon_emoji'=> $icon,
        ]),
    ]);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
