<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\dkan_mcp_server\EventSubscriber\UnauthorizedChallengeSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Unit tests for the RFC 9728 unauthenticated MCP challenge.
 */
final class UnauthorizedChallengeSubscriberTest extends TestCase {

  /**
   * Builds a subscriber with the given collaborators' behavior.
   */
  private function subscriber(bool $basic_auth, bool $anonymous, bool $metadata_module): UnauthorizedChallengeSubscriber {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('http_basic_auth')->willReturn($basic_auth);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('dkan_mcp_server.settings')->willReturn($config);

    $account = $this->createMock(AccountInterface::class);
    $account->method('isAnonymous')->willReturn($anonymous);

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->with('simple_oauth_server_metadata')->willReturn($metadata_module);

    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')
      ->willReturn('https://example.test/.well-known/oauth-protected-resource');

    return new UnauthorizedChallengeSubscriber($config_factory, $account, $module_handler, $url_generator);
  }

  /**
   * Dispatches a response event for the mcp route and returns the response.
   */
  private function handle(UnauthorizedChallengeSubscriber $s, Response $response): Response {
    $request = Request::create('https://example.test/mcp', 'POST');
    $request->attributes->set('_route', 'mcp_server.handle');
    $event = new ResponseEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      $response,
    );
    $s->onResponse($event);
    return $event->getResponse();
  }

  /**
   * Subscribes to the response event late.
   */
  public function testSubscribesToResponseLate(): void {
    $events = UnauthorizedChallengeSubscriber::getSubscribedEvents();
    $this->assertSame([['onResponse', -10]], $events[KernelEvents::RESPONSE] ? [$events[KernelEvents::RESPONSE]] : []);
  }

  /**
   * Anonymous 403 in OAuth-only mode gets a fresh Bearer challenge + 401.
   */
  public function testFreshChallengeOnAnonymous403(): void {
    $s = $this->subscriber(basic_auth: FALSE, anonymous: TRUE, metadata_module: TRUE);
    $response = $this->handle($s, new Response('', 403));

    $this->assertSame(401, $response->getStatusCode());
    $header = $response->headers->get('WWW-Authenticate');
    $this->assertStringStartsWith('Bearer ', $header);
    $this->assertStringContainsString('resource_metadata="https://example.test/.well-known/oauth-protected-resource"', $header);
  }

  /**
   * An existing Bearer challenge only gains the resource_metadata pointer.
   */
  public function testAugmentsExistingBearerChallenge(): void {
    $s = $this->subscriber(basic_auth: FALSE, anonymous: TRUE, metadata_module: TRUE);
    $response = new Response('', 401);
    $response->headers->set('WWW-Authenticate', 'Bearer realm="OAuth", error="access_denied"');

    $out = $this->handle($s, $response);
    $header = $out->headers->get('WWW-Authenticate');
    $this->assertStringContainsString('error="access_denied"', $header);
    $this->assertStringContainsString('resource_metadata=', $header);
  }

  /**
   * Basic-auth mode (local/demo) leaves the response untouched.
   */
  public function testInertWhenBasicAuthEnabled(): void {
    $s = $this->subscriber(basic_auth: TRUE, anonymous: TRUE, metadata_module: TRUE);
    $out = $this->handle($s, new Response('', 401));
    $this->assertNull($out->headers->get('WWW-Authenticate'));
  }

  /**
   * Authenticated-but-unauthorized users are not given a Bearer challenge.
   */
  public function testInertForAuthenticatedUser(): void {
    $s = $this->subscriber(basic_auth: FALSE, anonymous: FALSE, metadata_module: TRUE);
    $out = $this->handle($s, new Response('', 403));
    $this->assertSame(403, $out->getStatusCode());
    $this->assertNull($out->headers->get('WWW-Authenticate'));
  }

  /**
   * Without the discovery module there is nothing to point at.
   */
  public function testInertWithoutMetadataModule(): void {
    $s = $this->subscriber(basic_auth: FALSE, anonymous: TRUE, metadata_module: FALSE);
    $out = $this->handle($s, new Response('', 401));
    $this->assertNull($out->headers->get('WWW-Authenticate'));
  }

}
