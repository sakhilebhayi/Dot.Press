<?php

namespace App\Notifications;

use App\Models\Deck;
use App\Models\DeckReview;
use App\Models\User;
use App\Services\Press\DeckLinks;
use App\Services\Press\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class ReviewRequested extends Notification implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Deck $deck, public DeckReview $review, public User $requester) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(MailText::subject("{$this->requester->name} asked you to review \"{$this->deck->title}\""))
            ->greeting('Hello '.MailText::plain($notifiable->name, 80))
            ->line(MailText::plain($this->requester->name, 80).' asked you to review "'.MailText::plain($this->deck->title, 120).'".');

        if ($this->review->note) {
            $mail->line('"'.MailText::plain($this->review->note, 2000).'"');
        }

        return $mail->action('Open the deck', DeckLinks::editor($this->deck));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'review.requested',
            'deck_id' => $this->deck->id,
            'deck_title' => $this->deck->title,
            'review_id' => $this->review->id,
            'by' => ['id' => $this->requester->id, 'name' => $this->requester->name],
            'note' => $this->review->note,
        ];
    }
}
