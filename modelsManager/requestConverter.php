<?php
/* Copyright (c) 2013 Apycat / Association France-ioi, MIT License http://opensource.org/licenses/MIT */

require_once(__DIR__."/../../shared/connect.php");
require_once(__DIR__."/../sync/syncCommon.php");
require_once(__DIR__."/versionedRequestsManager.php");
require_once(__DIR__."/modelsTools.inc.php");

class RequestConverter {
   private $request;
   private $tableAliasToPathKey;
   private $pathKeyToTableAlias;

   function __construct($request) {
      $this->request = $request;
      $this->initPathKeyAndAliases();
   }

   function initPathKeyAndAliases() {
      $this->tableAliasToPathKey = array();
      $this->pathKeyToTableAlias = array();
      if (isset($this->request["pathsToTablesAliases"])) {
         foreach ($this->request["pathsToTablesAliases"] as $tableAlias => $path) {
            $pathKey = $this->getPathKey($path);
            $this->pathKeyToTableAlias[$pathKey] = $tableAlias;
            $this->tableAliasToPathKey[$tableAlias] = $pathKey;
         };
      }
   }

   function getPathKey($path) {
      $pathKey = "mainTable";
      foreach ($path as $joinName) {
         $pathKey .= "->" . $joinName;
      }
      return $pathKey;
   }

   function createAlias($tableName) {
      $tableAlias = $tableName;
      $numAlias = 2;
      while (isset($this->tableAliasToPathKey[$tableAlias])) {
         $tableAlias = $tableName . "_" . $numAlias;
         $numAlias++;
      }
      return $tableAlias;
   }

   function getTableInfosFromPath($path) {
      $tableName = $this->request["mainTable"];
      $tableModel = $this->request["tablesModels"][$tableName];
      foreach ($path as $joinName) {
         $tableName = $tableModel["joins"][$joinName]["dstTable"];
         $tableModel = $this->request["tablesModels"][$tableName];
      }
      $pathKey = $this->getPathKey($path);
      if (!isset($this->pathKeyToTableAlias[$pathKey])) {
         $tableAlias = $this->createAlias($tableName);
         $this->tableAliasToPathKey[$tableAlias] = $pathKey;
         $this->pathKeyToTableAlias[$pathKey] = $tableAlias;
      } else {
         $tableAlias = $this->pathKeyToTableAlias[$pathKey];
      }
      return array(
         "pathKey" => $pathKey,
         "name" => $tableName,
         "alias" => $tableAlias,
         "model" => $tableModel
      );
   }

   function getRequestFieldsSQL() {
      $fieldsSQL = array();
      foreach ($this->request["fields"] as $fieldAlias => $fieldInfos) {
         if (isset($fieldInfos["tableAlias"])) {
            $tableAlias = $fieldInfos["tableAlias"];
            $path = $this->request["pathsToTablesAliases"][$tableAlias];
         } else {
            $path = array();
         }
         $tableInfos = $this->getTableInfosFromPath($path);
         $fieldName = $fieldInfos["field"];
         $field = $tableInfos["model"]["fields"][$fieldName];
         if (isset($field["sql"])) {
             $sql = $field["sql"];
         } else {
            $fieldType = $field["type"];
            if ($fieldType == "point") {
               $sql = "AsText(`".$tableInfos["alias"]."`.`".$fieldName."`)";
            } else {
               $sql = "`".$tableInfos["alias"]."`.`".$fieldName."`";
            }
         }
         $fieldsSQL[$fieldAlias] = $sql;
      }
      return $fieldsSQL;
   }

   function getMainTableAlias() {
      $pathKey = $this->getPathKey(array());
      return $this->pathKeyToTableAlias[$pathKey];
   }

   function getFieldsByTableAlias($request) {
      $fieldsByTableAlias = array();
      foreach ($request["fields"] as $fieldName => $fieldInfos) {
         if (isset($fieldInfos["tableAlias"])) {
            $tableAlias = $fieldInfos["tableAlias"];
         } else {
            $tableAlias = $this->getMainTableAlias();
         }
         if (!isset($fieldsByTableAlias)) {
            $fieldsByTableAlias[$tableAlias] = array();
         }
         $fieldsByTableAlias[$tableAlias][$fieldInfos["field"]] = true;
      }
      return $fieldsByTableAlias;
   }

   function getTableInfosFromalias($tableAlias) {
      $pathToTable = $this->request["pathsToTablesAliases"][$tableAlias];
      return $this->getTableInfosFromPath($pathToTable);
   }

   function conditionsAffectedByUpdate($operation) {
      $tablesModels = $this->request["tablesModels"];
      $fieldsUsedByTableAlias = getFieldsByTableAlias();

      $tablesUsed = $this->getTablesUsed("read", "select"); // TODO : traiter le cas des updates
      foreach ($tablesUsed as $tableAlias => $isUsed) {
         $curTableAdded = false;
         $pathToTable = $this->request["pathsToTablesAliases"][$tableAlias];
         $tableInfos = $this->getTableInfosFromalias($tableAlias);
         $tablesAliasesToAdd = array();
         foreach ($tableInfos["model"]["joins"] as $joinName => $join) {
            $srcField = $join["srcField"];
            if (isset($fieldsUsedByTableAlias[$tableAlias]) && isset($fieldsUsedByTableAlias[$tableAlias][$srcField])) {
               if (!$curTableAdded) {
                  $tablesAliasesToAdd[] = $tableAlias;
                  $curTableAdded = true;
               }
               $curPath = $pathToTable;
               $curPath[] = $join["dstTable"];
               $dstTableInfos = $this->getTableInfosFromPath($curPath);
               $tablesAliasesToAdd[] = $dstTableInfos["alias"];
            }
         }
      }
      $joinedTablesAliases = array();
      while (!empty($tablesAliasToAdd)) {
         $tableAlias = array_pop($tablesAliasToAdd);
         if (!isset($tablesUsed[$tableAlias])) {
            continue;
         }
         $joinedTablesAliases[] = $tableAlias;
         $pathToTable = $this->request["pathsToTablesAliases"][$tableAlias];
         $tableInfos = $this->getTableInfosFromalias($tableAlias);
         $tableModel = $tableInfos["model"];
         foreach ($tableModel["joins"] as $joinName => $join) {
            $curPath = $pathToTable;
            $curPath[] = $join["dstTable"];
            $dstTableInfos = $this->getTableInfosFromPath($curPath);
            $tablesAliasesToAdd[] = $dstTableInfos["alias"];
         }
      }
      // une fois qu'on a la liste des tableAlias jointes, on peut juste exécuter la suite de getRequestJins


      
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

   function getTablesUsed($joinsMode = "read", $operation) {
      // TODO: handle case where $operation = "update"
      // => include joins for filters and conditions that might be impacted by update
      // => two cases for updates :
      // conditions on new values to check if they are acceptables
      // conditions on old values to check if we are allowed to touch these records
      // => for new values, we only need to check what can be impacted by the provided values
      // for old values, we need to check everything
      // TODO: count will not work if there are joins that prevent some records from the main table
      // or add multiple versions of the same record from the main table !
      // => find a way to make sure it works by defining the type of join ? (n => 1 or n => 0,1)
      $tablesUsed = array();
      if ($joinsMode != "countOnly") {
         foreach ($this->request["fields"] as $fieldAlias => $fieldInfos) {
            if (isset($fieldInfos["tableAlias"])) {
               $tablesUsed[$fieldInfos["tableAlias"]] = true;
            }
         }
      }
      foreach ($this->request["conditions"] as $condition) {
         foreach ($condition["tablesAliases"] as $tableAlias) {
            $tablesUsed[$tableAlias] = true;
         }
      }
      return $tablesUsed;
   }

   function createJoin($prevTableInfos, $joinName, $nextTableInfos) {
      $modelJoin = $prevTableInfos["model"]["joins"][$joinName];
      $newJoin = array();
      if (isset($modelJoin["type"])) {
         $newJoin["type"] = $modelJoin["type"];
      }
      $newJoin["dstTable"] = $nextTableInfos["name"];
      $newJoin["aliasSrcTable"] = $prevTableInfos["alias"];
      if (isset($modelJoin["on"])) {
         $newJoin["joinCondition"] = $modelJoin["on"];
      } else {
         $newJoin["joinCondition"] =  "`".$prevTableInfos["alias"]."`.`".$modelJoin["srcField"]."` = ".
            "`".$nextTableInfos["alias"]."`.`".$modelJoin["dstField"]."`";
      }
      return $newJoin;
   }

   function getRequestJoins() {
      $tablesUsed = $this->getTablesUsed("read", "select"); // TODO : traiter le cas des updates
      $joins = array();
      foreach ($tablesUsed as $tableAlias => $isUsed) {
         $pathToTable = $this->request["pathsToTablesAliases"][$tableAlias];
         $prevTableInfos = $this->getTableInfosFromPath(array());
         for ($iStep = 0; $iStep < count($pathToTable); $iStep++) {
            $curPath = array_slice($pathToTable, 0, ($iStep + 1));
            $nextTableInfos = $this->getTableInfosFromPath($curPath);

            if (!isset($joins[$nextTableInfos["pathKey"]])) {
               $joinName = $pathToTable[$iStep];
               $joins[$nextTableInfos["alias"]] = $this->createJoin($prevTableInfos, $joinName, $nextTableInfos);
            }
            $prevTableInfos = $nextTableInfos;
         }
      }
      return $joins;
   }

   public function getConditions() {
      $conditions = array();
      foreach ($this->request["conditions"] as $condition) {
         $conditions[] = $condition["sql"];
      }
      return $conditions;
   }

/*
   function getGroupBy() {
      $groupBys = array();
      foreach ($this->request["model"]["fields"] as $field) {
         if (isset($field["groupBy"])) {
            $groupBys[]  = $field["groupBy"];
         }
      }
      if (count($groupBys) > 0) {
         return " GROUP BY ".implode($groupBys, ", ");
      }
      return "";
   }
*/

   public function getDiffRequest() {
      return array(
         "joins" => $this->getRequestJoins(),
         "select" => $this->getRequestFieldsSQL(),
         "mainTable" => $this->request["mainTable"],
         "aliasMainTable" => $this->request["mainTable"],
         "primaryKey" => $this->request["tablesModels"][$this->request["mainTable"]]["primaryKey"],
         "conditions" => $this->getConditions(),
         //"groupBy" => $this->getGroupBy(),
         "orderBy" => ""
      );
   }
}
