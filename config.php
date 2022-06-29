<?php

$sCwd = preg_replace("#\\\\#", "/", getcwd());

$COMPOSER = $sCwd."/composer.json";
$PSALM_PATH = $sCwd."/vendor/bin/psalm";
$CONFIG_FILE = $sCwd."/psalm.xml";
$BASELINE_FOLDER = $sCwd."/baselines";
$IGNORE_FILE = $sCwd."/vpsalm-ignore.xml";

$SPY_REPORT = $sCwd."/SpyReport.txt";
$SPY_Errors = sys_get_temp_dir()."/SpyErrors";
