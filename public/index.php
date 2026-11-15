<?php

// Lets Herd's nginx and PHP-FPM serve the Testbench app with the conformance
// fixtures, so QUERY reaches PHP (the built-in server answers it with 501).
$root = dirname(__DIR__);
putenv("TESTBENCH_WORKING_PATH={$root}");
$_ENV['TESTBENCH_WORKING_PATH'] = $_SERVER['TESTBENCH_WORKING_PATH'] = $root;

require $root.'/vendor/orchestra/testbench-core/laravel/public/index.php';
