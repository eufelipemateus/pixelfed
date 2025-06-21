<?php
/**
 * Subscription model file.
 *
 * PHP version 8
 *
 * @category Models
 * @package  App\Models
 * @author   Felipe Mateus <eu@felipemateus.com>
 * @license  AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/eufelipemateus/pixelfed
 */
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\User;
use App\Enums\StatusSubscriptionEnums;


/**
 * Class Subscription
 *
 * Represents a user's subscription to a plan.
 *
 * @category Models
 * @package  App\Models
 * @author   Felipe Mateus <eu@felipemateus.com>
 * @license  AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/eufelipemateus/pixelfed
 */
class Subscription extends Model
{
    //
    protected $fillable = [
        'user_id',
        'paypal_subscription_id',
        'started_at',
        'expires_at',
        'canceled_at',
        'expires_at',
        'active',
    ];


    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'canceled_at' => 'datetime',
        'active' => 'boolean',
    ];


    /**
     * Get the custom casts for the model.
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'status' => StatusSubscriptionEnums::class,
        ];
    }

    /**
     * Get the user that owns the subscription.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
