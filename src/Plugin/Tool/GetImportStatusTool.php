<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: datastore import status and row/column counts for a resource.
 */
#[Tool(
  id: 'get_import_status',
  label: new TranslatableMarkup('Get import status'),
  description: new TranslatableMarkup('Get the datastore import status (done/pending/not_imported) and row/column counts for a resource.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'resourceId' => [
        'type' => 'string',
        'description' => 'Resource ID in identifier__version format (from list_distributions).',
      ],
    ],
    'required' => ['resourceId'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetImportStatusTool extends DatastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->datastore->getImportStatus((string) ($arguments['resourceId'] ?? ''));
  }

}
