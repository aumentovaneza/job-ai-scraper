<?php

namespace Tests\Unit;

use App\Exceptions\ResumeParseException;
use App\Services\ResumeParser;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class ResumeParserTest extends TestCase
{
    private ResumeParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ResumeParser;
    }

    public function test_extracts_text_from_docx(): void
    {
        $path = $this->makeDocx(['Jane Doe — Senior Platform Engineer', 'Built systems with Laravel and React.']);

        $text = $this->parser->extractText($path, 'docx');

        $this->assertStringContainsString('Senior Platform Engineer', $text);
        $this->assertStringContainsString('Laravel and React', $text);

        @unlink($path);
    }

    public function test_passes_through_plain_text(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'resume').'.txt';
        file_put_contents($path, "Line one\r\nLine two\n\n\n\nLine three");

        $text = $this->parser->extractText($path, 'txt');

        $this->assertSame("Line one\nLine two\n\nLine three", $text);

        @unlink($path);
    }

    public function test_rejects_unsupported_format(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'resume').'.rtf';
        file_put_contents($path, 'whatever');

        $this->expectException(ResumeParseException::class);

        try {
            $this->parser->extractText($path, 'rtf');
        } finally {
            @unlink($path);
        }
    }

    public function test_rejects_document_with_no_text(): void
    {
        $path = $this->makeDocx([]); // empty document

        $this->expectException(ResumeParseException::class);

        try {
            $this->parser->extractText($path, 'docx');
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  array<int, string>  $paragraphs
     */
    private function makeDocx(array $paragraphs): string
    {
        $word = new PhpWord;
        $section = $word->addSection();
        foreach ($paragraphs as $paragraph) {
            $section->addText($paragraph);
        }

        $path = tempnam(sys_get_temp_dir(), 'resume').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }
}
