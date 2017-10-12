<?php
/**
 * Minds Navigation Manager
 */
namespace Minds\Core\Navigation;

use Minds\Core;
use Minds\Helpers;

class Manager
{
    private static $containers = array();

    private static function defaults()
    {
        $newsfeed = new Item();
        $newsfeed->setPriority(1)
            ->setIcon('home')
            ->setName('Newsfeed')
            ->setTitle('Newsfeed')
            ->setPath('newsfeed');
        self::add($newsfeed);

        $capture = new Item();
        $capture->setPriority(2)
            ->setIcon('file_upload')
            ->setName('Capture')
            ->setTitle('Capture')
            ->setPath('capture');
        //self::add($capture);

        $discovery_trending = new Item();
        $discovery_trending
            ->setPriority(2)
            ->setIcon('trending_up')
            ->setName('Trending')
            ->setTitle('Trending')
            ->setPath('discovery/trending');

        $discovery_suggested = new Item();
        $discovery_suggested
            ->setPriority(1)
            ->setIcon('call_split')
            ->setName('Suggested')
            ->setTitle('Suggested')
            ->setPath('discovery/suggested')
            ->setVisibility(0); //only show for loggedin

        $discovery_featured = new Item();
        $discovery_featured
            ->setPriority(3)
            ->setIcon('star')
            ->setName('Featured')
            ->setTitle('Featured')
            ->setPath('discovery/featured');
        $discovery_my = new Item();
        $discovery_my
            ->setPriority(4)
            ->setIcon('person_pin')
            ->setName('My Media')
            ->setTitle('My Media')
            ->setPath('discovery/owner')
            ->setVisibility(0); //only show for loggedin

        $discovery = new Item();
        $discovery->setPriority(3)
            ->setIcon('search')
            ->setName('Discovery')
            ->setTitle('Discovery')
            ->setPath('discovery/trending/channels')
            ->addSubItem($discovery_trending)
            ->addSubItem($discovery_suggested)
            ->addSubItem($discovery_featured)
            ->addSubItem($discovery_my);
        self::add($discovery);

        $admin = new Item();
        $admin->setPriority(100)
            ->setIcon('settings_input_component')
            ->setName('Admin')
            ->setTitle('Admin')
            ->setPath('admin/analytics')
            ->setVisibility(-1)
            ->addSubItem((new Item())
                ->setPriority(1)
                ->setIcon('trending_up')
                ->setName('Boost')
                ->setTitle('Boost (Admin)')
                ->setPath('admin/boosts')
            )
            ->addSubItem((new Item())
                ->setPriority(2)
                ->setIcon('insert_chart')
                ->setName('Analytics')
                ->setTitle('Analytics')
                ->setPath('admin/analytics')
            )
            ->addSubItem((new Item())
                ->setPriority(3)
                ->setIcon('create')
                ->setName('Pages')
                ->setTitle('Pages')
                ->setPath('admin/pages')
            )
            ->addSubItem((new Item())
                ->setPriority(4)
                ->setIcon('flag')
                ->setName('Reports')
                ->setTitle('Reports')
                ->setPath('admin/reports')
            )
            ->addSubItem((new Item())
                ->setPriority(5)
                ->setIcon('attach_money')
                ->setName('Monetization review')
                ->setTitle('Monetization review')
                ->setPath('admin/monetization')
            )
            ->addSubItem((new Item())
                ->setPriority(6)
                ->setIcon('queue')
                ->setName('Program applications')
                ->setTitle('Program applications')
                ->setPath('admin/programs')
            )
            ->addSubItem((new Item())
                ->setPriority(7)
                ->setIcon('branding_watermark')
                ->setName('Payouts queue')
                ->setTitle('Payouts queue')
                ->setPath('admin/payouts')
            )
            ->addSubItem((new Item())
                ->setPriority(8)
	              ->setIcon('star')
                ->setName('Featured')
                ->setTitle('Featured')
                ->setPath('admin/featured')
            )
            ->addSubItem((new Item())
                ->setPriority(9)
                ->setIcon('whatshot')
                ->setName('Hashtags')
                ->setTitle('Hashtags')
                ->setPath('admin/tagcloud')
            )
            ->addSubItem((new Item())
                ->setPriority(10)
                ->setIcon('verified_user')
                ->setName('Verfiy')
                ->setTitle('Verify requests')
                ->setPath('admin/verify')
            );
        self::add($admin);

        self::add((new Item())
            ->setPriority(2)
            ->setIcon('account_balance')
            ->setName('Wallet')
            ->setTitle('Wallet')
            ->setPath('wallet')
            ->setExtras(array(
                'counter' => (int) Core\Session::isLoggedIn() ? \Minds\Helpers\Counters::get(Core\Session::getLoggedinUser()->guid, 'points', false) : 0
            )),
            'topbar'
        );

        self::add((new Item())
            ->setPriority(1)
            ->setIcon('notifications')
            ->setName('Notifications')
            ->setTitle('Notifications')
            ->setPath('notifications')
            ->setExtras(array(
                'counter' => (new Core\Notification\Counters())->getCount()
            )),
            'topbar'
        );

        self::add((new Item())
            ->setPriority(10)
            ->setIcon('help_outline')
            ->setName('Support')
            ->setTitle('Help & Support Group')
            ->setPath('groups/profile/100000000000000681/activity')
            //'topbar'
        );

        Core\Pages\Menu::_()->init();
    }

    /**
     * Add an item to the Navigation
     * @param Item $item - the item to add to the navigation
     * @param string $container - the container to add the item to
     * @return void
     */
    public static function add($item, $container = "sidebar")
    {
        if ($item instanceof Item) {
            self::getContainer($container)->add($item);
        }
    }

    /**
     * Indepotent get or create container
     * @param string $container - the name or ID of the container
     * @return Container
     */
    private static function getContainer($container)
    {
        if (!isset(self::$containers[$container])) {
            self::$containers[$container] = new Container();
        }
        return self::$containers[$container];
    }

    /**
     * Return items
     * @param string $container - the container to export
     * @return array
     */
    public static function export($container = null)
    {
        self::defaults();

        /* Initialize modules  */
        (new Core\Groups\Navigation())->setup();

        $containers = array();

        foreach (self::$containers as $id => $container) {
            $containers[$id] = $container->export();
        }
        return $containers;
    }
}
