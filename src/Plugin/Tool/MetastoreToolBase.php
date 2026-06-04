<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for read tools backed by the shared dkan_query_tools.metastore service.
 *
 * Concrete tools declare only their #[Tool] attribute and execute(); DI and
 * native enablement live here.
 */
abstract class MetastoreToolBase extends ToolPluginBase implements GroupedToolInterface {

  /**
   * {@inheritdoc}
   */
  public function toolGroup(): string {
    return ToolGroup::METASTORE;
  }

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    AccountProxyInterface $currentUser,
    protected MetastoreTools $metastore,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $currentUser);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('dkan_query_tools.metastore'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultConfiguration(): array {
    return ['enabled' => TRUE];
  }

}
