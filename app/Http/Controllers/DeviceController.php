<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Contracts\View\View;

class DeviceController extends Controller
{
    /** The interactive searchable/sortable table itself lives in the DevicesTable Livewire component. */
    public function index(): View
    {
        return view('devices.index');
    }

    public function show(Device $device): View
    {
        $device->load([
            'telemetryEvents' => fn ($query) => $query->latest('received_at')->limit(50),
        ]);

        return view('devices.show', ['device' => $device]);
    }
}
