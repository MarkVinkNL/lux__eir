# Eir

Deploy tool for Lux/Ivory Environments. Successor-style port of Lunae.

## Layout

Eir lives as a git submodule at `eir/` inside an Environment (template) folder:

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

## Usage

```bash
php eir/run.php          # initial install if no app/; else local refresh or server Deploy
php eir/run.php -f       # force Deploy (ignore last_commit gate)
php eir/run.php -r       # rollback: swap backup/ ↔ app/
php eir/run.php -s       # silent
php eir/run.php -h       # help
```

### First run

If `{environment}/.config` is missing, Eir copies `files/.config.local` or `files/.config.server` and exits. Edit `.config`, then run again.

### Initial install (`app/` missing)

Same on local, staging, and production:

1. Clone `EIR_GIT_REPOSITORY` @ `EIR_GIT_REMOTE_BRANCH` → `app/`
2. Compile `.env`
3. `composer install`
4. `artisan migrate --force` + `cache:clear`
5. Write `last_commit`

### Local (`EIR_SYS_ENV=local`, `app/` exists)

Compile `.env`; `composer install` if `vendor/` is missing.

### Server (`staging` / `production`, `app/` exists)

Commit gate → clone to `new_app/` → env → composer → migrate → swap `app/` ↔ `backup/`.

Migrate failure aborts without swap. Rollback leaves `last_commit` unchanged.

## Update Eir

From the Environment folder:

```bash
cd eir
git fetch --all
git reset --hard origin/main
```

Or bump the submodule pointer in the Environment template repo.
