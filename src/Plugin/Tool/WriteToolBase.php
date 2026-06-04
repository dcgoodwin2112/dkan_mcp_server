<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\dkan_mcp_server\Tools\WriteTools;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for write tools backed by the dkan_mcp_server.tools.write service.
 *
 * Concrete tools override checkAccess() with their fine-grained permission.
 */
abstract class WriteToolBase extends ToolPluginBase implements GroupedToolInterface {

  /**
   * {@inheritdoc}
   */
  public function toolGroup(): string {
    return ToolGroup::WRITE;
  }

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    AccountProxyInterface $currentUser,
    protected WriteTools $writeTools,
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
      $container->get('dkan_mcp_server.tools.write'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultConfiguration(): array {
    return ['enabled' => TRUE];
  }

}
