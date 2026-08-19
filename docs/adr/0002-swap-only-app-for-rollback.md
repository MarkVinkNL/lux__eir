# Swap only app/ for Deploy rollback

Server Redeploys prepare a fresh Application in `new_app/`, then rename live `app/` to `backup/` and `new_app/` to `app/`. That keeps media, Eir, and `.config` durable and makes Rollback a directory swap (`php eir/run.php -r`) without reverse-migrating the database.
