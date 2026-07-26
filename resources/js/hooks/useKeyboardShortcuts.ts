import { useEffect } from 'react';

type ShortcutHandler = (event: KeyboardEvent) => void;

interface Options {
    /** Disable the bindings (e.g. while a modal/dialog owns the keyboard). */
    enabled?: boolean;
}

/**
 * Bind single-key shortcuts to handlers (T-73). Keys are matched case-
 * insensitively against `event.key`. Bindings are ignored while the user is
 * typing in an input/textarea/select or a contentEditable element, and when any
 * modifier (⌘/Ctrl/Alt) is held, so they never fight with normal editing or
 * browser shortcuts.
 *
 * The map is read fresh on every keydown, so callers can pass an inline object
 * that closes over current state without re-binding listeners each render.
 */
export function useKeyboardShortcuts(
    shortcuts: Record<string, ShortcutHandler>,
    { enabled = true }: Options = {}
): void {
    useEffect(() => {
        if (!enabled) return;

        function onKeyDown(event: KeyboardEvent) {
            if (event.metaKey || event.ctrlKey || event.altKey) return;

            const target = event.target as HTMLElement | null;
            if (target) {
                const tag = target.tagName;
                if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable) {
                    return;
                }
            }

            const handler = shortcuts[event.key] ?? shortcuts[event.key.toLowerCase()];
            if (handler) {
                event.preventDefault();
                handler(event);
            }
        }

        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [enabled, shortcuts]);
}
