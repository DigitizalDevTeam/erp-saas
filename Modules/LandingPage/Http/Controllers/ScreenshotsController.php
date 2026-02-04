<?php

namespace Modules\LandingPage\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\LandingPage\Entities\LandingPageSetting;

class ScreenshotsController extends Controller
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
            $screenshots = json_decode($settings['screenshots'], true) ?? [];
            return view('landingpage::landingpage.screenshots.index',compact('settings','screenshots'));
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
        return view('landingpage::landingpage.screenshots.create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        $data = [];
    
        /* ------------------ SCREENSHOTS STATUS (PER LANGUAGE) ------------------ */
        $existingStatus = LandingPageSetting::where('name', 'screenshots_status')->first();
        $currentStatus = $existingStatus ? json_decode($existingStatus->value, true) : [];
        if (!is_array($currentStatus)) {
            $currentStatus = [];
        }
    
        foreach ($request->screenshots_heading ?? [] as $lang => $val) {
            // If checkbox for this language is set, mark 'on', else 'off'
            $currentStatus[$lang] = isset($request->screenshots_status[$lang]) ? 'on' : 'off';
        }
    
        $data['screenshots_status'] = json_encode($currentStatus);
    
        /* ------------------ TEXT FIELDS (MULTI-LANGUAGE) ------------------ */
        $fields = [
            'screenshots_heading',
            'screenshots_description',
        ];
    
        foreach ($fields as $field) {
            $existing = LandingPageSetting::where('name', $field)->first();
            $currentValues = $existing ? json_decode($existing->value, true) : [];
            if (!is_array($currentValues)) {
                $currentValues = [];
            }
    
            $newValues = $request->$field ?? [];
            if (!is_array($newValues)) {
                $newValues = [];
            }
    
            // Merge new values per language, keep existing others
            $mergedValues = array_merge($currentValues, $newValues);
    
            $data[$field] = json_encode($mergedValues);
        }
    
        /* ------------------ SAVE TO DB ------------------ */
        foreach ($data as $key => $value) {
            LandingPageSetting::updateOrCreate(
                ['name' => $key],
                ['value' => $value]
            );
        }
    
        return redirect()->back()->with(['success' => 'Screenshots settings updated successfully']);
    }




    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('landingpage::landingpage.screenshots.show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('landingpage::landingpage.screenshots.edit');
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






    public function screenshots_create(){
        $settings = LandingPageSetting::settings();
        $languages = Utility::languages();
        return view('landingpage::landingpage.screenshots.create',compact('languages'));
    }



    public function screenshots_store(Request $request){

        $settings = LandingPageSetting::settings();
        $data = json_decode($settings['screenshots'], true);

        if( $request->screenshots){
            $screenshots = time()."-screenshots." . $request->screenshots->getClientOriginalExtension();
            $dir        = 'uploads/landing_page_image';
            $path = LandingPageSetting::upload_file($request,'screenshots',$screenshots,$dir,[]);
            if($path['flag']==0){
                return redirect()->back()->with('error', __($path['msg']));
            }
            $datas['screenshots'] = $screenshots;
        }

        $datas['screenshots_heading']= $request->screenshots_heading;
        $datas['screenshots_language']= $request->screenshots_language;

        $data[] = $datas;
        $data = json_encode($data);
        LandingPageSetting::updateOrCreate(['name' =>  'screenshots'],['value' => $data]);

        return redirect()->back()->with(['success'=> 'screenshot add successfully']);
    }



    public function screenshots_edit($key){
        $settings = LandingPageSetting::settings();
        $screenshots = json_decode($settings['screenshots'], true);
        $screenshot = $screenshots[$key];
        $languages = Utility::languages();
        return view('landingpage::landingpage.screenshots.edit', compact('screenshot','key','languages'));
    }



    public function screenshots_update(Request $request, $key){

        $settings = LandingPageSetting::settings();
        $data = json_decode($settings['screenshots'], true);

        if( $request->screenshots){
            $screenshots = time()."-screenshots." . $request->screenshots->getClientOriginalExtension();
            $dir        = 'uploads/landing_page_image';
            $path = LandingPageSetting::upload_file($request,'screenshots',$screenshots,$dir,[]);
            if($path['flag']==0){
                return redirect()->back()->with('error', __($path['msg']));
            }
            $data[$key]['screenshots'] = $screenshots;
        }

        $data[$key]['screenshots_heading'] = $request->screenshots_heading;
        $data[$key]['screenshots_language'] = $request->screenshots_language;


        $data = json_encode($data);
        LandingPageSetting::updateOrCreate(['name' =>  'screenshots'],['value' => $data]);

        return redirect()->back()->with(['success'=> 'screenshot update successfully']);
    }



    public function screenshots_delete($key){
        $settings = LandingPageSetting::settings();
        $pages = json_decode($settings['screenshots'], true);
        unset($pages[$key]);
        LandingPageSetting::updateOrCreate(['name' =>  'screenshots'],['value' => $pages]);

        return redirect()->back()->with(['success'=> 'Screenshot delete successfully']);
    }


}
