<?php

namespace Tests\Feature\Press\Security;

use App\Models\DeckComment;
use App\Models\DeckReview;
use App\Models\User;
use App\Notifications\DeckMention;
use App\Notifications\ReviewDecided;
use App\Notifications\ReviewRequested;
use Tests\Feature\Press\AccessTestCase;

/**
 * Notification mails are written by the platform and sent from its address, so text a member typed must arrive as
 * text: no link, picture or markup of the writer's choosing inside a message people trust.
 */
class MailInjectionTest extends AccessTestCase
{
    private const HOSTILE = '[Sign in to keep access](https://evil.example/login) ![t](https://evil.example/track.gif) <a href="https://evil.example/x">go</a> <img src="https://evil.example/p.png"> <script>alert(1)</script>';

    private function assertPlain(string $html): void
    {
        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringNotContainsString('src="https://evil.example', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img src="https://evil', $html);
        $this->assertStringContainsString('evil.example', $html, 'the words stay readable, they are just not live');
    }

    public function test_a_mention_mail_shows_the_comment_as_plain_text(): void
    {
        $author = User::factory()->create(['name' => 'Eve '.self::HOSTILE]);
        $this->deck->forceFill(['title' => 'Plan '.self::HOSTILE])->save();
        $comment = DeckComment::create(['deck_id' => $this->deck->id, 'user_id' => $author->id, 'body' => self::HOSTILE, 'mentions' => []]);

        $mail = (new DeckMention($this->deck->fresh(), $comment, $author))->toMail($this->owner);
        $this->assertPlain((string) $mail->render());
    }

    public function test_review_mails_show_notes_titles_and_names_as_plain_text(): void
    {
        $requester = User::factory()->create(['name' => 'Eve '.self::HOSTILE]);
        $this->deck->forceFill(['title' => 'Plan '.self::HOSTILE])->save();
        $review = DeckReview::create(['deck_id' => $this->deck->id, 'requested_by' => $requester->id, 'reviewer_id' => $this->owner->id, 'status' => 'approved', 'round' => 1, 'note' => self::HOSTILE, 'decision_note' => self::HOSTILE]);

        $this->assertPlain((string) (new ReviewRequested($this->deck->fresh(), $review, $requester))->toMail($this->owner)->render());
        $this->assertPlain((string) (new ReviewDecided($this->deck->fresh(), $review, $requester))->toMail($this->owner)->render());
    }

    public function test_the_subject_line_cannot_carry_a_line_break(): void
    {
        $author = User::factory()->create(['name' => "Eve\r\nBcc: victim@example.test"]);
        $comment = DeckComment::create(['deck_id' => $this->deck->id, 'user_id' => $author->id, 'body' => 'hi', 'mentions' => []]);
        $mail = (new DeckMention($this->deck, $comment, $author))->toMail($this->owner);

        $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $mail->subject);
    }
}
