<?php
/* Copyright (c) 2013 Apycat / Association France-ioi, MIT License http://opensource.org/licenses/MIT */

require_once("syncCommon.php");
require_once("../shared/syncRequests.php");
require_once("../shared/connect.php");

function syncWithServer($db, $serverUrl, $requests) {
   global $tablesModels, $config;
   $db->exec("SET autocommit=0"); // as shown at the bottom of http://dev.mysql.com/doc/refman/5.0/en/lock-tables-and-transactions.html
   try {
      // The client side of the synchronization should never send back to the server changes coming from it
      // (Otherwise we would cause an infinite loop of sending these changes back and forth)
      // So we detect changes before we apply any server changes.
      // This means this HAS to be run within a transaction, so that no changes are ignored

      syncIncrementVersion($db);
      $curVersions = syncGetVersions($db);

      $minVersion = $curVersions->iLastClientVersion;
      $maxVersion = $curVersions->iVersion;
      $clientChanges = syncGetChanges($db, $requests, $minVersion, $maxVersion, 1000000, true);
      $clientData = array("changes" => json_encode($clientChanges), "minServerVersion" => $curVersions->iLastServerVersion, "params" => $config->sync->params);
      $serverResponse = httpPost($serverUrl, $clientData);
      echo "<br/>SERVER response :<br/>".$serverResponse."<br/>";
      $serverData = json_decode($serverResponse);
      if (!isset($serverData->changes)) {
          throw new Exception("Invalid server answer");
      }
      syncApplyChanges($db, $requests, $serverData->changes, array("admin"));
      syncIncrementVersion($db);
      syncUpdateVersions($db, $serverData->serverVersion);
      $db->exec("COMMIT");
   } catch (Exception $e) {
      $db->exec("ROLLBACK");
      echo "Failed: " . $e->getMessage();
   }
}

$requests = getSyncRequests(array());
syncWithServer($db, $config->sync->server, $requests);

?>