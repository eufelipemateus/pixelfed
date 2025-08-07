<?php

namespace App\Jobs\NotificationPipeline;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\NotificationService;

class RemoveOldNotificaion implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, Queueable;

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public $uniqueFor = 3600; // 1 hour


    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'notifications:remove-old-sync';
    }

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try{
            NotificationService::removeOldNotifications();
        }catch (\Exception $e){
            Log::error(
                'Error removing old notifications.',
                [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'attempt' => $this->attempts(),
                ]
            );
        }
    }
}
