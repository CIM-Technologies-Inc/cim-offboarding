<?php

namespace App\View\Components\header;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class NotificationDropdown extends Component
{
    public Collection $notifications;

    public int $unreadCount;

    /**
     * Create a new component instance.
     */
    public function __construct()
    {
        $user = auth()->user();

        $this->notifications = $user?->notifications()->latest()->take(10)->get() ?? collect();
        $this->unreadCount = $user?->unreadNotifications()->count() ?? 0;
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.header.notification-dropdown');
    }
}
