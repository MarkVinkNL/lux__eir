# Eir

Deploy tool for Lux/Ivory Environments. Successor-style port of Lunae.

## Create an Environment

This repository must be **public**. GitHub SSH is not required to fetch Eir; it is required afterwards for private remotes (skills, Application).

```bash
php -r "copy('https://raw.githubusercontent.com/MarkVinkNL/lux__eir/main/install.php', 'install.php');"
php install.php
```

`install.php` runs `git init`, adds this repo as an HTTPS submodule at `eir/`, then `php eir/scripts/setup-workspace.php`:

1. Writes `.config` (local vs server template, then Application git URL) — not committed
2. Ensures GitHub SSH (reuses an existing key, or generates `~/.ssh/id_ed25519` and prints the public key)
3. Adds skill submodules
4. Writes `.gitignore`, `AGENTS.md`, `README.md`, `images/.gitkeep`, `files/.gitkeep`
5. Commits `Setting up workspace`

The copy of `install.php` in the Environment root is not committed. Edit `.config` (database, URL, secrets), then `php eir/run.php`.

Servers always start this way. Do not clone a local workspace onto a server.

## Layout

Eir lives as a git submodule at `eir/` inside an Environment folder:

```
{environment}/
  .config          # secrets + Eir settings (not in git)
  eir/             # this tool
  app/             # Application (own git repo)
  images/
  files/
  last_commit
  deploy.log
  backup/          # previous app/ after Deploy
  new_app/         # ephemeral during Deploy
```

The Environment git repo is a **local workspace**. Locally `app/` is a submodule of that repo so editors show Application git. On servers `app/` is a plain clone so Deploy can swap the directory.

## Usage

```bash
php eir/run.php          # initial install if no app/; else local refresh or server Deploy
php eir/run.php -u       # upgrade Eir (workspace pull only if a remote exists; does not touch app/)
php eir/run.php -f       # force Deploy (ignore last_commit gate)
php eir/run.php -r       # rollback: swap backup/ ↔ app/
php eir/run.php -s       # silent
php eir/run.php -h       # help
```

### First run

If `{environment}/.config` is missing, Eir copies `files/.config.local` or `files/.config.server` and exits. Edit `.config`, then run again.

### Initial install (`app/` missing)

1. Clone `EIR_GIT_REPOSITORY` @ `EIR_GIT_REMOTE_BRANCH` → `app/`
2. Local only: register `app/` as a workspace submodule
3. Compile `.env`
4. `composer install`
5. `artisan migrate --force` + `cache:clear`
6. Write `last_commit`

### Local (`EIR_SYS_ENV=local`, `app/` exists)

Register `app/` as a workspace submodule if it is not one yet. Compile `.env`; `composer install` if `vendor/` is missing.

### Server (`staging` / `production`, `app/` exists)

Commit gate → clone to `new_app/` → env → composer → migrate → swap `app/` ↔ `backup/`.

Migrate failure aborts without swap. Rollback leaves `last_commit` unchanged.

## Upgrade Eir

Resets `eir/` to `origin/main`. Pulls the workspace only when that git repo has a remote. Does **not** touch Application (`app/`).

```bash
php eir/run.php -u
```

Also accepted: `-upgrade`.

A local workspace may have a remote for backup or another machine. Servers typically have none; `-u` still updates Eir.
