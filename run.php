<?php

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "Run from the command line: php eir/run.php\n");
  exit(1);
}

include_once __DIR__ . '/scripts/deploy.php';

