<?php

/**
 * Minds Newsfeed API
 *
 * @version 1
 * @author Mark Harding
 */

namespace Minds\Controllers\api\v1;

use Minds\Api\Factory;
use Minds\Core;
use Minds\Core\Di\Di;
use Minds\Core\Entities\Actions\Save;
use Minds\Core\Security;
use Minds\Entities;
use Minds\Entities\Activity;
use Minds\Helpers;
use Minds\Helpers\Counters;
use Minds\Interfaces;
use Minds\Interfaces\Flaggable;

class newsfeed implements Interfaces\Api
{
    /**
     * Returns the newsfeed
     * @param array $pages
     *
     * API:: /v1/newsfeed/
     */
    public function get($pages)
    {
        $response = [];
        $loadNext = '';

        if (!isset($pages[0])) {
            $pages[0] = 'network';
        }

        $pinned_guids = null;
        switch ($pages[0]) {
            case 'single':
                $activity = new Activity($pages[1]);

                if (!Security\ACL::_()->read($activity)) {
                    return Factory::response([
                        'status' => 'error',
                        'message' => 'You do not have permission to view this post',
                    ]);
                }

                if (!$activity->guid || Helpers\Flags::shouldFail($activity)) {
                    return Factory::response(['status' => 'error']);
                }
                return Factory::response(['activity' => $activity->export()]);
                break;
            default:
            case 'personal':
                $options = [
                    'owner_guid' => isset($pages[1]) ? $pages[1] : elgg_get_logged_in_user_guid(),
                ];
                if (isset($_GET['pinned']) && count($_GET['pinned']) > 0) {
                    $pinned_guids = [];
                    $p = explode(',', $_GET['pinned']);
                    foreach ($p as $guid) {
                        $pinned_guids[] = (string) $guid;
                    }
                }

                break;
            case 'network':
                $options = [
                    'network' => isset($pages[1]) ? $pages[1] : core\Session::getLoggedInUserGuid(),
                ];
                break;
            case 'top':
                $offset = isset($_GET['offset']) ? $_GET['offset'] : "";
                $result = Core\Di\Di::_()->get('Trending\Repository')
                    ->getList([
                        'type' => 'newsfeed',
                        'rating' => isset($_GET['rating']) ? (int) $_GET['rating'] : 1,
                        'limit' => 12,
                        'offset' => $offset,
                    ]);
                ksort($result['guids']);
                $options['guids'] = $result['guids'];
                if (!$options['guids']) {
                    return Factory::response([]);
                }
                $loadNext = base64_encode($result['token']);
                break;
            case 'featured':
                $db = Core\Di\Di::_()->get('Database\Cassandra\Indexes');
                $offset = isset($_GET['offset']) ? $_GET['offset'] : "";
                $guids = $db->getRow('activity:featured', ['limit' => 24, 'offset' => $offset]);
                if ($guids) {
                    $options['guids'] = $guids;
                } else {
                    return Factory::response([]);
                }
                break;
            case 'container':
                $options = [
                    'container_guid' => isset($pages[1]) ? $pages[1] : elgg_get_logged_in_user_guid(),
                ];

                if (isset($_GET['pinned']) && count($_GET['pinned']) > 0) {
                    $pinned_guids = [];
                    $p = explode(',', $_GET['pinned']);
                    foreach ($p as $guid) {
                        $pinned_guids[] = (string) $guid;
                    }
                }
                break;
        }

        if (get_input('count')) {
            $offset = get_input('offset', '');

            if (!$offset) {
                return Factory::response([
                    'count' => 0,
                    'load-previous' => '',
                ]);
            }

            $namespace = Core\Entities::buildNamespace(array_merge([
                'type' => 'activity',
            ], $options));

            $db = Core\Di\Di::_()->get('Database\Cassandra\Indexes');
            $guids = $db->get($namespace, [
                'limit' => 5000,
                'offset' => $offset,
                'reversed' => false,
            ]);

            if (isset($guids[$offset])) {
                unset($guids[$offset]);
            }

            if (!$guids) {
                return Factory::response([
                    'count' => 0,
                    'load-previous' => $offset,
                ]);
            }

            return Factory::response([
                'count' => count($guids),
                'load-previous' => (string) end(array_values($guids)) ?: $offset,
            ]);
        }

        //daily campaign reward
        if (Core\Session::isLoggedIn()) {
            Helpers\Campaigns\HourlyRewards::reward();
        }

        $activity = Core\Entities::get(array_merge([
            'type' => 'activity',
            'limit' => get_input('limit', 5),
            'offset' => get_input('offset', ''),
        ], $options));
        if (get_input('offset') && !get_input('prepend') && $activity) { // don't shift if we're prepending to newsfeed
            array_shift($activity);
        }

        $loadPrevious = $activity ? (string) current($activity)->guid : '';

        if ($this->shouldPrependBoosts($pages)) {
            try {
                $limit = isset($_GET['access_token']) && $_GET['offset'] ? 2 : 1;
                //$limit = 2;
                $cacher = Core\Data\cache\factory::build('Redis');
                $offset = $cacher->get(Core\Session::getLoggedinUser()->guid . ':boost-offset:newsfeed');

                /** @var Core\Boost\Network\Iterator $iterator */
                $iterator = Core\Di\Di::_()->get('Boost\Network\Iterator');
                $iterator->setPriority(!get_input('offset', ''))
                    ->setType('newsfeed')
                    ->setLimit($limit)
                    ->setOffset($offset)
                    //->setRating(0)
                    ->setQuality(0)
                    ->setIncrement(false);

                foreach ($iterator as $guid => $boost) {
                    $boost->boosted = true;
                    $boost->boosted_guid = (string) $guid;
                    array_unshift($activity, $boost);
                    //if (get_input('offset')) {
                    //bug: sometimes views weren't being calculated on scroll down
                    //Counters::increment($boost->guid, "impression");
                    //Counters::increment($boost->owner_guid, "impression");
                    //}
                }
                $cacher->set(Core\Session::getLoggedinUser()->guid . ':boost-offset:newsfeed', $iterator->getOffset(), (3600 / 2));
            } catch (\Exception $e) {
            }

            if (isset($_GET['thumb_guids'])) {
                foreach ($activity as $id => $object) {
                    unset($activity[$id]['thumbs:up:user_guids']);
                    unset($activity[$id]['thumbs:down:user_guid']);
                }
            }
        }

        if ($activity) {
            if (!$loadNext) {
                $loadNext = (string) end($activity)->guid;
            }
            if ($pages[0] == 'featured') {
                $loadNext = (string) end($activity)->featured_id;
            }
            $response['load-previous'] = $loadPrevious;

            if ($pinned_guids) {
                $response['pinned'] = [];
                $entities = Core\Entities::get(['guids' => $pinned_guids]);

                if ($entities) {
                    foreach ($entities as $entity) {
                        $exported = $entity->export();
                        $exported['pinned'] = true;
                        $response['pinned'][] = $exported;
                    }
                }
            }

            $response['activity'] = factory::exportable($activity, ['boosted', 'boosted_guid'], true);
        }

        $response['load-next'] = $loadNext;

        return Factory::response($response);
    }

    public function post($pages)
    {
        Factory::isLoggedIn();

        $save = new Save();

        switch ($pages[0]) {
            default:
                //essentially an edit
                if (is_numeric($pages[0])) {
                    $activity = new Activity($pages[0]);

                    if (!$activity->canEdit() || $activity->type !== 'activity') {
                        return Factory::response(['status' => 'error', 'message' => 'Post not editable']);
                    }

                    $allowed = ['message', 'title'];
                    foreach ($allowed as $allowed) {
                        if (isset($_POST[$allowed]) && $_POST[$allowed] !== false) {
                            $activity->$allowed = $_POST[$allowed];
                        }
                    }

                    if (isset($_POST['thumbnail'])) {
                        $activity->setThumbnail($_POST['thumbnail']);
                    }

                    if (isset($_POST['mature'])) {
                        $activity->setMature($_POST['mature']);
                    }

                    if (isset($_POST['tags'])) {
                        $activity->setTags($_POST['tags']);
                    }

                    if (isset($_POST['nsfw'])) {
                        $activity->setNsfw($_POST['nsfw']);
                    }

                    $user = Core\Session::getLoggedInUser();
                    if ($user->isMature()) {
                        $activity->setMature(true);
                    }

                    $activity->setEdited(true);

                    $activity->indexes = ["activity:$activity->owner_guid:edits"]; //don't re-index on edit
                    (new Core\Translation\Storage())->purge($activity->guid);

                    $save->setEntity($activity)
                        ->save();

                    (new Core\Entities\PropagateProperties())->from($activity);

                    $activity->setExportContext(true);
                    return Factory::response(['guid' => $activity->guid, 'activity' => $activity->export(), 'edited' => true]);
                }

                $activity = new Activity();

                $activity->setMature(isset($_POST['mature']) && !!$_POST['mature']);
                $activity->setNsfw($_POST['nsfw'] ?? []);

                $user = Core\Session::getLoggedInUser();

                if (isset($_POST['time_created'])) {
                    try {
                        $timeCreatedDelegate = new Core\Feeds\Activity\Delegates\TimeCreatedDelegate();
                        $timeCreatedDelegate->onAdd($activity, $_POST['time_created'], time());
                    } catch (\Exception $e) {
                        return Factory::response([
                            'status' => 'error',
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

                if ($user->isMature()) {
                    $activity->setMature(true);
                }

                if (isset($_POST['access_id'])) {
                    $activity->access_id = $_POST['access_id'];
                }

                if (isset($_POST['message'])) {
                    $activity->setMessage(rawurldecode($_POST['message']));
                }

                if (isset($_POST['title']) && $_POST['title']) {
                    $activity->setTitle(rawurldecode($_POST['title']))
                        ->setBlurb(rawurldecode($_POST['description']))
                        ->setURL(rawurldecode($_POST['url']))
                        ->setThumbnail($_POST['thumbnail']);
                }

                if (isset($_POST['attachment_guid']) && $_POST['attachment_guid']) {
                    $attachment = entities\Factory::build($_POST['attachment_guid']);
                    if (!$attachment) {
                        break;
                    }

                    if ((string) $attachment->owner_guid !== (string) Core\Session::getLoggedinUser()->guid) {
                        return Factory::response([
                            'status' => 'error',
                            'message' => 'You are not the owner of this attachment',
                        ]);
                    }

                    $attachment->title = $activity->message;
                    $attachment->access_id = 2;

                    if (isset($_POST['attachment_license'])) {
                        $attachment->license = $_POST['attachment_license'];
                    }

                    if ($attachment instanceof Flaggable) {
                        $attachment->setFlag('mature', $activity->getMature());
                    }

                    $attachment->setNsfw($activity->getNsfw());

                    $attachment->set('time_created', $activity->getTimeCreated());

                    $save->setEntity($attachment)->save();

                    switch ($attachment->subtype) {
                        case "image":
                            $activity->setCustom('batch', [[
                                'src' => elgg_get_site_url() . 'fs/v1/thumbnail/' . $attachment->guid,
                                'href' => elgg_get_site_url() . 'media/' . $attachment->container_guid . '/' . $attachment->guid,
                                'mature' => $attachment instanceof Flaggable ? $attachment->getFlag('mature') : false,
                                'width' => $attachment->width,
                                'height' => $attachment->height,
                                'blurhash' => $attachment->blurhash,
                                'gif' => (bool) $attachment->gif ?? false,
                            ]])
                                ->setFromEntity($attachment)
                                ->setTitle($attachment->message);
                            break;
                        case "video":
                            $activity->setFromEntity($attachment)
                                ->setCustom('video', [
                                    'thumbnail_src' => $attachment->getIconUrl(),
                                    'guid' => $attachment->guid,
                                    'mature' => $attachment instanceof Flaggable ? $attachment->getFlag('mature') : false
                                ])
                                ->setTitle($attachment->message);
                            break;
                    }
                }

                $container = null;

                if (isset($_POST['container_guid']) && $_POST['container_guid']) {
                    $activity->container_guid = $_POST['container_guid'];
                    if ($container = Entities\Factory::build($activity->container_guid)) {
                        $activity->containerObj = $container->export();
                    }
                    $activity->indexes = [
                        "activity:container:$activity->container_guid",
                        "activity:network:$activity->owner_guid",
                    ];

                    $cache = Di::_()->get('Cache');
                    $cache->destroy("activity:container:$activity->container_guid");

                    Core\Events\Dispatcher::trigger('activity:container:prepare', $container->type, [
                        'container' => $container,
                        'activity' => $activity,
                    ]);

                    if ($activity->getPending() && isset($attachment)) {
                        $attachment->access_id = 0;
                        $save->setEntity($attachment)->save();
                    }
                }

                if (isset($_POST['tags'])) {
                    $activity->setTags($_POST['tags']);
                }

                $nsfw = $_POST['nsfw'] ?? [];
                $activity->setNsfw($nsfw);

                try {
                    $guid = $save->setEntity($activity)->save();
                } catch (Core\Router\Exceptions\UnverifiedEmailException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    return Factory::response([
                        'status' => 'error',
                        'message' => $e->getMessage(),
                    ]);
                }

                if ($guid) {
                    if (in_array($activity->custom_type, ['batch', 'video'], true)) {
                        Helpers\Wallet::createTransaction(Core\Session::getLoggedinUser()->guid, 15, $guid, 'Post');
                    } else {
                        Helpers\Wallet::createTransaction(Core\Session::getLoggedinUser()->guid, 1, $guid, 'Post');
                    }

                    Core\Events\Dispatcher::trigger('social', 'dispatch', [
                        'entity' => $activity,
                        'services' => [
                            'facebook' => isset($_POST['facebook']) && $_POST['facebook'] ? $_POST['facebook'] : false,
                            'twitter' => isset($_POST['twitter']) && $_POST['twitter'] ? $_POST['twitter'] : false,
                        ],
                        'data' => [
                            'message' => rawurldecode($_POST['message']),
                            'perma_url' => isset($_POST['url']) ? rawurldecode($_POST['url']) : $activity->getURL(),
                            'thumbnail_src' => isset($_POST['thumbnail']) ? rawurldecode($_POST['thumbnail']) : null,
                            'description' => isset($_POST['description']) ? rawurldecode($_POST['description']) : null,
                        ],
                    ]);

                    // Follow activity
                    (new Core\Notification\PostSubscriptions\Manager())
                        ->setEntityGuid($activity->guid)
                        ->setUserGuid(Core\Session::getLoggedInUserGuid())
                        ->follow();

                    if (isset($attachment) && $attachment) {
                        // Follow attachment
                        (new Core\Notification\PostSubscriptions\Manager())
                            ->setEntityGuid($attachment->guid)
                            ->setUserGuid(Core\Session::getLoggedInUserGuid())
                            ->follow();
                    }

                    if ($container) {
                        Core\Events\Dispatcher::trigger('activity:container', $container->type, [
                            'container' => $container,
                            'activity' => $activity,
                        ]);
                    }

                    $activity->setExportContext(true);
                    return Factory::response(['guid' => $guid, 'activity' => $activity->export()]);
                } else {
                    return Factory::response(['status' => 'failed', 'message' => 'could not save']);
                }
        }
    }

    public function put($pages)
    {
        $activity = new Activity($pages[0]);
        if (!$activity->guid) {
            return Factory::response(['status' => 'error', 'message' => 'could not find activity post']);
        }

        switch ($pages[1]) {
            case 'view':
                try {
                    Core\Analytics\App::_()
                        ->setMetric('impression')
                        ->setKey($activity->guid)
                        ->increment();

                    Core\Analytics\User::_()
                        ->setMetric('impression')
                        ->setKey($activity->owner_guid)
                        ->increment();
                } catch (\Exception $e) {
                }
                break;
        }

        return Factory::response([]);
    }

    public function delete($pages)
    {
        $activity = new Activity($pages[0]);
        if (!$activity->guid) {
            return Factory::response(['status' => 'error', 'message' => 'could not find activity post']);
        }

        if (!$activity->canEdit()) {
            return Factory::response(['status' => 'error', 'message' => 'you don\'t have permission']);
        }
        /** @var Entities\User $owner */
        $owner = $activity->getOwnerEntity();

        if (
            $activity->entity_guid &&
            in_array($activity->custom_type, ['batch', 'video'], true)
        ) {
            // Delete attachment object
            try {
                $attachment = Entities\Factory::build($activity->entity_guid);

                if ($attachment && $owner->guid == $attachment->owner_guid) {
                    $attachment->delete();
                }
            } catch (\Exception $e) {
                error_log("Cannot delete attachment: {$activity->entity_guid}");
            }
        }

        // remove from pinned
        $owner->removePinned($activity->guid);

        if ($activity->delete()) {
            return Factory::response(['message' => 'removed ' . $pages[0]]);
        }

        return Factory::response(['status' => 'error', 'message' => 'could not delete']);
    }

    /**
     * To show boosts or not
     * @param array $pages
     * @return bool
     */
    protected function shouldPrependBoosts($pages = [])
    {
        //Plus Users -> NO
        $disabledBoost = Core\Session::getLoggedinUser()->plus && Core\Session::getLoggedinUser()->disabled_boost;
        if ($disabledBoost) {
            return false;
        }

        //Prepending posts -> NO
        if (isset($_GET['prepend'])) {
            return false;
        }

        //Not a network feed -> NO
        if ($pages[0] != 'network') {
            return false;
        }

        //Offset - YES
        if (isset($_GET['offset']) && $_GET['offset']) {
            return true;
        }

        //Mobile - YES
        if (isset($_GET['access_token'])) {
            return true;
        }

        return false;
    }
}
