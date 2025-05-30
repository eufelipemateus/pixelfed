<?php

namespace App\Http\Controllers;

use App\Jobs\InboxPipeline\DeleteWorker;
use App\Jobs\InboxPipeline\InboxValidator;
use App\Jobs\InboxPipeline\InboxWorker;
use App\Profile;
use App\Services\AccountService;
use App\Services\InstanceService;
use App\Status;
use App\Util\Lexer\Nickname;
use App\Util\Site\Nodeinfo;
use App\Util\Webfinger\Webfinger;
use Cache;
use Illuminate\Http\Request;

class FederationController extends Controller
{
    public function nodeinfoWellKnown()
    {
        abort_if(! config('federation.nodeinfo.enabled'), 404);

        return response()->json(Nodeinfo::wellKnown(), 200, [], JSON_UNESCAPED_SLASHES)
            ->header('Access-Control-Allow-Origin', '*');
    }

    public function nodeinfo()
    {
        abort_if(! config('federation.nodeinfo.enabled'), 404);

        return response()->json(Nodeinfo::get(), 200, [], JSON_UNESCAPED_SLASHES)
            ->header('Access-Control-Allow-Origin', '*');
    }

    public function webfinger(Request $request)
    {
        if (! config('federation.webfinger.enabled') ||
            ! $request->has('resource') ||
            ! $request->filled('resource')
        ) {
            return response('', 400);
        }

        $resource = $request->input('resource');
        $domain = config('pixelfed.domain.app');

        // Instance Actor
        if (
            config('federation.activitypub.sharedInbox') &&
            $resource == 'acct:'.$domain.'@'.$domain
        ) {
            $res = [
                'subject' => 'acct:'.$domain.'@'.$domain,
                'aliases' => [
                    'https://'.$domain.'/i/actor',
                ],
                'links' => [
                    [
                        'rel' => 'http://webfinger.net/rel/profile-page',
                        'type' => 'text/html',
                        'href' => 'https://'.$domain.'/kb/instance-actor',
                    ],
                    [
                        'rel' => 'self',
                        'type' => 'application/activity+json',
                        'href' => 'https://'.$domain.'/i/actor',
                    ],
                    [
                        'rel' => 'http://ostatus.org/schema/1.0/subscribe',
                        'template' => 'https://'.$domain.'/authorize_interaction?uri={uri}',
                    ],
                ],
            ];

            return response()->json($res, 200, [], JSON_UNESCAPED_SLASHES);
        }

        if (str_starts_with($resource, 'https://')) {
            if (str_starts_with($resource, 'https://'.$domain.'/users/')) {
                $username = str_replace('https://'.$domain.'/users/', '', $resource);
                if (strlen($username) > 30) {
                    return response('', 400);
                }
                $stripped = str_replace(['_', '.', '-'], '', $username);
                if (! ctype_alnum($stripped)) {
                    return response('', 400);
                }
                $key = 'federation:webfinger:sha256:url-username:'.$username;
                if ($cached = Cache::get($key)) {
                    return response()->json($cached, 200, [], JSON_UNESCAPED_SLASHES);
                }
                $profile = Profile::whereUsername($username)->first();
                if (! $profile || $profile->status !== null || $profile->domain) {
                    return response('', 400);
                }
                $webfinger = (new Webfinger($profile))->generate();
                Cache::put($key, $webfinger, 1209600);

                return response()->json($webfinger, 200, [], JSON_UNESCAPED_SLASHES)
                    ->header('Access-Control-Allow-Origin', '*');
            } else {
                return response('', 400);
            }
        }
        $hash = hash('sha256', $resource);
        $key = 'federation:webfinger:sha256:'.$hash;
        if ($cached = Cache::get($key)) {
            return response()->json($cached, 200, [], JSON_UNESCAPED_SLASHES);
        }
        if (strpos($resource, $domain) == false) {
            return response('', 400);
        }
        $parsed = Nickname::normalizeProfileUrl($resource);
        if (empty($parsed) || $parsed['domain'] !== $domain) {
            return response('', 400);
        }
        $username = $parsed['username'];
        $profile = Profile::whereUsername($username)->first();
        if (! $profile || $profile->status !== null || $profile->domain) {
            return response('', 400);
        }
        $webfinger = (new Webfinger($profile))->generate();
        Cache::put($key, $webfinger, 1209600);

        return response()->json($webfinger, 200, [], JSON_UNESCAPED_SLASHES)
            ->header('Access-Control-Allow-Origin', '*');
    }

    public function hostMeta(Request $request)
    {
        abort_if(! config('federation.webfinger.enabled'), 404);

        $path = route('well-known.webfinger');
        $xml = '<?xml version="1.0" encoding="UTF-8"?><XRD xmlns="http://docs.oasis-open.org/ns/xri/xrd-1.0"><Link rel="lrdd" type="application/xrd+xml" template="'.$path.'?resource={uri}"/></XRD>';

        return response($xml)->header('Content-Type', 'application/xrd+xml');
    }

    public function userOutbox(Request $request, $username)
    {
        abort_if(! (bool) config_cache('federation.activitypub.enabled'), 404);

        if (! $request->wantsJson()) {
            return redirect('/'.$username);
        }

        $id = AccountService::usernameToId($username);
        abort_if(! $id, 404);
        $account = AccountService::get($id);
        abort_if(! $account || ! isset($account['statuses_count']), 404);
        $res = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => 'https://'.config('pixelfed.domain.app').'/users/'.$username.'/outbox',
            'type' => 'OrderedCollection',
            'totalItems' => $account['statuses_count'] ?? 0,
        ];

        return response(json_encode($res, JSON_UNESCAPED_SLASHES))->header('Content-Type', 'application/activity+json');
    }

    public function userInbox(Request $request, $username)
    {
        abort_if(! (bool) config_cache('federation.activitypub.enabled'), 404);
        abort_if(! config('federation.activitypub.inbox'), 404);

        $headers = $request->headers->all();
        $payload = $request->getContent();
        if (! $payload || empty($payload)) {
            return;
        }
        $obj = json_decode($payload, true, 8);
        if (! isset($obj['id'])) {
            return;
        }
        $domain = parse_url($obj['id'], PHP_URL_HOST);
        if (in_array($domain, InstanceService::getBannedDomains())) {
            return;
        }

        if (isset($obj['type']) && $obj['type'] === 'Delete') {
            if (isset($obj['object']) && isset($obj['object']['type']) && isset($obj['object']['id'])) {
                if ($obj['object']['type'] === 'Person') {
                    if (Profile::whereRemoteUrl($obj['object']['id'])->exists()) {
                        dispatch(new DeleteWorker($headers, $payload))->onQueue('inbox');

                        return;
                    }
                }

                if ($obj['object']['type'] === 'Tombstone') {
                    if (Status::whereObjectUrl($obj['object']['id'])->exists()) {
                        dispatch(new DeleteWorker($headers, $payload))->onQueue('delete');

                        return;
                    }
                }

                if ($obj['object']['type'] === 'Story') {
                    dispatch(new DeleteWorker($headers, $payload))->onQueue('story');

                    return;
                }
            }

            return;
        } elseif (isset($obj['type']) && in_array($obj['type'], ['Follow', 'Accept'])) {
            dispatch(new InboxValidator($username, $headers, $payload))->onQueue('follow');
        } else {
            dispatch(new InboxValidator($username, $headers, $payload))->onQueue('high');
        }

    }

    public function sharedInbox(Request $request)
    {
        abort_if(! (bool) config_cache('federation.activitypub.enabled'), 404);
        abort_if(! config('federation.activitypub.sharedInbox'), 404);

        $headers = $request->headers->all();
        $payload = $request->getContent();

        if (! $payload || empty($payload)) {
            return;
        }

        $obj = json_decode($payload, true, 8);
        if (! isset($obj['id'])) {
            return;
        }

        $domain = parse_url($obj['id'], PHP_URL_HOST);
        if (in_array($domain, InstanceService::getBannedDomains())) {
            return;
        }

        if (isset($obj['type']) && $obj['type'] === 'Delete') {
            if (isset($obj['object']) && isset($obj['object']['type']) && isset($obj['object']['id'])) {
                if ($obj['object']['type'] === 'Person') {
                    if (Profile::whereRemoteUrl($obj['object']['id'])->exists()) {
                        dispatch(new DeleteWorker($headers, $payload))->onQueue('inbox');

                        return;
                    }
                }

                if ($obj['object']['type'] === 'Tombstone') {
                    if (Status::whereObjectUrl($obj['object']['id'])->exists()) {
                        dispatch(new DeleteWorker($headers, $payload))->onQueue('delete');

                        return;
                    }
                }

                if ($obj['object']['type'] === 'Story') {
                    dispatch(new DeleteWorker($headers, $payload))->onQueue('story');

                    return;
                }
            }

            return;
        } elseif (isset($obj['type']) && in_array($obj['type'], ['Follow', 'Accept'])) {
            dispatch(new InboxWorker($headers, $payload))->onQueue('follow');
        } else {
            dispatch(new InboxWorker($headers, $payload))->onQueue('shared');
        }

    }

    public function userFollowing(Request $request, $username)
    {
        abort_if(! (bool) config_cache('federation.activitypub.enabled'), 404);

        $id = AccountService::usernameToId($username);
        abort_if(! $id, 404);
        $account = AccountService::get($id);
        abort_if(! $account || ! isset($account['following_count']), 404);

        $perPage = 50;
        $page = max(1, (int) request()->query('page', 1));
        $offset = ($page - 1) * $perPage;

        $following = AccountService::getFollowing($id, $perPage, $offset);

        $baseUrl = url()->current();
        $nextPage = $account['following_count'] > $offset + $perPage ? "$baseUrl?page=" . ($page + 1) : null;
        $prevPage = $page > 1 ? "$baseUrl?page=" . ($page - 1) : null;
        $lastPage = $nextPage ? "$baseUrl?page=" . ceil($account['following_count'] / $perPage) : null;
        $firstPage = "$baseUrl?page=1";

        $obj = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' =>  url()->full(),
            'type' => 'OrderedCollection',
            'totalItems' => $account['following_count'] ?? 0,
        ];

        if (!request()->has('page')) {
            $obj['first'] = $firstPage;
        }

        if ($nextPage && request()->has('page')) {
            $obj['next'] = $nextPage;
        }

        if ($prevPage && request()->has('page')) {
            $obj['prev'] = $prevPage;
        }

        if ($lastPage && request()->has('page')) {
            $obj['last'] = $lastPage;
        }

        if (request()->has('page')) {
            $obj['partOf'] = $baseUrl;
        }

        if (request()->has('page')) {
            $obj['orderedItems'] = $following;
        }
        return response()->json($obj)->header('Content-Type', 'application/activity+json');;
    }

    public function userFollowers(Request $request, $username)
    {
        abort_if(! (bool) config_cache('federation.activitypub.enabled'), 404);
        $id = AccountService::usernameToId($username);
        abort_if(! $id, 404);
        $account = AccountService::get($id);
        abort_if(! $account || ! isset($account['followers_count']), 404);

        $perPage = 50;
        $page = max(1, (int) request()->query('page', 1));
        $offset = ($page - 1) * $perPage;

        $followers = AccountService::getFollowers($id, $perPage, $offset);

        $baseUrl = url()->current();
        $nextPage = $account['followers_count'] > $offset + $perPage ? "$baseUrl?page=" . ($page + 1) : null;
        $prevPage = $page > 1 ? "$baseUrl?page=" . ($page - 1) : null;
        $lastPage = $nextPage ? "$baseUrl?page=" . ceil($account['followers_count'] / $perPage) : null;
        $firstPage = "$baseUrl?page=1";

        $obj = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => url()->full(),
            'type' => 'OrderedCollection',
            'totalItems' => $account['followers_count'] ?? 0,
        ];


        if (!request()->has('page')) {
            $obj['first'] = $firstPage;
        }

        if ($nextPage && request()->has('page')) {
            $obj['next'] = $nextPage;
        }

        if ($prevPage && request()->has('page')) {
            $obj['prev'] = $prevPage;
        }

        if ($lastPage && request()->has('page')) {
            $obj['last'] = $lastPage;
        }

        if (request()->has('page')) {
            $obj['partOf'] = $baseUrl;
        }

        if (request()->has('page')) {
            $obj['orderedItems'] = $followers;
        }

        return response()->json($obj)->header('Content-Type', 'application/activity+json');
    }
}
