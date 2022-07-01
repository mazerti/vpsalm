<?php

$debug = getcwd();
include_once "vpsalmCore.php";

$oVersionedAnalyser = new VersionedAnalyser();
$oVersionedAnalyser->setBaselines();
