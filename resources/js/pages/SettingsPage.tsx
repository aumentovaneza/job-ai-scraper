import { type FormEvent, useState } from 'react';
import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AiKeyPanel } from '@/components/AiKeyPanel';
import { ProviderToggle } from '@/components/ProviderToggle';
import {
    type AiUsage,
    type ProfilePayload,
    useAiUsage,
    useProfile,
    useUpdateSettings,
} from '@/hooks/useProfile';

const TIMEZONES: string[] =
    typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : ['UTC'];

function dollars(cents: number): string {
    return `$${(cents / 100).toFixed(2)}`;
}

/**
 * Account settings (T-15): manage the AI provider + key, spend caps, and timezone,
 * plus a live "AI spend this week" indicator read from the ai_calls ledger.
 */
export default function SettingsPage() {
    const { data, isLoading } = useProfile();
    const usage = useAiUsage();

    if (isLoading || !data) {
        return (
            <div className="flex min-h-screen items-center justify-center text-muted-foreground">
                Loading…
            </div>
        );
    }

    return (
        <div className="mx-auto max-w-2xl space-y-8 p-8">
            <div className="flex items-center justify-between">
                <h1 className="text-2xl font-semibold tracking-tight">Settings</h1>
                <Link to="/" className="text-sm text-muted-foreground hover:text-foreground">
                    ← Back
                </Link>
            </div>

            <section className="space-y-3">
                <h2 className="font-medium">AI provider</h2>
                <ProviderToggle value={data.ai_provider} />
                <AiKeyPanel provider={data.ai_provider} status={data.providers[data.ai_provider]} />
            </section>

            {usage.data && <SpendIndicator usage={usage.data} />}

            <SettingsForm data={data} />
        </div>
    );
}

function SpendIndicator({ usage }: { usage: AiUsage }) {
    const { week } = usage;
    const cap = week.cap_cents;
    const pct = cap && cap > 0 ? Math.min(100, Math.round((week.spent_cents / cap) * 100)) : null;

    return (
        <section className="space-y-2 rounded-lg border p-4">
            <div className="flex items-baseline justify-between">
                <h2 className="font-medium">AI spend this week</h2>
                <p className="text-sm">
                    <span className="font-semibold">{dollars(week.spent_cents)}</span>
                    {cap != null ? ` / ${dollars(cap)} cap` : ' · no cap set'}
                </p>
            </div>
            {pct != null && (
                <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        className={`h-full ${pct >= 100 ? 'bg-destructive' : 'bg-primary'}`}
                        style={{ width: `${pct}%` }}
                    />
                </div>
            )}
            <p className="text-xs text-muted-foreground">Today: {dollars(usage.day.spent_cents)} spent.</p>
        </section>
    );
}

function SettingsForm({ data }: { data: ProfilePayload }) {
    const updateSettings = useUpdateSettings();

    const [timezone, setTimezone] = useState(data.settings.timezone);
    const [daily, setDaily] = useState(
        data.settings.daily_ai_spend_cap_cents != null
            ? String(data.settings.daily_ai_spend_cap_cents / 100)
            : ''
    );
    const [weekly, setWeekly] = useState(
        data.settings.weekly_ai_spend_cap_cents != null
            ? String(data.settings.weekly_ai_spend_cap_cents / 100)
            : ''
    );
    const [saved, setSaved] = useState(false);

    function toCents(value: string): number | null {
        if (value.trim() === '') return null;
        return Math.round(Number(value) * 100);
    }

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setSaved(false);
        await updateSettings.mutateAsync({
            timezone,
            daily_ai_spend_cap_cents: toCents(daily),
            weekly_ai_spend_cap_cents: toCents(weekly),
        });
        setSaved(true);
    }

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <h2 className="font-medium">Preferences</h2>

            <div className="space-y-1.5">
                <Label htmlFor="timezone">Timezone</Label>
                <select
                    id="timezone"
                    value={timezone}
                    onChange={(e) => setTimezone(e.target.value)}
                    className="flex h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                >
                    {TIMEZONES.map((tz) => (
                        <option key={tz} value={tz}>
                            {tz}
                        </option>
                    ))}
                </select>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                    <Label htmlFor="daily-cap">Daily cap (USD)</Label>
                    <Input
                        id="daily-cap"
                        type="number"
                        min={0}
                        step="0.01"
                        placeholder="No cap"
                        value={daily}
                        onChange={(e) => setDaily(e.target.value)}
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="weekly-cap">Weekly cap (USD)</Label>
                    <Input
                        id="weekly-cap"
                        type="number"
                        min={0}
                        step="0.01"
                        placeholder="No cap"
                        value={weekly}
                        onChange={(e) => setWeekly(e.target.value)}
                    />
                </div>
            </div>
            <p className="text-xs text-muted-foreground">
                Leave a cap blank for no limit. Work stops for that window once a cap is reached.
            </p>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={updateSettings.isPending}>
                    {updateSettings.isPending ? 'Saving…' : 'Save settings'}
                </Button>
                {saved && <span className="text-sm text-emerald-600">Saved.</span>}
            </div>
        </form>
    );
}
