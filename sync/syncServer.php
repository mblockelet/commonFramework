<?php
/* Copyright (c) 2013 Apycat / Association France-ioi, MIT License http://opensource.org/licenses/MIT */

$startTime = microtime(true);

require_once __DIR__."/syncCommon.php";
require_once __DIR__."/../../shared/connect.php";
require_once __DIR__."/../../shared/syncRequests.php";

syncDebug('Synchro', 'begin');
session_start();

function clientSentIdenticalRecord($recordID, & $serverRecord, & $modelClientChanges) {
   if (isset($modelClientChanges["inserted"][$recordID])) {
      $clientRecord = $modelClientChanges["inserted"][$recordID]["data"];
   } else if (isset($modelClientChanges["updated"][$recordID])) {
      $clientRecord = $modelClientChanges["updated"][$recordID]["data"];
   } else {
      return false;
   }
   foreach ($serverRecord["data"] as $fieldName => $fieldValue) {
      if ($fieldName == "ID") {
         continue;
      }
      if ($clientRecord[$fieldName] != $fieldValue) {
         return false;
      }
   }
   return true;
}

function removeModelBackfiredChanges($modelName, & $modelServerChanges, & $modelClientChanges) {
   foreach ($modelServerChanges["deleted"] as $recordID => $value) {
      if (isset($modelClientChanges["deleted"][$recordID])) {
         unset($modelServerChanges["deleted"][$recordID]);
      }
   }
   foreach ($modelServerChanges["inserted"] as $recordID => $serverRecord) {
      if (clientSentIdenticalRecord($recordID, $serverRecord, $modelClientChanges)) {
         unset($modelServerChanges["inserted"][$recordID]);
      }
   }
   foreach ($modelServerChanges["updated"] as $recordID => $serverRecord) {
      if (clientSentIdenticalRecord($recordID, $serverRecord, $modelClientChanges)) {
         unset($modelServerChanges["updated"][$recordID]);
      }
   }
}

// We don't want to send records to the client that are exactly what the client sent us, so
// we remove any record that is identical to what the client sent.
function removeBackfiredChanges(& $serverChanges, $clientChanges) {
   foreach ($clientChanges as $modelName => $modelClientChanges) {
      $serverModelName = strtolower($modelName);
      foreach ($serverChanges["requestSets"] as $setName => $requestSet) {
         if (isset($requestSet[$serverModelName])) {
            removeModelBackfiredChanges($modelName, $requestSet[$serverModelName], $modelClientChanges);
         }
      }
      if (isset($serverChanges[$serverModelName])) {
         removeModelBackfiredChanges($modelName, $serverChanges[$serverModelName], $modelClientChanges);
      }
   }
}

function syncWithClient($db, $clientChanges, $minServerVersion, $requests, $roles, $params) {
   global $tablesModels, $startTime, $config;
   $useTransaction = true;
   if ($config && $config->sync->useTransaction == false) {
      $useTransaction = false;
   }
   // When transactions are used, we can be sure of two things:
   // - each synchronization has a version nummber that is unique to this synchronization, so the associated changes could easily be recognized
   // - a synchronization at the wrong time (in the middle of applying changes from another synchronization) can't happen, so incoherent data
   //   can't be sent to the client for such a reason
   if ($useTransaction) {
      $db->exec("SET autocommit=0"); // as shown at the bottom of http://dev.mysql.com/doc/refman/5.0/en/lock-tables-and-transactions.html
   }

   if (function_exists("syncAddCustomClientChanges")) {
      syncDebug('syncAddCustomClientChanges', 'begin');
      syncAddCustomClientChanges($db, $minServerVersion, $clientChanges);
      syncDebug('syncAddCustomClientChanges', 'end');
   }
   syncDebug('syncApplyChangesSafe', 'begin');
   syncApplyChangesSafe($db, $requests, $clientChanges, $roles);
   syncApplyChangesSafe($db, $requests, $clientChanges, $roles, true);
   syncDebug('syncApplyChangesSafe', 'end');

   // Applying changes before detecting changes mans that the changes coming from a client will be re-sent to it.
   // This is necessary because some of the changes may affect other records, that enter or leave the scope during the same version.
   // Listeners and triggers may also generate new changes that need to be sent to the client.
   
   // We save the current version, which will be used as the next minVersion when the same client synchronizes in the future. 
   $curVersion = syncGetVersion($db);

   $bsearchTimes = array();
   $maxVersion = $curVersion;
   $continued = false;
   $prevTime = microtime(true);
   while (true) {
      syncDebug('syncGetChanges', 'begin');
      $serverChanges = syncGetChanges($db, $requests, $minServerVersion, $maxVersion, $config->sync->maxChanges, $maxVersion == $curVersion);
      syncDebug('syncGetChanges', 'end');
      $bsearchTimes[] = (microtime(true) - $prevTime) * 1000;
      $prevTime = microtime(true);
      if ($serverChanges != null) {
         break;
      }
      // If there are more than $config->sync->maxChanges changes, we reduce the window of versions to send fewer changes
      // This way, we reduce the risk of memory or timeout issues preventing the synchronization from working properly
      $continued = true;
      $maxVersion = max($minServerVersion + 1, floor(($minServerVersion + $maxVersion) / 2));
   }
   $serverCounts = syncGetCounts($db, $requests, $minServerVersion, $maxVersion, $maxVersion == $curVersion);
   if (function_exists("syncAddCustomServerChanges")) {
      syncDebug('syncAddCustomServerChanges', 'begin');
      syncAddCustomServerChanges($db, $minServerVersion, $serverChanges, $serverCounts, $params);
      syncDebug('syncAddCustomServerChanges', 'begin');
   }

   syncDebug('removeBackfiredChanges', 'begin');
   removeBackfiredChanges($serverChanges, $clientChanges);
   syncDebug('removeBackfiredChanges', 'begin');

   if ($useTransaction) {
      $db->exec("COMMIT");
   }
   $execTime = (microtime(true) - $startTime) * 1000;
   echo "{";
   echo  "\"changes\":".json_encode($serverChanges).",";
   echo  "\"counts\":".json_encode($serverCounts).",";
   echo  "\"serverVersion\":".json_encode($maxVersion).",";
   echo  "\"execTime\":".json_encode($execTime).",";
   echo  "\"bsearchTimes\":".json_encode($bsearchTimes).",";
   echo  "\"continued\":".json_encode($continued).",";
   echo  "\"serverDateTime\":".json_encode(date('Y-m-d H:i:s'));
   echo  "}";
}

if (isset($_GET['json']) && $_GET['json'] == '1') {
   $_POST = json_decode(file_get_contents('php://input'), true);
   $_REQUEST = $_POST;
}

$clientChanges = array();
if (isset($_POST["changes"])) {
   if (is_string($_POST["changes"])) {
      $clientChanges = json_decode($_POST["changes"], true);
   } else {
      $clientChanges = $_POST["changes"];
   }
}

$minServerVersion = $_REQUEST["minServerVersion"];
$params = array();
if (isset($_REQUEST["params"])) {
   if (is_string($_REQUEST["params"])) {
      $params = json_decode($_REQUEST["params"], true);
   } else {
      $params = $_REQUEST["params"];
   }
}

$requests = array();
if (isset($_POST["requestSets"])) {
   if (is_string($_POST["requestSets"])) {
      $requestSets = json_decode($_POST["requestSets"], true);
   } else {
      $requestSets = $_POST["requestSets"];
   }
   foreach($requestSets as $requestSet) {
      $setName = $requestSet["name"];
      require_once __DIR__."/../../syncRequests/".$setName.".php";
      $newRequests = $setName::getSyncRequests($requestSet, $minServerVersion);
      if (!$newRequests) {
         error_log('requestSet '.$setName.' did not give any request!');
         continue;
      }
      $requests = array_merge($requests, $newRequests);
   }
}
$requests = array_merge($requests, getSyncRequests($params, $minServerVersion));

syncWithClient($db, $clientChanges, $minServerVersion, $requests, array("user", "admin"), $params);

?>
