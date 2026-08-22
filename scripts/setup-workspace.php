<?php

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "Run from the command line: php eir/scripts/setup-workspace.php\n");
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

$skillSubmodules = [
  '.agents/skills/skills__productivity' => 'git@github.com:MarkVinkNL/skills__productivity.git',
  '.agents/skills/skills__engineering' => 'git@github.com:MarkVinkNL/skills__engineering.git',
  '.agents/skills/skills__lux' => 'git@github.com:MarkVinkNL/skills__lux.git',
];

function eirCreateWorkspaceConfig(CLI $cli, string $eir, string $home, string $DS): void
{
  $configFile = $home . $DS . '.config';
  if (file_exists($configFile)) {
    $cli->echo('.config already exists — leaving it');
    return;
  }

  $envType = 'server';
  if ($cli->promptBool("Is this a local environment? Type yes or local (anything else = server): ", 'yes')) {
    $envType = 'local';
  }

  $template = $eir . $DS . 'files' . $DS . '.config.' . $envType;
  if (!file_exists($template)) {
    $cli->error('No config template found: ' . $template)->exit(1);
  }

  if (!copy($template, $configFile)) {
    $cli->error('Could not copy config file')->exit(1);
  }

  $cli->echo('Using ' . $envType . ' template → ' . $configFile);

  $currentRepo = eirReadConfigValue($configFile, 'EIR_GIT_REPOSITORY') ?: '';
  $repo = $cli->promptLine('Application git repository', $currentRepo);
  while ($repo === '') {
    $cli->echo('Repository cannot be empty.');
    $repo = $cli->promptLine('Application git repository', $currentRepo);
  }

  eirSetConfigValue($configFile, 'EIR_GIT_REPOSITORY', $repo);
}

function eirWriteIfMissing(CLI $cli, string $target, string $source): void
{
  if (file_exists($target)) {
    $cli->echo('Exists, skipping: ' . $target);
    return;
  }

  if (!copy($source, $target)) {
    $cli->error('Could not copy ' . $source . ' → ' . $target)->exit(1);
  }

  $cli->echo('Wrote ' . $target);
}

function eirWriteGitkeep(CLI $cli, string $dir): void
{
  if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    $cli->error('Could not create ' . $dir)->exit(1);
  }

  $keep = $dir . DIRECTORY_SEPARATOR . '.gitkeep';
  if (file_exists($keep)) {
    return;
  }

  if (file_put_contents($keep, '') === false) {
    $cli->error('Could not write ' . $keep)->exit(1);
  }

  $cli->echo('Wrote ' . $keep);
}

function eirAddSubmodule(CLI $cli, string $git, string $url, string $path): void
{
  $gitMarker = $path . DIRECTORY_SEPARATOR . '.git';
  if (is_file($gitMarker) || is_dir($gitMarker)) {
    $cli->echo('Submodule already present: ' . $path);
    return;
  }

  $cli->echo('Adding submodule ' . $path);
  execOrFail($git . ' submodule add ' . escapeshellarg($url) . ' ' . escapeshellarg($path), $cli);
}

$cli->cd($home)->echo('Setting up workspace in ' . $home);

if (!execCheck('git rev-parse --git-dir 2>&1')) {
  $cli->error('Environment root is not a git repository (install.php should have run git init)')->exit(1);
}

eirCreateWorkspaceConfig($cli, $eir, $home, $DS);

$cli->setupConfig($home . $DS . '.config');
$git = $cli->config('EIR_SYS_GIT');

if (!eirCommandRunnable($git)) {
  $hint = 'Set EIR_SYS_GIT to a git binary that exists on this machine.';
  if (PHP_OS_FAMILY === 'Windows' && str_contains((string) $git, '/')) {
    $hint = 'On Windows, use the local template (type yes or local) so EIR_SYS_GIT=git. Delete .config and run install again if the server template was copied by mistake.';
  }
  $cli->error('Git not found: ' . $git . PHP_EOL . $hint)->exit(1);
}

eirEnsureGithubSsh($cli);

foreach ($skillSubmodules as $path => $url) {
  eirAddSubmodule($cli, $git, $url, $path);
}

eirWriteIfMissing($cli, $home . $DS . '.gitignore', $eir . $DS . 'workspace.gitignore');
eirWriteIfMissing($cli, $home . $DS . 'AGENTS.md', $eir . $DS . 'AGENTS.md');
eirWriteIfMissing($cli, $home . $DS . 'README.md', $eir . $DS . 'files' . $DS . 'workspace.README.md');
eirWriteGitkeep($cli, $home . $DS . 'images');
eirWriteGitkeep($cli, $home . $DS . 'files');

$add = [
  '.gitmodules',
  'eir',
  '.agents',
  '.gitignore',
  'AGENTS.md',
  'README.md',
  'images/.gitkeep',
  'files/.gitkeep',
];

execOrFail($git . ' add -- ' . implode(' ', array_map('escapeshellarg', $add)), $cli);

$status = execValue($git . ' status --porcelain');
if ($status === false || $status === '') {
  $cli->echo('Nothing to commit.');
} else {
  execOrFail($git . ' commit -m ' . escapeshellarg('Setting up workspace'), $cli);
  $cli->echo('Committed: Setting up workspace');
}

$cli
  ->echo('')
  ->echo('Workspace template is ready.')
  ->echo('Finish .config (database, URL, secrets), then:')
  ->echo('  php eir/run.php')
  ->exit(0);
