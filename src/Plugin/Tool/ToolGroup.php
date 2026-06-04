<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Canonical tool-group taxonomy for the operational enable/disable surface.
 *
 * Groups are DKAN subsystems, not operation types: each tool belongs to exactly
 * one group, matching its base class. Disabling a group hides every tool in it
 * (reads and writes alike) from tools/list and rejects them on tools/call. This
 * is operational gating, not authorization — to control who may mutate data,
 * use the `* via mcp` permissions, which are enforced independently.
 */
final class ToolGroup {

  const METASTORE = 'metastore';
  const DATASTORE = 'datastore';
  const SEARCH = 'search';
  const HARVEST = 'harvest';
  const RESOURCE = 'resource';
  const STATUS = 'status';
  const WRITE = 'write';

  /**
   * Group machine name => translatable label + description.
   *
   * Single source of truth for the taxonomy's user-facing text. The strings are
   * literal TranslatableMarkup so they are extractable for translation (the
   * form passes them through as placeholders); construction needs no container.
   *
   * @return array<string, array{label: \Drupal\Core\StringTranslation\TranslatableMarkup, description: \Drupal\Core\StringTranslation\TranslatableMarkup}>
   *   The group definitions, keyed by machine name.
   */
  public static function definitions(): array {
    return [
      self::METASTORE => [
        'label' => new TranslatableMarkup('Metastore (catalog reads)'),
        'description' => new TranslatableMarkup('Read dataset, distribution, schema, and catalog metadata.'),
      ],
      self::DATASTORE => [
        'label' => new TranslatableMarkup('Datastore (data reads)'),
        'description' => new TranslatableMarkup('Query datastore tables and read schema/stats/import status.'),
      ],
      self::SEARCH => [
        'label' => new TranslatableMarkup('Search'),
        'description' => new TranslatableMarkup('Full-text catalog search.'),
      ],
      self::HARVEST => [
        'label' => new TranslatableMarkup('Harvest'),
        'description' => new TranslatableMarkup('Read and run harvests (register, run, deregister, inspect plans/runs).'),
      ],
      self::RESOURCE => [
        'label' => new TranslatableMarkup('Resource'),
        'description' => new TranslatableMarkup('Resolve distribution resources to datastore identifiers.'),
      ],
      self::STATUS => [
        'label' => new TranslatableMarkup('Status'),
        'description' => new TranslatableMarkup('Site and queue status.'),
      ],
      self::WRITE => [
        'label' => new TranslatableMarkup('Write (dataset & metastore mutations)'),
        'description' => new TranslatableMarkup('Create, update, publish, and delete datasets, metastore items, and datastore tables. Still gated by the per-operation "via mcp" permissions.'),
      ],
    ];
  }

  /**
   * The valid group machine names.
   *
   * @return string[]
   *   The group machine names.
   */
  public static function ids(): array {
    return array_keys(self::definitions());
  }

}
