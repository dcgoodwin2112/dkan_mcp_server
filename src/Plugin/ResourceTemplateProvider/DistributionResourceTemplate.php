<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\ResourceTemplateProvider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\ResourceTemplateProvider;

/**
 * Exposes a single distribution's metadata at dkan://distribution/{id}.
 */
#[ResourceTemplateProvider(
  id: 'dkan_distribution',
  label: new TranslatableMarkup('DKAN distribution'),
  description: new TranslatableMarkup("A single distribution's metadata, addressed by UUID."),
  module_dependencies: ['dkan_query_tools'],
)]
final class DistributionResourceTemplate extends DkanResourceTemplateProviderBase {

  /**
   * {@inheritdoc}
   */
  protected function templates(): array {
    return [
      [
        'uriTemplate' => 'dkan://distribution/{id}',
        'pattern' => '#^dkan://distribution/([^/]+)$#',
        'name' => 'dkan_distribution',
        'description' => 'Metadata for a distribution, by UUID.',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function fetch(string $name, string $id): ?array {
    return $this->metastore->getDistribution($id);
  }

}
