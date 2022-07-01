<?php

$sCwd = preg_replace("#\\\\#", "/", getcwd());

$COMPOSER = $sCwd."/composer.json";
$CONFIG_FILE = $sCwd."/psalm.xml";
$BASELINE_FOLDER = $sCwd."/baselines";
$IGNORE_FILE = $sCwd."/vpsalm-ignore.xml";
