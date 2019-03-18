<?php

namespace Drupal\entityqueue;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Defines a class that builds a listing of entity subqueues.
 */
class EntitySubqueueListBuilder extends EntityListBuilder {

  /**
   * The ID of the entity queue for which to list all subqueues.
   *
   * @var \Drupal\entityqueue\Entity\EntityQueue
   */
  protected $queueId;

  /**
   * Sets the entity queue ID.
   *
   * @param string $queue_id
   *   The entity queue ID.
   *
   * @return $this
   */
  public function setQueueId($queue_id) {
    $this->queueId = $queue_id;

    return $this;
  }

  /**
   * Loads entity IDs using a pager sorted by the entity id and optionally
   * filtered by bundle.
   *
   * @return array
   *   An array of entity IDs.
   */
  protected function getEntityIds() {
    $query = $this->getStorage()->getQuery()
      ->sort($this->entityType->getKey('id'));

    // Only add the pager if a limit is specified.
    if ($this->limit) {
      $query->pager($this->limit);
    }

    if ($this->queueId) {
      $query->condition($this->entityType->getKey('bundle'), $this->queueId);
    }

    return $query->execute();
  }


  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Subqueue');
    $header['items'] = $this->t('Items');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row['label'] = $entity->label();
    $row['items'] = $this->t('@count items', ['@count' => count($entity->items)]);

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity) {
    /** @var \Drupal\entityqueue\EntityQueueInterface $queue */
    $queue = $entity->getQueue();

    $operations = [];
    if ($queue->access('update') && $queue->hasLinkTemplate('edit-form')) {
      $operations['configure'] = [
        'title' => $this->t('Configure'),
        'weight' => 10,
        'url' => $this->ensureDestination($queue->toUrl('edit-form')),
      ];
    }
    if ($queue->access('delete') && $queue->hasLinkTemplate('delete-form')) {
      $operations['delete'] = [
        'title' => $this->t('Delete'),
        'weight' => 100,
        'url' => $this->ensureDestination($queue->toUrl('delete-form')),
      ];
    }

    if (!$queue->status() && $queue->hasLinkTemplate('enable')) {
      $operations['enable'] = [
        'title' => t('Enable'),
        'weight' => -10,
        'url' => $this->ensureDestination($queue->toUrl('enable')),
      ];
    }
    elseif ($queue->hasLinkTemplate('disable')) {
      $operations['disable'] = [
        'title' => t('Disable'),
        'weight' => 40,
        'url' => $queue->toUrl('disable'),
      ];
    }
    // Add AJAX functionality to enable/disable operations.
    foreach (['enable', 'disable'] as $op) {
      if (isset($operations[$op])) {
        $operations[$op]['url'] = $queue->toUrl($op);
        // Enable and disable operations should use AJAX.
        $operations[$op]['attributes']['class'][] = 'use-ajax';
      }
    }

    // Allow queue handlers to add their own operations.
    $operations += $queue->getHandlerPlugin()->getQueueListBuilderOperations();

    // We provide queue operations on the subqueue list builder, so we need to
    // fire the alter hooks for the queue as well.
    $operations += $this->moduleHandler()->invokeAll('entity_operation', [$queue]);
    $this->moduleHandler->alter('entity_operation', $operations, $queue);
    uasort($operations, '\Drupal\Component\Utility\SortArray::sortByWeightElement');

    return $operations;
  }

}
