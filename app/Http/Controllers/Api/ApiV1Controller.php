<?php

namespace App\Http\Controllers\Api;

use App\Avatar;
use App\Bookmark;
use App\Collection;
use App\CollectionItem;
use App\DirectMessage;
use App\Follower;
use App\FollowRequest;
use App\Hashtag;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\FollowerController;
use App\Http\Controllers\StatusController;
use App\Instance;
use App\Jobs\AvatarPipeline\AvatarOptimize;
use App\Jobs\CommentPipeline\CommentPipeline;
use App\Jobs\FollowPipeline\FollowAcceptPipeline;
use App\Jobs\FollowPipeline\FollowPipeline;
use App\Jobs\FollowPipeline\FollowRejectPipeline;
use App\Jobs\FollowPipeline\UnfollowPipeline;
use App\Jobs\HomeFeedPipeline\FeedWarmCachePipeline;
use App\Jobs\ImageOptimizePipeline\ImageOptimize;
use App\Jobs\LikePipeline\LikePipeline;
use App\Jobs\MediaPipeline\MediaDeletePipeline;
use App\Jobs\MediaPipeline\MediaSyncLicensePipeline;
use App\Jobs\NotificationPipeline\NotificationWarmUserCache;
use App\Jobs\SharePipeline\SharePipeline;
use App\Jobs\SharePipeline\UndoSharePipeline;
use App\Jobs\StatusPipeline\NewStatusPipeline;
use App\Jobs\StatusPipeline\StatusDelete;
use App\Jobs\VideoPipeline\VideoThumbnail;
use App\Like;
use App\Media;
use App\Models\Conversation;
use App\Models\CustomFilter;
use App\Notification;
use App\Profile;
use App\Services\AccountService;
use App\Services\AdminShadowFilterService;
use App\Services\BookmarkService;
use App\Services\BouncerService;
use App\Services\CollectionService;
use App\Services\CustomEmojiService;
use App\Services\DiscoverService;
use App\Services\FollowerService;
use App\Services\HomeTimelineService;
use App\Services\InstanceService;
use App\Services\LikeService;
use App\Services\MarkerService;
use App\Services\MediaBlocklistService;
use App\Services\MediaPathService;
use App\Services\MediaService;
use App\Services\NetworkTimelineService;
use App\Services\NotificationService;
use App\Services\PublicTimelineService;
use App\Services\ReblogService;
use App\Services\RelationshipService;
use App\Services\SnowflakeService;
use App\Services\StatusService;
use App\Services\UserFilterService;
use App\Services\UserRoleService;
use App\Services\UserStorageService;
use App\Status;
use App\StatusHashtag;
use App\Transformer\Api\Mastodon\v1\AccountTransformer;
use App\Transformer\Api\Mastodon\v1\MediaTransformer;
use App\Transformer\Api\Mastodon\v1\NotificationTransformer;
use App\Transformer\Api\Mastodon\v1\StatusTransformer;
use App\Transformer\Api\RelationshipTransformer;
use App\User;
use App\UserFilter;
use App\UserSetting;
use App\Util\Lexer\Autolink;
use App\Util\Lexer\PrettyNumber;
use App\Util\Localization\Localization;
use App\Util\Media\Filter;
use App\Util\Media\License;
use Cache;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use League\Fractal;
use League\Fractal\Serializer\ArraySerializer;
use Purify;
use Storage;
use  App\Notifications\FollowRequestNotification;
use App\Enums\StatusEnums;

use App\Services\Translate\TranslateService;

class ApiV1Controller extends Controller
{
    protected $fractal;

    const PF_API_ENTITY_KEY = '_pe';

    public function __construct()
    {
        $this->fractal = new Fractal\Manager;
        $this->fractal->setSerializer(new ArraySerializer);
    }

    public function json($res, $code = 200, $headers = [])
    {
        return response()->json($res, $code, $headers, JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /api/v1/apps/verify_credentials
     */
    public function getApp(Request $request)
    {
        // FIXME: /api/v1/apps/verify_credentials should be accessible with any
        // valid Access Token, not just a user's access token (i.e., client
        // credentails grant flow access tokens)
        abort_if(! $request->user() || ! $request->user()->token(), 403);

        $client = $request->user()->token()->client;
        $res = [
            'name' => $client->name,
            'website' => null,
            'vapid_key' => null,
        ];

        return $this->json($res);
    }

    /**
     * POST /api/v1/apps
     */
    public function apps(Request $request)
    {
        abort_if(! (bool) config_cache('pixelfed.oauth_enabled'), 404);

        $this->validate($request, [
            'client_name' => 'required',
            'redirect_uris' => 'required',
        ]);

        $uris = collect(explode("\n", $request->redirect_uris))
            ->map('urldecode')
            ->filter()
            ->join(',');

        $client = Passport::client()->forceFill([
            'user_id' => null,
            'name' => e($request->client_name),
            'secret' => Str::random(40),
            'redirect' => $uris,
            'personal_access_client' => false,
            'password_client' => false,
            'revoked' => false,
        ]);

        $client->save();

        $res = [
            'id' => (string) $client->id,
            'name' => $client->name,
            'website' => null,
            'redirect_uri' => $client->redirect,
            'client_id' => (string) $client->id,
            'client_secret' => $client->secret,
            'vapid_key' => null,
        ];

        return $this->json($res, 200, [
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * GET /api/v1/accounts/verify_credentials
     *
     *
     * @return \App\Transformer\Api\AccountTransformer
     */
    public function verifyCredentials(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $user = $request->user();

        abort_if($user->status != StatusEnums::ACTIVE, 403);
        AccountService::setLastActive($user->id);

        $res = $request->has(self::PF_API_ENTITY_KEY) ? AccountService::get($user->profile_id) : AccountService::getMastodon($user->profile_id);

        $res['source'] = [
            'privacy' => $res['locked'] ? 'private' : 'public',
            'sensitive' => false,
            'language' => $user->language ?? 'en',
            'note' => strip_tags($res['note']),
            'fields' => [],
        ];

        if ($request->has(self::PF_API_ENTITY_KEY)) {
            $res['settings'] = AccountService::getAccountSettings($user->profile_id);
        }

        return $this->json($res);
    }

    /**
     * GET /api/v1/accounts/{id}
     *
     * @param  int  $id
     * @return \App\Transformer\Api\AccountTransformer
     */
    public function accountById(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $withInstanceMeta = $request->has('_wim');
        $res = $request->has(self::PF_API_ENTITY_KEY) ? AccountService::get($id, true) : AccountService::getMastodon($id, true);
        if (! $res) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        if ($res && strpos($res['acct'], '@') != -1) {
            $domain = parse_url($res['url'], PHP_URL_HOST);
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }

        return $this->json($res);
    }

    /**
     * PATCH /api/v1/accounts/update_credentials
     *
     * @return \App\Transformer\Api\AccountTransformer
     */
    public function accountUpdateCredentials(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        if (config('pixelfed.bouncer.cloud_ips.ban_api')) {
            abort_if(BouncerService::checkIp($request->ip()), 404);
        }

        $this->validate($request, [
            'avatar' => 'sometimes|mimetypes:image/jpeg,image/jpg,image/png|max:' . config('pixelfed.max_avatar_size'),
            'display_name' => 'nullable|string|max:30',
            'note' => 'nullable|string|max:200',
            'locked' => 'nullable',
            'website' => 'nullable|string|max:120',
            // 'source.privacy'    => 'nullable|in:unlisted,public,private',
            // 'source.sensitive'  => 'nullable|boolean'
        ], [
            'required' => 'The :attribute field is required.',
            'avatar.mimetypes' => 'The file must be in jpeg or png format',
            'avatar.max' => 'The :attribute exceeds the file size limit of ' . PrettyNumber::size(config('pixelfed.max_avatar_size'), true, false),
        ]);

        $user = $request->user();
        AccountService::setLastActive($user->id);
        $profile = $user->profile;
        $settings = $user->settings;

        $changes = false;
        $other = array_merge(AccountService::defaultSettings()['other'], $settings->other ?? []);
        $syncLicenses = false;
        $licenseChanged = false;
        $composeSettings = array_merge(AccountService::defaultSettings()['compose_settings'], $settings->compose_settings ?? []);

        if ($request->has('avatar')) {
            $av = Avatar::whereProfileId($profile->id)->first();
            if ($av) {
                $currentAvatar = storage_path('app/' . $av->media_path);
                $file = $request->file('avatar');
                $path = "public/avatars/{$profile->id}";
                $name = strtolower(str_random(6)) . '.' . $file->guessExtension();
                $request->file('avatar')->storePubliclyAs($path, $name);
                $av->media_path = "{$path}/{$name}";
                $av->save();
                Cache::forget("avatar:{$profile->id}");
                Cache::forget('user:account:id:' . $user->id);
                AvatarOptimize::dispatch($user->profile, $currentAvatar);
            }
            $changes = true;
        }

        if ($request->has('source[language]')) {
            $lang = $request->input('source[language]');
            if (in_array($lang, Localization::languages())) {
                $user->language = $lang;
                $changes = true;
                $other['language'] = $lang;
            }
        }

        if ($request->has('website')) {
            $website = $request->input('website');
            if ($website != $profile->website) {
                if ($website) {
                    if (! strpos($website, '.')) {
                        $website = null;
                    }

                    if ($website && ! strpos($website, '://')) {
                        $website = 'https://' . $website;
                    }

                    $host = parse_url($website, PHP_URL_HOST);

                    $bannedInstances = InstanceService::getBannedDomains();
                    if (in_array($host, $bannedInstances)) {
                        $website = null;
                    }
                }
                $profile->website = $website ? $website : null;
                $changes = true;
            }
        }

        if ($request->has('display_name')) {
            $displayName = $request->input('display_name');
            if ($displayName !== $user->name) {
                $user->name = $displayName;
                $profile->name = $displayName;
                $changes = true;
            }
        }

        if ($request->has('note')) {
            $note = $request->input('note');
            if ($note !== strip_tags($profile->bio)) {
                $profile->bio = Autolink::create()->autolink(strip_tags($note));
                $changes = true;
            }
        }

        if ($request->has('locked')) {
            $locked = $request->boolean('locked');
            if ($profile->is_private != $locked) {
                $profile->is_private = $locked;
                $changes = true;
            }
        }

        if ($request->has('reduce_motion')) {
            $reduced = $request->boolean('reduce_motion');
            if ($settings->reduce_motion != $reduced) {
                $settings->reduce_motion = $reduced;
                $changes = true;
            }
        }

        if ($request->has('high_contrast_mode')) {
            $contrast = $request->boolean('high_contrast_mode');
            if ($settings->high_contrast_mode != $contrast) {
                $settings->high_contrast_mode = $contrast;
                $changes = true;
            }
        }

        if ($request->has('video_autoplay')) {
            $autoplay = $request->boolean('video_autoplay');
            if ($settings->video_autoplay != $autoplay) {
                $settings->video_autoplay = $autoplay;
                $changes = true;
            }
        }

        if ($request->has('license')) {
            $license = $request->input('license');
            abort_if(! in_array($license, License::keys()), 422, 'Invalid media license id');
            $syncLicenses = $request->input('sync_licenses') == true;
            abort_if($syncLicenses && Cache::get('pf:settings:mls_recently:' . $user->id) == 2, 422, 'You can only sync licenses twice per 24 hours');
            if ($composeSettings['default_license'] != $license) {
                $composeSettings['default_license'] = $license;
                $licenseChanged = true;
                $changes = true;
            }
        }

        if ($request->has('media_descriptions')) {
            $md = $request->boolean('media_descriptions');
            if ($composeSettings['media_descriptions'] != $md) {
                $composeSettings['media_descriptions'] = $md;
                $changes = true;
            }
        }

        if ($request->has('crawlable')) {
            $crawlable = $request->boolean('crawlable');
            if ($settings->crawlable != $crawlable) {
                $settings->crawlable = $crawlable;
                $changes = true;
            }
        }

        if ($request->has('show_profile_follower_count')) {
            $show_profile_follower_count = $request->boolean('show_profile_follower_count');
            if ($settings->show_profile_follower_count != $show_profile_follower_count) {
                $settings->show_profile_follower_count = $show_profile_follower_count;
                $changes = true;
                Cache::forget('pf:acct-trans:hideFollowers:' . $profile->id);
            }
        }

        if ($request->has('show_profile_following_count')) {
            $show_profile_following_count = $request->boolean('show_profile_following_count');
            if ($settings->show_profile_following_count != $show_profile_following_count) {
                $settings->show_profile_following_count = $show_profile_following_count;
                $changes = true;
                Cache::forget('pf:acct-trans:hideFollowing:' . $profile->id);
            }
        }

        if ($request->has('public_dm')) {
            $public_dm = $request->boolean('public_dm');
            if ($settings->public_dm != $public_dm) {
                $settings->public_dm = $public_dm;
                $changes = true;
            }
        }

        if ($request->has('source[privacy]')) {
            $scope = $request->input('source[privacy]');
            if (in_array($scope, ['public', 'private', 'unlisted'])) {
                if ($composeSettings['default_scope'] != $scope) {
                    $composeSettings['default_scope'] = $profile->is_private ? 'private' : $scope;
                    $changes = true;
                }
            }
        }

        if ($request->has('disable_embeds')) {
            $disabledEmbeds = $request->boolean('disable_embeds');
            if ($other['disable_embeds'] != $disabledEmbeds) {
                $other['disable_embeds'] = $disabledEmbeds;
                $changes = true;
            }
        }

        if ($changes) {
            $settings->other = $other;
            $settings->compose_settings = $composeSettings;
            $settings->save();
            $user->save();
            $profile->save();
            Cache::forget('profile:settings:' . $profile->id);
            Cache::forget('user:account:id:' . $profile->user_id);
            Cache::forget('profile:follower_count:' . $profile->id);
            Cache::forget('profile:following_count:' . $profile->id);
            Cache::forget('profile:embed:' . $profile->id);
            Cache::forget('profile:compose:settings:' . $user->id);
            Cache::forget('profile:view:' . $profile->username);
            Cache::forget('profile:atom:enabled:' . $profile->id);
            Cache::forget('pfc:cached-user:wt:' . strtolower($profile->username));
            Cache::forget('pfc:cached-user:wot:' . strtolower($profile->username));
            Cache::forget('pf:acct:settings:hidden-followers:' . $profile->id);
            Cache::forget('pf:acct:settings:hidden-following:' . $profile->id);
            Cache::forget('pf:acct-trans:hideFollowing:' . $profile->id);
            Cache::forget('pf:acct-trans:hideFollowers:' . $profile->id);
            AccountService::del($user->profile_id);
            AccountService::forgetAccountSettings($profile->id);
        }

        if ($syncLicenses && $licenseChanged) {
            $key = 'pf:settings:mls_recently:' . $user->id;
            $val = Cache::has($key) ? 2 : 1;
            Cache::put($key, $val, 86400);
            MediaSyncLicensePipeline::dispatch($user->id, $request->input('license'));
        }

        if ($request->has(self::PF_API_ENTITY_KEY)) {
            $res = AccountService::get($user->profile_id, true);
        } else {
            $res = AccountService::getMastodon($user->profile_id, true);
            $res['bio'] = strip_tags($res['note']);
            $res = array_merge($res, $other);
        }

        return $this->json($res);
    }

    /**
     * GET /api/v1/accounts/{id}/followers
     *
     * @param  int  $id
     * @return \App\Transformer\Api\AccountTransformer
     */
    public function accountFollowersById(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $account = AccountService::get($id);
        abort_if(! $account, 404);
        abort_if(isset($account['moved'], $account['moved']['id']), 404, 'Account moved');
        $pid = $request->user()->profile_id;
        $this->validate($request, [
            'limit' => 'sometimes|integer|min:1',
        ]);
        $limit = $request->input('limit', 10);
        if ($limit > 80) {
            $limit = 80;
        }
        $napi = $request->has(self::PF_API_ENTITY_KEY);

        if ($account && strpos($account['acct'], '@') != -1) {
            $domain = parse_url($account['url'], PHP_URL_HOST);
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }

        if (intval($pid) !== intval($account['id'])) {
            if ($account['locked']) {
                if (! FollowerService::follows($pid, $account['id'])) {
                    return [];
                }
            }

            if (AccountService::hiddenFollowers($id)) {
                return [];
            }

            if ($request->has('page') && $request->user()->is_admin == false) {
                $page = (int) $request->input('page');
                if (($page * $limit) >= 100) {
                    return [];
                }
            }
        }
        if ($request->has('page')) {
            $res = DB::table('followers')
                ->selectRaw('followers.id as id, followers.profile_id as profile_id, followers.following_id as following_id')
                ->leftJoin('profiles', 'followers.profile_id', '=', 'profiles.id')
                ->whereFollowingId($account['id'])
                ->whereNull('profiles.status')
                ->orderByDesc('followers.id')
                ->simplePaginate($limit)
                ->map(function ($follower) use ($napi) {
                    return $napi ? AccountService::get($follower->profile_id, true) : AccountService::getMastodon($follower->profile_id, true);
                })
                ->filter(function ($account) {
                    return $account && isset($account['id']);
                })
                ->values()
                ->toArray();

            return $this->json($res);
        }

        $paginator = DB::table('followers')
            ->selectRaw('followers.id as id, followers.profile_id as profile_id, followers.following_id as following_id')
            ->leftJoin('profiles', 'followers.profile_id', '=', 'profiles.id')
            ->whereFollowingId($account['id'])
            ->whereNull('profiles.status')
            ->orderByDesc('followers.id')
            ->cursorPaginate($limit)
            ->withQueryString();

        $link = null;

        if ($paginator->onFirstPage()) {
            if ($paginator->hasMorePages()) {
                $link = '<' . $paginator->nextPageUrl() . '>; rel="prev"';
            }
        } else {
            if ($paginator->previousPageUrl()) {
                $link = '<' . $paginator->previousPageUrl() . '>; rel="next"';
            }

            if ($paginator->hasMorePages()) {
                $link .= ($link ? ', ' : '') . '<' . $paginator->nextPageUrl() . '>; rel="prev"';
            }
        }

        $res = $paginator->map(function ($follower) use ($napi) {
            return $napi ? AccountService::get($follower->profile_id, true) : AccountService::getMastodon($follower->profile_id, true);
        })
            ->filter(function ($account) {
                return $account && isset($account['id']);
            })
            ->values()
            ->toArray();

        $headers = isset($link) ? ['Link' => $link] : [];

        return $this->json($res, 200, $headers);
    }

    /**
     * GET /api/v1/accounts/{id}/following
     *
     * @param  int  $id
     * @return \App\Transformer\Api\AccountTransformer
     */
    public function accountFollowingById(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $account = AccountService::get($id);
        abort_if(! $account, 404);
        abort_if(isset($account['moved'], $account['moved']['id']), 404, 'Account moved');
        $pid = $request->user()->profile_id;
        $this->validate($request, [
            'limit' => 'sometimes|integer|min:1',
        ]);
        $limit = $request->input('limit', 10);
        if ($limit > 80) {
            $limit = 80;
        }
        $napi = $request->has(self::PF_API_ENTITY_KEY);

        if ($account && strpos($account['acct'], '@') != -1) {
            $domain = parse_url($account['url'], PHP_URL_HOST);
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }

        if (intval($pid) !== intval($account['id'])) {
            if ($account['locked']) {
                if (! FollowerService::follows($pid, $account['id'])) {
                    return [];
                }
            }

            if (AccountService::hiddenFollowing($id)) {
                return [];
            }

            if ($request->has('page') && $request->user()->is_admin == false) {
                $page = (int) $request->input('page');
                if (($page * $limit) >= 100) {
                    return [];
                }
            }
        }

        if ($request->has('page')) {
            $res = DB::table('followers')
                ->join('profiles', 'followers.following_id', '=', 'profiles.id')
                ->selectRaw('followers.id as id, followers.profile_id as profile_id, followers.following_id as following_id')
                ->where('followers.profile_id', $account['id'])
                ->whereNull('profiles.status')
                ->orderByDesc('followers.id')
                ->simplePaginate($limit)
                ->map(function ($follower) use ($napi) {
                    return $napi ? AccountService::get($follower->following_id, true) : AccountService::getMastodon($follower->following_id, true);
                })
                ->filter(function ($account) {
                    return $account && isset($account['id']);
                })
                ->values()
                ->toArray();

            return $this->json($res);
        }

        $paginator = DB::table('followers')
            ->join('profiles', 'followers.following_id', '=', 'profiles.id')
            ->selectRaw('followers.id as id, followers.profile_id as profile_id, followers.following_id as following_id')
            ->where('followers.profile_id', $account['id'])
            ->whereNull('profiles.status')
            ->orderByDesc('followers.id')
            ->cursorPaginate($limit)
            ->withQueryString();

        $link = null;

        if ($paginator->onFirstPage()) {
            if ($paginator->hasMorePages()) {
                $link = '<' . $paginator->nextPageUrl() . '>; rel="prev"';
            }
        } else {
            if ($paginator->previousPageUrl()) {
                $link = '<' . $paginator->previousPageUrl() . '>; rel="next"';
            }

            if ($paginator->hasMorePages()) {
                $link .= ($link ? ', ' : '') . '<' . $paginator->nextPageUrl() . '>; rel="prev"';
            }
        }

        $res = $paginator->map(function ($follower) use ($napi) {
            return $napi ? AccountService::get($follower->following_id, true) : AccountService::getMastodon($follower->following_id, true);
        })
            ->filter(function ($account) {
                return $account && isset($account['id']);
            })
            ->values()
            ->toArray();

        $headers = isset($link) ? ['Link' => $link] : [];

        return $this->json($res, 200, $headers);
    }

    /**
     * GET /api/v1/accounts/{id}/statuses
     *
     * @param  int  $id
     * @return \App\Transformer\Api\StatusTransformer
     */
    public function accountStatusesById(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $user = $request->user();

        $this->validate($request, [
            'only_media' => 'nullable',
            'media_type' => 'sometimes|string|in:photo,video',
            'pinned' => 'nullable',
            'exclude_replies' => 'nullable',
            'max_id' => 'nullable|integer|min:0|max:' . PHP_INT_MAX,
            'since_id' => 'nullable|integer|min:0|max:' . PHP_INT_MAX,
            'min_id' => 'nullable|integer|min:0|max:' . PHP_INT_MAX,
            'limit' => 'nullable|integer|min:1',
        ]);

        $napi = $request->has(self::PF_API_ENTITY_KEY);
        $profile = $napi ? AccountService::get($id, true) : AccountService::getMastodon($id, true);

        if (! $profile || ! isset($profile['id']) || ! $user) {
            return $this->json(['error' => 'Account not found'], 404);
        }

        if ($profile && strpos($profile['acct'], '@') != -1) {
            $domain = parse_url($profile['url'], PHP_URL_HOST);
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }

        $limit = $request->input('limit') ?? 20;
        if ($limit > 40) {
            $limit = 40;
        }

        $pid = $request->user()->profile_id;
        $scope = $request->only_media == true
            ? ['photo', 'photo:album', 'video', 'video:album']
            : ['photo', 'photo:album', 'video', 'video:album', 'share', 'reply'];

        if ($request->only_media && $request->has('media_type')) {
            $mt = $request->input('media_type');
            if ($mt == 'video') {
                $scope = ['video', 'video:album'];
            }
        }

        if (intval($pid) === intval($profile['id'])) {
            $visibility = ['public', 'unlisted', 'private'];
        } elseif ($profile['locked']) {
            $following = FollowerService::follows($pid, $profile['id']);
            if (! $following) {
                return response()->json([]);
            }
            $visibility = ['public', 'unlisted', 'private'];
        } else {
            $following = FollowerService::follows($pid, $profile['id']);
            $visibility = $following ? ['public', 'unlisted', 'private'] : ['public', 'unlisted'];
        }

        // Buscar created_at com base no min_id
        $createdAt = null;
        if ($request->filled('min_id')) {
            $status = Status::select('created_at')->where('id', $request->min_id)->first();
            $createdAt = $status?->created_at;
        }

        $resQuery = Status::select(
            'profile_id',
            'in_reply_to_id',
            'reblog_of_id',
            'type',
            'id',
            'scope',
            'pinned_order'
        )
            ->whereProfileId($profile['id'])
            ->whereNull('in_reply_to_id')
            ->whereNull('reblog_of_id')
            ->whereIn('type', $scope)
            ->whereIn('scope', $visibility);

        if ($createdAt) {
            $resQuery->where('created_at', '>', $createdAt);
        }

        $res = $resQuery
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function ($s) use ($user, $napi, $profile) {
                try {
                    $status = $napi
                        ? StatusService::get($s->id, false)
                        : StatusService::getMastodon($s->id, false);
                } catch (\Exception $e) {
                    return false;
                }

                if ($profile) {
                    $status['account'] = $profile;
                }

                if ($user && $status) {
                    $status['favourited'] = (bool) LikeService::liked($user->profile_id, $s->id);
                    $status['reblogged'] = (bool) ReblogService::get($user->profile_id, $s->id);
                    $status['bookmarked'] = (bool) BookmarkService::get($user->profile_id, $s->id);
                }

                return $status;
            })
            ->filter()
            ->values();

        return $this->json($res);
    }

    /**
     * POST /api/v1/accounts/{id}/follow
     *
     * @param  int  $id
     * @return \App\Transformer\Api\RelationshipTransformer
     */
    public function accountFollowById(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('follow'), 403);

        $user = $request->user();
        abort_if($user->profile_id == $id, 400, 'Invalid profile');

        abort_if($user->has_roles && ! UserRoleService::can('can-follow', $user->id), 403, 'Invalid permissions for this action');

        AccountService::setLastActive($user->id);

        $target = Profile::whereNull('status')->findOrFail($id);

        abort_if($target && $target->moved_to_profile_id, 400, 'Cannot follow an account that has moved!');

        if ($target && $target->domain) {
            $domain = $target->domain;
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }

        $private = (bool) $target->is_private;
        $remote = (bool) $target->domain;
        $blocked = UserFilter::whereUserId($target->id)
            ->whereFilterType('block')
            ->whereFilterableId($user->profile_id)
            ->whereFilterableType('App\Profile')
            ->exists();

        if ($blocked == true) {
            abort(400, 'You cannot follow this user.');
        }

        $isFollowing = Follower::whereProfileId($user->profile_id)
            ->whereFollowingId($target->id)
            ->exists();

        // Following already, return empty relationship
        if ($isFollowing == true) {
            $res = RelationshipService::get($user->profile_id, $target->id) ?? [];

            return $this->json($res);
        }

        // Rate limits, max 7500 followers per account
        if ($user->profile->following_count && $user->profile->following_count >= Follower::MAX_FOLLOWING) {
            abort(400, 'You cannot follow more than ' . Follower::MAX_FOLLOWING . ' accounts');
        }

        if ($private == true) {
            $follow = FollowRequest::firstOrCreate([
                'follower_id' => $user->profile_id,
                'following_id' => $target->id,
            ]);
            if ($remote && config('federation.activitypub.remoteFollow') == true) {
                (new FollowerController)->sendFollow($user->profile, $target);
            }

            if (!$remote &&  AccountService::getAccountSettings($target->id)["send_email_new_follower_request"]) {
                $target->user->notify(new FollowRequestNotification($user->profile_id));
            }
        } elseif ($remote == true) {
            $follow = FollowRequest::firstOrCreate([
                'follower_id' => $user->profile_id,
                'following_id' => $target->id,
            ]);

            if (config('federation.activitypub.remoteFollow') == true) {
                (new FollowerController)->sendFollow($user->profile, $target);
            }
        } else {
            $follower = Follower::firstOrCreate([
                'profile_id' => $user->profile_id,
                'following_id' => $target->id,
            ]);
            FollowPipeline::dispatch($follower)->onQueue('high');
        }

        RelationshipService::refresh($user->profile_id, $target->id);
        Cache::forget('profile:following:' . $target->id);
        Cache::forget('profile:followers:' . $target->id);
        Cache::forget('profile:following:' . $user->profile_id);
        Cache::forget('profile:followers:' . $user->profile_id);
        Cache::forget('api:local:exp:rec:' . $user->profile_id);
        Cache::forget('user:account:id:' . $target->user_id);
        Cache::forget('user:account:id:' . $user->id);
        Cache::forget('profile:follower_count:' . $target->id);
        Cache::forget('profile:follower_count:' . $user->profile_id);
        Cache::forget('profile:following_count:' . $target->id);
        Cache::forget('profile:following_count:' . $user->profile_id);
        AccountService::del($user->profile_id);
        AccountService::del($target->id);

        $res = RelationshipService::get($user->profile_id, $target->id);

        return $this->json($res);
    }

    /**
     * POST /api/v1/accounts/{id}/unfollow
     *
     * @param  int  $id
     * @return \App\Transformer\Api\RelationshipTransformer
     */
    public function accountUnfollowById(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('follow'), 403);

        $user = $request->user();

        abort_if($user->profile_id == $id, 400, 'Invalid profile');

        AccountService::setLastActive($user->id);

        $target = Profile::whereNull('status')
            ->findOrFail($id);

        $private = (bool) $target->is_private;
        $remote = (bool) $target->domain;

        $isFollowing = Follower::whereProfileId($user->profile_id)
            ->whereFollowingId($target->id)
            ->exists();

        if ($isFollowing == false) {
            $followRequest = FollowRequest::whereFollowerId($user->profile_id)
                ->whereFollowingId($target->id)
                ->first();
            if ($followRequest) {
                $followRequest->delete();
                RelationshipService::refresh($target->id, $user->profile_id);
                if ($target->domain) {
                    UnfollowPipeline::dispatch($user->profile_id, $target->id)->onQueue('high');
                }
            }
            $resource = new Fractal\Resource\Item($target, new RelationshipTransformer);
            $res = $this->fractal->createData($resource)->toArray();

            return $this->json($res);
        }

        Follower::whereProfileId($user->profile_id)
            ->whereFollowingId($target->id)
            ->delete();

        UnfollowPipeline::dispatch($user->profile_id, $target->id)->onQueue('high');

        if ($remote == true && config('federation.activitypub.remoteFollow') == true) {
            (new FollowerController)->sendUndoFollow($user->profile, $target);
        }

        RelationshipService::refresh($user->profile_id, $target->id);
        Cache::forget('profile:following:' . $target->id);
        Cache::forget('profile:followers:' . $target->id);
        Cache::forget('profile:following:' . $user->profile_id);
        Cache::forget('profile:followers:' . $user->profile_id);
        Cache::forget('api:local:exp:rec:' . $user->profile_id);
        Cache::forget('user:account:id:' . $target->user_id);
        Cache::forget('user:account:id:' . $user->id);
        Cache::forget('profile:follower_count:' . $target->id);
        Cache::forget('profile:follower_count:' . $user->profile_id);
        Cache::forget('profile:following_count:' . $target->id);
        Cache::forget('profile:following_count:' . $user->profile_id);
        AccountService::del($user->profile_id);
        AccountService::del($target->id);

        $res = RelationshipService::get($user->profile_id, $target->id);

        return $this->json($res);
    }

    /**
     * GET /api/v1/accounts/relationships
     *
     * @param  array|int  $id
     * @return \App\Services\RelationshipService
     */
    public function accountRelationshipsById(Request $request)
    {
        abort_if(! $request->user(), 403);

        $this->validate($request, [
            'id' => 'required|array|min:1',
            'id.*' => 'required|integer|min:1|max:' . PHP_INT_MAX,
        ]);
        $ids = $request->input('id');
        if (count($ids) > 20) {
            $ids = collect($ids)->take(20)->toArray();
        }
        $napi = $request->has(self::PF_API_ENTITY_KEY);
        $pid = $request->user()->profile_id ?? $request->user()->profile->id;
        $res = collect($ids)
            ->map(function ($id) use ($pid, $napi) {
                if (intval($id) === intval($pid)) {
                    return [
                        'id' => $id,
                        'following' => false,
                        'followed_by' => false,
                        'blocking' => false,
                        'muting' => false,
                        'muting_notifications' => false,
                        'requested' => false,
                        'domain_blocking' => false,
                        'showing_reblogs' => false,
                        'endorsed' => false,
                    ];
                }

                return $napi ?
                    RelationshipService::getWithDate($pid, $id) :
                    RelationshipService::get($pid, $id);
            });

        return $this->json($res);
    }

    /**
     * GET /api/v1/accounts/search
     *
     *
     *
     * @return \App\Transformer\Api\AccountTransformer
     */
    public function accountSearch(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $this->validate($request, [
            'q' => 'required|string|min:1|max:30',
            'limit' => 'nullable|integer|min:1',
            'resolve' => 'nullable',
        ]);

        $user = $request->user();
        abort_if($user->has_roles && ! UserRoleService::can('can-view-discover', $user->id), 403, 'Invalid permissions for this action');

        AccountService::setLastActive($user->id);
        $query = $request->input('q');
        $limit = $request->input('limit') ?? 20;
        if ($limit > 20) {
            $limit = 20;
        }
        $resolve = $request->boolean('resolve', false);
        $q = $query . '%';

        $profiles = Profile::where('username', 'like', $q)
            ->whereActive()
            ->orderByDesc('followers_count')
            ->limit($limit)
            ->pluck('id')
            ->map(function ($id) {
                return AccountService::getMastodon($id);
            })
            ->filter(function ($account) {
                return $account && isset($account['id']);
            })
            ->values();

        return $this->json($profiles);
    }

    /**
     * GET /api/v1/blocks
     *
     *
     *
     * @return \App\Transformer\Api\AccountTransformer
     */
    public function accountBlocks(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $this->validate($request, [
            'limit' => 'sometimes|integer|min:1',
            'page' => 'sometimes|integer|min:1',
        ]);

        $user = $request->user();
        $limit = $request->input('limit') ?? 40;
        if ($limit > 80) {
            $limit = 80;
        }

        $blocks = UserFilter::select('filterable_id', 'filterable_type', 'filter_type', 'user_id')
            ->whereUserId($user->profile_id)
            ->whereFilterableType('App\Profile')
            ->whereFilterType('block')
            ->orderByDesc('id')
            ->simplePaginate($limit)
            ->withQueryString();

        $res = $blocks->pluck('filterable_id')
            ->map(function ($id) {
                return AccountService::get($id, true);
            })
            ->filter(function ($account) {
                return $account && isset($account['id']);
            })
            ->values();

        $baseUrl = config('app.url') . '/api/v1/blocks?limit=' . $limit . '&';
        $next = $blocks->nextPageUrl();
        $prev = $blocks->previousPageUrl();

        if ($next && ! $prev) {
            $link = '<' . $next . '>; rel="next"';
        }

        if (! $next && $prev) {
            $link = '<' . $prev . '>; rel="prev"';
        }

        if ($next && $prev) {
            $link = '<' . $next . '>; rel="next",<' . $prev . '>; rel="prev"';
        }
        $headers = isset($link) ? ['Link' => $link] : [];

        return $this->json($res, 200, $headers);
    }

    /**
     * POST /api/v1/accounts/{id}/block
     *
     * @param  int  $id
     * @return \App\Transformer\Api\RelationshipTransformer
     */
    public function accountBlockById(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        $user = $request->user();
        $pid = $user->profile_id ?? $user->profile->id;
        AccountService::setLastActive($user->id);

        if (intval($id) === intval($pid)) {
            abort(400, 'You cannot block yourself');
        }

        $profile = Profile::findOrFail($id);

        abort_if($profile->moved_to_profile_id, 422, 'Cannot block an account that has migrated!');

        if ($profile->user && $profile->user->is_admin == true) {
            abort(400, 'You cannot block an admin');
        }

        $count = UserFilterService::blockCount($pid);
        $maxLimit = (int) config_cache('instance.user_filters.max_user_blocks');
        if ($count == 0) {
            $filterCount = UserFilter::whereUserId($pid)
                ->whereFilterType('block')
                ->get()
                ->map(function ($rec) {
                    return AccountService::get($rec->filterable_id, true);
                })
                ->filter(function ($account) {
                    return $account && isset($account['id']);
                })
                ->values()
                ->count();
            abort_if($filterCount >= $maxLimit, 422, AccountController::FILTER_LIMIT_BLOCK_TEXT . $maxLimit . ' accounts');
        } else {
            abort_if($count >= $maxLimit, 422, AccountController::FILTER_LIMIT_BLOCK_TEXT . $maxLimit . ' accounts');
        }

        $followed = Follower::whereProfileId($profile->id)->whereFollowingId($pid)->first();
        if ($followed) {
            $followed->delete();
            $profile->following_count = Follower::whereProfileId($profile->id)->count();
            $profile->save();
            $selfProfile = $user->profile;
            $selfProfile->followers_count = Follower::whereFollowingId($pid)->count();
            $selfProfile->save();
            FollowerService::remove($profile->id, $pid);
            AccountService::del($pid);
            AccountService::del($profile->id);
        }

        $following = Follower::whereProfileId($pid)->whereFollowingId($profile->id)->first();
        if ($following) {
            $following->delete();
            $profile->followers_count = Follower::whereFollowingId($profile->id)->count();
            $profile->save();
            $selfProfile = $user->profile;
            $selfProfile->following_count = Follower::whereProfileId($pid)->count();
            $selfProfile->save();
            FollowerService::remove($pid, $profile->pid);
            AccountService::del($pid);
            AccountService::del($profile->id);
        }

        Notification::whereProfileId($pid)
            ->whereActorId($profile->id)
            ->get()
            ->map(function ($n) use ($pid) {
                NotificationService::del($pid, $n['id']);
                $n->forceDelete();
            });

        $filter = UserFilter::firstOrCreate([
            'user_id' => $pid,
            'filterable_id' => $profile->id,
            'filterable_type' => 'App\Profile',
            'filter_type' => 'block',
        ]);

        UserFilterService::block($pid, $id);
        RelationshipService::refresh($pid, $id);
        $resource = new Fractal\Resource\Item($profile, new RelationshipTransformer);
        $res = $this->fractal->createData($resource)->toArray();

        return $this->json($res);
    }

    /**
     * POST /api/v1/accounts/{id}/unblock
     *
     * @param  int  $id
     * @return \App\Transformer\Api\RelationshipTransformer
     */
    public function accountUnblockById(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        $user = $request->user();
        $pid = $user->profile_id ?? $user->profile->id;
        AccountService::setLastActive($user->id);

        if (intval($id) === intval($pid)) {
            abort(400, 'You cannot unblock yourself');
        }

        $profile = Profile::findOrFail($id);

        abort_if($profile->moved_to_profile_id, 422, 'Cannot unblock an account that has migrated!');

        $filter = UserFilter::whereUserId($pid)
            ->whereFilterableId($profile->id)
            ->whereFilterableType('App\Profile')
            ->whereFilterType('block')
            ->first();

        if ($filter) {
            $filter->delete();
            UserFilterService::unblock($pid, $profile->id);
        }
        RelationshipService::refresh($pid, $id);

        $resource = new Fractal\Resource\Item($profile, new RelationshipTransformer);
        $res = $this->fractal->createData($resource)->toArray();

        return $this->json($res);
    }

    /**
     * GET /api/v1/custom_emojis
     *
     * Return custom emoji
     *
     * @return array
     */
    public function customEmojis()
    {
        return response(CustomEmojiService::all())->header('Content-Type', 'application/json');
    }

    /**
     * GET /api/v1/domain_blocks
     *
     * Return empty array
     *
     * @return array
     */
    public function accountDomainBlocks(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        return response()->json([]);
    }

    /**
     * GET /api/v1/endorsements
     *
     * Return empty array
     *
     * @return array
     */
    public function accountEndorsements(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        return response()->json([]);
    }

    /**
     * GET /api/v1/favourites
     *
     * Returns collection of liked statuses
     *
     * @return \App\Transformer\Api\StatusTransformer
     */
    public function accountFavourites(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $this->validate($request, [
            'limit' => 'sometimes|integer|min:1',
        ]);

        $user = $request->user();
        $maxId = $request->input('max_id');
        $minId = $request->input('min_id');
        $limit = $request->input('limit') ?? 10;
        if ($limit > 40) {
            $limit = 40;
        }

        $res = Like::whereProfileId($user->profile_id)
            ->when($maxId, function ($q, $maxId) {
                return $q->where('id', '<', $maxId);
            })
            ->when($minId, function ($q, $minId) {
                return $q->where('id', '>', $minId);
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function ($like) {
                $status = StatusService::getMastodon($like['status_id'], false);
                $status['favourited'] = true;
                $status['like_id'] = $like->id;
                $status['liked_at'] = str_replace('+00:00', 'Z', $like->created_at->format(DATE_RFC3339_EXTENDED));

                return $status;
            })
            ->filter(function ($status) {
                return $status && isset($status['id'], $status['like_id']);
            })
            ->values();

        if ($res->count()) {
            $ids = $res->map(function ($status) {
                return $status['like_id'];
            })->filter();

            $max = $ids->min() - 1;
            $min = $ids->max();

            $baseUrl = config('app.url') . '/api/v1/favourites?limit=' . $limit . '&';
            if ($maxId) {
                $link = '<' . $baseUrl . 'max_id=' . $max . '>; rel="next",<' . $baseUrl . 'min_id=' . $min . '>; rel="prev"';
            } else {
                $link = '<' . $baseUrl . 'max_id=' . $max . '>; rel="next"';
            }

            return $this->json($res, 200, ['Link' => $link]);
        } else {
            return $this->json($res);
        }
    }

    /**
     * POST /api/v1/statuses/{id}/favourite
     *
     * @param  int  $id
     * @return \App\Transformer\Api\StatusTransformer
     */
    public function statusFavouriteById(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        $user = $request->user();
        abort_if($user->has_roles && ! UserRoleService::can('can-like', $user->id), 403, 'Invalid permissions for this action');

        $napi = $request->has(self::PF_API_ENTITY_KEY);
        $status = $napi ? StatusService::get($id, false) : StatusService::getMastodon($id, false);

        abort_unless($status, 404);

        abort_if(isset($status['moved'], $status['moved']['id']), 422, 'Cannot like a post from an account that has migrated');

        if ($status && isset($status['account'], $status['account']['acct']) && strpos($status['account']['acct'], '@') != -1) {
            $domain = parse_url($status['account']['url'], PHP_URL_HOST);
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }

        $spid = $status['account']['id'];

        AccountService::setLastActive($user->id);

        if (intval($spid) !== intval($user->profile_id)) {
            if ($status['visibility'] == 'private') {
                abort_if(! FollowerService::follows($user->profile_id, $spid), 403);
            } else {
                abort_if(! in_array($status['visibility'], ['public', 'unlisted']), 403);
            }
        }

        abort_if(
            Like::whereProfileId($user->profile_id)
                ->where('created_at', '>', now()->subDay())
                ->count() >= Like::MAX_PER_DAY,
            429
        );

        $blocks = UserFilterService::blocks($spid);
        if ($blocks && in_array($user->profile_id, $blocks)) {
            abort(422);
        }

        $like = Like::firstOrCreate([
            'profile_id' => $user->profile_id,
            'status_id' => $status['id'],
        ]);

        if ($like->wasRecentlyCreated == true) {
            $like->status_profile_id = $spid;
            $like->is_comment = ! empty($status['in_reply_to_id']);
            $like->save();
            Status::findOrFail($status['id'])->update([
                'likes_count' => ($status['favourites_count'] ?? 0) + 1,
            ]);
            LikePipeline::dispatch($like)->onQueue('feed');
        }

        $status['favourited'] = true;
        $status['favourites_count'] = $status['favourites_count'] + 1;
        $status['bookmarked'] = BookmarkService::get($user->profile_id, $status['id']);
        $status['reblogged'] = ReblogService::get($user->profile_id, $status['id']);

        return $this->json($status);
    }

    /**
     * POST /api/v1/statuses/{id}/unfavourite
     *
     * @param  int  $id
     * @return \App\Transformer\Api\StatusTransformer
     */
    public function statusUnfavouriteById(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        $user = $request->user();
        abort_if($user->has_roles && ! UserRoleService::can('can-like', $user->id), 403, 'Invalid permissions for this action');

        $napi = $request->has(self::PF_API_ENTITY_KEY);
        $status = $napi ? StatusService::get($id, false) : StatusService::getMastodon($id, false);

        abort_unless($status && isset($status['account']), 404);
        abort_if(isset($status['moved'], $status['moved']['id']), 422, 'Cannot unlike a post from an account that has migrated');

        if ($status && isset($status['account'], $status['account']['acct']) && strpos($status['account']['acct'], '@') != -1) {
            $domain = parse_url($status['account']['url'], PHP_URL_HOST);
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }

        $spid = $status['account']['id'];

        AccountService::setLastActive($user->id);

        if (intval($spid) !== intval($user->profile_id)) {
            if ($status['visibility'] == 'private') {
                abort_if(! FollowerService::follows($user->profile_id, $spid), 403);
            } else {
                abort_if(! in_array($status['visibility'], ['public', 'unlisted']), 403);
            }
        }

        $like = Like::whereProfileId($user->profile_id)
            ->whereStatusId($status['id'])
            ->first();

        if ($like) {
            $like->forceDelete();
            $ogStatus = Status::find($status['id']);
            if ($ogStatus) {
                $ogStatus->likes_count = $ogStatus->likes_count > 1 ? $ogStatus->likes_count - 1 : 0;
                $ogStatus->save();
            }
        }

        StatusService::del($status['id']);

        $status['favourited'] = false;
        $status['favourites_count'] = isset($ogStatus) ? $ogStatus->likes_count : $status['favourites_count'] - 1;
        $status['bookmarked'] = BookmarkService::get($user->profile_id, $status['id']);
        $status['reblogged'] = ReblogService::get($user->profile_id, $status['id']);

        return $this->json($status);
    }

    /**
     * GET /api/v1/filters
     *
     *  Return empty response since we filter server side
     *
     * @return array
     */
    public function accountFilters(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        return response()->json([]);
    }

    /**
     * GET /api/v1/follow_requests
     *
     *  Return array of Accounts that have sent follow requests
     *
     * @return \App\Transformer\Api\AccountTransformer
     */
    public function accountFollowRequests(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $this->validate($request, [
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        $user = $request->user();

        $res = FollowRequest::whereFollowingId($user->profile_id)
            ->limit($request->input('limit', 40))
            ->pluck('follower_id')
            ->map(function ($id) {
                return AccountService::getMastodon($id, true);
            })
            ->filter(function ($acct) {
                return $acct && isset($acct['id']);
            })
            ->values();

        return $this->json($res);
    }

    /**
     * POST /api/v1/follow_requests/{id}/authorize
     *
     * @param  int  $id
     * @return null
     */
    public function accountFollowRequestAccept(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('follow'), 403);

        $pid = $request->user()->profile_id;
        $target = AccountService::getMastodon($id);

        abort_if(isset($target['moved'], $target['moved']['id']), 422, 'Cannot accept a request from an account that has migrated!');

        if (! $target) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        if ($target && strpos($target['acct'], '@') != -1) {
            $domain = parse_url($target['url'], PHP_URL_HOST);
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }

        $followRequest = FollowRequest::whereFollowingId($pid)->whereFollowerId($id)->first();

        if (! $followRequest) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $follower = $followRequest->follower;
        $follow = new Follower;
        $follow->profile_id = $follower->id;
        $follow->following_id = $pid;
        $follow->save();

        $profile = Profile::findOrFail($pid);
        $profile->followers_count++;
        $profile->save();
        AccountService::del($profile->id);

        $profile = Profile::findOrFail($follower->id);
        $profile->following_count++;
        $profile->save();
        AccountService::del($profile->id);

        if ($follower->domain != null && $follower->private_key === null) {
            FollowAcceptPipeline::dispatch($followRequest)->onQueue('follow');
        } else {
            FollowPipeline::dispatch($follow);
            $followRequest->delete();
        }

        RelationshipService::refresh($pid, $id);
        $res = RelationshipService::get($pid, $id);
        $res['followed_by'] = true;

        return $this->json($res);
    }

    /**
     * POST /api/v1/follow_requests/{id}/reject
     *
     * @param  int  $id
     * @return null
     */
    public function accountFollowRequestReject(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('follow'), 403);

        $pid = $request->user()->profile_id;
        $target = AccountService::getMastodon($id);

        if (! $target) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        abort_if(isset($target['moved'], $target['moved']['id']), 422, 'Cannot reject a request from an account that has migrated!');

        $followRequest = FollowRequest::whereFollowingId($pid)->whereFollowerId($id)->first();

        if (! $followRequest) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $follower = $followRequest->follower;

        if ($follower->domain != null && $follower->private_key === null) {
            FollowRejectPipeline::dispatch($followRequest)->onQueue('follow');
        } else {
            $followRequest->delete();
        }

        RelationshipService::refresh($pid, $id);
        $res = RelationshipService::get($pid, $id);

        return $this->json($res);
    }

    /**
     * GET /api/v1/suggestions
     *
     *   Return empty array as we don't support suggestions
     *
     * @return null
     */
    public function accountSuggestions(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        // todo

        return response()->json([]);
    }

    /**
     * GET /api/v1/instance
     *
     *   Information about the server.
     *
     * @return Instance
     */
    public function instance(Request $request)
    {
        $res = Cache::remember('api:v1:instance-data-response-v1', 1800, function () {
            $contact = Cache::remember('api:v1:instance-data:contact', 604800, function () {
                if (config_cache('instance.admin.pid')) {
                    return AccountService::getMastodon(config_cache('instance.admin.pid'), true);
                }
                $admin = User::whereIsAdmin(true)->first();

                return $admin && isset($admin->profile_id) ?
                    AccountService::getMastodon($admin->profile_id, true) :
                    null;
            });

            $stats = Cache::remember('api:v1:instance-data:stats:v0', 43200, function () {
                return [
                    'user_count' => (int) User::count(),
                    'status_count' => (int) StatusService::totalLocalStatuses(),
                    'domain_count' => (int) Instance::count(),
                ];
            });

            $rules = Cache::remember('api:v1:instance-data:rules', 604800, function () {
                return config_cache('app.rules') ?
                    collect(json_decode(config_cache('app.rules'), true))
                    ->map(function ($rule, $key) {
                        $id = $key + 1;

                        return [
                            'id' => "{$id}",
                            'text' => $rule,
                        ];
                    })
                    ->toArray() : [];
            });

            return [
                'uri' => config('pixelfed.domain.app'),
                'title' => config_cache('app.name'),
                'short_description' => config_cache('app.short_description'),
                'description' => config_cache('app.description'),
                'email' => config('instance.email'),
                'version' => '3.5.3 (compatible; Pixelfed ' . config('pixelfed.version') . ')',
                'urls' => [
                    'streaming_api' => null,
                ],
                'stats' => $stats,
                'thumbnail' => config_cache('app.banner_image') ?? url(Storage::url('public/headers/default.jpg')),
                'languages' => [config('app.locale')],
                'registrations' => (bool) config_cache('pixelfed.open_registration'),
                'approval_required' => (bool) config_cache('instance.curated_registration.enabled'),
                'contact_account' => $contact,
                'rules' => $rules,
                'mobile_registration' => (bool) config_cache('pixelfed.open_registration') && config('auth.in_app_registration'),
                'configuration' => [
                    'media_attachments' => [
                        'image_matrix_limit' => 2073600,
                        'image_size_limit' => config_cache('pixelfed.max_photo_size') * 1024,
                        'supported_mime_types' => explode(',', config_cache('pixelfed.media_types')),
                        'video_frame_rate_limit' => 120,
                        'video_matrix_limit' => 2073600,
                        'video_size_limit' => config_cache('pixelfed.max_photo_size') * 1024,
                    ],
                    'polls' => [
                        'max_characters_per_option' => 50,
                        'max_expiration' => 2629746,
                        'max_options' => 4,
                        'min_expiration' => 300,
                    ],
                    'statuses' => [
                        'characters_reserved_per_url' => 23,
                        'max_characters' => (int) config_cache('pixelfed.max_caption_length'),
                        'max_media_attachments' => (int) config_cache('pixelfed.max_album_length'),
                    ],
                ],
            ];
        });

        return $this->json($res);
    }

    /**
     * GET /api/v1/lists
     *
     *   Return empty array as we don't support lists
     *
     * @return null
     */
    public function accountLists(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        return response()->json([]);
    }

    /**
     * GET /api/v1/accounts/{id}/lists
     *
     * @param  int  $id
     * @return null
     */
    public function accountListsById(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        return response()->json([]);
    }

    /**
     * POST /api/v1/media
     *
     *
     * @return MediaTransformer
     */
    public function mediaUpload(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        $this->validate($request, [
            'file.*' => [
                'required_without:file',
                'mimetypes:' . config_cache('pixelfed.media_types'),
                'max:' . config_cache('pixelfed.max_photo_size'),
            ],
            'file' => [
                'required_without:file.*',
                'mimetypes:' . config_cache('pixelfed.media_types'),
                'max:' . config_cache('pixelfed.max_photo_size'),
            ],
            'filter_name' => 'nullable|string|max:24',
            'filter_class' => 'nullable|alpha_dash|max:24',
            'description' => 'nullable|string|max:' . config_cache('pixelfed.max_altext_length'),
        ]);

        $user = $request->user();
        abort_if($user->has_roles && ! UserRoleService::can('can-post', $user->id), 403, 'Invalid permissions for this action');

        AccountService::setLastActive($user->id);

        if ($user->last_active_at == null) {
            return [];
        }

        if (empty($request->file('file'))) {
            return response('', 422);
        }

        $limitKey = 'compose:rate-limit:media-upload:' . $user->id;
        $limitTtl = now()->addMinutes(15);
        $limitReached = Cache::remember($limitKey, $limitTtl, function () use ($user) {
            $dailyLimit = Media::whereUserId($user->id)->where('created_at', '>', now()->subDays(1))->count();

            return $dailyLimit >= 1250;
        });
        abort_if($limitReached == true, 429);

        $profile = $user->profile;

        $accountSize = UserStorageService::get($user->id);
        abort_if($accountSize === -1, 403, 'Invalid request.');
        $photo = $request->file('file');
        $fileSize = $photo->getSize();
        $sizeInKbs = (int) ceil($fileSize / 1000);
        $updatedAccountSize = (int) $accountSize + (int) $sizeInKbs;

        if ((bool) config_cache('pixelfed.enforce_account_limit') == true) {
            $limit = (int) config_cache('pixelfed.max_account_size');
            if ($updatedAccountSize >= $limit) {
                abort(403, 'Account size limit reached.');
            }
        }

        $filterClass = in_array($request->input('filter_class'), Filter::classes()) ? $request->input('filter_class') : null;
        $filterName = in_array($request->input('filter_name'), Filter::names()) ? $request->input('filter_name') : null;

        $mimes = explode(',', config_cache('pixelfed.media_types'));
        if (in_array($photo->getMimeType(), $mimes) == false) {
            abort(403, 'Invalid or unsupported mime type.');
        }

        $storagePath = MediaPathService::get($user, 2);
        $path = $photo->storePublicly($storagePath);
        $hash = \hash_file('sha256', $photo);
        $license = null;
        $mime = $photo->getMimeType();

        // if($photo->getMimeType() == 'image/heic') {
        //  abort_if(config('image.driver') !== 'imagick', 422, 'Invalid media type');
        //  abort_if(!in_array('HEIC', \Imagick::queryformats()), 422, 'Unsupported media type');
        //  $oldPath = $path;
        //  $path = str_replace('.heic', '.jpg', $path);
        //  $mime = 'image/jpeg';
        //  \Image::make($photo)->save(storage_path("app/{$path}"));
        //  @unlink(storage_path("app/{$oldPath}"));
        // }

        $settings = UserSetting::whereUserId($user->id)->first();

        if ($settings && ! empty($settings->compose_settings)) {
            $compose = $settings->compose_settings;

            if (isset($compose['default_license']) && $compose['default_license'] != 1) {
                $license = $compose['default_license'];
            }
        }

        abort_if(MediaBlocklistService::exists($hash) == true, 451);

        $media = new Media;
        $media->status_id = null;
        $media->profile_id = $profile->id;
        $media->user_id = $user->id;
        $media->media_path = $path;
        $media->original_sha256 = $hash;
        $media->size = $photo->getSize();
        $media->mime = $mime;
        $media->caption = $request->input('description') ?? '';
        $media->filter_class = $filterClass;
        $media->filter_name = $filterName;
        if ($license) {
            $media->license = $license;
        }
        $media->save();

        switch ($media->mime) {
            case 'image/jpg':
            case 'image/jpeg':
            case 'image/png':
            case 'image/webp':
            case 'image/heic':
            case 'image/avif':
                ImageOptimize::dispatch($media)->onQueue('mmo');
                break;

            case 'video/mp4':
                VideoThumbnail::dispatch($media)->onQueue('mmo');
                $preview_url = '/storage/no-preview.png';
                $url = '/storage/no-preview.png';
                break;
        }

        $user->storage_used = (int) $updatedAccountSize;
        $user->storage_used_updated_at = now();
        $user->save();

        Cache::forget($limitKey);
        $resource = new Fractal\Resource\Item($media, new MediaTransformer);
        $res = $this->fractal->createData($resource)->toArray();
        $res['preview_url'] = $media->url() . '?v=' . time();
        $res['url'] = $media->url() . '?v=' . time();

        return $this->json($res);
    }

    /**
     * PUT /api/v1/media/{id}
     *
     * @param  int  $id
     * @return MediaTransformer
     */
    public function mediaUpdate(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        $this->validate($request, [
            'description' => 'nullable|string|max:' . config_cache('pixelfed.max_altext_length'),
        ]);

        $user = $request->user();
        abort_if($user->has_roles && ! UserRoleService::can('can-post', $user->id), 403, 'Invalid permissions for this action');

        AccountService::setLastActive($user->id);

        $media = Media::whereUserId($user->id)
            ->whereProfileId($user->profile_id)
            ->findOrFail($id);

        $executed = RateLimiter::attempt(
            'media:update:' . $user->id,
            10,
            function () use ($media, $request) {
                $caption = Purify::clean($request->input('description'));

                if ($caption != $media->caption) {
                    $media->caption = $caption;
                    $media->save();

                    if ($media->status_id) {
                        MediaService::del($media->status_id);
                        StatusService::del($media->status_id);
                    }
                }
            }
        );

        if (! $executed) {
            return response()->json([
                'error' => 'Too many attempts. Try again in a few minutes.',
            ], 429);
        }

        $fractal = new Fractal\Manager;
        $fractal->setSerializer(new ArraySerializer);
        $resource = new Fractal\Resource\Item($media, new MediaTransformer);

        return $this->json($fractal->createData($resource)->toArray());
    }

    /**
     * GET /api/v1/media/{id}
     *
     * @param  int  $id
     * @return MediaTransformer
     */
    public function mediaGet(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $user = $request->user();
        abort_if($user->has_roles && ! UserRoleService::can('can-post', $user->id), 403, 'Invalid permissions for this action');
        AccountService::setLastActive($user->id);

        $media = Media::whereUserId($user->id)
            ->whereNull('status_id')
            ->findOrFail($id);

        $resource = new Fractal\Resource\Item($media, new MediaTransformer);
        $res = $this->fractal->createData($resource)->toArray();

        return $this->json($res);
    }

    /**
     * POST /api/v2/media
     *
     *
     * @return MediaTransformer
     */
    public function mediaUploadV2(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        $this->validate($request, [
            'file.*' => [
                'required_without:file',
                'mimetypes:' . config_cache('pixelfed.media_types'),
                'max:' . config_cache('pixelfed.max_photo_size'),
            ],
            'file' => [
                'required_without:file.*',
                'mimetypes:' . config_cache('pixelfed.media_types'),
                'max:' . config_cache('pixelfed.max_photo_size'),
            ],
            'filter_name' => 'nullable|string|max:24',
            'filter_class' => 'nullable|alpha_dash|max:24',
            'description' => 'nullable|string|max:' . config_cache('pixelfed.max_altext_length'),
            'replace_id' => 'sometimes',
        ]);

        $user = $request->user();
        abort_if($user->has_roles && ! UserRoleService::can('can-post', $user->id), 403, 'Invalid permissions for this action');

        if ($user->last_active_at == null) {
            return [];
        }

        AccountService::setLastActive($user->id);

        if (empty($request->file('file'))) {
            return response('', 422);
        }

        $limitKey = 'compose:rate-limit:media-upload:' . $user->id;
        $limitTtl = now()->addMinutes(15);
        $limitReached = Cache::remember($limitKey, $limitTtl, function () use ($user) {
            $dailyLimit = Media::whereUserId($user->id)->where('created_at', '>', now()->subDays(1))->count();

            return $dailyLimit >= 1250;
        });
        abort_if($limitReached == true, 429);

        $profile = $user->profile;

        $accountSize = UserStorageService::get($user->id);
        abort_if($accountSize === -1, 403, 'Invalid request.');
        $photo = $request->file('file');
        $fileSize = $photo->getSize();
        $sizeInKbs = (int) ceil($fileSize / 1000);
        $updatedAccountSize = (int) $accountSize + (int) $sizeInKbs;

        if ((bool) config_cache('pixelfed.enforce_account_limit') == true) {
            $limit = (int) config_cache('pixelfed.max_account_size');
            if ($updatedAccountSize >= $limit) {
                abort(403, 'Account size limit reached.');
            }
        }

        $filterClass = in_array($request->input('filter_class'), Filter::classes()) ? $request->input('filter_class') : null;
        $filterName = in_array($request->input('filter_name'), Filter::names()) ? $request->input('filter_name') : null;

        $mimes = explode(',', config_cache('pixelfed.media_types'));
        if (in_array($photo->getMimeType(), $mimes) == false) {
            abort(403, 'Invalid or unsupported mime type.');
        }

        $storagePath = MediaPathService::get($user, 2);
        $path = $photo->storePublicly($storagePath);
        $hash = \hash_file('sha256', $photo);
        $license = null;
        $mime = $photo->getMimeType();

        $settings = UserSetting::whereUserId($user->id)->first();

        if ($settings && ! empty($settings->compose_settings)) {
            $compose = $settings->compose_settings;

            if (isset($compose['default_license']) && $compose['default_license'] != 1) {
                $license = $compose['default_license'];
            }
        }

        abort_if(MediaBlocklistService::exists($hash) == true, 451);

        if ($request->has('replace_id')) {
            $rpid = $request->input('replace_id');
            $removeMedia = Media::whereNull('status_id')
                ->whereUserId($user->id)
                ->whereProfileId($profile->id)
                ->where('created_at', '>', now()->subHours(2))
                ->find($rpid);
            if ($removeMedia) {
                $dateTime = Carbon::now();
                MediaDeletePipeline::dispatch($removeMedia)
                    ->onQueue('mmo')
                    ->delay($dateTime->addMinutes(15));
            }
        }

        $media = new Media;
        $media->status_id = null;
        $media->profile_id = $profile->id;
        $media->user_id = $user->id;
        $media->media_path = $path;
        $media->original_sha256 = $hash;
        $media->size = $photo->getSize();
        $media->mime = $mime;
        $media->caption = $request->input('description') ?? '';
        $media->filter_class = $filterClass;
        $media->filter_name = $filterName;
        if ($license) {
            $media->license = $license;
        }
        $media->save();

        switch ($media->mime) {
            case 'image/jpg':
            case 'image/jpeg':
            case 'image/png':
            case 'image/webp':
            case 'image/heic':
            case 'image/avif':
                ImageOptimize::dispatch($media)->onQueue('mmo');
                break;

            case 'video/mp4':
                VideoThumbnail::dispatch($media)->onQueue('mmo');
                $preview_url = '/storage/no-preview.png';
                $url = '/storage/no-preview.png';
                break;
        }

        $user->storage_used = (int) $updatedAccountSize;
        $user->storage_used_updated_at = now();
        $user->save();

        Cache::forget($limitKey);
        $resource = new Fractal\Resource\Item($media, new MediaTransformer);
        $res = $this->fractal->createData($resource)->toArray();
        $res['preview_url'] = $media->url() . '?v=' . time();
        $res['url'] = null;

        return $this->json($res, 202);
    }

    /**
     * GET /api/v1/mutes
     *
     *
     * @return AccountTransformer
     */
    public function accountMutes(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $this->validate($request, [
            'limit' => 'sometimes|integer|min:1',
        ]);

        $user = $request->user();
        $limit = $request->input('limit', 40);
        if ($limit > 80) {
            $limit = 80;
        }

        $mutes = UserFilter::whereUserId($user->profile_id)
            ->whereFilterableType('App\Profile')
            ->whereFilterType('mute')
            ->orderByDesc('id')
            ->simplePaginate($limit)
            ->withQueryString();

        $res = $mutes->pluck('filterable_id')
            ->map(function ($id) {
                return AccountService::get($id, true);
            })
            ->filter(function ($account) {
                return $account && isset($account['id']);
            })
            ->values();

        $baseUrl = config('app.url') . '/api/v1/mutes?limit=' . $limit . '&';
        $next = $mutes->nextPageUrl();
        $prev = $mutes->previousPageUrl();

        if ($next && ! $prev) {
            $link = '<' . $next . '>; rel="next"';
        }

        if (! $next && $prev) {
            $link = '<' . $prev . '>; rel="prev"';
        }

        if ($next && $prev) {
            $link = '<' . $next . '>; rel="next",<' . $prev . '>; rel="prev"';
        }
        $headers = isset($link) ? ['Link' => $link] : [];

        return $this->json($res, 200, $headers);
    }

    /**
     * POST /api/v1/accounts/{id}/mute
     *
     * @param  int  $id
     * @return RelationshipTransformer
     */
    public function accountMuteById(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        $user = $request->user();
        $pid = $user->profile_id;

        if (intval($pid) === intval($id)) {
            return $this->json(['error' => 'You cannot mute yourself'], 500);
        }

        $account = Profile::findOrFail($id);

        abort_if($account->moved_to_profile_id, 422, 'Cannot mute an account that has migrated!');

        if ($account && $account->domain) {
            $domain = $account->domain;
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }

        $count = UserFilterService::muteCount($pid);
        $maxLimit = (int) config_cache('instance.user_filters.max_user_mutes');
        if ($count == 0) {
            $filterCount = UserFilter::whereUserId($pid)
                ->whereFilterType('mute')
                ->get()
                ->map(function ($rec) {
                    return AccountService::get($rec->filterable_id, true);
                })
                ->filter(function ($account) {
                    return $account && isset($account['id']);
                })
                ->values()
                ->count();
            abort_if($filterCount >= $maxLimit, 422, AccountController::FILTER_LIMIT_MUTE_TEXT . $maxLimit . ' accounts');
        } else {
            abort_if($count >= $maxLimit, 422, AccountController::FILTER_LIMIT_MUTE_TEXT . $maxLimit . ' accounts');
        }

        $filter = UserFilter::firstOrCreate([
            'user_id' => $pid,
            'filterable_id' => $account->id,
            'filterable_type' => 'App\Profile',
            'filter_type' => 'mute',
        ]);

        RelationshipService::refresh($pid, $id);

        $resource = new Fractal\Resource\Item($account, new RelationshipTransformer);
        $res = $this->fractal->createData($resource)->toArray();

        return $this->json($res);
    }

    /**
     * POST /api/v1/accounts/{id}/unmute
     *
     * @param  int  $id
     * @return RelationshipTransformer
     */
    public function accountUnmuteById(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        $user = $request->user();
        $pid = $user->profile_id;

        if (intval($pid) === intval($id)) {
            return $this->json(['error' => 'You cannot unmute yourself'], 500);
        }

        $profile = Profile::findOrFail($id);

        abort_if($profile->moved_to_profile_id, 422, 'Cannot unmute an account that has migrated!');

        $filter = UserFilter::whereUserId($pid)
            ->whereFilterableId($profile->id)
            ->whereFilterableType('App\Profile')
            ->whereFilterType('mute')
            ->first();

        if ($filter) {
            $filter->delete();
            UserFilterService::unmute($pid, $profile->id);
        }

        RelationshipService::refresh($pid, $id);

        $resource = new Fractal\Resource\Item($profile, new RelationshipTransformer);
        $res = $this->fractal->createData($resource)->toArray();

        return $this->json($res);
    }

    /**
     * GET /api/v1/notifications
     *
     *
     * @return NotificationTransformer
     */
    public function accountNotifications(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $this->validate($request, [
            'limit' => 'sometimes|integer|min:1',
            'min_id' => 'nullable|integer|min:1|max:' . PHP_INT_MAX,
            'max_id' => 'nullable|integer|min:1|max:' . PHP_INT_MAX,
            'since_id' => 'nullable|integer|min:1|max:' . PHP_INT_MAX,
            'types[]' => 'sometimes|array',
            'types[].*' => 'string|in:mention,reblog,follow,favourite',
            'type' => 'sometimes|string|in:mention,reblog,follow,favourite',
            '_pe' => 'sometimes',
        ]);

        $pid = $request->user()->profile_id;
        $limit = $request->input('limit', 20);
        $ogLimit = $request->input('limit', 20);
        if ($limit > 40) {
            $limit = 40;
            $ogLimit = 40;
        }

        $since = $request->input('since_id');
        $min = $request->input('min_id');
        $max = $request->input('max_id');
        $pe = $request->filled('_pe');

        if (! $since && ! $min && ! $max) {
            $min = 1;
        }

        if ($since) {
            $min = $since + 1;
        }

        $types = $request->input('types');

        if ($request->has('types')) {
            $limit = 150;
        }

        $maxId = null;
        $minId = null;
        AccountService::setLastActive($request->user()->id);

        $res = $max ?
            NotificationService::getMaxMastodon($pid, $max, $limit) :
            NotificationService::getMinMastodon($pid, $min ?? $since, $limit);
        $ids = $max ?
            NotificationService::getRankedMaxId($pid, $max, $limit) :
            NotificationService::getRankedMinId($pid, $min ?? $since, $limit);
        if (! empty($ids)) {
            $maxId = max($ids);
            $minId = min($ids);
        }

        if (empty($res)) {
            if (! Cache::has('pf:services:notifications:hasSynced:' . $pid)) {
                Cache::put('pf:services:notifications:hasSynced:' . $pid, 1, 1209600);
                NotificationWarmUserCache::dispatch($pid);
            }
        }

        if ($request->has('types')) {
            $typesParams = collect($types)->implode('&types[]=');
            $baseUrl = config('app.url') . '/api/v1/notifications?types[]=' . $typesParams . '&limit=' . $ogLimit . '&';
        } else {
            $baseUrl = config('app.url') . '/api/v1/notifications?limit=' . $ogLimit . '&';
        }

        if ($minId == $maxId) {
            $minId = null;
        }

        $res = collect($res)
            ->map(function ($n) use ($pe) {
                if (! $pe) {
                    if ($n['type'] == 'comment') {
                        $n['type'] = 'mention';

                        return $n;
                    }

                    return $n;
                }

                return $n;
            })
            ->filter(function ($n) use ($pe) {
                if (in_array($n['type'], ['mention', 'reblog', 'favourite'])) {
                    return isset($n['status'], $n['status']['id']);
                }

                if (! $pe) {
                    if (in_array($n['type'], [
                        'tagged',
                        'modlog',
                        'story:react',
                        'story:comment',
                        'group:comment',
                        'group:join:approved',
                        'group:join:rejected',
                    ])) {
                        return false;
                    }

                    return isset($n['account'], $n['account']['id']);
                }

                return true;
            })
            ->map(function ($n) use ($pid) {
                if (isset($n['status'])) {
                    $n['status']['favourited'] = (bool) LikeService::liked($pid, $n['status']['id']);
                    $n['status']['reblogged'] = (bool) ReblogService::get($pid, $n['status']['id']);
                    $n['status']['bookmarked'] = (bool) BookmarkService::get($pid, $n['status']['id']);
                }

                return $n;
            })
            ->filter(function ($n) use ($types) {
                if (! $types) {
                    return true;
                }

                return in_array($n['type'], $types);
            })
            ->take($ogLimit)
            ->values();

        if ($maxId) {
            $link = '<' . $baseUrl . 'max_id=' . $minId . '>; rel="next"';
        }

        if ($minId) {
            $link = '<' . $baseUrl . 'min_id=' . $maxId . '>; rel="prev"';
        }

        if ($maxId && $minId) {
            $link = '<' . $baseUrl . 'max_id=' . $minId . '>; rel="next",<' . $baseUrl . 'min_id=' . $maxId . '>; rel="prev"';
        }

        $headers = isset($link) ? ['Link' => $link] : [];

        return $this->json($res, 200, $headers);
    }

    /**
     * GET /api/v1/timelines/home
     *
     *
     * @return StatusTransformer
     */
    public function timelineHome(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $this->validate($request, [
            'page' => 'sometimes|integer|max:40',
            'min_id' => 'sometimes|integer|min:0|max:' . PHP_INT_MAX,
            'max_id' => 'sometimes|integer|min:0|max:' . PHP_INT_MAX,
            'limit' => 'sometimes|integer|min:1',
            'include_reblogs' => 'sometimes',
        ]);

        $napi = $request->has(self::PF_API_ENTITY_KEY);
        $page = $request->input('page');
        $min = $request->input('min_id');
        $max = $request->input('max_id');
        $limit = $request->input('limit') ?? 20;
        if ($limit > 40) {
            $limit = 40;
        }
        $pid = $request->user()->profile_id;
        $includeReblogs = $request->filled('include_reblogs') ? $request->boolean('include_reblogs') : false;
        $nullFields = $includeReblogs ?
            ['in_reply_to_id'] :
            ['in_reply_to_id', 'reblog_of_id'];
        $inTypes = $includeReblogs ?
            ['photo', 'photo:album', 'video', 'video:album', 'photo:video:album', 'share'] :
            ['photo', 'photo:album', 'video', 'video:album', 'photo:video:album'];
        AccountService::setLastActive($request->user()->id);

        $cachedFilters = CustomFilter::getCachedFiltersForAccount($pid);

        $homeFilters = array_filter($cachedFilters, function ($item) {
            [$filter, $rules] = $item;

            return in_array('home', $filter->context);
        });

        if (config('exp.cached_home_timeline')) {
            $paddedLimit = $includeReblogs ? $limit + 10 : $limit + 50;
            if ($min || $max) {
                if ($request->has('min_id')) {
                    $res = HomeTimelineService::getRankedMinId($pid, $min ?? 0, $paddedLimit);
                } else {
                    $res = HomeTimelineService::getRankedMaxId($pid, $max ?? 0, $paddedLimit);
                }
            } else {
                $res = HomeTimelineService::get($pid, 0, $paddedLimit);
            }

            if (! $res) {
                $res = Cache::has('pf:services:apiv1:home:cached:coldbootcheck:' . $pid);
                if (! $res) {
                    Cache::set('pf:services:apiv1:home:cached:coldbootcheck:' . $pid, 1, 86400);
                    FeedWarmCachePipeline::dispatchSync($pid);

                    return response()->json([], 206);
                } else {
                    Cache::set('pf:services:apiv1:home:cached:coldbootcheck:' . $pid, 1, 86400);

                    return response()->json([], 206);
                }
            }

            $res = collect($res)
                ->map(function ($id) use ($napi) {
                    return $napi ? StatusService::get($id, false) : StatusService::getMastodon($id, false);
                })
                ->filter(function ($res) {
                    return $res && isset($res['account']);
                })
                ->filter(function ($s) use ($includeReblogs) {
                    return $includeReblogs ? true : $s['reblog'] == null;
                })
                ->map(function ($status) use ($homeFilters) {
                    $filterResults = CustomFilter::applyCachedFilters($homeFilters, $status);

                    if (! empty($filterResults)) {
                        $status['filtered'] = $filterResults;
                        $shouldHide = collect($filterResults)->contains(function ($result) {
                            return $result['filter']['filter_action'] === 'hide';
                        });

                        if ($shouldHide) {
                            return null;
                        }
                    }

                    return $status;
                })
                ->filter()
                ->take($limit)
                ->map(function ($status) use ($pid) {
                    if ($pid) {
                        $status['favourited'] = (bool) LikeService::liked($pid, $status['id']);
                        $status['reblogged'] = (bool) ReblogService::get($pid, $status['id']);
                        $status['bookmarked'] = (bool) BookmarkService::get($pid, $status['id']);
                    }

                    return $status;
                })
                ->values();

            $baseUrl = config('app.url') . '/api/v1/timelines/home?limit=' . $limit . '&';
            $minId = $res->map(function ($s) {
                return ['id' => $s['id']];
            })->min('id');
            $maxId = $res->map(function ($s) {
                return ['id' => $s['id']];
            })->max('id');

            if ($minId == $maxId) {
                $minId = null;
            }

            if ($maxId && $res->count() >= $limit) {
                $link = '<' . $baseUrl . 'max_id=' . $minId . '>; rel="next"';
            }

            if ($minId) {
                $link = '<' . $baseUrl . 'min_id=' . $maxId . '>; rel="prev"';
            }

            if ($maxId && $minId) {
                $link = '<' . $baseUrl . 'max_id=' . $minId . '>; rel="next",<' . $baseUrl . 'min_id=' . $maxId . '>; rel="prev"';
            }

            $headers = isset($link) ? ['Link' => $link] : [];

            return $this->json($res->toArray(), 200, $headers);
        }

        $following = Cache::remember('profile:following:' . $pid, 1209600, function () use ($pid) {
            $following = Follower::whereProfileId($pid)->pluck('following_id');

            return $following->push($pid)->toArray();
        });

        $muted = UserFilterService::mutes($pid);

        if ($muted && count($muted)) {
            $following = array_diff($following, $muted);
        }

        if ($min || $max) {
            $dir = $min ? '>' : '<';
            $id = $min ?? $max;
            $res = Status::select(
                'id',
                'profile_id',
                'type',
                'visibility',
                'in_reply_to_id',
                'reblog_of_id'
            )
                ->where('id', $dir, $id)
                ->whereNull($nullFields)
                ->whereIntegerInRaw('profile_id', $following)
                ->whereIn('type', $inTypes)
                ->whereIn('visibility', ['public', 'unlisted', 'private'])
                ->orderByDesc('id')
                ->take(($limit * 2))
                ->get()
                ->map(function ($s) use ($pid, $napi) {
                    try {
                        $account = $napi ? AccountService::get($s['profile_id'], true) : AccountService::getMastodon($s['profile_id'], true);
                        if (! $account) {
                            return false;
                        }
                        $status = $napi ? StatusService::get($s['id'], false) : StatusService::getMastodon($s['id'], false);
                        if (! $status || ! isset($status['account']) || ! isset($status['account']['id'])) {
                            return false;
                        }
                    } catch (\Exception $e) {
                        return false;
                    }

                    $status['account'] = $account;

                    if ($pid) {
                        $status['favourited'] = (bool) LikeService::liked($pid, $s['id']);
                        $status['reblogged'] = (bool) ReblogService::get($pid, $status['id']);
                        $status['bookmarked'] = (bool) BookmarkService::get($pid, $status['id']);
                    }

                    return $status;
                })
                ->filter(function ($status) {
                    return $status && isset($status['account']);
                })
                ->map(function ($status) use ($pid) {
                    if (! empty($status['reblog'])) {
                        $status['reblog']['favourited'] = (bool) LikeService::liked($pid, $status['reblog']['id']);
                        $status['reblog']['reblogged'] = (bool) ReblogService::get($pid, $status['reblog']['id']);
                        $status['bookmarked'] = (bool) BookmarkService::get($pid, $status['id']);
                    }

                    return $status;
                })
                ->map(function ($status) use ($homeFilters) {
                    $filterResults = CustomFilter::applyCachedFilters($homeFilters, $status);

                    if (! empty($filterResults)) {
                        $status['filtered'] = $filterResults;
                        $shouldHide = collect($filterResults)->contains(function ($result) {
                            return $result['filter']['filter_action'] === 'hide';
                        });

                        if ($shouldHide) {
                            return null;
                        }
                    }

                    return $status;
                })
                ->filter()
                ->take($limit)
                ->values();
        } else {
            $res = Status::select(
                'id',
                'profile_id',
                'type',
                'visibility',
                'in_reply_to_id',
                'reblog_of_id',
            )
                ->whereNull($nullFields)
                ->whereIntegerInRaw('profile_id', $following)
                ->whereIn('type', $inTypes)
                ->whereIn('visibility', ['public', 'unlisted', 'private'])
                ->orderByDesc('id')
                ->take(($limit * 2))
                ->get()
                ->map(function ($s) use ($pid, $napi) {
                    try {
                        $account = $napi ? AccountService::get($s['profile_id'], true) : AccountService::getMastodon($s['profile_id'], true);
                        if (! $account) {
                            return false;
                        }
                        $status = $napi ? StatusService::get($s['id'], false) : StatusService::getMastodon($s['id'], false);
                        if (! $status || ! isset($status['account']) || ! isset($status['account']['id'])) {
                            return false;
                        }
                    } catch (\Exception $e) {
                        return false;
                    }

                    $status['account'] = $account;

                    if ($pid) {
                        $status['favourited'] = (bool) LikeService::liked($pid, $s['id']);
                        $status['reblogged'] = (bool) ReblogService::get($pid, $status['id']);
                        $status['bookmarked'] = (bool) BookmarkService::get($pid, $status['id']);
                    }

                    return $status;
                })
                ->filter(function ($status) {
                    return $status && isset($status['account']);
                })
                ->map(function ($status) use ($pid) {
                    if (! empty($status['reblog'])) {
                        $status['reblog']['favourited'] = (bool) LikeService::liked($pid, $status['reblog']['id']);
                        $status['reblog']['reblogged'] = (bool) ReblogService::get($pid, $status['reblog']['id']);
                        $status['bookmarked'] = (bool) BookmarkService::get($pid, $status['id']);
                    }

                    return $status;
                })
                ->map(function ($status) use ($homeFilters) {
                    $filterResults = CustomFilter::applyCachedFilters($homeFilters, $status);

                    if (! empty($filterResults)) {
                        $status['filtered'] = $filterResults;
                        $shouldHide = collect($filterResults)->contains(function ($result) {
                            return $result['filter']['filter_action'] === 'hide';
                        });

                        if ($shouldHide) {
                            return null;
                        }
                    }

                    return $status;
                })
                ->filter()
                ->take($limit)
                ->values();
        }

        $baseUrl = config('app.url') . '/api/v1/timelines/home?limit=' . $limit . '&';
        $minId = $res->map(function ($s) {
            return ['id' => $s['id']];
        })->min('id');
        $maxId = $res->map(function ($s) {
            return ['id' => $s['id']];
        })->max('id');

        if ($minId == $maxId) {
            $minId = null;
        }

        if ($maxId) {
            $link = '<' . $baseUrl . 'max_id=' . $minId . '>; rel="next"';
        }

        if ($minId) {
            $link = '<' . $baseUrl . 'min_id=' . $maxId . '>; rel="prev"';
        }

        if ($maxId && $minId) {
            $link = '<' . $baseUrl . 'max_id=' . $minId . '>; rel="next",<' . $baseUrl . 'min_id=' . $maxId . '>; rel="prev"';
        }

        $headers = isset($link) ? ['Link' => $link] : [];

        return $this->json($res->toArray(), 200, $headers);
    }

    /**
     * GET /api/v1/timelines/public
     *
     *
     * @return StatusTransformer
     */
    public function timelinePublic(Request $request)
    {
        $this->validate($request, [
            'min_id' => 'nullable|integer|min:0|max:' . PHP_INT_MAX,
            'max_id' => 'nullable|integer|min:0|max:' . PHP_INT_MAX,
            'limit' => 'sometimes|integer|min:1',
            'remote' => 'sometimes',
            'local' => 'sometimes',
        ]);

        $napi = $request->has(self::PF_API_ENTITY_KEY);
        $min = $request->input('min_id');
        $max = $request->input('max_id');
        if ($max == 0) {
            $min = 1;
        }
        $minOrMax = $request->anyFilled(['max_id', 'min_id']);
        $limit = $request->input('limit') ?? 20;
        if ($limit > 40) {
            $limit = 40;
        }
        $user = $request->user();
        $pid = $user->profile_id;
        $remote = $request->has('remote') && $request->boolean('remote');
        $local = $request->boolean('local');
        $userRoleKey = $remote ? 'can-view-network-feed' : 'can-view-public-feed';
        if ($user->has_roles && ! UserRoleService::can($userRoleKey, $user->id)) {
            return [];
        }
        $filtered = $user ? UserFilterService::filters($user->profile_id) : [];
        AccountService::setLastActive($user->id);
        $domainBlocks = UserFilterService::domainBlocks($user->profile_id);
        $hideNsfw = config('instance.hide_nsfw_on_public_feeds');
        $amin = SnowflakeService::byDate(now()->subDays(config('federation.network_timeline_days_falloff')));
        $asf = AdminShadowFilterService::getHideFromPublicFeedsList();

        $cachedFilters = CustomFilter::getCachedFiltersForAccount($pid);

        $homeFilters = array_filter($cachedFilters, function ($item) {
            [$filter, $rules] = $item;

            return in_array('public', $filter->context);
        });
        if ($local && $remote) {
            $feed = Status::select(
                'id',
                'uri',
                'type',
                'scope',
                'created_at',
                'profile_id',
                'in_reply_to_id',
                'reblog_of_id'
            )
                ->when($minOrMax, function ($q, $minOrMax) use ($min, $max) {
                    $dir = $min ? '>' : '<';
                    $id = $min ?? $max;

                    return $q->where('id', $dir, $id);
                })
                ->whereNull(['in_reply_to_id', 'reblog_of_id'])
                ->when($hideNsfw, function ($q, $hideNsfw) {
                    return $q->where('is_nsfw', false);
                })
                ->whereIn('type', ['photo', 'photo:album', 'video', 'video:album', 'photo:video:album'])
                ->whereScope('public')
                ->where('id', '>', $amin)
                ->orderByDesc('id')
                ->limit(($limit * 2))
                ->pluck('id')
                ->values()
                ->toArray();
        } elseif ($remote && ! $local) {
            if (config('instance.timeline.network.cached')) {
                Cache::remember('api:v1:timelines:network:cache_check', 10368000, function () {
                    if (NetworkTimelineService::count() == 0) {
                        NetworkTimelineService::warmCache(true, config('instance.timeline.network.cache_dropoff'));
                    }
                });

                if ($max) {
                    $feed = NetworkTimelineService::getRankedMaxId($max, $limit + 5);
                } elseif ($min) {
                    $feed = NetworkTimelineService::getRankedMinId($min, $limit + 5);
                } else {
                    $feed = NetworkTimelineService::get(0, $limit + 5);
                }
            } else {
                $feed = Status::select(
                    'id',
                    'uri',
                    'type',
                    'scope',
                    'local',
                    'created_at',
                    'profile_id',
                    'in_reply_to_id',
                    'reblog_of_id'
                )
                    ->when($minOrMax, function ($q, $minOrMax) use ($min, $max) {
                        $dir = $min ? '>' : '<';
                        $id = $min ?? $max;

                        return $q->where('id', $dir, $id);
                    })
                    ->whereNull(['in_reply_to_id', 'reblog_of_id'])
                    ->when($hideNsfw, function ($q, $hideNsfw) {
                        return $q->where('is_nsfw', false);
                    })
                    ->whereIn('type', ['photo', 'photo:album', 'video', 'video:album', 'photo:video:album'])
                    ->whereLocal(false)
                    ->whereScope('public')
                    ->where('id', '>', $amin)
                    ->orderByDesc('id')
                    ->limit(($limit * 2))
                    ->pluck('id')
                    ->values()
                    ->toArray();
            }
        } else {
            if (config('instance.timeline.local.cached')) {
                Cache::remember('api:v1:timelines:public:cache_check', 10368000, function () {
                    if (PublicTimelineService::count() == 0) {
                        PublicTimelineService::warmCache(true, 400);
                    }
                });

                if ($max) {
                    $feed = PublicTimelineService::getRankedMaxId($max, $limit + 5);
                } elseif ($min) {
                    $feed = PublicTimelineService::getRankedMinId($min, $limit + 5);
                } else {
                    $feed = PublicTimelineService::get(0, $limit + 5);
                }
            } else {
                $feed = Status::select(
                    'id',
                    'uri',
                    'type',
                    'scope',
                    'local',
                    'created_at',
                    'profile_id',
                    'in_reply_to_id',
                    'reblog_of_id'
                )
                    ->when($minOrMax, function ($q, $minOrMax) use ($min, $max) {
                        $dir = $min ? '>' : '<';
                        $id = $min ?? $max;

                        return $q->where('id', $dir, $id);
                    })
                    ->whereNull(['in_reply_to_id', 'reblog_of_id'])
                    ->when($hideNsfw, function ($q, $hideNsfw) {
                        return $q->where('is_nsfw', false);
                    })
                    ->whereIn('type', ['photo', 'photo:album', 'video', 'video:album', 'photo:video:album'])
                    ->whereLocal(true)
                    ->whereScope('public')
                    ->where('id', '>', $amin)
                    ->orderByDesc('id')
                    ->limit(($limit * 2))
                    ->pluck('id')
                    ->values()
                    ->toArray();
            }
        }

        $res = collect($feed)
            ->filter(function ($k) use ($min, $max) {
                if (! $min && ! $max) {
                    return true;
                }

                if ($min) {
                    return $min != $k;
                }

                if ($max) {
                    return $max != $k;
                }
            })
            ->map(function ($k) use ($user, $napi) {
                try {
                    $status = $napi ? StatusService::get($k) : StatusService::getMastodon($k);
                    if (! $status || ! isset($status['account']) || ! isset($status['account']['id'])) {
                        return false;
                    }
                } catch (\Exception $e) {
                    return false;
                }

                $account = $napi ? AccountService::get($status['account']['id'], true) : AccountService::getMastodon($status['account']['id'], true);
                if (! $account) {
                    return false;
                }

                $status['account'] = $account;

                if ($user) {
                    $status['favourited'] = (bool) LikeService::liked($user->profile_id, $k);
                    $status['reblogged'] = (bool) ReblogService::get($user->profile_id, $status['id']);
                    $status['bookmarked'] = (bool) BookmarkService::get($user->profile_id, $status['id']);
                }

                return $status;
            })
            ->filter(function ($s) use ($filtered) {
                return $s && isset($s['account']) && in_array($s['account']['id'], $filtered) == false;
            })
            ->filter(function ($s) use ($domainBlocks) {
                if (! $domainBlocks || ! count($domainBlocks)) {
                    return $s;
                }
                $domain = strtolower(parse_url($s['url'], PHP_URL_HOST));

                return ! in_array($domain, $domainBlocks);
            })
            ->filter(function ($s) use ($asf, $user) {
                if (! $asf || count($asf) === 0) {
                    return true;
                }

                if (in_array($s['account']['id'], $asf)) {
                    if ($user->profile_id == $s['account']['id']) {
                        return true;
                    }

                    return false;
                }

                return true;
            })
            ->map(function ($status) use ($homeFilters) {
                $filterResults = CustomFilter::applyCachedFilters($homeFilters, $status);

                if (! empty($filterResults)) {
                    $status['filtered'] = $filterResults;
                    $shouldHide = collect($filterResults)->contains(function ($result) {
                        return $result['filter']['filter_action'] === 'hide';
                    });

                    if ($shouldHide) {
                        return null;
                    }
                }

                return $status;
            })
            ->filter()
            ->take($limit)
            ->values();

        $baseUrl = config('app.url') . '/api/v1/timelines/public?limit=' . $limit . '&';
        if ($remote) {
            $baseUrl .= 'remote=1&';
        }
        if ($local) {
            $baseUrl .= 'local=1&';
        }
        $minId = $res->map(function ($s) {
            return ['id' => $s['id']];
        })->min('id');
        $maxId = $res->map(function ($s) {
            return ['id' => $s['id']];
        })->max('id');

        if ($minId == $maxId) {
            $minId = null;
        }

        if ($maxId && $res->count() >= $limit) {
            $link = '<' . $baseUrl . 'max_id=' . $minId . '>; rel="next"';
        }

        if ($minId) {
            $link = '<' . $baseUrl . 'min_id=' . $maxId . '>; rel="prev"';
        }

        if ($maxId && $minId) {
            $link = '<' . $baseUrl . 'max_id=' . $minId . '>; rel="next",<' . $baseUrl . 'min_id=' . $maxId . '>; rel="prev"';
        }

        $headers = isset($link) ? ['Link' => $link] : [];

        return $this->json($res->toArray(), 200, $headers);
    }

    /**
     * GET /api/v1/conversations
     *
     *   Not implemented
     *
     * @return array
     */
    public function conversations(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $this->validate($request, [
            'limit' => 'sometimes|integer|min:1|max:40',
            'scope' => 'nullable|in:inbox,sent,requests',
            'min_id' => 'nullable|integer',
            'max_id' => 'nullable|integer',
            'since_id' => 'nullable|integer',
        ]);

        $limit = $request->input('limit', 20);
        if ($limit > 20) {
            $limit = 20;
        }
        $scope = $request->input('scope', 'inbox');
        $user = $request->user();
        $min_id = $request->input('min_id');
        $max_id = $request->input('max_id');
        $since_id = $request->input('since_id');

        if ($user->has_roles && ! UserRoleService::can('can-direct-message', $user->id)) {
            return [];
        }

        $pid = $user->profile_id;

        $isPgsql = config('database.default') == 'pgsql';

        if ($isPgsql) {
            $dms = DirectMessage::when($scope === 'inbox', function ($q) use ($pid) {
                return $q->whereIsHidden(false)
                    ->where(function ($query) use ($pid) {
                        $query->where('to_id', $pid)
                            ->orWhere('from_id', $pid);
                    });
            })
                ->when($scope === 'sent', function ($q) use ($pid) {
                    return $q->whereFromId($pid)
                        ->groupBy(['to_id', 'id']);
                })
                ->when($scope === 'requests', function ($q) use ($pid) {
                    return $q->whereToId($pid)
                        ->whereIsHidden(true);
                });
        } else {
            $dms = Conversation::when($scope === 'inbox', function ($q) use ($pid) {
                return $q->whereIsHidden(false)
                    ->where(function ($query) use ($pid) {
                        $query->where('to_id', $pid)
                            ->orWhere('from_id', $pid);
                    })
                    ->orderByDesc('status_id')
                    ->groupBy(['to_id', 'from_id']);
            })
                ->when($scope === 'sent', function ($q) use ($pid) {
                    return $q->whereFromId($pid)
                        ->groupBy('to_id');
                })
                ->when($scope === 'requests', function ($q) use ($pid) {
                    return $q->whereToId($pid)
                        ->whereIsHidden(true);
                });
        }

        if ($min_id) {
            $dms = $dms->where('id', '>', $min_id);
        }
        if ($max_id) {
            $dms = $dms->where('id', '<', $max_id);
        }
        if ($since_id) {
            $dms = $dms->where('id', '>', $since_id);
        }

        $dms = $dms->orderByDesc('status_id')->orderBy('id');

        $dmResults = $dms->limit($limit + 1)->get();

        $hasNextPage = $dmResults->count() > $limit;

        if ($hasNextPage) {
            $dmResults = $dmResults->take($limit);
        }

        $transformedDms = $dmResults->map(function ($dm) use ($pid) {
            $from = $pid == $dm->to_id ? $dm->from_id : $dm->to_id;

            return [
                'id' => $dm->id,
                'unread' => false,
                'accounts' => [
                    AccountService::getMastodon($from, true),
                ],
                'last_status' => StatusService::getDirectMessage($dm->status_id),
            ];
        })
            ->filter(function ($dm) {
                return $dm
                    && ! empty($dm['last_status'])
                    && isset($dm['accounts'])
                    && count($dm['accounts'])
                    && isset($dm['accounts'][0])
                    && isset($dm['accounts'][0]['id']);
            })
            ->unique(function ($item) {
                return $item['accounts'][0]['id'];
            })
            ->values();

        $links = [];

        if (! $transformedDms->isEmpty()) {
            $baseUrl = url()->current() . '?' . http_build_query(array_merge(
                $request->except(['min_id', 'max_id', 'since_id']),
                ['limit' => $limit]
            ));

            $firstId = $transformedDms->first()['id'];
            $lastId = $transformedDms->last()['id'];

            $firstLink = $baseUrl;
            $links[] = '<' . $firstLink . '>; rel="first"';

            if ($hasNextPage) {
                $nextLink = $baseUrl . '&max_id=' . $lastId;
                $links[] = '<' . $nextLink . '>; rel="next"';
            }

            if ($max_id || $since_id) {
                $prevLink = $baseUrl . '&min_id=' . $firstId;
                $links[] = '<' . $prevLink . '>; rel="prev"';
            }
        }

        if (! empty($links)) {
            return response()->json($transformedDms->toArray())
                ->header('Link', implode(', ', $links));
        }

        return $this->json($transformedDms);
    }

    /**
     * GET /api/v1/statuses/{id}
     *
     * @param  int  $id
     * @return StatusTransformer
     */
    public function statusById(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        AccountService::setLastActive($request->user()->id);
        $pid = $request->user()->profile_id;

        $res = $request->has(self::PF_API_ENTITY_KEY) ? StatusService::get($id, false) : StatusService::getMastodon($id, false);
        if (! $res || ! isset($res['visibility'])) {
            abort(404);
        }

        if ($res && isset($res['account'], $res['account']['acct'], $res['account']['url']) && strpos($res['account']['acct'], '@') != -1) {
            $domain = parse_url($res['account']['url'], PHP_URL_HOST);
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }

        $scope = $res['visibility'];
        if (! in_array($scope, ['public', 'unlisted'])) {
            if ($scope === 'private') {
                if (intval($res['account']['id']) !== intval($pid)) {
                    abort_unless(FollowerService::follows($pid, $res['account']['id']), 403);
                }
            } else {
                abort(400, 'Invalid request');
            }
        }

        if (! empty($res['reblog']) && isset($res['reblog']['id'])) {
            $res['reblog']['favourited'] = (bool) LikeService::liked($pid, $res['reblog']['id']);
            $res['reblog']['reblogged'] = (bool) ReblogService::get($pid, $res['reblog']['id']);
            $res['reblog']['bookmarked'] = BookmarkService::get($pid, $res['reblog']['id']);
        }

        $res['favourited'] = LikeService::liked($pid, $res['id']);
        $res['reblogged'] = ReblogService::get($pid, $res['id']);
        $res['bookmarked'] = BookmarkService::get($pid, $res['id']);

        return $this->json($res);
    }

    /**
     * GET /api/v1/statuses/{id}/context
     *
     * @param  int  $id
     * @return StatusTransformer
     */
    public function statusContext(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $user = $request->user();
        $pid = $user->profile_id;
        $status = StatusService::getMastodon($id, false);
        $pe = $request->has(self::PF_API_ENTITY_KEY);

        if (! $status || ! isset($status['account'])) {
            return response('', 404);
        }

        if ($status && isset($status['account'], $status['account']['acct']) && strpos($status['account']['acct'], '@') != -1) {
            $domain = parse_url($status['account']['url'], PHP_URL_HOST);
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }

        if (intval($status['account']['id']) !== intval($user->profile_id)) {
            if ($status['visibility'] == 'private') {
                if (! FollowerService::follows($user->profile_id, $status['account']['id'])) {
                    return response('', 404);
                }
            } else {
                if (! in_array($status['visibility'], ['public', 'unlisted'])) {
                    return response('', 404);
                }
            }
        }

        $ancestors = [];
        $descendants = [];

        if ($status['in_reply_to_id']) {
            $ancestors[] = $pe ?
                StatusService::get($status['in_reply_to_id'], false) :
                StatusService::getMastodon($status['in_reply_to_id'], false);
        }

        if ($status['replies_count']) {
            $filters = UserFilterService::filters($pid);

            $descendants = DB::table('statuses')
                ->where('in_reply_to_id', $id)
                ->limit(20)
                ->pluck('id')
                ->map(function ($sid) use ($pe) {
                    return $pe ?
                        StatusService::get($sid, false) :
                        StatusService::getMastodon($sid, false);
                })
                ->filter(function ($post) use ($filters) {
                    return $post && isset($post['account'], $post['account']['id']) && ! in_array($post['account']['id'], $filters);
                })
                ->map(function ($status) use ($pid) {
                    $status['favourited'] = LikeService::liked($pid, $status['id']);
                    $status['reblogged'] = ReblogService::get($pid, $status['id']);

                    return $status;
                })
                ->values();
        }

        $res = [
            'ancestors' => $ancestors,
            'descendants' => $descendants,
        ];

        return $this->json($res);
    }

    /**
     * GET /api/v1/statuses/{id}/card
     *
     * @param  int  $id
     * @return StatusTransformer
     */
    public function statusCard(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $res = [];

        return response()->json($res);
    }

    /**
     * GET /api/v1/statuses/{id}/reblogged_by
     *
     * @param  int  $id
     * @return AccountTransformer
     */
    public function statusRebloggedBy(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $this->validate($request, [
            'limit' => 'sometimes|integer|min:1|max:80',
        ]);

        $limit = $request->input('limit', 10);
        $user = $request->user();
        $pid = $user->profile_id;
        $status = Status::findOrFail($id);
        $account = AccountService::get($status->profile_id, true);
        abort_if(! $account, 404);
        abort_if(isset($account['moved'], $account['moved']['id']), 404, 'Account moved');
        if ($account && strpos($account['acct'], '@') != -1) {
            $domain = parse_url($account['url'], PHP_URL_HOST);
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }
        $author = intval($status->profile_id) === intval($pid) || $user->is_admin;
        $napi = $request->has(self::PF_API_ENTITY_KEY);

        abort_if(
            ! $status->type ||
                ! in_array($status->type, ['photo', 'photo:album', 'photo:video:album', 'reply', 'text', 'video', 'video:album']),
            404,
        );

        if (! $author) {
            if ($status->scope == 'private') {
                abort_if(! FollowerService::follows($pid, $status->profile_id), 403);
            } else {
                abort_if(! in_array($status->scope, ['public', 'unlisted']), 403);
            }

            if ($request->has('cursor')) {
                return $this->json([]);
            }
        }

        $res = Status::where('reblog_of_id', $status->id)
            ->orderByDesc('id')
            ->cursorPaginate($limit)
            ->withQueryString();

        if (! $res) {
            return $this->json([]);
        }

        $headers = [];
        if ($author && $res->hasPages()) {
            $links = '';
            if ($res->onFirstPage()) {
                if ($res->nextPageUrl()) {
                    $links = '<' . $res->nextPageUrl() . '>; rel="prev"';
                }
            } else {
                if ($res->previousPageUrl()) {
                    $links = '<' . $res->previousPageUrl() . '>; rel="next"';
                }

                if ($res->nextPageUrl()) {
                    if (! empty($links)) {
                        $links .= ', ';
                    }
                    $links .= '<' . $res->nextPageUrl() . '>; rel="prev"';
                }
            }

            $headers = ['Link' => $links];
        }

        $res = $res->map(function ($status) use ($pid, $napi) {
            $account = $napi ? AccountService::get($status->profile_id, true) : AccountService::getMastodon($status->profile_id, true);
            if (! $account) {
                return false;
            }
            if ($napi) {
                $account['follows'] = $status->profile_id == $pid ? null : FollowerService::follows($pid, $status->profile_id);
            }

            return $account;
        })
            ->filter(function ($account) {
                return $account && isset($account['id']);
            })
            ->values();

        return $this->json($res, 200, $headers);
    }

    /**
     * GET /api/v1/statuses/{id}/favourited_by
     *
     * @param  int  $id
     * @return AccountTransformer
     */
    public function statusFavouritedBy(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $this->validate($request, [
            'limit' => 'sometimes|integer|min:1',
        ]);

        $limit = $request->input('limit', 40);
        if ($limit > 80) {
            $limit = 80;
        }
        $user = $request->user();
        $pid = $user->profile_id;
        $status = Status::findOrFail($id);
        $account = AccountService::get($status->profile_id, true);
        abort_if(! $account, 404);
        abort_if(isset($account['moved'], $account['moved']['id']), 404, 'Account moved');
        if ($account && strpos($account['acct'], '@') != -1) {
            $domain = parse_url($account['url'], PHP_URL_HOST);
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }
        $author = intval($status->profile_id) === intval($pid) || $user->is_admin;
        $napi = $request->has(self::PF_API_ENTITY_KEY);

        abort_if(
            ! $status->type ||
                ! in_array($status->type, ['photo', 'photo:album', 'photo:video:album', 'reply', 'text', 'video', 'video:album']),
            404,
        );

        if (! $author) {
            if ($status->scope == 'private') {
                abort_if(! FollowerService::follows($pid, $status->profile_id), 403);
            } else {
                abort_if(! in_array($status->scope, ['public', 'unlisted']), 403);
            }

            if ($request->has('cursor')) {
                return $this->json([]);
            }
        }

        $res = Like::where('status_id', $status->id)
            ->orderByDesc('id')
            ->cursorPaginate($limit)
            ->withQueryString();

        if (! $res) {
            return $this->json([]);
        }

        $headers = [];
        if ($author && $res->hasPages()) {
            $links = '';

            if ($res->onFirstPage()) {
                if ($res->nextPageUrl()) {
                    $links = '<' . $res->nextPageUrl() . '>; rel="prev"';
                }
            } else {
                if ($res->previousPageUrl()) {
                    $links = '<' . $res->previousPageUrl() . '>; rel="next"';
                }

                if ($res->nextPageUrl()) {
                    if (! empty($links)) {
                        $links .= ', ';
                    }
                    $links .= '<' . $res->nextPageUrl() . '>; rel="prev"';
                }
            }

            $headers = ['Link' => $links];
        }

        $res = $res->map(function ($like) use ($pid, $napi) {
            $account = $napi ? AccountService::get($like->profile_id, true) : AccountService::getMastodon($like->profile_id, true);
            if (! $account) {
                return false;
            }

            if ($napi) {
                $account['follows'] = $like->profile_id == $pid ? null : FollowerService::follows($pid, $like->profile_id);
            }

            return $account;
        })
            ->filter(function ($account) {
                return $account && isset($account['id']);
            })
            ->values();

        return $this->json($res, 200, $headers);
    }

    /**
     * POST /api/v1/statuses
     *
     *
     * @return StatusTransformer
     */
    public function statusCreate(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);
        abort_if(!AccountService::canPost($request->user()), 400, 'limit_daily_posts');

        $this->validate($request, [
            'status' => 'nullable|string|max:' . (int) config_cache('pixelfed.max_caption_length'),
            'in_reply_to_id' => 'nullable',
            'media_ids' => 'sometimes|array|max:' . (int) config_cache('pixelfed.max_album_length'),
            'sensitive' => 'nullable',
            'visibility' => 'string|in:private,unlisted,public,direct',
            'spoiler_text' => 'sometimes|max:140',
            'place_id' => 'sometimes|integer|min:1|max:128769',
            'collection_ids' => 'sometimes|array|max:3',
            'comments_disabled' => 'sometimes|boolean',
        ]);

        if ($request->filled('visibility') && $request->input('visibility') === 'direct') {
            return $this->json([
                'error' => 'Direct visibility is not available.',
            ], 400);
        }

        if ($request->hasHeader('idempotency-key')) {
            $key = 'pf:api:v1:status:idempotency-key:' . $request->user()->id . ':' . hash('sha1', $request->header('idempotency-key'));
            $exists = Cache::has($key);
            abort_if($exists, 400, 'Duplicate idempotency key.');
            Cache::put($key, 1, 3600);
        }

        if (config('costar.enabled') == true) {
            $blockedKeywords = config('costar.keyword.block');
            if ($blockedKeywords !== null && $request->status) {
                $keywords = config('costar.keyword.block');
                foreach ($keywords as $kw) {
                    if (Str::contains($request->status, $kw) == true) {
                        abort(400, 'Invalid object. Contains banned keyword.');
                    }
                }
            }
        }

        if (! $request->filled('media_ids') && ! $request->filled('in_reply_to_id')) {
            abort(403, 'Empty statuses are not allowed');
        }

        $ids = $request->input('media_ids');
        $in_reply_to_id = $request->input('in_reply_to_id');

        $user = $request->user();

        if ($user->has_roles) {
            if ($in_reply_to_id != null) {
                abort_if(! UserRoleService::can('can-comment', $user->id), 403, 'Invalid permissions for this action');
            } else {
                abort_if(! UserRoleService::can('can-post', $user->id), 403, 'Invalid permissions for this action');
            }
        }

        $profile = $user->profile;

        $limitKey = 'compose:rate-limit:store:' . $user->id;
        $limitTtl = now()->addMinutes(15);
        $limitReached = Cache::remember($limitKey, $limitTtl, function () use ($user) {
            $minId = SnowflakeService::byDate(now()->subDays(1));
            $dailyLimit = Status::whereProfileId($user->profile_id)
                ->where('id', '>', $minId)
                ->count();

            return $dailyLimit >= 1000;
        });

        abort_if($limitReached == true, 429);

        $visibility = $profile->is_private ? 'private' : (
            $profile->unlisted == true &&
            $request->input('visibility', 'public') == 'public' ?
            'unlisted' :
            $request->input('visibility', 'public'));

        if ($user->last_active_at == null) {
            return [];
        }

        $content = strip_tags($request->input('status'));
        $rendered = Autolink::create()->autolink($content);
        $cw = $user->profile->cw == true ? true : $request->boolean('sensitive', false);
        $spoilerText = $cw && $request->filled('spoiler_text') ? $request->input('spoiler_text') : null;

        if ($in_reply_to_id) {
            $parent = Status::findOrFail($in_reply_to_id);
            if ($parent->comments_disabled) {
                return $this->json('Comments have been disabled on this post', 422);
            }
            $blocks = UserFilterService::blocks($parent->profile_id);
            abort_if(in_array($profile->id, $blocks), 422, 'Cannot reply to this post at this time.');

            $status = new Status;
            $status->caption = $content;
            $status->rendered = $rendered;;
            $status->scope = $visibility;
            $status->visibility = $visibility;
            $status->profile_id = $user->profile_id;
            $status->is_nsfw = $cw;
            $status->cw_summary = $spoilerText;
            $status->in_reply_to_id = $parent->id;
            $status->in_reply_to_profile_id = $parent->profile_id;
            $status->save();
            StatusService::del($parent->id);
            Cache::forget('status:replies:all:' . $parent->id);
        }

        if ($ids) {
            if (
                Media::whereUserId($user->id)
                ->whereNull('status_id')
                ->find($ids)
                ->count() == 0
            ) {
                abort(400, 'Invalid media_ids');
            }

            if (! $in_reply_to_id) {
                $status = new Status;
                $status->caption = $content;
                $status->rendered = $rendered;
                $status->profile_id = $user->profile_id;
                $status->is_nsfw = $cw;
                $status->cw_summary = $spoilerText;
                $status->scope = 'draft';
                $status->visibility = 'draft';
                if ($request->has('place_id')) {
                    $status->place_id = $request->input('place_id');
                }
                $status->save();
            }

            $mimes = [];

            foreach ($ids as $k => $v) {
                if ($k + 1 > (int) config_cache('pixelfed.max_album_length')) {
                    continue;
                }
                $m = Media::whereUserId($user->id)->whereNull('status_id')->findOrFail($v);
                if ($m->profile_id !== $user->profile_id || $m->status_id) {
                    abort(403, 'Invalid media id');
                }
                $m->order = $k + 1;
                $m->status_id = $status->id;
                $m->save();
                array_push($mimes, $m->mime);
            }

            if (empty($mimes)) {
                $status->delete();
                abort(400, 'Invalid media ids');
            }

            if ($request->has('comments_disabled') && $request->input('comments_disabled')) {
                $status->comments_disabled = true;
            }

            $status->scope = $visibility;
            $status->visibility = $visibility;
            $status->type = StatusController::mimeTypeCheck($mimes);
            $status->save();
        }

        if (! $status) {
            abort(500, 'An error occured.');
        }

        Cache::forget('pf:status:ap:v1:sid:' . $status->id);
        Cache::forget('status:transformer:media:attachments:' . $status->id);
        Cache::forget('user:account:id:' . $user->id);
        Cache::forget('_api:statuses:recent_9:' . $user->profile_id);
        Cache::forget('profile:status_count:' . $user->profile_id);
        Cache::forget($user->storageUsedKey());
        Cache::forget('profile:embed:' . $status->profile_id);
        Cache::forget($limitKey);

        NewStatusPipeline::dispatch($status);
        if ($status->in_reply_to_id) {
            CommentPipeline::dispatch($parent, $status);
        }

        if ($request->has('collection_ids') && $ids) {
            $collections = Collection::whereProfileId($user->profile_id)
                ->find($request->input('collection_ids'))
                ->each(function ($collection) use ($status) {
                    $count = $collection->items()->count();
                    $item = CollectionItem::firstOrCreate([
                        'collection_id' => $collection->id,
                        'object_type' => 'App\Status',
                        'object_id' => $status->id,
                    ], [
                        'order' => $count,
                    ]);

                    CollectionService::addItem(
                        $collection->id,
                        $status->id,
                        $count
                    );
                    $collection->updated_at = now();
                    $collection->save();
                    CollectionService::setCollection($collection->id, $collection);
                });
        }

        $res = StatusService::getMastodon($status->id, false);
        $res['favourited'] = false;
        $res['language'] = 'en';
        $res['bookmarked'] = false;
        $res['card'] = null;

        return $this->json($res);
    }

    /**
     * DELETE /api/v1/statuses
     *
     * @param  int  $id
     * @return null
     */
    public function statusDelete(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        AccountService::setLastActive($request->user()->id);
        $status = Status::whereProfileId($request->user()->profile->id)
            ->findOrFail($id);

        $resource = new Fractal\Resource\Item($status, new StatusTransformer);

        Cache::forget('profile:status_count:' . $status->profile_id);
        StatusDelete::dispatch($status);

        $res = $this->fractal->createData($resource)->toArray();
        $res['text'] = $res['content'];
        unset($res['content']);

        return $this->json($res);
    }

    /**
     * POST /api/v1/statuses/{id}/reblog
     *
     * @param  int  $id
     * @return StatusTransformer
     */
    public function statusShare(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        $user = $request->user();
        abort_if($user->has_roles && ! UserRoleService::can('can-share', $user->id), 403, 'Invalid permissions for this action');
        AccountService::setLastActive($user->id);
        $status = Status::whereScope('public')->findOrFail($id);
        $account = AccountService::get($status->profile_id);
        abort_if(isset($account['moved'], $account['moved']['id']), 422, 'Cannot share a post from an account that has migrated');
        if ($status && ($status->uri || $status->url || $status->object_url)) {
            $url = $status->uri ?? $status->url ?? $status->object_url;
            $domain = parse_url($url, PHP_URL_HOST);
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }
        if (intval($status->profile_id) !== intval($user->profile_id)) {
            if ($status->scope == 'private') {
                abort_if(! FollowerService::follows($user->profile_id, $status->profile_id), 403);
            } else {
                abort_if(! in_array($status->scope, ['public', 'unlisted']), 403);
            }

            $blocks = UserFilterService::blocks($status->profile_id);
            if ($blocks && in_array($user->profile_id, $blocks)) {
                abort(422);
            }
        }

        $defaultCaption = config_cache('database.default') === 'mysql' ? null : '';
        $share = Status::firstOrCreate([
            'caption' => $defaultCaption,
            'rendered' => $defaultCaption,
            'profile_id' => $user->profile_id,
            'reblog_of_id' => $status->id,
            'type' => 'share',
            'in_reply_to_profile_id' => $status->profile_id,
            'scope' => 'public',
            'visibility' => 'public',
        ]);

        SharePipeline::dispatch($share)->onQueue('low');

        StatusService::del($status->id);
        ReblogService::add($user->profile_id, $status->id);
        $res = StatusService::getMastodon($status->id);
        $res['reblogged'] = true;
        $res['favourited'] = LikeService::liked($user->profile_id, $status->id);
        $res['bookmarked'] = BookmarkService::get($user->profile_id, $status->id);

        return $this->json($res);
    }

    /**
     * POST /api/v1/statuses/{id}/unreblog
     *
     * @param  int  $id
     * @return StatusTransformer
     */
    public function statusUnshare(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        $user = $request->user();
        abort_if($user->has_roles && ! UserRoleService::can('can-share', $user->id), 403, 'Invalid permissions for this action');
        AccountService::setLastActive($user->id);
        $status = Status::whereScope('public')->findOrFail($id);
        $account = AccountService::get($status->profile_id);
        abort_if(isset($account['moved'], $account['moved']['id']), 422, 'Cannot unshare a post from an account that has migrated');

        if (intval($status->profile_id) !== intval($user->profile_id)) {
            if ($status->scope == 'private') {
                abort_if(! FollowerService::follows($user->profile_id, $status->profile_id), 403);
            } else {
                abort_if(! in_array($status->scope, ['public', 'unlisted']), 403);
            }
        }

        $reblog = Status::whereProfileId($user->profile_id)
            ->whereReblogOfId($status->id)
            ->first();

        if (! $reblog) {
            $res = StatusService::getMastodon($status->id);
            $res['reblogged'] = false;

            return $this->json($res);
        }

        UndoSharePipeline::dispatch($reblog)->onQueue('low');
        ReblogService::del($user->profile_id, $status->id);

        $res = StatusService::getMastodon($status->id);
        $res['reblogged'] = false;
        $res['favourited'] = LikeService::liked($user->profile_id, $status->id);
        $res['bookmarked'] = BookmarkService::get($user->profile_id, $status->id);

        return $this->json($res);
    }

    /**
     * GET /api/v1/timelines/tag/{hashtag}
     *
     * @param  string  $hashtag
     * @return StatusTransformer
     */
    public function timelineHashtag(Request $request, $hashtag)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $this->validate($request, [
            'page' => 'nullable|integer|max:40',
            'min_id' => 'nullable|integer|min:0|max:' . PHP_INT_MAX,
            'max_id' => 'nullable|integer|min:0|max:' . PHP_INT_MAX,
            'limit' => 'sometimes|integer|min:1',
            'only_media' => 'sometimes',
            '_pe' => 'sometimes',
        ]);

        $user = $request->user();
        abort_if(
            $user->has_roles && ! UserRoleService::can('can-view-hashtag-feed', $user->id),
            403,
            'Invalid permissions for this action'
        );

        if (config('database.default') === 'pgsql') {
            $tag = Hashtag::where('name', 'ilike', $hashtag)
                ->orWhere('slug', 'ilike', $hashtag)
                ->first();
        } else {
            $tag = Hashtag::whereName($hashtag)
                ->orWhere('slug', $hashtag)
                ->first();
        }

        if (! $tag) {
            return response()->json([]);
        }

        if ($tag->is_banned == true) {
            return $this->json([]);
        }

        $min = $request->input('min_id');
        $max = $request->input('max_id');
        $limit = $request->input('limit', 20);
        if ($limit > 40) {
            $limit = 40;
        }
        $onlyMedia = $request->boolean('only_media', true);
        $pe = $request->has(self::PF_API_ENTITY_KEY);
        $pid = $request->user()->profile_id;

        $cachedFilters = CustomFilter::getCachedFiltersForAccount($pid);

        $tagFilters = array_filter($cachedFilters, function ($item) {
            [$filter, $rules] = $item;

            return in_array('tags', $filter->context);
        });

        if ($min || $max) {
            $minMax = SnowflakeService::byDate(now()->subMonths(9));
            if ($min && intval($min) < $minMax) {
                return [];
            }
            if ($max && intval($max) < $minMax) {
                return [];
            }
        }

        $filters = UserFilterService::filters($pid);
        $domainBlocks = UserFilterService::domainBlocks($pid);

        if (! $min && ! $max) {
            $id = 1;
            $dir = '>';
        } else {
            $dir = $min ? '>' : '<';
            $id = $min ?? $max;
        }

        $res = StatusHashtag::whereHashtagId($tag->id)
            ->where('status_id', $dir, $id)
            ->orderBy('status_id', 'desc')
            ->limit(100)
            ->pluck('status_id')
            ->map(function ($i) use ($pe) {
                return $pe ? StatusService::get($i, false) : StatusService::getMastodon($i, false);
            })
            ->filter(function ($i) use ($onlyMedia, $pid) {
                if (! $i || ! isset($i['account'], $i['account']['id'])) {
                    return false;
                }
                if ($i['visibility'] === 'unlisted') {
                    if ((int) $i['account']['id'] !== $pid) {
                        return false;
                    }
                }
                // if ($i['visibility'] === 'private') {
                //     if ((int) $i['account']['id'] !== $pid) {
                //         return FollowerService::follows($pid, $i['account']['id'], true);
                //     }
                // }
                if ($onlyMedia == true) {
                    if (! isset($i['media_attachments']) || ! count($i['media_attachments'])) {
                        return false;
                    }
                }

                return $i && isset($i['account'], $i['url']);
            })
            ->filter(function ($i) use ($filters, $domainBlocks) {
                $domain = strtolower(parse_url($i['url'], PHP_URL_HOST));

                return ! in_array($i['account']['id'], $filters) && ! in_array($domain, $domainBlocks);
            })
            ->map(function ($status) use ($tagFilters) {
                $filterResults = CustomFilter::applyCachedFilters($tagFilters, $status);

                if (! empty($filterResults)) {
                    $status['filtered'] = $filterResults;
                    $shouldHide = collect($filterResults)->contains(function ($result) {
                        return $result['filter']['filter_action'] === 'hide';
                    });

                    if ($shouldHide) {
                        return null;
                    }
                }

                return $status;
            })
            ->filter()
            ->take($limit)
            ->values()
            ->toArray();

        return $this->json($res);
    }

    /**
     * GET /api/v1/bookmarks
     *
     *
     *
     * @return StatusTransformer
     */
    public function bookmarks(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $this->validate($request, [
            'limit' => 'sometimes|integer|min:1',
            'max_id' => 'nullable|integer|min:0',
            'since_id' => 'nullable|integer|min:0',
            'min_id' => 'nullable|integer|min:0',
        ]);

        $pe = $request->has('_pe');
        $pid = $request->user()->profile_id;
        $limit = $request->input('limit') ?? 20;
        if ($limit > 40) {
            $limit = 40;
        }
        $max_id = $request->input('max_id');
        $since_id = $request->input('since_id');
        $min_id = $request->input('min_id');

        $dir = $min_id ? '>' : '<';
        $id = $min_id ?? $max_id;

        $bookmarkQuery = Bookmark::whereProfileId($pid)
            ->orderByDesc('id')
            ->cursorPaginate($limit);

        $bookmarks = $bookmarkQuery->map(function ($bookmark) use ($pid, $pe) {
            $status = $pe ? StatusService::get($bookmark->status_id, false) : StatusService::getMastodon($bookmark->status_id, false);

            if ($status) {
                $status['bookmarked'] = true;
                $status['favourited'] = LikeService::liked($pid, $status['id']);
                $status['reblogged'] = ReblogService::get($pid, $status['id']);
            }

            return $status;
        })
            ->filter()
            ->values()
            ->toArray();

        $links = null;
        $headers = [];

        if ($bookmarkQuery->nextCursor()) {
            $links .= '<' . $bookmarkQuery->nextPageUrl() . '&limit=' . $limit . '>; rel="next"';
        }

        if ($bookmarkQuery->previousCursor()) {
            if ($links != null) {
                $links .= ', ';
            }
            $links .= '<' . $bookmarkQuery->previousPageUrl() . '&limit=' . $limit . '>; rel="prev"';
        }

        if ($links) {
            $headers = ['Link' => $links];
        }

        return $this->json($bookmarks, 200, $headers);
    }

    /**
     * POST /api/v1/statuses/{id}/bookmark
     *
     *
     *
     * @return StatusTransformer
     */
    public function bookmarkStatus(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        $status = Status::findOrFail($id);
        $user = $request->user();
        $pid = $request->user()->profile_id;
        $account = AccountService::get($status->profile_id);
        abort_if(isset($account['moved'], $account['moved']['id']), 422, 'Cannot bookmark a post from an account that has migrated');
        abort_if($user->has_roles && ! UserRoleService::can('can-bookmark', $user->id), 403, 'Invalid permissions for this action');
        abort_if($status->in_reply_to_id || $status->reblog_of_id, 404);
        abort_if(! in_array($status->scope, ['public', 'unlisted', 'private']), 404);
        abort_if(! in_array($status->type, ['photo', 'photo:album', 'video', 'video:album', 'photo:video:album']), 404);

        if ($status->scope == 'private') {
            abort_if(
                $pid !== $status->profile_id && ! FollowerService::follows($pid, $status->profile_id),
                404,
                'Error: You cannot bookmark private posts from accounts you do not follow.'
            );
        }

        Bookmark::firstOrCreate([
            'status_id' => $status->id,
            'profile_id' => $pid,
        ]);

        BookmarkService::add($pid, $status->id);

        $res = StatusService::getMastodon($status->id, false);
        $res['bookmarked'] = true;

        return $this->json($res);
    }

    /**
     * POST /api/v1/statuses/{id}/unbookmark
     *
     *
     *
     * @return StatusTransformer
     */
    public function unbookmarkStatus(Request $request, $id)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        $status = Status::findOrFail($id);
        $pid = $request->user()->profile_id;
        $user = $request->user();

        abort_if($user->has_roles && ! UserRoleService::can('can-bookmark', $user->id), 403, 'Invalid permissions for this action');
        abort_if($status->in_reply_to_id || $status->reblog_of_id, 404);
        abort_if(! in_array($status->scope, ['public', 'unlisted', 'private']), 404);
        abort_if(! in_array($status->type, ['photo', 'photo:album', 'video', 'video:album', 'photo:video:album']), 404);

        $bookmark = Bookmark::whereStatusId($status->id)
            ->whereProfileId($pid)
            ->first();

        if ($bookmark) {
            BookmarkService::del($pid, $status->id);
            $bookmark->delete();
        }
        $res = StatusService::getMastodon($status->id, false);
        $res['bookmarked'] = false;

        return $this->json($res);
    }

    /**
     * GET /api/v1/discover/posts
     *
     *
     * @return array
     */
    public function discoverPosts(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $this->validate($request, [
            'limit' => 'integer|min:1|max:40',
        ]);

        $limit = $request->input('limit', 40);
        $pid = $request->user()->profile_id;
        $filters = UserFilterService::filters($pid);
        $forYou = DiscoverService::getForYou();
        $posts = $forYou->take(50)->map(function ($post) {
            return StatusService::getMastodon($post);
        })
            ->filter(function ($post) use ($filters) {
                return $post &&
                    isset($post['account']) &&
                    isset($post['account']['id']) &&
                    ! in_array($post['account']['id'], $filters);
            })
            ->take(12)
            ->values();

        return $this->json(compact('posts'));
    }

    /**
     * GET /api/v2/statuses/{id}/replies
     *
     *
     * @return array
     */
    public function statusReplies(Request $request, $id)
    {
        abort_if(! $request->user(), 403);

        $this->validate($request, [
            'limit' => 'sometimes|integer|min:1',
            'sort' => 'in:all,newest,popular',
        ]);

        $limit = $request->input('limit', 3);
        if ($limit > 10) {
            $limit = 10;
        }
        $pid = $request->user()->profile_id;
        $status = StatusService::getMastodon($id, false);
        abort_if(! $status, 404);
        abort_if(isset($status['account'], $account['account']['moved']['id']), 404, 'Account moved');

        if ($status['visibility'] == 'private') {
            if ($pid != $status['account']['id']) {
                abort_unless(FollowerService::follows($pid, $status['account']['id']), 404);
            }
        }

        $sortBy = $request->input('sort', 'all');

        if ($sortBy == 'all' && isset($status['replies_count']) && $status['replies_count'] && $request->has('refresh_cache')) {
            if (! Cache::has('status:replies:all-rc:' . $id)) {
                Cache::forget('status:replies:all:' . $id);
                Cache::put('status:replies:all-rc:' . $id, true, 300);
            }
        }

        if ($sortBy == 'all' && ! $request->has('cursor')) {
            $ids = Cache::remember('status:replies:all:' . $id, 3600, function () use ($id) {
                return DB::table('statuses')
                    ->where('in_reply_to_id', $id)
                    ->orderBy('id')
                    ->cursorPaginate(3);
            });
        } else {
            $ids = DB::table('statuses')
                ->where('in_reply_to_id', $id)
                ->when($sortBy, function ($q, $sortBy) {
                    if ($sortBy === 'all') {
                        return $q->orderBy('id');
                    }

                    if ($sortBy === 'newest') {
                        return $q->orderByDesc('created_at');
                    }

                    if ($sortBy === 'popular') {
                        return $q->orderByDesc('likes_count');
                    }
                })
                ->cursorPaginate($limit);
        }

        $filters = UserFilterService::filters($pid);
        $data = $ids->filter(function ($post) use ($filters) {
            return ! in_array($post->profile_id, $filters);
        })
            ->map(function ($post) use ($pid) {
                $status = StatusService::get($post->id, false);

                if (! $status || ! isset($status['id'])) {
                    return false;
                }

                $status['favourited'] = LikeService::liked($pid, $post->id);

                return $status;
            })
            ->map(function ($post) {
                if (isset($post['account']) && isset($post['account']['id'])) {
                    $account = AccountService::get($post['account']['id'], true);
                    $post['account'] = $account;
                }

                return $post;
            })
            ->filter(function ($post) {
                return $post && isset($post['id']) && isset($post['account']) && isset($post['account']['id']);
            })
            ->values();

        $res = [
            'data' => $data,
            'next' => $ids->nextPageUrl(),
        ];

        return $this->json($res);
    }

    /**
     * GET /api/v2/statuses/{id}/state
     *
     *
     * @return array
     */
    public function statusState(Request $request, $id)
    {
        abort_if(! $request->user(), 403);

        $status = StatusService::get($id, false, true);
        abort_if(! $status, 404);
        abort_if(! in_array($status['visibility'], ['public', 'unlisted', 'private']), 404);

        return $this->json(StatusService::getState($status['id'], $request->user()->profile_id));
    }

    /**
     * GET /api/v1.1/discover/accounts/popular
     *
     *
     * @return array
     */
    public function discoverAccountsPopular(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $pid = $request->user()->profile_id;

        $ids = Cache::remember('api:v1.1:discover:accounts:popular', 14400, function () {
            return DB::table('profiles')
                ->where('is_private', false)
                ->whereNull('status')
                ->orderByDesc('profiles.followers_count')
                ->limit(30)
                ->get();
        });
        $filters = UserFilterService::filters($pid);
        $asf = AdminShadowFilterService::getHideFromPublicFeedsList();
        $ids = $ids->map(function ($profile) {
            return AccountService::get($profile->id, true);
        })
            ->filter(function ($profile) {
                return $profile && isset($profile['id'], $profile['locked']) && ! $profile['locked'];
            })
            ->filter(function ($profile) use ($pid) {
                return $profile['id'] != $pid;
            })
            ->filter(function ($profile) use ($pid) {
                return ! FollowerService::follows($pid, $profile['id'], true);
            })
            ->filter(function ($profile) use ($asf) {
                return ! in_array($profile['id'], $asf);
            })
            ->filter(function ($profile) use ($filters) {
                return ! in_array($profile['id'], $filters);
            })
            ->take(16)
            ->values();

        return $this->json($ids);
    }

    /**
     * GET /api/v1/preferences
     *
     *
     * @return array
     */
    public function getPreferences(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $pid = $request->user()->profile_id;
        $account = AccountService::get($pid);

        return $this->json([
            'posting:default:visibility' => $account['locked'] ? 'private' : 'public',
            'posting:default:sensitive' => false,
            'posting:default:language' => null,
            'reading:expand:media' => 'default',
            'reading:expand:spoilers' => false,
        ]);
    }

    /**
     * GET /api/v1/trends
     *
     *
     * @return array
     */
    public function getTrends(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        return $this->json([]);
    }

    /**
     * GET /api/v1/announcements
     *
     *
     * @return array
     */
    public function getAnnouncements(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        return $this->json([]);
    }

    /**
     * GET /api/v1/markers
     *
     *
     * @return array
     */
    public function getMarkers(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);

        $type = $request->input('timeline');
        if (is_array($type)) {
            $type = $type[0];
        }
        if (! $type || ! in_array($type, ['home', 'notifications'])) {
            return $this->json([]);
        }
        $pid = $request->user()->profile_id;

        return $this->json(MarkerService::get($pid, $type));
    }

    /**
     * POST /api/v1/markers
     *
     *
     * @return array
     */
    public function setMarkers(Request $request)
    {
        abort_if(! $request->user() || ! $request->user()->token(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);

        $pid = $request->user()->profile_id;
        $home = $request->input('home[last_read_id]');
        $notifications = $request->input('notifications[last_read_id]');

        if ($home) {
            return $this->json(MarkerService::set($pid, 'home', $home));
        }

        if ($notifications) {
            return $this->json(MarkerService::set($pid, 'notifications', $notifications));
        }

        return $this->json([]);
    }

    /**
     * GET /api/v1/instance/peers
     *
     *
     * @return array
     */
    public function instancePeers(Request $request)
    {
        if ((bool) config('instance.show_peers') == false) {
            return $this->json([]);
        }

        return $this->json(
            Cache::remember(InstanceService::CACHE_KEY_API_PEERS_LIST, now()->addHours(24), function () {
                return Instance::whereNotNull('nodeinfo_last_fetched')
                    ->whereBanned(false)
                    ->where('nodeinfo_last_fetched', '>', now()->subDays(8))
                    ->pluck('domain');
            })
        );
    }

    public function accountRemoveFollowById(Request $request, $id)
    {
        abort_if(! $request->user(), 403);

        $pid = $request->user()->profile_id;

        if ($pid === $id) {
            return $this->json(['error' => 'Request invalid! target_id is same user id.'], 500);
        }

        $exists = Follower::whereProfileId($id)
            ->whereFollowingId($pid)
            ->first();

        abort_unless($exists, 404);

        $exists->delete();

        RelationshipService::refresh($pid, $id);
        RelationshipService::refresh($pid, $id);

        UnfollowPipeline::dispatch($id, $pid)->onQueue('high');

        Cache::forget('profile:following:' . $id);
        Cache::forget('profile:followers:' . $id);
        Cache::forget('profile:following:' . $pid);
        Cache::forget('profile:followers:' . $pid);
        Cache::forget('api:local:exp:rec:' . $pid);
        Cache::forget('user:account:id:' . $id);
        Cache::forget('user:account:id:' . $pid);
        Cache::forget('profile:follower_count:' . $id);
        Cache::forget('profile:follower_count:' . $pid);
        Cache::forget('profile:following_count:' . $id);
        Cache::forget('profile:following_count:' . $pid);
        AccountService::del($pid);
        AccountService::del($id);

        $res = RelationshipService::get($id, $pid);

        return $this->json($res);
    }

    /**
     *  GET /api/v1/statuses/{id}/pin
     */
    public function statusPin(Request $request, $id)
    {
        abort_if(! $request->user(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);
        $user = $request->user();
        $status = Status::whereScope('public')->find($id);

        if (! $status) {
            return $this->json(['error' => 'Record not found'], 404);
        }

        if ($status->profile_id != $user->profile_id) {
            return $this->json(['error' => "Validation failed: Someone else's post cannot be pinned"], 422);
        }

        $res = StatusService::markPin($status->id);

        if (! $res['success']) {
            return $this->json([
                'error' => $res['error'],
            ], 422);
        }

        $statusRes = StatusService::get($status->id, true, true);
        $status['pinned'] = true;

        return $this->json($statusRes);
    }

    /**
     *  GET /api/v1/statuses/{id}/unpin
     */
    public function statusUnpin(Request $request, $id)
    {
        abort_if(! $request->user(), 403);
        abort_unless($request->user()->tokenCan('write'), 403);
        $status = Status::whereScope('public')->findOrFail($id);
        $user = $request->user();

        if ($status->profile_id != $user->profile_id) {
            return $this->json(['error' => 'Record not found'], 404);
        }

        $res = StatusService::unmarkPin($status->id);
        if (! $res) {
            return $this->json($res, 422);
        }

        $status = StatusService::get($status->id, true, true);
        $status['pinned'] = false;

        return $this->json($status);
    }
    /**
     *  GET /api/v1/statuses/{id}/translate
     */
    public function translateStatus(Request $request, $id)
    {

        abort_if(! $request->user(), 403);
        abort_unless($request->user()->tokenCan('read'), 403);
        abort_if(config("pixelfed.translation.enabled") == false, 403);
        abort_if(!TranslateService::canTranslate( $request->user()), 403);

        $user = $request->user();
        $language = $user->language ?? app()->getLocale();
        $settings = $user->settings;

        $translate =  TranslateService::status($id, $language);
        $data = [
            'text' => $translate['text'],
        ];

        return $this->json($data);
    }

    /**
     *  GET /api/v1/accounts/{id}/translate
     */
    public function translateBio(Request $request, $pid){

        abort_if(! $request->user(), 403);
        abort_unless($request->user()->tokenCan('read'),403);
        abort_if(config("pixelfed.translation.enabled") == false, 403);
        abort_if(!TranslateService::canTranslate( $request->user()), 403);

        $user = $request->user();
        $language = $user->language ?? app()->getLocale();
        $settings = $user->settings;

        $translate =  TranslateService::bio($pid, $language);
        $data = [
            'text' => $translate['text'],
        ];
        return $this->json($data);
    }
}
