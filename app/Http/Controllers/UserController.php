<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CustomField;
use App\Models\Employee;
use App\Models\ExperienceCertificate;
use App\Models\GenerateOfferLetter;
use App\Models\JoiningLetter;
use App\Models\LoginDetail;
use App\Models\NOC;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserToDo;
use App\Models\Utility;
use Auth;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Lab404\Impersonate\Impersonate;
use Spatie\Permission\Models\Role;
use App\Models\ReferralTransaction;
use App\Models\ReferralSetting;
use App\Services\PosRestaurantRoleService;
use App\Services\TotpService;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{

    public function index()
    {
        User::defaultEmail();

        $user = \Auth::user();
        if (\Auth::user()->can('manage user')) {
            if (\Auth::user()->type == 'super admin') {
                $users = User::where('created_by', '=', $user->creatorId())->where('type', '=', 'company')->with(['currentPlan'])->get();
            } else {
                $companyId = (int) $user->creatorId();
                if (Schema::hasTable('company_user')) {
                    $memberUserIds = DB::table('company_user')
                        ->where('company_id', $companyId)
                        ->where('status', 'active')
                        ->pluck('user_id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                    $users = User::where('type', '!=', 'client')
                        ->where(function ($q) use ($companyId, $memberUserIds) {
                            $q->where('created_by', $companyId);
                            if (!empty($memberUserIds)) {
                                $q->orWhereIn('id', $memberUserIds);
                            }
                        })
                        ->with(['currentPlan'])
                        ->get();
                } else {
                    $users = User::where('created_by', '=', $companyId)->where('type', '!=', 'client')->with(['currentPlan'])->get();
                }
            }

            return view('user.index')->with('users', $users);
        } else {
            return redirect()->back();
        }

    }

    public function create()
    {

        $customFields = CustomField::where('created_by', '=', \Auth::user()->creatorId())->where('module', '=', 'user')->get();
        $user = \Auth::user();
        $roles = Role::where('created_by', '=', $user->creatorId())->where('name', '!=', 'client')->get()->pluck('name', 'id');
        $creatorId = (int) \Auth::user()->creatorId();
        $branches = Branch::where('created_by', $creatorId)->where('is_active', true)
            ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
            ->orderBy('name')->pluck('name', 'id');
        $tenantBranches = Branch::where('created_by', $creatorId)->where('is_active', true)->orderBy('name')->get();
        $posBranchRoleNames = PosRestaurantRoleService::posBranchRoleNames();
        $posBranchRoleIds = PosRestaurantRoleService::roleIdsRequiringPosBranch($creatorId);
        if (\Auth::user()->can('create user')) {
            return view('user.create', compact('roles', 'customFields', 'branches', 'posBranchRoleNames', 'tenantBranches', 'posBranchRoleIds'));
        } else {
            return redirect()->back();
        }
    }

    public function store(Request $request)
    {

        if (\Auth::user()->can('create user')) {
            $default_language = DB::table('settings')->select('value')->where('name', 'default_language')->where('created_by', '=', \Auth::user()->creatorId())->first();
            $objUser = \Auth::user()->creatorId();

            if (\Auth::user()->type == 'super admin') {
                $validator = \Validator::make(
                    $request->all(), [
                        'name' => 'required|max:120',
                        'email' => 'required|email|unique:users',
                    ]
                );
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();

                    return redirect()->back()->with('error', $messages->first());
                }

                $enableLogin = 0;
                if (!empty($request->password_switch) && $request->password_switch == 'on') {
                    $enableLogin = 1;
                    $validator = \Validator::make(
                        $request->all(), ['password' => 'required|min:6']
                    );

                    if ($validator->fails()) {
                        return redirect()->back()->with('error', $validator->errors()->first());
                    }
                }
                $userpassword = $request->input('password');
                $settings = Utility::settings();

                do {
                    $code = rand(100000, 999999);
                } while (User::where('referral_code', $code)->exists());

                $user = new User();
                $user['name'] = $request->name;
                $user['email'] = $request->email;
                $psw = $request->password;
                $user['password'] = !empty($userpassword)?\Hash::make($userpassword) : null;
                $user['type'] = 'company';
                $user['default_pipeline'] = 1;
                $user['plan'] = 1;
                $user['lang'] = !empty($default_language) ? $default_language->value : 'en';
                $user['referral_code'] = $code;
                $user['created_by'] = \Auth::user()->creatorId();
                $user['plan'] = Plan::first()->id;
                if ($settings['email_verification'] == 'on') {

                    $user['email_verified_at'] = null;
                } else {
                    $user['email_verified_at'] = date('Y-m-d H:i:s');
                }
                $user['is_enable_login'] = $enableLogin;

                $user->save();
                $this->syncCompanyMembership($user, [(int) $user->id], (int) $user->id);
                $role_r = Role::findByName('company');
                $user->assignRole($role_r);
                $user->userDefaultDataRegister($user->id);
                $user->userWarehouseRegister($user->id);

                //default bank account for new company
                $user->userDefaultBankAccount($user->id);

                Utility::insertChartAccountData($user->id);
                // Utility::chartOfAccountTypeData($user->id);
                // default chart of account for new company
                // Utility::chartOfAccountData1($user->id);

                Utility::pipeline_lead_deal_Stage($user->id);
                Utility::project_task_stages($user->id);
                Utility::labels($user->id);
                Utility::sources($user->id);
                Utility::jobStage($user->id);
                GenerateOfferLetter::defaultOfferLetterRegister($user->id);
                ExperienceCertificate::defaultExpCertificatRegister($user->id);
                JoiningLetter::defaultJoiningLetterRegister($user->id);
                NOC::defaultNocCertificateRegister($user->id);

                PosRestaurantRoleService::createPosRestaurantRolesForCompany($user->id);
            } else {
                $validator = \Validator::make(
                    $request->all(), [
                        'name' => 'required|max:120',
                        'email' => 'required|email',
                        'role' => 'required',
                    ]
                );
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();
                    return redirect()->back()->with('error', $messages->first());
                }

                $enableLogin = 0;
                if (!empty($request->password_switch) && $request->password_switch == 'on') {
                    $enableLogin = 1;
                    $validator = \Validator::make(
                        $request->all(), ['password' => 'required|min:6']
                    );

                    if ($validator->fails()) {
                        return redirect()->back()->with('error', $validator->errors()->first());
                    }
                }

                $objUser = User::find($objUser);
                $user = User::find(\Auth::user()->created_by);
                $total_user = $objUser->countUsers();
                $plan = Plan::find($objUser->plan);
                $userpassword = $request->input('password');
                if ($total_user < $plan->max_users || $plan->max_users == -1) {
                    $role_r = Role::findById($request->role);
                    $psw = $request->password;
                    $request['password'] = !empty($userpassword)?\Hash::make($userpassword) : null;
                    $request['type'] = $role_r->name;
                    $request['lang'] = !empty($default_language) ? $default_language->value : 'en';
                    $request['created_by'] = \Auth::user()->creatorId();
                    $request['email_verified_at'] = date('Y-m-d H:i:s');
                    $request['is_enable_login'] = $enableLogin;
                    $companyId = (int) \Auth::user()->creatorId();
                    $requestPayload = $request->all();
                    if ($redirect = PosRestaurantRoleService::applyPosBranchToUserInput($requestPayload, $request, $role_r, $companyId)) {
                        return $redirect;
                    }
                    if ($redirect = PosRestaurantRoleService::applyPosPinToUserInput($requestPayload, $request, $role_r, $companyId)) {
                        return $redirect;
                    }
                    $branchIdsForSync = PosRestaurantRoleService::branchIdsForSyncFromRequest($request);

                    $existingUser = User::where('email', $request->email)->first();
                    $linkedExistingUser = false;
                    if ($existingUser) {
                        if (in_array($existingUser->type, ['super admin', 'client'], true)) {
                            return redirect()->back()->with('error', __('This email belongs to a super admin/client account and cannot be linked here.'));
                        }
                        $existingCompanyIds = DB::table('company_user')->where('user_id', $existingUser->id)->pluck('company_id')->map(fn ($cid) => (int) $cid)->all();
                        if ($existingUser->type === 'company') {
                            $existingCompanyIds[] = (int) $existingUser->id;
                        }
                        $existingCompanyIds[] = (int) \Auth::user()->creatorId();
                        $defaultCompanyId = !empty($existingUser->default_company_id)
                            ? (int) $existingUser->default_company_id
                            : ($existingUser->type === 'company' ? (int) $existingUser->id : (int) \Auth::user()->creatorId());
                        $this->syncCompanyMembership($existingUser, $existingCompanyIds, $defaultCompanyId);
                        if ($existingUser->type !== 'company' && !$existingUser->hasRole($role_r->name)) {
                            $existingUser->assignRole($role_r);
                        }
                        if ($existingUser->type !== 'company') {
                            $existingUser->fill($requestPayload)->save();
                            $existingUser->syncBranchesFromRequest($branchIdsForSync);
                        } else {
                            if (empty($existingUser->default_company_id)) {
                                $existingUser->default_company_id = (int) $existingUser->id;
                                $existingUser->save();
                            }
                        }
                        $user = $existingUser;
                        $linkedExistingUser = true;
                    } else {
                        $user = User::create($requestPayload);
                        $this->syncCompanyMembership($user, [(int) \Auth::user()->creatorId()], (int) \Auth::user()->creatorId());
                        $user->assignRole($role_r);
                        $user->syncBranchesFromRequest($branchIdsForSync);
                        if ($request['type'] != 'client') {
                            \App\Models\Utility::employeeDetails($user->id, \Auth::user()->creatorId());
                        }
                    }

                } else {
                    return redirect()->back()->with('error', __('Your user limit is over, Please upgrade plan.'));
                }
            }
            // Send Email
            $setings = Utility::settings();
            if ($setings['new_user'] == 1 && (!isset($linkedExistingUser) || $linkedExistingUser === false)) {

                $user->password = $psw;
                $user->type = $role_r->name;
                $user->userDefaultDataRegister($user->id);

                $userArr = [
                    'email' => $user->email,
                    'password' => $user->password,
                ];
                $resp = Utility::sendEmailTemplate('new_user', [$user->id => $user->email], $userArr);

                if (\Auth::user()->type == 'super admin') {
                    return redirect()->route('users.index')->with('success', __('Company successfully created.') . ((!empty($resp) && $resp['is_success'] == false && !empty($resp['error'])) ? '<br> <span class="text-danger">' . $resp['error'] . '</span>' : ''));
                } else {
                    return redirect()->route('users.index')->with('success', __('User successfully created.') . ((!empty($resp) && $resp['is_success'] == false && !empty($resp['error'])) ? '<br> <span class="text-danger">' . $resp['error'] . '</span>' : ''));

                }
            }
            if (\Auth::user()->type == 'super admin') {
                return redirect()->route('users.index')->with('success', __('Company successfully created.'));
            } else {
                if (isset($linkedExistingUser) && $linkedExistingUser === true) {
                    return redirect()->route('users.index')->with('success', __('Existing user linked to this company successfully.'));
                }
                return redirect()->route('users.index')->with('success', __('User successfully created.'));

            }

        } else {
            return redirect()->back();
        }

    }
    public function show()
    {
        return redirect()->route('user.index');
    }

    public function edit($id)
    {
        $user = \Auth::user();
        $roles = Role::where('created_by', '=', $user->creatorId())->where('name', '!=', 'client')->get()->pluck('name', 'id');
        if (\Auth::user()->can('edit user')) {
            $user = User::findOrFail($id);
            $this->assertUserBelongsToCurrentCompany($user);
            $user->load('branches');
            $user->customField = CustomField::getData($user, 'user');
            $customFields = CustomField::where('created_by', '=', \Auth::user()->creatorId())->where('module', '=', 'user')->get();
            $creatorId = (int) \Auth::user()->creatorId();
            $branches = Branch::where('created_by', $creatorId)->where('is_active', true)
                ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
                ->orderBy('name')->pluck('name', 'id');
            if ($user->pos_branch_id && ! $branches->has((int) $user->pos_branch_id)) {
                $assigned = Branch::where('id', $user->pos_branch_id)->where('created_by', $creatorId)->first();
                if ($assigned) {
                    $branches[(int) $assigned->id] = $assigned->name.' — '.__('set branch POS type to Restaurant');
                }
            }
            $tenantBranches = Branch::where('created_by', $creatorId)->where('is_active', true)->orderBy('name')->get();
            $posBranchRoleNames = PosRestaurantRoleService::posBranchRoleNames();
            $posBranchRoleIds = PosRestaurantRoleService::roleIdsRequiringPosBranch($creatorId);

            return view('user.edit', compact('user', 'roles', 'customFields', 'branches', 'posBranchRoleNames', 'tenantBranches', 'posBranchRoleIds'));
        } else {
            return redirect()->back();
        }

    }

    public function update(Request $request, $id)
    {

        if (\Auth::user()->can('edit user')) {
            if (\Auth::user()->type == 'super admin') {
                $user = User::findOrFail($id);
                $validator = \Validator::make(
                    $request->all(), [
                        'name' => 'required|max:120',
                        'email' => 'required|email|unique:users,email,' . $id,
                    ]
                );
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();
                    return redirect()->back()->with('error', $messages->first());
                }

                $role = Role::findByName('company');
                $input = $request->all();
                $input['type'] = $role->name;

                $user->fill($input)->save();
                $this->syncCompanyMembership($user, [(int) $user->id], (int) $user->id);
                CustomField::saveData($user, $request->customField);

                $roles[] = $role->id;
                $user->roles()->sync($roles);

                return redirect()->route('users.index')->with(
                    'success', 'company successfully updated.'
                );
            } else {
                $user = User::findOrFail($id);
                $this->assertUserBelongsToCurrentCompany($user);
                $validator = \Validator::make(
                    $request->all(), [
                        'name' => 'required|max:120',
                        'email' => 'required|email|unique:users,email,' . $id,
                        'role' => 'required',
                    ]
                );
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();
                    return redirect()->back()->with('error', $messages->first());
                }

                $role = Role::findById($request->role);
                $companyId = (int) \Auth::user()->creatorId();
                $input = $request->all();
                $input['type'] = $role->name;
                if ($redirect = PosRestaurantRoleService::applyPosBranchToUserInput($input, $request, $role, $companyId, (int) $user->id)) {
                    return $redirect;
                }
                if ($redirect = PosRestaurantRoleService::applyPosPinToUserInput($input, $request, $role, $companyId, (int) $user->id)) {
                    return $redirect;
                }
                $branchIdsForSync = PosRestaurantRoleService::branchIdsForSyncFromRequest($request);

                $user->fill($input)->save();
                $existingCompanyIds = DB::table('company_user')->where('user_id', $user->id)->pluck('company_id')->map(fn ($cid) => (int) $cid)->all();
                if (empty($existingCompanyIds)) {
                    $existingCompanyIds = [(int) \Auth::user()->creatorId()];
                }
                $defaultCompanyId = !empty($user->default_company_id) ? (int) $user->default_company_id : (int) $existingCompanyIds[0];
                $this->syncCompanyMembership($user, $existingCompanyIds, $defaultCompanyId);
                Utility::employeeDetailsUpdate($user->id, \Auth::user()->creatorId());
                CustomField::saveData($user, $request->customField);

                $roles[] = $request->role;
                $user->roles()->sync($roles);
                $user->syncBranchesFromRequest($branchIdsForSync);

                return redirect()->route('users.index')->with(
                    'success', 'User successfully updated.'
                );
            }
        } else {
            return redirect()->back();
        }
    }

    public function destroy($id)
    {

        if (\Auth::user()->can('delete user')) {
            if ($id == 2) {
                return redirect()->back()->with('error', __('You can not delete By default Company'));
            }

            $user = User::find($id);
            if ($user) {
                if (\Auth::user()->type == 'super admin') {

                    $transaction = ReferralTransaction::where('company_id' , $id)->delete();

                    $users = User::where('created_by', $id)->delete();
                    $employee = Employee::where('created_by', $id)->delete();

                    $user->delete();

                    return redirect()->back()->with('success', __('Company Successfully deleted'));
                }

                if (\Auth::user()->type == 'company') {
                    $this->assertUserBelongsToCurrentCompany($user);

                    $delete_user = User::where(['id' => $user->id])->first();
                    if ($delete_user) {
                        $employee = Employee::where(['user_id' => $user->id])->delete();
                        $delete_user->delete();

                        if ($delete_user || $employee) {
                            return redirect()->route('users.index')->with('success', __('User successfully deleted .'));
                        } else {
                            return redirect()->back()->with('error', __('Something is wrong.'));
                        }
                    } else {
                        return redirect()->back()->with('error', __('Something is wrong.'));
                    }
                }
                return redirect()->route('users.index')->with('success', __('User successfully deleted .'));
            } else {
                return redirect()->back()->with('error', __('Something is wrong.'));
            }
        } else {
            return redirect()->back();
        }
    }

    public function profile()
    {
        $userDetail = \Auth::user();
        $userDetail->customField = CustomField::getData($userDetail, 'user');
        $customFields = CustomField::where('created_by', '=', \Auth::user()->creatorId())->where('module', '=', 'user')->get();
        $totpService = new TotpService();

        if ($userDetail->two_factor_enabled && !empty($userDetail->two_factor_secret)) {
            $twoFactorSecret = decrypt($userDetail->two_factor_secret);
        } else {
            $twoFactorSecret = session('two_factor_setup_secret');
            if (empty($twoFactorSecret)) {
                $twoFactorSecret = $totpService->generateSecretKey();
                session(['two_factor_setup_secret' => $twoFactorSecret]);
            }
        }

        $appName = config('app.name', 'ERP-SaaS');
        $otpAuthUrl = $totpService->getQRCodeUrl($appName, $userDetail->email, $twoFactorSecret);
        $twoFactorQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($otpAuthUrl);
        $plainRecoveryCodes = session('two_factor_recovery_codes_plain', []);

        return view('user.profile', compact('userDetail', 'customFields', 'twoFactorSecret', 'twoFactorQrUrl', 'plainRecoveryCodes'));
    }

    public function editprofile(Request $request)
    {
        $userDetail = \Auth::user();
        $user = User::findOrFail($userDetail['id']);

        $validator = \Validator::make(
            $request->all(), [
                'name' => 'required|max:120',
                'email' => 'required|email|unique:users,email,' . $userDetail['id'],
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        if ($request->hasFile('profile')) {
            $filenameWithExt = $request->file('profile')->getClientOriginalName();
            $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
            $extension = $request->file('profile')->getClientOriginalExtension();
            $fileNameToStore = $filename . '_' . time() . '.' . $extension;

            $settings = Utility::getStorageSetting();
            if ($settings['storage_setting'] == 'local') {
                $dir = 'uploads/avatar/';
            } else {
                $dir = 'uploads/avatar';
            }

            $image_path = $dir . $userDetail['avatar'];

            if (File::exists($image_path)) {
                File::delete($image_path);
            }

            $url = '';
            $path = Utility::upload_file($request, 'profile', $fileNameToStore, $dir, []);
            if ($path['flag'] == 1) {
                $url = $path['url'];
            } else {
                return redirect()->route('profile', \Auth::user()->id)->with('error', __($path['msg']));
            }
        }

        if (!empty($request->profile)) {
            $user['avatar'] = $fileNameToStore;
        }
        $user['name'] = $request['name'];
        $user['email'] = $request['email'];
        $user->save();
        CustomField::saveData($user, $request->customField);

        return redirect()->route('profile', $user)->with(
            'success', 'Profile successfully updated.'
        );
    }

    public function updatePassword(Request $request)
    {

        if (Auth::Check()) {

            $validator = \Validator::make(
                $request->all(), [
                    'old_password' => 'required',
                    'password' => 'required|min:6',
                    'password_confirmation' => 'required|same:password',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $objUser = Auth::user();
            $request_data = $request->All();
            $current_password = $objUser->password;
            if (Hash::check($request_data['old_password'], $current_password)) {
                $user_id = Auth::User()->id;
                $obj_user = User::find($user_id);
                $obj_user->password = Hash::make($request_data['password']);
                $obj_user->save();

                return redirect()->route('profile', $objUser->id)->with('success', __('Password successfully updated.'));
            } else {
                return redirect()->route('profile', $objUser->id)->with('error', __('Please enter correct current password.'));
            }
        } else {
            return redirect()->route('profile', \Auth::user()->id)->with('error', __('Something is wrong.'));
        }
    }

    public function enableTwoFactor(Request $request)
    {
        $request->validate([
            'otp' => 'required|digits:6',
        ]);

        $user = Auth::user();
        $secret = session('two_factor_setup_secret');
        if (empty($secret)) {
            return redirect()->route('profile')->with('error', __('Two-factor setup expired. Please try again.'));
        }

        $totpService = new TotpService();
        if (!$totpService->verifyKey($secret, $request->otp)) {
            return redirect()->route('profile')->with('error', __('Invalid authentication code.'));
        }

        $recoveryCodes = $this->generatePlainRecoveryCodes();
        $user->two_factor_enabled = true;
        $user->two_factor_secret = encrypt($secret);
        $user->two_factor_recovery_codes = json_encode(array_map(fn ($code) => Hash::make($code), $recoveryCodes));
        $user->save();

        session()->forget('two_factor_setup_secret');
        session()->flash('two_factor_recovery_codes_plain', $recoveryCodes);

        return redirect()->route('profile')->with('success', __('Two-factor authentication enabled successfully.'));
    }

    public function disableTwoFactor(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = Auth::user();
        if (!Hash::check($request->password, $user->password)) {
            return redirect()->route('profile')->with('error', __('Please enter correct current password.'));
        }

        $user->two_factor_enabled = false;
        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->save();

        session()->forget('two_factor_setup_secret');

        return redirect()->route('profile')->with('success', __('Two-factor authentication disabled successfully.'));
    }

    public function regenerateTwoFactorRecoveryCodes(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = Auth::user();
        if (!$user->two_factor_enabled || empty($user->two_factor_secret)) {
            return redirect()->route('profile')->with('error', __('Two-factor authentication is not enabled.'));
        }

        if (!Hash::check($request->password, $user->password)) {
            return redirect()->route('profile')->with('error', __('Please enter correct current password.'));
        }

        $recoveryCodes = $this->generatePlainRecoveryCodes();
        $user->two_factor_recovery_codes = json_encode(array_map(fn ($code) => Hash::make($code), $recoveryCodes));
        $user->save();

        session()->flash('two_factor_recovery_codes_plain', $recoveryCodes);

        return redirect()->route('profile')->with('success', __('Recovery codes regenerated successfully.'));
    }

    private function generatePlainRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = Str::upper(Str::random(4) . '-' . Str::random(4));
        }

        return $codes;
    }
    // User To do module
    public function todo_store(Request $request)
    {
        $request->validate(
            ['title' => 'required|max:120']
        );

        $post = $request->all();
        $post['user_id'] = Auth::user()->id;
        $todo = UserToDo::create($post);

        $todo->updateUrl = route(
            'todo.update', [
                $todo->id,
            ]
        );
        $todo->deleteUrl = route(
            'todo.destroy', [
                $todo->id,
            ]
        );

        return $todo->toJson();
    }

    public function todo_update($todo_id)
    {
        $user_todo = UserToDo::find($todo_id);
        if ($user_todo->is_complete == 0) {
            $user_todo->is_complete = 1;
        } else {
            $user_todo->is_complete = 0;
        }
        $user_todo->save();
        return $user_todo->toJson();
    }

    public function todo_destroy($id)
    {
        $todo = UserToDo::find($id);
        $todo->delete();

        return true;
    }

    // change mode 'dark or light'
    public function setBranchContext(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
        ]);
        $user = \Auth::user();
        if (! $user->canAccessBranchId((int) $request->branch_id)) {
            return redirect()->back()->with('error', __('You do not have access to this branch.'));
        }
        session(['active_branch_id' => (int) $request->branch_id]);

        return redirect()->back();
    }

    public function setCompanyContext(Request $request)
    {
        $request->validate([
            'company_id' => 'required|integer|exists:users,id',
        ]);
        $user = \Auth::user();
        $companyId = (int) $request->company_id;

        if (! $user->canAccessCompany($companyId)) {
            Log::warning('company_context_switch_denied', ['user_id' => $user->id, 'company_id' => $companyId]);
            return redirect()->back()->with('error', __('You do not have access to this company.'));
        }

        $user->setActiveCompany($companyId);
        session()->forget('active_branch_id');
        if ((int) ($user->default_company_id ?? 0) !== $companyId) {
            $user->default_company_id = $companyId;
            $user->save();
        }
        if (Schema::hasTable('company_user')) {
            DB::table('company_user')
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->update(['is_default' => false, 'updated_at' => now()]);
            DB::table('company_user')
                ->where('user_id', $user->id)
                ->where('company_id', $companyId)
                ->update(['is_default' => true, 'updated_at' => now()]);
        }

        Log::info('company_context_switched', ['user_id' => $user->id, 'company_id' => $companyId]);

        return redirect()->back()->with('success', __('Active company updated.'));
    }

    public function updateUserCompanyMemberships(Request $request, $id)
    {
        if (! \Auth::user()->can('edit user')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if (! Schema::hasTable('company_user')) {
            return redirect()->back()->with('error', __('Company memberships are not available yet.'));
        }

        $request->validate([
            'company_ids' => 'required|array|min:1',
            'company_ids.*' => 'integer|exists:users,id',
            'default_company_id' => 'required|integer|exists:users,id',
        ]);

        $actor = \Auth::user();
        $user = User::findOrFail($id);
        if ($actor->type !== 'super admin' && (int) $user->created_by !== (int) $actor->creatorId()) {
            return redirect()->back()->with('error', __('You do not have permission to update this user.'));
        }

        $allowedCompanyIds = $actor->type === 'super admin'
            ? User::where('type', 'company')->pluck('id')->map(fn ($cid) => (int) $cid)->all()
            : User::where('type', 'company')->where('id', $actor->creatorId())->pluck('id')->map(fn ($cid) => (int) $cid)->all();

        $companyIds = array_values(array_unique(array_map('intval', $request->input('company_ids', []))));
        $companyIds = array_values(array_intersect($companyIds, $allowedCompanyIds));
        if (empty($companyIds)) {
            return redirect()->back()->with('error', __('No valid company selected.'));
        }

        $defaultCompanyId = (int) $request->input('default_company_id');
        if (! in_array($defaultCompanyId, $companyIds, true)) {
            $defaultCompanyId = (int) $companyIds[0];
        }

        DB::table('company_user')->where('user_id', $user->id)->delete();
        $this->syncCompanyMembership($user, $companyIds, $defaultCompanyId);
        $user->created_by = $defaultCompanyId;
        $user->default_company_id = $defaultCompanyId;
        $user->save();
        Log::info('user_company_memberships_updated', ['actor_id' => $actor->id, 'user_id' => $user->id, 'default_company_id' => $defaultCompanyId]);

        return redirect()->back()->with('success', __('User company memberships updated.'));
    }

    public function changeMode()
    {
        $usr = \Auth::user();
        if ($usr->mode == 'light') {
            $usr->mode = 'dark';
            $usr->dark_mode = 1;
        } else {
            $usr->mode = 'light';
            $usr->dark_mode = 0;
        }
        $usr->save();

        return redirect()->back();
    }

    public function upgradePlan($user_id)
    {
        $user = User::find($user_id);
        $plans = Plan::get();
        $admin_payment_setting = Utility::getAdminPaymentSetting();

        return view('user.plan', compact('user', 'plans', 'admin_payment_setting'));
    }
    public function activePlan($user_id, $plan_id)
    {

        $plan = Plan::find($plan_id);
        if($plan->is_disable == 0)
        {
            return redirect()->back()->with('error', __('You are unable to upgrade this plan because it is disabled.'));
        }

        $user = User::find($user_id);
        $assignPlan = $user->assignPlan($plan_id, $user_id);
        if ($assignPlan['is_success'] == true && !empty($plan)) {
            $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
            Order::create(
                [
                    'order_id' => $orderID,
                    'name' => null,
                    'card_number' => null,
                    'card_exp_month' => null,
                    'card_exp_year' => null,
                    'plan_name' => $plan->name,
                    'plan_id' => $plan->id,
                    'price' => $plan->price,
                    'price_currency' => isset(\Auth::user()->planPrice()['currency'])?\Auth::user()->planPrice()['currency'] : '',
                    'txn_id' => '',
                    'payment_status' => 'success',
                    'receipt' => null,
                    'user_id' => $user->id,
                ]
            );

            return redirect()->back()->with('success', 'Plan successfully upgraded.');
        } else {
            return redirect()->back()->with('error', 'Plan fail to upgrade.');
        }

    }

    public function userPassword($id)
    {
        $eId = \Crypt::decrypt($id);
        $user = User::find($eId);

        return view('user.reset', compact('user'));

    }

    public function userPasswordReset(Request $request, $id)
    {
        $validator = \Validator::make(
            $request->all(), [
                'password' => 'required|confirmed|same:password_confirmation',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();

            return redirect()->back()->with('error', $messages->first());
        }

        $user = User::where('id', $id)->first();
        $user->forceFill([
            'password' => Hash::make($request->password),
            'is_enable_login' => 1,
        ])->save();

        if(\Auth::user()->type == 'super admin')
        {
        return redirect()->route('users.index')->with(
            'success', 'Company Password successfully updated.'
        );
    }
    else
    {
        return redirect()->route('users.index')->with(
            'success', 'User Password successfully updated.'
        );
    }

    }

    //start for user login details
    public function userLog(Request $request)
    {
        $filteruser = User::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
        $filteruser->prepend('Select User', '');

        $query = DB::table('login_details')
            ->join('users', 'login_details.user_id', '=', 'users.id')
            ->select(DB::raw('login_details.*, users.id as user_id , users.name as user_name , users.email as user_email ,users.type as user_type'))
            ->where(['login_details.created_by' => \Auth::user()->id]);

        if (!empty($request->month)) {
            $query->whereMonth('date', date('m', strtotime($request->month)));
            $query->whereYear('date', date('Y', strtotime($request->month)));
        } else {
            $query->whereMonth('date', date('m'));
            $query->whereYear('date', date('Y'));
        }

        if (!empty($request->users)) {
            $query->where('user_id', '=', $request->users);
        }
        $userdetails = $query->get();
        $last_login_details = LoginDetail::where('created_by', \Auth::user()->creatorId())->get();

        return view('user.userlog', compact('userdetails', 'last_login_details', 'filteruser'));
    }

    public function userLogView($id)
    {
        $users = LoginDetail::find($id);

        return view('user.userlogview', compact('users'));
    }

    public function userLogDestroy($id)
    {
        $users = LoginDetail::where('user_id', $id)->delete();
        return redirect()->back()->with('success', 'User successfully deleted.');
    }

    public function LoginWithCompany(Request $request, User $user, $id)
    {
        $user = User::find($id);
        if ($user && auth()->check()) {
            Impersonate::take($request->user(), $user);
            return redirect('/account-dashboard');
        }
    }

    public function ExitCompany(Request $request)
    {
        \Auth::user()->leaveImpersonation($request->user());
        return redirect('/dashboard');
    }

    public function companyInfo(Request $request, $id)
    {
        $user = User::find($request->id);
        $status = $user->delete_status;
        $userData = User::where('created_by', $id)->where('type', '!=', 'client')->selectRaw('COUNT(*) as total_users, SUM(CASE WHEN is_disable = 0 THEN 1 ELSE 0 END) as disable_users, SUM(CASE WHEN is_disable = 1 THEN 1 ELSE 0 END) as active_users')->first();

        return view('user.company_info', compact('userData', 'id', 'status'));
    }

    public function userUnable(Request $request)
    {
        User::where('id', $request->id)->update(['is_disable' => $request->is_disable]);
        $userData = User::where('created_by', $request->company_id)->where('type', '!=', 'client')->selectRaw('COUNT(*) as total_users, SUM(CASE WHEN is_disable = 0 THEN 1 ELSE 0 END) as disable_users, SUM(CASE WHEN is_disable = 1 THEN 1 ELSE 0 END) as active_users')->first();

        if ($request->is_disable == 1) {

            return response()->json(['success' => __('User successfully unable.'), 'userData' => $userData]);

        } else {
            return response()->json(['success' => __('User successfully disable.'), 'userData' => $userData]);
        }
    }

    public function LoginManage($id)
    {
        $eId = \Crypt::decrypt($id);
        $user = User::find($eId);
        $authUser = \Auth::user();

        if (! $user) {
            return redirect()->back()->with('error', __('User not found.'));
        }

        if ($authUser->type !== 'super admin' && Schema::hasTable('company_user')) {
            $companyId = (int) $authUser->creatorId();
            $membership = DB::table('company_user')
                ->where('user_id', $user->id)
                ->where('company_id', $companyId)
                ->first();

            if (! $membership) {
                return redirect()->back()->with('error', __('User is not linked to your company.'));
            }

            $nextStatus = ((string) $membership->status === 'active') ? 'inactive' : 'active';
            DB::table('company_user')
                ->where('user_id', $user->id)
                ->where('company_id', $companyId)
                ->update([
                    'status' => $nextStatus,
                    'is_default' => $nextStatus === 'active' ? (bool) $membership->is_default : false,
                    'updated_at' => now(),
                ]);

            if ($nextStatus === 'inactive' && (int) ($user->default_company_id ?? 0) === $companyId) {
                $nextDefault = DB::table('company_user')
                    ->join('users as companies', 'companies.id', '=', 'company_user.company_id')
                    ->where('company_user.user_id', $user->id)
                    ->where('company_user.status', 'active')
                    ->where('companies.type', 'company')
                    ->where('companies.is_enable_login', 1)
                    ->where('companies.is_active', 1)
                    ->where('companies.delete_status', 1)
                    ->orderByDesc('company_user.is_default')
                    ->orderBy('company_user.id')
                    ->value('company_user.company_id');
                $user->default_company_id = $nextDefault ? (int) $nextDefault : null;
            }

            $hasAnyActiveMembership = DB::table('company_user')
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();
            $user->is_enable_login = $hasAnyActiveMembership ? 1 : 0;
            $user->save();

            return redirect()->back()->with(
                'success',
                $nextStatus === 'active' ? __('User login enable successfully.') : __('User login disable successfully.')
            );
        }

        if ($user->is_enable_login == 1) {
            $user->is_enable_login = 0;
            $user->save();

            if($authUser->type == 'super admin')
            {
                return redirect()->back()->with('success', __('Company login disable successfully.'));
            }
            else
            {
                return redirect()->back()->with('success', __('User login disable successfully.'));
            }
        } else {
            $user->is_enable_login = 1;
            $user->save();
            if($authUser->type == 'super admin')
            {
                return redirect()->back()->with('success', __('Company login enable successfully.'));
            }
            else
            {
                return redirect()->back()->with('success', __('User login enable successfully.'));
            }
        }
    }

    public function checkUserExists(Request $request)
    {
        if (! \Auth::user()->can('create user')) {
            return response()->json(['exists' => false, 'allowed' => false], 403);
        }

        $request->validate([
            'email' => 'required|email',
        ]);

        $email = trim((string) $request->email);
        $user = User::where('email', $email)->first();
        if (! $user) {
            return response()->json([
                'exists' => false,
                'allowed' => true,
                'message' => '',
            ]);
        }

        if (in_array($user->type, ['super admin', 'client'], true)) {
            return response()->json([
                'exists' => true,
                'allowed' => false,
                'message' => __('This email belongs to a super admin/client account and cannot be linked here.'),
            ]);
        }

        return response()->json([
            'exists' => true,
            'allowed' => true,
            'message' => __('User already exists. This user will be linked to your company, and password will remain unchanged.'),
            'type' => $user->type,
        ]);
    }

    private function syncCompanyMembership(User $user, array $companyIds, ?int $defaultCompanyId = null): void
    {
        if (! Schema::hasTable('company_user')) {
            return;
        }

        $companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds), fn ($id) => $id > 0)));
        if (empty($companyIds)) {
            return;
        }

        $validCompanyIds = User::whereIn('id', $companyIds)
            ->where('type', 'company')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if (empty($validCompanyIds)) {
            return;
        }

        $default = $defaultCompanyId && in_array((int) $defaultCompanyId, $validCompanyIds, true)
            ? (int) $defaultCompanyId
            : (int) $validCompanyIds[0];

        foreach ($validCompanyIds as $companyId) {
            DB::table('company_user')->updateOrInsert(
                ['company_id' => $companyId, 'user_id' => (int) $user->id],
                [
                    'role' => null,
                    'status' => 'active',
                    'is_default' => $companyId === $default,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        if ((int) ($user->default_company_id ?? 0) !== $default) {
            $user->default_company_id = $default;
            $user->save();
        }
    }

    private function assertUserBelongsToCurrentCompany(User $user): void
    {
        $actor = \Auth::user();
        if ($actor->type === 'super admin') {
            return;
        }
        $companyId = (int) $actor->creatorId();
        $belongs = (int) $user->created_by === $companyId;
        if (! $belongs && Schema::hasTable('company_user')) {
            $belongs = DB::table('company_user')
                ->where('user_id', $user->id)
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->exists();
        }
        if (! $belongs) {
            abort(403, __('You do not have permission to access this user.'));
        }
    }
}
