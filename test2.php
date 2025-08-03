<?php

require "vendor/autoload.php";

$task = async(function () {
   throw new Exception("test");
});

$message = run($task);

echo $message;
