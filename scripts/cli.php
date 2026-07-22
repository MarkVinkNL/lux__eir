<?php

class CLI
{
  private $logFile;
  private $silent = false;
  private $settings = null;
  public $eir = null;
  public $home = null;

  public function __construct()
  {
    $this->argument(['-s', '-silent'], function () {
      $this->silent = true;
    });
  }

  public function setEir($eir)
  {
    $this->eir = $eir;
    return $this;
  }

  public function setHome($home)
  {
    $this->home = $home;
    return $this;
  }

  public function cd($path)
  {
    chdir($path);
    return $this;
  }

  public function echo($message)
  {
    if ($this->silent) {
      return $this;
    }

    echo $message . PHP_EOL;
    return $this;
  }

  public function error($message)
  {
    $this->echo('ERROR: ' . $message);
    $this->log('ERROR: ' . $message);
    return $this;
  }

  public function clear()
  {
    echo "\033[2J\033[;H";
    return $this;
  }

  public function argument(string|array $argument, $callback, $inverse = false): self
  {
    global $argc, $argv;

    if (PHP_SAPI !== 'cli') {
      return $this;
    }
    if (!isset($argc)) {
      return $this;
    }
    if ($argc <= 1 && !$inverse) {
      return $this;
    }

    foreach ($argv as $value) {
      if ((is_array($argument) && in_array($value, $argument, true)) || $value == $argument) {
        if ($inverse) {
          return $this;
        }
        $callback();
        return $this;
      }
    }

    if ($inverse) {
      $callback();
    }

    return $this;
  }

  public function exit($code = 0)
  {
    exit($code);
  }

  public function setupLog(string $logFile): self
  {
    $this->echo('Setting up log file');
    $this->logFile = $logFile;

    if (!file_exists($logFile)) {
      touch($logFile);
    }

    $logMaxSize = 50000;
    $logFileSize = file_exists($logFile) ? filesize($logFile) : 0;

    if ($logFileSize > $logMaxSize) {
      file_put_contents($logFile, '');
    }

    return $this;
  }

  public function log($message): self
  {
    if (empty($this->logFile)) {
      $this->echo('No log file set');
      return $this->exit();
    }

    file_put_contents($this->logFile, $message . PHP_EOL, FILE_APPEND);
    return $this;
  }

  public function setupConfig(string $configFile): self
  {
    $this->echo('Setting up config file');

    $this->settings = [];
    if (file_exists($configFile)) {
      $parsed = @parse_ini_file($configFile, false, INI_SCANNER_RAW);
      if ($parsed === false) {
        $this->error('Could not parse ' . $configFile);
        $this->error('PHP INI rules: no parentheses in comments; quote URLs like APP_URL="http://..."');
        $this->exit(1);
      }
      $this->settings = $parsed;
    }

    if (empty($this->settings)) {
      if (file_exists($configFile)) {
        $this->error('Config file exists but has no values: ' . $configFile);
        $this->exit(1);
      }

      $this->error('No config values found');

      $env_type = 'server';
      if ($this->promptBool("Is this a local environment?  Type 'yes' for local, all other responses choose server: ", 'yes')) {
        $env_type = 'local';
      }

      echo 'Using ' . $env_type . ' environment' . PHP_EOL;

      $template = $this->eir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . '.config.' . $env_type;
      if (!file_exists($template)) {
        $this->error('No config template found: ' . $template);
        $this->exit(1);
      }

      if (!copy($template, $this->home . DIRECTORY_SEPARATOR . '.config')) {
        $this->error('Could not copy config file');
        $this->exit(1);
      }

      $this->echo('Config file copied to ' . $this->home . DIRECTORY_SEPARATOR . '.config');
      $this->echo('Edit .config, then run Eir again.');
      $this->exit(0);
    }

    return $this;
  }

  public function config($variable = null)
  {
    if (empty($this->settings)) {
      $this->error('No config file set');
      return $this->exit(1);
    }

    if ($variable == null) {
      return $this->settings;
    }

    if (!isset($this->settings[$variable])) {
      $this->error("Config variable '$variable' not set");
      return $this->exit(1);
    }

    return $this->settings[$variable];
  }

  public function configOptional(string $variable, $default = null)
  {
    if (empty($this->settings)) {
      return $default;
    }

    return $this->settings[$variable] ?? $default;
  }

  private function promptBool($msg, $check)
  {
    echo $msg;

    $handle = fopen('php://stdin', 'r');
    $line = fgets($handle);

    return trim($line) == $check;
  }
}

$cli = new CLI();
