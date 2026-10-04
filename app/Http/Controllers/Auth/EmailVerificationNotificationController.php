<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\Services\EmailLogService;
use Illuminate\Http\Request;
use  App\Models\Utility;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(RouteServiceProvider::HOME);
        }

        $settings = Utility::settings();

        \App\Support\MailConfig::apply($request->user()->creatorId(), $settings);

        try {
            $request->user()->sendEmailVerificationNotification();
            EmailLogService::logSuccess(
                $request->user()->email,
                __('Email Verification'),
                'email_verification',
                $request->user()->creatorId(),
                $request->user()->id
            );
        } catch (\Throwable $th) {
            EmailLogService::logFailure(
                $request->user()->email,
                __('Email Verification'),
                'email_verification',
                $th->getMessage(),
                $request->user()->creatorId(),
                $request->user()->id
            );

            return redirect()->back()->with('error', __($th->getMessage()));
        }

        return back()->with('status', 'verification-link-sent');
    }
}
