<?php

$DS = DIRECTORY_SEPARATOR;

function eirCommandRunnable(string $binary, string $versionFlag = '--version'): bool
{
  if ($binary === '') {
    return false;
  }

  exec(escapeshellarg($binary) . ' ' . $versionFlag . ' 2>&1', $output, $code);

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
 * Strip wrapping/trailing quotes from APP_KEY.
 *
 * arrayToEnvFile writes empty strings as APP_KEY="". Laravel's key:generate
 * replaces /^APP_KEY=/ and leaves the quotes: APP_KEY=base64:…=""
 */
function eirNormalizeAppKey(string $value): string
{
  return trim(trim($value), "\"'");
}

function eirAppKeyIsPresent(?string $value): bool
{
  return eirNormalizeAppKey((string) $value) !== '';
}

/**
 * Write APP_KEY= with no quotes so artisan key:generate can replace the line.
 */
function eirWriteUnquotedEmptyAppKey(string $envPath): void
{
  if (!is_file($envPath)) {
    return;
  }

  $contents = file_get_contents($envPath);
  if ($contents === false) {
    return;
  }

  $updated = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=', $contents, 1);
  if (is_string($updated) && $updated !== $contents) {
    file_put_contents($envPath, $updated);
  }
}

/**
 * Repair APP_KEY=base64:…="" left by artisan key:generate.
 */
function eirRepairGeneratedAppKey(string $envPath): bool
{
  if (!is_file($envPath)) {
    return false;
  }

  $contents = file_get_contents($envPath);
  if ($contents === false) {
    return false;
  }

  $repaired = preg_replace('/^(APP_KEY=base64:[A-Za-z0-9+\/]+=*)"+$/m', '$1', $contents, 1);
  if (!is_string($repaired) || $repaired === $contents) {
    return false;
  }

  return file_put_contents($envPath, $repaired) !== false;
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
        // Empty APP_KEY must stay unquoted. Quoted APP_KEY="" makes
        // `php artisan key:generate` produce APP_KEY=base64:…=""
        if ($key === 'APP_KEY') {
          $value = eirNormalizeAppKey($value);
          if ($value === '') {
            $escapedValue = '';
            break;
          }
        }

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

/**
 * Treat localhost and 127.0.0.1 as the same host for Import same-target checks.
 */
function eirNormalizeDbHost(string $host): string
{
  $host = strtolower(trim($host));
  if ($host === 'localhost' || $host === '127.0.0.1') {
    return '127.0.0.1';
  }

  return $host;
}

/**
 * MySQL client defaults file so passwords never appear on the command line.
 */
function eirWriteMysqlDefaultsFile(string $host, string $port, string $user, string $password): string
{
  $path = tempnam(sys_get_temp_dir(), 'eir_mysql_');
  if ($path === false) {
    throw new RuntimeException('Could not create temporary MySQL defaults file');
  }

  $escape = static function (string $value): string {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
  };

  $content = "[client]\n"
    . 'host=' . $escape($host) . "\n"
    . 'port=' . $escape($port) . "\n"
    . 'user=' . $escape($user) . "\n"
    . 'password=' . $escape($password) . "\n";

  if (file_put_contents($path, $content) === false) {
    @unlink($path);
    throw new RuntimeException('Could not write temporary MySQL defaults file');
  }

  @chmod($path, 0600);

  return $path;
}

/**
 * Resolve Import source credentials from .config, prompting for any missing values.
 *
 * @return array{host: string, port: string, database: string, username: string, password: string, prompted: array<string, string>}
 */
function eirResolveImportSource(CLI $cli): array
{
  $prompted = [];

  $host = (string) $cli->configOptional('EIR_IMPORT_DB_HOST', '');
  if ($host === '') {
    $host = $cli->promptLine('Import source DB host');
    while ($host === '') {
      $cli->echo('Host cannot be empty.');
      $host = $cli->promptLine('Import source DB host');
    }
    $prompted['EIR_IMPORT_DB_HOST'] = $host;
  }

  $port = (string) $cli->configOptional('EIR_IMPORT_DB_PORT', '');
  if ($port === '') {
    $port = $cli->promptLine('Import source DB port', '3306');
    if ($port === '') {
      $port = '3306';
    }
    $prompted['EIR_IMPORT_DB_PORT'] = $port;
  }

  $database = (string) $cli->configOptional('EIR_IMPORT_DB_DATABASE', '');
  if ($database === '') {
    $database = $cli->promptLine('Import source DB database');
    while ($database === '') {
      $cli->echo('Database cannot be empty.');
      $database = $cli->promptLine('Import source DB database');
    }
    $prompted['EIR_IMPORT_DB_DATABASE'] = $database;
  }

  $username = (string) $cli->configOptional('EIR_IMPORT_DB_USERNAME', '');
  if ($username === '') {
    $username = $cli->promptLine('Import source DB username');
    while ($username === '') {
      $cli->echo('Username cannot be empty.');
      $username = $cli->promptLine('Import source DB username');
    }
    $prompted['EIR_IMPORT_DB_USERNAME'] = $username;
  }

  $password = $cli->configOptional('EIR_IMPORT_DB_PASSWORD', null);
  if ($password === null) {
    $password = $cli->promptLine('Import source DB password', '');
    $prompted['EIR_IMPORT_DB_PASSWORD'] = $password;
  } else {
    $password = (string) $password;
  }

  return [
    'host' => $host,
    'port' => $port,
    'database' => $database,
    'username' => $username,
    'password' => $password,
    'prompted' => $prompted,
  ];
}

/**
 * Dump a remote MySQL/MariaDB database into this Environment’s DB_* database.
 * Caller runs migrate/cache afterwards.
 */
function eirImportDatabase(CLI $cli, string $home): void
{
  $mysql = (string) $cli->configOptional('EIR_SYS_MYSQL', 'mysql');
  $mysqldump = (string) $cli->configOptional('EIR_SYS_MYSQLDUMP', 'mysqldump');

  if (!eirCommandRunnable($mysql)) {
    $cli->error(
      'mysql binary not runnable: ' . $mysql
      . '. Set EIR_SYS_MYSQL in .config to the full path (e.g. Herd/MariaDB mysql.exe).'
    )->exit(1);
  }

  if (!eirCommandRunnable($mysqldump)) {
    $cli->error(
      'mysqldump binary not runnable: ' . $mysqldump
      . '. Set EIR_SYS_MYSQLDUMP in .config to the full path (e.g. Herd/MariaDB mysqldump.exe).'
    )->exit(1);
  }

  $targetHost = (string) $cli->config('DB_HOST');
  $targetPort = (string) $cli->configOptional('DB_PORT', '3306');
  $targetDatabase = (string) $cli->config('DB_DATABASE');
  $targetUsername = (string) $cli->config('DB_USERNAME');
  $targetPassword = (string) $cli->configOptional('DB_PASSWORD', '');

  if ($targetDatabase === '') {
    $cli->error('DB_DATABASE is empty in .config — cannot Import')->exit(1);
  }

  $source = eirResolveImportSource($cli);

  $sameHost = eirNormalizeDbHost($source['host']) === eirNormalizeDbHost($targetHost);
  $samePort = (string) $source['port'] === (string) $targetPort;
  $sameDatabase = $source['database'] === $targetDatabase;

  if ($sameHost && $samePort && $sameDatabase) {
    $cli->error(
      'Import source and target are the same database ('
      . $targetHost . ':' . $targetPort . '/' . $targetDatabase
      . '). Refusing to overwrite with itself.'
    )->exit(1);
  }

  $cli->echo(
    'Import will REPLACE local database '
    . $targetDatabase . ' on ' . $targetHost . ':' . $targetPort
    . ' with a dump of '
    . $source['database'] . ' on ' . $source['host'] . ':' . $source['port']
  );

  if (!$cli->promptBool('Type yes to continue: ', 'yes')) {
    $cli->error('Import cancelled')->exit(1);
  }

  $sourceDefaults = null;
  $targetDefaults = null;
  $dumpFile = null;

  try {
    $sourceDefaults = eirWriteMysqlDefaultsFile(
      $source['host'],
      $source['port'],
      $source['username'],
      $source['password']
    );
    $targetDefaults = eirWriteMysqlDefaultsFile(
      $targetHost,
      $targetPort,
      $targetUsername,
      $targetPassword
    );

    $dumpFile = tempnam(sys_get_temp_dir(), 'eir_dump_');
    if ($dumpFile === false) {
      $cli->error('Could not create temporary dump file')->exit(1);
    }
    // Prefer a .sql suffix for clarity; tempnam has no extension.
    $dumpSql = $dumpFile . '.sql';
    if (!@rename($dumpFile, $dumpSql)) {
      $dumpSql = $dumpFile;
    }
    $dumpFile = $dumpSql;

    $cli->echo('Dumping source database...');
    $dumpCmd = escapeshellarg($mysqldump)
      . ' --defaults-extra-file=' . escapeshellarg($sourceDefaults)
      . ' --single-transaction'
      . ' --routines'
      . ' --no-tablespaces'
      . ' --add-drop-table'
      . ' --default-character-set=utf8mb4'
      . ' --result-file=' . escapeshellarg($dumpFile)
      . ' ' . escapeshellarg($source['database']);

    exec($dumpCmd . ' 2>&1', $dumpOutput, $dumpCode);
    if ($dumpCode !== 0) {
      $message = 'mysqldump failed (' . $dumpCode . ')';
      if (!empty($dumpOutput)) {
        $message .= PHP_EOL . implode(PHP_EOL, $dumpOutput);
      }
      $cli->error($message)->exit(1);
    }

    if (!is_file($dumpFile) || filesize($dumpFile) === 0) {
      $cli->error('mysqldump produced an empty dump file')->exit(1);
    }

    $cli->echo('Loading dump into ' . $targetDatabase . '...');
    $loadCmd = escapeshellarg($mysql)
      . ' --defaults-extra-file=' . escapeshellarg($targetDefaults)
      . ' --default-character-set=utf8mb4'
      . ' ' . escapeshellarg($targetDatabase)
      . ' < ' . escapeshellarg($dumpFile);

    exec($loadCmd . ' 2>&1', $loadOutput, $loadCode);
    if ($loadCode !== 0) {
      $message = 'mysql load failed (' . $loadCode . ')';
      if (!empty($loadOutput)) {
        $message .= PHP_EOL . implode(PHP_EOL, $loadOutput);
      }
      $cli->error($message)->exit(1);
    }

    $cli->echo('Import dump loaded.');
  } finally {
    if (is_string($sourceDefaults) && is_file($sourceDefaults)) {
      @unlink($sourceDefaults);
    }
    if (is_string($targetDefaults) && is_file($targetDefaults)) {
      @unlink($targetDefaults);
    }
    if (is_string($dumpFile) && is_file($dumpFile)) {
      @unlink($dumpFile);
    }
  }

  eirMaybeSavePromptedConfig($cli, $home, $source['prompted']);
}

function eirMaybeSavePromptedConfig(CLI $cli, string $home, array $prompted): void
{
  if ($prompted === []) {
    return;
  }

  if (!$cli->promptBool('Save Import source settings to .config? Type yes: ', 'yes')) {
    return;
  }

  $configFile = $home . DIRECTORY_SEPARATOR . '.config';
  foreach ($prompted as $key => $value) {
    $stored = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    eirSetConfigValue($configFile, $key, $stored);
    $cli->echo('Wrote ' . $key . ' to .config');
  }
}

function eirRemoveDirectory(string $dir): void
{
  if (!is_dir($dir)) {
    return;
  }

  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );

  foreach ($iterator as $file) {
    $path = $file->getPathname();
    if ($file->isDir()) {
      if (!@rmdir($path)) {
        throw new RuntimeException('Could not remove directory: ' . $path);
      }
    } elseif (!@unlink($path)) {
      throw new RuntimeException('Could not remove file: ' . $path);
    }
  }

  if (!@rmdir($dir)) {
    throw new RuntimeException('Could not remove directory: ' . $dir);
  }
}

function eirEnsureDirectory(string $dir): void
{
  if (is_dir($dir)) {
    return;
  }

  if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
    throw new RuntimeException('Could not create directory: ' . $dir);
  }
}

function eirWriteGitkeepFile(string $dir): void
{
  eirEnsureDirectory($dir);
  $keep = $dir . DIRECTORY_SEPARATOR . '.gitkeep';
  if (is_file($keep)) {
    return;
  }

  if (file_put_contents($keep, '') === false) {
    throw new RuntimeException('Could not write ' . $keep);
  }
}

function eirIsWindowsJunction(string $path): bool
{
  if (PHP_OS_FAMILY !== 'Windows' || !is_dir($path)) {
    return false;
  }

  exec('fsutil reparsepoint query ' . escapeshellarg($path) . ' 2>&1', $output, $code);

  return $code === 0;
}

function eirDetachLink(string $path): void
{
  if (is_link($path)) {
    if (!@unlink($path) && PHP_OS_FAMILY === 'Windows') {
      exec('cmd /c rmdir ' . escapeshellarg($path), $out, $code);
      if ($code !== 0) {
        throw new RuntimeException('Could not remove link: ' . $path);
      }
    }
    return;
  }

  if (eirIsWindowsJunction($path)) {
    exec('cmd /c rmdir ' . escapeshellarg($path), $out, $code);
    if ($code !== 0) {
      throw new RuntimeException('Could not remove junction: ' . $path);
    }
  }
}

function eirLinkPointsTo(string $link, string $target): bool
{
  $targetReal = realpath($target);
  if ($targetReal === false) {
    return false;
  }

  if (is_link($link)) {
    $resolved = realpath($link);
    return $resolved !== false && strcasecmp($resolved, $targetReal) === 0;
  }

  if (!eirIsWindowsJunction($link)) {
    return false;
  }

  $resolved = realpath($link);
  return $resolved !== false && strcasecmp($resolved, $targetReal) === 0;
}

function eirEnsureJunction(CLI $cli, string $link, string $target): void
{
  eirEnsureDirectory($target);
  $targetReal = realpath($target);
  if ($targetReal === false) {
    throw new RuntimeException('Media folder missing: ' . $target);
  }

  if (eirLinkPointsTo($link, $targetReal)) {
    $cli->echo('Public link ok: ' . $link);
    return;
  }

  if (is_link($link) || eirIsWindowsJunction($link)) {
    eirDetachLink($link);
  } elseif (is_dir($link)) {
    $items = array_values(array_diff(scandir($link) ?: [], ['.', '..']));
    $onlyGitkeep = ($items === [] || $items === ['.gitkeep']);
    if (!$onlyGitkeep) {
      throw new RuntimeException('Cannot create public media link; ' . $link . ' is a real folder with files');
    }
    eirRemoveDirectory($link);
  } elseif (is_file($link)) {
    if (!@unlink($link)) {
      throw new RuntimeException('Could not remove file occupying ' . $link);
    }
  }

  if (PHP_OS_FAMILY === 'Windows') {
    exec('cmd /c mklink /J ' . escapeshellarg($link) . ' ' . escapeshellarg($targetReal), $out, $code);
    if ($code !== 0) {
      throw new RuntimeException('Could not create junction ' . $link . ' → ' . $targetReal);
    }
  } elseif (!@symlink($targetReal, $link)) {
    throw new RuntimeException('Could not create symlink ' . $link . ' → ' . $targetReal);
  }

  $cli->echo('Linked ' . $link . ' → ' . $targetReal);
}

function eirEnsurePublicMediaLinks(CLI $cli, string $home, string $site): void
{
  try {
    $public = $site . DIRECTORY_SEPARATOR . 'public';
    eirEnsureDirectory($public);

    $imagesFolder = (string) $cli->configOptional('EIR_IMAGES_FOLDER', 'images');
    $filesFolder = (string) $cli->configOptional('EIR_FILES_FOLDER', 'files');

    foreach ([$imagesFolder, $filesFolder] as $folder) {
      eirEnsureJunction(
        $cli,
        $public . DIRECTORY_SEPARATOR . $folder,
        $home . DIRECTORY_SEPARATOR . $folder
      );
    }
  } catch (RuntimeException $e) {
    $cli->error($e->getMessage())->exit(1);
  }
}

/**
 * Replace $dest with $source (same-volume rename). Restores $dest if the swap fails.
 */
function eirReplaceDirectory(string $source, string $dest): void
{
  if (!is_dir($source)) {
    throw new RuntimeException('Import staging folder missing: ' . $source);
  }

  $old = $dest . '--old';
  eirRemoveDirectory($old);

  if (is_dir($dest) && !rename($dest, $old)) {
    throw new RuntimeException('Could not move ' . $dest . ' aside');
  }

  if (!rename($source, $dest)) {
    if (is_dir($old) && !is_dir($dest)) {
      rename($old, $dest);
    }
    throw new RuntimeException('Could not move ' . $source . ' → ' . $dest);
  }

  eirRemoveDirectory($old);
}

function eirSshLikeRunnable(string $binary): bool
{
  if ($binary === '') {
    return false;
  }

  exec(escapeshellarg($binary) . ' -V 2>&1', $output, $code);
  $text = implode("\n", $output);

  return $code === 0 || str_contains($text, 'OpenSSH');
}

function eirIsSafeSshHost(string $host): bool
{
  return (bool) preg_match('/^[A-Za-z0-9.-]+$/', $host);
}

function eirIsSafeSshUser(string $user): bool
{
  return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $user);
}

function eirNormalizeImportSshPath(string $path): string
{
  $path = str_replace('\\', '/', trim($path));
  if ($path !== '/' && $path !== '~') {
    $path = rtrim($path, '/');
  }

  return $path;
}

function eirIsSafeRemotePath(string $path): bool
{
  if ($path === '' || str_contains($path, '..')) {
    return false;
  }

  if ($path === '~') {
    return true;
  }

  // Absolute, home-relative (~/…), or a path from SSH login (e.g. production).
  return (bool) preg_match('#^(?:~/|/)?[A-Za-z0-9._][-A-Za-z0-9_./]*$#', $path);
}

function eirShSingleQuote(string $value): string
{
  return "'" . str_replace("'", "'\\''", $value) . "'";
}

/**
 * @return list<string>
 */
function eirRemoteMediaFolderCandidates(CLI $cli, string $kind, string $localFolder): array
{
  $key = $kind === 'images' ? 'EIR_IMPORT_SSH_IMAGES_FOLDER' : 'EIR_IMPORT_SSH_FILES_FOLDER';
  $configured = (string) $cli->configOptional($key, '');
  if ($configured !== '') {
    return [$configured];
  }

  $legacy = $kind === 'images' ? 'public_images' : 'public_files';
  if ($legacy === $localFolder) {
    return [$localFolder];
  }

  return [$localFolder, $legacy];
}

function eirFirstPresentFolder(array $candidates, array $present): ?string
{
  foreach ($candidates as $candidate) {
    if (in_array($candidate, $present, true)) {
      return $candidate;
    }
  }

  return null;
}

function eirResolveRemoteImportPath(CLI $cli, string $sshPrefix, string $path): string
{
  $remote = 'p=' . eirShSingleQuote($path)
    . '; case "$p" in'
    . ' ~) t=$HOME ;;'
    . ' ~/*) t=$HOME/${p#~/} ;;'
    . ' /*) t=$p ;;'
    . ' *) t=$HOME/$p ;;'
    . ' esac;'
    . ' if [ -d "$t" ]; then echo EIR_PWD:$t; else echo EIR_NOPATH:$t; exit 1; fi';

  $text = eirSshRun($cli, $sshPrefix, $remote, 'Could not resolve remote Environment path');
  foreach (preg_split('/\R/', $text) ?: [] as $line) {
    $line = trim($line);
    if (!str_starts_with($line, 'EIR_PWD:')) {
      continue;
    }

    $resolved = substr($line, 8);
    if (eirIsSafeRemotePath($resolved) && str_starts_with($resolved, '/')) {
      return $resolved;
    }
  }

  $cli->error('Remote path did not resolve to a directory: ' . $path)->exit(1);
}

function eirIsSafeFolderName(string $name): bool
{
  return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $name);
}

function eirEnsureKnownHost(CLI $cli, string $host, string $port = '22'): void
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
  $needle = ($port === '22') ? $host : '[' . $host . ']:' . $port;
  if (str_contains($existing, $needle)) {
    return;
  }

  $scan = 'ssh-keyscan';
  if ($port !== '22') {
    $scan .= ' -p ' . escapeshellarg($port);
  }
  $scan .= ' -t ed25519,ecdsa,rsa ' . escapeshellarg($host);

  exec($scan . ' 2>&1', $scanOut, $scanCode);
  $keys = [];
  foreach ($scanOut as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
      continue;
    }
    if (str_contains($line, $host)) {
      $keys[] = $line;
    }
  }

  if ($keys === []) {
    $cli->error('ssh-keyscan did not return host keys for ' . $host)->exit(1);
  }

  $append = implode(PHP_EOL, $keys) . PHP_EOL;
  if (file_put_contents($knownHosts, $append, FILE_APPEND) === false) {
    $cli->error('Could not write ' . $knownHosts)->exit(1);
  }
}

/**
 * @return array{host: string, port: string, user: string, path: string, identity: string, prompted: array<string, string>}
 */
function eirResolveImportSsh(CLI $cli): array
{
  $prompted = [];

  $host = (string) $cli->configOptional('EIR_IMPORT_SSH_HOST', '');
  if ($host === '') {
    $host = $cli->promptLine('Import SSH host');
    while ($host === '') {
      $cli->echo('Host cannot be empty.');
      $host = $cli->promptLine('Import SSH host');
    }
    $prompted['EIR_IMPORT_SSH_HOST'] = $host;
  }

  $port = (string) $cli->configOptional('EIR_IMPORT_SSH_PORT', '');
  if ($port === '') {
    $port = $cli->promptLine('Import SSH port', '22');
    if ($port === '') {
      $port = '22';
    }
    $prompted['EIR_IMPORT_SSH_PORT'] = $port;
  }

  $user = (string) $cli->configOptional('EIR_IMPORT_SSH_USER', '');
  if ($user === '') {
    $user = $cli->promptLine('Import SSH user');
    while ($user === '') {
      $cli->echo('User cannot be empty.');
      $user = $cli->promptLine('Import SSH user');
    }
    $prompted['EIR_IMPORT_SSH_USER'] = $user;
  }

  $path = (string) $cli->configOptional('EIR_IMPORT_SSH_PATH', '');
  if ($path === '') {
    $path = $cli->promptLine('Remote Environment path from SSH login, e.g. production');
    while ($path === '') {
      $cli->echo('Path cannot be empty.');
      $path = $cli->promptLine('Remote Environment path from SSH login, e.g. production');
    }
    $path = eirNormalizeImportSshPath($path);
    $prompted['EIR_IMPORT_SSH_PATH'] = $path;
  } else {
    $path = eirNormalizeImportSshPath($path);
  }

  $identity = (string) $cli->configOptional('EIR_IMPORT_SSH_IDENTITY', '');

  return [
    'host' => $host,
    'port' => $port,
    'user' => $user,
    'path' => $path,
    'identity' => $identity,
    'prompted' => $prompted,
  ];
}

function eirSshCommandPrefix(string $ssh, array $source): string
{
  $cmd = escapeshellarg($ssh)
    . ' -o BatchMode=yes'
    . ' -o StrictHostKeyChecking=yes'
    . ' -o ConnectTimeout=15';

  if ((string) $source['port'] !== '22') {
    $cmd .= ' -p ' . escapeshellarg((string) $source['port']);
  }

  if ($source['identity'] !== '') {
    $cmd .= ' -i ' . escapeshellarg($source['identity']);
  }

  $cmd .= ' ' . escapeshellarg($source['user'] . '@' . $source['host']);

  return $cmd;
}

function eirSshRun(CLI $cli, string $sshPrefix, string $remoteCommand, string $failMessage): string
{
  $cmd = $sshPrefix . ' ' . escapeshellarg($remoteCommand) . ' 2>&1';
  exec($cmd, $output, $code);
  $text = implode(PHP_EOL, $output);

  if ($code !== 0) {
    $message = $failMessage . ' (' . $code . ')';
    if ($text !== '') {
      $message .= PHP_EOL . $text;
    }
    $cli->error($message)->exit(1);
  }

  return $text;
}

/**
 * Copy remote Environment images/ and files/ into this Environment (local only).
 */
function eirImportMedia(CLI $cli, string $home): void
{
  $ssh = (string) $cli->configOptional('EIR_SYS_SSH', 'ssh');
  $tar = (string) $cli->configOptional('EIR_SYS_TAR', 'tar');

  if (!eirSshLikeRunnable($ssh)) {
    $cli->error(
      'ssh binary not runnable: ' . $ssh
      . '. Set EIR_SYS_SSH in .config to the full path (OpenSSH). Key authentication is required.'
    )->exit(1);
  }

  if (!eirCommandRunnable($tar)) {
    $cli->error(
      'tar binary not runnable: ' . $tar
      . '. Set EIR_SYS_TAR in .config to the full path.'
    )->exit(1);
  }

  $imagesFolder = (string) $cli->configOptional('EIR_IMAGES_FOLDER', 'images');
  $filesFolder = (string) $cli->configOptional('EIR_FILES_FOLDER', 'files');

  if (!eirIsSafeFolderName($imagesFolder) || !eirIsSafeFolderName($filesFolder)) {
    $cli->error('EIR_IMAGES_FOLDER / EIR_FILES_FOLDER contain unsafe characters')->exit(1);
  }

  $source = eirResolveImportSsh($cli);

  if (!eirIsSafeSshHost($source['host'])) {
    $cli->error('Import SSH host is not a hostname: ' . $source['host'])->exit(1);
  }
  if (!ctype_digit((string) $source['port']) || (int) $source['port'] < 1 || (int) $source['port'] > 65535) {
    $cli->error('Import SSH port is invalid: ' . $source['port'])->exit(1);
  }
  if (!eirIsSafeSshUser($source['user'])) {
    $cli->error('Import SSH user contains unsafe characters')->exit(1);
  }
  if (!eirIsSafeRemotePath($source['path'])) {
    $cli->error('Remote Environment path must be a simple path without .., e.g. production or /home/user/production')->exit(1);
  }
  if ($source['identity'] !== '' && !is_file($source['identity'])) {
    $cli->error('SSH identity file not found: ' . $source['identity'])->exit(1);
  }

  eirEnsureKnownHost($cli, $source['host'], (string) $source['port']);
  $sshPrefix = eirSshCommandPrefix($ssh, $source);

  $cli->echo('Resolving remote path ' . $source['path'] . ' ...');
  $source['path'] = eirResolveRemoteImportPath($cli, $sshPrefix, $source['path']);
  $cli->echo('Remote Environment: ' . $source['path']);

  $imageCandidates = eirRemoteMediaFolderCandidates($cli, 'images', $imagesFolder);
  $fileCandidates = eirRemoteMediaFolderCandidates($cli, 'files', $filesFolder);
  foreach (array_merge($imageCandidates, $fileCandidates) as $candidate) {
    if (!eirIsSafeFolderName($candidate)) {
      $cli->error('Remote media folder name contains unsafe characters: ' . $candidate)->exit(1);
    }
  }

  $cli->echo('Checking remote folders...');
  $checkFolders = array_values(array_unique(array_merge($imageCandidates, $fileCandidates)));
  $remoteCheck = 'for d in '
    . implode(' ', $checkFolders)
    . '; do if [ -d ' . eirShSingleQuote($source['path']) . '/$d ]; then echo EIR_OK:$d; else echo EIR_MISSING:$d; fi; done';

  $checkText = eirSshRun($cli, $sshPrefix, $remoteCheck, 'SSH could not list remote media folders');
  $present = [];
  foreach (preg_split('/\R/', $checkText) ?: [] as $line) {
    $line = trim($line);
    if (str_starts_with($line, 'EIR_OK:')) {
      $present[] = substr($line, 7);
    }
  }

  $mappings = [];
  $remoteImages = eirFirstPresentFolder($imageCandidates, $present);
  $remoteFiles = eirFirstPresentFolder($fileCandidates, $present);
  if ($remoteImages !== null) {
    $mappings[] = ['remote' => $remoteImages, 'local' => $imagesFolder];
  }
  if ($remoteFiles !== null) {
    $mappings[] = ['remote' => $remoteFiles, 'local' => $filesFolder];
  }

  if ($mappings === []) {
    $cli->error(
      'Remote Environment has none of '
      . implode(', ', $checkFolders)
      . ' under ' . $source['path']
    )->exit(1);
  }

  $cli->echo(
    'Import will REPLACE local '
    . $imagesFolder . '/ and ' . $filesFolder . '/'
    . ' with copies from '
    . $source['user'] . '@' . $source['host'] . ':' . $source['path']
  );
  foreach ($mappings as $map) {
    $cli->echo('  ' . $map['remote'] . '/ → ' . $map['local'] . '/');
  }

  if (!$cli->promptBool('Type yes to continue: ', 'yes')) {
    $cli->error('Import cancelled')->exit(1);
  }

  $staging = $home . DIRECTORY_SEPARATOR . 'eir-media-import';
  $tarFile = $home . DIRECTORY_SEPARATOR . 'eir-media-import.tar';
  $errFile = $home . DIRECTORY_SEPARATOR . 'eir-media-import.err';

  try {
    eirRemoveDirectory($staging);
    eirEnsureDirectory($staging);
    if (is_file($tarFile)) {
      @unlink($tarFile);
    }
    if (is_file($errFile)) {
      @unlink($errFile);
    }

    $remoteNames = array_column($mappings, 'remote');
    $remoteTar = 'tar -C ' . eirShSingleQuote($source['path']) . ' -cf - ' . implode(' ', $remoteNames);
    $cli->echo('Copying remote ' . implode(' and ', $remoteNames) . ' ...');

    $dumpCmd = $sshPrefix
      . ' ' . escapeshellarg($remoteTar)
      . ' > ' . escapeshellarg($tarFile)
      . ' 2> ' . escapeshellarg($errFile);

    exec($dumpCmd, $dumpOutput, $dumpCode);
    $errText = is_file($errFile) ? trim((string) file_get_contents($errFile)) : '';

    if ($dumpCode !== 0) {
      $message = 'Remote tar over SSH failed (' . $dumpCode . ')';
      if ($errText !== '') {
        $message .= PHP_EOL . $errText;
      }
      $cli->error($message)->exit(1);
    }

    if (!is_file($tarFile) || filesize($tarFile) === 0) {
      $message = 'Remote tar over SSH produced an empty archive';
      if ($errText !== '') {
        $message .= PHP_EOL . $errText;
      }
      $cli->error($message)->exit(1);
    }

    $cli->echo('Extracting archive...');
    $extractCmd = escapeshellarg($tar)
      . ' -xf ' . escapeshellarg($tarFile)
      . ' -C ' . escapeshellarg($staging)
      . ' 2>&1';

    exec($extractCmd, $extractOutput, $extractCode);
    if ($extractCode !== 0) {
      $message = 'tar extract failed (' . $extractCode . ')';
      if (!empty($extractOutput)) {
        $message .= PHP_EOL . implode(PHP_EOL, $extractOutput);
      }
      $cli->error($message)->exit(1);
    }

    foreach ($mappings as $map) {
      $staged = $staging . DIRECTORY_SEPARATOR . $map['remote'];
      $dest = $home . DIRECTORY_SEPARATOR . $map['local'];
      if (!is_dir($staged)) {
        $cli->error('Archive did not contain ' . $map['remote'] . '/')->exit(1);
      }
      $cli->echo('Replacing local ' . $map['local'] . '/');
      eirReplaceDirectory($staged, $dest);
      eirWriteGitkeepFile($dest);
      if ($map['remote'] !== $map['local']) {
        $leftover = $home . DIRECTORY_SEPARATOR . $map['remote'];
        if (is_dir($leftover)) {
          $cli->echo('Removing leftover ' . $map['remote'] . '/');
          eirRemoveDirectory($leftover);
        }
      }
    }
  } catch (RuntimeException $e) {
    $cli->error($e->getMessage())->exit(1);
  } finally {
    if (is_file($tarFile)) {
      @unlink($tarFile);
    }
    if (is_file($errFile)) {
      @unlink($errFile);
    }
    try {
      eirRemoveDirectory($staging);
    } catch (RuntimeException $e) {
      $cli->echo('Could not remove staging folder: ' . $e->getMessage());
    }
  }

  $cli->echo('Media Import complete.');
  eirEnsurePublicMediaLinks($cli, $home, $home . DIRECTORY_SEPARATOR . (string) $cli->configOptional('EIR_SITE_FOLDER', 'app'));
  eirMaybeSavePromptedConfig($cli, $home, $source['prompted']);
}
