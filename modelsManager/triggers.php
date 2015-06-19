<?php
/* Copyright (c) 2013 Apycat / Association France-ioi, MIT License http://opensource.org/licenses/MIT */

require_once __DIR__."/../../shared/connect.php";
require_once __DIR__."/../../shared/models.php";
require_once __DIR__."/../modelsManager/modelsManager.php";
require_once("triggersManager.inc.php");

include_once __DIR__."/../../shared/custom_triggers.php";

TriggerManager::$debug = true;
TriggerManager::generateAllTriggers($tablesModels);

?>
