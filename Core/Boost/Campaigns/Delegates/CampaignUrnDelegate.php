<?php

namespace Minds\Core\Boost\Campaigns\Delegates;

use Minds\Core\Boost\Campaigns\Campaign;
use Minds\Core\Boost\Campaigns\CampaignException;
use Minds\Core\Di\Di;
use Minds\Core\GuidBuilder;

class CampaignUrnDelegate
{
    /** @var GuidBuilder */
    protected $guid;

    /**
     * CampaignUrnDelegate constructor.
     * @param GuidBuilder $guid
     */
    public function __construct(?GuidBuilder $guid = null)
    {
        $this->guid = $guid ?: Di::_()->get('Guid');
    }

    /**
     * @param Campaign $campaign
     * @return Campaign
     * @throws CampaignException
     */
    public function onCreate(Campaign $campaign): Campaign
    {
        if ($campaign->getUrn()) {
            throw new CampaignException('Campaign already has an URN');
        }

        $guid = $this->guid->build();
        return $campaign->setUrn("urn:campaign:{$guid}");
    }
}
