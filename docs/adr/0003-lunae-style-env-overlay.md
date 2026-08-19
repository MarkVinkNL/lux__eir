# Lunae-style env overlay

Env compile merges Application env files in this order (later wins): `.env.example` → `.env.default` → `.env.template`. Example owns the key schema; the others overwrite values. Then Environment `.config` overlays only keys already in that map. `EIR_*` keys never enter `.env`.
