<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginDetail;
use App\Models\User;
use App\Models\Utility;
use App\Providers\RouteServiceProvider;
use App\Services\CompanyUserRoleService;
use App\Services\TotpService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class TwoFactorController extends Controller
{
    public function showChallenge()
    {
        if (!session()->has('2fa:user:id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function verifyChallenge(Request $request)
    {
        $request->validate([
            'otp' => 'nullable|digits:6',
            'recovery_code' => 'nullable|string',
        ]);

        $userId = session('2fa:user:id');
        $remember = (bool) session('2fa:remember', false);
        $user = User::find($userId);

        if (!$user || !$user->two_factor_enabled || empty($user->two_factor_secret)) {
            $this->clearChallengeSession();
            return redirect()->route('login')->with('error', __('Two-factor session expired. Please login again.'));
        }

        $otp = trim((string) $request->otp);
        $recoveryCode = trim((string) $request->recovery_code);
        $isValid = false;

        if ($otp !== '') {
            $totpService = new TotpService();
            $secret = decrypt($user->two_factor_secret);
            $isValid = $totpService->verifyKey($secret, $otp);
        }

        if (!$isValid && $recoveryCode !== '') {
            $isValid = $this->consumeRecoveryCode($user, $recoveryCode);
        }

        if (!$isValid) {
            return redirect()->back()->with('error', __('Invalid authentication code.'));
        }

        Auth::loginUsingId($user->id, $remember);
        session()->regenerate();
        $this->clearChallengeSession();

        $this->finalizeSuccessfulLogin(Auth::user());

        if (CompanyUserRoleService::isPlatformSuperAdmin($user)) {
            return redirect()->route('client.dashboard.view');
        }

        if ($user->type == 'company' || $user->type == 'client') {
            return redirect()->intended(RouteServiceProvider::HOME);
        }

        return redirect()->intended(RouteServiceProvider::EMPHOME);
    }

    private function consumeRecoveryCode(User $user, string $recoveryCode): bool
    {
        $codes = json_decode($user->two_factor_recovery_codes ?? '[]', true);
        if (!is_array($codes)) {
            return false;
        }

        foreach ($codes as $index => $hashCode) {
            if (Hash::check($recoveryCode, $hashCode)) {
                unset($codes[$index]);
                $user->two_factor_recovery_codes = json_encode(array_values($codes));
                $user->save();
                return true;
            }
        }

        return false;
    }

    private function finalizeSuccessfulLogin(User $user): void
    {
        $setting = Utility::settingsById($user->creatorId());
        $timezone = $setting['timezone'] ?? 'UTC';
        date_default_timezone_set($timezone);

        $user->update([
            'last_login_at' => Carbon::now()->toDateTimeString(),
        ]);

        if ($user->type != 'company' && $user->type != 'super admin') {
            $ip = $_SERVER['REMOTE_ADDR'] ?? request()->ip();
            $query = @unserialize(file_get_contents('http://ip-api.com/php/' . $ip));

            $whichbrowser = new \WhichBrowser\Parser($_SERVER['HTTP_USER_AGENT'] ?? '');
            if ($whichbrowser->device->type == 'bot') {
                return;
            }
            $referrer = isset($_SERVER['HTTP_REFERER']) ? parse_url($_SERVER['HTTP_REFERER']) : null;

            $query['browser_name'] = $whichbrowser->browser->name ?? null;
            $query['os_name'] = $whichbrowser->os->name ?? null;
            $query['browser_language'] = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? mb_substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2) : null;
            $query['device_type'] = $this->getDeviceType($_SERVER['HTTP_USER_AGENT'] ?? '');
            $query['referrer_host'] = !empty($referrer['host']);
            $query['referrer_path'] = !empty($referrer['path']);
            isset($query['timezone']) ? date_default_timezone_set($query['timezone']) : '';

            LoginDetail::create([
                'user_id' => $user->id,
                'ip' => $ip,
                'date' => date('Y-m-d H:i:s'),
                'Details' => json_encode($query),
                'created_by' => $user->creatorId(),
            ]);
        }
    }

    private function clearChallengeSession(): void
    {
        session()->forget('2fa:user:id');
        session()->forget('2fa:remember');
    }

    private function getDeviceType(string $userAgent): string
    {
        $mobileRegex = '/(?:phone|windows\s+phone|ipod|blackberry|(?:android|bb\d+|meego|silk|googlebot) .+? mobile|palm|windows\s+ce|opera mini|avantgo|mobilesafari|docomo)/i';
        $tabletRegex = '/(?:ipad|playbook|(?:android|bb\d+|meego|silk)(?! .+? mobile))/i';

        if (preg_match_all($mobileRegex, $userAgent)) {
            return 'mobile';
        }
        if (preg_match_all($tabletRegex, $userAgent)) {
            return 'tablet';
        }
        return 'desktop';
    }
}
