<?php

/* This file provides a few functions of modelsManager.php, adapted to
 * AWS DynamoDB. There are strong restrictions, please read code before
 * using it.
 */

require_once(__DIR__.'/../shared/connect.php');
require_once(__DIR__.'/../shared/models.php');
require_once(__DIR__.'/../shared/tinyORM.php');

$tinyOrm = isset($tinyOrm) ? $tinyOrm : new tinyOrm();

// simpler version of insertRows from modelsManager, must be called after
// mysql version in order to get a good sync
function insertRowsDynamoDB($request, $roles, $insertedIDs) {
   global $tinyOrm, $tablesToSync;
   if (count($request["records"]) == 0) {
      return;
   }
   $table = $request['modelName'];
   if (! in_array($table, $tablesToSync)) {
      return;
   }
   $formattedRecords = array();
   $i = 0;
   foreach ($request['records'] as $record) {
      $record['values']['ID'] = $insertedIDs[$i];
      $i = $i+1;
      $formattedRecords[] = $record['values'];
   }
   return $tinyOrm->batchWriteDynamoDB($table, $formattedRecords);
}

// simpler version of deleteRow from modelsManager
function deleteRowDynamoDB($table, $record) {
   global $tinyOrm, $tablesToSync;
   if (count($record) == 0) {
      return;
   }
   if (! in_array($table, $tablesToSync)) {
      return;
   }
   return $tinyOrm->deleteDynamoDB($table, $record);
}

// simpler version of insertRows from modelsManager
function updateRowsDynamoDB($request, $roles) {
   global $tinyOrm, $tablesToSync;
   if (count($request["records"]) == 0) {
      return;
   }
   $table = $request['modelName'];
   if (! in_array($table, $tablesToSync)) {
      return;
   }
   $formattedRecords = array();
   foreach ($request['records'] as $record) {
      $record['values']['ID'] = $record['ID'];
      $formattedRecords[] = $record['values'];
   }
   return $tinyOrm->batchWriteDynamoDB($table, $formattedRecords);
}
