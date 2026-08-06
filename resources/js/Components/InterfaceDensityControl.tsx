import { useViewportSafePanel } from '@/lib/useViewportSafePanel';
import axios from 'axios';
import { Check, SlidersHorizontal } from 'lucide-react';
import { useEffect, useState } from 'react';

type Density = 'comfortable' | 'standard' | 'compact';
const options: Array<{ value: Density; label: string; note: string }> = [
    { value: 'comfortable', label: 'Komfortní', note: 'větší text a více prostoru' },
    { value: 'standard', label: 'Standardní', note: 'vyvážené rozložení' },
    { value: 'compact', label: 'Kompaktní', note: 'více informací na ploše' },
];
const valid = (value: unknown): value is Density => value === 'comfortable' || value === 'standard' || value === 'compact';

export default function InterfaceDensityControl({ initial }: { initial?: unknown }) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const panel = useViewportSafePanel(open);
    const [density, setDensity] = useState<Density>(() => {
        const stored = localStorage.getItem('maki-interface-density');
        return valid(initial) ? initial : (valid(stored) ? stored : 'standard');
    });

    useEffect(() => {
        document.documentElement.dataset.interfaceDensity = density;
        localStorage.setItem('maki-interface-density', density);
    }, [density]);

    const choose = async (value: Density) => {
        if (value === density) { setOpen(false); return; }
        const previous = density;
        setDensity(value); setBusy(true);
        try { await axios.patch('/api/v1/user-preferences', { interface_density: value }); }
        catch { setDensity(previous); }
        finally { setBusy(false); setOpen(false); }
    };

    return <div className="relative">
        <button type="button" aria-label="Hustota rozhraní" aria-expanded={open} onClick={() => setOpen(value => !value)} className="flex h-9 w-9 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"><SlidersHorizontal size={16}/></button>
        {open && <><button type="button" aria-label="Zavřít nabídku hustoty" onClick={() => setOpen(false)} className="fixed inset-0 z-40 cursor-default"/><div ref={panel.ref} style={panel.style} className="absolute bottom-full z-50 mb-2 w-64 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2 shadow-2xl"><p className="px-2 py-1 text-xs font-medium text-[var(--color-text-primary)]">Hustota rozhraní</p>{options.map(option => <button key={option.value} type="button" disabled={busy} onClick={() => void choose(option.value)} className={`mt-1 flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left disabled:opacity-50 ${density === option.value ? 'bg-[var(--color-accent)]/15 text-[var(--color-accent-contrast)]' : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]'}`}><span className="flex h-5 w-5 items-center justify-center">{density === option.value && <Check size={14}/>}</span><span><span className="block text-xs">{option.label}</span><span className="block text-[10px] opacity-75">{option.note}</span></span></button>)}</div></>}
    </div>;
}