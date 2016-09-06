<?php

/* Copyright (c) 2013 Apycat / Association France-ioi, MIT License http://opensource.org/licenses/MIT */

require_once __DIR__."/../../shared/connect.php";
require_once __DIR__."/../../shared/models.php";

echo "Cleaning all history tables:<br/>";
foreach ($tablesModels as $tableName => $tableModel) {
   $query = "truncate history_$tableName;";
   echo $query."<br/>";
   $db->exec($query);
}

echo "<br/>Regenerating minimal history :<br/>";
foreach ($tablesModels as $tableName => $tableModel) {
   $fields = array_keys($tableModel['fields']);
   $fieldsStr = "`".implode('`, `', $fields)."`";
   $fieldsStrWithPrefix = "`".$tableName."`.`".implode("`, `".$tableName."`.`", $fields)."`";

   $query = "INSERT INTO `history_".$tableName."` (`ID`, ".$fieldsStr.", `bDeleted`, `iVersion`, `iNextVersion`) ".
      "(SELECT `".$tableName."`.`ID`, ".$fieldsStrWithPrefix.", 0 as `bDeleted`, CURRENT_TIMESTAMP as `iVersion`, NULL as `iNextVersion` ".
       "FROM `".$tableName."` ".
       "LEFT JOIN `history_".$tableName."` ON (`history_".$tableName."`.`ID` = `".$tableName."`.`ID` AND `history_".$tableName."`.`bDeleted` IS NULL AND `history_".$tableName."`.`iNextVersion` IS NULL)".
       "WHERE `history_".$tableName."`.`ID` IS NULL)";

   
   echo $tableName;
   $db->exec($query);
   echo " ok<br/>";
}
