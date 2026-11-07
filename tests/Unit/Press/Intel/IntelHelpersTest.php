<?php

namespace Tests\Unit\Press\Intel;

use App\Services\Press\Intel\Claims;
use App\Services\Press\Intel\Colour;
use App\Services\Press\Intel\DocText;
use App\Services\Press\Intel\Frameworks;
use App\Services\Press\Intel\Ids;
use App\Services\Press\Intel\JsonShape;
use App\Services\Press\Intel\Numbers;
use App\Services\Press\Intel\SeriesExtractor;
use App\Services\Press\Intel\Simplifier;
use App\Services\Press\Intel\Text;
use App\Services\Press\Intel\TextDiff;
use App\Services\Press\Intel\Titles;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntelHelpersTest extends TestCase
{
    // ------------------------------------------------------------- Text

    public function test_sentences_respect_abbreviations_decimals_and_initials(): void
    {
        $s = Text::sentences('Dr. Smith said e.g. costs fell 3.5% in Q3. J. Mokoena agreed! Is that all? Yes.');
        $this->assertSame(['Dr. Smith said e.g. costs fell 3.5% in Q3.', 'J. Mokoena agreed!', 'Is that all?', 'Yes.'], $s);
    }

    public function test_sentences_split_lines_and_drop_control_characters(): void
    {
        $this->assertSame(['One line', 'Another line.'], Text::sentences("One line\r\n\r\nAno\x07ther line."));
    }

    public function test_stemming_matches_plural_and_tense_forms(): void
    {
        $this->assertSame(Text::stem('risks'), Text::stem('risk'));
        $this->assertSame(Text::stem('delays'), Text::stem('delay'));
        $this->assertSame(Text::stem('recommendation'), Text::stem('recommendations'));
    }

    public function test_clip_never_cuts_a_word_or_adds_an_ellipsis(): void
    {
        $this->assertSame('Costs fell below', Text::clip('Costs fell below budget again', 18));
        $this->assertSame('Short', Text::clip('Short', 18));
    }

    public function test_jaccard_and_shingles(): void
    {
        $a = Text::shingles('the mill relining project is on track for november');
        $this->assertSame(1.0, Text::jaccard($a, $a));
        $this->assertSame(0.0, Text::jaccard($a, Text::shingles('nothing in common with this sentence at all')));
        $this->assertSame(0.0, Text::jaccard([], $a));
    }

    // ------------------------------------------------------------- Numbers

    #[DataProvider('numberCases')]
    public function test_numbers_are_parsed_by_value(string $text, array $keys): void
    {
        $this->assertSame($keys, array_column(Numbers::find($text), 'key'));
    }

    public static function numberCases(): array
    {
        return [
            'percent' => ['up 12.5% on last year', ['p:12.5']],
            'thousands comma' => ['1,200,000 tonnes', ['n:1.2e+6']],
            'million word' => ['R1.2 million', ['n:1.2e+6']],
            'thousands space' => ['1 200 units', ['n:1200']],
            'suffix' => ['sales of 5m', ['n:5.0e+6']],
            'decimal comma' => ['a ratio of 0,8', ['n:0.8']],
            'identifier glued to letters' => ['model 12abc and Q3', []],
            'quarter is not a number' => ['In Q3 2025 we grew', ['n:2025']],
        ];
    }

    public function test_unsupported_numbers_are_those_not_in_any_source(): void
    {
        $this->assertSame([], Numbers::unsupported('Revenue was R1,200,000 with 12% growth', 'We made R1.2 million, up 12 percent.'));
        $this->assertSame(['13%'], Numbers::unsupported('13% growth', 'up 12% last year'));
        $this->assertSame(['1999'], Numbers::unsupported('Founded in 1999', 'Founded long ago'));
        $this->assertSame([], Numbers::unsupported('Three steps and 4 sites', 'no figures'), 'small bare counts are not audited');
        $this->assertSame(['25'], Numbers::unsupported('We employ 25 people', 'We employ people'), 'figures above 20 are audited');
    }

    // ------------------------------------------------------------- Colour

    public function test_contrast_follows_wcag(): void
    {
        $this->assertEqualsWithDelta(21.0, Colour::contrast([0, 0, 0], [255, 255, 255]), 0.01);
        $this->assertEqualsWithDelta(1.0, Colour::contrast([10, 10, 10], [10, 10, 10]), 0.01);
        $this->assertEqualsWithDelta(4.54, Colour::contrast([118, 118, 118], [255, 255, 255]), 0.05);
    }

    public function test_colours_parse_in_all_spec_forms(): void
    {
        $this->assertSame([255, 255, 255, 1.0], Colour::parse('#fff'));
        $this->assertSame([17, 34, 51, 1.0], Colour::parse('#112233'));
        $this->assertEqualsWithDelta(0.5, Colour::parse('#11223380')[3], 0.01);
        $this->assertNull(Colour::parse('@ink'));
        $this->assertNull(Colour::parse('red'));
        $p = Colour::palette(null);
        $this->assertSame('#2563eb', $p['@accent']);
        $this->assertCount(18, $p);
        $this->assertSame([37, 99, 235, 1.0], Colour::resolve('@accent', $p));
    }

    public function test_nearest_token_and_gradient_stops(): void
    {
        $p = Colour::palette(null);
        $this->assertSame('@accent', Colour::nearestToken(Colour::parse('#2a60e8'), $p)['token']);
        $this->assertCount(2, Colour::fillColours(['g' => 'linear', 'a' => 90, 'stops' => [[0, '#000000'], [1, '@accent']]], $p));
        $this->assertSame([], Colour::fillColours(['img' => ['url' => 'x']], $p));
    }

    // ------------------------------------------------------------- DocText

    public function test_doc_text_reads_lists_and_builds_documents(): void
    {
        $doc = DocText::build([['text' => 'Intro', 'list' => null], ['text' => 'One', 'list' => 'bullet'], ['text' => 'Two', 'list' => 'bullet'], ['text' => 'Three', 'list' => 'ordered']]);
        $this->assertSame('doc', $doc['type']);
        $this->assertSame(['paragraph', 'bulletList', 'orderedList'], array_column($doc['content'], 'type'));
        $blocks = DocText::blocks($doc);
        $this->assertSame(['Intro', 'One', 'Two', 'Three'], array_column($blocks, 'text'));
        $this->assertSame([null, 'bullet', 'bullet', 'ordered'], array_column($blocks, 'list'));
        $this->assertSame("Intro\nOne\nTwo\nThree", DocText::text($doc));
        $this->assertTrue(DocText::isEmpty(DocText::paragraphs([''])));
    }

    public function test_map_text_keeps_marks(): void
    {
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'e-mail us', 'marks' => [['type' => 'bold']]]]]]];
        $out = DocText::mapText($doc, fn ($t) => str_replace('e-mail', 'email', $t));
        $this->assertSame('email us', $out['content'][0]['content'][0]['text']);
        $this->assertSame([['type' => 'bold']], $out['content'][0]['content'][0]['marks']);
    }

    public function test_doc_text_survives_garbage(): void
    {
        $this->assertSame([], DocText::blocks(null));
        $this->assertSame([], DocText::blocks(12));
        $this->assertSame('', DocText::text(['type' => 'doc', 'content' => 'nope']));
        $this->assertSame(1, count(DocText::blocks("one\ntwo\x00")) - 1);
    }

    // ------------------------------------------------------------- misc

    public function test_ids_match_the_spec_pattern_and_are_deterministic(): void
    {
        $a = Ids::make('sp', 'slide 1', 'el/2');
        $this->assertTrue(Ids::valid($a));
        $this->assertSame($a, Ids::make('sp', 'slide 1', 'el/2'));
        $this->assertNotSame($a, Ids::make('sp', 'slide 1', 'el/3'));
        $this->assertTrue(Ids::valid(Ids::make('weird prefix!!', 'x')));
        $this->assertFalse(Ids::valid('has space'));
    }

    public function test_text_diff_marks_removed_and_added_words(): void
    {
        $this->assertSame('We [-utilize-] {+use+} the tool.', preg_replace('/\s+/', ' ', TextDiff::words('We utilize the tool.', 'We use the tool.') === 'We [-utilize-] {+use+} the tool.' ? 'We [-utilize-] {+use+} the tool.' : TextDiff::words('We utilize the tool.', 'We use the tool.')));
        $d = TextDiff::words('In order to win we act', 'To win we act');
        $this->assertStringContainsString('[-In order to-]', $d);
        $this->assertStringContainsString('{+To+}', $d);
        $this->assertSame('same text', TextDiff::words('same text', 'same text'));
    }

    public function test_json_shape_validates_the_supported_subset(): void
    {
        $schema = ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'string', 'maxLength' => 3]], 'k' => ['type' => 'string', 'enum' => ['x', 'y']]]];
        $this->assertSame([], JsonShape::validate(['a' => ['ab', 'cd']], $schema));
        $this->assertNotSame([], JsonShape::validate(['b' => 1], $schema), 'missing required');
        $this->assertNotSame([], JsonShape::validate(['a' => ['a', 'b', 'c']], $schema), 'too many');
        $this->assertNotSame([], JsonShape::validate(['a' => ['abcd']], $schema), 'too long');
        $this->assertNotSame([], JsonShape::validate(['a' => [], 'k' => 'z'], $schema), 'bad enum');
        $this->assertNotSame([], JsonShape::validate('nope', $schema));
        $this->assertNotSame([], JsonShape::validate([], $schema), 'an empty array is an object missing a');
        $this->assertSame([], JsonShape::validate(null, ['type' => ['string', 'null']]));
    }

    // ------------------------------------------------------------- Frameworks

    public function test_every_framework_is_complete(): void
    {
        $layouts = ['title', 'section', 'bullets', 'two', 'image-left', 'image-right', 'image-full', 'compare', 'timeline', 'quote', 'data', 'kpi', 'closing', 'blank'];
        $this->assertContains('free', Frameworks::ids());
        $this->assertCount(8, Frameworks::ids());
        foreach (Frameworks::all() as $id => $fw) {
            $this->assertNotEmpty($fw['name']);
            $this->assertGreaterThanOrEqual(3, count($fw['beats']), $id);
            foreach ($fw['beats'] as $b) {
                foreach (['id', 'label', 'question', 'kinds', 'keywords'] as $k) {
                    $this->assertNotEmpty($b[$k], "$id.$k");
                }
                $this->assertGreaterThanOrEqual(5, count($b['keywords']), "$id {$b['id']} keywords");
                foreach ($b['kinds'] as $kind) {
                    $this->assertContains($kind, $layouts, "$id {$b['id']} kind $kind");
                }
                $this->assertStringEndsWith('?', $b['question']);
            }
            $this->assertCount(count($fw['beats']), array_unique(array_column($fw['beats'], 'id')));
            $this->assertLessThanOrEqual(4, count(Frameworks::beats($id, false)), "$id core beats leave room in a five slide deck");
        }
    }

    public function test_the_brief_structures_are_all_present(): void
    {
        $expected = [
            'problem-solution-value' => ['problem', 'solution', 'value'],
            'summary-findings-recommendations' => ['summary', 'findings', 'recommendations'],
            'introduction-evidence-conclusion' => ['introduction', 'evidence', 'conclusion'],
            'opportunity-market-strategy-financials' => ['opportunity', 'market', 'strategy', 'financials'],
            'situation-complication-resolution' => ['situation', 'complication', 'resolution'],
            'training' => ['objective', 'content', 'application'],
            'project-status' => ['overview', 'progress', 'risks', 'next'],
        ];
        foreach ($expected as $id => $beats) {
            $this->assertSame($beats, array_column(Frameworks::beats($id, false), 'id'), $id);
        }
    }

    #[DataProvider('frameworkTexts')]
    public function test_ranking_finds_the_matching_structure(string $text, string $expected): void
    {
        $this->assertSame($expected, Frameworks::rank($text)[0]['id']);
    }

    public static function frameworkTexts(): array
    {
        return [
            'investor' => ['The opportunity is a large unmet need. The market has 40 000 buyers and strong demand. Our strategy and go-to-market plan rely on pricing and partnerships. Financial forecast shows revenue, margin and funding need.', 'opportunity-market-strategy-financials'],
            'status' => ['Project overview and scope. Progress to date: milestone 2 completed, on track. Risks and issues: a supplier delay and a dependency. Next steps and upcoming deadlines.', 'project-status'],
            'training' => ['Learning objective: by the end you will be able to isolate energy. Module content covers the procedure and standard. Practical exercise and scenario with an assessment checklist.', 'training'],
            'scr' => ['The situation today is stable context. However the complication is a threat and a constraint causing delay. The resolution is to address it: we propose a plan and decision.', 'situation-complication-resolution'],
        ];
    }

    // ------------------------------------------------------------- Claims, Titles, Simplifier

    #[DataProvider('titles')]
    public function test_assertion_versus_topic_titles(string $title, bool $assertion): void
    {
        $this->assertSame($assertion, Claims::isAssertion($title), $title);
    }

    public static function titles(): array
    {
        return [
            ['Costs fell 3% below budget', true],
            ['Safety performance improved at both sites', true],
            ['We need to approve the conveyor replacement', true],
            ['Production is up', true],
            ['Revenue 12% up', true],
            ['Financial results', false],
            ['Overview', false],
            ['Agenda', false],
            ['Market analysis', false],
            ['Q3 summary', false],
            ['', false],
        ];
    }

    public function test_claims_are_found_by_type_and_never_judged(): void
    {
        $c = Claims::find('We grew 40% in 2025. Our unit is the fastest in the region. We saved R1.2 million. Output is twice as high as before. Nothing special here.');
        $this->assertSame(['percentage', 'superlative', 'money', 'comparative'], array_column($c, 'type'));
        foreach ($c as $claim) {
            $this->assertStringEndsWith('?', $claim['question']);
        }
        $this->assertTrue(Claims::hasSourceMarker('Source: Annual report'));
        $this->assertTrue(Claims::hasSourceMarker('according to the finance team'));
        $this->assertFalse(Claims::hasSourceMarker('We grew 40%'));
    }

    public function test_titles_are_extracted_from_claims_without_new_words(): void
    {
        $t = Titles::fromClaim('However, operating cost per tonne was R412, which is 3% below budget.');
        $this->assertSame('Operating cost per tonne was R412, which is 3% below budget', $t['title']);
        $this->assertTrue($t['lossless']);
        $cut = Titles::fromClaim('Operating cost per tonne was R412 across all of our sites in the north and the south, which is 3% below the budget agreed in March.');
        $this->assertSame('Operating cost per tonne was R412 across all of our sites in the north', $cut['title']);
        $this->assertFalse($cut['lossless']);
        $short = Titles::fromClaim('Safety remains our first priority.');
        $this->assertSame('Safety remains our first priority', $short['title']);
        $this->assertTrue($short['lossless']);
        $words = explode(' ', Titles::fromClaim(str_repeat('word ', 40))['title']);
        $this->assertLessThanOrEqual(16, count($words));
    }

    public function test_the_simplifier_only_removes_or_swaps_listed_phrases(): void
    {
        $this->assertSame('To win we use the tool because it is cheap.', Simplifier::line('In order to win we utilize the tool due to the fact that it is cheap.'));
        $this->assertSame('Costs fell 3%', Simplifier::bullet('Additionally, costs fell 3%.'));
        $this->assertSame('The plan is fine', Simplifier::line('The plan is really very fine'));
        $this->assertSame('Nothing to change here at all', Simplifier::line('Nothing to change here at all'));
        // numbers and negations survive
        $this->assertStringContainsString('not', Simplifier::line('This is not very good at 12.5%'));
        $this->assertStringContainsString('12.5%', Simplifier::line('This is not very good at 12.5%'));
    }

    public function test_long_sentences_are_split_only_when_both_halves_stand_alone(): void
    {
        $long = 'The plant processed 1.15 million tonnes at an average recovery of 91.4 percent during the quarter; the north pit delivered its best month of the year in September.';
        $parts = Simplifier::splitLong($long);
        $this->assertCount(2, $parts);
        $this->assertStringEndsWith('.', $parts[0]);
        $this->assertStringStartsWith('The north pit', $parts[1]);
        $this->assertSame(['Short sentence here.'], Simplifier::splitLong('Short sentence here.'));
        $this->assertCount(1, Simplifier::splitLong('A very long sentence '.str_repeat('with many words ', 12).'; ok.'), 'a tiny tail is not split off');
    }

    public function test_bulletise_gives_one_bullet_per_sentence(): void
    {
        $b = Simplifier::bulletise('Production rose 8% on Q2. We recorded two lost time injuries. The conveyor failed in August and cost 36 hours.');
        $this->assertSame(['Production rose 8% on Q2', 'We recorded two lost time injuries', 'The conveyor failed in August and cost 36 hours'], $b);
    }

    // ------------------------------------------------------------- SeriesExtractor

    public function test_series_come_only_from_numbers_in_the_text(): void
    {
        $t = "Quarterly output (kt)\nQuarter\tTonnes\tCost\nQ1\t1200\t450\nQ2\t1350\t470\nQ3\t1500\t480\n\nShare:\n- Retail: 42%\n- Wholesale: 33%\n- Online: 25%\n\nRevenue Q1 12.5, Q2 14.1, Q3 15.8 and Q4 16.2.\nThere are many things, and more things, and other things to say.";
        $blocks = SeriesExtractor::extract($t);
        $this->assertSame(['table', 'lines', 'inline'], array_column($blocks, 'kind'));
        $this->assertSame(['Q1', 'Q2', 'Q3'], $blocks[0]['categories']);
        $this->assertSame([1200.0, 1350.0, 1500.0], $blocks[0]['series'][0]['values']);
        $this->assertSame('Quarterly output (kt)', $blocks[0]['title']);
        $this->assertSame(['Retail', 'Wholesale', 'Online'], $blocks[1]['categories']);
        $this->assertSame('doughnut', SeriesExtractor::chart($blocks[1])['type'], 'shares summing to 100 are a doughnut');
        $this->assertSame([12.5, 14.1, 15.8, 16.2], $blocks[2]['series'][0]['values']);
        foreach ($blocks as $b) {
            $chart = SeriesExtractor::chart($b);
            $flat = implode(' ', array_merge(...array_map(fn ($s) => array_map(fn ($v) => (string) $v, $s['values']), $chart['data']['series'])));
            $this->assertSame([], Numbers::unsupported($flat, $t), 'every chart value is in the text');
        }
    }

    public function test_prose_and_short_runs_do_not_become_charts(): void
    {
        $this->assertSame([], SeriesExtractor::extract('We sold 40 units in March and 55 in April, which pleased everyone.'));
        $this->assertSame([], SeriesExtractor::extract("- Retail: 42%\n- Wholesale: 33%"));
        $this->assertSame([], SeriesExtractor::extract("a, b, c\nd, e, f\ng, h, i"), 'no numeric column');
        $this->assertSame([], SeriesExtractor::extract("Dear team,\nPlease note, the meeting moved, as agreed, to Monday.\nThanks, all, now."));
    }

    public function test_a_year_list_is_not_a_series_of_values(): void
    {
        $this->assertSame([], SeriesExtractor::extract('Founded 2019, 2020, 2021 and 2022.'));
    }
}
