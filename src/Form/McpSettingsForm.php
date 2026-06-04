<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dkan_mcp_server\Plugin\Tool\ToolGroup;

/**
 * Operational settings for the DKAN MCP server.
 *
 * Lets an operator switch whole tool groups (DKAN subsystems) on or off. This
 * is not authorization — write access stays governed by the "* via mcp"
 * permissions and ToolAccessSubscriber enforces both independently.
 */
final class McpSettingsForm extends ConfigFormBase {

  private const SETTINGS = 'dkan_mcp_server.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dkan_mcp_server_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $disabled = (array) ($this->config(self::SETTINGS)->get('disabled_groups') ?? []);

    $options = [];
    foreach (ToolGroup::definitions() as $id => $info) {
      $options[$id] = $this->t('@label — @description', [
        '@label' => $info['label'],
        '@description' => $info['description'],
      ]);
    }

    $form['enabled_groups'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled tool groups'),
      '#description' => $this->t('Unchecked groups are hidden from tools/list and rejected on tools/call, for every caller. This is operational gating, not authorization — write access stays governed by the per-operation "via mcp" permissions. HTTP auth and CORS are configured elsewhere (see the module README).'),
      '#options' => $options,
      '#default_value' => array_values(array_diff(ToolGroup::ids(), $disabled)),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $enabled = array_values(array_filter($form_state->getValue('enabled_groups')));
    $disabled = array_values(array_diff(ToolGroup::ids(), $enabled));
    $this->config(self::SETTINGS)->set('disabled_groups', $disabled)->save();
    parent::submitForm($form, $form_state);
  }

}
