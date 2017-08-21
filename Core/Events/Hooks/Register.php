<?php
/**
 */

namespace Minds\Core\Events\Hooks;

use Minds\Core;
use Minds\Entities;
use Minds\Helpers;
use Minds\Core\Events\Dispatcher;

class Register
{
    public function init()
    {
        Dispatcher::register('register', 'user', function ($event) {
            $params = $event->getParameters();

            $guid = $params['user']->guid;
            //subscribe to minds channel
            $minds = new Entities\User('minds');
            $params['user']->subscribe($minds->guid);

            Helpers\Wallet::createTransaction($guid, 1000, $guid, "Welcome");
            Core\Events\Dispatcher::trigger('notification', 'welcome', array(
                'to'=>array($guid),
                'from' => 100000000000000519,
                'notification_view' => 'welcome_points',
                'params' => array('points'=>1000),
                'points' => 1000
                ));

            //@todo maybe put this in background process
            foreach (array("welcome_boost", "welcome_chat", "welcome_discover") as $notif_type) {
                Core\Events\Dispatcher::trigger('notification', 'welcome', array(
                  'to' => [ $guid ],
                  'from' => "100000000000000519",
                  'notification_view' => $notif_type,
                ));
            }

            //@todo again, maybe in a background task?
            if ($params['referrer']) {
                $user = new Entities\User(strtolower(ltrim($params['referrer'], '@')));
                if ($user->guid) {
                    Helpers\Wallet::createTransaction($user->guid, 100, $guid, "Referred @" . $_POST['username']);
                    $params['user']->referrer = (string) $user->guid;
                    $params['user']->save();
                    //create graph connection for future referral trees
                    try {
                        $prepared = new Core\Data\Neo4j\Prepared\CypherQuery();
                        $prepared->setQuery('MATCH (referrer:User {guid: {referrer_guid}}), (user:User {guid: {user_guid}}) MERGE (referrer)-[:REFER]->(user)', [
                          'referrer_guid' => (string) Core\Session::getLoggedInUserGuid(),
                          'user_guid' => (string) $user->guid
                        ]);
                        Core\Data\Client::build('Neo4j')->request($prepared);
                    } catch (\Exception $e) {
                    }
                    $params['user']->subscribe($user->guid);
                }
            }
        });

        Dispatcher::register('register/complete', 'user', function ($event) {
            $params = $event->getParameters();
            //temp: if captcha failed
            if ($params['user']->captcha_failed) {
                return false;
            }
            //send welcome email
            try {
                $template = new Core\Email\Template();
                $template
                  ->setTemplate()
                  ->setBody('welcome.tpl')
                  ->set('guid', $params['user']->guid)
                  ->set('username', $params['user']->username)
                  ->set('email', $_POST['email'])
                  ->set('user', $params['user']);
                $message = new Core\Email\Message();
                $message->setTo($params['user'])
                  ->setMessageId(implode('-', [ $params['user']->guid, sha1($params['user']->getEmail()), sha1('register-' . time()) ]))
                  ->setSubject("Welcome to Minds. Introduce yourself.")
                  ->setHtml($template);
                $mailer = new Core\Email\Mailer();
                $mailer->queue($message);
            } catch (\Exception $e) { }
        });
    }
}
