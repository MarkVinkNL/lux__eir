# Local Application submodule, server clone

The Environment git repository is a local workspace. Servers never clone it; they run `install.php` and get their own Environment git (Eir + skills only).

Locally, after Application is cloned into `app/`, Eir registers that clone as a submodule of the workspace so editors show Application git. Servers keep a plain clone so Deploy can replace `app/` with `new_app/` without a gitlink in the way.

`php eir/run.php -u` updates Eir (`origin/main`). It pulls the workspace only when a remote exists, and never runs `git submodule update` on `app/`.
