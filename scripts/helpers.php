<?php

$DS = DIRECTORY_SEPARATOR;

function eirCommandRunnable(string $binary): bool
{
  if ($binary === '') {
    return false;
  }

  exec(escapeshellarg($binary) . ' --version 2>&1', $output, $code);

  return $code === 0;
}

function eirPromptBoolMatches(string $answer, string $check): bool
{
  $answer = strtolower(trim($answer));
  $check = strtolower($check);

  if ($answer === $check) {
    return true;
  }

  if ($check === 'yes') {
    return in_array($answer, ['y', 'local'], true);
  }

  return false;
}

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
      if (str_contains($path, DIRECTORY_SEPARATOR . $segment . DIRECTORY_SEPARATOR)) {
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

function eirUserSshDir(): string
{
  $home = getenv('HOME') ?: getenv('USERPROFILE');
  if (!$home) {
    return '';
  }

  return rtrim($home, '\\/') . DIRECTORY_SEPARATOR . '.ssh';
}

function eirTcpReachable(string $host, int $port, int $timeout = 5): bool
{
  $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
  if (!is_resource($fp)) {
    return false;
  }

  fclose($fp);
  return true;
}

/**
 * GitHub's `ssh -T` returns 1 even on success. Trust the output text, not the exit code.
 */
function eirGithubSshAuthenticated(): bool
{
  exec('ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -T git@github.com 2>&1', $output, $code);
  $text = implode("\n", $output);

  return str_contains($text, 'successfully authenticated');
}

function eirEnsureGithubKnownHosts(CLI $cli): void
{
  $sshDir = eirUserSshDir();
  if ($sshDir === '') {
    $cli->error('Could not resolve home directory for ~/.ssh')->exit(1);
  }

  if (!is_dir($sshDir) && !mkdir($sshDir, 0700, true) && !is_dir($sshDir)) {
    $cli->error('Could not create ' . $sshDir)->exit(1);
  }

  $knownHosts = $sshDir . DIRECTORY_SEPARATOR . 'known_hosts';
  $existing = is_file($knownHosts) ? (string) file_get_contents($knownHosts) : '';
  if (str_contains($existing, 'github.com')) {
    return;
  }

  exec('ssh-keyscan -t ed25519,ecdsa,rsa github.com 2>&1', $scanOut, $scanCode);
  $keys = [];
  foreach ($scanOut as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
      continue;
    }
    if (str_contains($line, 'github.com')) {
      $keys[] = $line;
    }
  }

  if ($keys === []) {
    $cli->error('ssh-keyscan did not return GitHub host keys')->exit(1);
  }

  $append = implode(PHP_EOL, $keys) . PHP_EOL;
  if (file_put_contents($knownHosts, $append, FILE_APPEND) === false) {
    $cli->error('Could not write ' . $knownHosts)->exit(1);
  }
}

function eirFindExistingGithubPubkey(): ?string
{
  $sshDir = eirUserSshDir();
  if ($sshDir === '') {
    return null;
  }

  foreach (['id_ed25519.pub', 'id_rsa.pub', 'id_ecdsa.pub'] as $name) {
    $path = $sshDir . DIRECTORY_SEPARATOR . $name;
    if (is_file($path)) {
      return $path;
    }
  }

  return null;
}

function eirShowOrCreateSshKey(CLI $cli): string
{
  $existing = eirFindExistingGithubPubkey();
  if ($existing !== null) {
    $cli->echo('Using existing public key: ' . $existing);
    return $existing;
  }

  $sshDir = eirUserSshDir();
  $keyFile = $sshDir . DIRECTORY_SEPARATOR . 'id_ed25519';
  $comment = 'eir@' . (gethostname() ?: 'workspace');

  $cli->echo('No SSH key found. Generating ' . $keyFile);
  execOrFail(
    'ssh-keygen -t ed25519 -f ' . escapeshellarg($keyFile) . ' -N ' . escapeshellarg('') . ' -C ' . escapeshellarg($comment),
    $cli
  );

  $pub = $keyFile . '.pub';
  if (!is_file($pub)) {
    $cli->error('ssh-keygen did not write ' . $pub)->exit(1);
  }

  return $pub;
}

function eirEnsureGithubSsh(CLI $cli): void
{
  if (eirGithubSshAuthenticated()) {
    return;
  }

  $cli->echo('Checking GitHub SSH...');

  if (!eirTcpReachable('github.com', 22)) {
    if (eirTcpReachable('github.com', 443)) {
      $cli->error('github.com is reachable on HTTPS (443) but not SSH (22). Key generation will not help.')->exit(1);
    }
    $cli->error('Cannot reach github.com. Check network/DNS/firewall.')->exit(1);
  }

  eirEnsureGithubKnownHosts($cli);

  if (eirGithubSshAuthenticated()) {
    return;
  }

  while (!eirGithubSshAuthenticated()) {
    $pub = eirShowOrCreateSshKey($cli);
    $cli->echo('');
    $cli->echo('Add this public key to GitHub (Settings → SSH and GPG keys, or a deploy key):');
    $cli->echo('');
    $cli->echo(trim((string) file_get_contents($pub)));
    $cli->echo('');
    $cli->promptEnter('Then press Enter to retry, or Ctrl+C to abort.');
  }

  $cli->echo('GitHub SSH authentication succeeded.');
}

function eirSetConfigValue(string $file, string $key, string $value): void
{
  $contents = (string) file_get_contents($file);
  $line = $key . '=' . $value;
  $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

  if (preg_match($pattern, $contents)) {
    $contents = preg_replace_callback($pattern, function () use ($line) {
      return $line;
    }, $contents, 1);
  } else {
    $contents = rtrim($contents) . PHP_EOL . $line . PHP_EOL;
  }

  if (file_put_contents($file, $contents) === false) {
    throw new RuntimeException('Could not write ' . $file);
  }
}

function eirReadConfigValue(string $file, string $key): ?string
{
  $parsed = @parse_ini_file($file, false, INI_SCANNER_RAW);
  if (!is_array($parsed) || !isset($parsed[$key])) {
    return null;
  }

  return (string) $parsed[$key];
}

function eirGitLinkInIndex(string $git, string $path): bool
{
  exec($git . ' ls-files -s -- ' . escapeshellarg($path), $output, $code);

  return $code === 0
    && count($output) === 1
    && str_starts_with($output[0], '160000 ');
}

function eirIndexHasRegularPath(string $git, string $path): bool
{
  exec($git . ' ls-files -s -- ' . escapeshellarg($path), $output, $code);
  if ($code !== 0 || $output === []) {
    return false;
  }

  return !(count($output) === 1 && str_starts_with($output[0], '160000 '));
}

function eirWorkspaceHasRemote(string $git): bool
{
  exec($git . ' remote', $output, $code);

  return $code === 0 && $output !== [];
}

/**
 * Local only: register Application as a submodule of the Environment git repo
 * so editors show Application git. Servers must keep a plain clone so Deploy can swap app/.
 */
function eirEnsureLocalAppSubmodule(
  CLI $cli,
  string $git,
  string $home,
  string $siteFolder,
  string $repository,
  string $branch
): void {
  $cli->cd($home);

  if (!preg_match('/^[A-Za-z0-9._-]+$/', $siteFolder)) {
    $cli->error('EIR_SITE_FOLDER is not a safe submodule name: ' . $siteFolder)->exit(1);
  }

  if (!execCheck($git . ' rev-parse --git-dir 2>&1')) {
    $cli->error('Environment root is not a git repository — cannot add ' . $siteFolder . '/ as a submodule')->exit(1);
  }

  $site = $home . DIRECTORY_SEPARATOR . $siteFolder;
  $gitMarker = $site . DIRECTORY_SEPARATOR . '.git';
  if (!is_file($gitMarker) && !is_dir($gitMarker)) {
    $cli->error($siteFolder . '/ is not a git repository — cannot register as a submodule')->exit(1);
  }

  if (eirGitLinkInIndex($git, $siteFolder)) {
    $cli->echo($siteFolder . '/ is already a workspace submodule');
    return;
  }

  $cli->echo('Registering ' . $siteFolder . '/ as a workspace submodule');

  execOrFail($git . ' config -f .gitmodules submodule.' . $siteFolder . '.path ' . escapeshellarg($siteFolder), $cli);
  execOrFail($git . ' config -f .gitmodules submodule.' . $siteFolder . '.url ' . escapeshellarg($repository), $cli);
  execOrFail($git . ' config -f .gitmodules submodule.' . $siteFolder . '.branch ' . escapeshellarg($branch), $cli);

  if (eirIndexHasRegularPath($git, $siteFolder)) {
    execOrFail($git . ' rm -r --cached -- ' . escapeshellarg($siteFolder), $cli);
  }

  $sha = execValue($git . ' -C ' . escapeshellarg($site) . ' rev-parse HEAD');
  if (!is_string($sha) || !preg_match('/^[0-9a-f]{7,40}$/i', $sha)) {
    $cli->error('Could not read HEAD of ' . $siteFolder)->exit(1);
  }

  execOrFail(
    $git . ' update-index --add --replace --cacheinfo 160000,' . $sha . ',' . $siteFolder,
    $cli
  );

  execOrFail($git . ' submodule init -- ' . escapeshellarg($siteFolder), $cli);
  execOrFail($git . ' config submodule.' . $siteFolder . '.url ' . escapeshellarg($repository), $cli);
  execOrFail($git . ' config submodule.' . $siteFolder . '.active true', $cli);

  exec($git . ' submodule absorbgitdirs -- ' . escapeshellarg($siteFolder), $absorbOut, $absorbCode);
  if ($absorbCode === 0) {
    $cli->echo('Moved ' . $siteFolder . '/.git into .git/modules/' . $siteFolder);
  }

  execOrFail($git . ' add -- .gitmodules ' . escapeshellarg($siteFolder), $cli);

  $status = execValue($git . ' status --porcelain -- .gitmodules ' . escapeshellarg($siteFolder));
  if ($status === false) {
    return;
  }

  exec(
    $git . ' commit -m ' . escapeshellarg('Add Application submodule') . ' -- .gitmodules ' . escapeshellarg($siteFolder),
    $commitOut,
    $commitCode
  );
  if ($commitCode === 0) {
    $cli->echo('Committed Application submodule');
  } else {
    $cli->echo('Application submodule is staged (commit it when ready)');
  }
}
