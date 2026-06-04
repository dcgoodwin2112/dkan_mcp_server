<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\ResourceTemplateProvider;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dkan_metastore\Factory\MetastoreItemFactoryInterface;
use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Drupal\mcp_server\Attribute\ResourceTemplateProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Exposes a datastore schema at dkan://datastore/{resourceId}/schema.
 *
 * Keyed by resource_id (identifier__version) — the datastore's own key, as
 * surfaced by list_distributions / resolve_resource — not a distribution UUID,
 * so no UUID-to-resource resolution is needed.
 */
#[ResourceTemplateProvider(
  id: 'dkan_datastore_schema',
  label: new TranslatableMarkup('DKAN datastore schema'),
  description: new TranslatableMarkup("A datastore resource's column schema, addressed by resource_id."),
  module_dependencies: ['dkan_query_tools'],
)]
final class DatastoreSchemaResourceTemplate extends DkanResourceTemplateProviderBase {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    MetastoreTools $metastore,
    MetastoreItemFactoryInterface $metastoreItemFactory,
    protected readonly DatastoreTools $datastore,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entityTypeManager,
      $currentUser,
      $metastore,
      $metastoreItemFactory,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('dkan_query_tools.metastore'),
      $container->get('dkan.metastore.metastore_item_factory'),
      $container->get('dkan_query_tools.datastore'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function templates(): array {
    return [
      [
        'uriTemplate' => 'dkan://datastore/{resourceId}/schema',
        'pattern' => '#^dkan://datastore/([^/]+)/schema$#',
        'name' => 'dkan_datastore_schema',
        'description' => 'Column schema for a datastore resource, by resource_id.',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function fetch(string $name, string $id): ?array {
    return $this->datastore->getDatastoreSchema($id);
  }

}
