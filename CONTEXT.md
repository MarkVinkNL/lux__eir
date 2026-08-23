# Ivory Environment

Deploy and runtime context for Ivory Environments: the durable folder that hosts Eir, media, config, and the Application.

## Language

**Environment**:
A durable folder that hosts one running site (local Herd checkout, `staging/`, or `production/`). It owns `.config`, Eir, media folders, and the live Application directory.
_Avoid_: Home, server root, APP_ENV

**Workspace**:
The Environment git repository on a local machine. Created by `install.php`. Tracks Eir, skills, workspace files, and (locally) the Application submodule. Not cloned onto servers.
_Avoid_: Environment template (when meaning something servers pull), monorepo

**Application**:
The Laravel site from its own git repository (`ivory.nl`), living in `app/` (and briefly in `new_app/` / `backup/` during Deploy). Locally `app/` is a submodule of the workspace; on servers it is a plain clone.
_Avoid_: Site, public_html, codebase (ambiguous)

**Eir**:
The PHP CLI deploy tool (`lux__eir`), consumed as the `eir/` submodule inside an Environment.
_Avoid_: Lunae, Solis, deployer

**Initial install**:
When `app/` is missing — obtain Application, compile env, composer, migrate, write `last_commit`. Locally the clone is then registered as a workspace submodule.
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
