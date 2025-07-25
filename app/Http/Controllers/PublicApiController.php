<?php

namespace App\Http\Controllers;

use App\Follower;
use App\Profile;
use App\Services\AccountService;
use App\Services\BookmarkService;
use App\Services\FollowerService;
use App\Services\InstanceService;
use App\Services\LikeService;
use App\Services\NetworkTimelineService;
use App\Services\PublicTimelineService;
use App\Services\ReblogService;
use App\Services\RelationshipService;
use App\Services\SnowflakeService;
use App\Services\StatusService;
use App\Services\UserFilterService;
use App\Status;
use App\Transformer\Api\StatusStatelessTransformer;
use Auth;
use Cache;
use Illuminate\Http\Request;
use League\Fractal;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Serializer\ArraySerializer;

class PublicApiController extends Controller
{
    protected $fractal;

    public function __construct()
    {
        $this->fractal = new Fractal\Manager;
        $this->fractal->setSerializer(new ArraySerializer);
    }

    public function json($res, $code = 200, $headers = [])
    {
        return response()->json($res, $code, $headers, JSON_UNESCAPED_SLASHES);
    }

    protected function getUserData($user)
    {
        if (! $user) {
            return [];
        } else {
            return AccountService::get($user->profile_id);
        }
    }

    public function getStatus(Request $request, $id)
    {
        abort_if(! $request->user(), 403);
        $status = StatusService::get($id, false);
        abort_if(! $status, 404);
        if (in_array($status['visibility'], ['public', 'unlisted'])) {
            return $status;
        }
        $pid = $request->user()->profile_id;
        if ($status['account']['id'] == $pid) {
            return $status;
        }
        if ($status['visibility'] == 'private') {
            if (FollowerService::follows($pid, $status['account']['id'])) {
                return $status;
            }
        }
        abort(404);
    }

    public function status(Request $request, $username, int $postid)
    {
        $profile = Profile::whereUsername($username)->whereNull('status')->firstOrFail();
        $status = Status::whereProfileId($profile->id)->findOrFail($postid);
        $this->scopeCheck($profile, $status);
        if (! $request->user()) {
            $cached = StatusService::get($status->id, false);
            abort_if(! in_array($cached['visibility'], ['public', 'unlisted']), 403);
            $res = ['status' => $cached];
        } else {
            $item = new Fractal\Resource\Item($status, new StatusStatelessTransformer);
            $res = [
                'status' => $this->fractal->createData($item)->toArray(),
            ];
        }

        return response()->json($res);
    }

    public function statusState(Request $request, $username, int $postid)
    {
        $profile = Profile::whereUsername($username)->whereNull('status')->firstOrFail();
        $status = Status::whereProfileId($profile->id)->findOrFail($postid);
        $this->scopeCheck($profile, $status);
        if (! Auth::check()) {
            $res = [
                'user' => [],
                'likes' => [],
                'shares' => [],
                'reactions' => [
                    'liked' => false,
                    'shared' => false,
                    'bookmarked' => false,
                ],
            ];

            return response()->json($res);
        }
        $res = [
            'user' => $this->getUserData($request->user()),
            'likes' => [],
            'shares' => [],
            'reactions' => [
                'liked' => (bool) $status->liked(),
                'shared' => (bool) $status->shared(),
                'bookmarked' => (bool) $status->bookmarked(),
            ],
        ];

        return response()->json($res);
    }

    public function statusComments(Request $request, $username, int $postId)
    {
        $this->validate($request, [
            'min_id' => 'nullable|integer|min:1',
            'max_id' => 'nullable|integer|min:1|max:'.PHP_INT_MAX,
            'limit' => 'nullable|integer|min:5|max:50',
        ]);

        $limit = $request->limit ?? 10;
        $profile = Profile::whereNull('status')->findOrFail($username);
        $status = Status::whereProfileId($profile->id)->whereCommentsDisabled(false)->findOrFail($postId);
        $this->scopeCheck($profile, $status);

        if (Auth::check()) {
            $p = Auth::user()->profile;
            $scope = $p->id == $status->profile_id || FollowerService::follows($p->id, $profile->id) ? ['public', 'private', 'unlisted'] : ['public', 'unlisted'];
        } else {
            $scope = ['public', 'unlisted'];
        }

        if ($request->filled('min_id') || $request->filled('max_id')) {
            if ($request->filled('min_id')) {
                $replies = $status->comments()
                    ->whereNull('reblog_of_id')
                    ->whereIn('scope', $scope)
                    ->select('id', 'caption', 'local', 'visibility', 'scope', 'is_nsfw', 'profile_id', 'in_reply_to_id', 'type', 'reply_count', 'created_at')
                    ->where('id', '>=', $request->min_id)
                    ->orderBy('id', 'desc')
                    ->paginate($limit);
            }
            if ($request->filled('max_id')) {
                $replies = $status->comments()
                    ->whereNull('reblog_of_id')
                    ->whereIn('scope', $scope)
                    ->select('id', 'caption', 'local', 'visibility', 'scope', 'is_nsfw', 'profile_id', 'in_reply_to_id', 'type', 'reply_count', 'created_at')
                    ->where('id', '<=', $request->max_id)
                    ->orderBy('id', 'desc')
                    ->paginate($limit);
            }
        } else {
            $replies = Status::whereInReplyToId($status->id)
                ->whereNull('reblog_of_id')
                ->whereIn('scope', $scope)
                ->select('id', 'caption', 'local', 'visibility', 'scope', 'is_nsfw', 'profile_id', 'in_reply_to_id', 'type', 'reply_count', 'created_at')
                ->orderBy('id', 'desc')
                ->paginate($limit);
        }

        $resource = new Fractal\Resource\Collection($replies, new StatusStatelessTransformer, 'data');
        $resource->setPaginator(new IlluminatePaginatorAdapter($replies));
        $res = $this->fractal->createData($resource)->toArray();

        return response()->json($res, 200, [], JSON_PRETTY_PRINT);
    }

    protected function scopeCheck(Profile $profile, Status $status)
    {
        if ($profile->is_private == true && Auth::check() == false) {
            abort(404);
        }

        switch ($status->scope) {
            case 'public':
            case 'unlisted':
                break;
            case 'private':
                $user = Auth::check() ? Auth::user() : false;
                if (! $user) {
                    abort(403);
                } else {
                    $follows = FollowerService::follows($profile->id, $user->profile_id);
                    if ($follows == false && $profile->id !== $user->profile_id && $user->is_admin == false) {
                        abort(404);
                    }
                }
                break;

            case 'direct':
                abort(404);
                break;

            case 'draft':
                abort(404);
                break;

            default:
                abort(404);
                break;
        }
    }

    public function publicTimelineApi(Request $request)
    {
        $this->validate($request, [
            'page' => 'nullable|integer|max:40',
            'min_id' => 'nullable|integer|min:0|max:'.PHP_INT_MAX,
            'max_id' => 'nullable|integer|min:0|max:'.PHP_INT_MAX,
            'limit' => 'nullable|integer|max:30',
        ]);

        if (! $request->user()) {
            return response('', 403);
        }

        $page = $request->input('page');
        $min = $request->input('min_id');
        $max = $request->input('max_id');
        $limit = $request->input('limit') ?? 3;
        $user = $request->user();
        $filtered = $user ? UserFilterService::filters($user->profile_id) : [];

        $hideNsfw = config('instance.hide_nsfw_on_public_feeds');
        if (config('exp.cached_public_timeline') == false) {
            if ($min || $max) {
                $dir = $min ? '>' : '<';
                $id = $min ?? $max;
                $timeline = Status::select(
                    'id',
                    'profile_id',
                    'type',
                    'scope',
                    'local'
                )
                    ->where('id', $dir, $id)
                    ->whereNull(['in_reply_to_id', 'reblog_of_id'])
                    ->whereIn('type', ['photo', 'photo:album', 'video', 'video:album', 'photo:video:album'])
                    ->whereLocal(true)
                    ->when($hideNsfw, function ($q, $hideNsfw) {
                        return $q->where('is_nsfw', false);
                    })
                    ->whereScope('public')
                    ->orderBy('id', 'desc')
                    ->limit($limit)
                    ->get()
                    ->map(function ($s) use ($user) {
                        $status = StatusService::getFull($s->id, $user->profile_id);
                        if (! $status) {
                            return false;
                        }
                        $status['favourited'] = (bool) LikeService::liked($user->profile_id, $s->id);
                        $status['bookmarked'] = (bool) BookmarkService::get($user->profile_id, $s->id);
                        $status['reblogged'] = (bool) ReblogService::get($user->profile_id, $s->id);

                        return $status;
                    })
                    ->filter(function ($s) use ($filtered) {
                        return $s && isset($s['account']) && in_array($s['account']['id'], $filtered) == false;
                    })
                    ->values();
                $res = $timeline->toArray();
            } else {
                $timeline = Status::select(
                    'id',
                    'uri',
                    'caption',
                    'profile_id',
                    'type',
                    'in_reply_to_id',
                    'reblog_of_id',
                    'is_nsfw',
                    'scope',
                    'local',
                    'reply_count',
                    'comments_disabled',
                    'created_at',
                    'place_id',
                    'likes_count',
                    'reblogs_count',
                    'updated_at'
                )
                    ->whereNull(['in_reply_to_id', 'reblog_of_id'])
                    ->whereIn('type', ['photo', 'photo:album', 'video', 'video:album', 'photo:video:album'])
                    ->whereLocal(true)
                    ->when($hideNsfw, function ($q, $hideNsfw) {
                        return $q->where('is_nsfw', false);
                    })
                    ->whereScope('public')
                    ->orderBy('id', 'desc')
                    ->limit($limit)
                    ->get()
                    ->map(function ($s) use ($user) {
                        $status = StatusService::getFull($s->id, $user->profile_id);
                        if (! $status) {
                            return false;
                        }
                        $status['favourited'] = (bool) LikeService::liked($user->profile_id, $s->id);
                        $status['bookmarked'] = (bool) BookmarkService::get($user->profile_id, $s->id);
                        $status['reblogged'] = (bool) ReblogService::get($user->profile_id, $s->id);

                        return $status;
                    })
                    ->filter(function ($s) use ($filtered) {
                        return $s && isset($s['account']) && in_array($s['account']['id'], $filtered) == false;
                    })
                    ->values();

                $res = $timeline->toArray();
            }
        } else {
            Cache::remember('api:v1:timelines:public:cache_check', 10368000, function () {
                if (PublicTimelineService::count() == 0) {
                    PublicTimelineService::warmCache(true, 400);
                }
            });

            if ($max) {
                $feed = PublicTimelineService::getRankedMaxId($max, $limit);
            } elseif ($min) {
                $feed = PublicTimelineService::getRankedMinId($min, $limit);
            } else {
                $feed = PublicTimelineService::get(0, $limit);
            }

            $res = collect($feed)
                ->take($limit)
                ->map(function ($k) use ($user) {
                    $status = StatusService::get($k);
                    if ($status && isset($status['account']) && $user) {
                        $status['favourited'] = (bool) LikeService::liked($user->profile_id, $k);
                        $status['bookmarked'] = (bool) BookmarkService::get($user->profile_id, $k);
                        $status['reblogged'] = (bool) ReblogService::get($user->profile_id, $k);
                        $status['relationship'] = RelationshipService::get($user->profile_id, $status['account']['id']);
                    }

                    return $status;
                })
                ->filter(function ($s) use ($filtered) {
                    return $s && isset($s['account']) && in_array($s['account']['id'], $filtered) == false;
                })
                ->values()
                ->toArray();
        }

        return response()->json($res);
    }

    public function homeTimelineApi(Request $request)
    {
        if (! $request->user()) {
            return response('', 403);
        }

        $this->validate($request, [
            'page' => 'nullable|integer|max:40',
            'min_id' => 'nullable|integer|min:0|max:'.PHP_INT_MAX,
            'max_id' => 'nullable|integer|min:0|max:'.PHP_INT_MAX,
            'limit' => 'nullable|integer|max:40',
            'recent_feed' => 'nullable',
            'recent_min' => 'nullable|integer',
        ]);

        $recentFeed = $request->input('recent_feed') == 'true';
        $recentFeedMin = $request->input('recent_min');
        $page = $request->input('page');
        $min = $request->input('min_id');
        $max = $request->input('max_id');
        $limit = $request->input('limit') ?? 3;
        $user = $request->user();

        $key = 'user:last_active_at:id:'.$user->id;
        if (Cache::get($key) == null) {
            $user->last_active_at = now();
            $user->save();
            Cache::put($key, true, 43200);
        }

        $pid = $user->profile_id;

        $following = Cache::remember('profile:following:'.$pid, 1209600, function () use ($pid) {
            $following = Follower::whereProfileId($pid)->pluck('following_id');

            return $following->push($pid)->toArray();
        });

        $filtered = $user ? UserFilterService::filters($user->profile_id) : [];
        $types = ['photo', 'photo:album', 'video', 'video:album', 'photo:video:album'];
        // $types = ['photo', 'photo:album', 'video', 'video:album', 'photo:video:album', 'text'];

        $textOnlyReplies = false;

        if ($min || $max) {
            $dir = $min ? '>' : '<';
            $id = $min ?? $max;

            return Status::select(
                'id',
                'uri',
                'caption',
                'profile_id',
                'type',
                'in_reply_to_id',
                'reblog_of_id',
                'is_nsfw',
                'scope',
                'local',
                'reply_count',
                'comments_disabled',
                'place_id',
                'likes_count',
                'reblogs_count',
                'created_at',
                'updated_at'
            )
                ->whereIn('type', $types)
                ->when(! $textOnlyReplies, function ($q, $textOnlyReplies) {
                    return $q->whereNull('in_reply_to_id');
                })
                ->where('id', $dir, $id)
                ->whereIn('profile_id', $following)
                ->whereIn('visibility', ['public', 'unlisted', 'private'])
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($s) use ($user) {
                    try {
                        $status = StatusService::get($s->id, false);
                        if (! $status) {
                            return false;
                        }
                    } catch (\Exception $e) {
                        return false;
                    }
                    $status['favourited'] = (bool) LikeService::liked($user->profile_id, $s->id);
                    $status['bookmarked'] = (bool) BookmarkService::get($user->profile_id, $s->id);
                    $status['reblogged'] = (bool) ReblogService::get($user->profile_id, $s->id);

                    return $status;
                })
                ->filter(function ($s) use ($filtered) {
                    return $s && in_array($s['account']['id'], $filtered) == false;
                })
                ->values()
                ->toArray();
        } else {
            return Status::select(
                'id',
                'uri',
                'caption',
                'profile_id',
                'type',
                'in_reply_to_id',
                'reblog_of_id',
                'is_nsfw',
                'scope',
                'local',
                'reply_count',
                'comments_disabled',
                'place_id',
                'likes_count',
                'reblogs_count',
                'created_at',
                'updated_at'
            )
                ->whereIn('type', $types)
                ->when(! $textOnlyReplies, function ($q, $textOnlyReplies) {
                    return $q->whereNull('in_reply_to_id');
                })
                ->whereIn('profile_id', $following)
                ->whereIn('visibility', ['public', 'unlisted', 'private'])
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($s) use ($user) {
                    try {
                        $status = StatusService::get($s->id, false);
                        if (! $status) {
                            return false;
                        }
                    } catch (\Exception $e) {
                        return false;
                    }
                    $status['favourited'] = (bool) LikeService::liked($user->profile_id, $s->id);
                    $status['bookmarked'] = (bool) BookmarkService::get($user->profile_id, $s->id);
                    $status['reblogged'] = (bool) ReblogService::get($user->profile_id, $s->id);

                    return $status;
                })
                ->filter(function ($s) use ($filtered) {
                    return $s && in_array($s['account']['id'], $filtered) == false;
                })
                ->values()
                ->toArray();
        }
    }

    public function networkTimelineApi(Request $request)
    {
        if (! $request->user()) {
            return response('', 403);
        }

        abort_if(config('federation.network_timeline') == false, 404);

        $this->validate($request, [
            'page' => 'nullable|integer|max:40',
            'min_id' => 'nullable|integer|min:0|max:'.PHP_INT_MAX,
            'max_id' => 'nullable|integer|min:0|max:'.PHP_INT_MAX,
            'limit' => 'nullable|integer|max:30',
        ]);

        $page = $request->input('page');
        $min = $request->input('min_id');
        $max = $request->input('max_id');
        $limit = $request->input('limit') ?? 3;
        $user = $request->user();
        $amin = SnowflakeService::byDate(now()->subDays(config('federation.network_timeline_days_falloff')));

        $filtered = $user ? UserFilterService::filters($user->profile_id) : [];
        $hideNsfw = config('instance.hide_nsfw_on_public_feeds');

        if (config('instance.timeline.network.cached') == false) {
            if ($min || $max) {
                $dir = $min ? '>' : '<';
                $id = $min ?? $max;
                $timeline = Status::select(
                    'id',
                    'uri',
                    'type',
                    'scope',
                    'created_at',
                )
                    ->where('id', $dir, $id)
                    ->when($hideNsfw, function ($q, $hideNsfw) {
                        return $q->where('is_nsfw', false);
                    })
                    ->whereNull(['in_reply_to_id', 'reblog_of_id'])
                    ->whereNotIn('profile_id', $filtered)
                    ->whereIn('type', ['photo', 'photo:album', 'video', 'video:album', 'photo:video:album'])
                    ->whereNotNull('uri')
                    ->whereScope('public')
                    ->where('id', '>', $amin)
                    ->orderBy('created_at', 'desc')
                    ->limit($limit)
                    ->get()
                    ->map(function ($s) use ($user) {
                        $status = StatusService::get($s->id);
                        $status['favourited'] = (bool) LikeService::liked($user->profile_id, $s->id);
                        $status['bookmarked'] = (bool) BookmarkService::get($user->profile_id, $s->id);
                        $status['reblogged'] = (bool) ReblogService::get($user->profile_id, $s->id);

                        return $status;
                    });
                $res = $timeline->toArray();
            } else {
                $timeline = Status::select(
                    'id',
                    'uri',
                    'type',
                    'scope',
                    'created_at',
                )
                    ->whereNull(['in_reply_to_id', 'reblog_of_id'])
                    ->whereNotIn('profile_id', $filtered)
                    ->when($hideNsfw, function ($q, $hideNsfw) {
                        return $q->where('is_nsfw', false);
                    })
                    ->whereIn('type', ['photo', 'photo:album', 'video', 'video:album', 'photo:video:album'])
                    ->whereNotNull('uri')
                    ->whereScope('public')
                    ->where('id', '>', $amin)
                    ->orderBy('created_at', 'desc')
                    ->limit($limit)
                    ->get()
                    ->map(function ($s) use ($user) {
                        $status = StatusService::get($s->id);
                        $status['favourited'] = (bool) LikeService::liked($user->profile_id, $s->id);
                        $status['bookmarked'] = (bool) BookmarkService::get($user->profile_id, $s->id);
                        $status['reblogged'] = (bool) ReblogService::get($user->profile_id, $s->id);

                        return $status;
                    });
                $res = $timeline->toArray();
            }
        } else {
            Cache::remember('api:v1:timelines:network:cache_check', 10368000, function () {
                if (NetworkTimelineService::count() == 0) {
                    NetworkTimelineService::warmCache(true, 400);
                }
            });

            if ($max) {
                $feed = NetworkTimelineService::getRankedMaxId($max, $limit);
            } elseif ($min) {
                $feed = NetworkTimelineService::getRankedMinId($min, $limit);
            } else {
                $feed = NetworkTimelineService::get(0, $limit);
            }

            $res = collect($feed)
                ->take($limit)
                ->map(function ($k) use ($user) {
                    $status = StatusService::get($k);
                    if ($status && isset($status['account']) && $user) {
                        $status['favourited'] = (bool) LikeService::liked($user->profile_id, $k);
                        $status['bookmarked'] = (bool) BookmarkService::get($user->profile_id, $k);
                        $status['reblogged'] = (bool) ReblogService::get($user->profile_id, $k);
                        $status['relationship'] = RelationshipService::get($user->profile_id, $status['account']['id']);
                    }

                    return $status;
                })
                ->filter(function ($s) use ($filtered) {
                    return $s && isset($s['account']) && in_array($s['account']['id'], $filtered) == false;
                })
                ->values()
                ->toArray();
        }

        return response()->json($res);
    }

    public function relationships(Request $request)
    {
        if (! Auth::check()) {
            return response()->json([]);
        }

        $pid = $request->user()->profile_id;

        $this->validate($request, [
            'id' => 'required|array|min:1|max:20',
            'id.*' => 'required|integer',
        ]);
        $ids = collect($request->input('id'));
        $res = $ids->filter(function ($v) use ($pid) {
            return $v != $pid;
        })
            ->map(function ($id) use ($pid) {
                return RelationshipService::get($pid, $id);
            });

        return response()->json($res);
    }

    public function account(Request $request, $id)
    {
        $res = AccountService::get($id);
        if ($res && isset($res['local'], $res['url']) && ! $res['local']) {
            $domain = parse_url($res['url'], PHP_URL_HOST);
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }

        return response()->json($res);
    }

    public function accountStatuses(Request $request, $id)
    {
        $this->validate($request, [
            'only_media' => 'nullable',
            'pinned' => 'nullable',
            'exclude_replies' => 'nullable',
            'limit' => 'nullable|integer|min:1|max:24',
            'cursor' => 'nullable',
        ]);

        $user = $request->user();
        $profile = AccountService::get($id);
        abort_if(! $profile, 404);

        if ($profile && isset($profile['local'], $profile['url']) && ! $profile['local']) {
            $domain = parse_url($profile['url'], PHP_URL_HOST);
            abort_if(in_array($domain, InstanceService::getBannedDomains()), 404);
        }

        $limit = $request->limit ?? 9;
        $scope = ['photo', 'photo:album', 'video', 'video:album'];
        $onlyMedia = $request->input('only_media', true);
        $pinned = $request->filled('pinned') && $request->boolean('pinned') == true;
        $hasCursor = $request->filled('cursor');

        $visibility = $this->determineVisibility($profile, $user);

        if (empty($visibility)) {
            return response()->json([]);
        }

        $result = collect();
        $remainingLimit = $limit;

        if ($pinned && ! $hasCursor) {
            $pinnedStatuses = Status::whereProfileId($profile['id'])
                ->whereNotNull('pinned_order')
                ->orderBy('pinned_order')
                ->get();

            $pinnedResult = $this->processStatuses($pinnedStatuses, $user, $onlyMedia);
            $result = $pinnedResult;

            $remainingLimit = max(1, $limit - $pinnedResult->count());
        }

        $paginator = Status::whereProfileId($profile['id'])
            ->whereNull('in_reply_to_id')
            ->whereNull('reblog_of_id')
            ->when($pinned, function ($query) {
                return $query->whereNull('pinned_order');
            })
            ->whereIn('type', $scope)
            ->whereIn('scope', $visibility)
            ->orderByDesc('created_at')
            ->cursorPaginate($remainingLimit)
            ->withQueryString();

        $headers = $this->generatePaginationHeaders($paginator);
        $regularStatuses = $this->processStatuses($paginator->items(), $user, $onlyMedia);
        $result = $result->concat($regularStatuses);

        return response()->json($result, 200, $headers);
    }

    /**
     *  GET /api/pixelfed/v1/statuses/{id}/pin
     */
    public function statusPin(Request $request, $id)
    {
        abort_if(! $request->user(), 403);
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
     *  GET /api/pixelfed/v1/statuses/{id}/unpin
     */
    public function statusUnpin(Request $request, $id)
    {
        abort_if(! $request->user(), 403);
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

    private function determineVisibility($profile, $user)
    {
        if (! $profile || ! isset($profile['id'])) {
            return [];
        }

        if ($user && $profile['id'] == $user->profile_id) {
            return ['public', 'unlisted', 'private'];
        }

        if ($profile['locked']) {
            if (! $user) {
                return [];
            }

            $pid = $user->profile_id;
            $isFollowing = FollowerService::follows($pid, $profile['id']);

            return $isFollowing ? ['public', 'unlisted', 'private'] : ['public'];
        } else {
            if ($user) {
                $pid = $user->profile_id;
                $isFollowing = FollowerService::follows($pid, $profile['id']);

                return $isFollowing ? ['public', 'unlisted', 'private'] : ['public', 'unlisted'];
            } else {
                return ['public', 'unlisted'];
            }
        }
    }

    private function processStatuses($statuses, $user, $onlyMedia)
    {
        return collect($statuses)->map(function ($status) use ($user) {
            try {
                $mastodonStatus = StatusService::get($status->id, false);
                if (! $mastodonStatus) {
                    return null;
                }

                if ($user) {
                    $mastodonStatus['favourited'] = (bool) LikeService::liked($user->profile_id, $status->id);
                    $mastodonStatus['bookmarked'] = (bool) BookmarkService::get($user->profile_id, $status->id);
                    $mastodonStatus['reblogged'] = (bool) StatusService::isShared($status->id, $user->profile_id);
                }

                return $mastodonStatus;
            } catch (\Exception $e) {
                return null;
            }
        })
            ->filter(function ($status) use ($onlyMedia) {
                if (! $status) {
                    return false;
                }

                if ($onlyMedia) {
                    return isset($status['media_attachments']) &&
                           is_array($status['media_attachments']) &&
                           ! empty($status['media_attachments']);
                }

                return true;
            })
            ->values();
    }

    /**
     * Generate pagination link headers from paginator
     */
    private function generatePaginationHeaders($paginator)
    {
        $link = null;

        if ($paginator->onFirstPage()) {
            if ($paginator->hasMorePages()) {
                $link = '<'.$paginator->nextPageUrl().'>; rel="prev"';
            }
        } else {
            if ($paginator->previousPageUrl()) {
                $link = '<'.$paginator->previousPageUrl().'>; rel="next"';
            }

            if ($paginator->hasMorePages()) {
                $link .= ($link ? ', ' : '').'<'.$paginator->nextPageUrl().'>; rel="prev"';
            }
        }

        return isset($link) ? ['Link' => $link] : [];
    }
}
