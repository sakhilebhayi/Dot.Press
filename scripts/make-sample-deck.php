<?php

/*
 * Rebuilds resources/js/press/brand/sampleDeck.js (the real sample deck on the public page).
 *
 *   php artisan tinker --execute="require 'scripts/make-sample-deck.php';"
 *
 * It takes six slides of the "Quarterly business review" template, runs the real deck review and the real export
 * preflight on them, and writes the document, the review and the preflight counts as static data. Nothing is invented:
 * every number on the landing page's sample is the output of the code that ships in this repository.
 */

use App\Services\Press\Export\ExportPreflight;
use App\Services\Press\Intel\DeckAnalyzer;
use App\Services\Press\TemplateLibrary;

$built = TemplateLibrary::build('quarterly-business-review');
$slides = [];
foreach ([0, 3, 4, 5, 8, 10] as $n => $index) {
    $slide = $built['slides'][$index];
    $slide['id'] = 'sd'.($n + 1);
    $slides[] = $slide;
}
$doc = ['deck' => ['w' => 1920, 'h' => 1080, 'theme' => $built['theme']], 'slides' => $slides, 'sections' => $built['sections']];

$analysis = (new DeckAnalyzer)->analyze($doc);
$review = [];
foreach ($analysis['issues'] as $issue) {
    if (in_array($issue['kind'], ['role-size', 'unsupported-claim', 'topic-title', 'acronym'], true)) {
        $review[] = [
            'kind' => $issue['kind'], 'severity' => $issue['severity'], 'category' => $issue['category'], 'slide' => $issue['slide'],
            'message' => $issue['message'], 'why' => $issue['why'], 'fix' => $issue['fix']['label'] ?? null, 'questions' => $issue['questions'] ?? null,
        ];
    }
}

$keys = array_flip(['native', 'approximated', 'flattened', 'dropped', 'total', 'slides', 'general']);
$pptx = ExportPreflight::analyze($doc, 'pptx')['summary'];
$pdf = ExportPreflight::analyze($doc, 'pdf')['summary'];
$preflight = ['pptx' => array_intersect_key($pptx, $keys), 'pdf' => array_intersect_key($pdf, $keys) + ['warnings' => $pdf['warnings']]];

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$header = <<<'JS'
// The sample deck on the public page. It is real data, not an illustration:
//   SAMPLE_DECK       six slides of the "Quarterly business review" template (App\Services\Press\TemplateLibrary),
//                     exactly as the template library produces them. The figures in it are the template's placeholders.
//   SAMPLE_REVIEW     issues the deck review (App\Services\Press\Intel\DeckAnalyzer) reports for that deck
//   SAMPLE_PREFLIGHT  the export preflight (App\Services\Press\Export\ExportPreflight) counts for that deck
// Regenerate with scripts/make-sample-deck.php.

JS;
file_put_contents(
    base_path('resources/js/press/brand/sampleDeck.js'),
    $header.'export const SAMPLE_DECK = '.json_encode($doc, $flags).";\n\n"
    .'export const SAMPLE_REVIEW = '.json_encode($review, $flags | JSON_PRETTY_PRINT).";\n\n"
    .'export const SAMPLE_REVIEW_SUMMARY = '.json_encode($analysis['summary'], $flags).";\n\n"
    .'export const SAMPLE_PREFLIGHT = '.json_encode($preflight, $flags | JSON_PRETTY_PRINT).";\n"
);
