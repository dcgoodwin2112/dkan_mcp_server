<?php

namespace Drupal\dkan_mcp_server\Tools;

use Drupal\dkan_harvest\HarvestService;
use Psr\Log\LoggerInterface;

/**
 * MCP tools for DKAN harvest operations.
 */
class HarvestTools {

  /**
   * ETL extractor classes a plan registered over MCP may use.
   *
   * DKAN's harvest Factory instantiates the plan's extract/load/transform
   * `type` strings with `new $class()` after only a class_exists() check, so an
   * unconstrained plan is an arbitrary-object-instantiation primitive. MCP only
   * permits the stock DKAN ETL classes.
   */
  private const ALLOWED_EXTRACT_CLASSES = [
    'Drupal\\dkan_harvest\\ETL\\Extract\\DataJson',
  ];

  /**
   * ETL loader classes a plan registered over MCP may use.
   */
  private const ALLOWED_LOAD_CLASSES = [
    'Drupal\\dkan_harvest\\Load\\Dataset',
    'Drupal\\dkan_harvest\\ETL\\Load\\Simple',
  ];

  /**
   * ETL transform classes a plan registered over MCP may use.
   */
  private const ALLOWED_TRANSFORM_CLASSES = [
    'Drupal\\dkan_harvest\\Transform\\ResourceImporter',
    'Drupal\\dkan_harvest\\ETL\\Transform\\AddId',
    'Drupal\\dkan_harvest\\ETL\\Transform\\AddRandomNumber',
  ];

  public function __construct(
    protected HarvestService $harvest,
    protected LoggerInterface $logger,
  ) {}

  /**
   * List all registered harvest plan IDs.
   */
  public function listHarvestPlans(): array {
    $ids = $this->harvest->getAllHarvestIds();
    return ['plans' => $ids, 'total' => count($ids)];
  }

  /**
   * Get harvest plan configuration.
   *
   * @param string $planId
   *   Harvest plan ID.
   */
  public function getHarvestPlan(string $planId): array {
    $plan = $this->harvest->getHarvestPlanObject($planId);
    if ($plan === NULL) {
      return ['error' => 'Harvest plan not found: ' . $planId];
    }
    return ['plan' => json_decode(json_encode($plan), TRUE)];
  }

  /**
   * List all runs for a harvest plan.
   *
   * @param string $planId
   *   Harvest plan ID.
   */
  public function getHarvestRuns(string $planId): array {
    $plan = $this->harvest->getHarvestPlanObject($planId);
    if ($plan === NULL) {
      return ['error' => 'Harvest plan not found: ' . $planId];
    }
    // HarvestService has no method for listing all run results, so read them
    // from its public run repository. Each value is a JSON-encoded run result
    // keyed by run ID (newest first).
    $runs = $this->harvest->runRepository->retrieveAllRunsJson($planId);
    $decoded = [];
    foreach ($runs as $runId => $run) {
      $item = is_string($run) ? json_decode($run, TRUE) : $run;
      if (!is_array($item)) {
        continue;
      }
      // Strip the embedded plan config to reduce token waste.
      unset($item['plan']);
      $item['run_id'] = (string) $runId;
      $decoded[] = $item;
    }
    return ['runs' => $decoded, 'total' => count($decoded)];
  }

  /**
   * Get detailed result for a harvest run.
   *
   * @param string $planId
   *   Harvest plan ID.
   * @param string|null $runId
   *   Run ID/timestamp. Omit for the latest run.
   */
  public function getHarvestRunResult(string $planId, ?string $runId = NULL): array {
    $result = $this->harvest->getHarvestRunResult($planId, $runId);
    if (empty($result)) {
      $msg = 'No run result found for plan: ' . $planId;
      if ($runId !== NULL) {
        $msg .= ', run: ' . $runId;
      }
      return ['error' => $msg];
    }
    unset($result['plan']);
    return ['result' => $result];
  }

  /**
   * Register a new harvest plan.
   *
   * @param string $plan
   *   Harvest plan as a JSON string with identifier, extract, and load
   *   properties.
   */
  public function registerHarvest(string $plan): array {
    $decoded = json_decode($plan);
    if (!is_object($decoded)) {
      $message = json_last_error() !== JSON_ERROR_NONE
        ? 'Invalid JSON: ' . json_last_error_msg()
        : 'Plan must be a JSON object.';
      return ['error' => $message];
    }

    // Security gate: DKAN does not constrain the plan's source URI or its ETL
    // `type` class names, so an unvalidated plan is an SSRF / local-file-read /
    // arbitrary-class-instantiation primitive once run_harvest fires. Reject
    // anything outside the stock DKAN ETL classes and non-public source URIs
    // before the plan reaches storage.
    if (($planError = $this->validatePlanSecurity($decoded)) !== NULL) {
      $this->logger->warning('MCP: Rejected unsafe harvest plan: @error', ['@error' => $planError]);
      return ['error' => $planError];
    }

    try {
      $this->harvest->registerHarvest($decoded);
      $planId = $decoded->identifier ?? 'unknown';
      $this->logger->notice('MCP: Harvest plan @id registered.', ['@id' => $planId]);
      return [
        'status' => 'success',
        'plan_id' => $planId,
        'message' => 'Harvest plan registered.',
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('MCP: Failed to register harvest: @error', ['@error' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Execute a harvest run.
   *
   * @param string $planId
   *   Harvest plan ID.
   */
  public function runHarvest(string $planId): array {
    $plan = $this->harvest->getHarvestPlanObject($planId);
    if ($plan === NULL) {
      return [
        'status' => 'not_found',
        'plan_id' => $planId,
        'message' => 'Harvest plan not found: ' . $planId,
      ];
    }

    // Re-validate at run time, not just at registration. This narrows the
    // DNS-rebinding window (a host that resolved to a public address at
    // registration may resolve to an internal one now) and also catches a plan
    // that reached storage through a non-MCP path. DKAN still re-resolves the
    // host during the fetch itself, so the residual rebinding gap is upstream.
    if (($planError = $this->validatePlanSecurity($plan)) !== NULL) {
      $this->logger->warning('MCP: Refused to run unsafe harvest plan @id: @error', [
        '@id' => $planId,
        '@error' => $planError,
      ]);
      return ['error' => $planError];
    }

    try {
      $result = $this->harvest->runHarvest($planId);
      $this->logger->notice('MCP: Harvest plan @id executed.', ['@id' => $planId]);
      return [
        'status' => 'success',
        'plan_id' => $planId,
        'result' => $result,
        'message' => 'Harvest run completed.',
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('MCP: Failed to run harvest @id: @error', ['@id' => $planId, '@error' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Remove a harvest plan.
   *
   * @param string $planId
   *   Harvest plan ID.
   */
  public function deregisterHarvest(string $planId): array {
    if ($this->harvest->getHarvestPlanObject($planId) === NULL) {
      return [
        'status' => 'not_found',
        'plan_id' => $planId,
        'message' => 'Harvest plan not found: ' . $planId,
      ];
    }

    try {
      $this->harvest->deregisterHarvest($planId);
      $this->logger->notice('MCP: Harvest plan @id deregistered.', ['@id' => $planId]);
      return [
        'status' => 'success',
        'plan_id' => $planId,
        'message' => 'Harvest plan deregistered.',
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('MCP: Failed to deregister harvest @id: @error', [
        '@id' => $planId,
        '@error' => $e->getMessage(),
      ]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Validates a decoded harvest plan against MCP's security policy.
   *
   * @param object $plan
   *   The decoded harvest plan object.
   *
   * @return string|null
   *   An error message if the plan is rejected, or NULL if it is acceptable.
   *   Structural/required-field validation is left to DKAN; this only enforces
   *   the ETL class allowlist and source-URI policy.
   */
  private function validatePlanSecurity(object $plan): ?string {
    $extractType = $plan->extract->type ?? NULL;
    if ($extractType !== NULL && !$this->classAllowed($extractType, self::ALLOWED_EXTRACT_CLASSES)) {
      return 'Harvest extract.type is not an allowed extractor class.';
    }

    $loadType = $plan->load->type ?? NULL;
    if ($loadType !== NULL && !$this->classAllowed($loadType, self::ALLOWED_LOAD_CLASSES)) {
      return 'Harvest load.type is not an allowed loader class.';
    }

    $transforms = $plan->transforms ?? [];
    // Reject a non-array transforms (e.g. a JSON object): DKAN's Factory
    // iterates it with foreach regardless of shape, so an object would skip a
    // shape-gated allowlist and still reach `new $class`.
    if (!is_array($transforms)) {
      return 'Harvest transforms must be an array.';
    }
    foreach ($transforms as $transform) {
      if (!$this->classAllowed($transform, self::ALLOWED_TRANSFORM_CLASSES)) {
        return 'Harvest transforms contains a class that is not allowed.';
      }
    }

    $uri = $plan->extract->uri ?? NULL;
    if (is_string($uri) && ($uriError = $this->sourceUriError($uri)) !== NULL) {
      return $uriError;
    }

    return NULL;
  }

  /**
   * Checks a plan class string against an allowlist (case-insensitive).
   *
   * @param mixed $value
   *   The candidate class string from the plan.
   * @param string[] $allowed
   *   The permitted fully-qualified class names (no leading backslash).
   */
  private function classAllowed(mixed $value, array $allowed): bool {
    if (!is_string($value)) {
      return FALSE;
    }
    // DKAN's Factory accepts a leading backslash and matches case-insensitively
    // (PHP class names are case-insensitive), so normalize before comparing.
    $normalized = ltrim($value, '\\');
    foreach ($allowed as $candidate) {
      if (strcasecmp($normalized, $candidate) === 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Validates a harvest source URI, rejecting local-file and SSRF targets.
   *
   * Requires an absolute http(s) URL and blocks hosts that resolve to loopback,
   * link-local (cloud-metadata), or private ranges. This closes the obvious
   * SSRF / local-file-read vectors; it cannot fully defeat DNS rebinding, since
   * DKAN re-resolves the host when the harvest actually runs.
   *
   * @param string $uri
   *   The plan's extract.uri value.
   *
   * @return string|null
   *   An error message if the URI is disallowed, or NULL if acceptable.
   */
  private function sourceUriError(string $uri): ?string {
    $parts = parse_url($uri);
    if ($parts === FALSE || empty($parts['scheme'])) {
      return 'Harvest source URI must be an absolute http(s) URL.';
    }
    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], TRUE)) {
      // Catches file://, php://, data://, ftp://, etc. before the host check so
      // a scheme-only abuse (e.g. file:///etc/passwd) gets a clear message.
      return "Harvest source URI scheme '{$scheme}' is not allowed; use http or https.";
    }
    if (empty($parts['host'])) {
      return 'Harvest source URI must be an absolute http(s) URL.';
    }
    if ($this->hostIsInternal($parts['host'])) {
      return 'Harvest source URI host resolves to a non-public (loopback, link-local, or private) address.';
    }
    return NULL;
  }

  /**
   * Determines whether a host is (or resolves to) a non-public address.
   *
   * @param string $host
   *   The host component of the URI (may be an IP literal or a hostname).
   */
  private function hostIsInternal(string $host): bool {
    // Strip IPv6 brackets, e.g. "[::1]".
    $host = trim($host, '[]');
    // Drop an IPv6 zone identifier (e.g. "fe80::1%eth0", possibly percent-
    // encoded as "%25eth0") so the address itself is range-checked rather than
    // skipped as unparseable.
    $decoded = rawurldecode($host);
    if (str_contains($decoded, '%')) {
      $host = substr($decoded, 0, strpos($decoded, '%'));
    }
    if (strcasecmp($host, 'localhost') === 0) {
      return TRUE;
    }

    // An IP literal is its own only candidate; otherwise resolve the hostname.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
      $ips = [$host];
    }
    else {
      $ips = $this->resolveHost($host);
      // Fail closed when a non-IP host does not resolve here. The HTTP client
      // can still coerce non-DNS host forms into an internal address (e.g.
      // integer/octal/hex IPv4 such as http://2130706433/ = 127.0.0.1), and a
      // genuinely unresolvable host cannot be harvested anyway.
      if ($ips === []) {
        return TRUE;
      }
    }

    foreach ($ips as $ip) {
      // FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE makes the filter
      // reject (return FALSE for) private and reserved addresses, which covers
      // loopback (127.0.0.0/8, ::1), link-local (169.254.0.0/16, fe80::/10),
      // and RFC 1918 / unique-local ranges.
      if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return TRUE;
      }
      if ($this->inExtraBlockedRange($ip)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Checks ranges PHP's private/reserved filter flags do not cover.
   *
   * Currently CGNAT shared address space (100.64.0.0/10), which is non-global
   * but not flagged as private or reserved by FILTER_VALIDATE_IP.
   *
   * @param string $ip
   *   A validated IP address.
   */
  private function inExtraBlockedRange(string $ip): bool {
    $long = ip2long($ip);
    if ($long === FALSE) {
      // IPv6 or non-IPv4: the filter flags above already cover it.
      return FALSE;
    }
    // 100.64.0.0/10 (mask 0xFFC00000).
    return ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000);
  }

  /**
   * Resolves a hostname to its IPv4 (A) and IPv6 (AAAA) addresses.
   *
   * Uses gethostbynamel() for IPv4 (it follows CNAME chains and returns the
   * terminal A records) and dns_get_record() for AAAA, so both a CNAME to an
   * internal IPv4 address and an AAAA-only host pointing at an internal IPv6
   * address are surfaced for range-checking rather than slipping past.
   *
   * @param string $host
   *   The hostname to resolve.
   *
   * @return string[]
   *   The resolved IP addresses (may be empty if the host does not resolve).
   */
  private function resolveHost(string $host): array {
    $ips = @gethostbynamel($host) ?: [];
    $records = @dns_get_record($host, DNS_AAAA) ?: [];
    foreach ($records as $record) {
      if (isset($record['ipv6'])) {
        $ips[] = $record['ipv6'];
      }
    }
    return $ips;
  }

}
