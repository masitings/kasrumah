import { Head, Link } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight, Receipt } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';

interface ExpenseItem {
    id: number;
    merchant: string | null;
    description: string | null;
    amount: number;
    category: string;
    source: string;
    image_url: string | null;
}

interface Group {
    date: string;
    total: number;
    expenses: ExpenseItem[];
}

interface CategoryBucket {
    key: string;
    label: string;
    total: number;
}

interface PageProps {
    month: string;
    monthLabel: string;
    prevMonth: string;
    nextMonth: string;
    groups: Group[];
    total: number;
    perCategory: CategoryBucket[];
    categories: Record<string, string>;
}

function formatDay(date: string): string {
    const parsed = new Date(`${date}T00:00:00`);

    return parsed.toLocaleDateString('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
}

export default function Expenses({
    monthLabel,
    prevMonth,
    nextMonth,
    groups,
    total,
    perCategory,
    categories,
}: PageProps) {
    const monthUrl = (value: string) => `/expenses?month=${value}`;

    return (
        <>
            <Head title="Pengeluaran" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-xl font-bold tracking-tight">Pengeluaran</h1>
                </div>

                {/* Month Switcher */}
                <div className="flex items-center justify-between rounded-xl border border-neutral-200/80 bg-white p-2 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <Link
                        href={monthUrl(prevMonth)}
                        className="flex size-10 items-center justify-center rounded-lg text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                        aria-label="Bulan sebelumnya"
                    >
                        <ChevronLeft className="size-5" />
                    </Link>

                    <div className="text-center">
                        <p className="text-sm font-semibold capitalize">{monthLabel}</p>
                        <p className={cn('text-lg font-bold', total > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-neutral-400')}>
                            {formatRupiah(total)}
                        </p>
                    </div>

                    <Link
                        href={monthUrl(nextMonth)}
                        className="flex size-10 items-center justify-center rounded-lg text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                        aria-label="Bulan berikutnya"
                    >
                        <ChevronRight className="size-5" />
                    </Link>
                </div>

                {/* Per-category totals */}
                {perCategory.length > 0 && (
                    <Card className="border-neutral-200/80 shadow-sm dark:border-neutral-800">
                        <CardContent className="space-y-2.5 pt-4">
                            <p className="text-xs font-semibold tracking-wide text-neutral-500 uppercase dark:text-neutral-400">
                                Per Kategori
                            </p>
                            {perCategory.map((bucket) => (
                                <div key={bucket.key} className="flex items-center justify-between gap-2 text-sm">
                                    <span className="flex items-center gap-2 text-neutral-600 dark:text-neutral-300">
                                        <span className="size-2 rounded-full bg-emerald-500" />
                                        {categories[bucket.key] ?? bucket.key}
                                    </span>
                                    <span className="font-semibold tabular-nums">{formatRupiah(bucket.total)}</span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {/* Grouped list */}
                {groups.length === 0 ? (
                    <Card className="border-dashed border-neutral-300 py-12 text-center dark:border-neutral-800">
                        <CardContent className="space-y-2">
                            <Receipt className="mx-auto size-8 text-neutral-400" />
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                Belum ada pengeluaran tercatat bulan ini.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-4">
                        {groups.map((group) => (
                            <div key={group.date} className="space-y-2">
                                <div className="flex items-center justify-between px-1">
                                    <span className="flex items-center gap-1.5 text-xs font-semibold text-neutral-500 capitalize dark:text-neutral-400">
                                        <CalendarDays className="size-3.5" />
                                        {formatDay(group.date)}
                                    </span>
                                    <span className="text-xs font-semibold tabular-nums text-neutral-500 dark:text-neutral-400">
                                        {formatRupiah(group.total)}
                                    </span>
                                </div>

                                <Card className="overflow-hidden border-neutral-200/80 shadow-sm dark:border-neutral-800">
                                    <CardContent className="divide-y divide-neutral-100 p-0 dark:divide-neutral-800">
                                        {group.expenses.map((expense) => (
                                            <div key={expense.id} className="flex items-center gap-3 p-3">
                                                {expense.image_url && (
                                                    <img
                                                        src={expense.image_url}
                                                        alt="Struk"
                                                        className="size-10 shrink-0 rounded-md border border-neutral-200 object-cover dark:border-neutral-800"
                                                        loading="lazy"
                                                    />
                                                )}
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-medium">
                                                        {expense.merchant || expense.description || 'Tanpa nama'}
                                                    </p>
                                                    <p className="truncate text-xs text-neutral-500 dark:text-neutral-400">
                                                        {categories[expense.category] ?? expense.category}
                                                        {expense.description && expense.merchant ? ` · ${expense.description}` : ''}
                                                    </p>
                                                </div>
                                                <span className="shrink-0 text-sm font-semibold tabular-nums">
                                                    {formatRupiah(expense.amount)}
                                                </span>
                                            </div>
                                        ))}
                                    </CardContent>
                                </Card>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
