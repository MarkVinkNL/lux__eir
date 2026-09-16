# Import copies remote media folders over SSH

Media Import is local-only (`php eir/run.php -m` or an initial-install prompt). SSH settings live as `EIR_IMPORT_SSH_*` so Env compile never overlays them into `.env`. The path is what you `cd` to after SSH login, e.g. `production` from home; relative paths resolve against `$HOME`. Remote `public_images` / `public_files` map onto local `images` / `files` when the Eir names are missing. Transfer is tar-over-SSH into a staging directory, then swap. Key authentication only (BatchMode); password SSH is not supported.
