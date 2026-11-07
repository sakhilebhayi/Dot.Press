<?php

namespace App\Notifications;

use App\Models\Deck;
use App\Models\DeckReview;
use App\Models\User;
use App\Services\Press\DeckLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class ReviewDecided extends Notification implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Deck $deck, public DeckReview $review, public User $reviewer) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verdict = $this->review->status === 'approved' ? 'approved' : 'asked for changes to';

        $mail = (new MailMessage)
            ->subject("{$this->reviewer->name} {$verdict} \"{$this->deck->title}\"")
            ->greeting("Hello {$notifiable->name}")
            ->line("{$this->reviewer->name} {$verdict} \"{$this->deck->title}\".");

        if ($this->review->decision_note) {
            $mail->line('"'.$this->review->decision_note.'"');
        }

        return $mail->action('Open the deck', DeckLinks::editor($this->deck));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'review.decided',
            'deck_id' => $this->deck->id,
            'deck_title' => $this->deck->title,
            'review_id' => $this->review->id,
            'decision' => $this->review->status,
            'by' => ['id' => $this->reviewer->id, 'name' => $this->reviewer->name],
            'note' => $this->review->decision_note,
        ];
    }
}
