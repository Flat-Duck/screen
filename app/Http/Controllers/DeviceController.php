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
            'user',
            'sessions.user',
            'pushToken',
            'telemetryEvents' => fn ($query) => $query->with(['user', 'deviceSession'])->latest('received_at')->limit(50),
        ]);

        return view('devices.show', ['device' => $device]);
    }
}
