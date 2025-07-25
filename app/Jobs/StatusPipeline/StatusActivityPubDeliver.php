<?php

namespace App\Jobs\StatusPipeline;

use App\Instance;
use Log;
use App\Profile;
use App\Status;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use League\Fractal;
use League\Fractal\Serializer\ArraySerializer;
use App\Transformer\ActivityPub\Verb\CreateNote;
use App\Transformer\ActivityPub\Verb\CreateQuestion;
use App\Util\ActivityPub\Helpers;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use App\Util\ActivityPub\HttpSignature;

class StatusActivityPubDeliver implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected $status;

    /**
     * Delete the job if its models no longer exist.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Status $status)
    {
        $this->status = $status;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $status = $this->status;
        $profile = $status->profile;

        // ignore group posts
        // if($status->group_id != null) {
        //     return;
        // }

        if ($status->local == false || $status->url || $status->uri) {
            return;
        }

        $audienceInboxes = $status->profile->getAudienceInbox();

        $mentionInboxes = $status->mentions
            ->filter(fn($mention) => $mention->domain !== null)
            ->map(fn($mention) => $mention->sharedInbox ?? $mention->inbox_url)
            ->values()
            ->toArray();

        $replyInbox = [];

        if ($status->in_reply_to_profile_id) {
            $parentProfile = Profile::find($status->in_reply_to_profile_id);

            if ($parentProfile && $parentProfile->domain !== null) {
                $replyInbox[] = $parentProfile->sharedInbox ?? $parentProfile->inbox_url;
            }
        }

        $audience = array_values(array_unique(array_merge(
            $audienceInboxes,
            $mentionInboxes,
            $replyInbox
        )));

        if (empty($audience) || !in_array($status->scope, ['public', 'unlisted', 'private'])) {
            // Return on profiles with no remote followers
            return;
        }

        if ($status->scope === 'public') {
            $knownSharedInboxes = Instance::whereNotNull('shared_inbox')->pluck('shared_inbox')->toArray();
            $audience = array_unique(array_merge($audience, $knownSharedInboxes));
        }

        switch ($status->type) {
            case 'poll':
                $activitypubObject = new CreateQuestion();
                break;

            default:
                $activitypubObject = new CreateNote();
                break;
        }


        $fractal = new Fractal\Manager();
        $fractal->setSerializer(new ArraySerializer());
        $resource = new Fractal\Resource\Item($status, $activitypubObject);
        $activity = $fractal->createData($resource)->toArray();

        $payload = json_encode($activity);

        $client = new Client([
            'timeout'  => config('federation.activitypub.delivery.timeout')
        ]);

        $version = config('pixelfed.version');
        $appUrl = config('app.url');
        $userAgent = "(Pixelfed/{$version}; +{$appUrl})";

        $requests = function ($audience) use ($client, $activity, $profile, $payload, $userAgent) {
            foreach ($audience as $url) {
                $headers = HttpSignature::sign($profile, $url, $activity, [
                    'Content-Type'    => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
                    'User-Agent'    => $userAgent,
                ]);
                yield function () use ($client, $url, $headers, $payload) {
                    return $client->postAsync($url, [
                        'curl' => [
                            CURLOPT_HTTPHEADER => $headers,
                            CURLOPT_POSTFIELDS => $payload,
                            CURLOPT_HEADER => true,
                            CURLOPT_SSL_VERIFYPEER => true,
                            CURLOPT_SSL_VERIFYHOST => false
                        ]
                    ]);
                };
            }
        };

        $pool = new Pool($client, $requests($audience), [
            'concurrency' => config('federation.activitypub.delivery.concurrency'),
            'fulfilled' => function ($response, $index) {},
            'rejected' => function ($reason, $index) {}
        ]);

        $promise = $pool->promise();

        $promise->wait();
    }
}
