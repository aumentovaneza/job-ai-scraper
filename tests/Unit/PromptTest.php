<?php

namespace Tests\Unit;

use App\Services\Ai\Prompt;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PromptTest extends TestCase
{
    private string $fixture = '';

    protected function tearDown(): void
    {
        if ($this->fixture !== '' && is_file($this->fixture)) {
            unlink($this->fixture);
        }

        parent::tearDown();
    }

    public function test_loads_a_shipped_prompt_by_versioned_name(): void
    {
        // The voice-profile prompt ships with the repo (T-14).
        $this->assertStringContainsString(
            'voice profile',
            Prompt::load('extract_voice_profile.v1'),
        );
    }

    public function test_renders_placeholders_from_variables(): void
    {
        $this->writeFixture('render_probe.v1', "Role: {{ title }}\nJD: {{ jd_text }}");

        $rendered = Prompt::render('render_probe.v1', [
            'title' => 'Staff Engineer',
            'jd_text' => 'Build platforms.',
        ]);

        $this->assertSame("Role: Staff Engineer\nJD: Build platforms.", $rendered);
    }

    public function test_render_throws_when_a_placeholder_is_unfilled(): void
    {
        $this->writeFixture('missing_var.v1', 'Hello {{ name }}');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing variable [name]');

        Prompt::render('missing_var.v1', []);
    }

    #[DataProvider('malformedNames')]
    public function test_rejects_malformed_names(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        Prompt::load($name);
    }

    public static function malformedNames(): array
    {
        return [
            'no version' => ['enrich_job'],
            'uppercase' => ['EnrichJob.v1'],
            'path traversal' => ['../secrets.v1'],
            'bad version token' => ['enrich_job.version1'],
        ];
    }

    public function test_load_throws_for_a_missing_file(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Prompt not found');

        Prompt::load('does_not_exist.v1');
    }

    public function test_exists_reflects_presence_on_disk(): void
    {
        $this->assertTrue(Prompt::exists('extract_voice_profile.v1'));
        $this->assertFalse(Prompt::exists('does_not_exist.v1'));
        $this->assertFalse(Prompt::exists('../secrets.v1'));
    }

    public function test_all_lists_shipped_prompts(): void
    {
        $this->assertContains('extract_voice_profile.v1', Prompt::all());
    }

    private function writeFixture(string $name, string $body): void
    {
        $this->fixture = resource_path("prompts/{$name}.md");
        file_put_contents($this->fixture, $body);
    }
}
