<?php

namespace App\Support\Notifications;

use App\Models\InternalNotification;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class NotificationInbox
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function query(User $user): Builder
    {
        $permissions = ($user->role?->is_active ?? false)
            ? $user->role->permissions->pluck('slug')->all()
            : [];

        return InternalNotification::withoutGlobalScopes()
            ->select('internal_notifications.*')
            ->addSelect('internal_notification_recipients.read_at as recipient_read_at')
            ->join('internal_notification_recipients', 'internal_notification_recipients.internal_notification_id', '=', 'internal_notifications.id')
            ->where('internal_notification_recipients.tenant_id', $user->tenant_id)
            ->where('internal_notification_recipients.user_id', $user->id)
            ->where('internal_notifications.tenant_id', $user->tenant_id)
            ->when($user->agency_id !== null, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereNull('internal_notifications.agency_id')
                ->orWhere('internal_notifications.agency_id', $user->agency_id)))
            ->whereIn('internal_notifications.required_permission', $permissions);
    }

    public function paginate(User $user, ?string $status, ?string $priority, ?string $category): LengthAwarePaginator
    {
        return $this->query($user)
            ->with('agency:id,name')
            ->when($status === 'unread', fn (Builder $query) => $query->whereNull('internal_notification_recipients.read_at'))
            ->when($priority, fn (Builder $query, string $value) => $query->where('internal_notifications.priority', $value))
            ->when($category, fn (Builder $query, string $value) => $query->where('internal_notifications.category', $value))
            ->orderByDesc('internal_notifications.occurred_at')
            ->orderByDesc('internal_notifications.id')
            ->paginate(20)
            ->withQueryString();
    }

    public function unreadCount(User $user): int
    {
        return $this->query($user)->whereNull('internal_notification_recipients.read_at')->count();
    }

    public function recent(User $user, int $limit = 5)
    {
        return $this->query($user)
            ->with('agency:id,name')
            ->orderByDesc('internal_notifications.occurred_at')
            ->limit($limit)
            ->get();
    }

    public function owns(User $user, InternalNotification $notification): bool
    {
        return $this->query($user)->where('internal_notifications.id', $notification->id)->exists();
    }

    public function markRead(User $user, InternalNotification $notification): void
    {
        $this->setReadAt($user, $notification, now(), 'notification.read');
    }

    public function markUnread(User $user, InternalNotification $notification): void
    {
        $this->setReadAt($user, $notification, null, 'notification.unread');
    }

    public function markAllRead(User $user): int
    {
        $ids = $this->query($user)
            ->whereNull('internal_notification_recipients.read_at')
            ->pluck('internal_notifications.id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $count = DB::table('internal_notification_recipients')
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->whereIn('internal_notification_id', $ids)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $this->audit->record('notification.all_read', $user, [], ['count' => $count]);

        return $count;
    }

    private function setReadAt(User $user, InternalNotification $notification, mixed $readAt, string $auditAction): void
    {
        abort_unless($this->owns($user, $notification), 403);

        DB::table('internal_notification_recipients')
            ->where('tenant_id', $user->tenant_id)
            ->where('internal_notification_id', $notification->id)
            ->where('user_id', $user->id)
            ->update(['read_at' => $readAt]);

        $this->audit->record($auditAction, $notification, [], ['category' => $notification->category]);
    }
}
