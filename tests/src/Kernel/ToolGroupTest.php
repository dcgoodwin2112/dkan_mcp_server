<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\dkan_mcp_server\Form\McpSettingsForm;
use Drupal\dkan_mcp_server\Plugin\Tool\GroupedToolInterface;
use Drupal\dkan_mcp_server\Plugin\Tool\ToolGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies the tool-group taxonomy and the settings form against real plugins.
 *
 * The group taxonomy is the contract the operational enable/disable surface
 * relies on: every discovered tool must resolve to exactly one known group, and
 * the settings form must translate "enabled" checkboxes into the stored
 * disabled_groups list. Both are this module's own logic, not framework
 * behavior.
 *
 * @group dkan_mcp_server
 */
#[RunTestsInSeparateProcesses]
class ToolGroupTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Mirrors ResourceDiscoveryTest: every module referenced while the container
   * builds (tool plugin DI) is listed explicitly.
   */
  protected static $modules = [
    'node',
    'user',
    'dkan_common',
    'dkan_metastore',
    'dkan_datastore',
    'dkan_harvest',
    'search_api',
    'dkan_metastore_search',
    'dkan_query_tools',
    'mcp_server',
    'dkan_mcp_server',
  ];

  /**
   * Representative tool id => expected group (incl. the harvest/write split).
   */
  private const EXPECTED = [
    'list_datasets' => ToolGroup::METASTORE,
    'query_datastore' => ToolGroup::DATASTORE,
    'search_datasets' => ToolGroup::SEARCH,
    'resolve_resource' => ToolGroup::RESOURCE,
    'get_site_status' => ToolGroup::STATUS,
    'update_dataset' => ToolGroup::WRITE,
    // State-changing, but a harvest subsystem tool — NOT in the write group.
    'run_harvest' => ToolGroup::HARVEST,
  ];

  /**
   * Every discovered tool resolves to exactly one valid group.
   */
  public function testEveryToolHasValidGroup(): void {
    $manager = $this->container->get('plugin.manager.mcp_server.tool');
    $valid = ToolGroup::ids();

    foreach (array_keys($manager->getDefinitions()) as $id) {
      $plugin = $manager->createInstance($id);
      $this->assertInstanceOf(
        GroupedToolInterface::class,
        $plugin,
        "Tool '$id' must declare a group (GroupedToolInterface).",
      );
      $this->assertContains(
        $plugin->toolGroup(),
        $valid,
        "Tool '$id' has an unknown group '{$plugin->toolGroup()}'.",
      );
    }
  }

  /**
   * Spot-check the taxonomy, including the harvest-vs-write distinction.
   *
   * Disabling the write group must not switch off harvest state-changers
   * (run_harvest is a harvest tool) — that is intentional subsystem gating, and
   * mutation control belongs to the "* via mcp" permissions.
   */
  public function testRepresentativeGroupsAndHarvestWriteSplit(): void {
    $manager = $this->container->get('plugin.manager.mcp_server.tool');
    foreach (self::EXPECTED as $id => $group) {
      $this->assertSame($group, $manager->createInstance($id)->toolGroup());
    }
    $this->assertNotSame(
      ToolGroup::WRITE,
      $manager->createInstance('run_harvest')->toolGroup(),
      'run_harvest is a harvest tool; disabling write must not gate it.',
    );
  }

  /**
   * The settings form stores the unchecked groups as disabled_groups.
   */
  public function testSettingsFormStoresDisabledGroups(): void {
    $this->installConfig(['dkan_mcp_server']);

    // Enable every group except write (checked checkboxes return id => id).
    $enabled = array_diff(ToolGroup::ids(), [ToolGroup::WRITE]);
    $form_state = new FormState();
    $form_state->setValues(['enabled_groups' => array_combine($enabled, $enabled)]);
    $this->container->get('form_builder')->submitForm(McpSettingsForm::class, $form_state);

    $this->assertSame(
      [ToolGroup::WRITE],
      $this->config('dkan_mcp_server.settings')->get('disabled_groups'),
    );
  }

}
