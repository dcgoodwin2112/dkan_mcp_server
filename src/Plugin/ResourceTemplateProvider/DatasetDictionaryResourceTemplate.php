<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\ResourceTemplateProvider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\ResourceTemplateProvider;

/**
 * Exposes a dataset's data dictionaries at dkan://dataset/{id}/dictionary.
 */
#[ResourceTemplateProvider(
  id: 'dkan_dataset_dictionary',
  label: new TranslatableMarkup('DKAN dataset dictionary'),
  description: new TranslatableMarkup('The data dictionaries linked to a dataset, addressed by UUID.'),
  module_dependencies: ['dkan_query_tools'],
)]
final class DatasetDictionaryResourceTemplate extends DkanResourceTemplateProviderBase {

  /**
   * {@inheritdoc}
   */
  protected function templates(): array {
    return [
      [
        'uriTemplate' => 'dkan://dataset/{id}/dictionary',
        'pattern' => '#^dkan://dataset/([^/]+)/dictionary$#',
        'name' => 'dkan_dataset_dictionary',
        'description' => 'Data dictionaries linked to a dataset, by UUID.',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function fetch(string $name, string $id): ?array {
    return $this->metastore->getDataDictionary($id);
  }

}
