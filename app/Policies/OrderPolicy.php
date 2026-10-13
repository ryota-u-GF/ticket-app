<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * Orders can be viewed by the buyer, the organizer of the event, and admins.
     */
    public function view(User $user, Order $order): bool
    {
        return $order->user_id === $user->id
            || $order->event->organizer_id === $user->id
            || $user->isAdmin();
    }

    /**
     * The order details with the ticket codes can be viewed by the buyer only.
     */
    public function viewTickets(User $user, Order $order): bool
    {
        return $order->user_id === $user->id;
    }

    /**
     * Orders can be cancelled by the buyer only.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $order->user_id === $user->id;
    }
}
