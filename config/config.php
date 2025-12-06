<?php

return [
	"name" => "Downloader",
	"blocked_domains" => [],
	"event_stream" => [
		"timeout" => 1800,
		"ping_interval" => 30,
		"max_reconnect_attempts" => 5,
		"reconnect_delay" => 3000,
	],
];
