<?php

include "phpstorm-interface.php";

$oVersionedAnalyser = new VersionedAnalyser();
echo $oVersionedAnalyser->run();
exit();
