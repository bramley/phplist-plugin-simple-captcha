<?php

ob_end_clean();
$fs = new phpList\plugin\Common\FileServer();
$fs->serveFile(__DIR__ . '/refresh.png');

exit;
