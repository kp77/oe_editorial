<?php

declare(strict_types = 1);

namespace Drupal\Tests\oe_editorial_corporate_workflow_unpublish\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the unpublishing form for nodes.
 */
class CorporateWorkflowUnpublishTest extends BrowserTestBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\node\NodeStorageInterface
   */
  protected $nodeStorage;

  /**
   * The test node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;

  /**
   * The current user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  protected static $modules = [
    'user',
    'node',
    'field',
    'text',
    'system',
    'workflows',
    'content_moderation',
    'oe_editorial',
    'oe_editorial_corporate_workflow',
    'oe_editorial_corporate_workflow_unpublish',
    'oe_editorial_workflow_demo',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $entity_type_manager = $this->container->get('entity_type.manager');
    $this->nodeStorage = $entity_type_manager->getStorage('node');
    $this->node = $this->nodeStorage->create(
      [
        'type' => 'oe_workflow_demo',
        'title' => 'My node',
        'moderation_state' => 'draft',
      ]
    );
    $this->node->save();

    /** @var \Drupal\user\RoleInterface $role */
    $role = $entity_type_manager->getStorage('user_role')->load('oe_validator');
    $this->user = $this->drupalCreateUser($role->getPermissions());
    $this->drupalLogin($this->user);
  }

  /**
   * Tests access to the unpublishing form.
   */
  public function testUnpublishAccess(): void {

    $unpublish_url = Url::fromRoute('entity.node.unpublish', [
      'node' => $this->node->id(),
    ]);

    // Assert that we can't access the unpublish page
    // when the node is not published.
    $this->assertFalse($unpublish_url->access($this->user));

    $this->node->moderation_state->value = 'published';
    $this->node->save();

    // A user with permissions can access the unpublish page.
    $this->assertTrue($unpublish_url->access($this->user));

    // A user without permissions can not access the unpublish page.
    $this->drupalLogout();
    $this->drupalGet($unpublish_url);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests the unpublishing form.
   */
  public function testUnpublishForm(): void {
    // Publish the node so we can access the form.
    $this->node->moderation_state->value = 'published';
    $this->node->save();

    $unpublish_url = Url::fromRoute('entity.node.unpublish', [
      'node' => $this->node->id(),
    ]);
    $this->drupalGet($unpublish_url);
    // A cancel link is present.
    $this->assertSession()->linkExists('Cancel');
    $unpublish_state = $this->assertSession()->selectExists('Select the state to unpublish this node')->getValue();
    // Assert the unpublish button is present and using it unpublishes the node.
    $this->assertSession()->buttonExists('Unpublish')->press();
    $this->assertSession()->pageTextContains('The node My node has been unpublished.');
    $node = $this->nodeStorage->load($this->node->id());
    $this->assertEqual($node->moderation_state->value, $unpublish_state);
  }

}
