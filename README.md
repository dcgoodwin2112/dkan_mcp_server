# DKAN MCP Server

> [!IMPORTANT]
> **This project has moved to [drupal.org](https://www.drupal.org/project/dkan_mcp_server).**
> Development, releases, and the issue queue are there now — code at
> <https://git.drupalcode.org/project/dkan_mcp_server>. This GitHub repository is
> an archived snapshot and is no longer maintained.

Exposes DKAN's catalog, datastore, harvest, and write operations as Model
Context Protocol (MCP) tools, built on the contrib
[`mcp_server`](https://www.drupal.org/project/mcp_server) module.

Successor to `dkan_mcp` (which vendors the MCP SDK and hand-rolls both
transports). This module delegates transport, discovery, and session handling to
`mcp_server` and contributes only the DKAN tools plus per-tool access control.

## Architecture

For the design — the thin-adapter pattern, the tool/resource/prompt surfaces,
the access-control and OAuth layers, the two downstream shims, and the update
hooks — see [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). Outstanding and
blocked work is in [`docs/ROADMAP.md`](docs/ROADMAP.md).

## Requirements

- `drupal/mcp_server` (`2.x-dev`, pinned to a tested commit — see [Tested
  versions](#tested-versions)), `mcp/sdk` (`^0.6`), and `drupal/dkan` (`4.x-dev`).
- DKAN `dkan_metastore`, `dkan_datastore`, `dkan_harvest`, and `dkan_query_tools`
  (the last ships bundled — see [Bundled `dkan_query_tools`](#bundled-dkan_query_tools)).
- OAuth HTTP clients also need `drupal/simple_oauth:^6` and
  `e0ipso/simple_oauth_21:^1` plus the optional `mcp_server_oauth` submodule.
  These are in Composer `suggest` (not `require`) — install them only for the
  OAuth path (see [OAuth](#oauth)).
- The HTTP Basic-auth local-dev path (off by default) needs the `basic_auth`
  core module. It is an optional dependency, not in `info.yml` — enable it only
  for that path (see [HTTP auth posture](#http-auth-posture-http_basic_auth)).

### Tested versions

`mcp_server` (`2.x-dev`) is a moving target, so `require` pins it to a specific
commit this module is verified against. `mcp/sdk` now has a stable line and is
required at `^0.6` (the constraint `mcp_server` itself declares):

| Package | Constraint | Pinned commit |
|---|---|---|
| `drupal/mcp_server` | `2.x-dev` | `5d6b54c6f2f29574248c56c768968438eac3be6c` |
| `mcp/sdk` | `^0.6` | stable; resolved by Composer |

Tested matrix: Drupal core `^10.2 || ^11`, PHP 8.3.

`mcp_server` and `drupal/dkan` are still pre-release, so drupal.org releases of
this module are published as **experimental** (alpha) until those cut stable tags.

**To bump:** update the `mcp_server` `#<sha>` pin (and the `mcp/sdk` constraint if
upstream moves) in `composer.json`, run `composer update drupal/mcp_server
mcp/sdk`, then the kernel `ToolDiscoveryTest` — it instantiates all tools via DI
against the real upstream plugins, so most API drift surfaces there. The consumed
upstream surface and drift guards are described in
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) (Dependency stability).

## Installation

A `drupal-module` Composer package on
[drupal.org](https://www.drupal.org/project/dkan_mcp_server). Drupal enforces the
module dependencies above from `dkan_mcp_server.info.yml` at enable time, so they
must be present first.

**Composer:**

```bash
composer require drupal/dkan_mcp_server
drush en dkan_mcp_server
```

`composer/installers` places it under `modules/contrib/`. The package `require`s
`drupal/dkan` and `drupal/mcp_server` (which brings `mcp/sdk` from Packagist); on
a DKAN site these are already present. `dkan_query_tools` ships **inside** this
package as a submodule — no separate install.

`drupal/mcp_server` and `drupal/dkan` ride dev branches (see [Tested
versions](#tested-versions)), so the consuming site needs `minimum-stability: dev`
+ `prefer-stable: true` — the `getdkan/recommended-project` and
`drupal/recommended-project` templates already set this.

**Manual:** clone into `modules/contrib/` and enable:

```bash
git clone https://git.drupalcode.org/project/dkan_mcp_server.git \
  docroot/modules/contrib/dkan_mcp_server
drush en dkan_mcp_server
```

### Bundled `dkan_query_tools`

The shared catalog/datastore/search query library lives at
`modules/dkan_query_tools/` within this package and is its own
independently-enable-able Drupal module (own machine name, `Drupal\dkan_query_tools\`
namespace, and `dkan_query_tools.*` service IDs). It does **not** depend on its
parent, so `drush en dkan_query_tools` enables the library alone without pulling
in the MCP server. `drush en dkan_mcp_server` pulls it in as a declared
dependency. Other consumers (e.g. `dkan_drupal_ai_query`) `composer require
drupal/dkan_mcp_server` to get the library — no VCS `repositories` snippet needed
now that it ships on drupal.org.

## Transports

- **stdio:** `drush mcp:server` — all enabled tools, gated by the running user's permissions.
- **HTTP:** `POST /mcp` — requires the `access mcp server` permission (from `mcp_server`).
  The path defaults to `/mcp` and is configurable via the `mcp_server.base_path` service
  parameter. Authenticate with OAuth2 Bearer tokens (default) or, for local/demo use, HTTP
  Basic auth — see [OAuth](#oauth) below.

## Tools

38 tools (25 read, 13 write). Reads delegate to the shared `dkan_query_tools`
services (metastore/datastore/search); harvest, resource, status, and write
tools delegate to local logic services in `src/Tools/`. One `#[Tool]` plugin per
tool lives in `src/Plugin/Tool/`, each extending a per-service base class
(`MetastoreToolBase`, `WriteToolBase`, …) that supplies DI and native enablement.

## Resources

Catalog data is also exposed as MCP **resources** (for clients that pin context
instead of re-calling tools), under the `dkan://` URI scheme:

**Concrete** resources (fixed URIs, `#[ResourceProvider]` in
`src/Plugin/ResourceProvider/`):

| URI | Backing |
|---|---|
| `dkan://catalog` | DCAT catalog (`dkan_query_tools.metastore`) |
| `dkan://schemas` | metastore schema identifiers |

**Templated** resources (parameterized URIs, `#[ResourceTemplateProvider]` in
`src/Plugin/ResourceTemplateProvider/`):

| URI template | Backing |
|---|---|
| `dkan://dataset/{id}` | dataset metadata by UUID (`MetastoreTools::getDataset`) |
| `dkan://distribution/{id}` | distribution metadata by UUID (`getDistribution`) |
| `dkan://dataset/{id}/dictionary` | linked data dictionaries (`getDataDictionary`) |
| `dkan://datastore/{resourceId}/schema` | column schema by resource_id (`DatastoreTools::getDatastoreSchema`) |

The datastore schema is keyed by `resource_id` (`identifier__version`, as
surfaced by `list_distributions` / `resolve_resource`) — the datastore's own
key — so no distribution-UUID resolution is needed. All delegate to the same
shared services as the read tools. Both registries are opt-in: providers
register only when listed in `mcp_server.resource_providers` (concrete) /
`mcp_server.resource_template_providers` (templated). This module's
`hook_install()` merges its entries into both (and `hook_uninstall()` removes
only those), so no manual config step is needed. Reads are open under `access
mcp server`, matching the read tools; a well-formed URI for an id that does not
resolve returns resource-not-found.

Content caches permanently and is invalidated by DKAN cache tags: the
metastore-backed resources carry the metastore list tag (`node_list:data`, via
`dkan.metastore.metastore_item_factory`, matching DKAN's own
`MetastoreApiResponse`), so any dataset/distribution edit busts the cached
resource. The schema list is untagged (static; it changes only on deploy). All
content varies by the `user.permissions` cache context.

## Prompts

Five curated MCP **prompts** ship as `mcp_prompt_config` entities
(`config/install/mcp_server.mcp_prompt_config.*.yml`); they steer a client
toward the right tools for a task. Admins can add or edit more via
`mcp_server_ui` (`/admin/config/services/mcp-server/prompts`).

| Prompt | Arguments | Steers toward |
|---|---|---|
| `explore_dataset` | `dataset_id` | `get_dataset`, `get_data_dictionary`, `query_datastore` |
| `build_datastore_query` | `dataset_id`, `question` | `get_datastore_schema`, `query_datastore` |
| `find_datasets` | `topic` | `search_datasets`, `list_datasets` |
| `diagnose_harvest` | `harvest_id` | `get_harvest_runs`, `get_harvest_run_result`, `get_site_status` |
| `dataset_health_check` | `dataset_id` | `get_import_status`, `get_datastore_stats` |

**Argument completion.** The `dataset_id` argument (on `explore_dataset`,
`build_datastore_query`, `dataset_health_check`) autocompletes against the live
catalog via the `dkan_dataset_id` completion provider
(`#[PromptArgumentCompletionProvider]`, delegating to `dkan_query_tools`):
`completion/complete` with empty input suggests the first page of dataset
identifiers; partial input matches catalog datasets by title or keyword via
search (whole catalog), and additionally matches identifier substrings within
the first page of results (a cheap supplement kept off the per-keystroke hot
path, since dataset identifiers are not full-text indexed). Clients that support
argument completion get valid IDs without a separate catalog lookup.

**Rendering shim.** At the pinned upstream commits `prompts/get` is unusable for
every config prompt: the SDK formatter json-encodes a message's content *list*
instead of emitting typed content, and `PromptConfigHandler` never substitutes
`{{ arg }}` placeholders. `PromptRenderSubscriber` works around both by
regenerating the `prompts/get` result from the config entity plus the request's
arguments — via `ResponseEvent`, so it covers HTTP and stdio identically (the
same mechanism as `ToolAccessSubscriber`). Remove the subscriber, its service,
and its tests once upstream fixes both defects and the pins are bumped;
`UpstreamContractTest::testPromptRenderShimStillNeeded` fails when that happens,
signalling removal. See [`docs/ROADMAP.md`](docs/ROADMAP.md) for the upstream
defects, the open MRs, and the removal trigger.

## Access control

Reads require only `access mcp server`. Writes are gated by fine-grained
permissions, enforced per-tool by `ToolAccessSubscriber` on **both** `tools/call`
(deny with 403) and `tools/list` (hide), on HTTP and stdio:

| Permission | Tools |
|---|---|
| `edit datasets via mcp` | `update_dataset`, `patch_dataset` |
| `publish datasets via mcp` | `publish_dataset`, `unpublish_dataset` |
| `delete datasets via mcp` | `delete_dataset` |
| `manage metastore items via mcp` | `post_metastore_item`, `patch_metastore_item`, `delete_metastore_item` |
| `import datastore via mcp` | `import_resource` |
| `drop datastore via mcp` | `drop_datastore` |
| `manage harvests via mcp` | `register_harvest`, `run_harvest`, `deregister_harvest` |

`mcp_server` core declares per-tool access (`ToolPluginInterface::checkToolAccess()`)
but never invokes it; `ToolAccessSubscriber` activates that contract via the SDK's
`RequestEvent`/`ResponseEvent`. The upstream contribution to move this into core is
tracked in [`docs/ROADMAP.md`](docs/ROADMAP.md).

## Settings

A settings form at `/admin/config/services/dkan-mcp-server` (permission
`administer dkan mcp server`) toggles **tool groups** — DKAN subsystems — on or off
for every caller. Each tool belongs to exactly one group:

| Group | Tools |
|---|---|
| `metastore` | dataset/distribution/schema/catalog reads |
| `datastore` | datastore queries, schema/stats/import-status reads |
| `search` | `search_datasets` |
| `harvest` | all harvest tools (read **and** run/register/deregister) |
| `resource` | `resolve_resource` |
| `status` | `get_site_status`, `get_queue_status` |
| `write` | dataset/metastore/datastore mutations |

A disabled group is hidden from `tools/list` and rejected on `tools/call` (the same
`ToolAccessSubscriber` gates, HTTP and stdio). This is **operational gating, not
authorization**: groups are subsystems, so disabling `write` does not disable harvest
mutations (those live in `harvest`). To control *who* may mutate data, use the `* via
mcp` permissions above — they are enforced independently. Stored as
`dkan_mcp_server.settings:disabled_groups` (empty = all enabled). HTTP auth and CORS
are configured elsewhere (see below).

## OAuth

OAuth is an opt-in HTTP transport path. The `simple_oauth` / `simple_oauth_21`
packages are in Composer `suggest`, not `require`, and `dkan_mcp_server` does not
hard-depend on the OAuth submodule at Drupal enable time — so stdio and Basic
Auth installs stay lightweight and AI-query-only sites that pull this package
transitively don't drag in the OAuth stack.

Install the OAuth packages, then enable the path:

```bash
composer require drupal/simple_oauth:^6 e0ipso/simple_oauth_21:^1
drush en mcp_server_oauth simple_oauth_server_metadata simple_oauth_client_registration
drush cr
```

The `mcp_server_oauth` route subscriber adds `oauth2` to the inherited
`mcp_server.handle` route. This module contributes `ResourceMetadataSubscriber`,
which advertises the scopes `dkan_mcp:read` and `dkan_mcp:write` in RFC 9728
protected resource metadata, and ships their backing `oauth2_scope` entities as
opt-in `config/optional/` — created automatically once `simple_oauth` is enabled:

| Scope | Granularity | Grants |
|---|---|---|
| `dkan_mcp:read` | permission | `access mcp server` |
| `dkan_mcp:write` | role | `dkan_mcp_write` role (every `* via mcp` write permission + `access mcp server`) |

Assign the matching scope(s) to each consumer, and ensure the token's user holds
the same permissions — authorization still flows through `ToolAccessSubscriber`,
so the effective Drupal permissions of the resolved account gate every tool.

### HTTP auth posture (`http_basic_auth`)

The production default is **OAuth-only** (`cookie` + `oauth2`):
`dkan_mcp_server.settings:http_basic_auth` is `false`, so an unauthenticated
request gets a proper `WWW-Authenticate: Bearer resource_metadata="…"` challenge
(RFC 9728) and clients discover the flow via
`/.well-known/oauth-protected-resource`. Basic auth is off by default because its
`Basic` challenge would otherwise shadow OAuth discovery.

For local/demo use — the bundled `.mcp.json` authenticates with a static
`Authorization: Basic` header — enable Basic auth on the route. The `basic_auth`
core module is an optional dependency (only this path needs it), so enable it
first:

```bash
drush en basic_auth -y
drush cset dkan_mcp_server.settings http_basic_auth true -y && drush cr
```

Toggling the flag re-runs the route subscriber, so it requires a router rebuild
(`drush cr`). If the flag is on without `basic_auth` enabled, the route
alteration is skipped (Basic auth is not served) and the status report flags the
dead configuration. Basic and OAuth can be enabled together; in that mode the
`Basic` challenge takes precedence on credential-less requests.

**Upgrading from a pre-OAuth build:** Basic auth used to be always on; it is now
opt-in (default off). `dkan_mcp_server_update_10001` creates the setting at the
secure default for installs that predate the flag, so run the `drush cset … true`
above (then `drush cr`) to restore Basic auth if your clients rely on it.

## CORS

Browser-based MCP clients (e.g. MCP Inspector) need cross-origin access to
`POST /mcp`. Drupal core CORS is **off by default**, so enable it with an explicit
origin allowlist in `sites/*/services.yml`:

```yaml
parameters:
  cors.config:
    enabled: true
    allowedOrigins: ['https://inspector.example']  # explicit; never '*'
    allowedMethods: ['GET', 'POST', 'DELETE', 'OPTIONS']
    allowedHeaders: ['content-type']
    supportsCredentials: false  # true only with cookies, and never with '*'
```

Two layers fill in the MCP-specific bits automatically once CORS is enabled, so
you only manage the origin allowlist:

- `mcp_server`'s compiler pass adds the MCP methods and the request headers
  `content-type`, `mcp-protocol-version`, `mcp-session-id`.
- This module's `McpCorsAuthHeaderPass` adds `authorization` to `allowedHeaders`
  (required for the OAuth Bearer header cross-origin) and `mcp-session-id` to
  `exposedHeaders` (so browser JS can read the session id off responses). It is a
  no-op when CORS is disabled.

Rebuild the container after editing (`drush cr`). Notes:

- **Never** pair `allowedOrigins: ['*']` with `supportsCredentials: true` —
  browsers reject it and it is insecure. Use an explicit allowlist.
- This is environment-specific and cannot ship as fixed module config.

## Testing

**Unit** (`tests/src/Unit`) — standalone bootstrap, no Drupal kernel:

```bash
ddev exec "cd docroot/modules/custom/dkan_mcp_server && ../../../../vendor/bin/phpunit"
```

- `ToolAccessSubscriberTest` — the access gate: deny/allow/defer on `tools/call`,
  filter/no-mutation/defer on `tools/list`.
- `WriteToolPermissionTest` — each write tool gates on exactly its one declared
  permission; reads stay open; every required permission exists in
  `permissions.yml`.

**Bundled `dkan_query_tools`** — standalone unit suite (stubs, no Drupal kernel):

```bash
ddev exec "cd docroot/modules/custom/dkan_mcp_server/modules/dkan_query_tools && ../../../../../../vendor/bin/phpunit"
```

**Kernel** (`tests/src/Kernel`) — boots a real container with DKAN + `mcp_server`
under core's PHPUnit (needs the DDEV test DB):

```bash
ddev exec bash -c 'cd docroot && SIMPLETEST_DB="mysql://db:db@${DDEV_PROJECT}-db:3306/db" \
  ../vendor/bin/phpunit -c core/phpunit.xml.dist \
  modules/custom/dkan_mcp_server/tests/src/Kernel/ToolDiscoveryTest.php'
```

- `ToolDiscoveryTest` — all 38 tools discover, instantiate via DI, and default to
  enabled; the anonymous access matrix (25 reads open, 13 writes denied) resolves
  through the real plugins. Boot-time DKAN/core deprecation notices are
  pre-existing and do not fail the run.

## Continuous integration

`.gitlab-ci.yml` uses the Drupal Association contrib pipeline templates (the same
`include` as contrib `mcp_server`). Config lives beside it: `phpcs.xml.dist`
(Drupal + DrupalPractice; `php`/`install`/`yml`) and `phpstan.neon.dist`
(level 1, drupal extension). Activation lands when the project moves to
drupal.org GitLab; until then it is authored against that template.

Jobs:

- **phpcs / phpstan** — the two config files above.
- **phpunit** — the module's own unit + kernel tests, selected by
  `@group dkan_mcp_server` (`_PHPUNIT_TESTGROUPS`). Group selection is required:
  pointing phpunit at the whole module folder would also sweep the bundled
  standalone-stub suite, which cannot boot under core's runner. Every test
  carries both a `#[Group]` attribute and a `@group` docblock: the attribute is
  read by PHPUnit 10+ (Drupal 10.3+/11), the docblock by PHPUnit 9 (Drupal 10.2).
  Keep both while `^10.2` is supported, or 10.2 runs silently select zero tests.
- **`phpunit (dkan_query_tools)`** — the bundled library's standalone suite, run
  against its own `phpunit.xml` (stubs, no kernel).
- **`drift (upstream HEAD)`** — scheduled/manual, `allow_failure`. Bumps
  `mcp_server` / `mcp/sdk` from their pinned commits to branch HEAD and runs the
  two contract guards (`ToolDiscoveryTest` + `UpstreamContractTest`).

**Reading a drift failure** (the canary for the pinned dev deps):

- `UpstreamContractTest` red → a consumed upstream class/method/property or
  `#[Tool]` attribute parameter moved. Update the consuming code and the contract
  list, then bump the `#<sha>` pin (README → Tested versions).
- `ToolDiscoveryTest` red → a tool no longer instantiates via DI against the new
  upstream.
- `testPromptRenderShimStillNeeded` red → upstream fixed the prompt-render
  defects; remove `PromptRenderSubscriber` (see [ROADMAP](docs/ROADMAP.md)).

The same gates run locally with the commands under Testing plus `phpcs` /
`phpstan analyse` from the module root (both auto-discover their `*.dist` config).
