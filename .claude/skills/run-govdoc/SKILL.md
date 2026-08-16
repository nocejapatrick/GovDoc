---
name: run-govdoc
description: Build, run, and drive GovDoc (Laravel + Inertia + Vue document management app). Use when asked to start GovDoc, log in and check a page, take a screenshot of its UI, click through a flow, or verify a frontend change actually works in the browser.
---

GovDoc is a Laravel 13 + Inertia + Vue 3 web app, served by Herd (not
`php artisan serve` — see Gotchas) at `http://govdoc.test`. Drive it via
the Playwright REPL at `.claude/skills/run-govdoc/driver.mjs`. All paths
below are relative to the repo root.

## Prerequisites

macOS via Herd, not a container — no `apt-get` step here. What actually
matters:

```bash
# System default `node` is v14.21.3 on this machine — too old to even
# parse modern deps (fails with "Unexpected token '??='"). Use a newer
# one from nvm for every command below:
export PATH="$HOME/.nvm/versions/node/v20.19.5/bin:$PATH"
node --version   # must be 18+; v20.19.5 and v24.x are both installed via nvm

# For the tmux-wrapped interactive path only: macOS ships neither tmux
# nor GNU `timeout` (that's `gtimeout` here, via coreutils).
brew install tmux coreutils
```

## Setup

Backend services (Postgres, Floci = local SQS/S3, OpenSearch, the OCR
worker) run in Docker:

```bash
cd dockers && docker compose up -d
```

Ollama (LLM inference) runs **natively on the host**, not in Docker —
Docker Desktop's VM has no Metal/GPU passthrough on macOS, so a
containerized Ollama is ~2.3x slower (measured: 8.7 vs 20.1 tok/s).
Laravel's `.env` (`OLLAMA_HOST`) already assumes native:

```bash
brew services start ollama
```

Herd auto-serves the PHP app once the directory is "parked" — this repo
already is (confirm with `herd parked`, look for `GovDoc` →
`http://GovDoc.test`). There's no explicit "start the server" step.

The frontend needs its dev server running for assets/HMR:

```bash
npm run dev &
# Laravel's Vite plugin reads public/hot for the actual port — confirm
# it matches a process that's actually listening:
cat public/hot
```

The driver itself needs Playwright, installed scoped to the skill
directory (not the app's own `package.json` — this is agent tooling,
not a project dependency):

```bash
cd .claude/skills/run-govdoc && npm install
```

## Run (agent path)

```bash
export PATH="$HOME/.nvm/versions/node/v20.19.5/bin:$PATH"
node .claude/skills/run-govdoc/driver.mjs
```

It's a REPL — pipe commands via stdin for a scripted sequence, or run
interactively:

```bash
node .claude/skills/run-govdoc/driver.mjs <<'EOF'
launch
login
nav /admin/settings
ss admin-settings
text h1
console
quit
EOF
```

Screenshots land in `/tmp/govdoc-shots/` (override: `SCREENSHOT_DIR`).
Base URL defaults to `http://govdoc.test` (override: `GOVDOC_BASE_URL`).

For interactive/iterative use, wrap in tmux instead of a heredoc:

```bash
tmux new-session -d -s govdoc -x 200 -y 50
tmux send-keys -t govdoc 'export PATH="$HOME/.nvm/versions/node/v20.19.5/bin:$PATH" && node .claude/skills/run-govdoc/driver.mjs' Enter
gtimeout 15 bash -c 'until tmux capture-pane -t govdoc -p | grep -q "driver>"; do sleep 0.2; done'
tmux send-keys -t govdoc 'launch' Enter
gtimeout 15 bash -c 'until tmux capture-pane -t govdoc -p | grep -q "launched"; do sleep 0.2; done'
tmux send-keys -t govdoc 'login' Enter
tmux capture-pane -t govdoc -p
```

### Commands

| command | what it does |
|---|---|
| `launch` | start headless Chromium |
| `login [email] [password]` | log in as the seeded admin (default `admin@gmail.com admin`), waits for `/dashboard` |
| `nav <path>` | go to `<base>/<path>` |
| `ss [name]` | screenshot (full page) → `/tmp/govdoc-shots/<name>.png` |
| `click <css-selector>` | click via Playwright's normal click |
| `click-text <text>` | click the first `a`/`button`/`[role=button]` whose text matches |
| `fill <css-selector> <text>` | fill an input |
| `wait <css-selector>` | wait up to 10s for an element |
| `eval <js>` | `page.evaluate(...)`, prints JSON |
| `text [css-selector]` | print `innerText` (body if no selector) |
| `console` | dump any console/page errors seen so far |
| `quit` | close the browser, exit |

## Run (human path)

Just open `http://govdoc.test` in a real browser once `docker compose
up -d`, `brew services start ollama`, and `npm run dev` are running —
no build step, Herd handles PHP directly.

## Test

```bash
php artisan test
```

**Currently broken, pre-existing, unrelated to this skill**: the test
suite uses SQLite (`:memory:`) by default, but
`database/migrations/2022_08_03_000000_create_vector_extension.php`
runs `CREATE EXTENSION IF NOT EXISTS vector` — valid Postgres, not
valid SQLite. Every test fails immediately with `SQLSTATE[HY000]:
General error: 1 near "EXTENSION"` before it ever reaches app code (38
of 39 failing this way as of this writing). Not something this skill
fixes — flagging it so you don't mistake it for something you broke.

## Gotchas

- **This is not `php artisan serve`.** Herd is the actual web server —
  it serves the parked directory directly. Running `artisan serve` too
  would just bind a second, unused port; don't bother.
- **Login form: race between click and redirect.** `page.click('button[type="submit"]')`
  followed by `waitForLoadState('networkidle')` is flaky — Inertia's
  redirect to `/dashboard` can resolve before `networkidle` ever fires,
  leaving Playwright waiting on the wrong signal. Race the click against
  an explicit `page.waitForURL('.../dashboard')` instead (see `login` in
  the driver).
- **Piped/heredoc stdin does NOT serialize async command handlers.**
  `rl.on('line', async (line) => {...})` starts a new handler for every
  line as it arrives — with heredoc input all lines arrive almost
  instantly, so `login` would fire while `launch`'s `chromium.launch()`
  was still resolving, and every command failed with "launch first".
  Fixed by chaining each command onto a `Promise` queue instead of
  firing them independently.
- **`readline`'s `close` event fires as soon as heredoc input hits EOF**
  — which can be *before* the queued commands above have actually run.
  The original `rl.on('close', () => process.exit(0))` was killing the
  browser mid-launch on every piped run. Fixed by `await`ing the command
  queue before quitting in the `close` handler.
- **macOS has neither `tmux` nor GNU `timeout` by default.** The
  tmux-wrapped path needs `brew install tmux coreutils`, and `timeout`
  becomes `gtimeout` — plain `timeout` doesn't exist here.
- **Multiple stale `vite` processes can accumulate** (e.g. from prior
  sessions not cleanly killed) — `public/hot` tells you which port is
  actually the live one; don't assume the lowest port number.
- **Node version matters for more than the driver.** `vue-tsc`,
  `npm install` for anything modern, and this driver all silently break
  under the system default Node v14. Always export the nvm path first.

## Troubleshooting

- **`Unexpected token '??='` from any `npx`/`node` command**: you're on
  the system default Node v14. `export PATH="$HOME/.nvm/versions/node/v20.19.5/bin:$PATH"` first.
- **Driver commands all print "ERROR: launch first" even after `launch`**:
  you're on an older version of this driver without the queue fix above
  — check `driver.mjs` has the `Promise` queue in the `rl.on('line', ...)`
  handler, not a bare `async` callback.
- **`net::ERR_NAME_NOT_RESOLVED` navigating to `govdoc.test`**: the site
  isn't parked. Run `herd parked` to check; `herd link` from the repo
  root if it's missing.
- **Login redirects back to `/login`**: check the admin user actually
  exists — `database/seeders/AdminSeeder.php` creates `admin@gmail.com`
  / `admin`, but only if seeders have been run.
