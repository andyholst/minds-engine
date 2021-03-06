<?php
/**
 * Minds Boost Api endpoint
 *
 * @version 1
 * @author Mark Harding
 *
 */
namespace Minds\Controllers\api\v2\boost;

use Minds\Core;
use Minds\Core\Di\Di;
use Minds\Core\Util\BigNumber;
use Minds\Helpers;
use Minds\Entities;
use Minds\Interfaces;
use Minds\Api\Factory;
use Minds\Core\Payments;
use Minds\Core\Feeds\Activity;
use Minds\Core\EventStreams\ActionEvent;
use Minds\Core\EventStreams\Topics\ActionEventsTopic;
use Zend\Diactoros\ServerRequestFactory;

class peer implements Interfaces\Api
{
    private $rate = 1;

    /**
     * Return a list of boosts that a user needs to review
     * @param array $pages
     */
    public function get($pages)
    {
        Factory::isLoggedIn();

        $response = [];
        $limit = isset($_GET['limit']) && $_GET['limit'] ? (int) $_GET['limit'] : 12;
        $offset = isset($_GET['offset']) && $_GET['offset'] ? $_GET['offset'] : '';
        /** @var Core\Boost\Peer\Review $review */
        $review = Core\Di\Di::_()->get('Boost\Peer\Review');

        switch ($pages[0]) {
            case 'outbox':
                $boosts = $review->getOutbox(Core\Session::getLoggedinUser()->guid, $limit, $offset);

                $response['boosts'] = Factory::exportable($boosts['data']);

                if ($boosts) {
                    $response['load-next'] = $boosts['next'];
                }
                break;
            case 'inbox':
            default:
                $review->setType(Core\Session::getLoggedinUser()->guid);
                $boosts = $review->getReviewQueue(
                    isset($_GET['limit']) ? $_GET['limit'] : 12,
                    isset($_GET['offset']) ? $_GET['offset'] : ""
                );

                $response['boosts'] = Factory::exportable($boosts['data']);

                if ($boosts) {
                    $response['load-next'] = (string) $boosts['next'];
                }
        }

        return Factory::response($response);
    }

    /**
     * Boost an entity
     * @param array $pages
     *
     * API:: /v2/boost/:type/:guid
     */
    public function post($pages)
    {
        Factory::isLoggedIn();

        $entity = Entities\Factory::build($pages[0]);
        $destination = Entities\Factory::build($_POST['destination']);
        $bidBN = BigNumber::_($_POST['bid']);
        $bid = (string) $bidBN;
        $currency = isset($_POST['currency']) ? $_POST['currency'] : '';
        $paymentMethod = isset($_POST['paymentMethod']) ? $_POST['paymentMethod'] : [];

        if (!$entity) {
            return Factory::response([
                'status' => 'error',
                'stage' => 'initial',
                'message' => 'We couldn\'t find the entity you wanted boost. Please try again.'
            ]);
        }

        if (!$destination) {
            return Factory::response([
                'status' => 'error',
                'stage' => 'initial',
                'message' => 'We couldn\'t find the user you wish to boost to. Please try another user.'
            ]);
        }

        if ($currency !== 'tokens') {
            return Factory::response([
                'status' => 'error',
                'stage' => 'initial',
                'message' => 'Unknown currency'
            ]);
        }

        if (!$bid) {
            return Factory::response([
                'status' => 'error',
                'stage' => 'initial',
                'message' => 'Invalid bid'
            ]);
        }

        // Ensure bid is positive

        if ($bidBN->lt(0)) {
            return Factory::response(['status' => 'error', 'message' => 'bid must be a positive number']);
        }

        // Do not allow boosting to self

        if (Core\Session::getLoggedinUser()->guid == $destination->guid) {
            return Factory::response(['status' => 'error', 'message' => 'can not boost to self']);
        }

        if (!Core\Security\ACL::_()->interact($destination, Core\Session::getLoggedinUser())) {
            return Factory::response([
                'status' => 'error',
                'stage' => 'initial',
                'message' => "You are not allowed to boost to @{$destination->username}'s channel"
            ]);
        }

        try {
            $twoFactorManager = Di::_()->get('Security\TwoFactor\Manager');
            $twoFactorManager->gatekeeper(Core\Session::getLoggedinUser(), ServerRequestFactory::fromGlobals());
        } catch (\Exception $e) {
            header('HTTP/1.1 ' . $e->getCode(), true, $e->getCode());
            $response['status'] = "error";
            $response['code'] = $e->getCode();
            $response['message'] = $e->getMessage();
            $response['errorId'] = str_replace('\\', '::', get_class($e));
            return Factory::response($response);
        }

        // Build entity

        $state = 'created';

        if ($paymentMethod['method'] === 'onchain') {
            $state = 'pending';
        }

        $boost = (new Entities\Boost\Peer())
          ->setEntity($entity)
          ->setType('peer')
          ->setMethod($currency)
          ->setBid($bid)
          ->setDestination($destination)
          ->setOwner(Core\Session::getLoggedInUser())
          ->postToFacebook($_POST['postToFacebook'])
          ->setScheduledTs($_POST['scheduledTs'])
          ->setState($state);

        try {
            // Pre-set GUID
            if (isset($_POST['guid'])) {
                $guid = $_POST['guid'];

                if (!is_numeric($guid) || $guid < 1) {
                    return Factory::response([
                        'status' => 'error',
                        'stage' => 'transaction',
                        'message' => 'Provided GUID is invalid'
                    ]);
                }

                /** @var Core\Boost\Repository $repository */
                $repository = Di::_()->get('Boost\Repository');

                $existingBoost = $repository->getEntity('peer', $guid);

                if ($existingBoost) {
                    return Factory::response([
                        'status' => 'error',
                        'stage' => 'transaction',
                        'message' => 'Provided GUID already exists'
                    ]);
                }

                $boost->setGuid($guid);

                $checksum = isset($_POST['checksum']) ? $_POST['checksum'] : null;

                $prehash = $guid
                    . $entity->type
                    . $entity->guid
                    . ($entity->owner_guid ?: '')
                    . ($entity->perma_url ?: '')
                    . ($entity->message ?: '')
                    . ($entity->title ?: '')
                    . $entity->time_created;

                if ($checksum !== md5($prehash)) {
                    return Factory::response([
                        'status' => 'error',
                        'stage' => 'transaction',
                        'message' => 'Checksum does not match'
                    ]);
                }

                $boost->setChecksum($checksum);
            }

            // Charge

            $transactionId = Di::_()->get('Boost\Payment')->pay($boost, $paymentMethod);

            // Save Boost

            $boost
                ->setTransactionId($transactionId)
                ->save();
        } catch (\Exception $e) {
            return Factory::response([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }

        // Notify

        $actionEvent = new ActionEvent();
        $actionEvent
            ->setAction(ActionEvent::ACTION_BOOST_PEER_REQUEST)
            ->setEntity($boost)
            ->setUser(Core\Session::getLoggedinUser());

        $actionEventTopic = new ActionEventsTopic();
        $actionEventTopic->send($actionEvent);

        //

        Core\Events\Dispatcher::trigger('notification', 'boost', [
            'to'=> [$boost->getDestination()->guid],
            'entity' => $boost->getEntity(),
            'notification_view' => 'boost_peer_request',
            'params' => [
                'bid' => $boost->getBid(),
                'type' => $boost->getType(),
                'currency' => $boost->getMethod(),
                'title' => $boost->getEntity()->title ?: $boost->getEntity()->message
            ]
        ]);

        $response['boost_guid'] = $boost->getGuid();

        return Factory::response($response);
    }

    /**
     * @param array $pages
     */
    public function put($pages)
    {
        Factory::isLoggedIn();

        $response = [];
        /** @var Core\Boost\Peer\Review $review */
        $review = Core\Di\Di::_()->get('Boost\Peer\Review');
        $boost = $review->getBoostEntity($pages[0]);

        if ($boost->getState() != 'created') {
            return Factory::response([
                'status' => 'error',
                'message' => 'This boost is in the ' . $boost->getState() . ' state and can not be approved'
            ]);
        }

        // Check embedded entity

        $embedded = Entities\Factory::build($boost->getEntity()->guid); //more accurate, as entity doesn't do this @todo maybe it should in the future

        if (!$embedded) {
            return Factory::response([
                'status' => 'error',
                'message' => 'The original post was deleted'
            ]);
        }

        // Set state (before charge)

        $review->setBoost($boost);
        try {
            $review->accept();
        } catch (\Exception $e) {
            $response['status'] = 'error';
            $response['message'] = $e->getMessage();
            return Factory::response($response);
        }

        // Charge

        $chargeId = Di::_()->get('Boost\Payment')->charge($boost);

        if (!$chargeId) {
            return Factory::response([
                'status' => 'error',
                'message' => 'Cannot process charge'
            ]);
        }

        // Post

        Helpers\Counters::increment($boost->getEntity()->guid, 'remind');

        $activity = new Entities\Activity();
        $activity->ownerObj = Core\Session::getLoggedInUser()->export();
        $activity->p2p_boosted = true;


        $remindIntent = new Activity\RemindIntent();
        $remindIntent->setGuid($embedded->guid)
            ->setOwnerGuid($embedded->owner_guid)
            ->setQuotedPost(false);
        $activity->setRemind($remindIntent)->save();

        // Notify

        $actionEvent = new ActionEvent();
        $actionEvent
            ->setAction(ActionEvent::ACTION_BOOST_PEER_ACCEPTED)
            ->setEntity($boost)
            ->setUser(Core\Session::getLoggedinUser());

        $actionEventTopic = new ActionEventsTopic();
        $actionEventTopic->send($actionEvent);

        //

        Core\Events\Dispatcher::trigger('notification', 'boost', [
            'to'=>[$boost->getOwner()->guid],
            'entity' => $boost->getEntity(),
            'notification_view' => 'boost_peer_accepted',
            'params' => ['bid' => $boost->getBid(), 'type' => $boost->getType(), 'title' => $boost->getEntity()->title]
        ]);

        $response['status'] = 'success';
        return Factory::response($response);
    }

    /**
     */
    public function delete($pages)
    {
        Factory::isLoggedIn();

        $response = [];

        $revoked = isset($pages[1]) && $pages[1] == 'revoke';

        /** @var Core\Boost\Peer\Review $review */
        $review = Core\Di\Di::_()->get('Boost\Peer\Review');
        $boost = $review->getBoostEntity($pages[0]);
        $review->setBoost($boost);

        if ($boost->getState() != 'created') {
            return Factory::response([
                'status' => 'error',
                'message' => 'This boost is in the ' . $boost->getState() . ' state and cannot be refunded'
            ]);
        }

        try {

            // Action
            if ($revoked) {
                $review->revoke();
            } else {
                $actionEvent = new ActionEvent();
                $actionEvent
                    ->setAction(ActionEvent::ACTION_BOOST_PEER_REJECTED)
                    ->setEntity($boost)
                    ->setUser(Core\Session::getLoggedinUser());

                $actionEventTopic = new ActionEventsTopic();
                $actionEventTopic->send($actionEvent);

                //
                Core\Events\Dispatcher::trigger('notification', 'boost', [
                    'to' => [$boost->getOwner()->guid],
                    'entity' => $boost->getEntity(),
                    'notification_view' => 'boost_peer_rejected',
                    'params' => [
                        'bid' => $boost->getBid(),
                        'type' => $boost->getType(),
                        'title' => $boost->getEntity()->title,
                    ]
                ]);

                //
                $review->reject();
            }

            // Refund payment

            $refund = Di::_()->get('Boost\Payment')->refund($boost);

            if (!$refund) {
                return Factory::response([
                    'status' => 'error',
                    'message' => 'Cannot process refund'
                ]);
            }

            $response['status'] = 'success';
        } catch (\Exception $e) {
            $response['status'] = 'error';
            $response['message'] = $e->getMessage();
        }

        return Factory::response($response);
    }
}
