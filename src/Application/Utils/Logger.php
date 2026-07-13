<?php

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

class Logger {
    public static function info($msg) { error_log("[INFO] ".json_encode($msg)); }
    public static function error($msg) { error_log("[ERROR] ".json_encode($msg)); }
}
