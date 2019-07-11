<?php

use PHPUnit\Framework\TestCase;


function testdir() {
	$dir = __DIR__ . '/testdata';
	if (!is_dir($dir))
		mkdir($dir, 0755);
	return $dir;
}


class Common extends TestCase {

	public function eq() {
		return function($a, $b) {
			$this->assertEquals($a, $b);
		};
	}

	public function tr() {
		return function($a) {
			$this->assertTrue($a);
		};
	}

	public function sm() {
		return function($a, $b) {
			$this->assertSame($a, $b);
		};
	}

}
