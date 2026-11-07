<?php

namespace App\Services\Press;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

/**
 * The built-in presentation templates. Each one is a class in App\Services\Press\Templates that follows a named
 * narrative structure, uses a built-in theme and is drawn entirely from the built-in layouts.
 *
 * all()    metadata for the gallery
 * find()   metadata of one template
 * build()  the theme, sections and slide fields for a new deck of a given size (what DeckController stores)
 */
final class TemplateLibrary
{
    public const CATEGORIES = [
        'business' => 'Business and executive',
        'sales' => 'Sales and marketing',
        'finance' => 'Finance and investment',
        'education' => 'Education and training',
        'operations' => 'Operations and industry',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $meta = null;

    /** @return list<class-string<Template>> */
    private static function classes(): array
    {
        $namespace = 'App\\Services\\Press\\Templates\\Library\\';

        return array_filter(array_map(fn (string $name): string => $namespace.$name, [
            'ExecutiveSummary', 'BoardPack', 'QuarterlyReview', 'Strategy',
            'SalesProposal', 'ProductLaunch', 'MarketingPlan',
            'InvestorPitch', 'FinancialReview', 'BudgetPresentation',
            'TrainingDeck', 'Lesson', 'Workshop', 'ResearchTalk',
            'MiningProduction', 'SafetyBriefing', 'ProjectStatus', 'EngineeringProposal', 'ConstructionProgress', 'ManufacturingPerformance',
        ]), 'class_exists');
    }

    /**
     * Gallery metadata: key, name, category, category_name, blurb, narrative, slides, theme, aspect, tags.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_values(self::metadata());
    }

    /** @return ?array<string, mixed> */
    public static function find(string $key): ?array
    {
        return self::metadata()[$key] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::metadata());
    }

    /**
     * The document parts of a new deck: the theme, the sections and the slides (title, notes, layout, section_id,
     * bg, transition, els). An unknown key gives empty parts.
     *
     * @return array{theme: ?array<string, mixed>, sections: list<array{id: string, title: string}>, slides: list<array<string, mixed>>}
     */
    public static function build(string $key, int $w = 1920, int $h = 1080): array
    {
        $class = self::classFor($key);
        if ($class === null) {
            return ['theme' => null, 'sections' => [], 'slides' => []];
        }

        $meta = $class::meta();
        $theme = ThemeLibrary::find($meta['theme']) ?? ThemeLibrary::defaults();
        $compose = new Compose($w, $h, $theme, [
            'label' => $meta['label'] ?? $meta['name'],
            'transition' => $meta['transition'] ?? ['type' => 'fade', 'dir' => 'l', 'dur' => 450, 'ease' => 'out'],
            'cover' => $meta['cover'] ?? 'band',
            'icon' => $meta['icon'] ?? 'target',
        ]);
        $class::compose($compose);

        return ['theme' => $theme, 'sections' => $compose->sections(), 'slides' => $compose->slides()];
    }

    /** @return ?class-string<Template> */
    private static function classFor(string $key): ?string
    {
        foreach (self::classes() as $class) {
            if ($class::meta()['key'] === $key) {
                return $class;
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    private static function metadata(): array
    {
        if (self::$meta !== null) {
            return self::$meta;
        }

        $out = [];
        foreach (self::classes() as $class) {
            $m = $class::meta();
            $built = self::build($m['key']);
            $out[$m['key']] = [
                'key' => $m['key'],
                'name' => $m['name'],
                'category' => $m['category'],
                'category_name' => self::CATEGORIES[$m['category']],
                'blurb' => $m['blurb'],
                'narrative' => $m['narrative'],
                'slides' => count($built['slides']),
                'sections' => count($built['sections']),
                'theme' => $m['theme'],
                'aspect' => '16:9',
                'tags' => $m['tags'],
            ];
        }

        return self::$meta = $out;
    }
}
