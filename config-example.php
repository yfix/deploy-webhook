<?php

return [
	'webhooks' => [
		'abcdefghijklmnopqrstuvwxyz012345' => [
			'secret' => '<your secret here>',
			'app' => 'app1',
		],
		'012345abcdefghijklmnopqrstuvwxyz' => [
			'secret' => '<your secret here>',
			'app' => 'app2',
		],
	],
	'apps' => [
		'app1' => array(
			'path' => __DIR__.'/deploys/app1',
		),
		'app2' => array(
			'path' => __DIR__.'/deploys/app2',
		),
	],
];
