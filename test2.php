<?php

require "vendor/autoload.php";

echo "=== Benchmarking Execution Order ===\n";
$startTime = microtime(true);
echo "[" . number_format((microtime(true) - $startTime) * 1000, 2) . "ms] Script started\n";

function task1()
{
   global $startTime;
   echo "[" . number_format((microtime(true) - $startTime) * 1000, 2) . "ms] Inside task1(), before run()\n";

   return run(function () use ($startTime) {
      echo "[" . number_format((microtime(true) - $startTime) * 1000, 2) . "ms] Inside run() closure, scheduling delays\n";

      // Schedule delays
      await(delay(1)->then(function () use ($startTime) {
         echo "[" . number_format((microtime(true) - $startTime) * 1000, 2) . "ms] Delay 1s callback executed (Hi)\n";
      }));

      await(delay(0.1)->then(function () use ($startTime) {
         echo "[" . number_format((microtime(true) - $startTime) * 1000, 2) . "ms] Delay 0.1s callback executed (Hello)\n";
      }));

      await(delay(0.3)->then(function () use ($startTime) {
         echo "[" . number_format((microtime(true) - $startTime) * 1000, 2) . "ms] Delay 0.3s callback executed (Php)\n";
      }));

      await(delay(0.2)->then(function () use ($startTime) {
         echo "[" . number_format((microtime(true) - $startTime) * 1000, 2) . "ms] Delay 0.2s callback executed (World)\n";
      }));

      // This return statement executes immediately after scheduling the delays
      $returnTime = number_format((microtime(true) - $startTime) * 1000, 2);
      echo "[" . $returnTime . "ms] Return statement inside run() executed\n";
      return "work is done";
   });
}

echo "[" . number_format((microtime(true) - $startTime) * 1000, 2) . "ms] Before calling task1()\n";
$message = task1();
echo "[" . number_format((microtime(true) - $startTime) * 1000, 2) . "ms] After calling task1(), before echo\n";
echo $message . "\n";
echo "[" . number_format((microtime(true) - $startTime) * 1000, 2) . "ms] Script finished\n";
