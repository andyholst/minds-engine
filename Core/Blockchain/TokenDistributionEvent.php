<?php

/**
 * Token Distribution Event Manager
 *
 * @author emi
 */

namespace Minds\Core\Blockchain;

use Minds\Core\Di\Di;

class TokenDistributionEvent
{
    protected $manager;
    protected $client;

    protected $tokenDistributionEventAddress;

    /**
     * TokenDistributionEvent constructor.
     * @param null $manager
     * @param null $client
     * @throws \Exception
     */
    public function __construct($manager = null, $client = null)
    {
        $this->manager = $manager ?: Di::_()->get('Blockchain\Manager');
        $this->client = $client ?: Di::_()->get('Blockchain\Services\Ethereum');

        if (!$contract = $this->manager->getContract('token_distribution_event')) {
            throw new \Exception('No token distribution event set');
        }

        $this->tokenDistributionEventAddress = $contract->getAddress();
    }

    /**
     * Gets the token <-> eth exchange rate
     * @return double
     */
    public function rate()
    {
        $result = $this->client->call($this->tokenDistributionEventAddress, 'rate()', []);

        return (double) Util::toDec($result);
    }

    /**
     * Gets the total of ETH raised
     * @return double
     */
    public function raised()
    {
        $result = $this->client->call($this->tokenDistributionEventAddress, 'weiRaised()', []);

        return (double) Util::toDec($result) / (10 ** 18);
    }

    /**
     * Gets the end time of the event
     * @return double
     */
    public function endTime()
    {
        $result = $this->client->call($this->tokenDistributionEventAddress, 'endTime()', []);

        return (int) Util::toDec($result);
    }
}
