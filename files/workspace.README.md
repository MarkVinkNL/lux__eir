# Environment

Created by Eir. Persistent `images/` / `files/`, config, and the Eir deploy tool. The Application (`app/`) is cloned separately by Eir.

## Create another Environment

Eir must be a **public** GitHub repository for this `copy()` URL to work.

```bash
php -r "copy('https://raw.githubusercontent.com/MarkVinkNL/lux__eir/main/install.php', 'install.php');"
php install.php
```

## First run (this machine)

1. Edit `.config` — database, URL, and remaining secrets.
2. `php eir/run.php`

## Clone (existing template)

```bash
git clone --recurse-submodules <this-repo-url> my-env
cd my-env
php eir/run.php
```

If you cloned without submodules:

```bash
git submodule update --init --recursive
```

## Usage

```bash
php eir/run.php          # initial install / local refresh / server Deploy
php eir/run.php -u       # upgrade Environment template + Eir (does not touch app/)
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
  app/          # Application (cloned by Eir, not in this repo)
  images/
  files/
```
