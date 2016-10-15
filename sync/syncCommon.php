<?php
/* Copyright (c) 2013 Apycat / Association France-ioi, MIT License http://opensource.org/licenses/MIT */

require_once __DIR__."/../modelsManager/modelsManager.php";
require_once __DIR__."/../modelsManager/modelsManagerVersions.php";
require_once __DIR__."/../../shared/models.php";

if (file_exists( __DIR__."/../../shared/debug.php")) {
   include_once __DIR__."/../../shared/debug.php"; // not required
} else {
   function syncDebug($type, $b_or_e, $subtype='') {}
}

if(!function_exists('array_utf8')) {
    function array_utf8($data) {
        // Can't use array_walk_recursive when there are objects in the array
        if (is_array($data) || is_object($data)) {
            $newdata = array();
            foreach((array) $data as $key => $val) {
                $newdata[$key] = array_utf8($val);
            }
            return $newdata;
        } elseif (is_string($data)) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        } else {
            return $data;
        }
    }
}

if(!function_exists('json_encode_safe')) {
    function json_encode_safe($data) {
        // If json_encode doesn't work, try again removing all invalid UTF-8
        // characters
        $result = '';

        try {
            $result = json_encode($data);
        } catch (Exception $e) {}

        if ($result == '') {
            $result = json_encode(array_utf8($data));
        }
        return $result;
    }
}

function syncUpdateVersions($db, $lastServerVersion) {
   $query = "UPDATE `synchro_version` SET `iLastServerVersion` = :lastServerVersion, `iLastClientVersion` = `iVersion`";
   $stmt = $db->prepare($query);
   $stmt->execute(array("lastServerVersion" => $lastServerVersion));
}

function syncGetVersion($db) {
   $query = "SELECT ROUND(UNIX_TIMESTAMP(CURTIME(2)) * 10);";
   $stmt = $db->query($query);
   return $stmt->fetchColumn();
}

function createViewModelFromTable($tableName) {
   global $viewsModels;
   $viewModel = null;
   $tableModel = getTableModel($tableName);
   if (isset($viewsModels) && isset($viewsModels[$tableName])) {
      $viewModel = $viewsModels[$tableName];
   } else {
      $viewModel = array("mainTable" => $tableName, "joins" => array(), "fields" => $tableModel["fields"], "filters" => array());
   }
   return $viewModel;

}

function syncGetTablesRequests($tables = null, $useCount = true) {
   global $tablesModels;
   $requests = array();
   foreach ($tablesModels as $tableName => $tableModel) {
      if (isset($tableModel["hasHistory"]) && (!$tableModel["hasHistory"])) {
         continue;
      }
      if (($tables != null) && (!isset($tables[$tableName]))) {
         continue;
      }
      $viewModel = createViewModelFromTable($tableName);
      if (!$viewModel) {
         error_log('cannot create view model from table '.$tableName);
      }
      $requests[$tableName] = array(
         "modelName" => $tableName,
         "model" => $viewModel,
         "fields" => getViewModelFieldsList($viewModel),
         "filters"  => array(),
         "countRows" => $useCount
      );
   }
   return $requests;
}

function syncGetCounts($db, $requests, $minVersion, $maxVersion, $maxVersionIsDefault) {
   $allCounts = array();
   foreach ($requests as $requestName => $request) {
      if (isset($request["countRows"]) && !$request["countRows"]) {
         continue;
      }
      $curMinVersion = $minVersion;
      if (isset($request["minVersion"])) {
         $curMinVersion = $request["minVersion"];
      }
      $requestCounts = getChangesCountSince($db, $request, $curMinVersion, $maxVersion, $maxVersionIsDefault);
      $allCounts[$requestName] = $requestCounts;
   }
   return $allCounts;
}

function runBeforeSelectListeners($db, $requests) {
   $allJoinsUsed = array();
   foreach ($requests as $requestName => $request) {
      $allJoinsUsed = getJoinsUsed($request, "read", "select");
   }
   foreach ($allJoinsUsed as $tableName => $isUsed) {
      callTableListener($db, $tableName, "before");
   }
}

function syncGetChanges($db, $requests, $minVersion, $maxVersion, $maxChanges, $maxVersionIsDefault) {
   $allChanges = array("requestSets" => array());
   $nbChanges = 0;
   foreach ($requests as $requestName => $request) {
      if (!$request || !is_array($request)) {
         error_log('something is wrong with request '.json_encode($request, true));
         continue;
      }
      if (isset($request['writeOnly']) && $request['writeOnly']) {
         continue;
      }
      syncDebug('getChanges', 'begin', $requestName);

      $curMinVersion = $minVersion;
      if (isset($request["minVersion"])) {
         $curMinVersion = $request["minVersion"];
      }
      $markRequest = isset($request["markRequest"]) ? $request["markRequest"] : false;
      if (!isset($request["getChanges"]) || $request["getChanges"]) {
         $requestChanges = getChangesSince($db, $request, $curMinVersion, $maxVersion, $requestName, $markRequest, $maxVersionIsDefault);
      } else {
         $requestChanges = null;
      }
      $modelName = $request["modelName"];
      if (isset($request["requestSet"])) {
         $setName = $request["requestSet"]["name"];
         if (!isset($allChanges["requestSets"][$setName])) {
            $allChanges["requestSets"][$setName] = array();
         }
         $changesContainer = &$allChanges["requestSets"][$setName];
      } else {
         $changesContainer = &$allChanges;
      }
      if ($requestChanges != null) {
         $types = array("inserted", "updated", "deleted");
         foreach ($types as $type) {
            $rows = $requestChanges[$type];
            foreach ($rows as $rowID => $row) {
               $nbChanges++;
            }
         }
         if (!isset($changesContainer[$modelName])) {
            $changesContainer[$modelName] = $requestChanges;
         } else {
            $prevChanges = $changesContainer[$modelName];
            foreach ($types as $type) {
               $rows = $requestChanges[$type];
               foreach ($rows as $rowID => $row) {
                  if (!isset($prevChanges[$rowID])) {
                     $changesContainer[$modelName][$type][$rowID] = $row;
                  }
               }
            }
         }
      }
      if (($nbChanges > $maxChanges) && ($maxVersion > $minVersion + 1)) {
         error_log("Too many changes for request ".$requestName." (".$nbChanges.") ".$minVersion."-".$maxVersion);
         return null;
      }
      syncDebug('getChanges', 'end', $requestName);
   }
   return $allChanges;
}

function httpPost($serverUrl, $data) {
   // use key 'http' even if you send the request to https://...
   $options = array(
       'http' => array(
           'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
           'method'  => 'POST',
           'content' => http_build_query($data),
       ),
   );
   $context  = stream_context_create($options);
   echo json_encode_safe($data);
   return file_get_contents($serverUrl, false, $context);
}

function syncApplyInserts($db, $viewModel, $tableName, $fieldNames, $rows) {
   $ID = getPrimaryKey($viewModel);
   $fields = array("`".$ID."`");
   $placeHolders = array(":".$ID);
   foreach ($fieldNames as $fieldName) {
      $fields[] = "`".$fieldName."`";
      $placeHolders[] = ":".$fieldName;
   }
   $query = "INSERT IGNORE INTO `".$tableName."` (".implode($fields, ",").") VALUES (".implode($placeHolders, ",").")";
   $stmt = $db->prepare($query);
   $rowsToUpdate = array();
   foreach ($rows as $row) {
      //error_log($query."".json_encode($row));
      $row = (array)$row;
      $stmt->execute((array)$row["data"]);
      if ($stmt->rowCount() == 0) {
         $rowsToUpdate[] = $row;
      }
   }
   return $rowsToUpdate;
}

function syncApplyUpdates($db, $viewModel, $tableName, $fieldNames, $rows) {
   $ID = getPrimaryKey($viewModel);
   $fieldUpdates = array();
   foreach ($fieldNames as $fieldName) {
      $fieldUpdates[] = "`".$fieldName."` = :".$fieldName;
   }
   $query = "UPDATE `".$tableName."` SET ".implode($fieldUpdates, ",")." WHERE `".$ID."` = :".$ID;
   $stmt = $db->prepare($query);
   foreach ($rows as $rowID => $row) {
      //error_log($query."\r\n".json_encode($row));
      $row = (array)$row;
      $stmt->execute((array)$row["data"]);
   }
}

function syncApplyDeletes($db, $viewModel, $tableName, $rows) {
   $ID = getPrimaryKey($viewModel);
   $query = "DELETE FROM `".$tableName."` WHERE `".$ID."` = :".$ID;
   $stmt = $db->prepare($query);
   foreach ($rows as $rowID => $row) {
      $stmt->execute(array($ID => $rowID));
   }
}

function syncGetRecords($request, $rows) {
   $viewModel = $request["model"];
   $ID = getPrimaryKey($viewModel);
   $fields = getViewModelFieldsList($viewModel);
   $records = array();
   foreach ($rows as $rowID => $row) {
      $row = (array)$row;
      foreach ($row["data"] as $fieldName => $value) {
         $fieldType = getFieldType($viewModel, $fieldName);
         if (($fieldType == "datetime") || ($fieldType == "date") || ($fieldType == "time")) {
            if ($value == '') {
               $row["data"][$fieldName] = NULL;
            }
         }
         if (($fieldType != "string") && ($value == "null")) {
            $row["data"][$fieldName] = NULL;
         }
      }
      $records[$rowID] = array($ID => $rowID, "values" => $row["data"]);
   }
   return $records;
}

function syncGetRecordsIds($request, $rows) {
   $records = array();
   $viewModel = $request["model"];
   $ID = getPrimaryKey($viewModel);
   foreach ($rows as $rowID => $row) {
      $records[] = array($ID => $rowID);
   }
   return $records;
}

function syncApplyChangesSafe($db, $requests, $changes, $roles, $lowPriority=false) {
   foreach ($changes as $modelName => $requestChanges) {
      $modelName = strtolower($modelName);
      if (!isset($requests[$modelName]) || !$requests[$modelName]) {
         error_log('syncApplyChangesSafe: no request for model '.$modelName);
         continue;
      }
      if (isset($requests[$modelName]['readOnly']) && $requests[$modelName]['readOnly']) {
         continue;
      }
      if ((isset($requests[$modelName]['lowPriority']) && $requests[$modelName]['lowPriority'] && !$lowPriority) ||
		  ($lowPriority && (!isset($requests[$modelName]['lowPriority']) || !$requests[$modelName]['lowPriority']))) {
         continue;
      }
      if (is_string($requestChanges)) {
         $requestChanges = json_decode($requestChanges, true);
      }
      $requestChanges = (array)$requestChanges;
      $request = $requests[$modelName]; // TODO : might not always be the case!!
      if (!$request) {
         error_log('cannot find proper request for modelName '.$modelName);
         continue;
      }
      set_time_limit(0);
      if (isset($requestChanges["inserted"])) {
         $request["records"] = syncGetRecords($request, $requestChanges["inserted"]);
         //error_log("records : ".json_encode($request["records"]));
         $insertedIDs = insertRows($db, $request, $roles);
         $updRecords = array();
         foreach ($insertedIDs as $key => $insertedID) {
            if ($insertedID == 0) {
               $updRecords[] = $request["records"][$key];
            }
         }
         $request["records"] = $updRecords;
         updateRows($db, $request, $roles);
      }
      if (isset($requestChanges["updated"])) {
         $request["records"] = syncGetRecords($request, $requestChanges["updated"]);
         updateRows($db, $request, $roles);
      }
      if (isset($requestChanges["deleted"])) {
         $request["records"] = syncGetRecordsIDs($request, $requestChanges["deleted"]);
         deleteRows($db, $request, $roles);
      }
   }
   callListeners($db, "after");
}

function syncApplyChanges($db, $requests, $changes) {
   foreach ($changes as $modelName => $requestChanges) {
      if ($modelName == "requestSets") {
         continue;
      }
      if (is_string($requestChanges)) {
         $requestChanges = json_decode($requestChanges, true);
      }
      if ($modelName === "config") {
         continue;
      }
      if (!isset($requests[$modelName])) {
         error_log('trying to apply a change in '.$modelName.', but no associated request!');
         continue;
      }
      $request = $requests[$modelName]; // TODO : might not always be the case!!
      if (!$request) {
         error_log('cannot find proper request for modelName '.$modelName);
         return;
      }
      if (isset($request['readOnly']) && $request['readOnly']) {
         continue;
      }
      $viewModel = $request["model"];
      $fields = getViewModelFieldsList($viewModel);
      set_time_limit(0);
      $tableName = $modelName; // TODO : might not always be the case!!
      $requestChanges = (array)$requestChanges;
      if (isset($requestChanges["inserted"])) {
         $rowsToUpdate = syncApplyInserts($db, $viewModel, $tableName, $fields, $requestChanges["inserted"], $request);
         syncApplyUpdates($db, $viewModel, $tableName, $fields, $rowsToUpdate);
      }
      if (isset($requestChanges["updated"])) {
         $rowsToUpdate = syncApplyInserts($db, $viewModel, $tableName, $fields, $requestChanges["updated"], $request);
         syncApplyUpdates($db, $viewModel, $tableName, $fields, $rowsToUpdate);
      }
      if (isset($requestChanges["deleted"])) {
         syncApplyDeletes($db, $viewModel, $tableName, $requestChanges["deleted"]);
      }
   }
}

?>
