<?php

namespace App\Http\Controllers\Auth;

use App\AccountLog;
use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use App\Services\BouncerService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Enums\StatusEnums;
use Illuminate\Support\Facades\Session;
use App\Services\SessionService;
class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/i/web';

    protected $maxAttempts = 5;
    protected $decayMinutes = 60;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    public function showLoginForm()
    {
		if(config('pixelfed.bouncer.cloud_ips.ban_logins')) {
			abort_if(BouncerService::checkIp(request()->ip()), 404);
		}

        return view('auth.login');
    }


    public function login(Request $request)
    {
        if (config('instance.limit_users_active.enabled')) {
            $totalAtiveSessions = SessionService::getTotalActiveSessions();
            if ($totalAtiveSessions >= config('instance.limit_users_active.max_users_active')) {
                return back()->withErrors(
                    [
                    'limite' => 'Limite de usuários simultâneos atingido. Tente novamente mais tarde.'
                    ]
                );
            }
        }

        $this->validateLogin($request);
        if ($this->attemptLogin($request)) {
            return $this->sendLoginResponse($request);
        }

        return $this->sendFailedLoginResponse($request);
    }

    /**
     * Validate the user login request.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return void
     */
    public function validateLogin($request)
    {
    	if(config('pixelfed.bouncer.cloud_ips.ban_logins')) {
			abort_if(BouncerService::checkIp($request->ip()), 404);
		}

        $rules = [
            $this->username() => 'required|email',
            'password'        => 'required|string|min:6',
        ];
        $messages = [];

        if(
        	(bool) config_cache('captcha.enabled') &&
        	(bool) config_cache('captcha.active.login') ||
        	(
				(bool) config_cache('captcha.triggers.login.enabled') &&
				request()->session()->has('login_attempts') &&
				request()->session()->get('login_attempts') >= config('captcha.triggers.login.attempts')
			)
        ) {
            $rules['h-captcha-response'] = 'required|filled|captcha|min:5';
            $messages['h-captcha-response.required'] = 'The captcha must be filled';
        }
        $request->validate($rules, $messages);
    }

    /**
     * The user has been authenticated.
     *
     * @param \Illuminate\Http\Request $request
     * @param mixed                    $user
     *
     * @return mixed
     */
    protected function authenticated($request, $user)
    {
        if($user->status == StatusEnums::DELETED) {
            return;
        }

        $profile = $user->profile;
        $user->enable();
        $profile->enable();

        if (config('instance.limit_users_active.enabled')) {
            SessionService::setActiveSession(Session::getId(), $user->id);
        }
        $log = new AccountLog();
        $log->user_id = $user->id;
        $log->item_id = $user->id;
        $log->item_type = 'App\User';
        $log->action = 'auth.login';
        $log->message = 'Account Login';
        $log->link = null;
        $log->ip_address = $request->ip();
        $log->user_agent = $request->userAgent();
        $log->save();
    }

    /**
     * Get the failed login response instance.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function sendFailedLoginResponse(Request $request)
    {
    	if(config('captcha.triggers.login.enabled')) {
			if ($request->session()->has('login_attempts')) {
				$ct = $request->session()->get('login_attempts');
				$request->session()->put('login_attempts', $ct + 1);
			} else {
				$request->session()->put('login_attempts', 1);
			}
    	}

        throw ValidationException::withMessages([
            $this->username() => [trans('auth.failed')],
        ]);
    }

    public function logout(Request $request)
    {
        if (config('instance.limit_users_active.enabled')) {
            $sessionId = Session::getId();
            SessionService::removeActiveSession($sessionId);
        }
        $this->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
