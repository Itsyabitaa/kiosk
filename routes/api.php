<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeviceEnrollmentController;

Route::group([
    'prefix' => 'admin/auth'
], function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('auth:api');
    Route::get('me', [AuthController::class, 'me'])->middleware('auth:api');
});

Route::post('/enroll', [DeviceEnrollmentController::class, 'enroll']);

Route::get('/admin/devices', [\App\Http\Controllers\DeviceController::class, 'index'])->middleware('auth:api');
Route::get('/admin/devices/{id}', [\App\Http\Controllers\DeviceController::class, 'show'])->middleware('auth:api');
Route::post('/admin/devices/{id}/unlock', [\App\Http\Controllers\DeviceController::class, 'unlock'])->middleware('auth:api');

Route::get('/admin/policies', [\App\Http\Controllers\PolicyController::class, 'index'])->middleware('auth:api');
Route::post('/admin/policies', [\App\Http\Controllers\PolicyController::class, 'store'])->middleware('auth:api');
Route::put('/admin/policies/{id}', [\App\Http\Controllers\PolicyController::class, 'update'])->middleware('auth:api');
Route::patch('/admin/policies/{id}/publish', [\App\Http\Controllers\PolicyController::class, 'publish'])->middleware('auth:api');
Route::post('/admin/policies/{id}/assign', [\App\Http\Controllers\PolicyController::class, 'assign'])->middleware('auth:api');

Route::get('/devices/{id}/policy', [DeviceEnrollmentController::class, 'getPolicy']);
Route::post('/devices/{id}/policy-ack', [DeviceEnrollmentController::class, 'ackPolicy']);
Route::post('/devices/{id}/events', [DeviceEnrollmentController::class, 'logEvent']);
Route::post('/devices/{id}/unlock', [DeviceEnrollmentController::class, 'deviceUnlock']);
Route::get('/devices/{id}/mdm/profile', [DeviceEnrollmentController::class, 'generateMdmProfile']);
Route::get('/devices/{id}/mdm/commands', [DeviceEnrollmentController::class, 'getMdmCommands']);
Route::post('/devices/{id}/mdm/commands/{commandId}/ack', [DeviceEnrollmentController::class, 'ackMdmCommand']);

Route::post('/admin/devices/deployment-qr', [\App\Http\Controllers\DeviceDeploymentController::class, 'generateDeploymentQr'])->middleware('auth:api');
Route::get('/devices/enroll-ios', [\App\Http\Controllers\DeviceDeploymentController::class, 'enrollIosDeepLink']);
Route::post('/devices/{id}/reassign', [\App\Http\Controllers\DeviceDeploymentController::class, 'reassignPolicy']);

/*
|--------------------------------------------------------------------------
| Sprint 10 — Fleet management (device groups, tags, real-time commands,
| staged rollouts, telemetry, analytics, alerts, exports)
|--------------------------------------------------------------------------
*/

// Device grouping + tags
Route::middleware('auth:api')->group(function () {
    Route::get('/admin/groups', [\App\Http\Controllers\DeviceGroupController::class, 'index']);
    Route::post('/admin/groups', [\App\Http\Controllers\DeviceGroupController::class, 'store']);
    Route::get('/admin/groups/{id}', [\App\Http\Controllers\DeviceGroupController::class, 'show']);
    Route::post('/admin/groups/{id}/members', [\App\Http\Controllers\DeviceGroupController::class, 'addMembers']);
    Route::delete('/admin/groups/{id}/members', [\App\Http\Controllers\DeviceGroupController::class, 'removeMembers']);
    Route::post('/admin/groups/{id}/assign-policy', [\App\Http\Controllers\DeviceGroupController::class, 'assignPolicy']);

    Route::post('/admin/devices/{id}/tags', [\App\Http\Controllers\DeviceController::class, 'addTag']);
    Route::delete('/admin/devices/{id}/tags/{tag}', [\App\Http\Controllers\DeviceController::class, 'removeTag']);

    // Real-time remote commands
    Route::post('/admin/devices/{id}/commands', [\App\Http\Controllers\DeviceCommandController::class, 'issueToDevice']);
    Route::post('/admin/groups/{id}/commands', [\App\Http\Controllers\DeviceCommandController::class, 'issueToGroup']);
});

// Broadcasting auth (JWT-based, supports both device and admin tokens)
Route::post('/broadcasting/auth', [\App\Http\Controllers\BroadcastAuthController::class, 'authenticate']);

