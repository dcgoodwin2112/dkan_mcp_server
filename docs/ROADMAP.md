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

## CI drift detection `[blocked]`

Remainder of backlog item #5. Phase 1 (pinned commits, tested-versions matrix,
`UpstreamContractTest`) shipped. Outstanding:

- **Bump-and-test CI job** (Phase 2.3) — periodically update the pins and run the
  suite to catch upstream drift early.
- **Release tracking** (Phase 3) — watch for `mcp_server` / `mcp/sdk` stable
  tags to move off dev branches.

Blocked on standing up CI for the standalone module repo.

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
  call currently rethrows and aborts the server loop; the fix catches the
  denial, emits the JSON-RPC error, and continues — matching HTTP's per-request
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

## Decision D1 — revisit triggers

Adopting Tool API + `mcp_server_tool_bridge` (instead of native `#[Tool]`
plugins) is reconsidered only if: non-developers need to add/configure tools via
UI; OR OAuth scope-per-tool gating is wanted instead of Drupal permissions; OR
`drupal/tool` + the bridge reach a stable release and become the de-facto
Drupal-AI tool standard. Full rationale in [ARCHITECTURE](ARCHITECTURE.md) →
Decision D1.
