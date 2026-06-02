<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: catalog-wide search of datastore column names/descriptions.
 */
#[Tool(
  id: 'search_columns',
  label: new TranslatableMarkup('Search columns'),
  description: new TranslatableMarkup('Search datastore column names and/or descriptions across imported resources in the catalog.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'searchTerm' => [
        'type' => 'string',
        'description' => 'Text to search for in column names/descriptions.',
      ],
      'searchIn' => [
        'type' => 'string',
        'description' => 'Where to search.',
        'enum' => ['name', 'description', 'both'],
        'default' => 'name',
      ],
      'limit' => [
        'type' => 'integer',
        'description' => 'Maximum matches to return.',
        'default' => 100,
        'minimum' => 1,
        'maximum' => 500,
      ],
    ],
    'required' => ['searchTerm'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class SearchColumnsTool extends DatastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->datastore->searchColumns(
      (string) ($arguments['searchTerm'] ?? ''),
      (string) ($arguments['searchIn'] ?? 'name'),
      (int) ($arguments['limit'] ?? 100),
    );
  }

}
