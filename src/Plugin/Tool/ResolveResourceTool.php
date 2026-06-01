<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: trace the full reference chain for a resource.
 */
#[Tool(
  id: 'resolve_resource',
  label: new TranslatableMarkup('Resolve resource'),
  description: new TranslatableMarkup('Trace a resource chain: distribution UUID or resource ID to its perspectives, datastore table, import status, and owning dataset.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'id' => [
        'type' => 'string',
        'description' => 'Distribution UUID or resource ID in identifier__version format.',
      ],
    ],
    'required' => ['id'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ResolveResourceTool extends ResourceToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->resource->resolveResource((string) ($arguments['id'] ?? ''));
  }

}
