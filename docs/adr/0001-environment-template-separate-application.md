# Environment template with separate Application repo

Ivory Environments are shaped by a template repo (Eir submodule, persistent `images/` / `files/`, `.config`). The Application is a separate git repository cloned into `app/`, so Deploy can atomically swap only the Application without fighting a monorepo worktree at the Environment root.
