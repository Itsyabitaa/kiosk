<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /**
     * Display a paginated and filterable listing of the devices.
     */
    public function index(Request $request)
    {
        $status = $request->query('status');

        $query = Device::with('assignedPolicy');

        if ($status) {
            $query->where('enrollment_status', $status);
        }

        $devices = $query->paginate($request->query('per_page', 15));

        return response()->json($devices);
    }

    /**
     * Display the specified device details.
     */
    public function show($id)
    {
        $device = Device::with('assignedPolicy')->findOrFail($id);

        return response()->json([
            'device' => $device,
            'event_logs' => [], // stub event log for Sprint 2
        ]);
    }
}
