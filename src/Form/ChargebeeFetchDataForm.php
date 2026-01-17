<?php

namespace Drupal\chargebee_fetch_data\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use GuzzleHttp\Exception\RequestException;

/**
 * Provides a form to fetch Chargebee data and update user fields in batch.
 */
class ChargebeeFetchDataForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'chargebee_fetch_data_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => $this->t('<p>This process fetches active subscription data from Chargebee and updates user fields as follows:</p>
        <ul>
          <li><strong>field_user_chargebee_plan</strong>: Updated with the plan ID from Chargebee.</li>
          <li><strong>field_member_payment_monthly</strong>: Updated with the monthly payment amount (converted from cents) from Chargebee.
            If this field is not present on the user account, the system will attempt to update it on the main profile.</li>
        </ul>
        <p>You may enter a specific User ID (UID) to test the process on one user. Otherwise, all users with a Chargebee customer ID and the "member" role will be processed.</p>
        <p>You can also optionally specify a <em>Start UID</em> to process only users with UID ≥ that value, and a <em>Delay</em> (in seconds) between processing each user.</p>'),
    ];
    $form['uid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User ID (optional)'),
      '#description' => $this->t('Enter a UID to test the process on one user. Leave empty to process all users with a Chargebee customer ID.'),
      '#default_value' => '',
    ];
    $form['start_uid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Start UID (optional)'),
      '#description' => $this->t('When processing all users, only process those with UID greater than or equal to this value. Leave empty to process all users.'),
      '#default_value' => '',
    ];
    $form['delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Delay between users (seconds, optional)'),
      '#description' => $this->t('Enter a delay (in whole seconds) to pause after processing each user. Fractional seconds are not supported.'),
      '#default_value' => 0,
      '#min' => 0,
    ];
    $form['create_revision'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create a new revision for each update'),
      '#description' => $this->t('If checked, a new revision of the user or profile will be created with each change.'),
      '#default_value' => FALSE,
    ];
    $form['detailed_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable detailed logging'),
      '#description' => $this->t('If checked, a log entry will be created for every user that is successfully updated.'),
      '#default_value' => FALSE,
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Fetch and Update Data'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid_input = trim($form_state->getValue('uid'));
    if (!empty($uid_input)) {
      $user_ids = [$uid_input];
      \Drupal::messenger()->addStatus($this->t('Processing only user with UID: @uid', ['@uid' => $uid_input]));
    }
    else {
      $query = \Drupal::entityQuery('user')
        ->accessCheck(FALSE)
        ->condition('field_user_chargebee_id', NULL, 'IS NOT NULL');
      $start_uid = trim($form_state->getValue('start_uid'));
      if (!empty($start_uid)) {
        $query->condition('uid', $start_uid, '>=');
        \Drupal::messenger()->addStatus($this->t('Processing only users with UID ≥ @uid', ['@uid' => $start_uid]));
      }
      $user_ids = $query->execute();
      \Drupal::messenger()->addStatus($this->t('Found @count users with a Chargebee customer ID.', ['@count' => count($user_ids)]));
    }
    $total_users = count($user_ids);
    $delay = (int) $form_state->getValue('delay');
    $detailed_logging = $form_state->getValue('detailed_logging');
    $create_revision = $form_state->getValue('create_revision');

    $batch_builder = new BatchBuilder();
    $batch_builder
      ->setTitle($this->t('Fetching and updating Chargebee data...'))
      ->setInitMessage($this->t('Initializing batch process.'))
      ->setProgressMessage($this->t('Processed @current out of @total users.'))
      ->setErrorMessage($this->t('An error occurred during the process.'));

    $chunks = array_chunk($user_ids, 50);
    foreach ($chunks as $chunk) {
      $batch_builder->addOperation([static::class, 'processUserBatch'], [$chunk, $total_users, $delay, $detailed_logging, $create_revision]);
    }

    $batch_builder->setFinishCallback([static::class, 'batchFinished']);
    batch_set($batch_builder->toArray());
    $response = batch_process();
    $form_state->setResponse($response);
  }

  /**
   * Batch operation callback.
   */
  public static function processUserBatch(array $user_ids, $total_users, $delay, $detailed_logging, $create_revision, array &$context) {
    if (!isset($context['sandbox']['processed'])) {
      $context['sandbox']['processed'] = 0;
    }

    $config = \Drupal::config('chargebee_portal.settings');
    $api_key = $config->get('live_api_key');
    $portal_url = rtrim($config->get('live_portal_url'), '/');
    $parsed = parse_url($portal_url);
    $base_url = (isset($parsed['host']) && isset($parsed['scheme'])) ? $parsed['scheme'] . '://' . $parsed['host'] : $portal_url;
    $subscriptions_endpoint = $base_url . '/api/v2/subscriptions';
    $detailed = (count($user_ids) == 1);
    
    if (!\Drupal::hasService('chargebee_status_sync.plan_manager')) {
      \Drupal::messenger()->addError(t('Service "chargebee_status_sync.plan_manager" not found. Please clear the cache and ensure the Chargebee Status Sync module is enabled.'));
      $context['finished'] = 1;
      return;
    }
    /** @var \Drupal\chargebee_status_sync\Service\PlanManager $plan_manager */
    $plan_manager = \Drupal::service('chargebee_status_sync.plan_manager');

    // Pre-load users and collect Chargebee Customer IDs.
    $users_to_process = [];
    $customer_ids = [];
    foreach ($user_ids as $uid) {
      $user = \Drupal\user\Entity\User::load($uid);
      if (!$user) continue;
      $users_to_process[$uid] = $user;
      if ($user->hasField('field_user_chargebee_id') && !$user->get('field_user_chargebee_id')->isEmpty()) {
        $val = trim($user->get('field_user_chargebee_id')->value);
        // Clean ID: remove 'was ' prefix and anything after ' --'.
        $val = preg_replace(['/^was\s+/i', '/\s+--.*$/'], '', $val);
        if (!empty($val)) {
          $customer_ids[] = $val;
        }
      }
    }

    if (empty($customer_ids)) {
      $context['sandbox']['processed'] += count($user_ids);
      return;
    }

    // Fetch subscriptions in one batch call for all customers in this chunk.
    $subscriptions_map = self::fetchSubscriptionsForCustomers($customer_ids, $api_key, $subscriptions_endpoint);

    foreach ($user_ids as $uid) {
      try {
        if (!isset($users_to_process[$uid])) continue;
        $user = $users_to_process[$uid];

        if ($detailed) {
          \Drupal::messenger()->addStatus(t('Processing user @uid.', ['@uid' => $uid]));
        }

        if ($user->hasField('field_user_chargebee_id') && !$user->get('field_user_chargebee_id')->isEmpty()) {
          $chargebee_customer_id = trim($user->get('field_user_chargebee_id')->value);
          // Clean ID: remove 'was ' prefix and anything after ' --'.
          $chargebee_customer_id = preg_replace(['/^was\s+/i', '/\s+--.*$/'], '', $chargebee_customer_id);
        } else {
          if ($detailed) {
            \Drupal::messenger()->addWarning(t('User @uid does not have a Chargebee customer ID.', ['@uid' => $uid]));
          }
          continue;
        }

        $subscription_data = $subscriptions_map[$chargebee_customer_id] ?? NULL;
        if ($subscription_data && isset($subscription_data['subscription'])) {
          $sub = $subscription_data['subscription'];
          $status = $sub['status'] ?? 'unknown';
          $plan_id = $sub['plan_id'] ?? NULL;
          $plan_amount_cents = $sub['plan_amount'] ?? NULL;
          $currency = $sub['currency_code'] ?? NULL;
          $cancelled_at = $sub['cancelled_at'] ?? NULL;
          $plan_term = NULL;

          if ($detailed) {
            \Drupal::messenger()->addStatus(t('Matched subscription for customer @cid: status=@status, plan_id=@pid', ['@cid' => $chargebee_customer_id, '@status' => $status, '@pid' => $plan_id]));
          }

          // Initialize save tracking
          $user_save_needed = FALSE;
          $user_updates_log = [];
          $profile_save_needed = FALSE;
          $profile_updates_log = [];

          // Load Main Profile
          $profile_storage = \Drupal::entityTypeManager()->getStorage('profile');
          $profiles = $profile_storage->loadByProperties(['uid' => $user->id(), 'type' => 'main']);
          $profile = reset($profiles) ?: NULL;

          $is_active_status = ($status == 'active' || $status == 'in_trial' || $status == 'future' || $status == 'non_renewing');
          $is_cancelled_status = ($status == 'cancelled');

          // COMMON LOGIC: Update Plan and Amount for both Active and Cancelled users
          if ($plan_id && $plan_amount_cents !== NULL) {
            $plan_amount = $plan_amount_cents / 100;

            // Ensure plan term exists
            $plan_term = $plan_manager->upsertPlan($plan_id, [
              'amount' => $plan_amount,
              'currency' => $currency,
              'provider' => 'chargebee',
            ]);

            // Sync Plan ID on User
            if ($user->hasField('field_user_chargebee_plan')) {
              if ($user->get('field_user_chargebee_plan')->value !== $plan_id) {
                $user->set('field_user_chargebee_plan', $plan_id);
                $user_save_needed = TRUE;
                $user_updates_log[] = 'plan ID';
              }
            }

            // Sync Monthly Payment Amount
            if ($profile && $profile->hasField('field_member_payment_monthly')) {
              if ((float) $profile->get('field_member_payment_monthly')->value !== (float) $plan_amount) {
                $profile->set('field_member_payment_monthly', $plan_amount);
                $profile_save_needed = TRUE;
                $profile_updates_log[] = 'monthly payment';
              }
            }

            // Sync Membership Type from Plan to Profile
            if ($plan_term && $profile && $plan_term->hasField('field_membership_type') && !$plan_term->get('field_membership_type')->isEmpty()) {
               $target_type_id = $plan_term->get('field_membership_type')->target_id;
               if ($profile->hasField('field_membership_type')) {
                   if ($profile->get('field_membership_type')->target_id != $target_type_id) {
                       $profile->set('field_membership_type', $target_type_id);
                       $profile_save_needed = TRUE;
                       $profile_updates_log[] = 'membership type';
                   }
               }
            }
          }

          // STATUS SPECIFIC LOGIC
          if ($is_active_status) {
            // Active users: Clear end date on PROFILE
            if ($profile && $profile->hasField('field_member_end_date') && !$profile->get('field_member_end_date')->isEmpty()) {
              $profile->set('field_member_end_date', NULL);
              $profile_save_needed = TRUE;
              $profile_updates_log[] = 'cleared end date';
            }

            // Active users: Ensure Member Role
            $member_role_id = \Drupal::config('chargebee_status_sync.settings')->get('chargebee_status_member_role');
            if ($member_role_id && !$user->hasRole($member_role_id)) {
                $user->addRole($member_role_id);
                $user_save_needed = TRUE;
                $user_updates_log[] = 'added member role';
            }
          } 
          elseif ($is_cancelled_status && $cancelled_at) {
            // Cancelled users: Set end date on PROFILE
            if ($profile && $profile->hasField('field_member_end_date')) {
              $formatted_date = gmdate('Y-m-d', $cancelled_at);
              $current_val = $profile->get('field_member_end_date')->value;
              if ($current_val !== $formatted_date) {
                $profile->set('field_member_end_date', $formatted_date);
                $profile_save_needed = TRUE;
                $profile_updates_log[] = 'end date set to ' . $formatted_date;
              }
            }

            // Cancelled users: Ensure Member Role is REMOVED
            $member_role_id = \Drupal::config('chargebee_status_sync.settings')->get('chargebee_status_member_role');
            if ($member_role_id && $user->hasRole($member_role_id)) {
                $user->removeRole($member_role_id);
                $user_save_needed = TRUE;
                $user_updates_log[] = 'removed member role';
            }
          }

          // SAVE PROFILE
          if ($profile_save_needed && $profile) {
            if ($create_revision) {
              $profile->setNewRevision(TRUE);
              $profile->setRevisionLogMessage('Updated Chargebee data via batch process: ' . implode(', ', $profile_updates_log));
            }
            $profile->save();
          }

          // SAVE USER
          if ($user_save_needed) {
            if ($create_revision) {
              $user->setNewRevision(TRUE);
              $user->setRevisionLogMessage('Updated Chargebee data via batch process: ' . implode(', ', $user_updates_log));
            }
            $user->save();
          }

        } else {
          $user_url = $user->toUrl()->setAbsolute()->toString();
          \Drupal::messenger()->addWarning(t('No subscription found for customer @cid (User: <a href="@url" target="_blank">@name (@uid)</a>).', [
            '@cid' => $chargebee_customer_id,
            '@url' => $user_url,
            '@name' => $user->getDisplayName(),
            '@uid' => $user->id(),
          ]));
        }

        if ($delay > 0) {
          sleep($delay);
        }
      } catch (\Exception $ex) {
        \Drupal::messenger()->addError(t('An unexpected error occurred for user @uid: @error', ['@uid' => $uid, '@error' => $ex->getMessage()]));
      }
      $context['sandbox']['processed']++;
    }
    $context['finished'] = 1;
  }

  /**
   * Helper function to fetch subscriptions for a list of customers.
   */
  protected static function fetchSubscriptionsForCustomers(array $customer_ids, $api_key, $subscriptions_endpoint) {
    $client = \Drupal::httpClient();
    $maxRetries = 4;
    
    // Construct the [in] filter using a JSON-like array string which Chargebee supports for many filters.
    // E.g. customer_id[in]=["id1","id2"]
    $id_filter = '[' . implode(',', array_map(fn($id) => '"' . addslashes($id) . '"', $customer_ids)) . ']';

    $map = [];
    $offset = NULL;

    do {
      $success = FALSE;
      for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        try {
          $query = [
            'customer_id[in]' => $id_filter,
            'limit' => 100, 
            'sort_by[desc]' => 'updated_at',
          ];
          if ($offset) {
            $query['offset'] = $offset;
          }

          $response = $client->get($subscriptions_endpoint, [
            'auth' => [$api_key, ''],
            'query' => $query,
          ]);
          
          $data = json_decode($response->getBody()->getContents(), TRUE);
          
          if (!empty($data['list'])) {
            foreach ($data['list'] as $item) {
              $cid = $item['subscription']['customer_id'] ?? NULL;
              // Because we sort by updated_at desc, the first time we see a customer ID, 
              // it's their most recent subscription state in this result set.
              // However, since we are paginating, a newer subscription might have appeared on a previous page.
              // So we only set it if NOT set.
              if ($cid && !isset($map[$cid])) {
                $map[$cid] = $item;
              }
            }
          }

          $offset = $data['next_offset'] ?? NULL;
          $success = TRUE;
          break; // Exit retry loop
        }
        catch (RequestException $e) {
          if ($e->hasResponse() && $e->getResponse()->getStatusCode() == 429) {
            $retryDelay = 5 * pow(2, $attempt);
            \Drupal::messenger()->addWarning(t('Rate limit reached during batch fetch, retrying in @delay seconds...', ['@delay' => $retryDelay]));
            sleep($retryDelay);
            continue;
          }
          else {
            \Drupal::messenger()->addError(t('Chargebee API batch request failed: @error', ['@error' => $e->getMessage()]));
            // If a hard error occurs, we might want to stop or return partial data. 
            // For now, let's break the pagination loop to avoid infinite loops on hard errors.
            $offset = NULL; 
            break;
          }
        }
      }
      if (!$success) {
        // If we failed all retries for a page, stop pagination to prevent infinite loops or missing data assumptions.
        break;
      }
    } while ($offset);

    return $map;
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, array $results, array $operations) {
    if ($success) {
      \Drupal::messenger()->addStatus(t('Chargebee data has been updated for all users.'));
    }
    else {
      \Drupal::messenger()->addError(t('An error occurred while updating Chargebee data.'));
    }
  }
}
