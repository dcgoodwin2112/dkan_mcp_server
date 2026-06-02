<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: join two datastore resources and query the result.
 */
#[Tool(
  id: 'query_datastore_join',
  label: new TranslatableMarkup('Query datastore (join)'),
  description: new TranslatableMarkup('Join two datastore resources on a column and query the result with filters, sorting, pagination, aggregation, and expressions. Use resource-qualified columns (t.col, j.col).'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'resourceId' => [
        'type' => 'string',
        'description' => 'Primary resource ID in identifier__version format (alias "t").',
      ],
      'joinResourceId' => [
        'type' => 'string',
        'description' => 'Resource ID to join in identifier__version format (alias "j").',
      ],
      'joinOn' => [
        'type' => 'string',
        'description' => 'Join condition as "col1=col2" shorthand or a JSON object.',
      ],
      'columns' => [
        'type' => 'string',
        'description' => 'Comma-separated (optionally resource-qualified, e.g. t.col,j.col) columns to return (omit for all).',
      ],
      'conditions' => [
        'type' => 'string',
        'description' => 'JSON array of condition objects: [{"property":"col","value":"val","operator":"="}].',
      ],
      'sortField' => [
        'type' => 'string',
        'description' => 'Column name to sort by.',
      ],
      'sortDirection' => [
        'type' => 'string',
        'enum' => ['asc', 'desc'],
        'default' => 'asc',
      ],
      'limit' => [
        'type' => 'integer',
        'description' => 'Max rows to return (1-500).',
        'default' => 100,
        'minimum' => 1,
        'maximum' => 500,
      ],
      'offset' => [
        'type' => 'integer',
        'description' => 'Number of rows to skip.',
        'default' => 0,
        'minimum' => 0,
      ],
      'expressions' => [
        'type' => 'string',
        'description' => 'JSON array of aggregate or arithmetic expressions (see query_datastore).',
      ],
      'groupings' => [
        'type' => 'string',
        'description' => 'Comma-separated columns to GROUP BY (required with aggregate expressions).',
      ],
    ],
    'required' => ['resourceId', 'joinResourceId', 'joinOn'],
  ],
  outputSchema: [
    'type' => 'object',
    'properties' => [
      'results' => ['type' => 'array'],
      'result_count' => ['type' => 'integer'],
      'total_rows' => ['type' => 'integer'],
      'limit' => ['type' => 'integer'],
      'offset' => ['type' => 'integer'],
      'sanity_flags' => ['type' => 'object'],
    ],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class QueryDatastoreJoinTool extends DatastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->datastore->queryDatastoreJoin(
      (string) ($arguments['resourceId'] ?? ''),
      (string) ($arguments['joinResourceId'] ?? ''),
      (string) ($arguments['joinOn'] ?? ''),
      $arguments['columns'] ?? NULL,
      $arguments['conditions'] ?? NULL,
      $arguments['sortField'] ?? NULL,
      $arguments['sortDirection'] ?? 'asc',
      (int) ($arguments['limit'] ?? 100),
      (int) ($arguments['offset'] ?? 0),
      $arguments['expressions'] ?? NULL,
      $arguments['groupings'] ?? NULL,
    );
  }

}
