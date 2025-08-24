<?php

namespace App\Jobs\SharePipeline;

use App\Jobs\HomeFeedPipeline\FeedInsertPipeline;
use App\Notification;
use App\Services\ReblogService;
use App\Services\StatusService;
use App\Status;
use App\Transformer\ActivityPub\Verb\Announce;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use League\Fractal;
use League\Fractal\Serializer\ArraySerializer;
use App\Services\AccountService;
use App\Notifications\ShareNotification;
use  App\Jobs\ActivityPub\PubDeliver;

class SharePipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        $parent = Status::find($this->status->reblog_of_id);
        if (! $parent) {
            return;
        }
        $actor = $status->profile;
        $target = $parent->profile;

        if ($status->uri !== null) {
            // Ignore notifications to remote statuses
            return;
        }

        if ($target->id === $status->profile_id) {
            $this->remoteAnnounceDeliver();

            return true;
        }

        ReblogService::addPostReblog($parent->profile_id, $status->id);

        $parent->reblogs_count = $parent->reblogs_count + 1;
        $parent->save();
        StatusService::del($parent->id);

        Notification::firstOrCreate(
            [
                'profile_id' => $target->id,
                'actor_id' => $actor->id,
                'action' => 'share',
                'item_type' => 'App\Status',
                'item_id' => $status->reblog_of_id ?? $status->id,
            ]
        );

        if (!empty($target->user_id)  && AccountService::getAccountSettings($target->id)["send_email_on_share"]) {
            $target->user->notify(new ShareNotification($actor->id,  $status->reblog_of_id ?? $status->id));
        }

        FeedInsertPipeline::dispatch($status->id, $status->profile_id)->onQueue('feed');

        return $this->remoteAnnounceDeliver();
    }

    public function remoteAnnounceDeliver()
    {
        if (config('app.env') !== 'production' || (bool) config_cache('federation.activitypub.enabled') == false) {
            return true;
        }
        $status = $this->status;
        $profile = $status->profile;

        $fractal = new Fractal\Manager();
        $fractal->setSerializer(new ArraySerializer());
        $resource = new Fractal\Resource\Item($status, new Announce());
        $activity = $fractal->createData($resource)->toArray();

        $audience = $status->profile->getAudienceInbox($status->scope);

        if (empty($audience) || $status->scope != 'public') {
            // Return on profiles with no remote followers
            return;
        }

        $payload = json_encode($activity);

        foreach (array_chunk($audience, 100) as $chunk) {
            PubDeliver::dispatch($activity, $profile, $payload, $chunk)->onQueue('deliver');
        }
    }
}
