<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit\EventSubscriber;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\dkan_mcp_server\EventSubscriber\ToolAccessSubscriber;
use Drupal\mcp_server\Exception\McpAuthorizationDeniedException;
use Drupal\mcp_server\Plugin\ToolPluginInterface;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the per-tool access gate.
 *
 * Verifies that ToolAccessSubscriber activates each plugin's checkToolAccess()
 * over both MCP gates: tools/call (deny) and tools/list (hide), with the
 * documented deferral for tool names that do not resolve to a tool plugin.
 */
final class ToolAccessSubscriberTest extends TestCase {

  /**
   * Both SDK events are subscribed at the elevated priority.
   */
  public function testSubscribedEvents(): void {
    $events = ToolAccessSubscriber::getSubscribedEvents();
    $this->assertSame(['onRequest', 100], $events[RequestEvent::class]);
    $this->assertSame(['onResponse', 100], $events[ResponseEvent::class]);
  }

  /**
   * A forbidden tool/call is denied with a 403.
   */
  public function testCallDeniedWhenForbidden(): void {
    $subscriber = new ToolAccessSubscriber(
      $this->managerReturning(['delete_dataset' => $this->toolPlugin(AccessResult::forbidden())]),
      $this->user(),
    );
    $event = new RequestEvent(new CallToolRequest('delete_dataset', []), $this->session());

    try {
      $subscriber->onRequest($event);
      $this->fail('Expected McpAuthorizationDeniedException.');
    }
    catch (McpAuthorizationDeniedException $e) {
      $this->assertSame('forbidden', $e->getMessage());
      $this->assertSame(403, $e->httpStatus);
    }
  }

  /**
   * An allowed tool/call passes through, having consulted the plugin.
   */
  public function testCallAllowedWhenPermitted(): void {
    $resolved = [];
    $subscriber = new ToolAccessSubscriber(
      $this->managerSpy($resolved, ['list_datasets' => $this->toolPlugin(AccessResult::allowed())]),
      $this->user(),
    );

    $subscriber->onRequest(new RequestEvent(new CallToolRequest('list_datasets', []), $this->session()));

    $this->assertSame(['list_datasets'], $resolved);
  }

  /**
   * Non-CallTool requests are ignored without resolving any plugin.
   */
  public function testNonCallRequestIgnored(): void {
    $resolved = [];
    $subscriber = new ToolAccessSubscriber($this->managerSpy($resolved, []), $this->user());

    $subscriber->onRequest(new RequestEvent(new ListToolsRequest(), $this->session()));

    $this->assertSame([], $resolved);
  }

  /**
   * An unknown tool name (PluginException) is deferred, not denied.
   */
  public function testCallDeferredWhenPluginNotFound(): void {
    $resolved = [];
    $subscriber = new ToolAccessSubscriber($this->managerSpy($resolved, []), $this->user());

    // No exception: the empty map makes the spy throw PluginNotFoundException,
    // which toolAllowed() swallows and defers.
    $subscriber->onRequest(new RequestEvent(new CallToolRequest('ghost', []), $this->session()));

    $this->assertSame(['ghost'], $resolved);
  }

  /**
   * A name resolving to a non-tool plugin is deferred, not denied.
   */
  public function testCallDeferredWhenNotToolPlugin(): void {
    $resolved = [];
    $subscriber = new ToolAccessSubscriber(
      $this->managerSpy($resolved, ['bridge' => $this->createMock(PluginInspectionInterface::class)]),
      $this->user(),
    );

    $subscriber->onRequest(new RequestEvent(new CallToolRequest('bridge', []), $this->session()));

    $this->assertSame(['bridge'], $resolved);
  }

  /**
   * Inaccessible tools are dropped from a tools/list result.
   */
  public function testListFiltersForbiddenTools(): void {
    $subscriber = new ToolAccessSubscriber(
      $this->managerReturning([
        'get_dataset' => $this->toolPlugin(AccessResult::allowed()),
        'delete_dataset' => $this->toolPlugin(AccessResult::forbidden()),
      ]),
      $this->user(),
    );
    $original = new Response(7, new ListToolsResult(
      [$this->tool('get_dataset'), $this->tool('delete_dataset')],
      'cursor-42',
    ));
    $event = new ResponseEvent($original, new ListToolsRequest(), $this->session());

    $subscriber->onResponse($event);

    $response = $event->getResponse();
    $this->assertNotSame($original, $response);
    $this->assertInstanceOf(ListToolsResult::class, $response->result);
    $this->assertSame(['get_dataset'], array_map(fn (Tool $t): string => $t->name, $response->result->tools));
    // Id and pagination cursor survive the rebuild.
    $this->assertSame(7, $response->getId());
    $this->assertSame('cursor-42', $response->result->nextCursor);
  }

  /**
   * A tools/list result is left untouched when every tool is accessible.
   */
  public function testListUnchangedWhenAllAllowed(): void {
    $subscriber = new ToolAccessSubscriber(
      $this->managerReturning([
        'get_dataset' => $this->toolPlugin(AccessResult::allowed()),
        'list_datasets' => $this->toolPlugin(AccessResult::allowed()),
      ]),
      $this->user(),
    );
    $original = new Response(1, new ListToolsResult([$this->tool('get_dataset'), $this->tool('list_datasets')]));
    $event = new ResponseEvent($original, new ListToolsRequest(), $this->session());

    $subscriber->onResponse($event);

    $this->assertSame($original, $event->getResponse());
  }

  /**
   * Unresolvable tool names are kept in the listing (deferred).
   */
  public function testListKeepsUnresolvableTools(): void {
    $subscriber = new ToolAccessSubscriber(
      // 'bridge' is absent from the map, so the manager throws and it defers.
      $this->managerReturning(['get_dataset' => $this->toolPlugin(AccessResult::allowed())]),
      $this->user(),
    );
    $original = new Response(1, new ListToolsResult([$this->tool('get_dataset'), $this->tool('bridge')]));
    $event = new ResponseEvent($original, new ListToolsRequest(), $this->session());

    $subscriber->onResponse($event);

    $this->assertSame($original, $event->getResponse());
  }

  /**
   * Responses to non-ListTools requests are ignored.
   */
  public function testNonListResponseIgnored(): void {
    $resolved = [];
    $subscriber = new ToolAccessSubscriber($this->managerSpy($resolved, []), $this->user());
    $original = new Response(1, new ListToolsResult([$this->tool('get_dataset')]));
    $event = new ResponseEvent($original, new CallToolRequest('get_dataset', []), $this->session());

    $subscriber->onResponse($event);

    $this->assertSame($original, $event->getResponse());
    $this->assertSame([], $resolved);
  }

  /**
   * A ListTools request whose result is not a ListToolsResult is ignored.
   */
  public function testNonListResultIgnored(): void {
    $resolved = [];
    $subscriber = new ToolAccessSubscriber($this->managerSpy($resolved, []), $this->user());
    $original = new Response(1, 'not-a-list-result');
    $event = new ResponseEvent($original, new ListToolsRequest(), $this->session());

    $subscriber->onResponse($event);

    $this->assertSame($original, $event->getResponse());
    $this->assertSame([], $resolved);
  }

  /**
   * Builds a plugin-manager mock keyed by tool name.
   *
   * Names absent from the map make createInstance() throw
   * PluginNotFoundException.
   *
   * @param array<string, object> $map
   *   Tool name keyed to the plugin instance createInstance() should return.
   */
  private function managerReturning(array $map): PluginManagerInterface {
    $unused = [];
    return $this->managerSpy($unused, $map);
  }

  /**
   * Like managerReturning(), but records each resolved name into $resolved.
   *
   * @param array<int, string> $resolved
   *   Receives every tool name passed to createInstance().
   * @param array<string, object> $map
   *   Tool name keyed to the plugin instance createInstance() should return.
   */
  private function managerSpy(array &$resolved, array $map): PluginManagerInterface {
    $manager = $this->createMock(PluginManagerInterface::class);
    $manager->method('createInstance')->willReturnCallback(
      function (string $id) use (&$resolved, $map): object {
        $resolved[] = $id;
        if (!array_key_exists($id, $map)) {
          throw new PluginNotFoundException($id);
        }
        return $map[$id];
      },
    );
    return $manager;
  }

  /**
   * A tool plugin whose checkToolAccess() returns the given result.
   */
  private function toolPlugin(AccessResultInterface $access): ToolPluginInterface {
    $plugin = $this->createMock(ToolPluginInterface::class);
    $plugin->method('checkToolAccess')->willReturn($access);
    return $plugin;
  }

  /**
   * A minimal MCP tool schema object with the given name.
   */
  private function tool(string $name): Tool {
    return new Tool($name, NULL, ['type' => 'object', 'properties' => []], NULL, NULL);
  }

  /**
   * A mocked current user.
   */
  private function user(): AccountProxyInterface {
    return $this->createMock(AccountProxyInterface::class);
  }

  /**
   * A mocked MCP session for event construction.
   */
  private function session(): SessionInterface {
    return $this->createMock(SessionInterface::class);
  }

}
