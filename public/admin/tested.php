<?php

echo "<pre>";

var_dump(extension_loaded('pdo_pgsql'));
var_dump(extension_loaded('pgsql'));
var_dump(PDO::getAvailableDrivers());
