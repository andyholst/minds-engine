<?php
/**
 * Manager
 * @author edgebal
 */

namespace Minds\Core\Boost\Campaigns;

class Manager
{
    /** @var Repository  */
    protected $repository;

    /** @var Delegates\CampaignUrnDelegate */
    protected $campaignUrnDelegate;

    /** @var Delegates\NormalizeDatesDelegate */
    protected $normalizeDatesDelegate;

    /** @var Delegates\NormalizeEntityUrnsDelegate */
    protected $normalizeEntityUrnsDelegate;

    /** @var Delegates\NormalizeHashtagsDelegate */
    protected $normalizeHashtagsDelegate;

    /** @var Delegates\BudgetDelegate */
    protected $budgetDelegate;

    /**
     * Manager constructor.
     * @param Repository $repository
     * @param Delegates\CampaignUrnDelegate $campaignUrnDelegate
     * @param Delegates\NormalizeDatesDelegate $normalizeDatesDelegate
     * @param Delegates\NormalizeEntityUrnsDelegate $normalizeEntityUrnsDelegate
     * @param Delegates\NormalizeHashtagsDelegate $normalizeHashtagsDelegate
     * @param Delegates\BudgetDelegate $budgetDelegate
     */
    public function __construct(
        $repository = null,
        $campaignUrnDelegate = null,
        $normalizeDatesDelegate = null,
        $normalizeEntityUrnsDelegate = null,
        $normalizeHashtagsDelegate = null,
        $budgetDelegate = null
    )
    {
        $this->repository = $repository ?: new Repository();

        // Delegates

        $this->campaignUrnDelegate = $campaignUrnDelegate ?: new Delegates\CampaignUrnDelegate();
        $this->normalizeDatesDelegate = $normalizeDatesDelegate ?: new Delegates\NormalizeDatesDelegate();
        $this->normalizeEntityUrnsDelegate = $normalizeEntityUrnsDelegate ?: new Delegates\NormalizeEntityUrnsDelegate();
        $this->normalizeHashtagsDelegate = $normalizeHashtagsDelegate ?: new Delegates\NormalizeHashtagsDelegate();
        $this->budgetDelegate = $budgetDelegate ?: new Delegates\BudgetDelegate();
    }

    /**
     * @param Campaign $campaign
     * @return Campaign
     * @throws CampaignException
     */
    public function create(Campaign $campaign)
    {
        $campaign = $this->campaignUrnDelegate->onCreate($campaign);

        // Validate that there's an owner

        if (!$campaign->getOwnerGuid()) {
            throw new CampaignException('Campaign should have an owner');
        }

        // Validate that there's a name

        if (!$campaign->getName()) {
            throw new CampaignException('Campaign should have a name');
        }

        // Validate type

        $validTypes = ['newsfeed', 'content', 'banner', 'video'];

        if (!in_array($campaign->getType(), $validTypes)) {
            throw new CampaignException('Invalid campaign type');
        }

        $campaign = $this->normalizeDatesDelegate->onCreate($campaign);
        $campaign = $this->normalizeEntityUrnsDelegate->onCreate($campaign);
        $campaign = $this->normalizeHashtagsDelegate->onCreate($campaign);
        $campaign = $this->budgetDelegate->onCreate($campaign); // Should be ALWAYS called after normalizing dates

        //

        $done = $this->repository->add($campaign);

        // TODO: Assign ->setBoost()

        if (!$done) {
            throw new CampaignException('Cannot save campaign');
        }

        return $campaign;
    }

    /**
     * @param Campaign $campaign
     * @return Campaign
     * @throws CampaignException
     */
    public function update(Campaign $campaign)
    {
        $campaign = $this->campaignUrnDelegate->onUpdate($campaign, null);

        // TODO: Check that campaign exists
        // TODO: Load old campaign for comparison, compare owners!!
        $oldCampaign = new Campaign();

        // Validate that there's an owner

        if (!$campaign->getOwnerGuid()) {
            throw new CampaignException('Campaign should have an owner');
        }

        // Validate that there's a name

        if (!$campaign->getName()) {
            throw new CampaignException('Campaign should have a name');
        }

        // Validate that type didn't change

        if ($campaign->getType() !== $oldCampaign->getType()) {
            throw new CampaignException('Campaigns cannot change types after created');
        }

        // Normalize and validate dates

        $campaign = $this->normalizeDatesDelegate->onUpdate($campaign, $oldCampaign);
        $campaign = $this->normalizeEntityUrnsDelegate->onUpdate($campaign, $oldCampaign);
        $campaign = $this->normalizeHashtagsDelegate->onUpdate($campaign, $oldCampaign);
        $campaign = $this->budgetDelegate->onUpdate($campaign, $oldCampaign); // Should be ALWAYS called after normalizing dates

        $done = $this->repository->update($campaign);

        // TODO: Assign ->setBoost()

        if (!$done) {
            throw new CampaignException('Cannot save campaign');
        }

        return $campaign;
    }
}
