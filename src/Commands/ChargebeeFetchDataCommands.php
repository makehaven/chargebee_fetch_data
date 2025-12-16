<?php

namespace Drupal\chargebee_fetch_data\Commands;

use Drupal\chargebee_fetch_data\Form\ChargebeeFetchDataForm;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Chargebee data synchronization.
 */
class ChargebeeFetchDataCommands extends DrushCommands {

  /**
   * Sync Chargebee plans and payment amounts for members.
   *
   * @command chargebee:sync-plans
   * @option uid Process a single user ID.
   * @option start-uid Begin processing users with UID greater than or equal to this value.
   * @option delay Delay (in seconds) between processed users.
   * @option create-revision Create revisions when saving users and profiles.
   * @option detailed Enable detailed logging for each processed user.
   * @usage drush chargebee:sync-plans --start-uid=1000
   *   Sync all Chargebee-linked members starting with UID 1000.
   */
  public function syncPlans(array $options = [
    'uid' => NULL,
    'start-uid' => NULL,
    'delay' => 0,
    'create-revision' => FALSE,
    'detailed' => FALSE,
  ]) {
    $uid = $options['uid'] ?? NULL;
    $start_uid = $options['start-uid'] ?? NULL;
    $delay = (int) ($options['delay'] ?? 0);
    $create_revision = !empty($options['create-revision']);
    $detailed = !empty($options['detailed']);

    if (!empty($uid)) {
      $user_ids = [(int) $uid];
    }
    else {
      $query = \Drupal::entityQuery('user')
        ->accessCheck(FALSE)
        ->condition('field_user_chargebee_id', NULL, 'IS NOT NULL');

      if (!empty($start_uid)) {
        $query->condition('uid', (int) $start_uid, '>=');
      }

      $user_ids = $query->execute();
    }

    $total = count($user_ids);
    if ($total === 0) {
      $this->logger()->notice('No users found to sync.');
      return;
    }

    $chunks = array_chunk($user_ids, 50);
    $processed = 0;

    foreach ($chunks as $chunk) {
      $context = [];
      ChargebeeFetchDataForm::processUserBatch($chunk, $total, $delay, $detailed, $create_revision, $context);
      $processed += count($chunk);
      $this->logger()->notice('Processed @processed of @total users.', [
        '@processed' => $processed,
        '@total' => $total,
      ]);
    }
  }

}
