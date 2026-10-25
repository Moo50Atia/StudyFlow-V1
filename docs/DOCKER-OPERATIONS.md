# Running EduPlatform with Docker

From this repository, run `./bin/product up`. It builds the frontend and all runtimes, runs pending migrations, and waits for infrastructure health. The app opens at http://127.0.0.1:8766, Temporal UI at http://127.0.0.1:8233, and bridge diagnostics at http://127.0.0.1:8765/health. Product mode needs no Vite process.

Requirements: Linux, Python 3 on the host, Docker Engine with Compose v2 or newer, access to `/var/run/docker.sock`, an existing `.env` with `APP_KEY`, `V3_BRIDGE_HMAC_KEY`, and `V3_CALLBACK_HMAC_KEY`, and the existing `database/database.sqlite`. Run the wrapper as the data owner, without sudo. Images use PHP 8.4, Python 3.12, Node 24, and immutable manifests in `docker/images.env`. The bundled checker/browser currently targets x86-64 Linux.

## Commands

```sh
./bin/product up           # build/recreate as needed and wait for health
./bin/product restart      # restart existing persistent services and wait
./bin/product stop         # remove containers/network; retain all data and volumes
./bin/product logs         # combined logs, Ctrl-C to exit
./bin/product dev          # source mounts and Vite HMR at 127.0.0.1:5173
./bin/product refresh-auth # hidden, interactive manual NotebookLM cookie import
./bin/product check-auth   # read-only ownership check through the shared NotebookLM service
```

Use `up` after changing code, environment, or images. `restart` preserves existing container configuration. `dev` retains the application URL, enables local debugging, mounts source, reuses image-installed dependencies when lockfiles match and installs changed locked dependencies, and shares the host `public/hot` file with Laravel. Return to `up` for built assets; its image-local public directory excludes that hot file. Container dependency volumes remain across stop/start.

## First migration and preservation

New PDF submissions retain the original and prepare ordered sources before processing. PDFs over 50 pages are halved recursively until every part is at most 50 pages. A 114-page PDF produces four sources: pages 1–28, 29–57, 58–85, and 86–114. Source and page ordinals are padded in filenames; uploads follow the submitted reading order and each part completes before the next. Existing captured runs keep their original policy and artifacts. Splitting preserves pages; generated lesson coverage still requires review.

On the first successful migration, the wrapper stops only recognized project servers/workers and their children, waits for graceful shutdown, and backs up `.env`, the full database directory, application storage (including Temporal SQLite and terminal index), sessions, and logs under `storage/backups/pre-docker-*`. Backup directories are mode 0700 and archives 0600. No keys, accounts, or seed data are regenerated. A failed startup retains the snapshot and does not mark migration complete.

`database/` and `storage/` are host bind mounts, allowing SQLite journal/WAL files and uploads to persist. ClamAV definitions and development dependencies use named volumes. Stop never passes `--volumes`. Temporal CLI 1.9.1 embeds server 1.32.0/UI 2.54.1, matching the original host runtime, and uses the existing `storage/app/private/v3/temporal/server.sqlite` with namespace `v3-terminal`.

Code lives at `/app`. The image also creates an alias at the original absolute checkout path so historical run references and manifests still resolve. The current deployment's original path is `/home/moahmmed/college_project`; keep this checkout path when moving the installation, or add that historical alias deliberately before rebuilding. Signed and hashed artifacts are never rewritten during migration.

Credential caches are mounted as directories at their existing absolute paths, using the invoking user's UID/GID. The wrapper obtains the Docker socket's group ID and adds it only to bridge and worker. It never changes socket permissions. Checker/preview children retain their own nonroot, no-network, read-only, capability/resource-restricted runtime and receive candidate data over stdin, without application mounts or secrets.

Private PDFs use `/app/storage/app/v3`; terminal artifacts use `/app/storage/app/private/v3/terminal`. Nginx receives up to 1100 MiB per request, PHP accepts up to 20 files at 100 MiB each with 512 MiB memory, and existing application limits remain authoritative. QPDF and the ClamAV client run in PHP containers. Remote ClamAV receives streamed bytes over the private Compose network. Queue timeout is 300 seconds; database retry-after is 360 seconds.

## Readiness and authentication

Initialization and checker verification must finish successfully. PHP-FPM, Laravel HTTP, Temporal, bridge, ClamAV, worker polling, queue process/database access, and scheduled heartbeat each have health probes. Queue and scheduler start after the application, bridge, worker, and scanner are ready. Logs rotate at 10 MiB with three files per persistent service. Shutdown allows up to 320 seconds for in-flight work.

Infrastructure readiness does not contact NotebookLM or generate paid scenes. Missing or expired Google sessions retain the existing auth-blocked workflow behavior and role-filtered error visibility. Docker restarts do not renew authentication or explicitly retry blocked runs.

`refresh-auth` imports the full Cookie header at a hidden prompt, updates the mounted MCP/SDK caches, and restarts bridge/worker. Run `check-auth` afterward to verify that the session owns the configured proof notebook. Existing run sessions adopt the refreshed profile at a provider boundary after that ownership check succeeds. Resuming a blocked run remains an explicit recovery action; a successful auth check verifies access, while the full workflow needs its own test.

The check preserves the reason for failure:

- `authentication_required`: NotebookLM rejected the session or could not establish an authenticated session; refresh the cookie.
- `account_mismatch`: the account has access to the proof notebook but is not its owner; check the account and notebook access.
- `notebook_verification_failed`: the proof notebook could not be read; check its link and access.
- `ownership_unverified` / `ownership_check_failed`: ownership could not be confirmed; retry the read-only check.
- `provider_unavailable` / `provider_rate_limit`: connectivity or provider availability prevented the check.
- `profile_unverified`: configure an owned proof notebook with `scripts/v3/check_notebooklm.py --notebook-id <id> --adapter notebooklm_py` in the same runtime.

## Recovery

New web runs send the exact mapped lesson number and topic with each request and restore the retained conversation context. Existing runs keep their original protocol and request hashes. A wrong-topic answer remains blocked; do not clear send fences or edit captured answers to force a retry. Manual export mode finishes with saved lesson sections and downloadable scene prompts; it does not generate paid scenes.

Inspect `./bin/product logs` for unhealthy services. Initial ClamAV definition loading/downloading can take several minutes; the wrapper waits up to 15 minutes after builds. Builds require access to image/package registries. An initialization failure leaves the existing database intact; correct the reported missing setting or permissions, then use `up`.

Before restoring a snapshot, stop the stack. Extract its database/storage/.env to the same checkout as the data owner, then run `up`; do not combine a live database with an old WAL file. Keep protected snapshots outside version control. Never use `docker compose down -v` for routine operations. Direct Compose calls need the UID/GID/path variables normally supplied by the wrapper; use the wrapper for daily operations.

Actual validation results and remaining live gates are recorded in `docs/DOCKER-VERIFICATION.md`.
