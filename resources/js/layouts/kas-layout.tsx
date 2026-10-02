import { Link, usePage } from '@inertiajs/react';
import { Camera, ListChecks, Receipt, Sparkles, SlidersHorizontal, LogOut } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { cn } from '@/lib/utils';

interface PageProps {
    auth?: {
        user?: {
            id: number;
            name: string;
            email: string;
        };
    };
    draftCount?: number;
    [key: string]: unknown;
}

export default function KasLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage<PageProps>();
    const draftCount = Number(props.draftCount ?? 0);

    const tabs = [
        {
            name: 'Catat',
            href: '/',
            icon: Camera,
            active: url === '/' || url.startsWith('/catat'),
        },
        {
            name: 'Review',
            href: '/review',
            icon: ListChecks,
            badge: draftCount > 0 ? draftCount : null,
            active: url.startsWith('/review'),
        },
        {
            name: 'Pengeluaran',
            href: '/expenses',
            icon: Receipt,
            active: url.startsWith('/expenses'),
        },
        {
            name: 'Ringkasan',
            href: '/summary',
            icon: Sparkles,
            active: url.startsWith('/summary'),
        },
    ];

    return (
        <div className="min-h-screen bg-neutral-50 text-neutral-900 dark:bg-neutral-950 dark:text-neutral-50">
            {/* Top Bar */}
            <header className="sticky top-0 z-30 border-b border-neutral-200/80 bg-white/90 backdrop-blur dark:border-neutral-800 dark:bg-neutral-900/90">
                <div className="mx-auto flex h-14 max-w-md items-center justify-between px-4">
                    <Link href="/" className="flex items-center gap-2 font-semibold tracking-tight text-emerald-600 dark:text-emerald-400">
                        <span className="flex size-7 items-center justify-center rounded-lg bg-emerald-500 text-white font-bold text-sm shadow-sm">
                            KR
                        </span>
                        <span>Kas Rumah</span>
                    </Link>

                    <div className="flex items-center gap-1">
                        <Link
                            href="/budgets"
                            className={cn(
                                'flex size-9 items-center justify-center rounded-full text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800',
                                url.startsWith('/budgets') && 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400'
                            )}
                            title="Atur Budget"
                        >
                            <SlidersHorizontal className="size-4" />
                        </Link>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="flex size-9 items-center justify-center rounded-full text-neutral-500 hover:bg-neutral-100 hover:text-red-600 dark:text-neutral-400 dark:hover:bg-neutral-800"
                            title="Keluar"
                        >
                            <LogOut className="size-4" />
                        </Link>
                    </div>
                </div>
            </header>

            {/* Main Content */}
            <main className="mx-auto max-w-md px-4 pt-4 pb-28">
                {children}
            </main>

            {/* Bottom Tab Bar */}
            <nav className="fixed inset-x-0 bottom-0 z-40 border-t border-neutral-200/80 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur dark:border-neutral-800 dark:bg-neutral-900/95">
                <div className="mx-auto grid h-16 max-w-md grid-cols-4 px-2">
                    {tabs.map((tab) => {
                        const Icon = tab.icon;
                        return (
                            <Link
                                key={tab.href}
                                href={tab.href}
                                className={cn(
                                    'relative flex flex-col items-center justify-center gap-1 text-[11px] font-medium transition-colors',
                                    tab.active
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-neutral-500 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200'
                                )}
                            >
                                <div className="relative">
                                    <Icon className={cn('size-5', tab.active && 'stroke-[2.25]')} />
                                    {tab.badge !== null && tab.badge !== undefined && (
                                        <span className="absolute -top-1 -right-2 flex min-w-4 h-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white shadow-sm">
                                            {tab.badge > 99 ? '99+' : tab.badge}
                                        </span>
                                    )}
                                </div>
                                <span>{tab.name}</span>
                            </Link>
                        );
                    })}
                </div>
            </nav>
        </div>
    );
}
