<?php

class TriggerManager {
   public static $debug = false;

   static function addVersionTriggers($tablesModels, &$triggers) {
      foreach ($tablesModels as $tableName => $tableModel) {
         if (isset($tableModel["hasHistory"]) && ($tableModel["hasHistory"] == false)) {
            continue;
         }
         $listFields = array("`ID`");
         $listFieldsNewValues = array("NEW.`ID`");
         $listFieldsOldValues = array("OLD.`ID`");
         $listFields[] = "`iVersion`";
         $listFieldsNewValues[] = "@curVersion";
         $listFieldsOldValues[] = "@curVersion";
         $conditions = 'OLD.`ID` = NEW.`ID`';
         foreach ($tableModel["fields"] as $fieldName => $field) {
            $listFields[] = "`".$fieldName."`";
            if (! isset($field['skipHistory']) ||  ! $field['skipHistory']) {
               $conditions .= ' AND OLD.`'.$fieldName.'` <=> NEW.`'.$fieldName.'`';
            }
            $listFieldsNewValues[] = "NEW.`".$fieldName."`";
            $listFieldsOldValues[] = "OLD.`".$fieldName."`";
         }
         $triggers[$tableName]["BEFORE INSERT"][] = "SELECT `iVersion` INTO @curVersion FROM `synchro_version`;".
                                                  "SET NEW.iVersion = @curVersion";

         $triggers[$tableName]["AFTER INSERT"][] = "INSERT INTO `history_".$tableName."` (".implode(",", $listFields).") VALUES (".implode(",", $listFieldsNewValues).")";

         $triggers[$tableName]["BEFORE UPDATE"][] =
                  "SELECT `iVersion` INTO @curVersion FROM `synchro_version`; ".
                  "IF @curVersion = OLD.iVersion THEN ".
                     "UPDATE `synchro_version` SET `iVersion` = `iVersion` + 1; ".
                     "SET @curVersion = @curVersion + 1; ".
                  "END IF; ".
                  "IF NOT (".$conditions.") THEN ".
                  "  SET NEW.iVersion = @curVersion; ".
                  "  UPDATE `history_".$tableName."` SET `iNextVersion` = @curVersion WHERE `ID` = OLD.`ID` AND `iNextVersion` IS NULL; ".
                  "  INSERT INTO `history_".$tableName."` (".implode(",", $listFields).") ".
                  "      VALUES (".implode(",", $listFieldsNewValues).") ".
                  "; END IF";

         $triggers[$tableName]["BEFORE DELETE"][] =
                  "SELECT `iVersion` INTO @curVersion FROM `synchro_version`; ".
                  "UPDATE `history_".$tableName."` SET `iNextVersion` = @curVersion WHERE `ID` = OLD.`ID` AND `iNextVersion` IS NULL; ".
                  "INSERT INTO `history_".$tableName."` (".implode(",", $listFields).", `bDeleted`) ".
                     "VALUES (".implode(",", $listFieldsOldValues).", 1)";
      }
   }

   static function addRandomIDTriggers($tablesModels, &$triggers) {
      foreach ($tablesModels as $tableName => $tableModel) {
         if (isset($tableModel["autoincrementID"]) && $tableModel["autoincrementID"]) {
            continue;
         }
         $triggers[$tableName]["BEFORE INSERT"][] = "IF (NEW.ID IS NULL OR NEW.ID = 0) THEN SET NEW.ID = FLOOR(RAND() * 1000000000) + FLOOR(RAND() * 1000000000) * 1000000000; END IF ";
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
