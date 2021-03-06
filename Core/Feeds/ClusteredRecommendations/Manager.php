<?php

namespace Minds\Core\Feeds\ClusteredRecommendations;

use Exception;
use Generator;
use Minds\Common\Repository\Response;
use Minds\Common\Urn;
use Minds\Core\EntitiesBuilder;
use Minds\Core\Feeds\FeedSyncEntity;
use Minds\Core\Recommendations\UserRecommendationsCluster;
use Minds\Entities\User;

/**
 *  Manager class to handle clustered recommendations feed's logic
 */
class Manager
{
    private User $user;

    public function __construct(
        private ?Repository $repository = null,
        private ?EntitiesBuilder $entitiesBuilder = null,
        private ?UserRecommendationsCluster $userRecommendationsCluster = null
    ) {
        $this->repository ??= new Repository();
        $this->entitiesBuilder ??= new EntitiesBuilder();
        $this->userRecommendationsCluster ??= new UserRecommendationsCluster();
    }

    /**
     * Sets the user
     * @param User $user
     * @return $this
     */
    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Gets the list of entities based on the clustered recommendations ES index
     * @param int $limit
     * @return Response
     * @throws Exception
     */
    public function getList(int $limit): Response
    {
        $clusterId = $this->userRecommendationsCluster->calculateUserRecommendationsClusterId($this->user);

        $entries = $this->repository->getList($clusterId, $limit);
        $feedSyncEntities = $this->prepareFeedSyncEntities($entries);
        $preparedEntities = $this->prepareEntities($feedSyncEntities);

        $paginationToken = $this->getPaginationToken($feedSyncEntities);

        $response = new Response($preparedEntities);
        $response->setPagingToken($paginationToken ?: '');

        return $response;
    }

    /**
     * Parses response from repository and return an array of FeedSyncEntities
     * @param Generator $entries
     * @return FeedSyncEntity[]
     * @throws Exception
     */
    private function prepareFeedSyncEntities(Generator $entries): array
    {
        $feedSyncEntities = [];

        foreach ($entries as $scoredGuid) {
            $ownerGuid = $scoredGuid->getOwnerGuid() ?: $scoredGuid->getGuid();
            $entityType = $scoredGuid->getType() ?? 'entity';

            $urn = implode(':', [
                'urn',
                $entityType ?: 'entity',
                $scoredGuid->getGuid()
            ]);

            $feedSyncEntities[] = (new FeedSyncEntity())
                ->setGuid((string) $scoredGuid->getGuid())
                ->setOwnerGuid((string) $ownerGuid)
                ->setUrn(new Urn($urn))
                ->setTimestamp($scoredGuid->getTimestamp());
        }

        return $feedSyncEntities;
    }

    /**
     * Prepares final array, hydrating the top 12 entities
     * @param FeedSyncEntity[] $feedSyncEntities
     * @return FeedSyncEntity[]
     */
    private function prepareEntities(array $feedSyncEntities): array
    {
        if (count($feedSyncEntities) == 0) {
            return [];
        }

        $entities = [];

        $hydrateGuids = array_map(function (FeedSyncEntity $feedSyncEntity) {
            return $feedSyncEntity->getGuid();
        }, array_slice($feedSyncEntities, 0, 12)); // hydrate the first 12

        $hydratedEntities = $this->entitiesBuilder->get(['guids' => $hydrateGuids]);

        foreach ($hydratedEntities as $entity) {
            $entities[] = (new FeedSyncEntity)
                ->setGuid($entity->getGuid())
                ->setOwnerGuid($entity->getOwnerGuid())
                ->setUrn($entity->getUrn())
                ->setEntity($entity);
        }

        foreach (array_slice($feedSyncEntities, 12) as $entity) {
            $entities[] = $entity;
        }

        return $entities;
    }

    /**
     * Gets the pagination token to return with the list of entities so that the FE knows if it should fetch more rows
     * @param array $feedSyncEntities
     * @return string
     */
    private function getPaginationToken(array $feedSyncEntities): string
    {
        return (string) (
            array_reduce(
                $feedSyncEntities,
                function ($carry, FeedSyncEntity $feedSyncEntity) {
                    return min($feedSyncEntity->getTimestamp() ?: INF, $carry);
                },
                INF
            ) - 1
        );
    }
}
