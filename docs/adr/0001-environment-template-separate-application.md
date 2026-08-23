# Environment git with a separate Application repo

An Environment is a durable folder (local workspace, `staging/`, or `production/`). Its git repo tracks Eir, skills, and workspace files (`images/`, `files/`), not Application source as regular files.

The Application is its own git repository, checked out at `app/`. That lets server Deploy swap only `app/` (see 0002) without fighting a monorepo at the Environment root.

The workspace git repo is local. Servers are not installed by cloning a workspace; they run `install.php`. Locally, `app/` is a submodule of that workspace (see 0005).
