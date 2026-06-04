<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\ResourceProvider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\ResourceProvider;
use Drupal\mcp_server\Resource\CacheableResourceContent;

/**
 * Exposes the DKAN metastore schema list as a single concrete MCP resource.
 */
#[ResourceProvider(
  id: 'dkan_schemas',
  label: new TranslatableMarkup('DKAN schemas'),
  description: new TranslatableMarkup('The metastore schema identifiers available on this site (dataset, distribution, etc.).'),
  module_dependencies: ['dkan_query_tools'],
)]
final class SchemaListResource extends DkanResourceProviderBase {

  /**
   * The URI advertised by this provider.
   */
  private const URI = 'dkan://schemas';

  /**
   * {@inheritdoc}
   */
  public function getResources(): array {
    return [
      [
        'uri' => self::URI,
        'name' => 'dkan_schemas',
        'description' => 'List of metastore schema identifiers on this site.',
        'mimeType' => 'application/json',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getResourceContent(string $uri): ?CacheableResourceContent {
    if ($uri !== self::URI) {
      return NULL;
    }
    // Schema identifiers come from on-disk JSON schema files, not metastore
    // data, so no cache tags: the list changes only on deploy (cache rebuild).
    return $this->jsonContent($uri, $this->metastore->listSchemas());
  }

}
