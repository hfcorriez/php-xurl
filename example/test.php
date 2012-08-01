<?php

include __DIR__ . '/../XUrl.php';

$xurl = new \CodeGun\Util\XUrl\XUrl();
$r = $xurl->fetch('http://www.baidu.com');

var_dump($r);