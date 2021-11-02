<?php
namespace Minds\Core\Media\Video\Transcoder;

use Minds\Core\Entities\Actions\Save;
use Minds\Entities\Video;
use Minds\Core\Media\Video\CloudflareStreams;

class TranscodeStates
{
    /** @var string */
    public const CREATED = 'created';

    /** @var string */
    public const TRANSCODING = 'transcoding';

    /** @var string */
    public const FAILED = 'failed';

    /** @var string */
    public const COMPLETED = 'completed';

    /** @var string */
    public const QUEUED = 'queued'; // only used by YouTubeImporter

    /** @var Repository */
    private $repository;

    /** @var Save */
    private $save;

    /** @var CloudflareStreams\Manager */
    private $cloudflareStreamsManager;

    public function __construct($repository = null, $save, $cloudflareStreamsManager)
    {
        // NOTE: We are using repository as this is called via
        // Delegates\NotificationDelegate and it causes an infinite loop
        // with the manager
        $this->repository = $repository ?? new Repository();
        $this->save = $save ?? new Save();
        $this->cloudflareStreamsManager = $cloudflareStreamsManager ?? new CloudflareStreams\Manager();
    }

    /**
     * Return the overral transcoding status
     * MH: I don't love this function at all!
     * @param Video $video
     * @return string
     */
    public function getStatus(Video $video): string
    {
        switch ($video->getTranscoder()) {
            case \Minds\Core\Media\Video\Manager::TRANSCODER_CLOUDFLARE:
                return $this->getCloudflareTranscodeStatus($video)->getState();
            case \Minds\Core\Media\Video\Manager::TRANSCODER_MINDS:
            default:
                 return $this->getMindsTranscoderStatus($video);
        }
    }

    /**
     * @param Video $video
     * @return CloudflareStreams\TranscodeStatus the transcode status
     */
    protected function getCloudflareTranscodeStatus(Video $video): object
    {
        // if the status was completed, just return completed
        if ($video->getTranscodingStatus() === TranscodeStates::COMPLETED) {
            return (new CloudflareStreams\TranscodeStatus())
                ->setPct(100)
                ->setState(TranscodeStates::COMPLETED);
        }
        
        // get video transcode status from cloudflare and save it in db
        $transcodeStatus = $this->cloudflareStreamsManager->getVideoTranscodeStatus($video);
        $video->patch([
            'transcoding_status' => $transcodeStatus->getState(),
        ]);
        
        // disable acl and set it back to what it was after saving
        $ia = $this->acl->setIgnore(true);
        $this->save
            ->setEntity($video)
            ->save();
        $this->acl->setIgnore($ia);

        return $transcodeStatus;
    }

    /**
     * @param Video $video
     * @return string the transcode state
     */
    protected function getMindsTranscoderStatus(Video $video): string
    {
        $transcodes = $this->repository->getList([
            'guid' => $video->getGuid(),
        ]);

        $total = 0;
        $created = 0;
        $failures = 0;
        $completed = 0;

        foreach ($transcodes as $transcode) {
            if ($transcode instanceof TranscodeProfiles\Thumbnails) {
                continue; // We skip thumbnails as these are likely to succeed
            }
            ++$total;
            switch ($transcode->getStatus()) {
                case TranscodeStates::TRANSCODING:
                    if ($transcode->getLastEventTimestampMs() >= (time() - Manager::TRANSCODER_TIMEOUT_SECS) * 1000) {
                        // Still transcoding
                        return TranscodeStates::TRANSCODING;
                    } else {
                        ++$failures;
                    }
                    break;
                case TranscodeStates::CREATED:
                    // If not started to transcode then we are in a created state
                    ++$created;
                    break;
                case TranscodeStates::FAILED:
                    ++$failures;
                    // We should allow failures for some transcodes
                    break;
                case TranscodeStates::COMPLETED:
                    ++$completed;
                    break;
            }
        }

        if ($created > ($completed + $failures)) {
            return TranscodeStates::CREATED;
        }

        if ($total < ($completed + $failures)) {
            return TranscodeStates::CREATED;
        }

        // If we have more completions then failures the declare completed
        if ($failures < $completed) {
            return TranscodeStates::COMPLETED;
        }

        return TranscodeStates::FAILED; // Our default state is failed?
    }
}
