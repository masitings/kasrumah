import { Head, router } from '@inertiajs/react';
import { ArrowUpRight, RefreshCw, Sparkles, TrendingUp, Wallet } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';

interface SummaryFacts {
    periode: string;
    total_minggu_ini: number;
    per_kategori_minggu_ini: Record<string, number>;
    rata_rata_per_minggu_4_minggu_terakhir: Record<string, number>;
    pemakaian_budget_bulan_ini_persen: Record<string, number>;
}

interface SummaryData {
    facts: SummaryFacts;
    text: string;
}

interface PageProps {
    summary: SummaryData;
}

export default function Summary({ summary }: PageProps) {
    const [regenerating, setRegenerating] = useState(false);
    const { facts, text } = summary;

    const handleRegenerate = () => {
        setRegenerating(true);
        router.post('/summary/regenerate', {}, {
            preserveScroll: true,
            onFinish: () => setRegenerating(false),
        });
    };

    const categories = Object.keys({
        ...facts.per_kategori_minggu_ini,
        ...facts.rata_rata_per_minggu_4_minggu_terakhir,
    });

    const budgetKeys = Object.keys(facts.pemakaian_budget_bulan_ini_persen || {});

    return (
        <>
            <Head title="Ringkasan Mingguan" />

            <div className="space-y-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-bold tracking-tight">Ringkasan Mingguan</h1>
                        <p className="text-xs text-neutral-500 dark:text-neutral-400">
                            Periode {facts.periode}
                        </p>
                    </div>

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 gap-1.5 text-xs"
                        disabled={regenerating}
                        onClick={handleRegenerate}
                    >
                        <RefreshCw className={cn('size-3.5', regenerating && 'animate-spin')} />
                        Bikin Ulang
                    </Button>
                </div>

                {/* AI Narration Card */}
                <Card className="border-emerald-200 bg-gradient-to-br from-emerald-50/70 via-white to-emerald-50/30 shadow-sm dark:border-emerald-950 dark:from-emerald-950/30 dark:via-neutral-900 dark:to-neutral-900">
                    <CardContent className="space-y-2 pt-4">
                        <div className="flex items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                            <Sparkles className="size-4" />
                            <span>Catatan Gemma</span>
                        </div>
                        <p className="text-sm leading-relaxed text-neutral-700 dark:text-neutral-200">
                            {text || 'Belum ada pengeluaran yang dicatat untuk periode minggu ini.'}
                        </p>
                    </CardContent>
                </Card>

                {/* Total Minggu Ini */}
                <Card className="border-neutral-200/80 shadow-sm dark:border-neutral-800">
                    <CardContent className="space-y-1 pt-4">
                        <p className="text-xs font-medium text-neutral-500 dark:text-neutral-400">
                            Total Pengeluaran Minggu Ini
                        </p>
                        <p className="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-50">
                            {formatRupiah(facts.total_minggu_ini)}
                        </p>
                    </CardContent>
                </Card>

                {/* Budget Usage Bars */}
                {budgetKeys.length > 0 && (
                    <Card className="border-neutral-200/80 shadow-sm dark:border-neutral-800">
                        <CardContent className="space-y-3 pt-4">
                            <div className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                                <Wallet className="size-3.5" />
                                <span>Pemakaian Budget Bulan Ini</span>
                            </div>

                            <div className="space-y-3">
                                {budgetKeys.map((cat) => {
                                    const percent = facts.pemakaian_budget_bulan_ini_persen[cat] ?? 0;
                                    const isWarning = percent >= 70;
                                    const isOver = percent >= 100;

                                    return (
                                        <div key={cat} className="space-y-1">
                                            <div className="flex items-center justify-between text-xs font-medium">
                                                <span className="capitalize">{cat}</span>
                                                <span
                                                    className={cn(
                                                        'font-semibold tabular-nums',
                                                        isOver
                                                            ? 'text-red-600 dark:text-red-400'
                                                            : isWarning
                                                              ? 'text-amber-600 dark:text-amber-400'
                                                              : 'text-neutral-600 dark:text-neutral-300'
                                                    )}
                                                >
                                                    {percent}%
                                                </span>
                                            </div>
                                            <div className="h-2 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                                                <div
                                                    className={cn(
                                                        'h-full transition-all',
                                                        isOver
                                                            ? 'bg-red-500'
                                                            : isWarning
                                                              ? 'bg-amber-500'
                                                              : 'bg-emerald-500'
                                                    )}
                                                    style={{ width: `${Math.min(100, percent)}%` }}
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Per Category vs 4-Week Average */}
                {categories.length > 0 && (
                    <Card className="border-neutral-200/80 shadow-sm dark:border-neutral-800">
                        <CardContent className="space-y-3 pt-4">
                            <div className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                                <TrendingUp className="size-3.5" />
                                <span>Minggu Ini vs Rata-rata 4 Minggu</span>
                            </div>

                            <div className="divide-y divide-neutral-100 text-xs dark:divide-neutral-800">
                                {categories.map((cat) => {
                                    const thisWeek = facts.per_kategori_minggu_ini[cat] ?? 0;
                                    const avg = facts.rata_rata_per_minggu_4_minggu_terakhir[cat] ?? 0;
                                    const isHigher = thisWeek > avg && avg > 0;

                                    return (
                                        <div key={cat} className="flex items-center justify-between py-2">
                                            <div className="space-y-0.5">
                                                <p className="font-semibold capitalize text-neutral-800 dark:text-neutral-200">
                                                    {cat}
                                                </p>
                                                <p className="text-[11px] text-neutral-400">
                                                    Rata-rata: {formatRupiah(avg)}/mgg
                                                </p>
                                            </div>

                                            <div className="text-right">
                                                <p className="font-semibold tabular-nums text-neutral-900 dark:text-neutral-50">
                                                    {formatRupiah(thisWeek)}
                                                </p>
                                                {isHigher && (
                                                    <span className="inline-flex items-center gap-0.5 text-[10px] font-medium text-amber-600 dark:text-amber-400">
                                                        <ArrowUpRight className="size-3" /> Di atas rata-rata
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}
