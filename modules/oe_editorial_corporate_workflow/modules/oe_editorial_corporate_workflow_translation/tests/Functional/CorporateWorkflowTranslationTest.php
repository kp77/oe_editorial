<?php

declare(strict_types = 1);

namespace Drupal\Tests\oe_editorial_corporate_workflow_translation\Functional;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the translation revision capability.
 *
 * Using the corporate editorial workflow, translations need to be saved onto
 * the latest revision of the entity's major version. In other words, if the
 * translation is started when the entity is in validated state (the minimum),
 * and the entity gets published before the translation comes back, the latter
 * should be saved on the published revision. But not on any future drafts
 * which create new minor versions.
 */
class CorporateWorkflowTranslationRevisionTest extends BrowserTestBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'tmgmt',
    'tmgmt_local',
    'tmgmt_content',
    'node',
    'toolbar',
    'content_translation',
    'user',
    'field',
    'text',
    'options',
    'oe_editorial_workflow_demo',
    'oe_translation',
    'oe_editorial_corporate_workflow_translation',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $this->entityTypeManager = $this->container->get('entity_type.manager');

    $this->entityTypeManager->getStorage('node_type')->create([
      'name' => 'Page',
      'type' => 'page',
    ])->save();

    $this->container->get('content_translation.manager')->setEnabled('node', 'page', TRUE);
    $this->container->get('oe_editorial_corporate_workflow.workflow_installer')->installWorkflow('page');
    $default_values = [
      'major' => 0,
      'minor' => 1,
      'patch' => 0,
    ];
    $this->container->get('entity_version.entity_version_installer')->install('node', ['page'], $default_values);
    $this->container->get('router.builder')->rebuild();

    /** @var \Drupal\user\RoleInterface $role */
    $role = $this->entityTypeManager->getStorage('user_role')->load('oe_translator');
    $this->user = $this->drupalCreateUser($role->getPermissions());

    $this->drupalLogin($this->user);
  }

  /**
   * Tests that users can only create translations of validated content.
   */
  public function testTranslationAccess(): void {
    /** @var \Drupal\node\NodeStorageInterface $node_storage */
    $node_storage = $this->entityTypeManager->getStorage('node');

    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->create([
      'type' => 'page',
      'title' => 'My node',
      'moderation_state' => 'draft',
    ]);
    $node->save();

    // Ensure we can only create translation task if the node is validated or
    // published.
    $local_task_creation_url = Url::fromRoute('oe_translation.permission_translator.create_local_task', [
      'entity' => $node->id(),
      'source' => 'en',
      'target' => 'fr',
      'entity_type' => 'node',
    ]);
    $this->assertFalse($local_task_creation_url->access($this->user));

    $node->set('moderation_state', 'needs_review');
    $node->save();
    $this->assertFalse($local_task_creation_url->access($this->user));

    $node->set('moderation_state', 'request_validation');
    $node->save();
    $this->assertFalse($local_task_creation_url->access($this->user));

    // Navigate to the translation overview page and assert we don't have a link
    // to start a translation.
    $this->drupalGet($node->toUrl('drupal:content-translation-overview'));
    // No link for regular Drupal core translation creation.
    $this->assertSession()->linkNotExists('Add');
    // No link for the local translation.
    $this->assertSession()->linkNotExists('Translate locally');

    $node->set('moderation_state', 'validated');
    $node->save();
    $this->assertTrue($local_task_creation_url->access($this->user));

    $this->drupalGet($node->toUrl('drupal:content-translation-overview'));
    $this->assertSession()->linkNotExists('Add');
    $this->assertSession()->linkExists('Translate locally');

    $node->set('moderation_state', 'published');
    $node->save();
    $this->assertTrue($local_task_creation_url->access($this->user));

    $this->drupalGet($node->toUrl('drupal:content-translation-overview'));
    $this->assertSession()->linkNotExists('Add');
    $this->assertSession()->linkExists('Translate locally');

    // If we start a new draft, then we block access to creating a new
    // translation until the content is validated again.
    $node->set('moderation_state', 'draft');
    $node->save();
    $this->assertFalse($local_task_creation_url->access($this->user));
  }

  /**
   * Tests the creation of new translations using the workflow.
   */
  public function testModeratedTranslationCreation(): void {
    /** @var \Drupal\node\NodeStorageInterface $node_storage */
    $node_storage = $this->entityTypeManager->getStorage('node');

    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->create([
      'type' => 'page',
      'title' => 'My node',
      'moderation_state' => 'draft',
    ]);
    $node->save();
    $node = $this->moderateNode($node, 'validated');

    // At this point, we expect to have 4 revisions of the node.
    $revision_ids = $node_storage->revisionIds($node);
    $this->assertCount(4, $revision_ids);

    // Create a local translation task.
    $this->drupalGet(Url::fromRoute('oe_translation.permission_translator.create_local_task', [
      'entity' => $node->id(),
      'source' => 'en',
      'target' => 'fr',
      'entity_type' => 'node',
    ]));
    $this->assertSession()->elementContains('css', '#edit-title0value-translation', 'My node');

    // At this point the job item and local task have been created for the
    // translation and they should reference the last revision of the node, that
    // of the validated revision.
    $job_items = $this->entityTypeManager->getStorage('tmgmt_job_item')->loadByProperties(['item_rid' => $node->getRevisionId()]);
    $this->assertCount(1, $job_items);

    // Publish the node before finalizing the translation.
    $node = $this->moderateNode($node, 'published');
    $revision_ids = $node_storage->revisionIds($node);
    $this->assertCount(5, $revision_ids);

    // Finalize the translation and check that the translation got saved onto
    // the published version rather than the validated one where it actually
    // got started.
    $values = [
      'title|0|value[translation]' => 'My node FR',
    ];
    // It should be the first local task item created so we use the ID 1.
    $url = Url::fromRoute('entity.tmgmt_local_task_item.canonical', ['tmgmt_local_task_item' => 1]);
    $this->drupalPostForm($url, $values, t('Save and complete translation'));
    $node_storage->resetCache();
    /** @var \Drupal\node\NodeInterface[] $revisions */
    $revisions = $node_storage->loadMultipleRevisions($revision_ids);
    foreach ($revisions as $revision) {
      if ($revision->get('moderation_state')->value === 'published') {
        $this->assertTrue($revision->hasTranslation('fr'), 'The published node does not have a translation');
        continue;
      }

      $this->assertFalse($revision->hasTranslation('fr'), sprintf('The %s node has a translation and it shouldn\'t', $revision->get('moderation_state')->value));
    }

    // Start a new draft from the latest published node and validate it.
    $node = $node_storage->load($node->id());
    $node->set('title', 'My node 2');
    $node->set('moderation_state', 'draft');
    $node->save();
    $revision_ids = $node_storage->revisionIds($node);
    $this->assertCount(6, $revision_ids);
    $node = $this->moderateNode($node, 'validated');
    $revision_ids = $node_storage->revisionIds($node);
    $this->assertCount(9, $revision_ids);
    // Assert that the latest revision that was just validated is the correct
    // version and inherited the translation from the previous version.
    /** @var \Drupal\node\NodeInterface $validated_node */
    $validated_node = $node_storage->loadRevision($node_storage->getLatestRevisionId($node->id()));
    $this->assertEquals('2', $validated_node->get('version')->major);
    $this->assertEquals('0', $validated_node->get('version')->minor);
    $this->assertEquals('My node FR', $validated_node->getTranslation('fr')->label());

    // Create a new local translation task.
    $this->drupalGet(Url::fromRoute('oe_translation.permission_translator.create_local_task', [
      'entity' => $node->id(),
      'source' => 'en',
      'target' => 'fr',
      'entity_type' => 'node',
    ]));

    // The default translation value comes from the previous version
    // translation.
    $this->assertSession()->elementContains('css', '#edit-title0value-translation', 'My node FR');

    // Assert that the new job item got created and it has the revision ID of
    // the validated node.
    $job_items = $this->entityTypeManager->getStorage('tmgmt_job_item')->loadByProperties(['item_rid' => $validated_node->getRevisionId()]);
    $this->assertCount(1, $job_items);

    // Publish the node before finalizing the translation.
    $this->moderateNode($node, 'published');
    $revision_ids = $node_storage->revisionIds($node);
    $this->assertCount(10, $revision_ids);

    // Finalize the translation and check that the translation got saved onto
    // the published version rather than the validated one where it actually
    // got started.
    $values = [
      'title|0|value[translation]' => 'My node FR 2',
    ];
    // It should be the second local task item created so we use the ID 2.
    $url = Url::fromRoute('entity.tmgmt_local_task_item.canonical', ['tmgmt_local_task_item' => 2]);
    $this->drupalPostForm($url, $values, t('Save and complete translation'));
    $node_storage->resetCache();
    $validated_node = $node_storage->loadRevision($validated_node->getRevisionId());
    // The second validated revision should have the old FR translation.
    $this->assertEquals('My node FR', $validated_node->getTranslation('fr')->label());
    $node = $node_storage->load($node->id());
    // The new (current) published revision should have the new FR translation.
    $this->assertEquals('My node FR 2', $node->getTranslation('fr')->label());

    // The previous published revisions have the old FR translation.
    $revision_ids = $node_storage->revisionIds($node);
    /** @var \Drupal\node\NodeInterface[] $revisions */
    $revisions = $node_storage->loadMultipleRevisions($revision_ids);
    foreach ($revisions as $revision) {
      if ($revision->isPublished() && (int) $revision->get('version')->major === 1) {
        $this->assertEquals('My node FR', $revision->getTranslation('fr')->label());
        break;
      }
    }
  }

  /**
   * Tests that revision translations are carried over from latest revision.
   *
   * The test focuses on ensuring that when a new revision is created by the
   * storage based on another one, the new one inherits the translated values
   * from the one its based on and NOT from the latest default revision as core
   * would have it.
   *
   * @see oe_editorial_corporate_workflow_translation_node_revision_create()
   */
  public function testTranslationRevisions(): void {
    /** @var \Drupal\node\NodeStorageInterface $node_storage */
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Create a validated node directly and translate it.
    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->create([
      'type' => 'page',
      'title' => 'My node',
      'moderation_state' => 'draft',
    ]);
    $node->save();
    $node = $this->moderateNode($node, 'validated');
    $this->drupalGet(Url::fromRoute('oe_translation.permission_translator.create_local_task', [
      'entity' => $node->id(),
      'source' => 'en',
      'target' => 'fr',
      'entity_type' => 'node',
    ]));
    $this->assertSession()->elementContains('css', '#edit-title0value-translation', 'My node');
    $values = [
      'title|0|value[translation]' => 'My node FR',
    ];
    // It should be the first local task item created so we use the ID 1.
    $url = Url::fromRoute('entity.tmgmt_local_task_item.canonical', ['tmgmt_local_task_item' => 1]);
    $this->drupalPostForm($url, $values, t('Save and complete translation'));

    $node = $node_storage->load($node->id());
    // Publish the node and check that the translation is available in the
    // published revision.
    $node = $this->moderateNode($node, 'published');
    $revision_ids = $node_storage->revisionIds($node);

    $node_storage->resetCache();
    /** @var \Drupal\node\NodeInterface[] $revisions */
    $revisions = $node_storage->loadMultipleRevisions($revision_ids);
    // Since we translated the node while it was validated, both revisions
    // should contain the same translation.
    foreach ($revisions as $revision) {
      if ($revision->isPublished() || $revision->get('moderation_state')->value === 'validated') {
        $this->assertTrue($revision->hasTranslation('fr'), 'The revision does not have a translation');
        $this->assertEquals('My node FR', $revision->getTranslation('fr')->label(), 'The revision does not have a correct translation');
      }
    }

    // Start a new draft from the latest published node and validate it.
    $node->set('title', 'My node 2');
    $node->set('moderation_state', 'draft');
    $node->save();

    $node = $this->moderateNode($node, 'validated');
    $this->drupalGet(Url::fromRoute('oe_translation.permission_translator.create_local_task', [
      'entity' => $node->id(),
      'source' => 'en',
      'target' => 'fr',
      'entity_type' => 'node',
    ]));

    // The default translation value comes from the previous version
    // translation.
    $this->assertSession()->elementContains('css', '#edit-title0value-translation', 'My node FR');
    $values = [
      'title|0|value[translation]' => 'My node FR 2',
    ];
    // It should be the second local task item created so we use the ID 2.
    $url = Url::fromRoute('entity.tmgmt_local_task_item.canonical', ['tmgmt_local_task_item' => 2]);
    $this->drupalPostForm($url, $values, t('Save and complete translation'));

    // Publish the node and check that the published versions have the correct
    // translations.
    $node = $node_storage->load($node->id());
    $node = $this->moderateNode($node, 'published');
    $revision_ids = $node_storage->revisionIds($node);

    /** @var \Drupal\node\NodeInterface[] $revisions */
    $revisions = $node_storage->loadMultipleRevisions($revision_ids);
    foreach ($revisions as $revision) {
      if ($revision->isPublished() && (int) $revision->get('version')->major === 1) {
        $this->assertEquals('My node FR', $revision->getTranslation('fr')->label());
        continue;
      }

      if ($revision->isPublished() && (int) $revision->get('version')->major === 2) {
        $this->assertEquals('My node FR 2', $revision->getTranslation('fr')->label());
        continue;
      }
    }
  }

  /**
   * Sends the node through the moderation states to reach the target.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $target_state
   *   The target moderation state,.
   *
   * @return \Drupal\node\NodeInterface
   *   The latest node revision.
   */
  protected function moderateNode(NodeInterface $node, string $target_state): NodeInterface {
    $states = [
      'draft',
      'needs_review',
      'request_validation',
      'validated',
      'published',
    ];

    $current_state = $node->get('moderation_state')->value;
    if ($current_state === $target_state) {
      return $node;
    }

    $pos = array_search($current_state, $states);
    foreach (array_slice($states, $pos + 1) as $new_state) {
      $node = isset($revision) ? $revision : $node;
      $revision = $this->entityTypeManager->getStorage('node')->createRevision($node);
      $revision->set('moderation_state', $new_state);
      $revision->save();
      if ($new_state === $target_state) {
        return $revision;
      }
    }

    return isset($revision) ? $revision : $node;
  }

}
