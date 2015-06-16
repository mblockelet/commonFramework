<?php

//error_log("jsErrors");
$stringData = date("Y-m-d H:i:s T")." ".json_encode($_REQUEST)."\r\n";
error_log($stringData);
$myFile =  sys_get_temp_dir()."/jsErrors.log";
$fh = fopen($myFile, 'a') or die("can't open file");
fwrite($fh, $stringData);
fclose($fh);

?>