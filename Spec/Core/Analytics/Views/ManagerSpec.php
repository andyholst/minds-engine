<?php

namespace Spec\Minds\Core\Analytics\Views;

use Minds\Core\Analytics\Views\Manager;
use Minds\Core\Analytics\Views\Repository;
use Minds\Core\Analytics\Views\View;
use Minds\Core\Feeds\UnseenTopFeed\Manager as UnseenTopFeedManager;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class ManagerSpec extends ObjectBehavior
{
    /** @var Repository */
    protected $repository;

    /** @var UnseenTopFeedManager */
    protected $unseenTopFeedManager;

    public function let(
        Repository $repository,
        UnseenTopFeedManager $unseenTopFeedManager,
    ) {
        $this->beConstructedWith($repository, null, $unseenTopFeedManager);
        $this->repository = $repository;
        $this->unseenTopFeedManager = $unseenTopFeedManager;
    }

    public function it_is_initializable()
    {
        $this->shouldHaveType(Manager::class);
    }

    public function it_should_record(
        View $view
    ) {
        $view->setYear(null)
            ->shouldBeCalled()
            ->willReturn($view);

        $view->setMonth(null)
            ->shouldBeCalled()
            ->willReturn($view);

        $view->setDay(null)
            ->shouldBeCalled()
            ->willReturn($view);

        $view->setUuid(null)
            ->shouldBeCalled()
            ->willReturn($view);

        $view->setTimestamp(Argument::type('int'))
            ->shouldBeCalled()
            ->willReturn($view);

        $view->getEntityUrn()
            ->shouldBeCalled()
            ->willReturn("urn:activity:fakeguid");

        $this->unseenTopFeedManager->seeEntities(["fakeguid"])
            ->shouldBeCalled();

        $this->repository->add($view)
            ->shouldBeCalled()
            ->willReturn(true);

        $this
            ->record($view)
            ->shouldReturn(true);
    }
}
