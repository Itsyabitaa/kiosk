<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceGroup;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FleetController extends Controller
{
    /**
     * Aggregate fleet stats: uptime %, offline count, and compliance breakdown.
     *
     * Compliance = a device's applied policy version matches the assigned policy version;
     * mismatches surface sync failures worth investigating.
     */
    public function stats(Request $request)
    {
        $orgId = auth('api')->user()->org_id;
        $offlineThreshold = Carbon::now()->subMinutes((int) env('FLEET_OFFLINE_THRESHOLD_MINUTES', 15));

        $total = Device::count();

        $offline = Device::where(function ($q) use ($offlineThreshold) {
            $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $offlineThreshold);
        })->count();

        $online = $total - $offline;
        $uptimePct = $total > 0 ? round(($online / $total) * 100, 2) : 0.0;

        // Compliance: join assignments to their assigned policy version (org-scoped via devices).
        $complianceRows = DB::table('policy_assignments')
            ->join('devices', 'devices.id', '=', 'policy_assignments.device_id')
            ->join('policies', 'policies.id', '=', 'policy_assignments.policy_id')
            ->where('devices.org_id', $orgId)
            ->select(
                'policy_assignments.applied_version as applied_version',
                'policies.version as assigned_version'
            )
            ->get();

        $compliant = 0;
        $nonCompliant = 0;
        $unknown = 0;
        foreach ($complianceRows as $row) {
            if ($row->applied_version === null) {
                $unknown++;
            } elseif ((int) $row->applied_version === (int) $row->assigned_version) {
                $compliant++;
            } else {
                $nonCompliant++;
            }
        }

        return response()->json([
            'total_devices' => $total,
            'online' => $online,
            'offline' => $offline,
            'uptime_pct' => $uptimePct,
            'compliance' => [
                'compliant' => $compliant,
                'non_compliant' => $nonCompliant,
                'unknown' => $unknown,
                'assigned_total' => $complianceRows->count(),
            ],
            'open_alerts' => Alert::where('status', Alert::STATUS_OPEN)->count(),
        ]);
    }

    /**
     * Merged alert feed: tamper alerts (promoted from device events) + fleet-health alerts,
     * filterable by type/status, newest first.
     */
    public function alerts(Request $request)
    {
        $query = Alert::query()->orderByDesc('created_at');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->paginate($request->query('per_page', 25)));
    }

    public function ackAlert($id)
    {
        $alert = Alert::findOrFail($id);
        $alert->update(['status' => Alert::STATUS_ACKNOWLEDGED]);

        return response()->json($alert);
    }

    /**
     * Export a compliance report for a device group as CSV (default) or PDF. Useful for
     * customers with audit/compliance requirements.
     */
    public function complianceReport(Request $request, $id)
    {
        $group = DeviceGroup::findOrFail($id);
        $format = $request->query('format', 'csv');

        $rows = $this->buildComplianceRows($group);

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('reports.compliance', [
                'group' => $group,
                'rows' => $rows,
                'generatedAt' => Carbon::now()->toDayDateTimeString(),
            ]);

            return $pdf->download("compliance-group-{$group->id}.pdf");
        }

        return $this->streamCsv($group, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildComplianceRows(DeviceGroup $group): array
    {
        $devices = $group->devices()
            ->with(['assignedPolicy', 'policyAssignment'])
            ->get();

        return $devices->map(function (Device $device) {
            $assignment = $device->policyAssignment;
            $policy = $device->assignedPolicy;

            $applied = $assignment?->applied_version;
            $assigned = $policy?->version;

            if ($assigned === null) {
                $compliance = 'no_policy';
            } elseif ($applied === null) {
                $compliance = 'unknown';
            } elseif ((int) $applied === (int) $assigned) {
                $compliance = 'compliant';
            } else {
                $compliance = 'non_compliant';
            }

            return [
                'device_uid' => $device->device_uid,
                'platform' => $device->platform,
                'enrollment_status' => $device->enrollment_status,
                'last_seen_at' => optional($device->last_seen_at)->toIso8601String(),
                'policy_name' => $policy?->name,
                'assigned_version' => $assigned,
                'applied_version' => $applied,
                'compliance' => $compliance,
            ];
        })->all();
    }

    private function streamCsv(DeviceGroup $group, array $rows): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"compliance-group-{$group->id}.csv\"",
        ];

        $columns = ['device_uid', 'platform', 'enrollment_status', 'last_seen_at', 'policy_name', 'assigned_version', 'applied_version', 'compliance'];

        return response()->stream(function () use ($rows, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($c) => $row[$c] ?? '', $columns));
            }
            fclose($out);
        }, 200, $headers);
    }
}

