<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The weekly digest email (T-63): the top new matches from the past week plus the
 * Claude-written insights summary and headline conversion numbers. Sent by
 * GenerateWeeklyDigestJob; delivery uses whatever mailer is configured (log in
 * dev, Mailtrap in staging, Resend in prod).
 */
class WeeklyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $totals  Headline figures from AnalyticsService.
     * @param  list<array{title: string, company: string, score: ?int, apply_url: ?string}>  $topMatches
     */
    public function __construct(
        public readonly User $user,
        public readonly ?string $summaryMarkdown,
        public readonly array $totals,
        public readonly array $topMatches,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->topMatches);
        $subject = $count > 0
            ? "Your weekly job digest — {$count} new ".str('match')->plural($count)
            : 'Your weekly job digest';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.weekly-digest',
            with: [
                'name' => $this->user->name,
                'summaryHtml' => $this->summaryMarkdown !== null
                    ? nl2br(e(trim($this->summaryMarkdown)))
                    : null,
                'totals' => $this->totals,
                'topMatches' => $this->topMatches,
            ],
        );
    }
}
