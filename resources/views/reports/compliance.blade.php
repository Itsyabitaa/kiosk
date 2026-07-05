<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Compliance Report - {{ $group->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .meta { color: #666; margin-bottom: 16px; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 5px 6px; text-align: left; }
        th { background: #f2f2f2; }
        .compliant { color: #157347; font-weight: bold; }
        .non_compliant { color: #b02a37; font-weight: bold; }
        .unknown, .no_policy { color: #997404; }
    </style>
</head>
<body>
    <h1>Compliance Report</h1>
    <div class="meta">
        Group: {{ $group->name }} (#{{ $group->id }})<br>
        Generated: {{ $generatedAt }}<br>
        Devices: {{ count($rows) }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Device UID</th>
                <th>Platform</th>
                <th>Status</th>
                <th>Last Seen</th>
                <th>Policy</th>
                <th>Assigned v</th>
                <th>Applied v</th>
                <th>Compliance</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['device_uid'] }}</td>
                    <td>{{ $row['platform'] }}</td>
                    <td>{{ $row['enrollment_status'] }}</td>
                    <td>{{ $row['last_seen_at'] }}</td>
                    <td>{{ $row['policy_name'] }}</td>
                    <td>{{ $row['assigned_version'] }}</td>
                    <td>{{ $row['applied_version'] }}</td>
                    <td class="{{ $row['compliance'] }}">{{ str_replace('_', ' ', $row['compliance']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
