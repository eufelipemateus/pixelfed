<?php

namespace App\Http\Controllers;

use App\Jobs\ReportPipeline\ReportNotifyAdminViaEmail;
use App\Models\Group;
use App\Profile;
use App\Report;
use App\Status;
use Auth;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    protected $profile;

    public function __construct()
    {
        $this->middleware('auth');
    }

    public function showForm(Request $request)
    {
        $this->validate($request, [
            'type' => 'required|alpha_dash|in:comment,group,post,user',
            'id' => 'required|integer|min:1',
        ]);

        $type = $request->input('type');
        $id = $request->input('id');
        $pid = $request->user()->profile_id;

        switch ($request->input('type')) {
            case 'post':
            case 'comment':
                Status::findOrFail($id);
                break;

            case 'user':
                Profile::findOrFail($id);
                break;

            case 'group':
                Group::where('profile_id', '!=', $pid)->findOrFail($id);
                break;

            default:
                // code...
                break;
        }

        return view('report.form');
    }

    public function notInterestedForm(Request $request)
    {
        return view('report.not-interested');
    }

    public function spamForm(Request $request)
    {
        return view('report.spam');
    }

    public function spamCommentForm(Request $request)
    {
        return view('report.spam.comment');
    }

    public function spamPostForm(Request $request)
    {
        return view('report.spam.post');
    }

    public function spamProfileForm(Request $request)
    {
        return view('report.spam.profile');
    }

    public function sensitiveCommentForm(Request $request)
    {
        return view('report.sensitive.comment');
    }

    public function sensitivePostForm(Request $request)
    {
        return view('report.sensitive.post');
    }

    public function sensitiveProfileForm(Request $request)
    {
        return view('report.sensitive.profile');
    }

    public function abusiveCommentForm(Request $request)
    {
        return view('report.abusive.comment');
    }

    public function abusivePostForm(Request $request)
    {
        return view('report.abusive.post');
    }

    public function abusiveProfileForm(Request $request)
    {
        return view('report.abusive.profile');
    }

    public function formStore(Request $request)
    {
        $this->validate($request, [
            'report' => 'required|alpha_dash',
            'type' => 'required|alpha_dash',
            'id' => 'required|integer|min:1',
            'msg' => 'nullable|string|max:150',
        ]);

        $profile = Auth::user()->profile;
        $reportType = $request->input('report');
        $object_id = $request->input('id');
        $object_type = $request->input('type');
        $msg = $request->input('msg');
        $object = null;
        $types = [
            // original 3
            'spam',
            'sensitive',
            'abusive',

            // new
            'underage',
            'copyright',
            'impersonation',
            'scam',
            'terrorism',
        ];

        if (! in_array($reportType, $types)) {
            if ($request->wantsJson()) {
                return abort(400, 'Invalid report type');
            } else {
                return redirect('/timeline')->with('error', 'Invalid report type');
            }
        }

        $rpid = null;

        switch ($object_type) {
            case 'post':
                $object = Status::findOrFail($object_id);
                $object_type = 'App\Status';
                $exists = Report::whereUserId(Auth::id())
                    ->whereObjectId($object->id)
                    ->whereObjectType('App\Status')
                    ->count();

                $rpid = $object->profile_id;
                break;

            case 'user':
                $object = Profile::findOrFail($object_id);
                $object_type = 'App\Profile';
                $exists = Report::whereUserId(Auth::id())
                    ->whereObjectId($object->id)
                    ->whereObjectType('App\Profile')
                    ->count();
                $rpid = $object->id;
                break;

            case 'group':
                $object = Group::findOrFail($object_id);
                $object_type = 'App\Models\Group';
                $exists = Report::whereUserId(Auth::id())
                    ->whereObjectId($object->id)
                    ->whereObjectType('App\Models\Group')
                    ->count();
                $rpid = $object->profile_id;
                break;

            default:
                if ($request->wantsJson()) {
                    return abort(400, 'Invalid report type');
                } else {
                    return redirect('/timeline')->with('error', 'Invalid report type');
                }
                break;
        }

        if ($exists !== 0) {
            if ($request->wantsJson()) {
                return response()->json(200);
            } else {
                return redirect('/timeline')->with('error', 'You have already reported this!');
            }
        }

        if ($object->profile_id == $profile->id) {
            if ($request->wantsJson()) {
                return response()->json(200);
            } else {
                return redirect('/timeline')->with('error', 'You cannot report your own content!');
            }
        }

        $report = new Report;
        $report->profile_id = $profile->id;
        $report->user_id = Auth::id();
        $report->object_id = $object->id;
        $report->object_type = $object_type;
        $report->reported_profile_id = $rpid;
        $report->type = $request->input('report');
        $report->message = e($request->input('msg'));
        $report->save();

        if (config('instance.reports.email.enabled')) {
            ReportNotifyAdminViaEmail::dispatch($report)->onQueue('default');
        }

        if ($request->wantsJson()) {
            return response()->json(200);
        } else {
            return redirect('/timeline')->with('status', 'Report successfully sent!');
        }
    }
}
