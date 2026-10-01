<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\PosPrinterAgentApiController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
Route::post('login', [ApiController::class, 'login']);

// Printer agent — no login; agent connects with ERP Base URL + branch only.
Route::get('printer-agent/branches', [PosPrinterAgentApiController::class, 'branches']);
Route::post('printer-agent/heartbeat', [PosPrinterAgentApiController::class, 'heartbeat']);
Route::get('printer-agent/logo', [PosPrinterAgentApiController::class, 'logo']);
Route::post('printer-agent/printers', [PosPrinterAgentApiController::class, 'syncPrinters']);
Route::get('printer-agent/jobs', [PosPrinterAgentApiController::class, 'jobs']);
Route::post('printer-agent/jobs/{id}/ack', [PosPrinterAgentApiController::class, 'ack']);

Route::group(['middleware' => ['auth:sanctum']], function () {

    Route::post('logout', [ApiController::class, 'logout']);
    Route::get('get-projects', [ApiController::class, 'getProjects']);
    Route::post('add-tracker', [ApiController::class, 'addTracker']);
    Route::post('stop-tracker', [ApiController::class, 'stopTracker']);
    Route::post('upload-photos', [ApiController::class, 'uploadImage']);
});
