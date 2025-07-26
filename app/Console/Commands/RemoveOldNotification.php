<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\NotificationPipeline\RemoveOldNotificaion;

class RemoveOldNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:remove-old-notification';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove old notifications read from the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        RemoveOldNotificaion::dispatch()
            ->onQueue('low');
    }
}
