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
        ->condition('field_user_chargebee_id', NULL, 'IS NOT NULL')
        ->condition('roles', 'member');
      $start_uid = trim($form_state->getValue('start_uid'));
      if (!empty($start_uid)) {
        $query->condition('uid', $start_uid, '>=');
        \Drupal::messenger()->addStatus($this->t('Processing only users with UID ≥ @uid', ['@uid' => $start_uid]));
      }
      $user_ids = $query->execute();
      \Drupal::messenger()->addStatus($this->t('Found @count users with a Chargebee customer ID and the member role.', ['@count' => count($user_ids)]));
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

    foreach ($user_ids as $uid) {
      try {
        $user = \Drupal\user\Entity\User::load($uid);
        if (!$user) continue;

        if ($detailed) {
          \Drupal::messenger()->addStatus(t('Processing user @uid.', ['@uid' => $uid]));
        }

        if ($user->hasField('field_user_chargebee_id') && !$user->get('field_user_chargebee_id')->isEmpty()) {
          $chargebee_customer_id = $user->get('field_user_chargebee_id')->value;
          if ($detailed) {
            \Drupal::messenger()->addStatus(t('Chargebee customer ID for user @uid: @cid', ['@uid' => $uid, '@cid' => $chargebee_customer_id]));
          }
        } else {
          \Drupal::messenger()->addWarning(t('User @uid does not have a Chargebee customer ID.', ['@uid' => $uid]));
          continue;
        }

        $subscription_data = self::fetchSubscriptionForCustomer($chargebee_customer_id, $api_key, $subscriptions_endpoint);
        if ($subscription_data && isset($subscription_data['subscription'])) {
          $plan_id = $subscription_data['subscription']['plan_id'] ?? NULL;
          $plan_amount_cents = $subscription_data['subscription']['plan_amount'] ?? NULL;

          if ($detailed) {
            \Drupal::messenger()->addStatus(t('Fetched subscription for customer @cid: plan_id=@pid, plan_amount_cents=@amt', ['@cid' => $chargebee_customer_id, '@pid' => $plan_id, '@amt' => $plan_amount_cents]));
          }

          if ($plan_id && $plan_amount_cents !== NULL) {
            $plan_amount = $plan_amount_cents / 100;

            if ($user->hasField('field_user_chargebee_plan')) {
              $user->set('field_user_chargebee_plan', $plan_id);
              try {
                if ($create_revision) {
                  $user->setNewRevision(TRUE);
                  $user->setRevisionLogMessage('Updated Chargebee plan ID via batch process.');
                }
                $user->save();
                if ($detailed) {
                  \Drupal::messenger()->addStatus(t('User @uid saved successfully with plan ID updated.', ['@uid' => $uid]));
                }
                if ($detailed_logging) {
                  \Drupal::logger('chargebee_fetch_data')->notice('User @uid updated with plan ID: @value', ['@uid' => $uid, '@value' => $user->get('field_user_chargebee_plan')->value]);
                }
              } catch (\Exception $e) {
                \Drupal::messenger()->addError(t('Failed to save user @uid for plan ID: @error', ['@uid' => $uid, '@error' => $e->getMessage()]));
                continue;
              }
            } else {
              \Drupal::messenger()->addWarning(t('User @uid does not have field_user_chargebee_plan.', ['@uid' => $uid]));
            }

            if ($user->hasField('field_member_payment_monthly')) {
              $user->set('field_member_payment_monthly', $plan_amount);
              try {
                if ($create_revision) {
                  $user->setNewRevision(TRUE);
                  $user->setRevisionLogMessage('Updated monthly payment amount via Chargebee batch process.');
                }
                $user->save();
                if ($detailed) {
                  \Drupal::messenger()->addStatus(t('User @uid saved with monthly payment @amount on account.', ['@uid' => $uid, '@amount' => $plan_amount]));
                }
              }
              catch (\Exception $e) {
                \Drupal::messenger()->addError(t('Failed to save user @uid for monthly payment: @error', ['@uid' => $uid, '@error' => $e->getMessage()]));
              }
            }
            else {
              $profile_storage = \Drupal::entityTypeManager()->getStorage('profile');
              $profiles = $profile_storage->loadByProperties(['uid' => $user->id(), 'type' => 'main']);
              if (!empty($profiles)) {
                $profile = reset($profiles);
                if ($profile->hasField('field_member_payment_monthly')) {
                  $profile->set('field_member_payment_monthly', $plan_amount);
                  try {
                    if ($create_revision) {
                      $profile->setNewRevision(TRUE);
                      $profile->setRevisionLogMessage('Updated monthly payment amount via Chargebee batch process.');
                    }
                    $profile->save();
                    if ($detailed) {
                      \Drupal::messenger()->addStatus(t('Profile for user @uid saved with monthly payment @amount.', ['@uid' => $user->id(), '@amount' => $plan_amount]));
                    }
                  }
                  catch (\Exception $e) {
                    \Drupal::messenger()->addError(t('Failed to save profile for user @uid: @error', ['@uid' => $user->id(), '@error' => $e->getMessage()]));
                  }
                }
                else {
                  \Drupal::messenger()->addWarning(t('User @uid profile does not have field_member_payment_monthly.', ['@uid' => $user->id()]));
                }
              }
              else {
                \Drupal::messenger()->addWarning(t('No main profile found for user @uid.', ['@uid' => $user->id()]));
              }
            }
          } else {
            \Drupal::messenger()->addWarning(t('Subscription data incomplete for customer @cid.', ['@cid' => $chargebee_customer_id]));
          }
        } else {
          \Drupal::messenger()->addWarning(t('No active subscription found for customer @cid.', ['@cid' => $chargebee_customer_id]));
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
   * Helper function to fetch the active subscription for a given Chargebee customer.
   */
  protected static function fetchSubscriptionForCustomer($customer_id, $api_key, $subscriptions_endpoint) {
    $client = \Drupal::httpClient();
    $maxRetries = 3;
    $retryDelay = 2;
    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
      try {
        $response = $client->get($subscriptions_endpoint, [
          'auth' => [$api_key, ''],
          'query' => [
            'customer_id[is]' => $customer_id,
            'limit' => 1,
            'status[is]' => 'active',
          ],
        ]);
        $data = json_decode($response->getBody()->getContents(), TRUE);
        if (!empty($data['list'][0]['subscription'])) {
          return $data['list'][0];
        }
        else {
          \Drupal::messenger()->addWarning(t('No subscription data returned for customer @cid.', ['@cid' => $customer_id]));
          return FALSE;
        }
      }
      catch (RequestException $e) {
        if ($e->hasResponse() && $e->getResponse()->getStatusCode() == 429) {
          \Drupal::messenger()->addWarning(t('Rate limit reached for customer @cid, retrying in @delay seconds...', ['@cid' => $customer_id, '@delay' => $retryDelay]));
          sleep($retryDelay);
          continue;
        }
        else {
          \Drupal::messenger()->addError(t('Chargebee API request failed for customer @cid: @error', [
            '@cid' => $customer_id,
            '@error' => $e->getMessage(),
          ]));
          return FALSE;
        }
      }
    }
    return FALSE;
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