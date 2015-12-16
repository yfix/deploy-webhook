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
function validate_payload($raw_payload, $headers, $secret) {
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
function get_app_conf($config) {
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
	if (!isset($webhook_conf['secret']) || !validate_payload($raw_payload, $headers, $secret)) {
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
function copy_dir($from, $to) {
	if (!file_exists($from)) {
		return false;
	}
	if (!file_exists($to)) {
		mkdir($to, 0755, true);
	}
	foreach ($iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
		if ($item->isDir()) {
			mkdir($to. DIRECTORY_SEPARATOR. $iterator->getSubPathName());
		} else {
			copy($item, $to. DIRECTORY_SEPARATOR. $iterator->getSubPathName());
		}
	}
	return true;
}
/***/
function deploy_git($ref, $path, $clone_url, $app_conf) {
	$ref = basename($ref) ?: 'master';
	if (!preg_match('~^[a-z0-9\.-]+$~ims', $ref)) {
		return false;
	}
	if (!$path) {
		return false;
	}
	$path = rtrim($path, '/').'/'.$ref.'/';
	if (!file_exists($path)) {
		mkdir($path, 0755, true);
	}
	$cmd = [];
	$cmd[] = 'cd '.$path;
	if (!file_exists($path.'.git') || !file_exists($path.'.git/config')) {
		if (!$clone_url) {
			return false;
		}
		$cmd[] = 'git clone --recursive '.$clone_url.' '.$path;
	}
	$cmd[] = 'git reset --hard HEAD';
	$cmd[] = 'git pull origin';
	$cmd[] = 'git checkout '.$ref;
	$cmd[] = 'git submodule init && git submodule update && git submodule foreach --recursive "git submodule init && git submodule update"';
	$cmd[] = 'chown -R www-data:www-data .';
	_log(implode(' && '.PHP_EOL, $cmd));

	$output = array();
	exec(implode(' && ', $cmd), $output, $exec_status);
	_log(implode(PHP_EOL, $output));

	if ($output && $exec_status === 0 && $app_conf['name']) {
		$skel_dir = __DIR__.'/skels/'.$app_conf['name'];
		if (file_exists($skel_dir)) {
			copy_dir($skel_dir, $path);
			exec('chown -R www-data:www-data '.$path);
			_log('skel dir contents: '.$skel_dir.' copied to: '.$path);
		}
	}

	_log('deployed: '.$ref, __DIR__.'/log/'.$app_conf['name'].'.log');
	return $output;
}