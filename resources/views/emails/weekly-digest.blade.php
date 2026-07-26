<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your weekly job digest</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e4e4e7;">
                    <tr>
                        <td style="padding:28px 32px 8px 32px;">
                            <h1 style="margin:0; font-size:20px; font-weight:700;">Your weekly job digest</h1>
                            <p style="margin:8px 0 0 0; font-size:14px; color:#71717a;">Hi {{ $name }}, here's how your search is going.</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    @php
                                        $responseRate = $totals['response_rate'] !== null ? round($totals['response_rate'] * 100).'%' : 'n/a';
                                    @endphp
                                    <td style="width:33%; padding:12px; background-color:#fafafa; border-radius:8px; text-align:center;">
                                        <div style="font-size:22px; font-weight:700;">{{ $totals['applied'] }}</div>
                                        <div style="font-size:12px; color:#71717a;">Applications</div>
                                    </td>
                                    <td style="width:8px;">&nbsp;</td>
                                    <td style="width:33%; padding:12px; background-color:#fafafa; border-radius:8px; text-align:center;">
                                        <div style="font-size:22px; font-weight:700;">{{ $responseRate }}</div>
                                        <div style="font-size:12px; color:#71717a;">Response rate</div>
                                    </td>
                                    <td style="width:8px;">&nbsp;</td>
                                    <td style="width:33%; padding:12px; background-color:#fafafa; border-radius:8px; text-align:center;">
                                        <div style="font-size:22px; font-weight:700;">{{ $totals['in_progress'] }}</div>
                                        <div style="font-size:12px; color:#71717a;">In progress</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    @if ($summaryHtml)
                        <tr>
                            <td style="padding:8px 32px 16px 32px;">
                                <h2 style="margin:0 0 8px 0; font-size:15px; font-weight:700;">This week's read</h2>
                                <div style="font-size:14px; line-height:1.6; color:#3f3f46;">{!! $summaryHtml !!}</div>
                            </td>
                        </tr>
                    @endif

                    @if (count($topMatches) > 0)
                        <tr>
                            <td style="padding:8px 32px 24px 32px;">
                                <h2 style="margin:0 0 12px 0; font-size:15px; font-weight:700;">Top new matches</h2>
                                @foreach ($topMatches as $match)
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px; border:1px solid #e4e4e7; border-radius:8px;">
                                        <tr>
                                            <td style="padding:12px 14px;">
                                                <div style="font-size:14px; font-weight:600;">
                                                    @if (!empty($match['apply_url']))
                                                        <a href="{{ $match['apply_url'] }}" style="color:#18181b; text-decoration:none;">{{ $match['title'] }}</a>
                                                    @else
                                                        {{ $match['title'] }}
                                                    @endif
                                                </div>
                                                <div style="font-size:13px; color:#71717a; margin-top:2px;">{{ $match['company'] }}</div>
                                            </td>
                                            <td style="padding:12px 14px; text-align:right; white-space:nowrap;">
                                                @if ($match['score'] !== null)
                                                    <span style="display:inline-block; padding:3px 10px; background-color:#18181b; color:#ffffff; border-radius:999px; font-size:12px; font-weight:600;">{{ $match['score'] }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    </table>
                                @endforeach
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td style="padding:16px 32px 28px 32px; border-top:1px solid #f4f4f5;">
                            <a href="{{ config('app.url') }}/insights" style="display:inline-block; padding:10px 18px; background-color:#18181b; color:#ffffff; border-radius:8px; font-size:14px; font-weight:600; text-decoration:none;">Open your insights</a>
                            <p style="margin:16px 0 0 0; font-size:12px; color:#a1a1aa;">You're receiving this because you have an active JobScope account.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
