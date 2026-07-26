/**
 * Minimal, dependency-free Markdown helpers for the cover-letter editor
 * (T-53) and export/copy actions (T-55). Letters are prose — paragraphs
 * separated by blank lines, with occasional bold or italic emphasis — so a
 * hand-rolled parser is enough; no markdown-rendering dependency is added.
 */

/** Split Markdown into paragraph blocks on blank lines. */
export function splitParagraphs(markdown: string): string[] {
    return markdown
        .split(/\n\s*\n/)
        .map((p) => p.trim())
        .filter((p) => p.length > 0);
}

/** Rejoin paragraph blocks the way splitParagraphs expects them. */
export function joinParagraphs(paragraphs: string[]): string {
    return paragraphs.join('\n\n');
}

/**
 * Best-effort Markdown → plain text: drop the common inline/block markers so
 * copy/paste reads cleanly. Mirrors the backend's export stripping
 * (CoverLetterExportController::toPlainText) so copy and download agree.
 */
export function toPlainText(markdown: string): string {
    let text = markdown;
    text = text.replace(/^#{1,6}\s+/gm, '');
    text = text.replace(/^\s*[-*+]\s+/gm, '');
    text = text.replace(/(\*\*|__)([\s\S]*?)\1/g, '$2');
    text = text.replace(/(\*|_)([\s\S]*?)\1/g, '$2');
    text = text.replace(/`([^`]*)`/g, '$1');
    text = text.replace(/\[([^\]]+)\]\(([^)]*)\)/g, '$1');
    return text.trim();
}

function escapeHtml(value: string): string {
    return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** Render minimal inline Markdown (**bold**, *italic*) to escaped, safe HTML. */
export function renderInlineHtml(text: string): string {
    let html = escapeHtml(text);
    html = html.replace(/(\*\*|__)([\s\S]+?)\1/g, '<strong>$2</strong>');
    html = html.replace(/(\*|_)([\s\S]+?)\1/g, '<em>$2</em>');
    return html.replace(/\n/g, '<br />');
}

/** Build a `<p>`-per-paragraph HTML fragment for rich-text clipboard copy. */
export function toHtml(markdown: string): string {
    return splitParagraphs(markdown)
        .map((p) => `<p>${renderInlineHtml(p)}</p>`)
        .join('');
}

const VARIANT_LABELS: Record<string, string> = {
    story_led: 'Story-led',
    results_led: 'Results-led',
    culture_led: 'Culture-led',
    custom: 'Custom',
};

/** Humanize a variant_label ('story_led' -> 'Story-led') for display. */
export function humanizeVariant(variant: string): string {
    return VARIANT_LABELS[variant] ?? variant;
}

/**
 * Copy the letter to the clipboard as rich text (HTML + a plain-text
 * fallback) when the browser supports the async Clipboard `write` API,
 * falling back to plain text otherwise.
 */
export async function copyRichText(markdown: string): Promise<void> {
    const html = toHtml(markdown);
    const plain = toPlainText(markdown);

    if (typeof ClipboardItem !== 'undefined' && navigator.clipboard?.write) {
        const item = new ClipboardItem({
            'text/html': new Blob([html], { type: 'text/html' }),
            'text/plain': new Blob([plain], { type: 'text/plain' }),
        });
        await navigator.clipboard.write([item]);
        return;
    }

    await navigator.clipboard.writeText(plain);
}

export async function copyPlainText(markdown: string): Promise<void> {
    await navigator.clipboard.writeText(toPlainText(markdown));
}
