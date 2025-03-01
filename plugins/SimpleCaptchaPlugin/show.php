<?php

use SimpleCaptcha\Builder;
use SimpleCaptcha\Helpers\Mime;

$builder = Builder::create();
$builder->distort = false;
$builder->maxLinesBehind = 1;
$builder->maxLinesFront = 1;
$builder->build(220, 80);
$_SESSION['simple_captcha_phrase'] = $builder->phrase;

ob_end_clean();
header('Content-type: ' . Mime::fromExtension('jpg'));
$builder->output();

exit;
