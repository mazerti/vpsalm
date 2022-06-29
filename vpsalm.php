<?php

include "vpsalmCore.php";

$oVersionedAnalyser = new VersionedAnalyser();
echo $oVersionedAnalyser->run();
exit();
