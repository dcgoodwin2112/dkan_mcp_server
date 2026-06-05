<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit\EventSubscriber;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dkan_mcp_server\EventSubscriber\PromptRenderSubscriber;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the downstream prompts/get rendering shim.
 *
 * Verifies PromptRenderSubscriber works around both upstream defects: it
 * substitutes {{ arg }} placeholders (Defect B) and emits typed content rather
 * than a json-encoded list (Defect A), while leaving non-prompt and
 * unknown-name responses untouched.
 *
 * @group dkan_mcp_server
 */
#[Group('dkan_mcp_server')]
final class PromptRenderSubscriberTest extends TestCase {

  /**
   * Only the response event is subscribed, at the elevated priority.
   */
  public function testSubscribedEvents(): void {
    $events = PromptRenderSubscriber::getSubscribedEvents();
    $this->assertSame(['onResponse', 100], $events[ResponseEvent::class]);
  }

  /**
   * A non-GetPrompt response is left untouched without touching storage.
   */
  public function testNonPromptResponseIgnored(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->never())->method('getStorage');
    $subscriber = new PromptRenderSubscriber($manager);

    $original = new Response(1, 'untouched');
    $event = new ResponseEvent($original, new ListToolsRequest(), $this->session());

    $subscriber->onResponse($event);

    $this->assertSame($original, $event->getResponse());
  }

  /**
   * An unknown prompt name leaves the SDK response untouched.
   */
  public function testUnknownPromptNameIgnored(): void {
    $subscriber = new PromptRenderSubscriber($this->managerReturning('ghost', NULL));

    $original = new Response(2, 'untouched');
    $event = new ResponseEvent($original, new GetPromptRequest('ghost', []), $this->session());

    $subscriber->onResponse($event);

    $this->assertSame($original, $event->getResponse());
  }

  /**
   * A text item is substituted and emitted as TextContent, not a JSON list.
   */
  public function testTextContentSubstitutedAndTyped(): void {
    $config = $this->promptConfig(
      [
        [
          'role' => 'user',
          'content' => [
            ['type' => 'text', 'text' => 'Explore {{ dataset_id }} now.'],
          ],
        ],
      ],
      'Explore a dataset.',
    );
    $subscriber = new PromptRenderSubscriber($this->managerReturning('explore_dataset', $config));

    $event = new ResponseEvent(
      new Response(7, 'mangled'),
      new GetPromptRequest('explore_dataset', ['dataset_id' => 'abc-123']),
      $this->session(),
    );

    $subscriber->onResponse($event);

    $response = $event->getResponse();
    $this->assertSame(7, $response->getId(), 'The response id is preserved.');
    $result = $response->result;
    $this->assertInstanceOf(GetPromptResult::class, $result);
    $this->assertSame('Explore a dataset.', $result->description);
    $this->assertCount(1, $result->messages);

    $message = $result->messages[0];
    $this->assertInstanceOf(PromptMessage::class, $message);
    $this->assertSame(Role::User, $message->role);
    $this->assertInstanceOf(TextContent::class, $message->content);
    $this->assertSame('Explore abc-123 now.', $message->content->text);
  }

  /**
   * Unknown tokens survive substitution unchanged.
   */
  public function testUnknownTokenKept(): void {
    $config = $this->promptConfig(
      [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi {{ missing }}']]]],
      NULL,
    );
    $subscriber = new PromptRenderSubscriber($this->managerReturning('p', $config));

    $event = new ResponseEvent(
      new Response(1, 'mangled'),
      new GetPromptRequest('p', []),
      $this->session(),
    );

    $subscriber->onResponse($event);

    $content = $event->getResponse()->result->messages[0]->content;
    $this->assertSame('Hi {{ missing }}', $content->text);
  }

  /**
   * Image and resource items map to their typed content objects.
   */
  public function testImageAndResourceItemsTyped(): void {
    $config = $this->promptConfig(
      [
        ['role' => 'user', 'content' => [['type' => 'image', 'data' => 'aGk=', 'mimeType' => 'image/png']]],
        [
          'role' => 'assistant',
          'content' => [
            [
              'type' => 'resource',
              'resource' => [
                'uri' => 'dkan://dataset/{{ id }}',
                'mimeType' => 'application/json',
                'text' => 'doc for {{ id }}',
              ],
            ],
          ],
        ],
      ],
      NULL,
    );
    $subscriber = new PromptRenderSubscriber($this->managerReturning('mixed', $config));

    $event = new ResponseEvent(
      new Response(1, 'mangled'),
      new GetPromptRequest('mixed', ['id' => 'd1']),
      $this->session(),
    );

    $subscriber->onResponse($event);

    $messages = $event->getResponse()->result->messages;
    $this->assertCount(2, $messages);

    $this->assertInstanceOf(ImageContent::class, $messages[0]->content);
    $this->assertSame(Role::User, $messages[0]->role);

    $this->assertInstanceOf(EmbeddedResource::class, $messages[1]->content);
    $this->assertSame(Role::Assistant, $messages[1]->role);
    // The resource URI and text are both templated like any other field.
    $this->assertSame('dkan://dataset/d1', $messages[1]->content->resource->uri);
    $this->assertSame('doc for d1', $messages[1]->content->resource->text);
  }

  /**
   * A message with multiple content items fans out to one message per item.
   */
  public function testMultiItemMessageFansOut(): void {
    $config = $this->promptConfig(
      [
        [
          'role' => 'user',
          'content' => [
            ['type' => 'text', 'text' => 'one'],
            ['type' => 'text', 'text' => 'two'],
          ],
        ],
      ],
      NULL,
    );
    $subscriber = new PromptRenderSubscriber($this->managerReturning('multi', $config));

    $event = new ResponseEvent(
      new Response(1, 'mangled'),
      new GetPromptRequest('multi', []),
      $this->session(),
    );

    $subscriber->onResponse($event);

    $messages = $event->getResponse()->result->messages;
    $this->assertCount(2, $messages);
    $this->assertSame('one', $messages[0]->content->text);
    $this->assertSame('two', $messages[1]->content->text);
  }

  /**
   * A prompt with no renderable content leaves the response untouched.
   */
  public function testEmptyMessagesLeaveResponseUntouched(): void {
    $config = $this->promptConfig([['role' => 'user', 'content' => [['type' => 'bogus']]]], NULL);
    $subscriber = new PromptRenderSubscriber($this->managerReturning('empty', $config));

    $original = new Response(1, 'untouched');
    $event = new ResponseEvent($original, new GetPromptRequest('empty', []), $this->session());

    $subscriber->onResponse($event);

    $this->assertSame($original, $event->getResponse());
  }

  /**
   * An entity-type manager whose prompt storage loads the given config object.
   *
   * @param string $name
   *   The prompt name the storage expects to load.
   * @param object|null $config
   *   The config object load() returns (NULL for a miss).
   */
  private function managerReturning(string $name, ?object $config): EntityTypeManagerInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with($name)->willReturn($config);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('mcp_prompt_config')->willReturn($storage);
    return $manager;
  }

  /**
   * A stand-in prompt config exposing the two methods the subscriber reads.
   *
   * McpPromptConfig is final, so this duck-typed stub stands in for it.
   *
   * @param array $messages
   *   The stored message templates.
   * @param string|null $description
   *   The prompt description.
   */
  private function promptConfig(array $messages, ?string $description): object {
    return new class($messages, $description) {

      public function __construct(
        private readonly array $messages,
        private readonly ?string $description,
      ) {}

      /**
       * Returns the stored message templates.
       */
      public function getMessages(): array {
        return $this->messages;
      }

      /**
       * Returns the prompt description.
       */
      public function getDescription(): ?string {
        return $this->description;
      }

    };
  }

  /**
   * A mocked MCP session for event construction.
   */
  private function session(): SessionInterface {
    return $this->createMock(SessionInterface::class);
  }

}
