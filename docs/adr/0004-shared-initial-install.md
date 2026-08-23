# Shared initial install when app/ is missing

If `app/` does not exist, Eir still runs the same Application bring-up on local, staging, and production: obtain Application → compile `.env` → composer install → migrate → write `last_commit`. First server bring-up is `php eir/run.php`, same command as local.

How Application is obtained differs (see 0005):

- Local: clone, then register `app/` as a workspace submodule
- Server: clone only (plain nested repo), so later Deploy can swap the directory
