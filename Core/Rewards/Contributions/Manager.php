<?php
/**
 * Syncs a users contributions
 */
namespace Minds\Core\Rewards\Contributions;

use Minds\Core\Analytics;
use Minds\Core\Util\BigNumber;

class Manager
{
    protected $analytics;
    protected $repository;
    protected $user;

    /** @var int */
    protected $from;

    /** @var int */
    protected $to;

    /** @var bool */
    protected $dryRun = false;

    /** @var array */
    protected $site_contribtion_score_cache = [];

    /** @var Sums */
    protected $sums;

    public function __construct($analytics = null, $repository = null, $sums = null)
    {
        $this->analytics = $analytics ?: new Analytics\Manager();
        $this->repository = $repository ?: new Repository;
        $this->sums = $sums ?: new Sums;
        $this->from = strtotime('-7 days') * 1000;
        $this->to = time() * 1000;
    }

    public function setUser($user)
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Sets if to dry run or not. A dry run will return the data but will save
     * to the database
     * @param bool $dryRun
     * @return $this
     */
    public function setDryRun($dryRun)
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    public function setFrom($from)
    {
        $this->from = $from;
        return $this;
    }

    public function setTo($to)
    {
        $this->to = $to;
        return $this;
    }

    public function sync()
    {
        $this->analytics
            ->setFrom($this->from)
            ->setTo($this->to)
            ->setInterval('day')
            ->setOnlyPlus(false);

        if ($this->user) {
            $this->analytics
                ->setUser($this->user);
        }

        $contributions = [];
        foreach ($this->analytics->getCounts() as $ts => $data) {
            foreach ($data as $metric => $count) {
                $multiplier = ContributionValues::$multipliers[$metric];
                $contribution = new Contribution();
                $contribution->setMetric($metric)
                    ->setTimestamp($ts)
                    ->setScore($count * $multiplier)
                    ->setAmount($count);

                if ($this->user) {
                    $contribution->setUser($this->user);
                }
                $contributions[] = $contribution;
            }
        }


        if ($this->dryRun) {
            return $contributions;
        }

        $this->repository->add($contributions);

        return $contributions;
    }

    /**
     * Add a contibution score row manually
     * @param Contribution $contribution
     * @return bool
     */
    public function add(Contribution $contribution)  : bool
    {
        return (bool) $this->repository->add($contribution);
    }

    public function issueCheckins($count)
    {
        $multiplier = ContributionValues::$multipliers['checkin'];
        $contribution = new Contribution();
        $contribution->setMetric('checkins')
            ->setTimestamp($this->from)
            ->setScore($count * $multiplier)
            ->setAmount($count);

        $contribution->setUser($this->user);
        $this->repository->add($contribution);
    }

    /**
     * Gather the entire site contribution score
     */
    public function getSiteContribtionScore()
    {
        if (isset($this->site_contribtion_score_cache[$this->from])) {
            return $this->site_contribtion_score_cache[$this->from];
        }
        return $this->site_contribtion_score_cache[$this->from] = $this->sums
            ->setTimestamp($this->from)
            ->setUser(null)
            ->getScore();
    }

    /**
     * Gather the contribution score for the user
     * @return int
     */
    public function getUserContributionScore()
    {
        return $this->sums
            ->setTimestamp($this->from)
            ->setUser($this->user)
            ->getScore();
    }

    /**
     * Return the number of tokens to be rewarded
     * @return string
     */
    public function getRewardsAmount()
    {
        if ($this->getUserContributionScore() <= 0) {
            return "0"; // Do not issue negative tokens
        }
        
        $tokensPerScore = BigNumber::_(pi())->mul(10 ** 18)->div(200);
        $tokens = BigNumber::_($this->getUserContributionScore())->mul($tokensPerScore);
        return (string) $tokens;
    }

    /**
     * Returns contributions summaries for users
     * @return ContributionSummary[]
     */
    public function getSummaries(ContributionQueryOpts $opts): iterable
    {
        return $this->repository->getSummaries($opts);
    }
}
