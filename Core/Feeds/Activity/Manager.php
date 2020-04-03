<?php
/**
 * Manager
 * @author edgebal
 */

namespace Minds\Core\Feeds\Activity;

use Minds\Entities\Activity;
use Minds\Common\EntityMutation;
use Minds\Core\Entities\Actions\Save;
use Minds\Core\Entities\PropagateProperties;

class Manager
{
    /** @var Delegates\ForeignEntityDelegate */
    private $foreignEntityDelegate;

    /** @var Delegates\TranslationsEntityDelegate */
    private $translationsDelegate;

    /** @var Delegates\AttachmentDelegate */
    private $attachmentDelegate;

    /** @var Delegates\TimeCreatedDelegate */
    private $timeCreatedDelegate;

    /** @var Delegates\VideoPosterDelegate */
    private $videoPosterDelegate;

    /** @var Save */
    private $save;

    /** @var PropagateProperties */
    private $propagateProperties;

    public function __construct(
        $foreignEntityDelegate = null,
        $translationsDelegate = null,
        $attachmentDelegate = null,
        $timeCreatedDelegate = null,
        $save = null,
        $propagateProperties = null,
        $videoPosterDelegate = null
    ) {
        $this->foreignEntityDelegate = $foreignEntityDelegate ?? new Delegates\ForeignEntityDelegate();
        $this->translationsDelegate = $translationsDelegate ?? new Delegates\TranslationsDelegate();
        $this->attachmentDelegate = $attachmentDelegate ?? new Delegates\AttachmentDelegate();
        $this->timeCreatedDelegate = $timeCreatedDelegate ?? new Delegates\TimeCreatedDelegate();
        $this->save = $save ?? new Save();
        $this->propagateProperties = $propagateProperties ?? new PropagateProperties();
        $this->videoPosterDelegate = $videoPosterDelegate ?? new Delegates\VideoPosterDelegate();
    }

    /**
     * Update the activity entity
     */
    public function update(EntityMutation $activityMutation): void
    {
        $activity = $activityMutation->getMutatedEntity();

        if ($activity->type !== 'activity' && in_array($activity->subtype, [
            'video', 'image'
        ], true)) {
            $this->foreignEntityDelegate->onUpdate($activity, $activityMutation);
            return;
        }

        if ($activity->type !== 'activity') {
            throw new \Exception('Invalid entity type');
        }

        if (!$activity->canEdit()) {
            throw new \Exception('Invalid permission to edit this activity post');
        }

        $activity->setEdited(true);

        $activity->indexes = ["activity:$activity->owner_guid:edits"]; //don't re-index on edit
        
        $this->translationsDelegate->onUpdate($activity);

        if ($activityMutation->hasMutated('timeCreated')) {
            $this->timeCreatedDelegate->onUpdate($activityMutation->getOriginalEntity(), $activity->getTimeCreated(), $activity->getTimeSent());
        }

        // Attachment and rich embed

        // An activity can be updated ONLY if doesn't have either entity or URL
        $canUpdateEntity = !$activity->getEntityGuid() || !$activity->getURL();

        if ($canUpdateEntity) {

            // - Attachment

            if ($activityMutation->hasMutated('entityGuid')) {
                // Edit the attachment, if needed
                $activity = $this->attachmentDelegate
                    ->setActor(Core\Session::getLoggedinUser())
                    ->onEdit($activity, (string) $activity->getEntityGuid());

                // Clean rich embed
                $activity
                    //->setTitle('')
                    ->setBlurb('')
                    ->setURL('')
                    ->setThumbnail('');

                if (!$activityMutation->hasMutated('title')) {
                    $activity->setTitle('');
                }
            }

            if ($activityMutation->hasMutated('videoPosterBase64Blob')) {
                $this->videoPosterDelegate->onUpdate($activity, $activityMutation);
            }
        }

        $this->save
            ->setEntity($activity)
            ->save();

        $this->propagateProperties->from($activity);
    }
}
