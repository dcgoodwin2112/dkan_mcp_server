<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\ResourceTemplateProvider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\ResourceTemplateProvider;

/**
 * Exposes a single dataset's metadata at dkan://dataset/{id}.
 */
#[ResourceTemplateProvider(
  id: 'dkan_dataset',
  label: new TranslatableMarkup('DKAN dataset'),
  description: new TranslatableMarkup("A single dataset's full metadata, addressed by UUID."),
  module_dependencies: ['dkan_query_tools'],
)]
final class DatasetResourceTemplate extends DkanResourceTemplateProviderBase {

  /**
   * {@inheritdoc}
   */
  protected function templates(): array {
    return [
      [
        'uriTemplate' => 'dkan://dataset/{id}',
        'pattern' => '#^dkan://dataset/([^/]+)$#',
        'name' => 'dkan_dataset',
        'description' => 'Full metadata for a dataset, by UUID.',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function fetch(string $name, string $id): ?array {
    return $this->metastore->getDataset($id);
  }

}
