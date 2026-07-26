<?php

namespace App\Http\Controllers;

use App\Models\CoverLetterVersion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Letter export (T-55): download a version's Markdown body as .docx, .txt, .md,
 * or .pdf. User-scoped via the BelongsToUser scope + CoverLetterVersionPolicy.
 */
class CoverLetterExportController extends Controller
{
    /** Export formats supported by this deployment (PDF via barryvdh/laravel-dompdf). */
    private const FORMATS = ['docx', 'txt', 'md', 'pdf'];

    public function export(Request $request, CoverLetterVersion $version): Response|StreamedResponse
    {
        $this->authorize('view', $version);

        $format = (string) $request->query('format', 'txt');

        if (! in_array($format, self::FORMATS, true)) {
            abort(422, 'Unsupported export format.');
        }

        $markdown = (string) $version->content_md;
        $filename = 'cover-letter-'.$this->companySlug($version).'.'.$format;

        return match ($format) {
            'md' => response($markdown, 200, [
                'Content-Type' => 'text/markdown',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]),
            'txt' => response($this->toPlainText($markdown), 200, [
                'Content-Type' => 'text/plain',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]),
            'docx' => $this->downloadDocx($markdown, $filename),
            'pdf' => $this->downloadPdf($markdown, $filename),
        };
    }

    /**
     * Stream a .docx built from the Markdown — one paragraph per blank-line block.
     */
    private function downloadDocx(string $markdown, string $filename): StreamedResponse
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();

        foreach ($this->paragraphs($markdown) as $paragraph) {
            $section->addText($paragraph);
            $section->addTextBreak();
        }

        return response()->streamDownload(function () use ($phpWord): void {
            IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * Render the Markdown paragraphs to a simple PDF via dompdf.
     */
    private function downloadPdf(string $markdown, string $filename): Response
    {
        $html = collect($this->paragraphs($markdown))
            ->map(fn (string $p): string => '<p>'.e($p).'</p>')
            ->implode('');

        return Pdf::loadHtml('<html><body style="font-family: serif; font-size: 12pt;">'.$html.'</body></html>')
            ->download($filename);
    }

    /**
     * Split Markdown into paragraph blocks on blank lines, stripping inline
     * Markdown to plain text for the docx/pdf renderers.
     *
     * @return list<string>
     */
    private function paragraphs(string $markdown): array
    {
        $blocks = preg_split('/\n\s*\n/', trim($this->toPlainText($markdown))) ?: [];

        return collect($blocks)
            ->map(fn (string $block): string => trim(preg_replace('/\s+/', ' ', $block) ?? $block))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Best-effort Markdown → plain text: drop the common inline/block markers so
     * exports read cleanly. Not a full Markdown parser — letters are prose.
     */
    private function toPlainText(string $markdown): string
    {
        $text = $markdown;
        $text = preg_replace('/^#{1,6}\s+/m', '', $text) ?? $text;      // headings
        $text = preg_replace('/^\s*[-*+]\s+/m', '', $text) ?? $text;    // bullets
        $text = preg_replace('/(\*\*|__)(.*?)\1/s', '$2', $text) ?? $text; // bold
        $text = preg_replace('/(\*|_)(.*?)\1/s', '$2', $text) ?? $text; // italics
        $text = preg_replace('/`([^`]*)`/', '$1', $text) ?? $text;      // inline code
        $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text) ?? $text; // links

        return trim($text);
    }

    private function companySlug(CoverLetterVersion $version): string
    {
        $company = $version->coverLetter?->application?->jobPosting?->company;

        return Str::slug((string) $company) ?: 'letter';
    }
}
