# DKAN MCP Server

Exposes DKAN's catalog, datastore, harvest, and write operations as Model
Context Protocol (MCP) tools, built on the contrib
[`mcp_server`](https://www.drupal.org/project/mcp_server) module.

Successor to `dkan_mcp` (which vendors the MCP SDK and hand-rolls both
transports). This module delegates transport, discovery, and session handling to
`mcp_server` and contributes only the DKAN tools plus per-tool access control.

## Requirements

- `drupal/mcp_server` and `mcp/sdk` (both ride dev branches; pinned to tested
  commits in `require` — see [Tested versions](#tested-versions)) and
  `drupal/dkan` (`4.x-dev`).
- DKAN `dkan_metastore`, `dkan_datastore`, `dkan_harvest`, and `dkan_query_tools`
  (the last ships bundled — see [Bundled `dkan_query_tools`](#bundled-dkan_query_tools)).
- OAuth HTTP clients also need `drupal/simple_oauth:^6` and
  `e0ipso/simple_oauth_21:^1` plus the optional `mcp_server_oauth` submodule.
  These are in Composer `suggest` (not `require`) — install them only for the
  OAuth path (see [OAuth](#oauth)).

### Tested versions

`mcp_server` (`2.x-dev`) and `mcp/sdk` (`dev-main`) are moving targets, so
`require` pins them to specific commits this module is verified against:

| Package | Branch | Pinned commit |
|---|---|---|
| `drupal/mcp_server` | `2.x-dev` | `5d6b54c6f2f29574248c56c768968438eac3be6c` |
| `mcp/sdk` | `dev-main` | `0347dc85b4e577037f2fa177ac3fccd6aca7d8d7` |

Tested matrix: Drupal core `^10.2 || ^11`, PHP 8.3.

**To bump:** update the `#<sha>` pins in `composer.json`, run `composer update
drupal/mcp_server mcp/sdk`, then the kernel `ToolDiscoveryTest` — it instantiates
all tools via DI against the real upstream plugins, so most API drift surfaces
there. The consumed upstream surface is enumerated in
[`docs/DEPENDENCY_STABILITY_PLAN.md`](docs/DEPENDENCY_STABILITY_PLAN.md).

## Installation

A `drupal-custom-module` Composer package. Drupal enforces the module
dependencies above from `dkan_mcp_server.info.yml` at enable time, so they must
be present first.

**Composer** (not on Packagist — add this repo as a VCS source):

```bash
composer config repositories.dkan_mcp_server vcs https://github.com/dcgoodwin2112/dkan_mcp_server
composer require dcgoodwin2112/dkan_mcp_server
drush en dkan_mcp_server
```

`composer/installers` places it under `modules/custom/`. The package `require`s
`drupal/dkan` and `drupal/mcp_server` (which brings `mcp/sdk`); on a DKAN site
these are already present. `dkan_query_tools` ships **inside** this package as a
submodule — no separate install.

**Manual:** clone into `modules/custom/` and enable:

```bash
git clone https://github.com/dcgoodwin2112/dkan_mcp_server \
  docroot/modules/custom/dkan_mcp_server
drush en dkan_mcp_server
```

### Bundled `dkan_query_tools`

The shared catalog/datastore/search query library lives at
`modules/dkan_query_tools/` within this package and is its own
independently-enable-able Drupal module (own machine name, `Drupal\dkan_query_tools\`
namespace, and `dkan_query_tools.*` service IDs). It does **not** depend on its
parent, so `drush en dkan_query_tools` enables the library alone without pulling
in the MCP server. `drush en dkan_mcp_server` pulls it in as a declared
dependency. Other consumers (e.g. `dkan_drupal_ai_query`) require this package to
get the library; see their READMEs for the downstream `repositories` snippet.

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
tracked in [`docs/contrib-mcp-server-contributions.md`](docs/contrib-mcp-server-contributions.md).

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
`Authorization: Basic` header — enable Basic auth on the route:

```bash
drush cset dkan_mcp_server.settings http_basic_auth true -y && drush cr
```

Toggling the flag re-runs the route subscriber, so it requires a router rebuild
(`drush cr`). Basic and OAuth can be enabled together; in that mode the `Basic`
challenge takes precedence on credential-less requests.

**Upgrading from a pre-OAuth build:** Basic auth used to be always on; it is now
opt-in (default off). `dkan_mcp_server_update_10001` creates the setting at the
secure default for installs that predate the flag, so run the `drush cset … true`
above (then `drush cr`) to restore Basic auth if your clients rely on it.

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
