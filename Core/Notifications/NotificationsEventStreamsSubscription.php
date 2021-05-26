<?php
/**
 * This subscription will build notifications from stream events
 */
namespace Minds\Core\Notifications;

use Minds\Core\Di\Di;
use Minds\Core\EventStreams\ActionEvent;
use Minds\Core\EventStreams\EventInterface;
use Minds\Core\EventStreams\SubscriptionInterface;
use Minds\Core\EventStreams\Topics\ActionEventsTopic;
use Minds\Core\EventStreams\Topics\TopicInterface;
use Minds\Core\Log\Logger;

class NotificationsEventStreamsSubscription implements SubscriptionInterface
{
    /** @var Manager */
    protected $manager;

    /** @var Logger */
    protected $logger;

    public function __construct(Manager $manager = null, Logger $logger = null)
    {
        $this->manager = $manager ?? Di::_()->get('Notifications\Manager');
        $this->logger = $logger ?? Di::_()->get('Logger');
    }

    /**
     * @return string
     */
    public function getSubscriptionId(): string
    {
        return 'notifications';
    }

    /**
     * @return TopicInterface
     */
    public function getTopic(): TopicInterface
    {
        return new ActionEventsTopic();
    }

    /**
     * @return string
     */
    public function getTopicRegex(): string
    {
        return '.*'; // Notifications want all actions
    }

    /**
     * Called when there is a new event
     * @param EventInterface $event
     * @return booo
     */
    public function consume(EventInterface $event): bool
    {
        if (!$event instanceof ActionEvent) {
            return false;
        }

        if ($event->getEntity()->getOwnerGuid() == $event->getUser()->getGuid()) {
            $this->logger->info('Skipping as owner is sender');
            return true; // True to awknowldge, but we dont care about interactions with our own posts
        }

        if ($event->getTimestamp() < time() - 3600) {
            // Don't notify for event older than 1 hour, here
            return true;
        }
         
        $notification = new Notification();
        $notification->setToGuid((string) $event->getEntity()->getOwnerGuid());
        $notification->setFromGuid((string) $event->getUser()->getGuid());
        $notification->setEntityUrn($event->getEntity()->getUrn());
        
        switch ($event->getAction()) {
            case ActionEvent::ACTION_VOTE:
                $notification->setType($event->getActionData()['vote_direction'] === 'up' ? NotificationTypes::TYPE_VOTE_UP : NotificationTypes::TYPE_VOTE_DOWN);
                break;
            case ActionEvent::ACTION_COMMENT:
                $notification->setType(NotificationTypes::TYPE_COMMENT);
                $notification->setData([
                    'comment_urn' => $event->getActionData()['comment_urn'],
                ]);
                break;
            case ActionEvent::ACTION_TAG:
                // Replace toGuid with the entity guid as the entity is the tagged person
+               $notification->setToGuid((string) $notification->getEntity()->getGuid());
                // Replace entity_urn with the post guid,
                $notification->setEntityUrn($event->getActionData()['tag_in_entity_urn']);
                $notification->setType(NotificationTypes::TYPE_TAG);
                break;
            case ActionEvent::ACTION_SUBSCRIBE:
                // Replace toGuid with the entity guid as the entity is the subscribed person
+               $notification->setToGuid((string) $notification->getEntity()->getGuid());
                $notification->setType(NotificationTypes::TYPE_SUBSCRIBE);
                break;
            case ActionEvent::ACTION_REMIND:
                $notification->setType(NotificationTypes::TYPE_REMIND);
                $notification->setData([
                    'remind_urn' => $event->getActionData()['remind_urn'],
                ]);
                break;
            case ActionEvent::ACTION_QUOTE:
                $notification->setType(NotificationTypes::TYPE_QUOTE);
                $notification->setData([
                    'quote_urn' => $event->getActionData()['quote_urn'],
                ]);
                // Replace entity_urn with our quote
                $notification->setEntityUrn($event->getActionData()['quote_urn']);
                break;
            default:
                return true; // We will not make a notification from this
        }

        // Save and submit
        if ($this->manager->add($notification)) {

            // Some logging
            $this->logger->info("{$notification->getUuid()} {$notification->getType()} saved");

            return true; // Return true to awknowledge the event from the stream (stop it being redelivered)
        }

        return false;
    }
}
