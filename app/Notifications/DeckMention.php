<?php

namespace App\Notifications;

use App\Models\Deck;
use App\Models\DeckComment;
use App\Models\User;
use App\Services\Press\DeckLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class DeckMention extends Notification implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Deck $deck, public DeckComment $comment, public User $author) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->author->name} mentioned you in \"{$this->deck->title}\"")
            ->greeting("Hello {$notifiable->name}")
            ->line("{$this->author->name} mentioned you in a comment on \"{$this->deck->title}\".")
            ->line('"'.$this->excerpt().'"')
            ->action('Open the deck', DeckLinks::editor($this->deck, $this->comment->slide_id));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'deck.mention',
            'deck_id' => $this->deck->id,
            'deck_title' => $this->deck->title,
            'comment_id' => $this->comment->id,
            'slide_id' => $this->comment->slide_id,
            'by' => ['id' => $this->author->id, 'name' => $this->author->name],
            'excerpt' => $this->excerpt(),
        ];
    }

    private function excerpt(): string
    {
        $plain = preg_replace('/@\[([^\]]*)\]\(\d+\)/u', '@$1', $this->comment->body) ?? '';

        return Str::limit($plain, 200);
    }
}
