<?php
define('DEBUG', true);

include './libs/Template.php';

$obj = new Template();

$obj->assign('title', 'Template engine.');
$obj->assign('body', 'This is body.');

$obj->display('index.html');

