<?php

namespace App\Jobs\InboxPipeline;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Util\ActivityPub\Helpers;
use App\Util\ActivityPub\HttpSignature;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use App\Status;

class LoadOutbox implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public $profile;

    public const LIMIT_ACTIVITIES = 10;

    /**
     * Create a new job instance.
     *
     * @param \App\Profile $profile The profile for which to load the outbox.
     */
    public function __construct($profile)
    {
        $this->profile = $profile;
    }

    public function handle(): void
    {
        Log::info('Starting LoadOutbox job for: ' . $this->profile->username);


        $outboxUrl = $this->profile->outbox_url;

        $allItems = $this->fetchAllOutboxPages($outboxUrl);

        Log::info('Total activities collected: ' . count($allItems));
        $count = 0;
        foreach ($allItems as $item) {
            if ($count >= self::LIMIT_ACTIVITIES) {
                Log::info('Activity limit reached: ' . self::LIMIT_ACTIVITIES);
                break;
            }
            if (isset($item['type']) && $item['type'] === 'Create') {
                $this->handleCreateActivity($item);
                $count++;
            }
        }
    }


    private function fetchAllOutboxPages(string $url): array
    {
        $filteredItems = [];
        $data = $this->fetchActivityPubJson($url);

        if (!isset($data['first'])) {
            Log::error('The first page of the outbox was not found.');
            return [];
        }

        $nextUrl = is_array($data['first']) ? $data['first']['id'] ?? null : $data['first'];
        while ($nextUrl) {
            $page = $this->fetchActivityPubJson($nextUrl);

            if (!isset($page['orderedItems']) || !is_array($page['orderedItems'])) {
                break;
            }

            foreach ($page['orderedItems'] as $item) {
                if (
                    isset($item['type']) && $item['type'] === 'Create'
                    && isset($item['object']['type']) && $item['object']['type'] === 'Note'
                ) {
                    $filteredItems[] = $item;
                }
            }

            $nextUrl = $page['next'] ?? null;
        }

        return $filteredItems;
    }



    private function fetchActivityPubJson(string $url): ?array
    {

        $version = config('pixelfed.version');
        $appUrl = config('app.url');

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
                'Accept: application/activity+json',
                'User-Agent' => "(Pixelfed/{$version}; +{$appUrl})",
            ]
        );
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        } else {
            Log::info("Error requesting: $url (HTTP $httpCode)");
            return null;
        }
    }

    public function verifyNoteAttachment(array $payload)
    {
        $activity = $payload['object'];

        if (
            isset($activity['inReplyTo'])
            && !empty($activity['inReplyTo'])
            && Helpers::validateUrl($activity['inReplyTo'])
        ) {
            return true;
        }

        $valid = Helpers::verifyAttachments($activity);
        return $valid;
    }

    public function handleNoteCreate($payload)
    {
        $activity = $payload['object'];

        $hasUrl = isset($activity['url']);
        $url = isset($activity['url']) ? $activity['url'] : $activity['id'];

        if ($hasUrl) {
            if (Status::whereUri($url)->exists()) {
                return;
            }
        } else {
            if (Status::whereObjectUrl($url)->exists()) {
                return;
            }
        }

        $actor = $this->actorFirstOrCreate($payload['actor']);
        if (! $actor || $actor->domain == null) {
            return;
        }
        Helpers::storeStatus(
            $url,
            $actor,
            $activity
        );
    }


    public function handleCreateActivity($payload)
    {
        $activity = $payload['object'];
        if (config('autospam.live_filters.enabled')) {
            $filters = config('autospam.live_filters.filters');
            if (
                ! empty($filters) && isset($activity['content'])
                && ! empty($activity['content'])
                && strlen($filters) > 3
            ) {
                $filters = array_map('trim', explode(',', $filters));
                $content = $activity['content'];
                foreach ($filters as $filter) {
                    $filter = trim(strtolower($filter));
                    if (! $filter || ! strlen($filter)) {
                        continue;
                    }
                    if (str_contains(strtolower($content), $filter)) {
                        return;
                    }
                }
            }
        }
        $actor = $this->actorFirstOrCreate($payload['actor']);
        if (! $actor || $actor->domain == null) {
            return;
        }

        if (! isset($activity['to'])) {
            return;
        }

        if ($activity['type'] == 'Note' && ! empty($activity['inReplyTo'])) {
            $this->handleNoteReply($payload);
        } elseif ($activity['type'] == 'Note' && ! empty($activity['attachment'])) {
            if (! $this->verifyNoteAttachment($payload)) {
                return;
            }
            $this->handleNoteCreate($payload);
        }
    }

    public function handleNoteReply($payload)
    {
        $activity = $payload['object'];
        $actor = $this->actorFirstOrCreate($payload['actor']);
        if (! $actor || $actor->domain == null) {
            return;
        }
        $url = isset($activity['url']) ? $activity['url'] : $activity['id'];

        Helpers::statusFirstOrFetch($url, true);
    }

    public function actorFirstOrCreate($actorUrl)
    {
        return Helpers::profileFetch($actorUrl);
    }
}
