<?php

include __DIR__ . '/../src/CodeGun/XUrl/XUrl.php';

$xurl = new \CodeGun\XUrl\XUrl();
$r = $xurl->fetch('http://www.baidu.com');

var_dump($r);