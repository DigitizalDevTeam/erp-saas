<?php

namespace Modules\LandingPage\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\LandingPage\Entities\LandingPageSetting;

class DiscoverController extends Controller
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
            $discover_of_features = json_decode($settings['discover_of_features'], true) ?? [];

            return view('landingpage::landingpage.discover.index', compact('settings', 'discover_of_features'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        
        return view('landingpage::landingpage.discover.create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        $data = [];
    
        /* ------------------ DISCOVER STATUS (PER LANGUAGE) ------------------ */
        // Get existing settings from DB
        $existingStatus = json_decode(LandingPageSetting::settings()['discover_status'] ?? '{}', true);
    
        $discoverStatus = $existingStatus;
    
        foreach ($request->discover_heading ?? [] as $lang => $val) {
            $discoverStatus[$lang] = isset($request->discover_statuss[$lang]) ? 'on' : 'off';
        }
    
        $data['discover_status'] = json_encode($discoverStatus);
    
        /* ------------------ TEXT FIELDS (MERGE MULTI-LANGUAGE) ------------------ */
        $fields = [
            'discover_heading',
            'discover_description',
            'discover_live_demo_link',
            'discover_buy_now_link',
        ];
    
        foreach ($fields as $field) {
            // Get existing values from DB
            $existingValues = json_decode(LandingPageSetting::settings()[$field] ?? '{}', true);
            $existingValues = $existingValues ?: [];
    
            // Merge only submitted languages
            foreach (($request->$field ?? []) as $lang => $value) {
                $existingValues[$lang] = $value;
            }
    
            $data[$field] = json_encode($existingValues);
        }
    
        /* ------------------ SAVE TO DB ------------------ */
        foreach ($data as $key => $value) {
            LandingPageSetting::updateOrCreate(
                ['name' => $key],
                ['value' => $value]
            );
        }
    
        return redirect()->back()->with(['success'=> 'Discover settings updated successfully']);
    }



    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('landingpage::landingpage.discover.show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        $languages = \App\Models\Utility::languages();
        return view('landingpage::landingpage.discover.edit',compact('languages'));
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




    public function discover_create(){
        $settings = LandingPageSetting::settings();
        $languages = \App\Models\Utility::languages();
        return view('landingpage::landingpage.discover.create',compact('languages'));
    }



    public function discover_store(Request $request){

        $settings = LandingPageSetting::settings();
        $data = json_decode($settings['discover_of_features'], true);

        if( $request->discover_logo){
            $discover_logo = time()."-discover_logo." . $request->discover_logo->getClientOriginalExtension();
            $dir        = 'uploads/landing_page_image';
            $path = LandingPageSetting::upload_file($request,'discover_logo',$discover_logo,$dir,[]);
            if($path['flag']==0){
                return redirect()->back()->with('error', __($path['msg']));
            }
            $datas['discover_logo'] = $discover_logo;
        }

        $datas['discover_heading']= $request->discover_heading;
        $datas['discover_description']= $request->discover_description;
        $datas['discover_language']= $request->discover_language;

        $data[] = $datas;
        $data = json_encode($data);
        LandingPageSetting::updateOrCreate(['name' =>  'discover_of_features'],['value' => $data]);

        return redirect()->back()->with(['success'=> 'Discover add successfully']);
    }



    public function discover_edit($key){
        $settings = LandingPageSetting::settings();
        $discovers = json_decode($settings['discover_of_features'], true);
        $discover = $discovers[$key];
        $languages = \App\Models\Utility::languages();
        return view('landingpage::landingpage.discover.edit', compact('discover','key','languages'));
    }



    public function discover_update(Request $request, $key){

        $settings = LandingPageSetting::settings();
        $data = json_decode($settings['discover_of_features'], true);

        if( $request->discover_logo){
            $discover_logo = time()."-discover_logo." . $request->discover_logo->getClientOriginalExtension();
            $dir        = 'uploads/landing_page_image';
            $path = LandingPageSetting::upload_file($request,'discover_logo',$discover_logo,$dir,[]);
            if($path['flag']==0){
                return redirect()->back()->with('error', __($path['msg']));
            }
            $data[$key]['discover_logo'] = $discover_logo;
        }

        $data[$key]['discover_heading'] = $request->discover_heading;
        $data[$key]['discover_description'] = $request->discover_description;
        $data[$key]['discover_language'] = $request->discover_language;

        $data = json_encode($data);
        LandingPageSetting::updateOrCreate(['name' =>  'discover_of_features'],['value' => $data]);

        return redirect()->back()->with(['success'=> 'Discover update successfully']);
    }



    public function discover_delete($key)
    {

        $settings = LandingPageSetting::settings();
        $pages = json_decode($settings['discover_of_features'], true);
        unset($pages[$key]);
        LandingPageSetting::updateOrCreate(['name' =>  'discover_of_features'],['value' => $pages]);
        return redirect()->back()->with(['success'=> 'Discover delete successfully']);
    }




}
