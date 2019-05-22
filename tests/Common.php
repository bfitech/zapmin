<?php

function testdir() {
	$dir = __DIR__ . '/testdata';
	if (!is_dir($dir))
		mkdir($dir, 0755);
	return $dir;
}
