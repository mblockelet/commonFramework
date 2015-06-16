<?php

$tablesModels =  array(
  "test_sync_main" => array(
      "autoincrementID" => false,
      "primaryKey" => "ID",
      "fields" => array(
         "sFieldA" => array("type" => "string", "access" => array("write" => array("user"), "read" => array("user"))),
         "iFieldB" => array("type" => "int", "access" => array("write" => array("user"), "read" => array("user"))),
         "secondID" => array("type" => "string", "access" => array("write" => array("user"), "read" => array("user")))
      ),
      "joins" => array(
         "second" => array(
            "srcField" => "secondID",
            "dstTable" => "test_sync_second",
            "dstField" => "ID"
         ),
         "third" => array( // do we define this here, or do we generate it from the opposite join ? We need to name it...
            "type" => "LEFT",
            "srcField" => "ID",
            "dstTable" => "test_sync_third",
            "dstField" => "mainID"
         )
      ),
   ),
  "test_sync_second" => array(
      "autoincrementID" => false,
      "primaryKey" => "ID",
      "fields" => array(
         "sFieldA" => array("type" => "string", "access" => array("write" => array("user"), "read" => array("user"))),
         "thirdID" => array("type" => "string", "access" => array("write" => array("user"), "read" => array("user")))
      ),
      "joins" => array(
         "third" => array(
            "srcField" => "thirdID",
            "dstTable" => "test_sync_third",
            "dstField" => "ID"
         )
      )
   ),
  "test_sync_third" => array(
      "autoincrementID" => false,
      "fields" => array(
         "iFieldB" => array("type" => "int", "access" => array("write" => array("user"), "read" => array("user"))),
         "mainID" => array("type" => "string", "access" => array("write" => array("user"), "read" => array("user")))
      ),
      "joins" => array(
         "main" => array(
            "srcField" => "mainID",
            "dstTable" => "test_sync_main",
            "dstField" => "ID"
         )
      )
   )
);
