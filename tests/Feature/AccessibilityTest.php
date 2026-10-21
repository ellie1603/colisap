<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Monitoring;
use App\Filament\Pages\MonitoringApiKeys;
use App\Filament\Pages\Reports;
use App\Filament\Resources\ApplicationResource;
use App\Filament\Resources\MemberResource;
use App\Models\Member;
use App\Services\Access\Permissions;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

/**
 * Automated WCAG 2.2 checks on the main pages: language and title (3.1.1, 2.4.2), text alternatives for
 * images (1.1.1), a name for every button and form field (4.1.2, 1.3.1) and unique ids (4.1.1 practice).
 * Colour contrast of the custom palette is checked separately (1.4.3).
 */
class AccessibilityTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function pages(): array
    {
        return [
            'sign-in' => ['login'],
            'dashboard' => ['dashboard'],
            'members' => ['members'],
            'member profile' => ['member'],
            'edit member' => ['edit-member'],
            'applications' => ['applications'],
            'monitoring' => ['monitoring'],
            'reports' => ['reports'],
            'monitoring API' => ['api'],
            'my account' => ['profile'],
        ];
    }

    private function html(string $page): string
    {
        if ($page === 'login') {
            return $this->get('/admin/login')->assertOk()->getContent();
        }

        $page === 'api' ? $this->actingAsDeveloper() : $this->actingAsRole(Permissions::ADMIN);
        $member = Member::factory()->create();

        $url = match ($page) {
            'dashboard' => '/admin',
            'members' => MemberResource::getUrl('index'),
            'member' => MemberResource::getUrl('view', ['record' => $member]),
            'edit-member' => MemberResource::getUrl('edit', ['record' => $member]),
            'applications' => ApplicationResource::getUrl('index'),
            'monitoring' => Monitoring::getUrl(),
            'reports' => Reports::getUrl(),
            'api' => MonitoringApiKeys::getUrl(),
            'profile' => EditProfile::getUrl(),
        };

        return $this->get($url)->assertOk()->getContent();
    }

    #[DataProvider('pages')]
    public function test_the_page_meets_the_automated_wcag_checks(string $page): void
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($this->html($page));
        libxml_clear_errors();
        $xpath = new DOMXPath($document);
        $problems = [];

        if (trim((string) $xpath->evaluate('string(/html/@lang)')) === '') {
            $problems[] = 'the page has no language (<html lang>)';
        }

        if (trim((string) $xpath->evaluate('string(//title)')) === '') {
            $problems[] = 'the page has no title';
        }

        // Elements inside <template> are not on the page until a script copies them.
        $visible = '[not(ancestor::template)]';

        foreach ($xpath->query("//img{$visible}") as $image) {
            if (! $image->hasAttribute('alt')) {
                $problems[] = 'image without alt text: '.$image->getAttribute('src');
            }
        }

        foreach ($xpath->query("//button{$visible} | //a[@href]{$visible}") as $control) {
            if ($this->accessibleName($control, $xpath) === '') {
                $problems[] = "{$control->nodeName} without a name: ".$this->describe($control);
            }
        }

        foreach ($xpath->query("//input[not(@type='hidden')]{$visible} | //select{$visible} | //textarea{$visible}") as $field) {
            if (! $this->isLabelled($field, $xpath)) {
                $problems[] = "{$field->nodeName} without a label: ".$this->describe($field);
            }
        }

        $ids = [];
        foreach ($xpath->query("//*[@id]{$visible}") as $element) {
            $ids[$element->getAttribute('id')] = ($ids[$element->getAttribute('id')] ?? 0) + 1;
        }
        foreach (array_filter($ids, fn (int $count) => $count > 1) as $id => $count) {
            $problems[] = "id \"{$id}\" is used {$count} times";
        }

        $this->assertSame([], array_values(array_unique($problems)), "{$page} has accessibility problems.");
    }

    /**
     * The custom text colours on their backgrounds must reach 4.5:1 (normal text), WCAG 1.4.3.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function colourPairs(): array
    {
        return [
            'muted text on white (light)' => ['#667091', '#FFFFFF'],
            'muted text on the page background (light)' => ['#667091', '#F8F9FC'],
            'muted text on the dark card (dark)' => ['#949DBA', '#141A3A'],
            'primary button text' => ['#FFFFFF', '#2E3A8C'],
            'error page muted text (light)' => ['#4A5476', '#FFFFFF'],
            'error page muted text (dark)' => ['#949DBA', '#141A3A'],
            'error code label (light)' => ['#B8520F', '#FFFFFF'],
            'login heading (light)' => ['#161C52', '#FFFFFF'],
        ];
    }

    #[DataProvider('colourPairs')]
    public function test_custom_text_colours_have_enough_contrast(string $text, string $background): void
    {
        $this->assertGreaterThanOrEqual(4.5, round($this->contrast($text, $background), 2));
    }

    private function accessibleName(DOMElement $element, DOMXPath $xpath): string
    {
        foreach (['aria-label', 'title', 'x-bind:aria-label', ':aria-label'] as $attribute) {
            if (trim($element->getAttribute($attribute)) !== '') {
                return trim($element->getAttribute($attribute));
            }
        }

        if ($element->getAttribute('aria-labelledby') !== '') {
            return 'labelled';
        }

        // A switch or button named by a <label for="…"> (valid HTML: buttons are labelable).
        if (($id = $element->getAttribute('id')) !== '' && $xpath->query('//label[@for="'.$id.'"]')->length > 0) {
            return 'labelled';
        }

        foreach ($xpath->query('.//img[@alt]', $element) as $image) {
            if (trim($image->getAttribute('alt')) !== '') {
                return trim($image->getAttribute('alt'));
            }
        }

        return trim(preg_replace('/\s+/', ' ', $element->textContent));
    }

    private function isLabelled(DOMElement $field, DOMXPath $xpath): bool
    {
        if (trim($field->getAttribute('aria-label')) !== '' || $field->getAttribute('aria-labelledby') !== '' || trim($field->getAttribute('title')) !== '') {
            return true;
        }

        if (($id = $field->getAttribute('id')) !== '' && $xpath->query('//label[@for="'.$id.'"]')->length > 0) {
            return true;
        }

        // Alpine links label and field at runtime: x-bind:for on the label, x-bind:id on the field (e.g. global search).
        if ($field->getAttribute('x-bind:id') !== '' && $xpath->query("ancestor::*[label[@*[name()='x-bind:for']]]", $field)->length > 0) {
            return true;
        }

        return $xpath->query('ancestor::label', $field)->length > 0;
    }

    private function describe(DOMElement $element): string
    {
        $parts = array_filter([
            $element->getAttribute('id') ? '#'.$element->getAttribute('id') : null,
            $element->getAttribute('name') ? 'name='.$element->getAttribute('name') : null,
            $element->getAttribute('wire:model') ? 'wire:model='.$element->getAttribute('wire:model') : null,
            $element->getAttribute('href') ? 'href='.$element->getAttribute('href') : null,
            $element->getAttribute('class') ? '.'.str_replace(' ', '.', substr($element->getAttribute('class'), 0, 60)) : null,
        ]);

        return implode(' ', $parts);
    }

    private function contrast(string $foreground, string $background): float
    {
        [$lighter, $darker] = [max($this->luminance($foreground), $this->luminance($background)), min($this->luminance($foreground), $this->luminance($background))];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function luminance(string $hex): float
    {
        $channels = array_map(fn (string $pair) => hexdec($pair) / 255, str_split(ltrim($hex, '#'), 2));
        $linear = array_map(fn (float $value) => $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4, $channels);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }
}
