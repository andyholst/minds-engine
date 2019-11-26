<?php
/**
 * BoostGuidResolverDelegate.
 *
 * @author emi
 */

namespace Minds\Core\Entities\Delegates;

use Minds\Common\Urn;
use Minds\Core\Boost\Repository;
use Minds\Core\Di\Di;
use Minds\Core\Entities\Resolver;
use Minds\Core\EntitiesBuilder;
use Minds\Entities\Boost\BoostEntityInterface;

class BoostGuidResolverDelegate implements ResolverDelegate
{
    /**
     * @var Manager
     */
    protected $manager;

    /** @var Resolver */
    protected $resolver;

    /**
     * BoostGuidResolverDelegate constructor.
     * @param Manager $manager
     */
    public function __construct($manager = null)
    {
        $this->manager = $manager ?: Di::_()->get('Boost\Network\Manager');
    }

    /**
     * @param Resolver $resolver
     * @return BoostGuidResolverDelegate
     */
    public function setResolver(Resolver $resolver)
    {
        $this->resolver = $resolver;
        return $this;
    }

    /**
     * @param Urn $urn
     * @return boolean
     */
    public function shouldResolve(Urn $urn)
    {
        return $urn->getNid() === 'boost';
    }

    /**
     * @param array $urns
     * @param array $opts
     * @return mixed
     */
    public function resolve(array $urns, array $opts = [])
    {
        $entities = [];

        foreach ($urns as $urn) {
            /** @var BoostEntityInterface $boost */
            $boost = $this->manager->get($urn, [ 'hydrate' => true ]);

            $entities[] = $boost;
        }

        return $entities;
    }

    /**
     * @param BoostEntityInterface $entity
     * @return mixed
     */
    public function map($urn, $entity)
    {
        $boostedEntity = $entity ? $entity->getEntity() : null;

        if ($boostedEntity) {
            $boostedEntity->boosted = true;
            $boostedEntity->boosted_guid = $entity->getGuid();
            $boostedEntity->boosted_onchain = $entity->isOnChain();
            $boostedEntity->urn = $urn;
        }

        return $boostedEntity;
    }

    /**
     * @param BoostEntityInterface $entity
     * @return string|null
     */
    public function asUrn($entity)
    {
        if (!$entity) {
            return null;
        }

        return "urn:boost:{$entity->getType()}:{$entity->getGuid()}";
    }
}
