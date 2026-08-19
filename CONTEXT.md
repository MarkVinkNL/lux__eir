# Ivory Environment

Deploy and runtime context for Ivory Environments: the durable folder that hosts Eir, media, config, and the Application.

## Language

**Environment**:
A durable folder that hosts one running site (local Herd checkout, `staging/`, or `production/`). It owns `.config`, Eir, media folders, and the live Application directory.
_Avoid_: Home, server root, APP_ENV

**Environment template**:
The git project that defines Environment shape (`eir` submodule, `images/`, `files/`) without Application source code.
_Avoid_: Monorepo, project root (when meaning the Application)

**Application**:
The Laravel site from its own git repository (`ivory.nl`), living in `app/` (and briefly in `new_app/` / `backup/` during Deploy).
_Avoid_: Site, public_html, codebase (ambiguous)

**Eir**:
The PHP CLI deploy tool (`lux__eir`), consumed as the `eir/` submodule inside an Environment.
_Avoid_: Lunae, Solis, deployer

**Initial install**:
When `app/` is missing — the same flow on every Environment: clone Application → env compile → composer → migrate → write `last_commit`.
_Avoid_: Bootstrap (unless meaning only the clone step), setup

**Deploy**:
When `app/` already exists on a non-local Environment — clone into `new_app/`, prepare, then swap into `app/` with the previous tree kept as `backup/`.
_Avoid_: Release, publish, push

**Rollback**:
Swapping `backup/` ↔ `app/` via Eir (`-r`), without reversing migrations or changing `last_commit`.
_Avoid_: Undo, revert (git sense)

**Env compile**:
Building `app/.env` by merging Application env templates, overlaying matching `.config` keys, and preserving `APP_KEY`.
_Avoid_: Envsubst, dotenv build
