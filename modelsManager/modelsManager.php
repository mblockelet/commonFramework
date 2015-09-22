<?php
/* Copyright (c) 2013 Apycat / Association France-ioi, MIT License http://opensource.org/licenses/MIT */

if (file_exists( __DIR__."/../../shared/listeners.php")) {
   include_once __DIR__."/../../shared/listeners.php"; // not required
}
require_once __DIR__."/modelsTools.inc.php";

function getGroupBy($request) {
   $groupBys = array();
   foreach ($request["model"]["fields"] as $field) {
      if (isset($field["groupBy"])) {
         $groupBys[]  = $field["groupBy"];
      }
   }
   if (count($groupBys) > 0) {
      return " GROUP BY ".implode($groupBys, ", ");
   }
   return "";
}

function getFieldsUsedByTable($request) {
   $viewModel = $request["model"];
   $fieldsUsed = array();
   foreach ($viewModel["fields"] as $fieldAlias => $field) {
      $fieldName = getFieldName($viewModel, $fieldAlias);
      $tableName = getFieldTable($viewModel, $fieldAlias);
      if (!isset($fieldsUsed[$tableName])) {
         $fieldsUsed[$tableName] = array();
      }
      if (!in_array($fieldAlias, $request["fields"])) {
         continue;
      }
      $fieldsUsed[$tableName][$fieldName] = true;
   }
   return $fieldsUsed;
}

function propagateJoinsUsedTowardsDest($viewModel, $joinsUsed) {
   $modif = true;
   while ($modif) {
      $modif = false;
      foreach ($viewModel["joins"] as $joinName => $join) {
         if (isset($joinsUsed[$joinName]) && $joinsUsed[$joinName]) {
            continue;
         }
         if (isset($joinsUsed[$join["srcTable"]]) && $joinsUsed[$join["srcTable"]]) {
            $joinsUsed[$joinName] = true;
            $modif = true;
         }
      }
   }
   return $joinsUsed;
}

function propagateJoinsUsedTowardsMainTable($viewModel, $joinsUsed) {
   $modif = true;
   while ($modif) {
      $modif = false;
      foreach ($viewModel["joins"] as $joinName => $join) {
         if (isset($joinsUsed[$join["srcTable"]]) && $joinsUsed[$join["srcTable"]]) {
            continue;
         }
         if (isset($joinsUsed[$joinName]) && $joinsUsed[$joinName]) {
            $joinsUsed[$join["srcTable"]] = true;
            $modif = true;
         }
      }
   }
   return $joinsUsed;
}

function filtersAffectedByUpdate($request, $operation) {
   $viewModel = $request["model"];
   $fieldsUsed = getFieldsUsedByTable($request);
   // Joins affected by the change of value of a field
   $joinsAffected = array();
   // We start to mark the joins directly affected
   foreach ($viewModel["joins"] as $joinName => $join) {
      $srcTable = $join["srcTable"];
      $srcField = $join["srcField"];
      if (isset($fieldsUsed[$srcTable]) && isset($fieldsUsed[$srcTable][$srcField])) {
         $joinsAffected[$joinName] = true;
      }
   }
   // We then propagate to the descendants of affected joins
   $joinsAffected = propagateJoinsUsedTowardsDest($viewModel, $joinsAffected);
   // We finally check which filters are affected by the update
   $filtersAffected = array();
   foreach ($request["filters"] as $filterName => $filterValue) {
      if (isset($viewModel["filters"][$filterName])) {
         $filter = $viewModel["filters"][$filterName];
         if (!filterIsUsed($viewModel, $filterName, $filterValue, null, $operation)) {
            continue;
         }
         if (!isset($filter["joins"]) || count($filter["joins"]) == 0) { // filter applies directly to the main table
            $filtersAffected[$filterName] = true;
         } else {
            foreach ((array) $filter["joins"] as $joinName) {
               if (isset($joinsAffected[$joinName]) && ($joinsAffected[$joinName])) {
                  $filtersAffected[$filterName] = true;
               }
            }
         }
      } else if (isset($viewModel["fields"][$filterName])) {
         $fieldTable = getFieldTable($viewModel, $filterName);
         if (isset($joinsAffected[$fieldTable]) || isset($fieldsUsed[$fieldTable][$filterName])) {
            $filtersAffected[$filterName] = true;
         }
      }
   }
   return $filtersAffected;
}

function filterIsUsed($viewModel, $filterName, $filterValue, $filtersUsed, $operation) {
   if (isset($viewModel["filters"][$filterName])) {
      $filter = $viewModel["filters"][$filterName];
      if (isset($filter["readOnly"]) && $filter["readOnly"] && ($operation != "select")) {
         return false;
      }
   }
   if (isset($filterValue["readOnly"]) && ($filterValue["readOnly"] == true) && ($operation != "select")) {
      return false;
   }
   if (isset($filterValue["modes"]) && (!isset($filterValue["modes"][$operation]) || !$filterValue["modes"][$operation])) {
      return false;
   }
   if ($filtersUsed === null) {
      return true;
   }
   return (isset($filtersUsed[$filterName]) && $filtersUsed[$filterName]);
}

function getJoinsUsed($request, $joinsMode = "read", $operation, $filtersUsed = null, $roles = null) {
   $viewModel = $request["model"];
   $joinsUsed = array();
   if ($joinsMode != "countOnly") {
      if (!is_array($viewModel["fields"])) { error_log(print_r($request, true)); }
      foreach ($viewModel["fields"] as $fieldAlias => $field) {
         if (($joinsMode == "write") && !hasFieldAccess($viewModel, $fieldAlias, "write", $roles)) {
            continue;
         }
         if (isset($field["tableName"])) {
            $joinsUsed[$field["tableName"]] = true;
         }
      }
   }
   foreach ($request["filters"] as $filterName => $filterValue) {
      if (!filterIsUsed($viewModel, $filterName, $filterValue, $filtersUsed, $operation)) {
         continue;
      }
      if (isset($viewModel["filters"][$filterName])) {
         $filter = $viewModel["filters"][$filterName];
         if (isset($filter["joins"])) {
            foreach ($filter["joins"] as $joinName) {
               $joinsUsed[$joinName] = true;
            }
         }
      } else if (isset($viewModel["fields"][$filterName])) {
         $field = $viewModel["fields"][$filterName];
         if (isset($field["tableName"])) {
            $joinsUsed[$field["tableName"]] = true;
         }
      }
   }
   return propagateJoinsUsedTowardsMainTable($viewModel, $joinsUsed);
}

function getDstTable($joinName, $join) {
   $dstTable = $joinName;
   if (isset($join["dstTable"])) {
      $dstTable = $join["dstTable"];
   }
   return $dstTable;
}

function getSqlJoinsFromUsed($viewModel, $joinsUsed, $aliasPrefix = "") {
   $sqlJoins = "";
   foreach ($viewModel["joins"] as $joinName => $join) {
      if (!isset($joinsUsed[$joinName])) {
         continue;
      }
      $dstTable = getDstTable($joinName, $join);
      if (isset($join["type"]) && ($join["type"] == "LEFT")) {
         $sqlJoins .= " LEFT";
      }
      if (isset($join['on'])) {
         $joinOn = str_replace("[PREFIX]", $aliasPrefix, $join["on"]);
         $sqlJoins .= " JOIN `".$dstTable."` AS `".$aliasPrefix.$joinName."` ON (".
            $joinOn.")";
      } else {
         $sqlJoins .= " JOIN `".$dstTable."` AS `".$aliasPrefix.$joinName."` ON (".
            "`".$aliasPrefix.$join["srcTable"]."`.`".$join["srcField"]."` = ".
            "`".$aliasPrefix.$joinName."`.`".$join["dstField"]."`) ";
      }
   }
   return $sqlJoins;
}

function getSqlJoins($request, $joinsMode, $operation, $aliasPrefix = "", $filtersUsed = null, $roles = null) {
   $joinsUsed = getJoinsUsed($request, $joinsMode, $operation, $filtersUsed, $roles);
   return getSqlJoinsFromUsed($request["model"], $joinsUsed, $aliasPrefix);
}

/*
  If filtersUsed is null, this means every filter in the request is used
*/
function getConditions($request, $operation, $prefixFields = "", $prefixTables = "", $filtersUsed = null) {
   $viewModel = $request["model"];
   $ID = getPrimaryKey($viewModel);
   $conditions = array();
   foreach ($request["filters"] as $filterName => $filterValue) {
      if (!filterIsUsed($viewModel, $filterName, $filterValue, $filtersUsed, $operation)) {
         continue;
      }
      if ($filterName === "recordID") {
         $conditions[] = "`".$prefixTables.$viewModel["mainTable"]."`.`".$ID."` = :".$prefixFields."recordID";
      } else if (isset($viewModel["filters"][$filterName]) && isset($viewModel["filters"][$filterName]["condition"])){
         $filter = $viewModel["filters"][$filterName];
         $newCondition = str_replace("[PREFIX]", $prefixTables, $filter["condition"]);
         $newCondition = str_replace("[PREFIX_FIELD]", $prefixFields, $newCondition);
         $conditions[] = $newCondition;
      } else if (isset($viewModel["fields"][$filterName])) {
         $tableName = getFieldTable($viewModel, $filterName);
         $fieldType = getFieldType($viewModel, $filterName);
         if ($fieldType == "string") {
            $operator = "LIKE";
         } else {
            $operator = "=";
         }
         $conditions [] = "`".$prefixTables.$tableName."`.`".$filterName."` ".$operator." :".$prefixFields.$filterName;
      }
   }
   return $conditions;
}

function getFieldsSelect($request) {
   $viewModel = $request["model"];
   $fieldsSelect = array();
   if (!isset($request["fields"]) || !count($request["fields"])) {
      die("No fields requested in request ".$request['name']);
   }
   foreach ($request["fields"] as $fieldAlias) {
      $field = $viewModel["fields"][$fieldAlias];
      if (isset($field["sql"])) {
         $fieldsSelect[] = $field["sql"]." AS `".$fieldAlias."`";
      } else {
         $tableName = getFieldTable($viewModel, $fieldAlias);
         $fieldName = getFieldName($viewModel, $fieldAlias);
         $fieldType = getFieldType($viewModel, $fieldAlias);
         if ($fieldType == "point") {
            $fieldsSelect[] = "AsText(`".$tableName."`.`".$fieldName."`) as `".$fieldAlias."`";
         } else {
            $fieldsSelect[] = "`".$tableName."`.`".$fieldName."` as `".$fieldAlias."`";
         }
      }
   }
   return $fieldsSelect;
}

function getFieldsUpdate($request, $roles, $newMainTable) {
   $viewModel = $request["model"];
   $fieldsUpdate = array();
   foreach ($request["fields"] as $fieldAlias) {
      if (isset($viewModel['fields'][$fieldAlias]) && isset($viewModel['fields'][$fieldAlias]['readOnly']) && $viewModel['fields'][$fieldAlias]['readOnly']) {
         continue;
      }
      $tableName = getFieldTable($viewModel, $fieldAlias);
      $fieldName = getFieldName($viewModel, $fieldAlias);
      if (!hasFieldAccess($viewModel, $fieldAlias, "write", $roles)) {
         error_log("getFileldsUpdate: illegal access to ".$tableName.".".$fieldName." by ".implode($roles, ","));
         return null;
      }
      $fieldUpdate = "`".$tableName."`.`".$fieldName."` = ";
      if ($newMainTable == "") {
         $fieldUpdate .= ":".$fieldAlias;
      } else {
         $fieldUpdate .= "`".$newMainTable."`.`".$fieldAlias."`";
      }
      $fieldsUpdate[] = $fieldUpdate;
   }
   return $fieldsUpdate;
}

function hasAutoIncrementID($viewModel) {
   $tableModel = getTableModel($viewModel["mainTable"]);
   return (!isset($tableModel["autoincrementID"]) || $tableModel["autoincrementID"]);
}

function getFieldsInsert($request, $roles, $newMainTable) {
   $viewModel = $request["model"];
   $fieldsInsert = array();
   foreach ($request["fields"] as $fieldAlias) {
      $tableName = getFieldTable($viewModel, $fieldAlias);
      $fieldName = getFieldName($viewModel, $fieldAlias);
      if (isset($viewModel['fields'][$fieldAlias]['readOnly']) && $viewModel['fields'][$fieldAlias]['readOnly']) {
         continue;
      }
      if (!hasFieldAccess($viewModel, $fieldAlias, "write", $roles)) {
         error_log("getFieldsInsert: illegal access to ".$tableName.".".$fieldName." by ".implode($roles, ","));
         return null;
      }
      $fieldsInsert[] = "`".$fieldName."`";
   }
   return $fieldsInsert;
}

function getFieldsSelectForUpdate($request, $roles) {
   $viewModel = $request["model"];
   $fieldsSelect = array();
   foreach ($request["fields"] as $fieldAlias) {
      if (isset($viewModel['fields'][$fieldAlias]) && isset($viewModel['fields'][$fieldAlias]['readOnly']) && $viewModel['fields'][$fieldAlias]['readOnly']) {
         continue;
      }
      $tableName = getFieldTable($viewModel, $fieldAlias);
      $fieldName = getFieldName($viewModel, $fieldAlias);
      if (isset($viewModel['fields'][$fieldAlias]['readOnly']) && $viewModel['fields'][$fieldAlias]['readOnly']) {
         continue;
      }
      if (!hasFieldAccess($viewModel, $fieldAlias, "write", $roles)) {
         error_log("getFieldsSelectForUpdate illegal access to ".$tableName.".".$fieldName." by ".implode($roles, ","));
         return null;
      }
      $type = getFieldType($viewModel, $fieldAlias);
      if ($type == "point") {
         $fieldsSelect[] = "GeomFromText(:".$fieldAlias.") AS `".$fieldAlias."`";
      } else {
         $fieldsSelect[] = ":".$fieldAlias." AS `".$fieldAlias."`";
      }
   }
   return $fieldsSelect;
}

function getSelectQuery($request, $joinsMode) {
   $viewModel = $request["model"];
   $ID = getPrimaryKey($viewModel);
   $sqlJoins = getSqlJoins($request, $joinsMode, "select");
   $conditions = getConditions($request, "select");
   if ($joinsMode == "countOnly") {
      $sqlFieldsSelect = "count(*) as `nbItems`";
   } else {
      $fieldsSelect = getFieldsSelect($request);
      $sqlFieldsSelect = "`".$viewModel["mainTable"]."`.`".$ID."`, ".implode($fieldsSelect, ", ");
   }

   if ($sqlFieldsSelect == '') {
      die("cannot determine the main table");
   }
   $query = "SELECT ".$sqlFieldsSelect." FROM `".$viewModel["mainTable"]."` ".$sqlJoins;
   if (count($conditions) > 0) {
      $query .= " WHERE ".implode($conditions, " AND ");
   }
   if ($joinsMode != "countOnly") {
      $query .= getGroupBy($request);
   }
   if (isset($request["orders"]) && ($joinsMode != "countOnly")) {
      $orders = array();
      foreach ($request["orders"] as $order) {
         $fieldName = $order["field"];
         $tableName = getFieldTable($viewModel, $fieldName);
         $dir = isset($order["dir"]) ? $order["dir"] : 'ASC';
         $orders[] = "`".$fieldName."` ".$dir;
      }
      $query .= " ORDER BY ".implode($orders, ", ");
   }
   return $query;
}

// The Old conditions are there to check that the user is allowed to modify these rows
// The New conditions are there to check that the new values are allowed
function getUpdateQuery($request, $roles, $filtersUsedForNewValues) {
   $viewModel = $request["model"];
   $ID = getPrimaryKey($viewModel);
   $sqlJoinsOld = getSqlJoins($request, "write", "update", "", null, $roles);
   $conditionsOld = getConditions($request, "update", "filterOld_");
   //error_log("filters used : ".json_encode($filtersUsedForNewValues));
   $sqlJoinsNew = getSqlJoins($request, "countOnly", "update", "new_", $filtersUsedForNewValues, $roles);
   $conditionsNew = getConditions($request, "update", "filterNew_", "new_", $filtersUsedForNewValues);
   $mainTable = $viewModel["mainTable"];
   $newMainTable = "new_".$mainTable;
   $conditions[] = "`".$mainTable."`.`".$ID."` = :".$ID;
   $conditions = array_merge($conditions, $conditionsOld, $conditionsNew);
   $fieldsUpdate = getFieldsUpdate($request, $roles, $newMainTable);
   $selectNewValues = getFieldsSelectForUpdate($request, $roles);
   if ($selectNewValues == null) {
      error_log("selectedNewValues is null");
      return null;
   }
   $query = "UPDATE `".$mainTable."` ".$sqlJoinsOld.", (SELECT ".implode($selectNewValues, ", ").") as `" .$newMainTable."` ".$sqlJoinsNew." SET ".implode($fieldsUpdate, ", ")." WHERE ".implode($conditions, " AND ");
   //error_log($query);
   return $query;
}

function getDeleteQuery($request, $roles) {
   $viewModel = $request["model"];
   $ID = getPrimaryKey($viewModel);
   $sqlJoinsOld = getSqlJoins($request, "read", "delete", "");
   $conditionsOld = getConditions($request, "delete", "filterOld_");
   //error_log("filters used : ".json_encode($filtersUsedForNewValues))."<br/>";
   $mainTable = $viewModel["mainTable"];
   $conditions[] = "`".$mainTable."`.`".$ID."` = :".$ID;
   $conditions = array_merge($conditions, $conditionsOld);
   return "DELETE `".$mainTable."` FROM `".$mainTable."` ".$sqlJoinsOld." WHERE ".implode($conditions, " AND ");
}

function getInsertQuery($request, $roles, $filtersUsedForNewValues) {
   $viewModel = $request["model"];
   $ID = getPrimaryKey($viewModel);
   $sqlJoinsNew = getSqlJoins($request, "write", "insert", "new_", $filtersUsedForNewValues, $roles);
   $conditionsNew = getConditions($request, "insert", "filterNew_", "new_", $filtersUsedForNewValues);
   $mainTable = $viewModel["mainTable"];
   $newMainTable = "new_".$mainTable;
   $fieldsInsert = getFieldsInsert($request, $roles, $newMainTable);
   $selectNewValues = getFieldsSelectForUpdate($request, $roles);
   if (!hasAutoincrementID($viewModel)) {
      $selectNewValues[] = ":".$ID." as `".$ID."`";
      $fieldsInsert[] = "`".$ID."`";
   }
   if ($selectNewValues == null) {
      $selectNewValues = array("NULL as `".$ID."`");
      $fieldsInsert = array($ID);
   }
   $selectQuery = "SELECT `".$newMainTable."`.* FROM (SELECT ".implode($selectNewValues, ", ").") as `" .$newMainTable."` ".$sqlJoinsNew;
   if (count($conditionsNew) > 0) {
      $selectQuery .= "WHERE ".implode($conditionsNew, " AND ");
   }
   $insertQuery = "INSERT IGNORE INTO `".$mainTable."` (".implode($fieldsInsert, ",").") (".$selectQuery.")";
   return $insertQuery;
}

function getLimits($request, $nbItems) {
   $rowsPerPage = $request["rowsPerPage"];
   $page = $request["page"];
   if( $nbItems > 0 && $rowsPerPage > 0) {
      $nbPages = ceil($nbItems / $rowsPerPage);
   } else {
      $nbPages = 0;
   }
   if ($page > $nbPages)
      $page = $nbPages;
   $startRow = ($page - 1) * $rowsPerPage;
   if($startRow < 0)
      $startRow = 0;
   return array("startRow" => $startRow, "rowsPerPage" => $rowsPerPage, "nbPages" => $nbPages, "page" => $page);
}

function hasFieldAccess($viewModel, $fieldAlias, $accessType, $roles) {
   if (isset($viewModel["fields"][$fieldAlias]["access"])) {
      $access = $viewModel["fields"][$fieldAlias]["access"];
   } else {
      $tableName = getFieldTable($viewModel, $fieldAlias);
      $fieldName = getFieldName($viewModel, $fieldAlias);
      $tableModel = getTableModel($tableName);
      if (isset($tableModel["fields"][$fieldName]) && isset($tableModel["fields"][$fieldName]["access"])) {
         $access = $tableModel["fields"][$fieldName]["access"];
      } else {
         return false;
      }
   }
   foreach ($roles as $role) {
      if ($access[$accessType] && in_array($role, $access[$accessType])) {
         return true;
      }
   }
   return false;
}

function getTableModel($modelName) {
   global $tablesModels;
   return $tablesModels[$modelName];
}

function getViewModel($modelName) {
   global $viewsModels;
   return $viewsModels[$modelName];
}

function selectRows($db, $request) {
   $ID = getPrimaryKey($request["model"]);
   $limits = array();
   $query = getSelectQuery($request, "read");
   $viewModel = $request["model"];
   if (isset($request["rowsPerPage"])) {
      $countQuery = getSelectQuery($request, "countOnly");
      $stmt = $db->prepare($countQuery);
      $stmt->execute(getSelectExecValues($request));
      $nbItems = 0;
      if ($row = $stmt->fetchObject()) {
         $nbItems = $row->nbItems;
      }
      $limits = getLimits($request, $nbItems);
      $query .= " LIMIT ".$limits["startRow"].", ".$limits["rowsPerPage"];
   }
   //error_log($query." ".json_encode($request["filters"]));
   $stmt = $db->prepare($query);
   $stmt->execute(getSelectExecValues($request));
   $items = array();
   while ($row = $stmt->fetchObject()) {
      $items[$row->$ID] = $row;
   }
   if (count($limits) == 0) {
      $nbItems = count($items);
   }
   return array("items" => $items, "nbTotalItems" => $nbItems, "limits" => $limits);
}

function getSelectExecValues($request) {
   $values = array();
   foreach ($request["filters"] as $filterName => $filterValue) {
      if (!filterIsUsed($request["model"], $filterName, $filterValue, null, "select")) {
         continue;
      }
      if (gettype($filterValue) == 'array') {
         foreach((array)$filterValue["values"] as $valueName => $value) {
            $values[$valueName] = $value;
         }
      } else if (!isset($request["model"]["filters"][$filterName]["ignoreValue"]) ||
            $request["model"]["filters"][$filterName]["ignoreValue"] != true) {
         $values[$filterName] = $filterValue;
      }
   }
   return $values;
}

function addFilterValues($viewModel, $filterName, $filterValue, $prefix, &$values) {
   if (!isset($viewModel["filters"][$filterName]) || isset($viewModel["filters"][$filterName]['ignoreValue'])) {
      return;
   }
   if (gettype($filterValue) == 'array') {
      foreach($filterValue['values'] as $valueName => $value) {
         $values[$prefix.$valueName] = $value;
      }
   } else {
      $values[$prefix.$filterName] = $filterValue;
   }
}

function filterUsesValue($viewModel, $filterName) {
   if (!isset($viewModel["filters"][$filterName])) {
      return true;
   }
   $filter = $viewModel["filters"][$filterName];
   if (isset($filter["ignoreValue"])) {
      return false;
   }
   return true;
}

function callTableListener($db, $tableName, $type) {
   $tableModel = getTableModel($tableName);
   //error_log("callTableListener ".$tableName." ".$type);
   if (isset($tableModel["listeners"][$type])) {
      call_user_func($tableModel["listeners"][$type], $db);
   }
}

$listenersToCall = array("after" => array());
function markListenersToCall($viewModel, $joinsUsed, $type) {
   global $listenersToCall;
   foreach ($viewModel["joins"] as $joinName => $join) {
      if (!isset($joinsUsed[$joinName])) {
         continue;
      }
      $dstTableName = getDstTable($joinName, $join);
      $listenersToCall[$type][$dstTableName] = true;
   }
   $listenersToCall[$type][$viewModel["mainTable"]] = true;
}

function callListeners($db, $type) {
   global $listenersToCall;
   foreach ($listenersToCall[$type] as $tableName => $nothing) {
      callTableListener($db, $tableName, $type);
   }
}

function updateRows($db, $request, $roles) {
   if (count($request["records"]) == 0) {
      return;
   }
   $viewModel = $request["model"];
   $ID = getPrimaryKey($viewModel);
   $filtersUsedForNewValues = filtersAffectedByUpdate($request, "update");
   $query = getUpdateQuery($request, $roles, $filtersUsedForNewValues);
   if ($query == null) {
      return false;
   }
   $stmt = $db->prepare($query);
   foreach ($request["records"] as $record) {
      $values = array($ID => $record[$ID]);
      //error_log(json_encode($request["fields"])." ".json_encode($record));
      foreach ($request["fields"] as $fieldName) {
         if (isset($viewModel['fields'][$fieldName]) && isset($viewModel['fields'][$fieldName]['readOnly']) && $viewModel['fields'][$fieldName]['readOnly']) {
            continue;
         }
         if (isset($viewModel['fields'][$fieldName]) && isset($viewModel['fields'][$fieldName]['imposedWriteValue'])) {
            $values[$fieldName] = $viewModel['fields'][$fieldName]['imposedWriteValue'];
         } else {
            $values[$fieldName] = $record["values"][$fieldName];
         }
      }
      foreach ($request["filters"] as $filterName => $filterValue) {
         if (!filterIsUsed($viewModel, $filterName, $filterValue, null, "update")) {
            continue;
         }
         addFilterValues($viewModel, $filterName, $filterValue, 'filterOld_', $values);
         if (isset($filtersUsedForNewValues[$filterName])) {
            addFilterValues($viewModel, $filterName, $filterValue, 'filterNew_', $values);
         }
      }
      if (isset($request['debugLogFunction'])) {
         $request['debugLogFunction']($query, $values, 'update');
      }
      $stmt->execute($values);
   }
   $joinsUsed = getJoinsUsed($request, "read", "update", $filtersUsedForNewValues, $roles);
   markListenersToCall($request["model"], $joinsUsed, "after");
   return true;
}

function insertRows($db, $request, $roles) {
   if (count($request["records"]) == 0) {
      return array();
   }
   $viewModel = $request["model"];
   $ID = getPrimaryKey($viewModel);
   $filtersUsedForNewValues = filtersAffectedByUpdate($request, "insert");
   $query = getInsertQuery($request, $roles, $filtersUsedForNewValues);
   //error_log("insertRows query : ".$query." request : ".json_encode($request));
   $stmt = $db->prepare($query);
   $insertIDs = array();
   foreach ($request["records"] as $key => $record) {
      $values = array();
      foreach ($request["fields"] as $fieldName) {
         if (isset($record["values"][$fieldName])) {
            $values[$fieldName] = $record["values"][$fieldName];
         } else if (!isset($viewModel['fields'][$fieldName]['readOnly']) || !$viewModel['fields'][$fieldName]['readOnly']) {
            if (isset($viewModel['fields'][$fieldName]) && isset($viewModel['fields'][$fieldName]['imposedWriteValue'])) {
               $values[$fieldName] = $viewModel['fields'][$fieldName]['imposedWriteValue'];
            } else {
               $values[$fieldName] = $record["values"][$fieldName];
            }
         }
      }
      if (!hasAutoincrementID($viewModel) && $ID) {
         $values[$ID] = (isset($record[$ID]) && $record[$ID]) ? $record[$ID] : getRandomID();
      }
      foreach ($request["filters"] as $filterName => $filterValue) {
         if (!filterIsUsed($viewModel, $filterName, $filterValue, null, "insert")) {
            continue;
         }
         if (isset($filtersUsedForNewValues[$filterName])) {
            addFilterValues($viewModel, $filterName, $filterValue, 'filterNew_', $values);
         }
      }
      if (isset($request['debugLogFunction'])) {
         $request['debugLogFunction']($query, $values, 'insert');
      }
      if (!$stmt->execute($values)) {
         error_log("execution failed");
      }
      if ($stmt->rowCount() == 0) {
         error_log("nothing inserted ".json_encode($record));
         error_log($query);
         error_log(json_encode($values));
         $insertIDs[$key] = 0;
      } else {
         if (!hasAutoincrementID($viewModel) && $ID) {
            $insertIDs[$key] = $ID ? $values[$ID] : null;
         } else {
            $insertIDs[$key] = $db->lastInsertId();
         }
      }
   }
   $joinsUsed = getJoinsUsed($request, "read", "insert", $filtersUsedForNewValues, $roles);
   markListenersToCall($request["model"], $joinsUsed, "after");
   return $insertIDs;
}

function deleteRows($db, $request, $roles) {
   $viewModel = $request["model"];
   $ID = getPrimaryKey($viewModel);
   $query = getDeleteQuery($request, $roles);
   $stmt = $db->prepare($query);
   foreach ($request["records"] as $record) {
      $values = array($ID => $record[$ID]);
      foreach ($request["filters"] as $filterName => $filterValue) {
         if (!filterIsUsed($viewModel, $filterName, $filterValue, null, "delete")) {
            continue;
         }
         addFilterValues($viewModel, $filterName, $filterValue, 'filterOld_', $values);
      }
      if (isset($request['debugLogFunction'])) {
         $request['debugLogFunction']($debugQuery, $values, 'delete');
      }
      $stmt->execute($values);
   }
   markListenersToCall($request["model"], array(), "after");
}

function deleteRow($db, $modelName, $record) {
   $viewModel = getViewModel($modelName);
   $ID = getPrimaryKey($viewModel);
   $stmtDelete = $db->prepare("DELETE FROM `".$modelName."` WHERE `".$ID."` = :recordID");
   $stmtDelete->execute(array("recordID" => $record[$ID]));
}

?>
