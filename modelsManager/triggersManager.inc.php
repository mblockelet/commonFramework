<?php

class TriggerManager {
   public static $debug = false;

   static function addVersionTriggers($tablesModels, &$triggers) {
      foreach ($tablesModels as $tableName => $tableModel) {
         if (isset($tableModel["hasHistory"]) && ($tableModel["hasHistory"] == false)) {
            continue;
         }
         $idField = isset($tableModel["primaryKey"]) ? $tableModel["primaryKey"] : 'ID';
         $listFields = array('`'.$idField.'`');
         $listFieldsNewValues = array('NEW.`'.$idField.'`');
         $listFieldsOldValues = array('OLD.`'.$idField.'`');
         $conditions = 'OLD.`'.$idField.'` = NEW.`'.$idField.'`';
         $listFields[] = "`iVersion`";
         $listFieldsNewValues[] = "@curVersion";
         $listFieldsOldValues[] = "@curVersion";
         foreach ($tableModel["fields"] as $fieldName => $field) {
            $listFields[] = "`".$fieldName."`";
            if (! isset($field['skipHistory']) ||  ! $field['skipHistory']) {
               $conditions .= ' AND OLD.`'.$fieldName.'` <=> NEW.`'.$fieldName.'`';
            }
            $listFieldsNewValues[] = "NEW.`".$fieldName."`";
            $listFieldsOldValues[] = "OLD.`".$fieldName."`";
         }

         $triggers[$tableName]["BEFORE INSERT"][] = "SELECT ROUND(UNIX_TIMESTAMP(CURTIME(2)) * 10) INTO @curVersion;".
                                                  "SET NEW.iVersion = @curVersion";

         $triggers[$tableName]["AFTER INSERT"][] = "INSERT INTO `history_".$tableName."` (".implode(",", $listFields).") VALUES (".implode(",", $listFieldsNewValues).")";

         $triggers[$tableName]["BEFORE UPDATE"][] =
            "IF NEW.iVersion <> OLD.iVersion THEN ".
               "SET @curVersion = NEW.iVersion; ".
            "ELSE ".
               "SELECT ROUND(UNIX_TIMESTAMP(CURTIME(2)) * 10) INTO @curVersion; ".
            "END IF; ".
            "IF NOT (".$conditions.") THEN ".
            "  SET NEW.iVersion = @curVersion; ".
            "  UPDATE `history_".$tableName."` SET `iNextVersion` = @curVersion WHERE `ID` = OLD.`".$idField."` AND `iNextVersion` IS NULL; ".
            "  INSERT INTO `history_".$tableName."` (".implode(",", $listFields).") ".
            "      VALUES (".implode(",", $listFieldsNewValues).") ".
            "; END IF";

         $triggers[$tableName]["BEFORE DELETE"][] =
                  "SELECT ROUND(UNIX_TIMESTAMP(CURTIME(2)) * 10) INTO @curVersion; ".
                  "UPDATE `history_".$tableName."` SET `iNextVersion` = @curVersion WHERE `".$idField."` = OLD.`".$idField."` AND `iNextVersion` IS NULL; ".
                  "INSERT INTO `history_".$tableName."` (".implode(",", $listFields).", `bDeleted`) ".
                     "VALUES (".implode(",", $listFieldsOldValues).", 1)";
      }
   }

   static function addRandomIDTriggers($tablesModels, &$triggers) {
      foreach ($tablesModels as $tableName => $tableModel) {
         if ((isset($tableModel["autoincrementID"]) && $tableModel["autoincrementID"]) || (isset($tableModel["primaryKey"]) && !$tableModel["primaryKey"])) {
            continue;
         }
         $idField = isset($tableModel["primaryKey"]) ? $tableModel["primaryKey"] : 'ID';
         $triggers[$tableName]["BEFORE INSERT"][] = "IF (NEW.".$idField." IS NULL OR NEW.".$idField." = 0) THEN SET NEW.".$idField." = FLOOR(RAND() * 1000000000) + FLOOR(RAND() * 1000000000) * 1000000000; END IF ";
      }
   }

   static function createTriggers($db, $tablesModels, $triggers) {
      foreach ($tablesModels as $tableName => $tableModel) {
         $db->exec("DROP TRIGGER IF EXISTS `delete_".$tableName."`");
         $db->exec("DROP TRIGGER IF EXISTS `insert_".$tableName."`");
         $db->exec("DROP TRIGGER IF EXISTS `insert_after_".$tableName."`");
         $db->exec("DROP TRIGGER IF EXISTS `update_".$tableName."`");
         $db->exec("DROP TRIGGER IF EXISTS `custom_delete_".$tableName."`");
         $db->exec("DROP TRIGGER IF EXISTS `custom_insert_".$tableName."`");
         $db->exec("DROP TRIGGER IF EXISTS `custom_update_".$tableName."`");
         $tableTriggers = $triggers[$tableName];
         foreach ($tableTriggers as $triggerType => $triggersForType) {
            if (TriggerManager::$debug) {
               echo $tableName." : ".$triggerType." => ".json_encode($triggersForType)."\n<br>";
            }
            $triggerName = strtolower(str_replace(" ", "_", $triggerType))."_".$tableName;
            $db->exec("DROP TRIGGER IF EXISTS `".$triggerName."`");
            if (count($triggersForType) == 0) {
               continue;
            }
            $triggerQuery = "CREATE TRIGGER `".$triggerName."` ".$triggerType." ON `".$tableName."` ".
               "FOR EACH ROW BEGIN ".implode("; ", $triggersForType)."; END";
            if (TriggerManager::$debug) {
               echo $triggerQuery."\n<br>\n<br>\n";
            }
            $db->exec($triggerQuery);
         }
      }
   }

   public static function generateAllTriggers($tablesModels, $customTriggers = array()) {
      global $db;
      $triggers = array();
      foreach ($tablesModels as $tableName => $tableModel) {
         $triggers[$tableName] = array(
            "BEFORE INSERT" => array(),
            "AFTER INSERT" => array(),
            "BEFORE UPDATE" => array(),
            "AFTER UPDATE" => array(),
            "BEFORE DELETE" => array(),
            "AFTER DELETE" => array()
         );
      }
      TriggerManager::addRandomIDTriggers($tablesModels, $triggers);
      TriggerManager::addVersionTriggers($tablesModels, $triggers);
      if (function_exists("addCustomTriggers")) {
         addCustomTriggers($triggers);
      }
      TriggerManager::createTriggers($db, $tablesModels, $triggers);
   }
}
