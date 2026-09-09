# Lunae-style env overlay

Env compile merges Application env files in this order (later wins): `.env.example` → `.env.default` → `.env.template`. Example owns the key schema; the others overwrite values. Then Environment `.config` overlays only keys already in that map. `EIR_*` keys never enter `.env`.

`APP_KEY` is preserved from a live `.env` when present. An empty key is written as unquoted `APP_KEY=`. Quoted `APP_KEY=""` makes `php artisan key:generate` leave trailing quotes (`APP_KEY=base64:…=""`).
