<?php

require_once("modelsManager.php");
require_once("../shared/models.php");

function displayRowsAsCsvWithFields($fileName, $rows, $fields) {
   $out = "";
   foreach ($fields as $fieldName => $fieldInfos) {
      if ($out !== "") {
         $out .= ";";
      }
      $out .= $fieldName;
   }
   $out .= "\r\n";
   foreach ($rows as $row) {
      $csvRow = "";
      foreach ($fields as $fieldName => $fieldInfos) {
         if ($csvRow !== "") {
            $csvRow .= ";";
         }
         $csvRow .= "\"".str_replace(array(";", "\n", "\"", "'"), array("\\;", "\\n", "\"\"", "\\'") , $row->$fieldName)."\"";
      }
      $csvRow .= "\r\n";
      $out .= $csvRow;
   }

   header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
   header("Content-Length: " . strlen($out));
   // Output to browser with appropriate mime type, you choose <img src="http://thetechnofreak.com/wp-includes/images/smilies/icon_wink.gif" alt=";)" class="wp-smiley"> 
   header("Content-type: text/x-csv;charset=utf-8");
   //header("Content-type: text/csv");
   //header("Content-type: application/csv");
   header("Content-Disposition: attachment; filename=".$fileName.".csv");
   echo $out;
}

function displayRowsAsCsv($modelName, $rows, $viewModel = null) {
   if (!$viewModel) {
      $viewModel = getViewModel($modelName);
   }
   $fields = $viewModel["fields"];
   displayRowsAsCsvWithFields($modelName, $rows, $fields);
}

?>
