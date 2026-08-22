<?php

/**
 * Drop in an empty folder and run: php install.php
 *
 * php -r "copy('https://raw.githubusercontent.com/MarkVinkNL/lux__eir/main/install.php', 'install.php');"
 */

$git = 'git';
$url = 'https://github.com/MarkVinkNL/lux__eir.git';

function eirInstallRun(string $command): int
{
  passthru($command, $code);
  return $code;
}

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "Run from the command line: php install.php\n");
  exit(1);
}

if (!is_dir('.git')) {
  if (eirInstallRun($git . ' init') !== 0) {
    fwrite(STDERR, "git init failed\n");
    exit(1);
  }
}

if (!is_dir('eir')) {
  if (eirInstallRun($git . ' submodule add ' . escapeshellarg($url) . ' eir') !== 0) {
    fwrite(STDERR, "Could not add eir submodule. Is lux__eir public? Use the HTTPS URL.\n");
    exit(1);
  }
}

$setup = 'eir' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'setup-workspace.php';
if (!is_file($setup)) {
  fwrite(STDERR, "Missing $setup\n");
  exit(1);
}

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($setup), $code);
exit($code);
