import { type FormEvent, useState } from 'react';
import { Link } from 'react-router-dom';
import { AxiosError } from 'axios';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { MailPlus } from 'lucide-react';
import { EmptyState } from '@/components/EmptyState';
import { ErrorState } from '@/components/ErrorState';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { api } from '@/lib/api';

interface Invitation {
    id: number;
    email: string;
    token: string;
    expires_at: string;
    accepted_at: string | null;
}

const INVITATIONS_KEY = ['invitations'] as const;

function inviteLink(token: string): string {
    return `${window.location.origin}/invite/${token}`;
}

export default function InvitationsPage() {
    const queryClient = useQueryClient();
    const [email, setEmail] = useState('');
    const [formError, setFormError] = useState<string | null>(null);

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: INVITATIONS_KEY,
        queryFn: async () => {
            const { data } = await api.get<{ invitations: Invitation[] }>('/api/invitations');
            return data.invitations;
        },
    });

    const createInvite = useMutation({
        mutationFn: async (email: string) => {
            await api.get('/sanctum/csrf-cookie');
            const { data } = await api.post<{ invitation: Invitation }>('/api/invitations', { email });
            return data.invitation;
        },
        onSuccess: () => {
            setEmail('');
            setFormError(null);
            queryClient.invalidateQueries({ queryKey: INVITATIONS_KEY });
        },
        onError: (err: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
            const firstError = err.response?.data?.errors?.email?.[0];
            setFormError(firstError ?? err.response?.data?.message ?? 'Could not create invitation.');
        },
    });

    const revokeInvite = useMutation({
        mutationFn: async (id: number) => {
            await api.get('/sanctum/csrf-cookie');
            await api.delete(`/api/invitations/${id}`);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: INVITATIONS_KEY }),
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        createInvite.mutate(email);
    }

    return (
        <div className="mx-auto max-w-2xl space-y-8 p-8">
            <div className="flex items-center justify-between">
                <h1 className="text-2xl font-semibold tracking-tight">Invitations</h1>
                <Link to="/" className="text-sm text-muted-foreground hover:text-foreground">
                    ← Back
                </Link>
            </div>

            <form onSubmit={handleSubmit} className="flex gap-2">
                <Input
                    type="email"
                    placeholder="invitee@example.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    required
                />
                <Button type="submit" disabled={createInvite.isPending}>
                    {createInvite.isPending ? 'Inviting…' : 'Invite'}
                </Button>
            </form>
            {formError && <p className="-mt-6 text-sm text-destructive">{formError}</p>}

            <div className="space-y-3">
                {isLoading && (
                    <>
                        <Skeleton className="h-16 w-full" />
                        <Skeleton className="h-16 w-full" />
                        <Skeleton className="h-16 w-full" />
                    </>
                )}
                {isError && (
                    <ErrorState message="Could not load invitations." onRetry={() => void refetch()} />
                )}
                {!isLoading && !isError && data?.length === 0 && (
                    <EmptyState
                        icon={MailPlus}
                        title="No invitations yet"
                        description="Invite someone with the form above to get started."
                    />
                )}
                {data?.map((invite) => {
                    const accepted = invite.accepted_at !== null;
                    const expired = !accepted && new Date(invite.expires_at) < new Date();
                    const label = accepted ? 'Accepted' : expired ? 'Expired' : 'Pending';

                    return (
                        <div
                            key={invite.id}
                            className="flex items-center justify-between rounded-md border p-3"
                        >
                            <div className="space-y-1">
                                <p className="text-sm font-medium">{invite.email}</p>
                                <p className="text-xs text-muted-foreground">{label}</p>
                            </div>
                            <div className="flex items-center gap-2">
                                {!accepted && !expired && (
                                    <Button
                                        variant="outline"
                                        onClick={() => void navigator.clipboard?.writeText(inviteLink(invite.token))}
                                    >
                                        Copy link
                                    </Button>
                                )}
                                {!accepted && (
                                    <Button
                                        variant="outline"
                                        onClick={() => revokeInvite.mutate(invite.id)}
                                        disabled={revokeInvite.isPending}
                                    >
                                        Revoke
                                    </Button>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
