# DKAN MCP Server

Exposes DKAN's catalog, datastore, harvest, and write operations as Model
Context Protocol (MCP) tools, built on the contrib
[`mcp_server`](https://www.drupal.org/project/mcp_server) module.

Successor to `dkan_mcp` (which vendors the MCP SDK and hand-rolls both
transports). This module delegates transport, discovery, and session handling to
`mcp_server` and contributes only the DKAN tools plus per-tool access control.

## Requirements

- `drupal/mcp_server` (`2.x-dev`) + `mcp/sdk` (`dev-main`)
- DKAN `dkan_metastore`, `dkan_datastore`, `dkan_harvest`, and `dkan_query_tools`
- OAuth HTTP clients also require `drupal/simple_oauth:^6`,
  `e0ipso/simple_oauth_21:^1`, and the optional `mcp_server_oauth` submodule.

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

`composer/installers` places it under `modules/custom/`. The package declares no
contrib requirements: on a DKAN site `drupal/dkan` and `drupal/mcp_server` (which
brings `mcp/sdk`) are already installed, and `dkan_query_tools` is a sibling
custom module added the same way.

**Manual:** clone into `modules/custom/` and enable:

```bash
git clone https://github.com/dcgoodwin2112/dkan_mcp_server \
  docroot/modules/custom/dkan_mcp_server
drush en dkan_mcp_server
```

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

OAuth is an opt-in HTTP transport path. The module package declares the
`simple_oauth` and `simple_oauth_21` Composer dependencies, but
`dkan_mcp_server` does not hard-depend on the OAuth submodule at Drupal enable
time so stdio and Basic Auth installs remain lightweight.

Enable the OAuth path after the dependencies are installed:

```bash
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
