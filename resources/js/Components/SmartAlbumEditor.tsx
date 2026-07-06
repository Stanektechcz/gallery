/**
 * SmartAlbumEditor — UI for managing smart album rules.
 * Shown in Albums/Show when album_type === 'smart'.
 */

import axios from 'axios';
import { Check, Loader2, Plus, RefreshCw, Sparkles, X } from 'lucide-react';
import { useState } from 'react';

interface Condition {
    field: string;
    op:    string;
    value: any;
}

interface SmartRules {
    match:      'all' | 'any';
    conditions: Condition[];
}

const FIELDS = [
    { value: 'rating',      label: '★ Hodnocení (min)',  op: 'gte',  type: 'number', placeholder: '4' },
    { value: 'is_favorite', label: '❤️ Oblíbené',        op: 'eq',   type: 'bool'   },
    { value: 'has_gps',     label: '📍 Má GPS',          op: 'eq',   type: 'bool'   },
    { value: 'media_type',  label: '📷 Typ média',       op: 'eq',   type: 'select', options: ['photo', 'video'] },
    { value: 'date_from',   label: '📅 Datum od',        op: 'gte',  type: 'date'   },
    { value: 'date_to',     label: '📅 Datum do',        op: 'lte',  type: 'date'   },
    { value: 'taken_year',  label: '📅 Rok pořízení',    op: 'eq',   type: 'number', placeholder: '2026' },
    { value: 'camera_make', label: '📸 Fotoaparát',      op: 'eq',   type: 'text',   placeholder: 'Apple' },
    { value: 'is_panorama', label: '🔭 Panorama',        op: 'eq',   type: 'bool'   },
    { value: 'is_360',      label: '🌐 360°',            op: 'eq',   type: 'bool'   },
    { value: 'is_raw',      label: '🎞 RAW',             op: 'eq',   type: 'bool'   },
    { value: 'min_width',   label: '📐 Min. šířka (px)', op: 'gte',  type: 'number', placeholder: '3000' },
];

const DEFAULT_EMPTY: SmartRules = { match: 'all', conditions: [] };

interface Preview { count: number; samples: { uuid: string; thumbnail_url: string }[] }

export default function SmartAlbumEditor({
    albumUuid, initialRules, albumType: initType, onSaved,
}: {
    albumUuid:   string;
    initialRules?: SmartRules | null;
    albumType:   string;
    onSaved:     (type: string, rules: SmartRules) => void;
}) {
    const [type,    setType]    = useState(initType ?? 'physical');
    const [rules,   setRules]   = useState<SmartRules>(initialRules ?? DEFAULT_EMPTY);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [loading, setLoading] = useState(false);
    const [saving,  setSaving]  = useState(false);

    const addCondition = () => {
        setRules(r => ({ ...r, conditions: [...r.conditions, { field: 'rating', op: 'gte', value: 4 }] }));
    };

    const removeCondition = (idx: number) => {
        setRules(r => ({ ...r, conditions: r.conditions.filter((_, i) => i !== idx) }));
    };

    const updateCondition = (idx: number, patch: Partial<Condition>) => {
        setRules(r => ({
            ...r,
            conditions: r.conditions.map((c, i) => i === idx ? { ...c, ...patch } : c),
        }));
    };

    const loadPreview = async () => {
        if (type !== 'smart') return;
        setLoading(true);
        // Save first, then preview
        try {
            await axios.put(`/api/v1/albums/${albumUuid}/smart-rules`, { album_type: type, smart_rules: rules });
            const r = await axios.get(`/api/v1/albums/${albumUuid}/smart-preview`);
            setPreview(r.data);
        } finally { setLoading(false); }
    };

    const save = async () => {
        setSaving(true);
        try {
            await axios.put(`/api/v1/albums/${albumUuid}/smart-rules`, { album_type: type, smart_rules: type === 'smart' ? rules : null });
            onSaved(type, rules);
        } finally { setSaving(false); }
    };

    const fieldMeta = (field: string) => FIELDS.find(f => f.value === field) ?? FIELDS[0];

    return (
        <div className="bg-[var(--color-bg-card)] border border-[var(--color-accent)]/30 rounded-xl p-4 mb-5">
            <div className="flex items-center gap-2 mb-4">
                <Sparkles size={15} className="text-[var(--color-accent)]"/>
                <h3 className="text-sm font-semibold text-white">Typ alba</h3>
            </div>

            {/* Type toggle */}
            <div className="flex gap-2 mb-4">
                {[
                    { k: 'physical', label: '📁 Klasické', desc: 'Fotky přidávate ručně' },
                    { k: 'smart',    label: '✨ Dynamické', desc: 'Automaticky dle pravidel' },
                ].map(({ k, label, desc }) => (
                    <button key={k} onClick={() => setType(k)}
                        className={`flex-1 text-left px-3 py-2.5 rounded-xl border-2 transition-colors ${type === k ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/10' : 'border-[var(--color-border)] hover:border-[var(--color-accent)]/40'}`}>
                        <p className={`text-sm font-semibold ${type === k ? 'text-[var(--color-accent)]' : 'text-white'}`}>{label}</p>
                        <p className="text-[10px] text-[var(--color-text-secondary)] mt-0.5">{desc}</p>
                    </button>
                ))}
            </div>

            {type === 'smart' && (
                <>
                    {/* Match mode */}
                    <div className="flex items-center gap-3 mb-3">
                        <span className="text-xs text-[var(--color-text-secondary)]">Zobrazit fotky kde platí</span>
                        <div className="flex gap-1">
                            {['all', 'any'].map(m => (
                                <button key={m} onClick={() => setRules(r => ({ ...r, match: m as any }))}
                                    className={`text-xs px-2 py-1 rounded-lg transition-colors ${rules.match === m ? 'bg-[var(--color-accent)] text-white' : 'border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'}`}>
                                    {m === 'all' ? 'VŠECHNA pravidla' : 'ALESPOŇ JEDNO pravidlo'}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Conditions */}
                    <div className="space-y-2 mb-3">
                        {rules.conditions.map((cond, idx) => {
                            const meta = fieldMeta(cond.field);
                            return (
                                <div key={idx} className="flex items-center gap-2 flex-wrap">
                                    {/* Field */}
                                    <select value={cond.field}
                                        onChange={e => { const m = fieldMeta(e.target.value); updateCondition(idx, { field: e.target.value, op: m.op, value: m.type === 'bool' ? true : m.type === 'number' ? 4 : '' }); }}
                                        className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-2 py-1.5 text-xs text-white outline-none focus:border-[var(--color-accent)]">
                                        {FIELDS.map(f => <option key={f.value} value={f.value}>{f.label}</option>)}
                                    </select>

                                    {/* Value */}
                                    {meta.type === 'bool' ? (
                                        <select value={String(cond.value)}
                                            onChange={e => updateCondition(idx, { value: e.target.value === 'true' })}
                                            className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-2 py-1.5 text-xs text-white outline-none focus:border-[var(--color-accent)]">
                                            <option value="true">Ano</option>
                                            <option value="false">Ne</option>
                                        </select>
                                    ) : meta.type === 'select' ? (
                                        <select value={String(cond.value)}
                                            onChange={e => updateCondition(idx, { value: e.target.value })}
                                            className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-2 py-1.5 text-xs text-white outline-none focus:border-[var(--color-accent)]">
                                            {meta.options!.map(o => <option key={o} value={o}>{o}</option>)}
                                        </select>
                                    ) : (
                                        <input value={String(cond.value ?? '')}
                                            onChange={e => updateCondition(idx, { value: meta.type === 'number' ? parseInt(e.target.value) || 0 : e.target.value })}
                                            type={meta.type === 'number' ? 'number' : meta.type === 'date' ? 'date' : 'text'}
                                            placeholder={meta.placeholder}
                                            className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-2 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)] w-28"/>
                                    )}

                                    <button onClick={() => removeCondition(idx)} className="p-1 text-[var(--color-text-secondary)] hover:text-red-400 transition-colors">
                                        <X size={12}/>
                                    </button>
                                </div>
                            );
                        })}
                    </div>

                    <button onClick={addCondition}
                        className="flex items-center gap-1.5 text-xs text-[var(--color-accent)] hover:text-[var(--color-accent)] border border-dashed border-[var(--color-accent)]/40 hover:border-[var(--color-accent)] px-3 py-1.5 rounded-lg transition-colors mb-4">
                        <Plus size={11}/> Přidat podmínku
                    </button>

                    {/* Preview */}
                    <div className="mb-4">
                        <button onClick={loadPreview} disabled={loading || rules.conditions.length === 0}
                            className="flex items-center gap-1.5 text-xs border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white px-3 py-1.5 rounded-lg transition-colors disabled:opacity-40">
                            <RefreshCw size={11} className={loading ? 'animate-spin' : ''}/> Náhled
                        </button>

                        {preview && (
                            <div className="mt-2 flex items-center gap-2 flex-wrap">
                                <span className="text-xs text-white font-medium">
                                    {preview.count} fotografií splňuje podmínky
                                </span>
                                {preview.samples.map(s => (
                                    <img key={s.uuid} src={s.thumbnail_url} alt="" className="w-8 h-8 rounded object-cover"/>
                                ))}
                                {preview.count > 6 && <span className="text-xs text-[var(--color-text-secondary)]">+{preview.count - 6}</span>}
                            </div>
                        )}
                    </div>
                </>
            )}

            <div className="flex gap-2">
                <button onClick={save} disabled={saving}
                    className="flex items-center gap-1.5 bg-[var(--color-accent)] text-white text-sm px-4 py-2 rounded-lg hover:opacity-90 disabled:opacity-40">
                    {saving ? <Loader2 size={13} className="animate-spin"/> : <Check size={13}/>}
                    Uložit
                </button>
            </div>
        </div>
    );
}
