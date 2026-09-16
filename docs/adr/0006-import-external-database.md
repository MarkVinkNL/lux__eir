# Import dumps into the local Application database

Import is local-only (`php eir/run.php -i` or an initial-install prompt). Source credentials live as `EIR_IMPORT_DB_*` so Env compile never overlays them into `.env`. The flow dumps the remote database first, loads it into this Environment’s `DB_*` database, then runs `migrate --force` for catch-up — never empty-migrate then overwrite.
