<?php

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "Run from the command line: php eir/run.php\n");
  exit(1);
}

include_once __DIR__ . '/helpers.php';

include_once __DIR__ . '/cli.php';

$eir = dirname(realpath(dirname(__FILE__)));
$home = dirname($eir);
$DS = DIRECTORY_SEPARATOR;

$cli
  ->setEir($eir)
  ->setHome($home);

$cli->argument(['-h', '-help', '--help'], function () use ($cli) {
  $cli
    ->echo('Eir — Environment deploy tool')
    ->echo('')
    ->echo('  php eir/run.php          Initial install (no app/), local refresh, or server Deploy')
    ->echo('  php eir/run.php -u       Upgrade Eir (workspace pull only if a remote exists; does not touch app/)')
    ->echo('  php eir/run.php -f       Force Deploy even if last_commit matches remote')
    ->echo('  php eir/run.php -r       Rollback: swap backup/ ↔ app/')
    ->echo('  php eir/run.php -s       Silent')
    ->exit(0);
});

$cli
  ->setupLog($home . $DS . 'deploy.log')
  ->setupConfig($home . $DS . '.config')
  ->cd($home)
  ->clear();

$config = $cli->config();
$siteFolder = $cli->config('EIR_SITE_FOLDER');
$site = $home . $DS . $siteFolder;
$new = $home . $DS . 'new_' . $siteFolder;
$backup = $home . $DS . 'backup';
$git = $cli->config('EIR_SYS_GIT');
$php = $cli->config('EIR_SYS_PHP');
$composer = $cli->config('EIR_SYS_COMPOSER');
$sysEnv = $cli->config('EIR_SYS_ENV');
$repository = $cli->config('EIR_GIT_REPOSITORY');
$branch = $cli->config('EIR_GIT_REMOTE_BRANCH');

$cli
  ->echo('---------------------------------------------------------------')
  ->echo('| EIR  : ' . $eir)
  ->echo('| home : ' . $home)
  ->echo('| site : ' . $site)
  ->echo('| env  : ' . $sysEnv)
  ->echo('---------------------------------------------------------------' . PHP_EOL);

/**
 * Upgrade Eir. Workspace git is local-only: pull only if a remote exists.
 * Does not touch Application (app/), including a local app submodule.
 */
$cli->argument(['-u', '-upgrade'], function () use ($cli, $home, $eir, $git, $DS, $siteFolder) {
  $cli->cd($home);
  eirEnsureGithubSsh($cli);

  if (!execCheck($git . ' rev-parse --git-dir 2>&1')) {
    $cli->error('Environment root is not a git repository')->exit(1);
  }

  if (eirWorkspaceHasRemote($git)) {
    $cli->echo('Pulling workspace...');
    exec($git . ' pull', $pullOutput, $pullCode);
    if ($pullCode !== 0) {
      $cli->echo('Workspace pull skipped (no upstream, or pull failed). Continuing with Eir update.');
      if (!empty($pullOutput)) {
        $cli->echo(implode(PHP_EOL, $pullOutput));
      }
    }
  } else {
    $cli->echo('No workspace remote — updating Eir only.');
  }

  $eirGit = $eir . $DS . '.git';
  if (!is_dir($eirGit) && !is_file($eirGit)) {
    $cli->echo('Initializing Eir submodule...');
    execOrFail($git . ' submodule update --init -- eir', $cli);
  }

  $cli->cd($eir);
  $cli->echo('Updating Eir to origin/main...');
  execOrFail($git . ' fetch --all', $cli);
  execOrFail($git . ' reset --hard origin/main', $cli);

  $cli
    ->echo('Upgrade complete. Application (' . $siteFolder . '/) was not touched.')
    ->exit(0);
});

/**
 * Rollback: swap backup ↔ app. Does not change last_commit or reverse migrations.
 */
$cli->argument(['-r', '-rollback', '--rollback'], function () use ($cli, $home, $site, $backup, $DS, $siteFolder) {
  $cli->cd($home);

  if (!is_dir($backup)) {
    $cli->error('No backup/ directory — nothing to roll back')->exit(1);
  }

  if (!is_dir($site)) {
    $cli->error('No ' . $siteFolder . '/ directory to swap with backup')->exit(1);
  }

  $rolling = $home . $DS . $siteFolder . '--rolling';
  if (is_dir($rolling)) {
    execOrFail('rm -rf ' . escapeshellarg($rolling), $cli);
  }

  $cli->echo('Rolling back: backup/ ↔ ' . $siteFolder . '/');
  execOrFail('mv ' . escapeshellarg($site) . ' ' . escapeshellarg($rolling), $cli);
  execOrFail('mv ' . escapeshellarg($backup) . ' ' . escapeshellarg($site), $cli);
  execOrFail('mv ' . escapeshellarg($rolling) . ' ' . escapeshellarg($backup), $cli);

  $cli->echo('Rollback complete. last_commit unchanged.')->exit(0);
});

/**
 * Compile .env into $targetDir/.env from templates + config overlay + optional APP_KEY preserve.
 */
function eirCompileEnv(CLI $cli, array $config, string $targetDir, ?string $liveSiteDir = null): array
{
  $cli->echo('Generating ENV file for ' . $targetDir);

  // .env.example supplies the key schema; later files overwrite values.
  $envFormat = array_merge(
    parseEnvFile(findNamedFile($targetDir, '.env.example')),
    parseEnvFile(findNamedFile($targetDir, '.env.default')),
    parseEnvFile(findNamedFile($targetDir, '.env.template'))
  );

  $envFormat = array_filter($envFormat, function ($key) {
    return strpos($key, '#') !== 0;
  }, ARRAY_FILTER_USE_KEY);

  foreach ($config as $key => $value) {
    if (str_starts_with($key, 'EIR_')) {
      continue;
    }
    if (isset($envFormat[$key])) {
      $envFormat[$key] = $value;
    }
  }

  $preserveFrom = $liveSiteDir ?: $targetDir;
  $liveEnv = $preserveFrom . DIRECTORY_SEPARATOR . '.env';
  if (file_exists($liveEnv)) {
    $original = parseEnvFile($liveEnv);
    if (!empty($original['APP_KEY'])) {
      $envFormat['APP_KEY'] = preg_replace('/=""/', '=', $original['APP_KEY']);
    }
  }

  if (empty($envFormat)) {
    $cli->error('No env keys found (missing .env.example/.env.default/.env.template?)')->exit(1);
  }

  arrayToEnvFile($envFormat, $targetDir . DIRECTORY_SEPARATOR . '.env');
  return $envFormat;
}

function eirComposerInstall(CLI $cli, string $composer, string $sysEnv, string $cwd): void
{
  chdir($cwd);
  $params = ($sysEnv === 'production') ? ' --no-dev -o' : ' -o';
  $cli->echo('composer install' . $params);
  execOrFail($composer . ' install' . $params, $cli);
}

function eirMigrateAndClear(CLI $cli, string $php, string $cwd): void
{
  chdir($cwd);
  $cli->echo('artisan migrate --force');
  execOrFail($php . ' artisan migrate --force', $cli);
  $cli->echo('artisan cache:clear');
  execOrFail($php . ' artisan cache:clear', $cli);
}

function eirEnsureAppKey(CLI $cli, string $php, string $cwd, array $envFormat): void
{
  if (!empty($envFormat['APP_KEY'])) {
    return;
  }

  chdir($cwd);
  $cli->echo('artisan key:generate');
  execOrFail($php . ' artisan key:generate', $cli);
}

function eirCloneApp(CLI $cli, string $git, string $repository, string $branch, string $target, string $sysEnv): void
{
  eirEnsureGithubSsh($cli);

  // Local: full fetch refspec so other branches are available for development.
  // Server: single-branch keeps Deploy clones small.
  $singleBranch = ($sysEnv === 'local') ? '' : ' --single-branch';
  $cli->echo('Cloning ' . $repository . ' (' . $branch . ')' . ($singleBranch === '' ? ' [all remotes]' : ' [single-branch]') . ' → ' . $target);
  execOrFail(
    $git . ' clone -b ' . escapeshellarg($branch) . $singleBranch . ' ' . escapeshellarg($repository) . ' ' . escapeshellarg($target),
    $cli
  );

  chdir($target);
  execOrFail($git . ' submodule update --init --recursive', $cli);
}

/**
 * Initial install when app/ is missing.
 * Clone is the same; locally the clone is then registered as a workspace submodule.
 */
function eirInitialInstall(
  CLI $cli,
  array $config,
  string $home,
  string $site,
  string $siteFolder,
  string $git,
  string $php,
  string $composer,
  string $sysEnv,
  string $repository,
  string $branch
): void {
  $cli->echo('Initial install: ' . $site . ' is missing');

  if (is_dir($site)) {
    $cli->error('Site folder unexpectedly exists')->exit(1);
  }

  eirCloneApp($cli, $git, $repository, $branch, $site, $sysEnv);

  if ($sysEnv === 'local') {
    eirEnsureLocalAppSubmodule($cli, $git, $home, $siteFolder, $repository, $branch);
  }

  $envFormat = eirCompileEnv($cli, $config, $site);
  eirComposerInstall($cli, $composer, $sysEnv, $site);
  eirEnsureAppKey($cli, $php, $site, $envFormat);
  eirMigrateAndClear($cli, $php, $site);
  writeLastCommit($site, $home, $git, $cli);

  $cli->echo('Initial install complete.')->exit(0);
}

/**
 * Local refresh when app/ exists.
 */
function eirLocalExisting(
  CLI $cli,
  array $config,
  string $home,
  string $site,
  string $siteFolder,
  string $git,
  string $composer,
  string $sysEnv,
  string $php,
  string $repository,
  string $branch
): void {
  $cli->echo('Local refresh');
  eirEnsureLocalAppSubmodule($cli, $git, $home, $siteFolder, $repository, $branch);
  $envFormat = eirCompileEnv($cli, $config, $site, $site);

  if (!is_dir($site . DIRECTORY_SEPARATOR . 'vendor')) {
    eirComposerInstall($cli, $composer, $sysEnv, $site);
  }

  eirEnsureAppKey($cli, $php, $site, $envFormat);

  $cli->echo('Local refresh complete.')->exit(0);
}

/**
 * Server Deploy when app/ exists.
 */
function eirServerDeploy(
  CLI $cli,
  array $config,
  string $home,
  string $site,
  string $new,
  string $backup,
  string $siteFolder,
  string $git,
  string $php,
  string $composer,
  string $sysEnv,
  string $repository,
  string $branch
): void {
  $DS = DIRECTORY_SEPARATOR;
  $cli->cd($site);

  do {
    if (argCheck(['-f', '-force'])) {
      break;
    }
    if (!is_dir($site)) {
      break;
    }
    if (!execCheck($git . ' rev-parse --git-dir 2>&1')) {
      break;
    }

    $lastCommitFile = $home . $DS . 'last_commit';
    if (!file_exists($lastCommitFile)) {
      break;
    }

    $remoteHash = remoteCommitHash($git, $repository, $branch);
    $deployedHash = trim((string) file_get_contents($lastCommitFile));

    if ($remoteHash && $remoteHash === $deployedHash) {
      $cli
        ->echo('No new commit — stop Deploy')
        ->echo($remoteHash)
        ->echo('Use -f or -force to redeploy')
        ->exit(0);
    }
  } while (0);

  $cli->cd($home);

  $backupOld = $home . $DS . 'backup--old';
  if (is_dir($backup)) {
    if (is_dir($backupOld)) {
      execOrFail('rm -rf ' . escapeshellarg($backupOld), $cli);
    }
    execOrFail('mv ' . escapeshellarg($backup) . ' ' . escapeshellarg($backupOld), $cli);
  }

  if (is_dir($new)) {
    execOrFail('rm -rf ' . escapeshellarg($new), $cli);
  }

  eirCloneApp($cli, $git, $repository, $branch, $new, $sysEnv);
  $envFormat = eirCompileEnv($cli, $config, $new, $site);
  eirComposerInstall($cli, $composer, $sysEnv, $new);
  eirEnsureAppKey($cli, $php, $new, $envFormat);

  // Migrate/cache on new_app — failure aborts without swap
  eirMigrateAndClear($cli, $php, $new);

  writeLastCommit($new, $home, $git, $cli);

  $cli->cd($home);
  $cli->echo('Swapping ' . $siteFolder . ' ↔ backup');

  if (is_dir($site)) {
    execOrFail('mv ' . escapeshellarg($site) . ' ' . escapeshellarg($backup), $cli);
  }

  execOrFail('mv ' . escapeshellarg($new) . ' ' . escapeshellarg($site), $cli);

  if (is_dir($backupOld)) {
    execOrFail('rm -rf ' . escapeshellarg($backupOld), $cli);
  }

  $cli->echo('Deploy complete.')->exit(0);
}

// --- Main -------------------------------------------------------------------

if (!is_dir($site)) {
  eirInitialInstall($cli, $config, $home, $site, $siteFolder, $git, $php, $composer, $sysEnv, $repository, $branch);
}

if ($sysEnv === 'local') {
  eirLocalExisting($cli, $config, $home, $site, $siteFolder, $git, $composer, $sysEnv, $php, $repository, $branch);
}

eirServerDeploy($cli, $config, $home, $site, $new, $backup, $siteFolder, $git, $php, $composer, $sysEnv, $repository, $branch);
