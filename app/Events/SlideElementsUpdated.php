<?php

namespace App\Events;

use App\Models\Slide;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast after a slide's canvas elements are saved, so every other
 * browser tab with that slide open can apply the change live instead of
 * only finding out on their next save (via the existing revision-conflict
 * check) or next presence heartbeat. Dispatched with ->toOthers() so the
 * saving client -- which already has the state it just wrote -- doesn't
 * receive its own echo.
 */
class SlideElementsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Slide $slide,
        public readonly int $editedByUserId,
        public readonly string $editedByName,
    ) {}

    /**
     * Production has no websocket server and runs BROADCAST_CONNECTION=null.
     * Skip the event entirely then: otherwise every save would add a
     * BroadcastEvent job to the database queue only for the worker to hand it
     * to the null driver (and fail if the slide was deleted meanwhile).
     */
    public function broadcastWhen(): bool
    {
        return config('broadcasting.default') !== 'null';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('slide.'.$this->slide->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'slide.elements-updated';
    }

    public function broadcastWith(): array
    {
        $payload = $this->slide->canvasStatePayload();

        return [
            'slide_id' => $this->slide->id,
            'revision' => $this->slide->revision,
            'canvas_state' => $payload,
            'edited_by' => [
                'user_id' => $this->editedByUserId,
                'name' => $this->editedByName,
            ],
        ];
    }
}
