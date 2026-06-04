<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Result\GetPromptResult;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Renders mcp_server prompt config correctly until two upstream defects land.
 *
 * At the pinned commits (mcp_server@5d6b54c, mcp/sdk@0347dc8) prompts/get is
 * unusable for every config prompt because:
 * - Defect A: the config schema stores a message's content as a sequence, but
 *   the SDK's PromptResultFormatter only handles a string or a single typed
 *   dict, so a list falls through to a json_encode fallback and is returned as
 *   a JSON string instead of typed content.
 * - Defect B: PromptConfigHandler returns the stored templates verbatim and
 *   never substitutes {{ arg }} placeholders from the request arguments.
 *
 * This regenerates the GetPromptResult from the config entity plus the
 * request's arguments, mapping each content item to a typed Content object and
 * fanning a multi-item message out to one PromptMessage per item (per spec).
 * It uses ResponseEvent, the blessed downstream hook dispatched for every
 * successful response, so it covers HTTP and STDIO identically — the same
 * mechanism ToolAccessSubscriber already uses.
 *
 * Remove this subscriber (and its service + tests) when mcp_server ships fixes
 * for both defects and the pins are bumped. See PROMPTS_PLAN.md and
 * contrib-mcp-server-contributions.md (#3/#4).
 */
final class PromptRenderSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ResponseEvent::class => ['onResponse', 100]];
  }

  /**
   * Rebuilds a prompts/get response from config so it renders correctly.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    if (!$request instanceof GetPromptRequest) {
      return;
    }
    $config = $this->entityTypeManager
      ->getStorage('mcp_prompt_config')
      ->load($request->name);
    if ($config === NULL) {
      // Unknown name or not a config prompt: leave the SDK response untouched.
      return;
    }
    $messages = $this->render($config->getMessages(), $request->arguments ?? []);
    if ($messages === []) {
      return;
    }
    $event->setResponse(new Response(
      $event->getResponse()->getId(),
      new GetPromptResult($messages, $config->getDescription()),
    ));
  }

  /**
   * Maps config messages to typed PromptMessage objects with args substituted.
   *
   * @param array<int, array{role?: string, content?: array}> $messages
   *   The stored message templates.
   * @param array<string, mixed> $arguments
   *   The client-supplied prompt arguments.
   *
   * @return \Mcp\Schema\Content\PromptMessage[]
   *   One PromptMessage per content item.
   */
  private function render(array $messages, array $arguments): array {
    $out = [];
    foreach ($messages as $message) {
      $role = Role::tryFrom($message['role'] ?? 'user') ?? Role::User;
      // The spec models content as one block per message; fan a list out.
      foreach ($message['content'] ?? [] as $item) {
        $content = $this->toContent($item, $arguments);
        if ($content !== NULL) {
          $out[] = new PromptMessage($role, $content);
        }
      }
    }
    return $out;
  }

  /**
   * Converts one config content item to a typed SDK Content object.
   *
   * @param array $item
   *   A content item from the config message.
   * @param array<string, mixed> $arguments
   *   The client-supplied prompt arguments.
   */
  private function toContent(array $item, array $arguments): TextContent|ImageContent|AudioContent|EmbeddedResource|NULL {
    return match ($item['type'] ?? NULL) {
      'text' => new TextContent($this->substitute($item['text'] ?? '', $arguments)),
      'image' => new ImageContent($item['data'] ?? '', $item['mimeType'] ?? ''),
      'audio' => new AudioContent($item['data'] ?? '', $item['mimeType'] ?? ''),
      'resource' => $this->toResource($item['resource'] ?? [], $arguments),
      default => NULL,
    };
  }

  /**
   * Converts a config resource item to an embedded text resource, or NULL.
   *
   * @param array $resource
   *   The resource sub-array of a content item.
   * @param array<string, mixed> $arguments
   *   The client-supplied prompt arguments.
   */
  private function toResource(array $resource, array $arguments): ?EmbeddedResource {
    if (empty($resource['uri'])) {
      return NULL;
    }
    return new EmbeddedResource(new TextResourceContents(
      $this->substitute($resource['uri'], $arguments),
      $resource['mimeType'] ?? 'text/plain',
      $this->substitute($resource['text'] ?? '', $arguments),
    ));
  }

  /**
   * Replaces {{ name }} tokens with argument values; unknown tokens are kept.
   *
   * @param string $text
   *   The template text.
   * @param array<string, mixed> $arguments
   *   The client-supplied prompt arguments.
   */
  private function substitute(string $text, array $arguments): string {
    return preg_replace_callback(
      '/\{\{\s*(\w+)\s*\}\}/',
      static fn (array $m): string => (string) ($arguments[$m[1]] ?? $m[0]),
      $text,
    ) ?? $text;
  }

}
