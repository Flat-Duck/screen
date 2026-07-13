<?php

namespace App\Http\Controllers;

use App\Models\TelemetryEvent;
use Illuminate\Contracts\View\View;

class EventController extends Controller
{
    /** The interactive searchable/sortable/filterable table itself lives in the EventsTable Livewire component. */
    public function index(): View
    {
        return view('events.index');
    }

    public function show(TelemetryEvent $event): View
    {
        $event->load(['device', 'user', 'deviceSession']);

        return view('events.show', ['event' => $event]);
    }
}
