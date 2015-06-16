<?php

require_once("../shared/connect.php");
require_once("requestConverter.php");
require_once("testModels.php");

$requestSyncMain = array(
   "tablesModels" => $tablesModels,
   "mainTable" => "test_sync_main",
   "fields" => array(
      "fieldA" => array("field" => "sFieldA"),
      "fieldB" => array("field" => "iFieldB"),
      "secondID" => array("field" => "secondID")
   ),
   "conditions" => array()
);

// TODO: extraJoins si on veut utiliser des joins pas définis dans tablesModels ?
// On donne la table du join, et la syntaxe est la même que pour tablesModels

$requestAllTables = array(
   "tablesModels" => $tablesModels,
   "mainTable" => "test_sync_main",
   "pathsToTablesAliases" => array(
      "mainAlias" => array(),
      "secondAlias" => array("second")
   ),
   "fields" => array(
      "mainFieldA" => array("field" => "sFieldA"),
      "mainFieldB" => array("field" => "iFieldB"),
      "secondID" => array("field" => "secondID"),
      "secondFieldA"  => array("tableAlias" => "secondAlias", "field" => "sFieldA"),
      "thirdID" => array("tableAlias" => "secondAlias", "field" => "thirdID")
   ),
   "conditions" => array(
      array("tablesAliases" => array("secondAlias"), "sql" => "`second`.`sFieldA` = :secondFieldA")
   )
);


$idGroupSelf = 42;

/*
$requests = syncGetTablesRequests();//(["items_items"]);

$itemsItemsRequest = $requests["items_items"];

$itemsItemsRequest["model"]["joins"]["items_ancestors"] = array("srcTable" => "items_items", "srcField" => "idItemChild", "dstField" => "idItemChild");
$itemsItemsRequest["model"]["fields"]["sType"]["groupBy"] = "`items_items`.`ID`"; // Could be added to any field. TODO : fix group by system
$itemsItemsRequest["model"]["joins"]["groups_items"] =  array("srcTable" => "items_items", "srcField" => "idItemChild", "dstField" => "idItem");
$itemsItemsRequest["model"]["joins"]["groups_ancestors"] =  array("srcTable" => "groups_items", "dstTable" => "groups_ancestors", "on" => "groups_ancestors.idGroupChild = ".$idGroupSelf.
" OR groups_items.idGroup = groups_ancestors.idGroupAncestor ".
" OR groups_items.idGroup = ".$idGroupSelf);
$itemsItemsRequest["model"]["filters"]["idGroup"] = array(
   "joins" => array("groups_items", "groups_ancestors"),
   "condition"  => "(`groups_items`.`bCachedGrayedAccess` = 1 OR `groups_items`.`bCachedPartialAccess` = 1 OR `groups_items`.`bCachedFullAccess` = 1)",
   "ignoreValue" => true,
   "selectOnly" => true
);
$itemsItemsRequest["filters"]["idGroup"] = true;

   $requests["items_strings"]["model"]["joins"]["groups_items"] =  array("srcTable" => "items_strings", "srcField" => "idItem", "dstField" => "idItem");
   $requests["items_strings"]["model"]["joins"]["groups_ancestors"] =  array("type" => "LEFT", "srcTable" => "groups_items", "dstTable" => "groups_ancestors", "srcField" => "idGroup", "dstField" => "idGroupAncestor");
   $requests["items_strings"]["model"]["filters"]["idGroup"] = array(
      "joins" => array("groups_items", "groups_ancestors"),
      "condition"  => "((`groups_items`.`bCachedGrayedAccess` = 1 OR `groups_items`.`bCachedPartialAccess` = 1 OR `groups_items`.`bCachedFullAccess` = 1) AND (`groups_ancestors`.`idGroupChild` = :idGroup OR `groups_items`.`idGroup` = :idGroup))",
      "ignoreValue" => true,
      "selectOnly" => true
   );
   $requests["items_strings"]["filters"]["idGroup"] = $idGroupSelf;
*/


$converterSyncMain = new RequestConverter($requestSyncMain);

echo "<b>Test 1</b>";
$diffRequest = $converterSyncMain->getDiffRequest();
echo "<pre>".json_encode($requestSyncMain, JSON_PRETTY_PRINT)."</pre><br><br>";

echo "<b>Requete generee :</b><pre>".json_encode($diffRequest, JSON_PRETTY_PRINT)."</pre><br/><br/>";

$converterAllTables = new RequestConverter($requestAllTables);

echo "<b>Test 2</b>";
$diffRequest2 = $converterAllTables->getDiffRequest();
echo "<pre>".json_encode($requestAllTables, JSON_PRETTY_PRINT)."</pre><br><br>";

echo "<b>Requete generee :</b><pre>".json_encode($diffRequest2, JSON_PRETTY_PRINT)."</pre><br/><br/>";


/*
$requestsManager = new VersionedRequestsManager();

$changes = $requestsManager->getChangesSince($db, $diffRequest, array("idGroup" => 33), 42);

echo "<pre>".json_encode($changes)."</pre>";

$changes = $requestsManager->getChangesCountSince($db, $diffRequest, array("idGroup" => 33), 42);

echo "<pre>".json_encode($changes)."</pre>";
*/


/*
Dans la nouvelle version, a-t-on toujours des tableModels d'une part et viewModels de l'autre ?

On a mis les jointures dans tableModels, ce qui permet de définir des requêtes où un champ est spécifié par une succession de jointures + le nom du champ

Du coup une requête n'a pas besoin de viewModels.

Il faudrait cependant une description commune entre le côté client et le côté serveur.
Les requêtes devraient pouvoir n'être qu'une combinaison d'un viewModel et une utilisation de certains des filtres... On a cependant des cas où on a des jointures complexes nécessaires qu'il serait absurde de regrouper dans un même viewModel


On pourrait avoir une gestion des droits qui se greffe par dessus tableModels : certains champs nécessitent certains rôles pour pouvoir être modifiés,
et ces rôles impliquent l'ajout de certains filtres et de leurs jointures associées.

Il faut cependant pouvoir faire différemment pour certaines requêtes, donc outrepasser ces règles.

Pour la description commune, une possibilité serait de fournir une simple liste de (table, champ, [alias]) faisant référence à tableModels, voire pour les cas simples de dire simplement "toute cette table". On pourrait alors relier une requête à une telle liste, simplement pour vérifier que c'est
cohérent (mêmes champs). => On peut s'en passer pour l'instant.

=> On fait d'abord sans les filtres et jointures associées aux rôles.

Ok. reste l'update. 


*/