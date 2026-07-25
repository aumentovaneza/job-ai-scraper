<?php

namespace App\Services;

use App\Exceptions\ResumeParseException;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Extracts plain text from an uploaded resume (T-13).
 *
 * Supports PDF (smalot/pdfparser) and Word .docx (phpoffice/phpword); plain .txt
 * is passed through. Legacy .doc is not supported — the UI restricts uploads to
 * PDF/DOCX. Extracted text is normalised and length-capped before it lands in
 * profiles.resume_text.
 */
class ResumeParser
{
    /** Guard against pathological documents blowing up downstream prompt sizes. */
    private const MAX_CHARS = 60000;

    /**
     * @param  string  $absolutePath  A readable path to the uploaded file.
     * @param  string  $extension  Lower-cased file extension (pdf|docx|txt).
     *
     * @throws ResumeParseException
     */
    public function extractText(string $absolutePath, string $extension): string
    {
        $extension = strtolower($extension);

        $text = match ($extension) {
            'pdf' => $this->fromPdf($absolutePath),
            'docx' => $this->fromDocx($absolutePath),
            'txt' => (string) file_get_contents($absolutePath),
            default => throw new ResumeParseException("Unsupported resume format: .{$extension}"),
        };

        $text = $this->normalise($text);

        if ($text === '') {
            throw new ResumeParseException('No readable text could be extracted from the resume.');
        }

        return $text;
    }

    private function fromPdf(string $path): string
    {
        try {
            return (new PdfParser)->parseFile($path)->getText();
        } catch (Throwable $e) {
            throw new ResumeParseException('Could not read the PDF resume.', 0, $e);
        }
    }

    private function fromDocx(string $path): string
    {
        try {
            $document = IOFactory::load($path, 'Word2007');
        } catch (Throwable $e) {
            throw new ResumeParseException('Could not read the Word resume.', 0, $e);
        }

        $lines = [];
        foreach ($document->getSections() as $section) {
            $this->collectText($section, $lines);
        }

        return implode("\n", $lines);
    }

    /**
     * Recursively pull text out of a PhpWord container (sections, tables, rows,
     * cells, text runs). PhpWord has no top-level getText(), so we walk the tree.
     */
    private function collectText(AbstractContainer $container, array &$lines): void
    {
        foreach ($container->getElements() as $element) {
            if ($element instanceof AbstractContainer) {
                $this->collectText($element, $lines);

                continue;
            }

            if (method_exists($element, 'getText')) {
                $value = $element->getText();
                if (is_string($value) && trim($value) !== '') {
                    $lines[] = $value;
                }
            }
        }
    }

    private function normalise(string $text): string
    {
        // Collapse Windows/Mac line endings, trim trailing whitespace per line,
        // and squeeze runs of blank lines.
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+\n/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS);
        }

        return $text;
    }
}
