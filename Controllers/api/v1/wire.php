<?php
/**
 * Minds Wire Api endpoint
 *
 * @version 1
 * @author Mark Harding
 *
 */

namespace Minds\Controllers\api\v1;

use Minds\Core;
use Minds\Entities\User;
use Minds\Helpers;
use Minds\Entities;
use Minds\Interfaces;
use Minds\Api\Factory;
use Minds\Core\Wire\Methods;
use Minds\Core\Payments;

class wire implements Interfaces\Api
{

    /**
     *
     */
    public function get($pages)
    {
        $response = [];

        return Factory::response($response);
    }

    /**
     * Send a wire to someone
     * @param array $pages
     *
     * API:: /v1/wire/:guid
     */
    public function post($pages)
    {
        Factory::isLoggedIn();
        $response = [];

        if (!isset($pages[0])) {
            return Factory::response(['status' => 'error', 'message' => ':guid must be passed in uri']);
        }

        $entity = Entities\Factory::build($pages[0]);

        if (!$entity) {
            return Factory::response(['status' => 'error', 'message' => 'Entity not found']);
        }

        $amount = $_POST['amount'];
        $method = $_POST['method'];
        if ($method == 'usd') {
            $method = 'money';
        }

        $recurring = isset($_POST['recurring']) ? $_POST['recurring'] : false;

        if (!$amount) {
            return Factory::response(['status' => 'error', 'message' => 'you must send an amount']);
        }

        if ($amount <= 0) {
            return Factory::response(['status' => 'error', 'message' => 'amount must be a positive number']);
        }

        $service = Methods\Factory::build($method);

        try {
            $service->setAmount($amount)
                ->setEntity($entity)
                ->setPayload((array) $_POST['payload'])
                ->setRecurring($recurring);
            $result = $service->create();

            if (isset($result['subscriptionId'])) {
                $response['subscriptionId'] = $result['subscriptionId'];
            }

            //now send notification

        } catch (Methods\NotMonetizedException $e) {
            $this->sendMonetizeNotification();
            $response['status'] = 'error';
            $response['message'] = $e->getMessage();
        } catch (\Exception $e) {
            $response['status'] = 'error';
            $response['message'] = $e->getMessage();
        }

        return Factory::response($response);
    }

    /**
     */
    public function put($pages)
    {
    }

    /**
     */
    public function delete($pages)
    {
    }


    private function sendMonetizeNotification()
    {
        $message = 'Somebody wanted to send you a money wire, but you need to setup your merchant account first! You can monetize your account in your Wallet.';
        Core\Events\Dispatcher::trigger('notification', 'wire', [
            'to' => [$this->entity->owner_guid],
            'from' => 100000000000000519,
            'notification_view' => 'custom_message',
            'params' => ['message' => $message],
            'message' => $message,
        ]);
    }
}
