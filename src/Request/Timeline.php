<?php

namespace InstagramAPI\Request;

use InstagramAPI\Constants;
use InstagramAPI\Request\Metadata\Internal as InternalMetadata;
use InstagramAPI\Response;
use InstagramAPI\Utils;

/**
 * Functions for managing your timeline and interacting with other timelines.
 *
 * @see Media for more functions that let you interact with the media.
 * @see Usertag for functions that let you tag people in media.
 */
class Timeline extends RequestCollection
{
    /**
     * Uploads a photo to your Instagram timeline.
     *
     * @param string $photoFilename    The photo filename.
     * @param array  $externalMetadata (optional) User-provided metadata key-value pairs.
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\ConfigureResponse
     *
     * @see Internal::configureSinglePhoto() for available metadata fields.
     */
    public function uploadPhoto(
        $photoFilename,
        array $externalMetadata = [])
    {
        return $this->ig->internal->uploadSinglePhoto('timeline', $photoFilename, null, $externalMetadata);
    }

    /**
     * Uploads a video to your Instagram timeline.
     *
     * @param string $videoFilename    The video filename.
     * @param array  $externalMetadata (optional) User-provided metadata key-value pairs.
     *
     * @throws \InvalidArgumentException
     * @throws \InstagramAPI\Exception\InstagramException
     * @throws \InstagramAPI\Exception\UploadFailedException If the video upload fails.
     *
     * @return \InstagramAPI\Response\ConfigureResponse
     *
     * @see Internal::configureSingleVideo() for available metadata fields.
     */
    public function uploadVideo(
        $videoFilename,
        array $externalMetadata = [])
    {
        return $this->ig->internal->uploadSingleVideo('timeline', $videoFilename, null, $externalMetadata);
    }

    /**
     * Uploads an album to your Instagram timeline.
     *
     * An album is also known as a "carousel" and "sidecar". They can contain up
     * to 10 photos or videos (at the moment).
     *
     * @param array $media            Array of image/video files and their per-file
     *                                metadata (type, file, and optionally
     *                                usertags). The "type" must be "photo" or
     *                                "video". The "file" must be its disk path.
     *                                And the optional "usertags" can only be
     *                                used on PHOTOS, never on videos.
     * @param array $externalMetadata (optional) User-provided metadata key-value pairs
     *                                for the album itself (its caption, location, etc).
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \InstagramAPI\Exception\InstagramException
     * @throws \InstagramAPI\Exception\UploadFailedException If the video upload fails.
     *
     * @return \InstagramAPI\Response\ConfigureResponse
     *
     * @see Internal::configureTimelineAlbum() for available album metadata fields.
     */
    public function uploadAlbum(
        array $media,
        array $externalMetadata = [])
    {
        if (empty($media)) {
            throw new \InvalidArgumentException("List of media to upload can't be empty.");
        }
        if (count($media) < 2 || count($media) > 10) {
            throw new \InvalidArgumentException(sprintf(
                'Instagram requires that albums contain 2-10 items. You tried to submit %d.',
                count($media)
            ));
        }

        // Figure out the media file details for ALL media in the album.
        // NOTE: We do this first, since it validates whether the media files are
        // valid and lets us avoid wasting time uploading totally invalid albums!
        foreach ($media as $key => $item) {
            if (!isset($item['file']) || !isset($item['type'])) {
                throw new \InvalidArgumentException(sprintf(
                    'Media at index "%s" does not have the required "file" and "type" keys.',
                    $key
                ));
            }

            $itemInternalMetadata = new InternalMetadata();

            // If usertags are provided, verify that the entries are valid.
            if (isset($item['usertags'])) {
                Utils::throwIfInvalidUsertags($item['usertags']);
            }

            // Pre-process media details and throw if not allowed on Instagram.
            switch ($item['type']) {
            case 'photo':
                // Determine the photo details.
                $itemInternalMetadata->setPhotoDetails('album', $item['file']);
                break;
            case 'video':
                // Determine the video details.
                $itemInternalMetadata->setVideoDetails('album', $item['file']);
                break;
            default:
                throw new \InvalidArgumentException(sprintf('Unsupported album media type "%s".', $item['type']));
            }

            $media[$key]['internalMetadata'] = $itemInternalMetadata;
        }

        // Perform all media file uploads.
        foreach ($media as $key => $item) {
            /** @var InternalMetadata $itemInternalMetadata */
            $itemInternalMetadata = $media[$key]['internalMetadata'];

            switch ($item['type']) {
            case 'photo':
                $itemInternalMetadata->setPhotoUploadResponse($this->ig->internal->uploadPhotoData('album', $itemInternalMetadata));
                break;
            case 'video':
                // Attempt to upload the video data.
                $itemInternalMetadata = $this->ig->internal->uploadVideo('album', $item['file'], $itemInternalMetadata);

                // Attempt to upload the thumbnail, associated with our video's ID.
                $itemInternalMetadata->setPhotoUploadResponse($this->ig->internal->uploadPhotoData('album', $itemInternalMetadata));
            }

            $media[$key]['internalMetadata'] = $itemInternalMetadata;
        }

        // Generate an uploadId (via internal metadata) for the album.
        $albumInternalMetadata = new InternalMetadata();
        // Configure the uploaded album and attach it to our timeline.
        /** @var \InstagramAPI\Response\ConfigureResponse $configure */
        $configure = $this->ig->internal->configureWithRetries(
            'album',
            function () use ($media, $albumInternalMetadata, $externalMetadata) {
                return $this->ig->internal->configureTimelineAlbum($media, $albumInternalMetadata, $externalMetadata);
            }
        );

        return $configure;
    }

    /**
     * Get your "home screen" timeline feed.
     *
     * This is the feed of recent timeline posts from people you follow.
     *
     * @param null|string $maxId   Next "maximum ID", used for pagination.
     * @param null|array  $options An associative array with following keys (all of them are optional):
     *                             "latest_story_pk" The media ID in Instagram's internal format (ie "3482384834_43294").
     *                             "seen_posts" One or more seen media IDs.
     *                             "unseen_posts" One or more unseen media IDs.
     *                             "is_pull_to_refresh" Whether this call was triggered by refresh.
     *                             "push_disabled" Whether user has disabled PUSH.
     *                             "recovered_from_crash" Whether app has recovered from crash.
     *                             "feed_view_info" DON'T USE IT YET.
     *
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\TimelineFeedResponse
     */
    public function getTimelineFeed(
        $maxId = null,
        array $options = null)
    {
        $request = $this->ig->request('feed/timeline/')
            ->setSignedPost(false)
            ->addHeader('X-Ads-Opt-Out', '0')
            ->addHeader('X-Google-AD-ID', $this->ig->advertising_id)
            ->addHeader('X-DEVICE-ID', $this->ig->uuid)
            ->addPost('_csrftoken', $this->ig->client->getToken())
            ->addPost('_uuid', $this->ig->uuid)
            ->addPost('is_prefetch', '0')
            ->addPost('phone_id', $this->ig->settings->get('phone_id'))
            ->addPost('battery_level', '100')
            ->addPost('is_charging', '1')
            ->addPost('timezone_offset', date('Z'));

        if (isset($options['latest_story_pk'])) {
            $request->addPost('latest_story_pk', $options['latest_story_pk']);
        }

        if (isset($options['is_pull_to_refresh'])) {
            $request->addPost('is_pull_to_refresh', $options['is_pull_to_refresh'] ? '1' : '0');
        } else {
            $request->addPost('is_pull_to_refresh', '0');
        }

        if (isset($options['seen_posts'])) {
            if (is_array($options['seen_posts'])) {
                $request->addPost('seen_posts', implode(',', $options['seen_posts']));
            } else {
                $request->addPost('seen_posts', $options['seen_posts']);
            }
        } elseif ($maxId === null) {
            $request->addPost('seen_posts', '');
        }

        if (isset($options['unseen_posts'])) {
            if (is_array($options['unseen_posts'])) {
                $request->addPost('unseen_posts', implode(',', $options['unseen_posts']));
            } else {
                $request->addPost('unseen_posts', $options['unseen_posts']);
            }
        } elseif ($maxId === null) {
            $request->addPost('unseen_posts', '');
        }

        if (isset($options['feed_view_info'])) {
            if (is_array($options['feed_view_info'])) {
                $request->addPost('feed_view_info', json_encode($options['feed_view_info']));
            } else {
                $request->addPost('feed_view_info', json_encode([$options['feed_view_info']]));
            }
        } elseif ($maxId === null) {
            $request->addPost('feed_view_info', '');
        }

        if (isset($options['push_disabled']) && $options['push_disabled']) {
            $request->addPost('push_disabled', 'true');
        }

        if (isset($options['recovered_from_crash']) && $options['recovered_from_crash']) {
            $request->addPost('recovered_from_crash', '1');
        }

        if ($maxId) {
            $request->addPost('max_id', $maxId);
        } else {
            $request->addHeader('X-IG-INSTALLED-APPS', base64_encode(json_encode([
                '1' => 0, // com.instagram.boomerang
                '2' => 0, // com.instagram.layout
            ])));
        }

        return $request->getResponse(new Response\TimelineFeedResponse());
    }

    /**
     * Get a user's timeline feed.
     *
     * @param string      $userId       Numerical UserPK ID.
     * @param null|string $maxId        Next "maximum ID", used for pagination.
     * @param null|int    $minTimestamp Minimum timestamp.
     *
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\UserFeedResponse
     */
    public function getUserFeed(
        $userId,
        $maxId = null,
        $minTimestamp = null)
    {
        return $this->ig->request("feed/user/{$userId}/")
            ->addParam('rank_token', $this->ig->rank_token)
            ->addParam('ranked_content', 'true')
            ->addParam('max_id', (!is_null($maxId) ? $maxId : ''))
            ->addParam('min_timestamp', (!is_null($minTimestamp) ? $minTimestamp : ''))
            ->getResponse(new Response\UserFeedResponse());
    }

    /**
     * Get your own timeline feed.
     *
     * @param null|string $maxId        Next "maximum ID", used for pagination.
     * @param null|int    $minTimestamp Minimum timestamp.
     *
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\UserFeedResponse
     */
    public function getSelfUserFeed(
        $maxId = null,
        $minTimestamp = null)
    {
        return $this->getUserFeed($this->ig->account_id, $maxId, $minTimestamp);
    }

    /**
     * Get archived media feed.
     *
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\ArchivedMediaFeedResponse
     */
    public function getArchivedMediaFeed()
    {
        return $this->ig->request("feed/only_me_feed/")
            ->getResponse(new Response\UserFeedResponse());
    }

    /**
     * Archives or unarchives one of your timeline media items.
     *
     * Marking media as "archived" will hide it from everyone except yourself.
     * You can unmark the media again at any time, to make it public again.
     *
     * @param string $mediaId   The media ID in Instagram's internal format (ie "3482384834_43294").
     * @param string $mediaType Media type ("photo", "album" or "video").
     * @param bool   $onlyMe    If true, archives your media so that it's only visible to you.
     *                          Otherwise, if false, makes the media public to everyone again.
     *
     * @throws \InvalidArgumentException
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\ArchiveMediaResponse
     */
    public function archiveMedia(
        $mediaId,
        $mediaType,
        $onlyMe)
    {
        $endpoint = $onlyMe ? 'only_me' : 'undo_only_me';
        switch ($mediaType) {
            case 'photo':
                $mediaCode = 1;
                break;
            case 'video':
                $mediaCode = 2;
                break;
            case 'album':
                $mediaCode = 8;
                break;
            default:
                throw new \InvalidArgumentException('You must provide a valid media type.');
                break;
        }

        return $this->ig->request("media/{$mediaId}/{$endpoint}/?media_type={$mediaCode}")
            ->addPost('_uuid', $this->ig->uuid)
            ->addPost('_uid', $this->ig->account_id)
            ->addPost('_csrftoken', $this->ig->client->getToken())
            ->addPost('media_id', $mediaId)
            ->getResponse(new Response\ArchiveMediaResponse());
    }

    /**
     * Backup all of your own uploaded photos and videos. :).
     *
     * Note that the backup filenames contain the date and time that the media
     * was uploaded. It uses PHP's timezone to calculate the local time. So be
     * sure to use date_default_timezone_set() with your local timezone if you
     * want correct times in the filenames!
     *
     * @param string $baseOutputPath (optional) Base-folder for output.
     *                               Uses "backups/" path in lib dir if null.
     * @param bool   $printProgress  (optional) Toggles terminal output.
     *
     * @throws \RuntimeException
     * @throws \InstagramAPI\Exception\InstagramException
     */
    public function backup(
        $baseOutputPath = null,
        $printProgress = true)
    {
        // Decide which path to use.
        if ($baseOutputPath === null) {
            $baseOutputPath = Constants::SRC_DIR.'/../backups/';
        }

        // Ensure that the whole directory path for the backup exists.
        $backupFolder = $baseOutputPath.$this->ig->username.'/'.date('Y-m-d').'/';
        if (!Utils::createFolder($backupFolder)) {
            throw new \RuntimeException(sprintf(
                'The "%s" backup folder is not writable.',
                $backupFolder
            ));
        }

        // Download all media to the output folders.
        $nextMaxId = null;
        do {
            $myTimeline = $this->getSelfUserFeed($nextMaxId);

            // Build a list of all media files on this page.
            $mediaFiles = []; // Reset queue.
            foreach ($myTimeline->getItems() as $item) {
                $itemDate = date('Y-m-d \a\t H.i.s O', $item->getTakenAt());
                if ($item->media_type == Response\Model\Item::ALBUM) {
                    // Albums contain multiple items which must all be queued.
                    // NOTE: We won't name them by their subitem's getIds, since
                    // those Ids have no meaning outside of the album and they
                    // would just mean that the album content is spread out with
                    // wildly varying filenames. Instead, we will name all album
                    // items after their album's Id, with a position offset in
                    // their filename to show their position within the album.
                    $subPosition = 0;
                    foreach ($item->getCarouselMedia() as $subItem) {
                        ++$subPosition;
                        if ($subItem->media_type == Response\Model\CarouselMedia::PHOTO) {
                            $mediaUrl = $subItem->getImageVersions2()->candidates[0]->getUrl();
                        } else {
                            $mediaUrl = $subItem->getVideoVersions()[0]->getUrl();
                        }
                        $subItemId = sprintf('%s [%s-%02d]', $itemDate, $item->getId(), $subPosition);
                        $mediaFiles[$subItemId] = [
                            'taken_at' => $item->getTakenAt(),
                            'url'      => $mediaUrl,
                        ];
                    }
                } else {
                    if ($item->media_type == Response\Model\Item::PHOTO) {
                        $mediaUrl = $item->getImageVersions2()->candidates[0]->getUrl();
                    } else {
                        $mediaUrl = $item->getVideoVersions()[0]->getUrl();
                    }
                    $itemId = sprintf('%s [%s]', $itemDate, $item->getId());
                    $mediaFiles[$itemId] = [
                        'taken_at' => $item->getTakenAt(),
                        'url'      => $mediaUrl,
                    ];
                }
            }

            // Download all media files in the current page's file queue.
            foreach ($mediaFiles as $mediaId => $mediaInfo) {
                $mediaUrl = $mediaInfo['url'];
                $fileExtension = pathinfo(parse_url($mediaUrl, PHP_URL_PATH), PATHINFO_EXTENSION);
                $filePath = $backupFolder.$mediaId.'.'.$fileExtension;

                // Attempt to download the file.
                if ($printProgress) {
                    echo sprintf("* Downloading \"%s\" to \"%s\".\n", $mediaUrl, $filePath);
                }
                copy($mediaUrl, $filePath);

                // Set the file modification time to the taken_at timestamp.
                if (is_file($filePath)) {
                    touch($filePath, $mediaInfo['taken_at']);
                }
            }
        } while (!is_null($nextMaxId = $myTimeline->getNextMaxId()));
    }
}
