<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\EventSubscriber;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\dkan_mcp_server\Plugin\Tool\GroupedToolInterface;
use Drupal\mcp_server\Exception\McpAuthorizationDeniedException;
use Drupal\mcp_server\Plugin\ToolPluginInterface;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Enforces each tool plugin's self-declared access across the MCP surface.
 *
 * Mcp_server core declares ToolPluginInterface::checkToolAccess() but never
 * invokes it: the factory registers every enabled tool, and the SDK both lists
 * and serves them to any user who can reach the server. This subscriber closes
 * that gap with two gates, both keyed on checkToolAccess():
 * - tools/call: deny the call (RequestEvent) when the user lacks access.
 * - tools/list: omit inaccessible tools from the listing (ResponseEvent).
 *
 * The base checkAccess() allows by default, so read tools that do not restrict
 * themselves are unaffected. Both gates run below the transport layer, so they
 * apply identically to HTTP and STDIO.
 *
 * The same two gates also enforce operational group gating: a tool whose group
 * (GroupedToolInterface::toolGroup()) is listed in
 * dkan_mcp_server.settings:disabled_groups is hidden and denied. This is not
 * authorization (which stays in permissions) — it lets an operator switch off a
 * whole DKAN subsystem regardless of who is calling.
 *
 * Downstream equivalent of the planned upstream core patch; lives here so we
 * can adopt mcp_server before/whether-or-not that lands.
 */
final class ToolAccessSubscriber implements EventSubscriberInterface {

  public function __construct(
    // Typed as the interface, not the final ToolPluginManager: we only call
    // createInstance(), and the abstraction keeps the subscriber unit-testable.
    private readonly PluginManagerInterface $toolPluginManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority 100: a permission denial is more fundamental than the OAuth
    // submodule's scope policy, and yields a clearer 403.
    return [
      RequestEvent::class => ['onRequest', 100],
      ResponseEvent::class => ['onResponse', 100],
    ];
  }

  /**
   * Denies a tools/call when the tool plugin forbids the current user.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if (!$request instanceof CallToolRequest) {
      return;
    }
    if (!$this->toolAllowed($request->name)) {
      throw new McpAuthorizationDeniedException('forbidden', 403);
    }
  }

  /**
   * Removes inaccessible tools from a tools/list result.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->getRequest() instanceof ListToolsRequest) {
      return;
    }
    $response = $event->getResponse();
    $result = $response->result;
    if (!$result instanceof ListToolsResult) {
      return;
    }
    $allowed = array_values(array_filter(
      $result->tools,
      fn (Tool $tool): bool => $this->toolAllowed($tool->name),
    ));
    if (count($allowed) === count($result->tools)) {
      return;
    }
    $event->setResponse(new Response(
      $response->getId(),
      new ListToolsResult($allowed, $result->nextCursor),
    ));
  }

  /**
   * Whether the current user may use the named tool.
   *
   * Tool names that do not resolve to a ToolPluginInterface (e.g. closure- or
   * bridge-registered tools) are deferred to other policy subscribers.
   */
  private function toolAllowed(string $tool_name): bool {
    $plugin = $this->resolveTool($tool_name);
    if (!$plugin instanceof ToolPluginInterface) {
      return TRUE;
    }
    if ($this->groupDisabled($plugin)) {
      return FALSE;
    }
    return $plugin->checkToolAccess($tool_name, $this->currentUser)->isAllowed();
  }

  /**
   * Whether the tool's group is switched off in settings.
   *
   * Tools that declare no group (GroupedToolInterface) are never group-gated.
   */
  private function groupDisabled(ToolPluginInterface $plugin): bool {
    if (!$plugin instanceof GroupedToolInterface) {
      return FALSE;
    }
    return in_array($plugin->toolGroup(), $this->disabledGroups(), TRUE);
  }

  /**
   * The operator-disabled tool groups; empty (and safe) when the key is unset.
   *
   * @return string[]
   *   The disabled group machine names.
   */
  private function disabledGroups(): array {
    return (array) ($this->configFactory
      ->get('dkan_mcp_server.settings')
      ->get('disabled_groups') ?? []);
  }

  /**
   * Resolves an MCP tool name to its plugin instance, or NULL.
   */
  private function resolveTool(string $tool_name): ?ToolPluginInterface {
    try {
      $plugin = $this->toolPluginManager->createInstance($tool_name);
    }
    catch (PluginException) {
      return NULL;
    }
    return $plugin instanceof ToolPluginInterface ? $plugin : NULL;
  }

}
