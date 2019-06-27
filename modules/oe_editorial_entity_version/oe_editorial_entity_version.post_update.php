<?php

/**
 * @file
 * Editorial Entity Version post update functions.
 */

declare(strict_types = 1);

/**
 * Configures the entity version action rules for corporate workflow.
 */
function oe_editorial_entity_version_post_update_configure_workflow(): void {
  // Apply entity version number rules for the corporate workflow.
  $corporate_workflow = \Drupal::configFactory()->getEditable('workflows.workflow.oe_corporate_workflow');
  $corporate_workflow->set('third_party_settings', [
    'entity_version_workflows' => [
      'create_new_draft' => [
        'minor' => 'increase',
      ],
      'needs_review_to_draft' => [
        'minor' => 'increase',
      ],
      'request_validation_to_draft' => [
        'minor' => 'increase',
      ],
      'validated_to_draft' => [
        'minor' => 'increase',
      ],
      'published_to_draft' => [
        'minor' => 'increase',
      ],
      'archived_to_draft' => [
        'minor' => 'increase',
      ],
      'request_validation_to_validated' => [
        'major' => 'increase',
        'minor' => 'reset',
      ],
    ],
  ])->save();

  // Get the bundles the workflow is associated with.
  $bundles = $corporate_workflow->get('type_settings.entity_types.node');
  if (!$bundles) {
    return;
  }
  $default_values = [
    'major' => 0,
    'minor' => 1,
    'patch' => 0,
  ];
  \Drupal::service('entity_version.entity_version_installer')
    ->addEntityVersionFieldToBundles($bundles, $default_values);
}
