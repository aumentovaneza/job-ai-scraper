import { create } from 'zustand';
import { api } from '@/lib/api';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
}

type AuthStatus = 'idle' | 'loading' | 'authenticated' | 'unauthenticated';

interface AuthState {
    user: AuthUser | null;
    status: AuthStatus;
    /** Hydrate the session on app load (GET /api/me). */
    fetchMe: () => Promise<void>;
    login: (email: string, password: string) => Promise<void>;
    logout: () => Promise<void>;
}

export const useAuthStore = create<AuthState>((set) => ({
    user: null,
    status: 'idle',

    fetchMe: async () => {
        set({ status: 'loading' });
        try {
            const { data } = await api.get('/api/me');
            set({ user: data.user, status: 'authenticated' });
        } catch {
            set({ user: null, status: 'unauthenticated' });
        }
    },

    login: async (email, password) => {
        await api.get('/sanctum/csrf-cookie');
        const { data } = await api.post('/api/login', { email, password });
        set({ user: data.user, status: 'authenticated' });
    },

    logout: async () => {
        await api.post('/api/logout');
        set({ user: null, status: 'unauthenticated' });
    },
}));
