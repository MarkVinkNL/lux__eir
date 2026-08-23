# Environment

Created by Eir. Persistent `images/` / `files/`, config, and the Eir deploy tool.

This git repository is a **local workspace**. Servers are not installed by cloning it; they run `install.php` (see below).

Locally the Application (`app/`) is a submodule of this workspace. On a server, Eir clones `app/` as a plain directory so Deploy can replace it.

## Create another Environment

Eir must be a **public** GitHub repository for this `copy()` URL to work. Use this on servers and on a new local machine.

```bash
php -r "copy('https://raw.githubusercontent.com/MarkVinkNL/lux__eir/main/install.php', 'install.php');"
php install.php
```

## First run (this machine)

1. Edit `.config` — database, URL, and remaining secrets.
2. `php eir/run.php`

## Copy this workspace (local only)

```bash
git clone --recurse-submodules <this-repo-url> my-env
cd my-env
php eir/run.php
```

If you cloned without submodules:

```bash
git submodule update --init --recursive
```

Do not use this clone path on a server.

## Usage

```bash
php eir/run.php          # initial install / local refresh / server Deploy
php eir/run.php -u       # upgrade Eir (workspace pull only if a remote exists; does not touch app/)
php eir/run.php -f       # force Deploy
php eir/run.php -r       # rollback
php eir/run.php -h       # help
```

See [eir/readme.md](eir/readme.md) for Deploy details.

## Layout

```
{environment}/
  .config       # local secrets (not in git)
  eir/          # submodule
  app/          # Application (submodule locally; clone on servers)
  images/
  files/
```
