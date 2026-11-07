<?php

namespace App\Services\Press;

/**
 * Text a member typed (names, deck titles, notes, comments) on its way into a notification mail. The mail theme renders
 * every line as Markdown, so `[text](https://...)` would become a link and `![](https://...)` a picture, inside a message
 * that carries the platform's address and credibility. plain() makes such text arrive as text; subject() keeps a line
 * break out of a header.
 */
final class MailText
{
    /** A value for a line of the mail body: control characters out, every Markdown and HTML character escaped. */
    public static function plain(?string $text, int $max = 500): string
    {
        $text = self::singleLine((string) $text);
        $text = mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;

        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|<>~&"])/u', '\\\\$1', $text);
    }

    /** A value for the subject line: one line of plain text. */
    public static function subject(?string $text): string
    {
        return self::singleLine((string) $text);
    }

    private static function singleLine(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
