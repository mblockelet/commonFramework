<?php
/* Copyright (c) 2013 Apycat / Association France-ioi, MIT License http://opensource.org/licenses/MIT */

require_once(__DIR__."/../../shared/connect.php");
require_once(__DIR__."/modelsTools.inc.php");

class VersionedRequestsManager {
   public static $debug = false;

   /*
      All the conditions provided in the request are relative to the main tables of the database or their aliases.
      When detecting changes, these conditions must also be applied to the corresponding history tables.
      This function is used to transform the conditions by adding the given prefix (usually "history_" to every
      occurrence of each table used.
   */
   static function addPrefix($request, $strConditions, $aliasPrefix) {
      foreach ($request["joins"] as $aliasDstTable => $join) {
         $strConditions = str_replace("`".$aliasDstTable."`", "`".$aliasPrefix.$aliasDstTable."`", $strConditions);
      }
      $aliasMainTable = $request["aliasMainTable"];
      return str_replace("`".$aliasMainTable."`", "`".$aliasPrefix.$aliasMainTable."`", $strConditions);
   }

   static function getConditions($request, $aliasPrefix = "") {
      $conditions = array();
      foreach ($request["conditions"] as $condition) {
         $conditions[] = VersionedRequestsManager::addPrefix($request, $condition, $aliasPrefix);
      }
      return $conditions;
   }

   static function getAliasIfDifferent($table, $aliasTable) {
      if ($table != $aliasTable) {
         return "AS `".$aliasTable."`";
      }
      return "";
   }


   static function selectUsesTable($request, $tableAlias) {
      foreach ($request["fields"] as $fieldAlias => $fieldSql) {
         if (strpos($fieldSql, "`".$tableAlias."`") !== false) {
            return true;
         }
      }
      return false;
   }

   // get max version among all tables. if $selectedOnly, we only include tables that are used for the selected fields
   static function getMaxVersion($request, $selectedOnly) {
      $allVersions = array();
      if ((!$selectedOnly) || VersionedRequestsManager::selectUsesTable($request, $request["aliasMainTable"])) {
         $allVersions[] = "IFNULL(`".$request["aliasMainTable"]."`.`iVersion`, 0)";
      }
      foreach ($request["joins"] as $aliasDstTable => $join) {
         if ((!$selectedOnly) || VersionedRequestsManager::selectUsesTable($request, $aliasDstTable)) {
            $allVersions[] = "IFNULL(`".$aliasDstTable."`.`iVersion`, 0)";
         }
      }
      return sqlGreatest($allVersions);
   }

   static function getJoinTypeSQL($join) {
      if (isset($join["type"]) && ($join["type"] == "LEFT")) {
         return "LEFT JOIN";
      }
      return "JOIN";
   }

   static function getSqlJoins($request) {
      $sqlJoins = "";
      foreach ($request["joins"] as $aliasDstTable => $join) {
         $dstTable = $join["dstTable"];
         $aliasSrcTable = $join["aliasSrcTable"];
         $sqlJoins .= VersionedRequestsManager::getJoinTypeSQL($join)." `".$dstTable."` AS `".$aliasDstTable."` ON (".$join["joinCondition"].")\n";
      }
      return $sqlJoins;
   }

   /*
      getQueryListChangedRecords generates an SQL query that lists the IDs of all the records
      that were present just before version $minVersion, but for which there has since been a
      change in the table $changedDstTable.

      For every table involved except changedDstTable, we consider the last version of each record
      before $minVersion, if there was one.

      For the table changedDstTable, we consider the last version of a record if there has been a
      change to this record after $minVersion.

      All the conditions defined by the request are applied, so that we only return records
      that were part of the request just before $minVersion.
   */
   static function getQueryListChangedRecords($request, $minVersion, $changedDstTable) {
      global $db;
      $minVersion = $db->quote($minVersion);
      $hasLeftJoins = false;
      $sqlJoins = "";
      foreach ($request["joins"] as $aliasDstTable => $join) {
         $dstTable = $join["dstTable"];
         $aliasSrcTable = $join["aliasSrcTable"];
         $minVersionCondition = "";
         if ($minVersion != null) {
            if ($aliasDstTable == $changedDstTable) {
               $allowNoChangeCondition = "";
            } else {
               $allowNoChangeCondition = " OR ". "`history_".$aliasDstTable."`.`iNextVersion` IS NULL";
            }
            $minVersionCondition = "AND `history_".$aliasDstTable."`.`iVersion` < ".$minVersion.
            " AND (`history_".$aliasDstTable."`.`iNextVersion` >= ".$minVersion." ".$allowNoChangeCondition.")";
         }
         if (isset($join["type"]) && ($join["type"] == "LEFT")) {
            $hasLeftJoins = true;
         }
         $joinCondition = VersionedRequestsManager::addPrefix($request, $join["joinCondition"], "history_");
         $alias = VersionedRequestsManager::getAliasIfDifferent("history_".$dstTable, "history_".$aliasDstTable);
         $sqlJoins .= VersionedRequestsManager::getJoinTypeSQL($join)." `history_".$dstTable."` ".$alias." ON (".$joinCondition.$minVersionCondition.")\n";
      }

      $conditions = VersionedRequestsManager::getConditions($request, "history_");
      $aliasMainTable = $request["aliasMainTable"];
      if ($aliasMainTable == $changedDstTable) {
         $allowNoChangeCondition = "";
      } else {
         $allowNoChangeCondition = "OR `history_".$aliasMainTable."`.`iNextVersion` IS NULL";
      }
      $conditions[] = "`history_".$aliasMainTable."`.`iVersion` < ".$minVersion."\n".
         " AND (`history_".$aliasMainTable."`.`iNextVersion` >= ".$minVersion." ".$allowNoChangeCondition.")\n";

      if ($hasLeftJoins) {
         /*
            If there are left joins in the query, we need prevent the query from returning
            NULL for the table on which we detect changes.
            We could transform the left joins on the path to that table into inner joins, but it is more complicated.
         */
         $conditions[] = "`history_".$changedDstTable."`.`iVersion` IS NOT NULL";
      }
      
      $mainTable = $request["mainTable"];
      $alias = VersionedRequestsManager::getAliasIfDifferent("history_".$mainTable, "history_".$aliasMainTable);
      return "SELECT `history_".$aliasMainTable."`.`".$request["primaryKey"]."`\n".
            "FROM `history_".$mainTable."` ".$alias."\n".
            $sqlJoins." ".
            "WHERE ".implode($conditions, "\n AND ")."\n";
   }

   /*
      getIDsModifiedSince returns an SQL query that selects the IDs in the main table of every
      record that was present in the request's result before $minVersion, and for which there
      has been a change after $minVersion in at least one of the tables involved.

      A record for the request has changed since minVersion if and only if either the record in the
      mainTable has changed since minVersion, or one of the records of the joined table referenced
      by the main record has changed since minVersion.

      We use a separate subquery to detect changes in each table for performance reasons.
      
      If we used only one query, SQL would probably list every record as it was before $minVersion,
      and then only check for each record if there has been a change in one of the tables.

      By decomposing in subqueries, each focusing on the changes on one table, we hope to
      restrict the records listed to the ones that have changed for that table.

   */
   static function getIDsModifiedSince($request, $minVersion) {
      $queries = array();
      foreach ($request["joins"] as $aliasDstTable => $join) {
         $queries[] = VersionedRequestsManager::getQueryListChangedRecords($request, $minVersion, $aliasDstTable);
      }
      $queries[] = VersionedRequestsManager::getQueryListChangedRecords($request, $minVersion, $request["aliasMainTable"]);

      return "SELECT DISTINCT `".$request["primaryKey"]."` FROM (\n\n".implode($queries, "\n UNION \n\n")."\n) AS `mainTable`";
   }

   static function getFieldsWithAliases($fields) {
      $fieldsWithAliases = array();
      foreach ($fields as $alias => $field) {
         $fieldsWithAliases[] = $field." AS `".$alias."`";
      }
      return $fieldsWithAliases;
   }

   /*
      The records that are deleted from the request are the records that have been
      modified since minVersion, and are not in the current version.
   */
   static function getSelectQueryDeleted($request, $minVersion, $joinsMode = "") {
      $ID = $request["primaryKey"];
      $selectIDsModified = VersionedRequestsManager::getIDsModifiedSince($request, $minVersion);
      $sqlJoins = VersionedRequestsManager::getSqlJoins($request);
      $conditions = VersionedRequestsManager::getConditions($request);

      $alias = VersionedRequestsManager::getAliasIfDifferent($request["mainTable"], $request["aliasMainTable"]);
      $mainTableQuery = "SELECT `".$request["aliasMainTable"]."`.`".$ID."` \n".
         "FROM  `".$request["mainTable"]."` ".$alias."  \n".
         $sqlJoins;

      if (count($conditions) > 0) {
         $mainTableQuery .= " WHERE ".implode($conditions, "\n AND ")."\n";
      }
      if ($joinsMode == "countOnly") {
         $selectFields = "count(*) as `nbRows`";
      } else {
         $selectFields = "`changedIDs`.`".$ID."`";
      }
      $query = "SELECT ".$selectFields."\n ".
         "FROM (".$selectIDsModified.") as `changedIDs` \n".
         "LEFT JOIN (".$mainTableQuery.") AS `mainTable` ON (`changedIDs`.`".$ID."` = `mainTable`.`".$ID."`) \n".
         "WHERE `mainTable`.`".$ID."` IS NULL";
      return $query;
   }

   /* The records that have been updated or inserted in the request are
      records that are in the current version, but with maxVersionJoins >= $minVersion

      If they are not in changedIDs, this means they are inserted records
      Otherwise, they are updated records if maxVersionSelected >= $minVersion
   */
   static function getSelectQueryChanged($request, $minVersion, $joinsMode = "") {
      $ID = $request["primaryKey"];
      $selectIDsModified = VersionedRequestsManager::getIDsModifiedSince($request, $minVersion);
      $sqlJoins = VersionedRequestsManager::getSqlJoins($request);
      $conditions = VersionedRequestsManager::getConditions($request);
      $fieldsSelect = VersionedRequestsManager::getFieldsWithAliases($request["fields"]);
      $fieldsSelect[] = VersionedRequestsManager::getMaxVersion($request, true)." as `_maxVersionSelected`";
      $sqlFieldsSelect = "`changedIDs`.`".$ID."` as `_changedID`, \n".implode($fieldsSelect, ",\n ");

      if ($joinsMode === "countOnly") {
         $conditions[] = "`changedIDs`.`".$ID."` IS NULL";
         $sqlFieldsSelect = "count(*) as `nbRows`";
      }
      $alias = VersionedRequestsManager::getAliasIfDifferent($request["mainTable"], $request["aliasMainTable"]);
      $query = "SELECT `".$request["aliasMainTable"]."`.`".$ID."`, ".$sqlFieldsSelect."\n".
         "FROM  `".$request["mainTable"]."` ".$alias."\n".
         $sqlJoins.
         " LEFT JOIN (".$selectIDsModified.") as `changedIDs` ON (`".$request["aliasMainTable"]."`.`".$ID."` = `changedIDs`.`".$ID."`) \n";
      $conditions[] = VersionedRequestsManager::getMaxVersion($request, false)." >= ".$minVersion;
      $query .= " WHERE ".implode($conditions, "\n AND ")."\n";
      if ($request["groupBy"] != "") {
         $query .= " ".$request["groupBy"]."\n";
      }
      if ($joinsMode != "countOnly") {
         $query .= " ".$request["orderBy"]."\n";
      }
      return $query;
   }

   /*
      getChangesCountSince gives for each type of change, how many records are affected :
      deleted: records whose ID is returned by getIDsModifiedSince but who are not present in the new query
         => mainTable.ID IS NULL

      inserted: records that are present in the new query but not returned by getIDsModifiedSince
         => changedIDs.ID IS NULL AND _maxVersion >= minVersion

      updated: records that are present in both queries and for which maxVersion is >= minVersion
         => _maxVersion >= minVersion

      Right now we don't differentiate inserted and updated (why ?)
   */
   public static function getChangesCountSince($db, $request, $params, $minVersion) {
      $changedRecords = array();
      $query = VersionedRequestsManager::getSelectQueryDeleted($request, $minVersion, "countOnly");
      $stmt = $db->prepare($query);
      $stmt->execute($params);
      $row = $stmt->fetchObject();
      $changedRecords["deleted"] = $row->nbRows;

      $query = VersionedRequestsManager::getSelectQueryChanged($request, $minVersion, "countOnly");
      $stmt = $db->prepare($query);
      $stmt->execute($params);
      $row = $stmt->fetchObject();
      $changedRecords["inserted"] = $row->nbRows;
      return $changedRecords;
   }

   public static function getChangesSince($db, $request, $params, $minVersion) {
     $ID = $request["primaryKey"];
      $changedRecords = array(
         "inserted" => array(),
         "deleted" => array(),
         "updated" => array()
      );
      $nbChanges = 0;

      $query = VersionedRequestsManager::getSelectQueryDeleted($request, $minVersion, "read");
      if (VersionedRequestsManager::$debug) {
         echo "<hr><p>DELETED QUERY :</p><pre>".$query."</pre><hr/><br/>";
      }
      $stmt = $db->prepare($query);
      $stmt->execute($params);
      while ($row = $stmt->fetchObject()) {
        $changedRecords["deleted"][$row->$ID] = array();
        $nbChanges++;
      }

      $query = VersionedRequestsManager::getSelectQueryChanged($request, $minVersion, "read");
      if (VersionedRequestsManager::$debug) {
         echo "<hr><p>CHANGED QUERY :</p><pre>".$query."</pre><hr/><br/>";
      }
      $stmt = $db->prepare($query);
      $stmt->execute($params);
      while ($row = $stmt->fetchObject()) {
         $changedID = $row->_changedID;
         $maxVersionSelected = $row->_maxVersionSelected;
         unset($row->_changedID);
         unset($row->_maxVersionSelected);
         if ($changedID == null) {
            $changedRecords["inserted"][$row->$ID] = array("data" => $row);
            $nbChanges++;
         } else if ($maxVersionSelected >= $minVersion) {
            $changedRecords["updated"][$row->$ID] = array("data" => $row);
            $nbChanges++;
         }
      }
      if ($nbChanges == 0) {
         return null;
      }
      return $changedRecords;
   }

   public static function getVersions() {
      global $db;
      $query = "SELECT * FROM `synchro_version`";
      $stmt = $db->query($query);
      return $stmt->fetchObject();
   }
}
