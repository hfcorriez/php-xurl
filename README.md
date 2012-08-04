Intro
=========

XUrl http request util for PHP


Install
==============

Add `codegun/xurl` to `composer.json` then install with `composer.phar install`


Namesapce
==============

All the classes under `CodeGun\XUrl` namespace

- XUrl

Usage
==============

```php
$xurl = new \CodeGun\XUrl();
$r = $xurl->fetch('http://www.baidu.com');
```