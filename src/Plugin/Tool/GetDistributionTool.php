<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: fetch full distribution metadata by UUID.
 */
#[Tool(
  id: 'get_distribution',
  label: new TranslatableMarkup('Get distribution'),
  description: new TranslatableMarkup('Fetch full distribution metadata by UUID.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'identifier' => [
        'type' => 'string',
        'description' => 'Distribution UUID.',
      ],
    ],
    'required' => ['identifier'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetDistributionTool extends MetastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->metastore->getDistribution((string) ($arguments['identifier'] ?? ''));
  }

}
