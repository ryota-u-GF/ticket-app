<?php

namespace App\Policies;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EventPolicy
{
    /**
     * Published events are visible to anyone; others only to the owner and admins.
     *
     * Denied requests get a 404 so that unpublished events stay hidden.
     */
    public function view(?User $user, Event $event): Response
    {
        if ($event->isPublished()) {
            return Response::allow();
        }

        return $user !== null && ($this->owns($user, $event) || $user->isAdmin())
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Only published events can be purchased, by anyone logged in or not.
     *
     * Denied requests get a 404 so that unpublished events stay hidden.
     */
    public function purchase(?User $user, Event $event): Response
    {
        return $event->isPublished() ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Determine whether the user can create events.
     */
    public function create(User $user): bool
    {
        return $user->isOrganizer();
    }

    /**
     * Determine whether the user can update the event.
     */
    public function update(User $user, Event $event): bool
    {
        return $this->owns($user, $event);
    }

    /**
     * Determine whether the user can delete the event.
     */
    public function delete(User $user, Event $event): bool
    {
        return $this->owns($user, $event);
    }

    /**
     * Owners can publish their events, except ones an admin has unpublished.
     */
    public function publish(User $user, Event $event): bool
    {
        return $this->owns($user, $event) && $event->status !== EventStatus::Unpublished;
    }

    /**
     * Determine whether the user can force the event to be unpublished.
     */
    public function unpublish(User $user, Event $event): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user is the organizer of the event.
     */
    private function owns(User $user, Event $event): bool
    {
        return $event->organizer_id === $user->id;
    }
}
