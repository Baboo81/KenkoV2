<?php

namespace Drupal\block\Hook;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\language\ConfigurableLanguageInterface;
use Drupal\system\Entity\Menu;
use Drupal\block\Entity\Block;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for block.
 */
class BlockHooks {

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    switch ($route_name) {
      case 'help.page.block':
        $block_content = \Drupal::moduleHandler()->moduleExists('block_content') ? Url::fromRoute('help.page', ['name' => 'block_content'])->toString() : '#';
        $output = '';
        $output .= '<h2>' . t('About') . '</h2>';
        $output .= '<p>' . t('The Block module allows you to place blocks in regions of your installed themes, and configure block settings. For more information, see the <a href=":blocks-documentation">online documentation for the Block module</a>.', [':blocks-documentation' => 'https://www.drupal.org/documentation/modules/block/']) . '</p>';
        $output .= '<h2>' . t('Uses') . '</h2>';
        $output .= '<dl>';
        $output .= '<dt>' . t('Placing and moving blocks') . '</dt>';
        $output .= '<dd>' . t('You can place a new block in a region by selecting <em>Place block</em> on the <a href=":blocks">Block layout page</a>. Once a block is placed, it can be moved to a different region by drag-and-drop or by using the <em>Region</em> drop-down list, and then clicking <em>Save blocks</em>.', [':blocks' => Url::fromRoute('block.admin_display')->toString()]) . '</dd>';
        $output .= '<dt>' . t('Toggling between different themes') . '</dt>';
        $output .= '<dd>' . t('Blocks are placed and configured specifically for each theme. The Block layout page opens with the default theme, but you can toggle to other installed themes.') . '</dd>';
        $output .= '<dt>' . t('Demonstrating block regions for a theme') . '</dt>';
        $output .= '<dd>' . t('You can see where the regions are for the current theme by clicking the <em>Demonstrate block regions</em> link on the <a href=":blocks">Block layout page</a>. Regions are specific to each theme.', [':blocks' => Url::fromRoute('block.admin_display')->toString()]) . '</dd>';
        $output .= '<dt>' . t('Configuring block settings') . '</dt>';
        $output .= '<dd>' . t('To change the settings of an individual block click on the <em>Configure</em> link on the <a href=":blocks">Block layout page</a>. The available options vary depending on the module that provides the block. For all blocks you can change the block title and toggle whether to display it.', [':blocks' => Url::fromRoute('block.admin_display')->toString()]) . '</dd>';
        $output .= '<dt>' . t('Controlling visibility') . '</dt>';
        $output .= '<dd>' . t('You can control the visibility of a block by restricting it to specific pages, content types, and/or roles by setting the appropriate options under <em>Visibility settings</em> of the block configuration.') . '</dd>';
        $output .= '<dt>' . t('Adding content blocks') . '</dt>';
        $output .= '<dd>' . t('You can add content blocks, if the <em>Block Content</em> module is installed. For more information, see the <a href=":blockcontent-help">Block Content help page</a>.', [':blockcontent-help' => $block_content]) . '</dd>';
        $output .= '</dl>';
        return $output;
    }
    if ($route_name == 'block.admin_display' || $route_name == 'block.admin_display_theme') {
      $demo_theme = $route_match->getParameter('theme') ?: \Drupal::config('system.theme')->get('default');
      $themes = \Drupal::service('theme_handler')->listInfo();
      $output = '<p>' . t('Block placement is specific to each theme on your site. Changes will not be saved until you click <em>Save blocks</em> at the bottom of the page.') . '</p>';
      $output .= '<p>' . Link::fromTextAndUrl(t('Demonstrate block regions (@theme)', ['@theme' => $themes[$demo_theme]->info['name']]), Url::fromRoute('block.admin_demo', ['theme' => $demo_theme]))->toString() . '</p>';
      return $output;
    }
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme() : array {
    return ['block' => ['render element' => 'elements']];
  }

  /**
   * Implements hook_page_top().
   */
  #[Hook('page_top')]
  public function pageTop(array &$page_top): void {
    if (\Drupal::routeMatch()->getRouteName() === 'block.admin_demo') {
      $theme = \Drupal::theme()->getActiveTheme()->getName();
      $page_top['backlink'] = [
        '#type' => 'link',
        '#title' => t('Exit block region demonstration'),
        '#options' => [
          'attributes' => [
            'class' => [
              'block-demo-backlink',
            ],
          ],
        ],
        '#weight' => -10,
      ];
      if (\Drupal::config('system.theme')->get('default') == $theme) {
        $page_top['backlink']['#url'] = Url::fromRoute('block.admin_display');
      }
      else {
        $page_top['backlink']['#url'] = Url::fromRoute('block.admin_display_theme', ['theme' => $theme]);
      }
    }
  }

  /**
   * Implements hook_modules_installed().
   *
   * @see block_themes_installed()
   */
  #[Hook('modules_installed')]
  public function modulesInstalled($modules) {
    // block_themes_installed() does not call block_theme_initialize() during site
    // installation because block configuration can be optional or provided by the
    // profile. Now, when the profile is installed, this configuration exists,
    // call block_theme_initialize() for all installed themes.
    $profile = \Drupal::installProfile();
    if (in_array($profile, $modules, TRUE)) {
      foreach (\Drupal::service('theme_handler')->listInfo() as $theme => $data) {
        block_theme_initialize($theme);
      }
    }
  }

  /**
   * Implements hook_rebuild().
   */
  #[Hook('rebuild')]
  public function rebuild() {
    foreach (\Drupal::service('theme_handler')->listInfo() as $theme => $data) {
      if ($data->status) {
        $regions = system_region_list($theme);
        /** @var \Drupal\block\BlockInterface[] $blocks */
        $blocks = \Drupal::entityTypeManager()->getStorage('block')->loadByProperties(['theme' => $theme]);
        foreach ($blocks as $block_id => $block) {
          // Disable blocks in invalid regions.
          if (!isset($regions[$block->getRegion()])) {
            if ($block->status()) {
              \Drupal::messenger()->addWarning(t('The block %info was assigned to the invalid region %region and has been disabled.', ['%info' => $block_id, '%region' => $block->getRegion()]));
            }
            $block->setRegion(system_default_region($theme))->disable()->save();
          }
        }
      }
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for user_role entities.
   *
   * Removes deleted role from blocks that use it.
   */
  #[Hook('user_role_delete')]
  public function userRoleDelete($role) {
    foreach (Block::loadMultiple() as $block) {
      /** @var \Drupal\block\BlockInterface $block */
      $visibility = $block->getVisibility();
      if (isset($visibility['user_role']['roles'][$role->id()])) {
        unset($visibility['user_role']['roles'][$role->id()]);
        $block->setVisibilityConfig('user_role', $visibility['user_role']);
        $block->save();
      }
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for menu entities.
   */
  #[Hook('menu_delete')]
  public function menuDelete(Menu $menu) {
    if (!$menu->isSyncing()) {
      foreach (Block::loadMultiple() as $block) {
        if ($block->getPluginId() == 'system_menu_block:' . $menu->id()) {
          $block->delete();
        }
      }
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for 'configurable_language'.
   *
   * Delete the potential block visibility settings of the deleted language.
   */
  #[Hook('configurable_language_delete')]
  public function configurableLanguageDelete(ConfigurableLanguageInterface $language) {
    // Remove the block visibility settings for the deleted language.
    foreach (Block::loadMultiple() as $block) {
      /** @var \Drupal\block\BlockInterface $block */
      $visibility = $block->getVisibility();
      if (isset($visibility['language']['langcodes'][$language->id()])) {
        unset($visibility['language']['langcodes'][$language->id()]);
        $block->setVisibilityConfig('language', $visibility['language']);
        $block->save();
      }
    }
  }

  /**
   * Implements hook_block_build_BASE_BLOCK_ID_alter().
   */
  #[Hook('block_build_local_actions_block_alter')]
  public function blockBuildLocalActionsBlockAlter(array &$build, BlockPluginInterface $block): void {
    $build['#lazy_builder_preview'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'invisible',
        ],
      ],
      'actions' => [
        '#theme' => 'menu_local_action',
        '#link' => [
          'title' => t('Add'),
          'url' => Url::fromUserInput('#'),
        ],
      ],
    ];
  }

}