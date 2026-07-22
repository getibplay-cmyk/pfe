<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationFilterRequest;
use App\Models\InternalNotification;
use App\Support\Notifications\NotificationDestination;
use App\Support\Notifications\NotificationInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(NotificationFilterRequest $request, NotificationInbox $inbox): View
    {
        $filters = $request->validated();

        return view('notifications.index', [
            'notifications' => $inbox->paginate(
                $request->user(),
                ($filters['status'] ?? 'all') === 'unread' ? 'unread' : null,
                $filters['priority'] ?? null,
                $filters['category'] ?? null,
            ),
            'unreadCount' => $inbox->unreadCount($request->user()),
        ]);
    }

    public function read(Request $request, InternalNotification $notification, NotificationInbox $inbox): RedirectResponse
    {
        $this->authorize('update', $notification);
        $inbox->markRead($request->user(), $notification);

        return back()->with('status', 'Notification marquée comme lue.');
    }

    public function unread(Request $request, InternalNotification $notification, NotificationInbox $inbox): RedirectResponse
    {
        $this->authorize('update', $notification);
        $inbox->markUnread($request->user(), $notification);

        return back()->with('status', 'Notification marquée comme non lue.');
    }

    public function readAll(Request $request, NotificationInbox $inbox): RedirectResponse
    {
        $this->authorize('viewAny', InternalNotification::class);
        $count = $inbox->markAllRead($request->user());

        return back()->with('status', $count === 0 ? 'Aucune notification non lue.' : 'Toutes les notifications ont été marquées comme lues.');
    }

    public function open(Request $request, InternalNotification $notification, NotificationInbox $inbox, NotificationDestination $destination): RedirectResponse
    {
        $this->authorize('view', $notification);
        $url = $destination->resolve($notification, $request->user());
        $inbox->markRead($request->user(), $notification);

        return redirect()->to($url);
    }
}
