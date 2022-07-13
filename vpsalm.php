<?php
/*
 * @copyright   Copyright (C) 2010-2022 Combodo SARL
 * @license     http://opensource.org/licenses/AGPL-3.0
 */
include "vpsalmCore.php";

$oVersionedAnalyser = new VersionedAnalyser();
echo $oVersionedAnalyser->run();
exit();
