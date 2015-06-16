<?php

$requests = syncGetTablesRequests();

/*
$requests["items_strings"]["model"]["filters"]["idLanguage"] = array("joins" => array(), "condition"  => "(`[PREFIX]items_strings`.`idLanguage` = '1' OR (`[PREFIX]items_strings`.`idLanguage` = '2' AND `[PREFIX]items_strings`.`idItem` NOT IN (SELECT `idItem` FROM `items_strings` AS `[PREFIX]filter_items_strings` WHERE `[PREFIX]filter_items_strings`.`idLanguage` = '1')))");
$requests["items_strings"]["filters"]["idLanguage"] = true;
*/

/*
function getTestRequests() {
   $viewModel = createViewModelFromTable("user");
   return array("user" => array(
         "modelName" => "table_user",
         "model" => $viewModel,
         "fields" => getViewModelFieldsList($viewModel),
         "filters" => array("firstName" => "mathias")
      )
   );
}

$requests = getTestRequests();
*/
?>