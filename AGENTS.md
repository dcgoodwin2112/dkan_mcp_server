# AGENTS.md

Guidance for AI agents (and humans) working on `dkan_mcp_server`. Operational
context only; the design lives in the docs below.

- **What it is / how to use it:** [README.md](README.md)
- **Design + full feature inventory:** [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- **Outstanding / blocked / upstream work:** [docs/ROADMAP.md](docs/ROADMAP.md)

A Drupal 10.2+/11 module exposing DKAN's catalog, datastore, harvest, and write
operations as MCP tools/resources/prompts, built on contrib `mcp_server` +
`mcp/sdk`. Bundles the `dkan_query_tools` shared library as a submodule.

## Repository

- **This module is its own git repo** (`git@github.com:dcgoodwin2112/dkan_mcp_server.git`).
  It is normally developed *inside* a DKAN site at
  `docroot/modules/custom/dkan_mcp_server/`, but ships standalone — keep it
  self-contained (no references to the parent site's files or config).
- The bundled submodule `modules/dkan_query_tools/` is a separate, independently
  enable-able Drupal module shipped in this same package (not a separate repo).
- `composer.json`: `type: drupal-custom-module`, GPL-2.0-or-later, `php >= 8.3`.

## Dev environment

- **No PHP on the host.** Run everything through DDEV (`ddev ...`). The site is
  `drupal11` / PHP 8.3 / MySQL; the site-level Composer vendor is at the DKAN
  site root, not in this module.
- Live MCP endpoints for manual testing:
  - HTTP: `POST https://dkan-site.ddev.site/mcp` (Basic auth; creds in the site's
    `.mcp.json` — `mcp_reader` / `mcp_writer`).
  - stdio: `ddev drush mcp:server`.

## Build / test / lint

Paths assume the module at `docroot/modules/custom/dkan_mcp_server/` in a DKAN
site. A standalone CI clone would `composer install` first, then run the same
phpunit/phpcs binaries.

```bash
# Unit (standalone bootstrap, no Drupal kernel)
ddev exec "cd docroot/modules/custom/dkan_mcp_server && ../../../../vendor/bin/phpunit"

# Bundled dkan_query_tools unit suite (stubs, no kernel)
ddev exec "cd docroot/modules/custom/dkan_mcp_server/modules/dkan_query_tools && ../../../../../../vendor/bin/phpunit"

# Kernel (real container + DKAN + mcp_server), via core's PHPUnit + the DDEV test DB
ddev exec bash -c 'cd docroot && SIMPLETEST_DB="mysql://db:db@${DDEV_PROJECT}-db:3306/db" \
  ../vendor/bin/phpunit -c core/phpunit.xml.dist \
  modules/custom/dkan_mcp_server/tests/src/Kernel/'

# Lint (uses the module's phpcs.xml.dist: standard, extensions, and excludes)
ddev exec "cd docroot/modules/custom/dkan_mcp_server && ../../../../vendor/bin/phpcs"
```

- Unit tests are wired in `phpunit.xml` (suite `unit` → `tests/src/Unit`,
  bootstrap `tests/bootstrap.php`). Kernel tests are **not** in that config; run
  them through core's `phpunit.xml.dist` as above.
- `getdkan/mock-chain` is the unit mocking helper (require-dev).
- Green tests must mean working code — don't assert framework behavior.

## Code style

- `declare(strict_types=1);` everywhere. Prefer `final` classes and constructor
  property promotion with `readonly` for injected deps.
- Guard clauses / early returns; full type hints; comments explain *why*, ≤80
  columns; doc short descriptions start with a capital and end with a period.
- **Em dashes are accepted project style** — do not "fix" them (codex flags them;
  decline those nits).
- No `eval()` / runtime code generation.

## Architecture in one screen

Thin-adapter pattern (Decision D1): tool **logic** lives in framework-neutral
services (`dkan_query_tools.*` for reads/search; `src/Tools/*` for
write/harvest/resource/status); each MCP surface is a thin adapter. Surfaces:

- **Tools:** 38 native `#[Tool]` plugins in `src/Plugin/Tool/`, one per tool,
  each extending a per-subsystem base class (`MetastoreToolBase`, ...) that
  supplies DI + `enabled` default. 25 read / 13 write.
- **Resources:** `#[ResourceProvider]` (catalog, schemas) +
  `#[ResourceTemplateProvider]` (dataset, distribution, dictionary, datastore
  schema) under `dkan://` URIs.
- **Prompts:** 5 `mcp_prompt_config` entities + a `dkan_dataset_id` completion
  provider.

Access is two independent layers: `ToolAccessSubscriber` (authorization — `*
via mcp` permissions, per-tool deny on `tools/call` + hide on `tools/list`, HTTP
and stdio) and tool **groups** (operational gating via the settings form +
`disabled_groups`). Full detail in ARCHITECTURE.md.

**Adding a capability:** if more than one consumer needs it, put the logic in
`dkan_query_tools/src/Tool/`; otherwise a `src/Tools/*` service. Then add the
thin `#[Tool]`/resource/completion adapter. New tools go through the right
base class so they inherit DI, enablement, and a `ToolGroup`.

### Layout

```
src/
  Plugin/Tool/                       38 #[Tool] plugins + per-subsystem base classes + ToolGroup
  Plugin/ResourceProvider/           concrete dkan:// resources (catalog, schemas)
  Plugin/ResourceTemplateProvider/   templated dkan:// resources (dataset, distribution, ...)
  Plugin/PromptArgumentCompletionProvider/   dkan_dataset_id completer
  Tools/                             logic services: Write/Harvest/Resource/Status Tools
  EventSubscriber/                   ToolAccess, PromptRender, ResourceMetadata, UnauthorizedChallenge
  CompilerPass/                      McpCorsAuthHeaderPass
  Form/                              McpSettingsForm (tool-group toggles)
  Routing/RouteSubscriber.php        toggles basic_auth on the /mcp route
  OAuth/DkanMcpScopes.php            scope constants
  Resource/ResourceJsonContentTrait.php   shared JSON shaping
  DkanMcpServerServiceProvider.php   registers the CORS compiler pass
config/install/                      settings + 5 mcp_prompt_config prompts
config/optional/                     OAuth scopes + dkan_mcp_write role
dkan_mcp_server.install              hook_install/uninstall + update_10001..10006
modules/dkan_query_tools/            bundled shared query library (own README)
```

## Gotchas

- **Pinned dev dependencies.** `composer.json` pins `drupal/mcp_server` and
  `mcp/sdk` to exact commits (both ride dev branches). After bumping the
  `#<sha>` pins, run the kernel `ToolDiscoveryTest` (instantiates every tool via
  DI against the real upstream) and `UpstreamContractTest` (asserts the consumed
  upstream symbols still exist). See README → Tested versions.
- **Two temporary downstream shims** wait on upstream fixes (see ROADMAP):
  - `PromptRenderSubscriber` — works around two `prompts/get` render defects.
    Remove it (+ service + tests) when both land and the pins bump;
    `UpstreamContractTest::testPromptRenderShimStillNeeded` goes red as the
    signal.
  - `ToolAccessSubscriber` activates the per-tool access contract `mcp_server`
    declares but never invokes.
- **No vendored SDK, no opis shim here.** Unlike the deprecated `dkan_mcp`, this
  module consumes `mcp_server` + `mcp/sdk` natively. Do not add a module-level
  `vendor/` or opis workaround.
- **Manual MCP testing.** Raw JSON-RPC needs the handshake
  (`initialize` → capture `Mcp-Session-Id` → `notifications/initialized` → call);
  HTTP responses are SSE (`data:` lines). Footguns found the hard way: a
  `clientInfo.version` of `"0"` fails the SDK's `empty()` check (use a real
  version string); stdio input must be paced or the server reports "Fiber
  yielded unexpected payload"; `drush cset ... false` does not reliably set a
  boolean config value (use `php:eval` with `(bool) false`).

## Working norms

- **Commit/push only when asked.** If on the default branch (`main`), branch
  first. Keep PRs focused.
- Run `phpcs` + the relevant test suite before opening a PR; pass non-trivial
  changes through the codex reviewer and integrate validated feedback (decline
  em-dash nits).
- A local **commit-gate hook** (shipped by the `drupal-dkan-ai` plugin) runs
  phpcs + the unit suite via DDEV before each `git commit` and blocks on failure;
  bypass an intentional WIP commit with `CLAUDE_SKIP_COMMIT_GATE=1`. CI is
  authoritative.
- Commit messages: concise, no hype; trailer
  `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`. PR bodies end with
  the Claude Code generated-with line.
- `docs/` is an allowlist (`docs/.gitignore`): only `ARCHITECTURE.md`,
  `ROADMAP.md`, and `.gitignore` are tracked. Planning/scratch docs stay
  untracked local history — do not commit them.
