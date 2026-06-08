# Architecture

How `dkan_mcp_server` is built and what every feature does. The
[README](../README.md) is the operator/user guide (install, transports, auth,
settings); this is the design reference. Where the README already documents a
surface in detail, this doc links rather than repeats.

## Foundation: built on `mcp_server`

The module is a downstream consumer of contrib
[`mcp_server`](https://www.drupal.org/project/mcp_server) (`2.x-dev`) +
[`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk) (`dev-main`), both
pinned to tested commits (see README → Tested versions). It is the successor to
the deprecated `dkan_mcp`, which vendored the SDK and hand-rolled both
transports.

**Inherited from `mcp_server` (not reimplemented):**

- Both transports — stdio (`drush mcp:server`) and HTTP (`POST /mcp`, route
  `mcp_server.handle`, permission `access mcp server`).
- Plugin discovery for tools, resources, resource templates, and prompt
  argument completion providers.
- MCP session handling and the JSON-RPC protocol layer (via the SDK).
- Prompt storage (`mcp_prompt_config` config entities) and the optional
  `mcp_server_oauth` submodule for the OAuth path.

**Added here:** the DKAN tools/resources/prompts, per-tool access control, tool
groups, the OAuth scope mapping, the CORS header fill-in, and the two downstream
shims documented below.

## Decision D1 — native `#[Tool]` plugins, thin-adapter pattern

Tools are authored as `mcp_server` **native `#[Tool]` plugins**
(`src/Plugin/Tool/`), not as `drupal/tool` (Tool API) plugins behind
`mcp_server_tool_bridge`. Native `#[Tool]` is the module's first-class, stable
contract; the bridge is unreleased/incubating and its headline wins (an admin
CRUD UI, config-entity per-tool settings for OAuth scope gating) are not needed
here — authorization is already handled by Drupal permissions.

The design keeps this cheap to revisit: tool **logic** lives in
framework-neutral services and each MCP surface is a **thin adapter** over them.

```
dkan_query_tools.{metastore,datastore,search}   ← shared query library (submodule)
src/Tools/{Write,Harvest,Resource,Status}Tools  ← module-local logic services
        ▲                ▲                ▲
        │ delegate       │ delegate       │ delegate
  #[Tool] plugins   #[ResourceProvider]  DkanDatasetIdCompletionProvider
  (src/Plugin/Tool) (src/Plugin/Resource*)  (src/Plugin/PromptArgument…)
```

Adopting Tool API later means adding an adapter, not rewriting logic. **Revisit
if:** non-developers need to add/configure tools via UI; OR OAuth scope-per-tool
gating is wanted instead of permissions; OR `drupal/tool` + the bridge reach a
stable release and become the de-facto Drupal-AI tool standard.

## Tools

38 native `#[Tool]` plugins (25 read, 13 write) in `src/Plugin/Tool/`. Each
extends one **per-subsystem base class** that supplies DI and native enablement
(`defaultConfiguration()['enabled'] => TRUE`):

| Base class | Subsystem | Delegates to |
|---|---|---|
| `MetastoreToolBase` | metastore reads | `dkan_query_tools.metastore` |
| `DatastoreToolBase` | datastore reads | `dkan_query_tools.datastore` |
| `SearchToolBase` | search | `dkan_query_tools.search` |
| `HarvestToolBase` | harvest | `src/Tools/HarvestTools` |
| `ResourceToolBase` | resource resolution | `src/Tools/ResourceTools` |
| `StatusToolBase` | site/queue status | `src/Tools/StatusTools` |
| `WriteToolBase` | mutations | `src/Tools/WriteTools` |

Reads delegate to the shared `dkan_query_tools` services; write/harvest/
resource/status delegate to module-local logic services in `src/Tools/`.

**Behavior-hint matrix.** Every `#[Tool]` declares all four MCP hints
(`readOnly`, `destructive`, `idempotent`, `openWorld`) explicitly. Reads are
`readOnly + non-destructive + idempotent + local`. Only `import_resource` and
`run_harvest` set `openWorld: TRUE` (they fetch a remote source URL). The four
removal tools (`delete_dataset`, `delete_metastore_item`, `deregister_harvest`,
`drop_datastore`) are `destructive + idempotent` (deleting an absent item has no
further effect). The non-idempotent set is exactly the create/run/import writes
(`import_resource`, `post_metastore_item`, `register_harvest`, `run_harvest`).
`ToolAnnotationAuditTest` reflects each plugin's attribute and asserts this
decided matrix, so a wrong-hint edit fails the suite.

## Resources

Catalog data is also exposed as MCP **resources** under the `dkan://` URI
scheme, for clients that pin context instead of re-calling tools. See README →
Resources for the URI tables and caching contract. Design notes:

- **Concrete** (`#[ResourceProvider]`, `src/Plugin/ResourceProvider/`):
  `CatalogResource`, `SchemaListResource`, sharing `DkanResourceProviderBase`.
- **Templated** (`#[ResourceTemplateProvider]`,
  `src/Plugin/ResourceTemplateProvider/`): dataset, distribution,
  dataset-dictionary, datastore-schema, sharing `DkanResourceTemplateProviderBase`
  with JSON shaping in `ResourceJsonContentTrait`.
- All delegate to the same `dkan_query_tools` services as the read tools.
- **Opt-in registration:** providers register only when listed in
  `mcp_server.resource_providers` / `mcp_server.resource_template_providers`.
  `hook_install()` merges this module's entries into both; `hook_uninstall()`
  removes only those (no manual config step).
- **Caching:** permanent, invalidated by DKAN cache tags. Metastore-backed
  resources carry `node_list:data` (matching DKAN's own `MetastoreApiResponse`),
  so any dataset/distribution edit busts the cache. The schema list is untagged
  (static). All vary by `user.permissions`.

## Prompts

Five curated `mcp_prompt_config` entities (`config/install/`) steer clients
toward the right tools. See README → Prompts for the prompt/argument table.

- **Argument completion.** `DkanDatasetIdCompletionProvider`
  (`#[PromptArgumentCompletionProvider]`, id `dkan_dataset_id`) autocompletes the
  `dataset_id` argument against the live catalog, delegating to
  `dkan_query_tools`. Empty input → first page of identifiers; partial input →
  fulltext/title/keyword search (whole catalog) plus identifier-substring matches
  within the first page. The identifier-substring step is first-page-only on
  purpose: DKAN search does **not** full-text-index dataset identifiers, so a
  whole-catalog id-substring match isn't available without scanning — the cheap
  supplement is kept off the per-keystroke hot path.
- **Rendering shim (`PromptRenderSubscriber`).** At the pinned upstream commits
  `prompts/get` is unusable for every config prompt: the SDK formatter
  json-encodes a message's content *list* instead of emitting typed content, and
  `PromptConfigHandler` never substitutes `{{ arg }}` placeholders. The
  subscriber regenerates the `prompts/get` result from the config entity plus the
  request arguments, via `ResponseEvent` (so it covers HTTP and stdio
  identically). This is a temporary downstream shim for two upstream defects —
  see [ROADMAP](ROADMAP.md) for the defects, the open MRs, and the removal
  trigger.

## Access control and settings

Two independent layers gate tools. They compose: a call must pass both.

### Authorization — `ToolAccessSubscriber` (who may call)

`mcp_server` declares a per-tool access contract
(`ToolPluginInterface::checkToolAccess()`) but never invokes it.
`ToolAccessSubscriber` activates it via the SDK's `RequestEvent` (deny
`tools/call` with 403) and `ResponseEvent` (hide from `tools/list`), on **both**
HTTP and stdio. Reads require only `access mcp server`; writes gate on
fine-grained `* via mcp` permissions (`permissions.yml`). See README → Access
control for the permission→tool table.

### Operational gating — tool groups (which subsystems are on)

The settings form at `/admin/config/services/dkan-mcp-server` (`McpSettingsForm`,
permission `administer dkan mcp server`) toggles **tool groups** — DKAN
subsystems — for every caller. Each tool maps to exactly one group via
`GroupedToolInterface::toolGroup()`, declared on its base class; the taxonomy
(metastore, datastore, search, harvest, resource, status, write) lives in
`ToolGroup`. A disabled group is hidden from `tools/list` and rejected on
`tools/call` by the **same** `ToolAccessSubscriber` (injected with
`config.factory`). Stored as `dkan_mcp_server.settings:disabled_groups` (empty =
all enabled).

This is **operational gating, not authorization**: groups are subsystems, so
disabling `write` does not disable harvest mutations (those live in `harvest`).
To control *who* may mutate, use the `* via mcp` permissions — enforced
independently. See README → Settings.

## OAuth and HTTP auth posture

OAuth is an opt-in HTTP path; the `simple_oauth` packages are in Composer
`suggest`, not `require`, so stdio/Basic-auth installs stay lightweight. See
README → OAuth for setup and the scope table. Components added here:

- `ResourceMetadataSubscriber` — advertises scopes `dkan_mcp:read` /
  `dkan_mcp:write` in RFC 9728 protected-resource metadata.
- `UnauthorizedChallengeSubscriber` — emits the
  `WWW-Authenticate: Bearer resource_metadata="…"` challenge so credential-less
  requests discover the flow via `/.well-known/oauth-protected-resource`.
- `RouteSubscriber` — appends `basic_auth` to the route's `_auth` when
  `dkan_mcp_server.settings:http_basic_auth` is TRUE *and* the optional
  `basic_auth` module is enabled (default flag FALSE = OAuth-only posture;
  toggling requires `drush cr`). `basic_auth` is not a hard `info.yml`
  dependency — only this opt-in path needs it — so the alteration is skipped
  when it is absent, and `hook_requirements` flags a flag-on-without-module
  misconfiguration as a dead (`inert`) posture.
- Scope-backing `oauth2_scope` entities + the `dkan_mcp_write` role ship as
  opt-in `config/optional/` (created once `simple_oauth` is enabled).

Authorization always flows through `ToolAccessSubscriber`: the effective Drupal
permissions of the resolved account gate every tool, whatever the auth scheme.

## CORS — `McpCorsAuthHeaderPass`

A compiler pass (registered by `DkanMcpServerServiceProvider`, priority -10, runs
after `mcp_server`'s own CORS pass) that, **only when Drupal core CORS is
enabled**, adds `authorization` to `allowedHeaders` (for the cross-origin OAuth
Bearer header) and `mcp-session-id` to `exposedHeaders` (so browser JS can read
the session id). No-op when CORS is disabled. The origin allowlist is
operator-owned in `sites/*/services.yml` and cannot ship as module config — see
README → CORS.

## Dependency stability

The stack rides moving dev branches, so `composer.json` pins both to tested
commits. `UpstreamContractTest` asserts the consumed upstream symbols exist
(clear failure on drift); the kernel `ToolDiscoveryTest` instantiates every tool
via DI against the real upstream plugins, so most API drift surfaces there.
`UpstreamContractTest::testPromptRenderShimStillNeeded` asserts the two
prompt-render defects still exist — it goes **red** when upstream fixes land,
which is the signal to drop `PromptRenderSubscriber` (see ROADMAP). Bump
procedure is in README → Tested versions. CI runs these guards two ways: on every
pipeline at the pinned commits, and in a scheduled/manual `drift (upstream HEAD)`
job that re-points both deps to branch HEAD to catch drift before a bump (see
README → Continuous integration).

## Submodule split — `dkan_query_tools`

The shared catalog/datastore/search query library ships as an independently
enable-able submodule at `modules/dkan_query_tools/` (own machine name,
namespace, and `dkan_query_tools.*` service IDs). It does not depend on its
parent, has a standalone unit suite (stubs, no Drupal kernel), and is consumed by
both this module and `dkan_drupal_ai_query`. Query methods return structured
`error`/`sanity_flags` payloads instead of throwing. See the
[submodule README](../modules/dkan_query_tools/README.md).

It has no own `composer.json`, so the whole tree packages as a single project —
one drupal.org release / Composer package (`drupal/dkan_mcp_server`, a
`drupal-module` installed to `modules/contrib/`). (Drupal's packaging adds
`version`/`LICENSE.txt`/datestamp at release time; they are not committed here.)

## Update hooks

Each backfills a feature added after initial release onto existing installs
(fresh installs get everything from `config/install` + `hook_install`); all are
idempotent and non-clobbering:

| Hook | Backfills |
|---|---|
| `update_10001` | `http_basic_auth` setting at the secure default (FALSE) |
| `update_10002` | concrete resource providers → `mcp_server.resource_providers` |
| `update_10003` | resource template providers → `mcp_server.resource_template_providers` |
| `update_10004` | the 5 prompt config entities |
| `update_10005` | the `dataset_id` completion provider on existing prompts |
| `update_10006` | `disabled_groups` setting at its default (empty) |

## Performance

Reads are dominated by DKAN node loading: a dataset summary loads its `data`
node, and `MetastoreService::getAll()` additionally decodes the body and swaps
references (tens of queries per dataset, measured). Costs are bounded as follows.

- **Row/page caps everywhere.** Datastore reads cap at 500 rows; metastore
  listings clamp to ≤100/page; search to ≤50. No tool runs an unbounded row scan.
- **`get_catalog` — permanent cache.** The whole-catalog read is O(datasets)
  (hundreds of queries cold). The tool caches its shaped payload permanently in
  `cache.default`, busted by `node_list:data` — the same metastore list tag the
  `dkan://catalog` resource uses, invalidated by any dataset
  create/update/delete/publish/unpublish (kernel-tested). Warm calls are one cache
  read (~hundreds of queries → 1). The payload is access-independent (access is
  gated before `execute()`), so one shared key needs no per-user variation. A cold
  rebuild happens only right after a dataset write, so concurrent-miss stampede is
  bounded — a single-site server needs no lock.
- **Completion — identifier-only.** `dataset_id` completion fires per keystroke
  but needs only identifiers, so it uses `MetastoreTools::listDatasetIdentifiers()`
  (`getIdentifiers()`: no body decode, no reference swap) rather than full
  summaries — ~20% fewer queries and ~2× faster per keystroke at measurement, and
  no per-keystroke decode of full dataset bodies at scale. Both result sets are
  page-capped.
- **`get_site_status` — live, sampled, uncached.** Gathers per-dataset import
  status via `DatasetInfo::gather()` (~80 queries/dataset), bounded by sampling the
  first `MAX_DATASETS = 100` datasets. Intentionally **not** cached: import/queue
  progress is the live signal this tool reports, and a TTL cache would show stale
  counts during imports while `node_list:data` cannot invalidate importer-state
  changes. A bulk import-status query to cut the cold cost is a ROADMAP follow-up.

## Testing

Three suites — unit (standalone bootstrap), the bundled `dkan_query_tools` unit
suite (stubs), and kernel (real container with DKAN + `mcp_server`). Commands and
the per-suite coverage notes are in README → Testing.
