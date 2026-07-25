import { type FormEvent, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AxiosError } from 'axios';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { AiKeyPanel } from '@/components/AiKeyPanel';
import { ProviderToggle } from '@/components/ProviderToggle';
import {
    type ProfilePayload,
    useExtractVoice,
    useProfile,
    useUpdateProfile,
    useUploadResume,
} from '@/hooks/useProfile';

const STEPS = ['AI provider', 'Resume', 'Targets'] as const;

/**
 * Post-invite onboarding wizard (T-12): verify your AI provider's API key, upload a resume,
 * then set target roles/locations/comp. The owner runs the same flow. Steps gate
 * on the server-computed onboarding status so the user can't skip a requirement.
 */
export default function OnboardingPage() {
    const navigate = useNavigate();
    const { data, isLoading } = useProfile();
    const [step, setStep] = useState(0);
    const jumped = useRef(false);

    // On first load, resume at the earliest incomplete step.
    useEffect(() => {
        if (!data || jumped.current) return;
        jumped.current = true;
        const { onboarding } = data;
        setStep(!onboarding.key_verified ? 0 : !onboarding.resume ? 1 : 2);
    }, [data]);

    if (isLoading || !data) {
        return (
            <div className="flex min-h-screen items-center justify-center text-muted-foreground">
                Loading…
            </div>
        );
    }

    const { onboarding } = data;
    const stepDone = [onboarding.key_verified, onboarding.resume, onboarding.targets];

    return (
        <div className="mx-auto max-w-xl space-y-8 p-8">
            <div className="space-y-1">
                <h1 className="text-2xl font-semibold tracking-tight">Set up JobScope</h1>
                <p className="text-sm text-muted-foreground">Three quick steps to get you matching.</p>
            </div>

            <ol className="flex items-center gap-2 text-sm">
                {STEPS.map((label, i) => (
                    <li key={label} className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setStep(i)}
                            className={`flex items-center gap-2 rounded-md px-2 py-1 ${
                                i === step ? 'bg-accent text-accent-foreground' : 'text-muted-foreground'
                            }`}
                        >
                            <span
                                className={`flex size-5 items-center justify-center rounded-full text-xs ${
                                    stepDone[i]
                                        ? 'bg-emerald-600 text-white'
                                        : 'border border-muted-foreground/40'
                                }`}
                            >
                                {stepDone[i] ? '✓' : i + 1}
                            </span>
                            {label}
                        </button>
                        {i < STEPS.length - 1 && <span className="text-muted-foreground">→</span>}
                    </li>
                ))}
            </ol>

            <div className="rounded-lg border p-5">
                {step === 0 && <KeyStep data={data} />}
                {step === 1 && <ResumeStep data={data} />}
                {step === 2 && <TargetsStep data={data} />}
            </div>

            <div className="flex items-center justify-between">
                <Button variant="ghost" onClick={() => setStep((s) => Math.max(0, s - 1))} disabled={step === 0}>
                    Back
                </Button>
                {step < STEPS.length - 1 ? (
                    <Button onClick={() => setStep((s) => s + 1)} disabled={!stepDone[step]}>
                        Continue
                    </Button>
                ) : (
                    <Button onClick={() => navigate('/', { replace: true })} disabled={!onboarding.complete}>
                        Go to JobScope
                    </Button>
                )}
            </div>
        </div>
    );
}

function KeyStep({ data }: { data: ProfilePayload }) {
    return (
        <div className="space-y-4">
            <div className="space-y-1">
                <h2 className="font-medium">Choose your AI provider</h2>
                <p className="text-sm text-muted-foreground">
                    JobScope uses your API key to enrich jobs and draft letters. You control the spend.
                </p>
            </div>
            <ProviderToggle value={data.ai_provider} />
            <AiKeyPanel provider={data.ai_provider} status={data.providers[data.ai_provider]} />
        </div>
    );
}

function ResumeStep({ data }: { data: ProfilePayload }) {
    const uploadResume = useUploadResume();
    const [error, setError] = useState<string | null>(null);

    async function handleFile(e: React.ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0];
        if (!file) return;
        setError(null);
        try {
            await uploadResume.mutateAsync(file);
        } catch (err) {
            const axiosErr = err as AxiosError<{ message?: string }>;
            setError(axiosErr.response?.data?.message ?? 'Could not read that file. Use a PDF or DOCX.');
        } finally {
            e.target.value = '';
        }
    }

    return (
        <div className="space-y-4">
            <div className="space-y-1">
                <h2 className="font-medium">Upload your resume</h2>
                <p className="text-sm text-muted-foreground">PDF or DOCX. We extract the text for matching.</p>
            </div>

            {data.profile.resume_chars > 0 && (
                <p className="text-sm text-emerald-600">
                    Resume stored ({data.profile.resume_chars.toLocaleString()} characters). Upload again to replace.
                </p>
            )}

            <Input
                type="file"
                accept=".pdf,.docx"
                onChange={handleFile}
                disabled={uploadResume.isPending}
            />
            {uploadResume.isPending && <p className="text-sm text-muted-foreground">Reading resume…</p>}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}

function TargetsStep({ data }: { data: ProfilePayload }) {
    const updateProfile = useUpdateProfile();
    const extractVoice = useExtractVoice();

    const [roles, setRoles] = useState(data.profile.target_roles.join(', '));
    const [locations, setLocations] = useState(data.profile.target_locations.join(', '));
    const [comp, setComp] = useState(
        data.profile.target_comp_min_cents ? String(Math.round(data.profile.target_comp_min_cents / 100)) : ''
    );
    const [saved, setSaved] = useState(false);
    const [writingSample, setWritingSample] = useState('');
    const [voiceMessage, setVoiceMessage] = useState<string | null>(null);

    function splitList(value: string): string[] {
        return value
            .split(',')
            .map((s) => s.trim())
            .filter((s) => s.length > 0);
    }

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setSaved(false);
        await updateProfile.mutateAsync({
            target_roles: splitList(roles),
            target_locations: splitList(locations),
            target_comp_min_cents: comp ? Math.round(Number(comp) * 100) : null,
        });
        setSaved(true);
    }

    async function handleVoice() {
        setVoiceMessage(null);
        try {
            await extractVoice.mutateAsync(writingSample.trim() || null);
            setVoiceMessage('Voice profile is generating — it appears in Settings shortly.');
        } catch (err) {
            const axiosErr = err as AxiosError<{ message?: string }>;
            setVoiceMessage(axiosErr.response?.data?.message ?? 'Could not start voice profile generation.');
        }
    }

    return (
        <div className="space-y-5">
            <form onSubmit={handleSubmit} className="space-y-4">
                <div className="space-y-1">
                    <h2 className="font-medium">What are you looking for?</h2>
                    <p className="text-sm text-muted-foreground">Comma-separated. You can refine these later.</p>
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="roles">Target roles</Label>
                    <Input
                        id="roles"
                        placeholder="Platform Engineer, Backend Engineer"
                        value={roles}
                        onChange={(e) => setRoles(e.target.value)}
                    />
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="locations">Target locations</Label>
                    <Input
                        id="locations"
                        placeholder="Remote, Berlin"
                        value={locations}
                        onChange={(e) => setLocations(e.target.value)}
                    />
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="comp">Minimum comp (USD / year)</Label>
                    <Input
                        id="comp"
                        type="number"
                        min={0}
                        placeholder="150000"
                        value={comp}
                        onChange={(e) => setComp(e.target.value)}
                    />
                </div>

                <div className="flex items-center gap-3">
                    <Button type="submit" disabled={updateProfile.isPending}>
                        {updateProfile.isPending ? 'Saving…' : 'Save targets'}
                    </Button>
                    {saved && <span className="text-sm text-emerald-600">Saved.</span>}
                </div>
            </form>

            <details className="rounded-md border p-3">
                <summary className="cursor-pointer text-sm font-medium">
                    Optional: teach JobScope your writing voice
                </summary>
                <div className="mt-3 space-y-3">
                    <p className="text-sm text-muted-foreground">
                        Paste a short writing sample (a past cover letter, a bio). We analyse it with your key so
                        drafted letters sound like you. Uses your resume if left blank.
                    </p>
                    <Textarea
                        rows={4}
                        placeholder="Paste a paragraph or two you wrote…"
                        value={writingSample}
                        onChange={(e) => setWritingSample(e.target.value)}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        onClick={handleVoice}
                        disabled={extractVoice.isPending}
                    >
                        {extractVoice.isPending ? 'Starting…' : 'Generate voice profile'}
                    </Button>
                    {voiceMessage && <p className="text-sm text-muted-foreground">{voiceMessage}</p>}
                </div>
            </details>
        </div>
    );
}
