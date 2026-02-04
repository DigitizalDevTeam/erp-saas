<?php

namespace Modules\LandingPage\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\LandingPage\Entities\LandingPageSetting;

class HomeController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        if(\Auth::user()->type == 'super admin')
        {
            $settings = LandingPageSetting::landingPageSetting();
            return view('landingpage::landingpage.homesection', compact('settings'));
        }
        else{
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('landingpage::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
   public function store(Request $request)
{
    $data = [];
    $settings = LandingPageSetting::settings();

    /* ------------------ HOME BANNER (MERGE) ------------------ */
    $existingBanner = json_decode($settings['home_banner'] ?? '{}', true);

    if ($request->hasFile('home_banner')) {
        foreach ($request->file('home_banner') as $lang => $file) {

            if ($file) {
                $file_name = 'home_banner_' . $lang . '.' . $file->getClientOriginalExtension();
                $dir = 'uploads/landing_page_image';

                $path = LandingPageSetting::upload_file($file, $file_name, $dir, []);

                if ($path['flag'] == 0) {
                    return back()->with('error', __($path['msg']));
                }

                $existingBanner[$lang] = $file_name; // merge
            }
        }
    }
    $data['home_banner'] = json_encode($existingBanner);

    /* ------------------ HOME LOGO (MERGE) ------------------ */
    $existingLogos = json_decode($settings['home_logo'] ?? '{}', true);
    $savedlogos = $request->savedlogo ?? [];

    foreach ($savedlogos as $lang => $logos) {
        $temp_logo = array_filter(explode(",", $logos));
        $existingLogos[$lang] = $temp_logo;
    }

    if ($request->home_logo) {
        foreach ($request->home_logo as $lang => $files) {
            foreach ($files as $fileArr) {

                $file = $fileArr['file'] ?? null;
                if ($file) {
                    $file_name = md5(time()) . "_" . $file->getClientOriginalName();
                    $dir = 'uploads/landing_page_image/';

                    $path = LandingPageSetting::upload_file($file, $file_name, $dir, []);

                    if ($path['flag'] == 1) {
                        $existingLogos[$lang][] = $path['url'];
                    } else {
                        return back()->with('error', __($path['msg']));
                    }
                }
            }
        }
    }

    $data['home_logo'] = json_encode($existingLogos);

    /* ------------------ TEXT FIELDS (MERGE) ------------------ */
    $fields = [
        'home_offer_text',
        'home_title',
        'home_heading',
        'home_description',
        'home_trusted_by',
        'home_live_demo_link',
        'home_buy_now_link',
    ];

    foreach ($fields as $field) {
        $old = json_decode($settings[$field] ?? '{}', true);
        $new = $request->$field ?? [];

        foreach ($new as $lang => $value) {
            $old[$lang] = $value; // merge per language
        }

        $data[$field] = json_encode($old);
    }

    $data['home_status'] = 'on';

    /* ------------------ SAVE ------------------ */
    foreach ($data as $key => $value) {
        LandingPageSetting::updateOrCreate(
            ['name' => $key],
            ['value' => $value]
        );
    }

    return back()->with('success', 'Settings updated successfully');
}




    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('landingpage::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('landingpage::edit');
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        //
    }
}
