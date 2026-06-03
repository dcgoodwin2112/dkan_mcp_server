<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds an RFC 9728 Bearer challenge to unauthenticated MCP responses.
 *
 * In the production OAuth posture (`http_basic_auth` off), a credential-less
 * request to the MCP endpoint is denied at the route's `access mcp server`
 * permission before reaching the MCP handler, so mcp_server_oauth's
 * AuthenticationErrorSubscriber (which only rewrites JSON-RPC -32001 handler
 * errors) never runs and no usable challenge is emitted. This subscriber emits
 * `WWW-Authenticate: Bearer resource_metadata="<PRM url>"` so spec-compliant
 * MCP clients can discover the protected-resource metadata and start the OAuth
 * flow. When a Bearer challenge is already present (e.g. an invalid token
 * handled by simple_oauth), it only appends the missing `resource_metadata`.
 *
 * Inert when Basic auth is enabled (local/demo) or when the
 * simple_oauth_server_metadata discovery endpoint is unavailable.
 */
final class UnauthorizedChallengeSubscriber implements EventSubscriberInterface {

  /**
   * Route of the RFC 9728 protected resource metadata document.
   */
  private const PRM_ROUTE = 'simple_oauth_server_metadata.resource_metadata';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountInterface $currentUser,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly UrlGeneratorInterface $urlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run late so it sees challenges set by simple_oauth / mcp_server_oauth.
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

  /**
   * Adds the Bearer/resource_metadata challenge to anonymous MCP 401/403s.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'mcp_server.handle') {
      return;
    }
    // Only in OAuth-only mode, only for unauthenticated callers, and only when
    // the discovery document this points to actually exists.
    if ($this->configFactory->get('dkan_mcp_server.settings')->get('http_basic_auth')) {
      return;
    }
    if (!$this->currentUser->isAnonymous()) {
      return;
    }
    if (!$this->moduleHandler->moduleExists('simple_oauth_server_metadata')) {
      return;
    }

    $response = $event->getResponse();
    if (!in_array($response->getStatusCode(), [401, 403], TRUE)) {
      return;
    }

    // Build the discovery URL from the route so it stays correct for
    // subdirectory installs and any base-path/route alterations. The
    // module-exists guard above guarantees the route is registered.
    $prm = $this->urlGenerator->generateFromRoute(self::PRM_ROUTE, [], ['absolute' => TRUE]);
    $metadata = sprintf('resource_metadata="%s"', $prm);
    $existing = (string) $response->headers->get('WWW-Authenticate', '');

    if (stripos($existing, 'Bearer') === 0) {
      // Augment an existing Bearer challenge (e.g. invalid-token from
      // simple_oauth) with the discovery pointer if it lacks one.
      if (stripos($existing, 'resource_metadata=') === FALSE) {
        $response->headers->set('WWW-Authenticate', $existing . ', ' . $metadata);
      }
      return;
    }

    // No usable challenge (credential-less request): emit a fresh one.
    $response->setStatusCode(401);
    $response->headers->set('WWW-Authenticate', 'Bearer ' . $metadata);
  }

}
