import { create } from 'zustand';

/**
 * Client-only UI state. Placeholder shell store for the SPA scaffold;
 * feature stores (auth, filters, …) will live alongside this file.
 */
interface AppState {
    sidebarOpen: boolean;
    toggleSidebar: () => void;
}

export const useAppStore = create<AppState>((set) => ({
    sidebarOpen: true,
    toggleSidebar: () => set((state) => ({ sidebarOpen: !state.sidebarOpen })),
}));
