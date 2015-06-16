<?php

require_once("../shared/connect.php");
require_once("testModels.php");
require_once("triggersManager.inc.php");
require_once("versionedRequestsManager.php");

function createTableAndHistory($tableName, $fields) {
   global $db;
   $db->query("DROP TABLE IF EXISTS `".$tableName."`");
   $db->query("DROP TABLE IF EXISTS `history_".$tableName."`");

   $query = "CREATE TABLE `".$tableName."` (".
      "  `ID` bigint(20) NOT NULL,";
   foreach($fields as $name => $type) {
      $query .= "`".$name."` ".$type.",";
   }
   $query .= "`iVersion` int(11) NOT NULL,".
             "PRIMARY KEY (`ID`),".
             "KEY `iVersion` (`iVersion`)".
             ") ENGINE=InnoDB DEFAULT CHARSET=utf8";
   $db->query($query);

   $query = "CREATE TABLE `history_".$tableName."` (".
      "  `historyID` bigint(20) NOT NULL AUTO_INCREMENT,".
      "  `ID` bigint(20) NOT NULL,";
   foreach($fields as $name => $type) {
      $query .= "`".$name."` ".$type.",";
   }
   $query .= "`iVersion` int(11) NOT NULL,".
             "`iNextVersion` int(11) NULL, ".
             "`bDeleted` tinyint(11) NOT NULL, ".
             "PRIMARY KEY (`historyID`),".
             "KEY `ID` (`ID`),".
             "KEY `iVersion` (`iVersion`)".
             ") ENGINE=InnoDB DEFAULT CHARSET=utf8";
   $db->query($query);
}

echo "Creating test tables : ";
createTableAndHistory("test_sync_main",
   array(
      "secondID" => "bigint(11) NOT NULL",
      "sFieldA" => "varchar(30) NOT NULL",
      "iFieldB" => "int(11) NOT NULL"
   )
);
createTableAndHistory("test_sync_second",
   array(
      "thirdID" => "bigint(20) NOT NULL",
      "sFieldA" => "varchar(30) NOT NULL"
   )
);

createTableAndHistory("test_sync_third",
   array(
      "mainID" => "bigint(20) NOT NULL",
      "iFieldB" => "int(11) NOT NULL"
   )
);

echo "OK<br/>";

TriggerManager::generateAllTriggers($tablesModels, array());

function insertRecords($tableName, $records) {
   global $db;
   $sqlFields = "`".implode("`, `", array_keys($records[0]))."`";
   $sqlPlaceholders = ":".implode(", :", array_keys($records[0]));
   $query = "INSERT INTO `".$tableName."` (".$sqlFields.") VALUES (".$sqlPlaceholders.")";
   $stmt = $db->prepare($query);
   foreach ($records as $record) {
      $stmt->execute($record);
   };
}

insertRecords("test_sync_main",
   array(
      array("ID" => 1, "sFieldA" => "1", "iFieldB" => 1, "secondID" => 1),
      array("ID" => 2, "sFieldA" => "1", "iFieldB" => 2, "secondID" => 1),
      array("ID" => 3, "sFieldA" => "2", "iFieldB" => 1, "secondID" => 2),
      array("ID" => 4, "sFieldA" => "2", "iFieldB" => 2, "secondID" => 2),
      array("ID" => 5, "sFieldA" => "3", "iFieldB" => 1, "secondID" => 3),
      array("ID" => 6, "sFieldA" => "3", "iFieldB" => 2, "secondID" => 3),
   )
);

insertRecords("test_sync_second",
   array(
      array("ID" => 1, "sFieldA" => "1", "thirdID" => 1),
      array("ID" => 2, "sFieldA" => "1", "thirdID" => 1),
      array("ID" => 3, "sFieldA" => "2", "thirdID" => 2),
   )
);

insertRecords("test_sync_third",
   array(
      array("ID" => 1, "iFieldB" => "1", "mainID" => 1),
      array("ID" => 2, "iFieldB" => "2", "mainID" => 2),
      array("ID" => 3, "iFieldB" => "3", "mainID" => 3),
   )
);

function checkRequest($name, $request, $params, $minVersion, $expectedInserts, $expectedDeletes) {
   global $db;
   echo "Request ".$name." from version ".$minVersion.": ";
   $changes = VersionedRequestsManager::getChangesCountSince($db, $request, $params, $minVersion);
   if (($changes["inserted"] != $expectedInserts) || ($changes["deleted"] != $expectedDeletes)) {
      echo "Error count:<br/>";
      echo "<pre>".json_encode($changes)."</pre><br/>";
   } else {
      echo "OK count,";
   }

   $changes = VersionedRequestsManager::getChangesSince($db, $request, $params, $minVersion);
   if ((count($changes["inserted"]) != $expectedInserts) || (count($changes["deleted"]) != $expectedDeletes)) {
      echo "Error changes:<br/>";
      echo "<pre>".json_encode($changes)."</pre><br/>";
   } else {
      echo "OK changes<br/>";
   }
}

$requestSyncMain = array(
   "mainTable" => "test_sync_main",
   "aliasMainTable" => "main",
   "primaryKey" => "ID",
   "joins" => array(
   ),
   "fields" => array(
      "fieldA" => "`main`.`sFieldA`",
      "fieldB" => "`main`.`iFieldB`",
      "secondID" => "`main`.`secondID`"
   ),
   "conditions" => array(),
   "groupBy" => "",
   "orderBy" => ""
);

$requestAllTables = array(
   "mainTable" => "test_sync_main",
   "aliasMainTable" => "main",
   "primaryKey" => "ID",
   "joins" => array(
      "second" => array(
         "dstTable" => "test_sync_second",
         "aliasSrcTable" => "main",
         "type" => "",
         "joinCondition" => "`main`.`secondID` = `second`.`ID`"
      ),
      "third" => array(
         "dstTable" => "test_sync_third",
         "aliasSrcTable" => "second",
         "type" => "LEFT",
         "joinCondition" => "`second`.`thirdID` = `third`.`ID`"
      ),
   ),
   "fields" => array(
      "mainFieldA" => "`main`.`sFieldA`",
      "mainFieldB" => "`main`.`iFieldB`",
      "secondID" => "`main`.`secondID`",
      "secondFieldA"  => "`second`.`sFieldA`",
      "thirdID" => "`second`.`thirdID`"
   ),
   "conditions" => array("`second`.`sFieldA` = :secondFieldA"),
   "groupBy" => "",
   "orderBy" => ""
);


//VersionedRequestsManager::$debug = true;
checkRequest("test_sync_main", $requestSyncMain, array(), 0, 6, 0);
checkRequest("test_all_tables", $requestAllTables, array("secondFieldA" => 1), 0, 4, 0);

VersionedRequestsManager::incrementVersion();
$newMinVersion = VersionedRequestsManager::getVersions()->iVersion;

$db->query("DELETE FROM `test_sync_main` WHERE `ID` = 3");
insertRecords("test_sync_main",
   array(
      array("ID" => 7, "sFieldA" => "4", "iFieldB" => 2, "secondID" => 2),
   )
);
insertRecords("test_sync_second",
   array(
      array("ID" => 4, "sFieldA" => "1", "thirdID" => 2)
   )
);
//VersionedRequestsManager::$debug = true;
checkRequest("test_sync_main", $requestSyncMain, array(), $newMinVersion, 1, 1);
checkRequest("test_all_tables", $requestAllTables, array("secondFieldA" => 1), $newMinVersion, 1, 1);
