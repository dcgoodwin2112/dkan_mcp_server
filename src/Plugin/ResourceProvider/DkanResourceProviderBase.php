<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\ResourceProvider;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\dkan_mcp_server\Resource\ResourceJsonContentTrait;
use Drupal\dkan_metastore\Factory\MetastoreItemFactoryInterface;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Drupal\mcp_server\Plugin\ResourceProviderBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for DKAN resource providers backed by the shared metastore service.
 *
 * Adds dkan_query_tools.metastore to mcp_server's ResourceProviderBase DI and
 * centralizes read access (open under `access mcp server`, matching the read
 * tools) plus JSON content shaping. Concrete providers declare only their
 * #[ResourceProvider] attribute, getResources(), and getResourceContent().
 */
abstract class DkanResourceProviderBase extends ResourceProviderBase {

  use ResourceJsonContentTrait;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    protected readonly MetastoreTools $metastore,
    protected readonly MetastoreItemFactoryInterface $metastoreItemFactory,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entityTypeManager,
      $currentUser,
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
    );
  }

  /**
   * DKAN cache tags that bust this content when metastore data changes.
   *
   * Uses the metastore item factory's list cache tags (node_list:data) —
   * matching DKAN's own MetastoreApiResponse — so any dataset/distribution
   * create/update/delete invalidates the cached resource.
   *
   * @return string[]
   *   The metastore list cache tags.
   */
  protected function dataCacheTags(): array {
    return $this->metastoreItemFactory::getCacheTags();
  }

  /**
   * {@inheritdoc}
   *
   * Reads are open to any client that can reach the server, consistent with the
   * read tools (which require only `access mcp server`).
   */
  public function checkAccess(string $uri, AccountInterface $account): AccessResultInterface {
    if (!in_array($uri, array_column($this->getResources(), 'uri'), TRUE)) {
      return AccessResult::forbidden();
    }
    return AccessResult::allowedIfHasPermission($account, 'access mcp server')
      ->cachePerPermissions();
  }

}
