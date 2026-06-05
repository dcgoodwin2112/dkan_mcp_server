# Roadmap — outstanding and blocked work

Everything not yet shipped in `dkan_mcp_server`, consolidated. The feature set is
otherwise complete (tools, resources, prompts, OAuth, CORS, admin settings — see
[ARCHITECTURE](ARCHITECTURE.md)). Items are tagged `[blocked]`, `[optional]`,
`[external]`, or `[deferred]`.

## Upstream prompt-render fixes `[external]`

`PromptRenderSubscriber` is a downstream shim for two `mcp_server` `prompts/get`
defects (see [ARCHITECTURE](ARCHITECTURE.md) → Prompts). Both are filed upstream
as merge requests.

**Verified state as of 2026-06-04** (re-verify before the next pins bump — MR
numbers and status can move):

- **Defect A — content double-encoding.** Issue
  [#3585891](https://git.drupalcode.org/project/mcp_server/-/issues/3585891) →
  [MR !40](https://git.drupalcode.org/project/mcp_server/-/merge_requests/40):
  **open** (not draft), targets `2.x`. Fans each message's content sequence out
  to single-block messages so the SDK formatter receives typed content. Enforced
  CI (phpunit) green; the pre-existing red phpstan job is a stale `excludePaths`
  entry unrelated to this change.
- **Defect B — argument substitution.** Issue
  [#3572404](https://git.drupalcode.org/project/mcp_server/-/issues/3572404) →
  [MR !41](https://git.drupalcode.org/project/mcp_server/-/merge_requests/41):
  **draft**, stacked on !40, targets `2.x`. Fills `{{ name }}` placeholders from
  the client args; an unsupplied token stays literal. Open question on the MR:
  `{{ }}` vs `{$ }` syntax and blank-vs-literal for a missing token.

**When !40 merges:** rebase the Defect B branch onto `2.x` (drops A's commit),
flip !41 Draft → Ready.

**Removal trigger.** When both fixes land upstream and the pins are bumped,
delete `PromptRenderSubscriber`, its service registration, and its tests.
`UpstreamContractTest::testPromptRenderShimStillNeeded` goes red at that point —
that red is the signal. Realign any token syntax to whatever upstream accepts so
removing the shim is a no-op.

Draft MR text, bug reports, repro evidence, and the patch are kept as local
upstream working files outside the tracked doc set.

**Related gap (not shimmed) — `prompts/get` does not validate required
arguments.** Neither the SDK `GetPromptHandler` nor `mcp_server`
`PromptConfigHandler` rejects a `prompts/get` that omits a required argument; the
call returns 200 and the unsupplied `{{ token }}` is left literal in the rendered
text. Our `PromptRenderSubscriber` cannot fix this cleanly: it runs on
`ResponseEvent`, whose `setResponse()` only accepts a success `Response`, not an
error, so it has no way to turn the call into a JSON-RPC error. Per the existing
design decision, required-argument validation is the SDK's job; this is an
upstream gap to raise alongside the prompt-render fixes, not a downstream fix.

## CI drift detection `[external]`

Remainder of backlog item #5. Phase 1 (pinned commits, tested-versions matrix,
`UpstreamContractTest`) and the **bump-and-test CI job** (Phase 2.3) are shipped:
`.gitlab-ci.yml` defines a scheduled/manual `drift (upstream HEAD)` job that
re-points `mcp_server` / `mcp/sdk` to branch HEAD and runs `ToolDiscoveryTest` +
`UpstreamContractTest` (see README → Continuous integration). Outstanding:

- **Release tracking** (Phase 3) — watch for `mcp_server` / `mcp/sdk` stable
  tags to move off dev branches.

### drupal.org CI cutover checklist

The pipeline is authored against the drupal.org contrib templates but only runs
once the project lives on drupal.org GitLab. The items below could not be
validated off drupal.org (no GitLab runner, no `glab` CI lint here); verify each
on the first pipeline run and adjust `.gitlab-ci.yml`:

1. **Template job paths.** The `phpunit (dkan_query_tools)` and
   `drift (upstream HEAD)` jobs reference template internals that only resolve on
   a drupal.org runner: `$DRUPAL_PROJECT_FOLDER` and `$COMPOSER_BIN_DIR` (the
   submodule `-c` path + the phpunit binary) and `!reference [phpunit, script]`
   (drift reuses the template's phpunit command). Confirm each resolves against
   the pinned `$_GITLAB_TEMPLATES_REF` and that both jobs run green; the variable
   names can change between template versions.
2. **Drift schedule.** The drift job's `schedule` rule needs an actual GitLab CI
   **pipeline schedule** (project Settings → CI/CD → Schedules), e.g. weekly.
   Until one exists it only fires on a manual web trigger.
3. **cspell dictionary.** `_CSPELL_WORDS` is a starter list; top it up from the
   first cspell job output (or set `SKIP_CSPELL: '1'` if not wanted).
4. **Core matrix.** `OPT_IN_TEST_NEXT_MINOR` / `_NEXT_MAJOR` are off (DKAN 4.x is
   `^10.2 || ^11`); enable next-minor once the baseline is green.
5. **PHPUnit 9 / Drupal 10.2.** If `CORE_PREVIOUS` testing is ever enabled, group
   selection on PHPUnit 9 relies on the `@group` docblocks (PHPUnit 9 ignores the
   `#[Group]` attribute). Keep both the docblock and the attribute on every test
   while `^10.2` is supported.

## Structured output schemas (#7b) `[optional]`

`outputSchema` is already declared on the four datastore-query tools. Optional
extension: audit those four, optionally add schemas to `get_dataset` /
`get_datastore_schema` / `get_datastore_stats`, and add per-tool error-variant
drift tests. Not started; no launch dependency.

## Upstream contributions `[external]`

Community-good upstreaming of infrastructure already shipped downstream. None
block this module.

- **`checkToolAccess` enforcement (#7c / Contribution 1).** Move the per-tool
  access contract into `mcp_server` core. `tools/call` enforcement first;
  `tools/list` hiding as a separate follow-up (maintainers favor small MRs). The
  identical subscriber already ships here, so the outcome doesn't affect this
  module either way. File the issue first for a maintainer signal on approach
  (core-wire vs. write-your-own).
- **Clean stdio per-call auth denial (Contribution 2).** Over stdio a denied tool
  call crashes `drush mcp:server` outright: `McpServerCommands::server()` catches
  `McpAuthorizationDeniedException`, then `fwrite(STDOUT, …)` throws
  `TypeError: supplied resource is not a valid stream resource` (STDOUT is not a
  valid resource in the Drush context), and even absent that it rethrows and
  aborts the run loop. Net effect: the client receives no JSON-RPC error and the
  session dies. This affects every downstream per-tool denial over stdio —
  including this module's write-permission gating and tool-group gating (HTTP
  handles the identical denial cleanly). Confirmed locally 2026-06-04. The fix:
  in the stdio command, write the JSON-RPC error to the SDK's output stream (not
  the `STDOUT` constant) and continue the loop, matching HTTP's per-request
  semantics. Small, self-contained; reproduce on a clean install first.
- **Config-driven native-tool enablement.** Upstream "TODO (later)": a
  `mcp_server.tools` config object so native `#[Tool]` plugins are enabled via
  config rather than code. An upstream tool-discovery/enablement MR was in
  progress as of 2026-06-04 — check the `mcp_server` queue before doing anything
  (numbers move). If it lands, DKAN tools can be config-enabled for free.

## Prompts Phase 2 completers `[deferred]`

`harvest_id` and `topic` argument completers. Deferred: there is no enumerable
source for the free-text `topic` argument, and `harvest_id` completion is low
value until requested. The `dataset_id` completer shipped.

## Security review — triaged findings (Phase 1, 2026-06)

A four-dimension adversarial review (authZ, info disclosure, DoS bounds, input
handling) drove the Phase 1 hardening. **Fixed:** harvest SSRF / local-file-read
+ ETL-class allowlist in `HarvestTools::registerHarvest`; datastore error
scrubbing (no raw SQL / table names to clients); DoS bounds (offset clamp, stats
column cap, condition/column count guards); read-output redaction (internal node
/revision IDs and absolute file paths in `get_dataset_info` / `resolve_resource`);
and a `hook_requirements` warning for the Basic-auth posture. The items below were
validated and intentionally **not** fixed in Phase 1, with rationale.

- **No rate limiting `[deferred]`.** Expensive reads (datastore queries/stats,
  `get_catalog`) are repeatable as fast as an authenticated client can send. Rate
  limiting belongs at the deployment edge (reverse proxy) or via Drupal `flood`;
  not imposed in-module so operators keep the policy choice. Recommend documenting
  reverse-proxy throttling for `/mcp`. Revisit if an in-module limiter is wanted.
- **Single read permission tier `[deferred]`.** `access mcp server` grants every
  read (catalog, arbitrary datastore queries, harvest/queue/site status).
  Deliberate, matching DKAN's public-data posture. A finer permission +
  `checkAccess` override on diagnostic/harvest reads can be added if an operator
  needs to restrict them.
- **Harvest plan source URLs readable without a harvest permission `[deferred]`.**
  `get_harvest_plan` / `_runs` / `_run_result` are read-gated only, so a reader can
  enumerate source URLs and any secret an operator embedded in a plan. Mitigation:
  operators must not embed secrets in plan URIs/headers; gating harvest reads
  behind a permission is a future option.
- **`get_site_status` version fingerprinting `[deferred]`.** Exposes DKAN/Drupal
  versions and enabled DKAN modules to readers — standard for an operator status
  tool. Coarsen or gate if the reader audience widens.
- **`get_catalog` unpaginated full load `[addressed, Phase 3]`.** Loads the whole
  catalog (descriptions already truncated). Phase 3 added a permanent
  `node_list:data`-tagged cache, so repeated calls are a single cache read (see
  ARCHITECTURE → Performance); the cold rebuild is still O(datasets). Add pagination
  or a size guard only if the cold-load memory cost becomes an issue on a very large
  catalog.
- **Join group/sort/property canonicalization `[deferred]`.** `queryDatastoreJoin`
  relies on DKAN's `query.json` schema plus core's compile-time `escapeField`
  rather than the single-resource path's explicit canonicalization. Verified safe
  on the installed core; add canonicalization for defense-in-depth if ever run on
  an older core that did not escape group/order fields.
- **`ToolAccessSubscriber` fail-open for unresolved tool names `[note]`.** A wire
  name that does not resolve to a `ToolPluginInterface` is deferred (allowed), by
  design. Latent only: every shipped tool resolves (enforced by
  `ToolDiscoveryTest`, which instantiates all 38 via DI), so there is no live hole.
- **Raw error messages in write/harvest/search services `[accepted]`.** Only the
  datastore paths (which carry SQL / table identifiers) were scrubbed; write,
  harvest, and search service errors are still returned to the authorized caller
  to aid debugging — lower risk since they require write/permissioned access.
- **Fetch-time harvest SSRF residual `[deferred]`.** The URI allowlist (scheme +
  resolved-address checks) runs at both registration and run time, blocking the
  direct vectors: file://, literal internal IPs (v4/v6, incl. integer/zone
  forms), internal hostnames, and unresolved hosts (fail-closed). Two vectors
  remain in DKAN's fetch itself, where the stock Guzzle client follows redirects
  and re-resolves DNS:
  1. **DNS rebinding** — DKAN re-resolves the host during the fetch, after the
     run-time check, so a host can flip to an internal address in that window.
  2. **HTTP redirects** — Guzzle follows redirects by default, so a public URL
     can 302 to an internal target after the check passes.
  Both require a harvest-write permission to reach. A complete fix needs a
  hardened harvest HTTP client (redirects disabled or each Location re-validated,
  plus resolved-IP pinning). DKAN does expose a downstream seam — `DataJson` and
  the ETL `Factory` accept an injected `ClientInterface`, and
  `HarvestService::getDkanHarvesterInstance()` is `protected` — so this *can* be
  done in a `HarvestService` subclass injected only into `HarvestTools` (scoping
  it to MCP runs without a site-wide override). Deferred because it duplicates
  DKAN's harvester-construction internals (upstream-coupling risk) and is better
  served by an upstream redirect/IP-pinning fix in DKAN's extractor; tracked as a
  follow-up decision, not a blocker for the preflight hardening shipped here.

## Phase 3 follow-ups — performance & integrity (2026-06)

Surfaced by the Phase 3 perf review and its codex plan review; none block release.

- **`get_site_status` bulk import-status query `[perf]`.** The status overview
  gathers import status per dataset via `DatasetInfo::gather()` (~80 queries/dataset,
  measured), bounded only by `MAX_DATASETS = 100` sampling, and is deliberately
  uncached (the counts are its live signal — see ARCHITECTURE → Performance). The
  real fix is a single bulk query over datastore import state to aggregate
  done/pending/error without the per-dataset gather — cuts the cold cost without
  trading away freshness. Deferred: couples to datastore-internal storage, so scope
  it carefully or push upstream.
- **`patch` identifier immutability `[integrity, deferred]`.** `MetastoreService::patch()`
  — reached via `patch_dataset` / `patch_metastore_item` — does not reject a patch
  that rewrites `$.identifier`, unlike `put()` (which throws
  `CannotChangeUuidException`). A divergent patch desyncs the node uuid (the key
  `get()` resolves by) from the metadata identifier. Low impact (the record is just
  unfetchable by its new id) and requires write permission, but a guard mirroring
  `put()` would close it. Surfaced by codex during the Phase 3 plan review.

## Decision D1 — revisit triggers

Adopting Tool API + `mcp_server_tool_bridge` (instead of native `#[Tool]`
plugins) is reconsidered only if: non-developers need to add/configure tools via
UI; OR OAuth scope-per-tool gating is wanted instead of Drupal permissions; OR
`drupal/tool` + the bridge reach a stable release and become the de-facto
Drupal-AI tool standard. Full rationale in [ARCHITECTURE](ARCHITECTURE.md) →
Decision D1.
