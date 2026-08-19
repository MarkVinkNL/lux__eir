# Shared initial install when app/ is missing

If `app/` does not exist, Eir runs the same Initial install on local, staging, and production: clone Application, compile `.env`, composer install, migrate, write `last_commit`. That lets first server bring-up use `php eir/run.php` with no special bootstrap path, matching local Herd setup.
