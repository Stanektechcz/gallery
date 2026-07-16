import { usePwaInstall } from '@/Contexts/PwaInstallContext';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    BellRing,
    CalendarHeart,
    Check,
    ChevronRight,
    Cloud,
    Copy,
    Download,
    Heart,
    Images,
    LoaderCircle,
    MapPinned,
    RefreshCw,
    Share2,
    ShieldCheck,
    Smartphone,
    Sparkles,
} from 'lucide-react';
import { useMemo, useState } from 'react';

interface AndroidPackage {
    available: boolean;
    download_url: string;
    version: string;
    package_name: string;
    sha256: string | null;
    size_bytes: number | null;
    verified_origin: boolean;
}

interface PageProps {
    android: AndroidPackage;
    apkStatus: string;
    auth?: { user?: { name?: string } | null };
}

function formatBytes(bytes: number | null): string | null {
    if (!bytes || bytes < 1) return null;
    if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
    if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    return `${Math.ceil(bytes / 1024)} kB`;
}

const BENEFITS = [
    { icon: Images, title: 'Galerie bez čekání', text: 'Fotky, videa, alba a vzpomínky v rozhraní navrženém pro dotyk.' },
    { icon: CalendarHeart, title: 'Plány pro nás dva', text: 'Kalendář, úkoly, výročí, randíčka i upozornění na jednom místě.' },
    { icon: MapPinned, title: 'Cesty v kapse', text: 'Trasy, rozpočty, jízdenky, místa a itinerář jsou propojené se zážitky.' },
    { icon: RefreshCw, title: 'Vždy aktuální', text: 'Aplikace používá stejný systém a data jako web. Novinky se načtou automaticky.' },
];

export default function MobileAppIndex({ android, apkStatus }: PageProps) {
    const page = usePage<PageProps>();
    const { canInstall, installed, installing, install } = usePwaInstall();
    const [shareState, setShareState] = useState<'idle' | 'copied' | 'shared'>('idle');
    const size = useMemo(() => formatBytes(android.size_bytes), [android.size_bytes]);
    const isAuthenticated = Boolean(page.props.auth?.user);

    const installWebApp = async () => {
        if (installed) {
            window.location.assign('/');
            return;
        }
        if (canInstall) {
            await install();
            return;
        }
        document.getElementById('installation-help')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const share = async () => {
        const url = `${window.location.origin}/app`;
        try {
            if (navigator.share) {
                await navigator.share({
                    title: 'Maki – naše společné zážitky',
                    text: 'Nainstaluj si naši partnerskou aplikaci Maki.',
                    url,
                });
                setShareState('shared');
            } else {
                await navigator.clipboard.writeText(url);
                setShareState('copied');
            }
            window.setTimeout(() => setShareState('idle'), 2200);
        } catch (error) {
            if ((error as DOMException)?.name !== 'AbortError') setShareState('idle');
        }
    };

    return (
        <>
            <Head title="Aplikace pro Android">
                <meta name="description" content="Nainstalujte si Maki do telefonu nebo tabletu – partnerskou galerii, kalendář, cestování a společné zážitky."/>
            </Head>

            <main className="relative min-h-screen overflow-hidden bg-[#0f1020] text-white">
                <div aria-hidden="true" className="pointer-events-none absolute inset-0">
                    <div className="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-violet-600/25 blur-3xl"/>
                    <div className="absolute right-[-8rem] top-[28rem] h-[30rem] w-[30rem] rounded-full bg-fuchsia-500/15 blur-3xl"/>
                    <div className="absolute bottom-[-10rem] left-1/3 h-96 w-96 rounded-full bg-sky-500/10 blur-3xl"/>
                </div>

                <header className="safe-area-pt relative z-10 mx-auto flex w-full max-w-7xl items-center justify-between gap-3 px-4 py-4 sm:px-6 lg:px-8">
                    <Link href={isAuthenticated ? '/' : '/login'} className="group inline-flex items-center gap-3 rounded-2xl outline-none focus-visible:ring-2 focus-visible:ring-violet-400">
                        <img src="/icons/maki-app.svg" alt="" className="h-11 w-11 rounded-xl shadow-lg shadow-violet-500/20"/>
                        <div>
                            <p className="text-sm font-bold tracking-wide">Maki</p>
                            <p className="text-[10px] text-white/50">naše společné zážitky</p>
                        </div>
                    </Link>
                    <div className="flex items-center gap-2">
                        <button type="button" onClick={() => void share()} className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3 text-xs font-medium text-white/75 backdrop-blur transition hover:bg-white/10 hover:text-white">
                            {shareState === 'idle' ? <Share2 size={16}/> : <Check size={16} className="text-emerald-300"/>}
                            <span className="hidden sm:inline">{shareState === 'copied' ? 'Odkaz zkopírován' : shareState === 'shared' ? 'Sdíleno' : 'Sdílet aplikaci'}</span>
                        </button>
                        <Link href={isAuthenticated ? '/' : '/login'} className="inline-flex min-h-11 items-center gap-1.5 rounded-xl bg-white px-3 text-xs font-semibold text-[#17172b] transition hover:bg-violet-50">
                            {isAuthenticated ? 'Otevřít Maki' : 'Přihlásit se'} <ChevronRight size={15}/>
                        </Link>
                    </div>
                </header>

                <section className="relative z-[1] mx-auto grid w-full max-w-7xl items-center gap-12 px-4 pb-20 pt-10 sm:px-6 md:pt-16 lg:grid-cols-[1.08fr_.92fr] lg:px-8 lg:pb-28">
                    <div className="max-w-2xl">
                        <div className="mb-5 inline-flex items-center gap-2 rounded-full border border-violet-300/15 bg-violet-400/10 px-3 py-1.5 text-[11px] font-medium text-violet-100">
                            <Sparkles size={14}/> Telefon, tablet i web. Jedna společná Maki.
                        </div>
                        <h1 className="text-balance text-4xl font-black leading-[1.05] tracking-[-0.04em] sm:text-5xl lg:text-6xl">
                            Všechny naše zážitky <span className="bg-gradient-to-r from-violet-300 via-fuchsia-300 to-rose-300 bg-clip-text text-transparent">krásně v kapse.</span>
                        </h1>
                        <p className="mt-6 max-w-xl text-base leading-relaxed text-white/65 sm:text-lg">
                            Galerie, cesty, společný kalendář, vzpomínky, finance, recepty i plány pro dva. Aplikace je propojená s webem, takže vždy vidíte stejná aktuální data.
                        </p>

                        {apkStatus === 'preparing' && (
                            <div className="mt-5 flex max-w-xl items-start gap-3 rounded-2xl border border-amber-300/20 bg-amber-400/10 p-3 text-xs leading-relaxed text-amber-50">
                                <ShieldCheck size={18} className="mt-0.5 shrink-0 text-amber-200"/>
                                Přímý Android balíček ještě na serveru není. Bezpečná instalace webové aplikace je dostupná níže; APK se na tomto odkazu objeví po nahrání podepsané verze.
                            </div>
                        )}

                        <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                            <button type="button" onClick={() => void installWebApp()} disabled={installing} className="inline-flex min-h-14 items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-violet-500 to-fuchsia-500 px-6 text-sm font-bold shadow-xl shadow-violet-700/25 transition hover:-translate-y-0.5 hover:shadow-violet-600/35 disabled:opacity-60">
                                {installing ? <LoaderCircle size={20} className="animate-spin"/> : installed ? <Check size={20}/> : <Download size={20}/>}
                                {installing ? 'Otevírám instalaci…' : installed ? 'Otevřít nainstalovanou aplikaci' : canInstall ? 'Nainstalovat jedním klepnutím' : 'Jak aplikaci nainstalovat'}
                            </button>
                            {android.available ? (
                                <a href={android.download_url} className="inline-flex min-h-14 items-center justify-center gap-2 rounded-2xl border border-white/15 bg-white/[0.06] px-6 text-sm font-semibold backdrop-blur transition hover:-translate-y-0.5 hover:bg-white/10">
                                    <Smartphone size={20}/> Stáhnout Android APK
                                </a>
                            ) : (
                                <a href={android.download_url} className="inline-flex min-h-14 items-center justify-center gap-2 rounded-2xl border border-white/10 bg-white/[0.03] px-6 text-sm font-medium text-white/45 transition hover:bg-white/[0.06] hover:text-white/70">
                                    <Smartphone size={20}/> Přímé APK · čeká na podpis
                                </a>
                            )}
                        </div>
                        <div className="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-[11px] text-white/45">
                            <span className="inline-flex items-center gap-1.5"><ShieldCheck size={13}/> Soukromá data zůstávají chráněná</span>
                            <span className="inline-flex items-center gap-1.5"><Cloud size={13}/> Aktuální stav webu bez dvojí správy</span>
                            <span className="inline-flex items-center gap-1.5"><BellRing size={13}/> Partnerská upozornění</span>
                        </div>
                    </div>

                    <div className="relative mx-auto w-full max-w-md lg:justify-self-end">
                        <div aria-hidden="true" className="absolute inset-8 rounded-[4rem] bg-violet-500/25 blur-3xl"/>
                        <div className="relative mx-auto w-[min(20rem,82vw)] rounded-[3rem] border border-white/20 bg-[#070711] p-2.5 shadow-2xl shadow-black/60">
                            <div className="overflow-hidden rounded-[2.45rem] border border-white/10 bg-gradient-to-b from-[#20203d] to-[#111122]">
                                <div className="flex items-center justify-between px-5 pb-3 pt-4 text-[9px] text-white/55"><span>9:41</span><span className="h-2 w-20 rounded-full bg-black/50"/><span>● 5G</span></div>
                                <div className="px-4 pb-5">
                                    <div className="flex items-center justify-between">
                                        <div><p className="text-[10px] text-white/50">Dobré ráno, vy dva</p><p className="mt-0.5 text-lg font-bold">Náš dnešek <Heart size={14} className="inline fill-rose-400 text-rose-400"/></p></div>
                                        <img src="/icons/maki-app.svg" alt="" className="h-9 w-9 rounded-xl"/>
                                    </div>
                                    <div className="mt-4 rounded-2xl bg-gradient-to-br from-violet-500 to-fuchsia-500 p-4 shadow-lg shadow-violet-900/30">
                                        <p className="text-[9px] font-medium uppercase tracking-widest text-white/70">Nejbližší společný plán</p>
                                        <p className="mt-2 text-base font-bold">Víkend v Rakousku</p>
                                        <p className="mt-1 text-[10px] text-white/75">Za 12 dní · trasa i rozpočet připraveny</p>
                                        <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-white/20"><div className="h-full w-3/4 rounded-full bg-white"/></div>
                                    </div>
                                    <div className="mt-3 grid grid-cols-2 gap-3">
                                        <div className="rounded-2xl border border-white/8 bg-white/5 p-3"><p className="text-[9px] text-white/45">Spolu už</p><p className="mt-1 text-lg font-bold">1 482 dní</p><p className="text-[9px] text-rose-200">a pořád počítáme ♥</p></div>
                                        <div className="rounded-2xl border border-white/8 bg-white/5 p-3"><p className="text-[9px] text-white/45">Dnešní plán</p><p className="mt-1 text-sm font-bold">Společná večeře</p><p className="mt-2 text-[9px] text-emerald-200">19:00 · potvrzeno</p></div>
                                    </div>
                                    <div className="mt-3"><div className="mb-2 flex items-center justify-between"><p className="text-xs font-semibold">Poslední vzpomínky</p><span className="text-[9px] text-violet-200">Zobrazit vše</span></div><div className="grid grid-cols-3 gap-1.5"><div className="aspect-square rounded-xl bg-gradient-to-br from-amber-300/80 to-rose-500/70"/><div className="aspect-square rounded-xl bg-gradient-to-br from-sky-300/80 to-indigo-500/70"/><div className="aspect-square rounded-xl bg-gradient-to-br from-emerald-300/70 to-cyan-600/70"/></div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section className="relative z-[1] border-y border-white/8 bg-white/[0.025]">
                    <div className="mx-auto grid w-full max-w-7xl gap-3 px-4 py-12 sm:grid-cols-2 sm:px-6 lg:grid-cols-4 lg:px-8">
                        {BENEFITS.map(({ icon: Icon, title, text }) => (
                            <article key={title} className="rounded-2xl border border-white/8 bg-white/[0.035] p-5 backdrop-blur">
                                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-400/12 text-violet-200"><Icon size={19}/></span>
                                <h2 className="mt-4 text-sm font-bold">{title}</h2>
                                <p className="mt-2 text-xs leading-relaxed text-white/50">{text}</p>
                            </article>
                        ))}
                    </div>
                </section>

                <section id="installation-help" className="relative z-[1] mx-auto w-full max-w-5xl scroll-mt-6 px-4 py-16 sm:px-6 lg:py-24">
                    <div className="text-center"><p className="text-xs font-bold uppercase tracking-[.25em] text-violet-300">Dvě bezpečné možnosti</p><h2 className="mt-3 text-2xl font-black sm:text-3xl">Instalace, která vám vyhovuje</h2><p className="mx-auto mt-3 max-w-2xl text-sm leading-relaxed text-white/55">Obě varianty zobrazují stejná živá data. Webová instalace je nejrychlejší; podepsané APK lze stáhnout přímo ze sdíleného odkazu.</p></div>
                    <div className="mt-10 grid gap-5 md:grid-cols-2">
                        <article className="rounded-3xl border border-violet-300/20 bg-gradient-to-b from-violet-400/10 to-white/[0.025] p-6 sm:p-8">
                            <div className="flex items-start justify-between gap-4"><span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-400/15 text-violet-200"><Download size={22}/></span><span className="rounded-full bg-emerald-400/12 px-2.5 py-1 text-[10px] font-semibold text-emerald-200">Doporučeno</span></div>
                            <h3 className="mt-5 text-lg font-bold">Instalace z webu</h3>
                            <ol className="mt-4 space-y-3 text-xs leading-relaxed text-white/60">
                                <li className="flex gap-3"><span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/8 text-[10px] text-white">1</span><span>Otevřete tento odkaz v Chrome na telefonu nebo tabletu.</span></li>
                                <li className="flex gap-3"><span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/8 text-[10px] text-white">2</span><span>Klepněte na „Nainstalovat jedním klepnutím“. Pokud tlačítko není aktivní, v menu Chrome zvolte „Nainstalovat aplikaci“.</span></li>
                                <li className="flex gap-3"><span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/8 text-[10px] text-white">3</span><span>Maki se objeví mezi aplikacemi a bude se sama aktualizovat společně s webem.</span></li>
                            </ol>
                            <button type="button" onClick={() => void installWebApp()} disabled={installing} className="mt-6 inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-violet-500 px-4 text-sm font-semibold transition hover:bg-violet-400 disabled:opacity-50"><Download size={18}/>{canInstall ? 'Nainstalovat Maki' : installed ? 'Maki je nainstalovaná' : 'Zobrazit nabídku prohlížeče'}</button>
                        </article>
                        <article className="rounded-3xl border border-white/10 bg-white/[0.025] p-6 sm:p-8">
                            <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/8 text-white/75"><Smartphone size={22}/></span>
                            <h3 className="mt-5 text-lg font-bold">Přímý Android APK</h3>
                            <p className="mt-3 text-xs leading-relaxed text-white/55">Pro instalaci přímo ze souboru. Android může při prvním stažení požádat o povolení instalace aplikací z tohoto zdroje. Balíček musí být podepsán naším stálým release certifikátem.</p>
                            <dl className="mt-5 space-y-2 rounded-2xl bg-black/15 p-4 text-[11px]">
                                <div className="flex justify-between gap-3"><dt className="text-white/40">Verze</dt><dd className="font-medium">{android.version}</dd></div>
                                {size && <div className="flex justify-between gap-3"><dt className="text-white/40">Velikost</dt><dd className="font-medium">{size}</dd></div>}
                                <div className="flex justify-between gap-3"><dt className="text-white/40">Balíček</dt><dd className="truncate font-mono text-[10px] text-white/70">{android.package_name}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-white/40">Propojení webu</dt><dd className={android.verified_origin ? 'text-emerald-200' : 'text-amber-200'}>{android.verified_origin ? 'ověřeno' : 'čeká na certifikát'}</dd></div>
                            </dl>
                            <a href={android.download_url} className={`mt-6 inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border px-4 text-sm font-semibold transition ${android.available ? 'border-white/15 bg-white/8 hover:bg-white/12' : 'border-white/8 bg-white/[0.025] text-white/45 hover:text-white/70'}`}><Smartphone size={18}/>{android.available ? 'Stáhnout podepsané APK' : 'APK ještě není na serveru'}</a>
                            {android.sha256 && <p className="mt-3 break-all text-[9px] leading-relaxed text-white/30">SHA-256: {android.sha256}</p>}
                        </article>
                    </div>

                    <div className="mt-8 flex flex-col items-center justify-between gap-4 rounded-2xl border border-white/8 bg-white/[0.025] p-4 sm:flex-row sm:px-5">
                        <div className="flex items-center gap-3"><span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-400/12 text-rose-200"><Heart size={18}/></span><div><p className="text-xs font-semibold">Pošlete Maki partnerovi</p><p className="mt-0.5 text-[10px] text-white/45">Odkaz /app funguje na telefonu, tabletu i počítači.</p></div></div>
                        <button type="button" onClick={() => void share()} className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 text-xs font-medium hover:bg-white/10 sm:w-auto">{shareState === 'idle' ? <Copy size={15}/> : <Check size={15} className="text-emerald-300"/>}{shareState === 'idle' ? 'Sdílet instalační odkaz' : 'Odkaz je připravený'}</button>
                    </div>
                </section>
            </main>
        </>
    );
}
