<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\dkan_query_tools\Tool\SearchTools;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for read tools backed by the shared dkan_query_tools.search service.
 */
abstract class SearchToolBase extends ToolPluginBase {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    AccountProxyInterface $currentUser,
    protected SearchTools $search,
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
      $container->get('dkan_query_tools.search'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultConfiguration(): array {
    return ['enabled' => TRUE];
  }

}
