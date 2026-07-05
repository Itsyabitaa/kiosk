<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /**
     * Display a listing of the devices for the authenticated organization.
     */
    public function index()
    {
        return response()->json(Device::all());
    }
}
