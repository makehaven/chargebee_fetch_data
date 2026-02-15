<?php

declare(strict_types=1);

namespace Drupal\Tests\chargebee_fetch_data\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\chargebee_fetch_data\Form\ChargebeeFetchDataForm;
use Drupal\Core\Messenger\MessengerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Tests batch helper behavior for Chargebee fetch form.
 *
 * @group chargebee_fetch_data
 */
class ChargebeeFetchDataFormKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'chargebee_fetch_data',
  ];

  /**
   * Tests batched subscription fetch mapping across paginated responses.
   */
  public function testFetchSubscriptionsForCustomersBuildsLatestMap(): void {
    $client = $this->createMock(Client::class);
    $client->expects($this->exactly(2))
      ->method('get')
      ->willReturnOnConsecutiveCalls(
        new Response(200, [], json_encode([
          'list' => [
            ['subscription' => ['customer_id' => 'cust_1', 'plan_id' => 'plan_new']],
          ],
          'next_offset' => 'offset_2',
        ])),
        new Response(200, [], json_encode([
          'list' => [
            ['subscription' => ['customer_id' => 'cust_1', 'plan_id' => 'plan_old']],
            ['subscription' => ['customer_id' => 'cust_2', 'plan_id' => 'plan_2']],
          ],
        ]))
      );
    $this->container->set('http_client', $client);

    $result = $this->invokeFetchSubscriptions(['cust_1', 'cust_2'], 'key_live', 'https://cb.example.com/api/v2/subscriptions');

    $this->assertArrayHasKey('cust_1', $result);
    $this->assertArrayHasKey('cust_2', $result);
    $this->assertSame('plan_new', $result['cust_1']['subscription']['plan_id']);
    $this->assertSame('plan_2', $result['cust_2']['subscription']['plan_id']);
  }

  /**
   * Tests hard API errors return partial/empty map and report a message.
   */
  public function testFetchSubscriptionsForCustomersHandlesHardApiError(): void {
    $request = new Request('GET', 'https://cb.example.com/api/v2/subscriptions');
    $response = new Response(500, [], '{"message":"boom"}');
    $exception = new RequestException('server error', $request, $response);

    $client = $this->createMock(Client::class);
    $client->expects($this->once())
      ->method('get')
      ->willThrowException($exception);
    $this->container->set('http_client', $client);

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addError');
    $this->container->set('messenger', $messenger);

    $result = $this->invokeFetchSubscriptions(['cust_1'], 'key_live', 'https://cb.example.com/api/v2/subscriptions');
    $this->assertSame([], $result);
  }

  /**
   * Tests batch finished callback emits success and error messages.
   */
  public function testBatchFinishedMessaging(): void {
    $successMessenger = $this->createMock(MessengerInterface::class);
    $successMessenger->expects($this->once())->method('addStatus');
    $this->container->set('messenger', $successMessenger);
    ChargebeeFetchDataForm::batchFinished(TRUE, [], []);

    $errorMessenger = $this->createMock(MessengerInterface::class);
    $errorMessenger->expects($this->once())->method('addError');
    $this->container->set('messenger', $errorMessenger);
    ChargebeeFetchDataForm::batchFinished(FALSE, [], []);
  }

  /**
   * Invokes the protected static fetch helper.
   */
  private function invokeFetchSubscriptions(array $customerIds, string $apiKey, string $endpoint): array {
    $method = new \ReflectionMethod(ChargebeeFetchDataForm::class, 'fetchSubscriptionsForCustomers');
    $method->setAccessible(TRUE);
    return $method->invoke(NULL, $customerIds, $apiKey, $endpoint);
  }

}
