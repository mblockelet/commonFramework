<?php
/* Copyright (c) 2013 Apycat / Association France-ioi, MIT License http://opensource.org/licenses/MIT */

require_once("modelsTools.inc.php");

// get joins
function getSqlJoinsFromUsedWithVersions($viewModel, $joinsUsed, $aliasPrefix = "", $lastVersion = null, $changedJoinName = "") {
   global $db;
   $lastVersionQ = $db->quote($lastVersion);
   $sqlJoins = "";
   foreach ($viewModel["joins"] as $joinName => $join) {
      if (!isset($joinsUsed[$joinName])) {
         continue;
      }
      $dstTable = getDstTable($joinName, $join);
      $srcTable = $join["srcTable"];
      $lastVersionCondition = "";
      if ($lastVersion != null) {
         $allowNoChangeCondition = "";
         if ($joinName != $changedJoinName) {
            $allowNoChangeCondition = " OR (`".$aliasPrefix.$joinName."`.`iNextVersion` IS NULL AND NOT `".$aliasPrefix.$joinName."`.`bDeleted` <=> 1)";
         }
         $lastVersionCondition = "AND `".$aliasPrefix.$joinName."`.`iVersion` < ".$lastVersionQ.
         " AND (`".$aliasPrefix.$joinName."`.`iNextVersion` >= ".$lastVersionQ." ".$allowNoChangeCondition.")";
      }
      if (isset($join['on'])) {
         $joinOn = str_replace("[PREFIX]", $aliasPrefix, $join["on"]);
         $sqlJoins .= " JOIN `history_".$dstTable."` AS `".$aliasPrefix.$joinName."` ON (".
            $joinOn." ".$lastVersionCondition.")";
      } else {
         $sqlJoins .= " JOIN `history_".$dstTable."` AS `".$aliasPrefix.$joinName."` ON (".
            "`".$aliasPrefix.$srcTable."`.`".$join["srcField"]."` = ".
            "`".$aliasPrefix.$joinName."`.`".$join["dstField"]."` ".$lastVersionCondition.") ";
      }
   }
   return $sqlJoins;
}

/*
Here we consider records that were included in the request just before minVersion, and check if there was a change since then in at least
one of the table.

A record for the request has changed since minVersion if and only if either the record in the mainTable has changed since minVersion, or
one of the records of the joined table referenced by the current record has changed since minVersion.
*/
function getIDsModifiedSince($request, $minVersion, $maxVersion) {
   global $db;
   $minVersionQ = $db->quote($minVersion);
   $maxVersionQ = $db->quote($maxVersion);
   $viewModel = $request["model"];
   $ID = getPrimaryKey($viewModel);
   $joinsUsed = getJoinsUsed($request, "read", "select");
   $queries = array();
   $conditions = getConditions($request, "select", "", "history_");
   foreach ($viewModel["joins"] as $joinName => $join) {
      if (!isset($joinsUsed[$joinName]) || (isset($join['ignoreHistory']) && $join['ignoreHistory'])) {
         continue;
      }
      $sqlJoins = getSqlJoinsFromUsedWithVersions($viewModel, $joinsUsed, "history_", $minVersion, $joinName);
      $joinConditions = $conditions;
      $joinConditions[] = "`history_".$viewModel["mainTable"]."`.`iVersion` < ".$minVersionQ." AND (`history_".$viewModel["mainTable"]."`.`iNextVersion` >= ".$minVersionQ." OR ".
         "(`history_".$viewModel["mainTable"]."`.`iNextVersion` IS NULL AND NOT `history_".$viewModel["mainTable"]."`.`bDeleted` <=> 1))";
      $queries[] = "SELECT `history_".$viewModel["mainTable"]."`.`".$ID."` ".
         "FROM `history_".$viewModel["mainTable"]."` ".$sqlJoins." ".
         "WHERE ".implode($joinConditions, " AND ");
   }
   $sqlJoins = getSqlJoinsFromUsedWithVersions($viewModel, $joinsUsed, "history_", $minVersion, "");
   $conditions[] = "`history_".$viewModel["mainTable"]."`.`iVersion` < ".$minVersionQ." AND (`history_".$viewModel["mainTable"]."`.`iNextVersion` >= ".$minVersionQ.")";
   $queries[] = "SELECT `history_".$viewModel["mainTable"]."`.`".$ID."` FROM `"."history_".$viewModel["mainTable"]."` ".$sqlJoins." WHERE ".implode($conditions, " AND ");
   return "SELECT DISTINCT `".$ID."` FROM (".implode($queries, " UNION ").") AS `mainTable`";
}

// get max version among all tables from which we actually select fields
function getMaxVersionSelect($request) {
   $viewModel = $request["model"];
   $usedTables = array();
   foreach ($request["fields"] as $fieldName) {
      $field = $viewModel["fields"][$fieldName];
      if (!isset($field["sql"])) {
         $tableName = getFieldTable($viewModel, $fieldName);
         $usedTables[$tableName] = true;
      }
   }
   $allVersions = array();
   foreach ($usedTables as $tableName => $isUsed) {
      $allVersions[] = "IFNULL(`".$tableName."`.`iVersion`, 0)";
   }
   return sqlGreatest($allVersions)." as `_maxVersionSelected`";
}

function getMaxVersionAllJoins($request) {
   $viewModel = $request["model"];
   $allVersions = array("`".$viewModel["mainTable"]."`.`iVersion`");
   $joinsUsed = getJoinsUsed($request, "read", "select");
   foreach ($viewModel["joins"] as $joinName => $join) {
      if (!isset($joinsUsed[$joinName])) {
         continue;
      }
      $joinVersion = "`".$joinName."`.`iVersion`";
      if (isset($join["type"]) && $join["type"] == "LEFT") {
         $joinVersion = "IFNULL(".$joinVersion.", 0)";
      }
      $allVersions[] = $joinVersion;
   }
   if (count($allVersions) == 1) {
      return $allVersions[0];
   }
   return sqlGreatest($allVersions);
}

/*
   The records that are deleted from the request are the records that have been
   modified since minVersion, but are not in the current version.
*/
function getSelectQueryDeleted($request, $minVersion, $maxVersion, $joinsMode) {
   $viewModel = $request["model"];
   $ID = getPrimaryKey($viewModel);
   $selectIDsModified = getIDsModifiedSince($request, $minVersion, $maxVersion);
   $sqlJoins = getSqlJoins($request, $joinsMode, "select");
   $conditions = getConditions($request, "select");

   $mainTableQuery = "SELECT `".$viewModel["mainTable"]."`.`".$ID."` FROM  `".$viewModel["mainTable"]."` ".$sqlJoins;

   if (count($conditions) > 0) {
      $mainTableQuery .= " WHERE ".implode($conditions, " AND ");
   }
   $selectFields = "`changedIDs`.`".$ID."`";
   if ($joinsMode == "countOnly") {
      $selectFields = "count(*) as `nbItems`";
   }
   $query = "SELECT ".$selectFields." FROM (".$selectIDsModified.") as `changedIDs` ".
      " LEFT JOIN (".$mainTableQuery.") AS `mainTable` ON (`changedIDs`.`".$ID."` = `mainTable`.`".$ID."`) WHERE `mainTable`.`".$ID."` IS NULL";
   return $query;
}

/* The records that have been updated or inserted in the request are
   records that are in the current version, but with maxVersionJoins >= $minVersion

   If they are not in changedIDs, this means they are inserted records
   Otherwise, they are updated records if maxVersionSelected >= $minVersion
*/
function getSelectQueryChanged($request, $minVersion, $maxVersion, $joinsMode) {
   global $db;
   $minVersionQ = $db->quote($minVersion);
   $maxVersionQ = $db->quote($maxVersion);
   $viewModel = $request["model"];
   $ID = getPrimaryKey($viewModel);
   $selectIDsModified = getIDsModifiedSince($request, $minVersion, $maxVersion);
   $sqlJoins = getSqlJoins($request, "read", "select");
   $conditions = getConditions($request, "select");
   $fieldsSelect = getFieldsSelect($request);
   $fieldsSelect[] = getMaxVersionSelect($request);
   $sqlFieldsSelect = "`changedIDs`.`".$ID."` as `_changedID`, `".$viewModel["mainTable"]."`.`".$ID."`, ".implode($fieldsSelect, ", ");

   if ($joinsMode === "countOnly") {
      $conditions[] = "`changedIDs`.`".$ID."` IS NULL";
      $sqlFieldsSelect = "count(*) as `nbItems`";
   }
   $query = "SELECT ".$sqlFieldsSelect.
      " FROM  `".$viewModel["mainTable"]."`".
      $sqlJoins.
      " LEFT JOIN (".$selectIDsModified.") as `changedIDs` ON (`".$viewModel["mainTable"]."`.`".$ID."` = `changedIDs`.`".$ID."`) ";
   $conditions[] = getMaxVersionAllJoins($request)." >= ".$minVersionQ;
   $conditions[] = getMaxVersionAllJoins($request)." < ".$maxVersionQ;
   $query .= " WHERE ".implode($conditions, " AND ");
   if ($joinsMode != "countOnly") {
      $query .= getGroupBy($request);
   }
   //echo "<br/>".$query."<br/>";
   return $query;
}

/*
enreg à retirer : ceux dont l'ID est dans getIDsModifiedSince, mais qui ne sont pas présents dans la nouvelle requête
   => mainTable.ID IS NULL

enreg à ajouter : ceux qui sont présent dans la nouvelle requête mais pas dans getIDsModifiedSince
   => changedIDs.ID IS NULL et _maxVersion >= minVersion

enreg à modifier : ceux qui sont présents dans les 2 et dont le maxVersion des tables dont on sélectionne des champs est >= minVersion
   => _maxVersion >= minVersion
*/

function getChangesCountSince($db, $request, $minVersion, $maxVersion, $maxVersionIsDefault = false) {
   $changedRecords = array();
   if ($minVersion != 0) {
      $query = getSelectQueryDeleted($request, $minVersion, $maxVersion, "countOnly");
      $stmt = $db->prepare($query);
      $selectExecValues = getSelectExecValues($request);
      $stmt->execute($selectExecValues);
      $row = $stmt->fetchObject();
      $changedRecords["deleted"] = $row->nbItems;
   } else {
      $changedRecords["deleted"] = 0;
   }

   if($minVersion == 0 && $maxVersionIsDefault) {
      $query = getSelectQuery($request, "countOnly");
   } else {
      $query = getSelectQueryChanged($request, $minVersion, $maxVersion, "countOnly");
   }
   $stmt = $db->prepare($query);
   $selectExecValues = getSelectExecValues($request);
   $stmt->execute($selectExecValues);
   $row = $stmt->fetchObject();
   $changedRecords["inserted"] = $row->nbItems;
   return $changedRecords;
}

function getChangesSince($db, $request, $minVersion, $maxVersion, $requestName, $markRequest = false, $maxVersionIsDefault = false) {
   global $config;
   if (!$request || !is_array($request)) {
      error_log("no request provided");
      return;
   }
   $ID = getPrimaryKey($request["model"]);
   $changedRecords = array(
      "inserted" => array(),
      "deleted" => array(),
      "updated" => array()
   );
   $nbChanges = 0;
   $selectExecValues = getSelectExecValues($request);

   if ($minVersion != 0) {
      $query = getSelectQueryDeleted($request, $minVersion, $maxVersion, "read");
      if (isset($request['debugLogFunction'])) {
         $request['debugLogFunction']($query, $selectExecValues, 'get deleted');
      }
      $stmt = $db->prepare($query);
      $stmt->execute($selectExecValues);
      while ($row = $stmt->fetchObject()) {
         if ($markRequest) {
            $row->requestName = requestName;
         }
         $changedRecords["deleted"][$row->$ID] = array();
         $nbChanges++;
      }
   }

   if($minVersion == 0 && $maxVersionIsDefault) {
      $query = getSelectQuery($request, "read");
      if (isset($request['debugLogFunction'])) {
         $request['debugLogFunction']($query, $selectExecValues, 'getChangesSince (minVersion = 0)');
      }
   } else {
      $query = getSelectQueryChanged($request, $minVersion, $maxVersion, "read");
      if (isset($request['debugLogFunction'])) {
         $debugQuery = getSelectQuery($request, "read");
         $request['debugLogFunction']($debugQuery, $selectExecValues, 'simplified getChangesSince');
         $request['debugLogFunction']($query, $selectExecValues, 'getChangesSince');
      }
   }
   $stmt = $db->prepare($query);
   $stmt->execute($selectExecValues);
   while ($row = $stmt->fetchObject()) {
      $changedID = property_exists($row, '_changedID') ? $row->_changedID : null;
      $maxVersionSelected = property_exists($row, '_maxVersionSelected') ? $row->_maxVersionSelected : null;
      if ($markRequest) {
         $row->requestName = requestName;
      }
      unset($row->_changedID);
      unset($row->_maxVersionSelected);
      // if (isset($request['debugLogFunction'])) {
      //    $request['debugLogFunction'](print_r($row, true), array(), 'found');
      // }
      if ($changedID == null) {
         $changedRecords["inserted"][$row->$ID] = array("data" => $row);
         $nbChanges++;
      } else if ($maxVersionSelected >= $minVersion) {
         $changedRecords["updated"][$row->$ID] = array("data" => $row);
         $nbChanges++;
      }
      if ($nbChanges > $config->sync->maxChanges) {
        break;
      }
   }
   //echo "<br/><br/>".json_encode($changedRecords);
   if ($nbChanges == 0) {
      return null;
   }
   return $changedRecords;
}

function getRemovedIDsWhenRemovingFilters($db, $modelName, $viewModel, $remainingFilters, $removedFilters) {
   $ID = getPrimaryKey($viewModel);
   $requestRemaining = array(
      "modelName" => $modelName,
      "model" => $viewModel,
      "fields" => array(),
      "filters" => $remainingFilters
   );
   $queryRemaining = getSelectQuery($requestRemaining, "read");
   $requestRemoved = array(
      "modelName" => $modelName,
      "model" => $viewModel,
      "fields" => array(),
      "filters" => $removedFilters
   );
   $queryRemoved = getSelectQuery($requestRemoved, "read");
   $query = "SELECT `__removed__`.`".$ID."` FROM (".$queryRemoved.") AS `__removed__` WHERE NOT EXISTS (".$queryRemaining." AND `".$ID."` = `__removed__`.`".$ID."`)";
   $stmt = $db->prepare($query);
   $stmt->execute(getSelectExecValues($request));
   $removedIDs = array();
   while ($row = $stmt->fetchObject()) {
      $removedIDs[] = $row->$ID;
   }
   return $removedIDs;
}

function getViewModelFieldsList($viewModel) {
   $fields = array();
   foreach ($viewModel["fields"] as $fieldName => $fieldInfo) {
      $fields[] = $fieldName;
   }
   return $fields;
}
