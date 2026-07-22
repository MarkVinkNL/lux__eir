<?php

$DS = DIRECTORY_SEPARATOR;

function execCheck($command, $status = 0, $output = false)
{
  exec($command, $execOutput, $execReturnVar);

  if ($execReturnVar == $status) {
    return true;
  }

  if ($output) {
    print_r($execOutput);
  }

  return false;
}

function execOrFail($command, $cli = null)
{
  exec($command, $execOutput, $execReturnVar);

  if ($execReturnVar === 0) {
    return true;
  }

  $message = 'Command failed (' . $execReturnVar . '): ' . $command;
  if (!empty($execOutput)) {
    $message .= PHP_EOL . implode(PHP_EOL, $execOutput);
  }

  if ($cli) {
    $cli->error($message)->exit(1);
  }

  echo 'ERROR: ' . $message . PHP_EOL;
  exit(1);
}

function execLog($command)
{
  exec($command, $execOutput, $execReturnVar);

  echo 'command: ' . $command . PHP_EOL;
  echo 'status : ' . $execReturnVar . PHP_EOL;

  if (empty($execOutput)) {
    echo 'output : empty' . PHP_EOL;
    return;
  }

  if (count($execOutput) == 1) {
    echo 'output: ' . $execOutput[0] . PHP_EOL;
    return;
  }

  print_r($execOutput);
  echo PHP_EOL;
}

function execValue($command)
{
  exec($command, $execOutput, $execReturnVar);

  if (empty($execOutput)) {
    return false;
  }

  if (count($execOutput) == 1) {
    return $execOutput[0];
  }

  return $execOutput;
}

function argCheck(string|array $argument)
{
  global $argc, $argv;

  if (PHP_SAPI !== 'cli') {
    return false;
  }
  if (!isset($argc)) {
    return false;
  }
  if ($argc <= 1) {
    return false;
  }

  foreach ($argv as $value) {
    if (is_array($argument) && in_array($value, $argument, true)) {
      return true;
    }
    if ($value == $argument) {
      return true;
    }
  }

  return false;
}

/**
 * Find a named file under $root, skipping vendor/node_modules.
 */
function findNamedFile(string $root, string $filename): ?string
{
  if (!is_dir($root)) {
    return null;
  }

  $skip = ['vendor', 'node_modules', '.git'];
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
  );

  foreach ($iterator as $file) {
    if (!$file->isFile()) {
      continue;
    }

    $path = $file->getPathname();
    foreach ($skip as $segment) {
      if (str_contains($path, $DS . $segment . $DS)) {
        continue 2;
      }
    }

    if ($file->getFilename() === $filename) {
      return $path;
    }
  }

  return null;
}

function parseEnvFile(?string $filePath): array
{
  if (!$filePath || !file_exists($filePath)) {
    return [];
  }

  $parsed = parse_ini_file($filePath, false, INI_SCANNER_RAW);
  return is_array($parsed) ? $parsed : [];
}

/**
 * Convert a key-value array to a .env file.
 */
function arrayToEnvFile(array $data, string $filePath): bool
{
  if (empty($data)) {
    throw new InvalidArgumentException('The data array is empty.');
  }

  $directory = dirname($filePath);
  if (!is_dir($directory)) {
    throw new RuntimeException('Directory does not exist: ' . $directory);
  }

  $envContent = '';

  foreach ($data as $key => $value) {
    if (!preg_match('/^[A-Z0-9_]+$/', $key)) {
      throw new InvalidArgumentException("Invalid key: $key. Keys must be uppercase and contain only letters, numbers, or underscores.");
    }

    do {
      if (is_bool($value)) {
        $escapedValue = $value ? 'true' : 'false';
        break;
      }

      if (is_null($value)) {
        $escapedValue = '';
        break;
      }

      if (is_numeric($value)) {
        $escapedValue = $value;
        break;
      }

      if (is_string($value)) {
        $escapedValue = str_replace(['\\', '"'], ['\\\\', '\"'], $value);
        $escapedValue = "\"{$escapedValue}\"";
        break;
      }

      throw new InvalidArgumentException("Unsupported value type for key: $key.");
    } while (0);

    $envContent .= "{$key}={$escapedValue}\n";
  }

  $tempFilePath = $filePath . '.tmp';
  if (file_put_contents($tempFilePath, $envContent) === false) {
    throw new RuntimeException("Failed to write to temporary file: $tempFilePath");
  }

  if (!rename($tempFilePath, $filePath)) {
    throw new RuntimeException('Failed to replace the .env file.');
  }

  return true;
}

function remoteCommitHash(string $gitBin, string $repository, string $branch): ?string
{
  $output = execValue($gitBin . ' ls-remote ' . escapeshellarg($repository) . ' ' . escapeshellarg('refs/heads/' . $branch));
  if ($output === false) {
    return null;
  }

  $line = is_array($output) ? ($output[0] ?? '') : $output;
  if ($line === '') {
    return null;
  }

  $parts = preg_split('/\s+/', trim($line));
  return $parts[0] ?? null;
}

function writeLastCommit(string $sitePath, string $home, string $gitBin, $cli): void
{
  chdir($sitePath);
  $hash = execValue($gitBin . ' log -n 1 --pretty=format:"%H"');
  if (!$hash) {
    $cli->error('Could not read commit hash from ' . $sitePath)->exit(1);
  }

  $target = $home . DIRECTORY_SEPARATOR . 'last_commit';
  if (file_put_contents($target, $hash) === false) {
    $cli->error('Could not write last_commit')->exit(1);
  }
}
