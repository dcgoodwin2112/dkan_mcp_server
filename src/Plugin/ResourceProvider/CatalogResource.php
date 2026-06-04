<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\ResourceProvider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\ResourceProvider;
use Drupal\mcp_server\Resource\CacheableResourceContent;

/**
 * Exposes the DKAN DCAT catalog as a single concrete MCP resource.
 */
#[ResourceProvider(
  id: 'dkan_catalog',
  label: new TranslatableMarkup('DKAN catalog'),
  description: new TranslatableMarkup('The full DCAT data catalog (descriptions truncated, spatial fields removed).'),
  module_dependencies: ['dkan_query_tools'],
)]
final class CatalogResource extends DkanResourceProviderBase {

  /**
   * The URI advertised by this provider.
   */
  private const URI = 'dkan://catalog';

  /**
   * {@inheritdoc}
   */
  public function getResources(): array {
    return [
      [
        'uri' => self::URI,
        'name' => 'dkan_catalog',
        'description' => "The site's DCAT data catalog.",
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
    return $this->jsonContent($uri, $this->metastore->getCatalog(), $this->dataCacheTags());
  }

}
