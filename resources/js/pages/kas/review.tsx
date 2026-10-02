import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Check, CheckCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';

interface ExpenseDraft {
    id: number;
    spent_on: string;
    merchant: string | null;
    description: string | null;
    amount: number;
    category: string;
    source: string;
    confidence: number | null;
    image_url: string | null;
}

interface PageProps {
    expenses: ExpenseDraft[];
    categories: Record<string, string>;
}

export default function Review({ expenses, categories }: PageProps) {
    const [drafts, setDrafts] = useState<Record<number, ExpenseDraft>>(() => {
        const map: Record<number, ExpenseDraft> = {};
        for (const exp of expenses) {
            map[exp.id] = { ...exp };
        }
        return map;
    });

    const [saving, setSaving] = useState<number | null>(null);
    const [savingAll, setSavingAll] = useState(false);

    const updateField = <K extends keyof ExpenseDraft>(id: number, field: K, value: ExpenseDraft[K]) => {
        setDrafts((prev) => ({
            ...prev,
            [id]: {
                ...prev[id],
                [field]: value,
            },
        }));
    };

    const handleSaveRow = (id: number) => {
        const item = drafts[id];
        if (!item) return;

        setSaving(id);
        router.patch(`/review/${id}`, {
            spent_on: item.spent_on,
            merchant: item.merchant ?? '',
            description: item.description ?? '',
            amount: item.amount,
            category: item.category,
            confirm: true,
        }, {
            preserveScroll: true,
            onFinish: () => setSaving(null),
        });
    };

    const handleDeleteRow = (id: number) => {
        if (!confirm('Hapus draf pengeluaran ini?')) return;

        router.delete(`/review/${id}`, {
            preserveScroll: true,
        });
    };

    const handleSaveAll = () => {
        setSavingAll(true);
        router.post('/review/confirm-all', {}, {
            preserveScroll: true,
            onFinish: () => setSavingAll(false),
        });
    };

    return (
        <>
            <Head title="Review Draf" />

            <div className="space-y-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-bold tracking-tight">Review Draf</h1>
                        <p className="text-xs text-neutral-500 dark:text-neutral-400">
                            {expenses.length === 0
                                ? 'Tidak ada draf yang perlu diperiksa'
                                : `${expenses.length} draf siap disimpan ke pengeluaran`}
                        </p>
                    </div>

                    {expenses.length > 0 && (
                        <Button
                            type="button"
                            size="sm"
                            className="h-9 gap-1.5 font-semibold bg-emerald-600 hover:bg-emerald-700"
                            disabled={savingAll}
                            onClick={handleSaveAll}
                        >
                            <CheckCheck className="size-4" />
                            Simpan Semua
                        </Button>
                    )}
                </div>

                {expenses.length === 0 ? (
                    <Card className="border-dashed border-neutral-300 py-12 text-center dark:border-neutral-800">
                        <CardContent className="space-y-3">
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                Belum ada draf. Catat struk atau ketik pengeluaran dulu ya.
                            </p>
                            <Button asChild size="sm">
                                <Link href="/">Mulai Catat</Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-3">
                        {expenses.map((expense) => {
                            const current = drafts[expense.id] ?? expense;
                            const isLowConfidence = current.confidence !== null && current.confidence < 0.7;

                            return (
                                <Card
                                    key={expense.id}
                                    className={cn(
                                        'overflow-hidden border-neutral-200/90 shadow-sm transition-colors dark:border-neutral-800',
                                        isLowConfidence && 'border-amber-400 bg-amber-50/40 dark:border-amber-500/60 dark:bg-amber-950/20'
                                    )}
                                >
                                    <CardContent className="space-y-3 p-3.5">
                                        {/* Low confidence warning banner */}
                                        {isLowConfidence && (
                                            <div className="flex items-center gap-1.5 text-xs font-semibold text-amber-700 dark:text-amber-400">
                                                <AlertTriangle className="size-3.5" />
                                                <span>Cek lagi nominalnya ya, Gemma kurang yakin ({(current.confidence! * 100).toFixed(0)}%)</span>
                                            </div>
                                        )}

                                        {/* Thumbnail if image exists */}
                                        {current.image_url && (
                                            <div className="overflow-hidden rounded-md border border-neutral-200 dark:border-neutral-800">
                                                <img
                                                    src={current.image_url}
                                                    alt="Foto struk"
                                                    className="max-h-48 w-full object-cover"
                                                    loading="lazy"
                                                />
                                            </div>
                                        )}

                                        {/* Inline Edit Form */}
                                        <div className="grid grid-cols-2 gap-2">
                                            <div className="space-y-1">
                                                <label className="text-[11px] font-medium text-neutral-500 dark:text-neutral-400">
                                                    Tanggal
                                                </label>
                                                <Input
                                                    type="date"
                                                    value={current.spent_on}
                                                    onChange={(e) => updateField(expense.id, 'spent_on', e.target.value)}
                                                    className="h-9 text-xs"
                                                />
                                            </div>

                                            <div className="space-y-1">
                                                <label className="text-[11px] font-medium text-neutral-500 dark:text-neutral-400">
                                                    Nominal (Rupiah)
                                                </label>
                                                <Input
                                                    type="text"
                                                    inputMode="numeric"
                                                    value={current.amount ? formatRupiah(current.amount) : ''}
                                                    onChange={(e) => {
                                                        const num = parseInt(e.target.value.replace(/\D/g, ''), 10) || 0;
                                                        updateField(expense.id, 'amount', num);
                                                    }}
                                                    className="h-9 text-xs font-semibold text-emerald-700 dark:text-emerald-400"
                                                />
                                            </div>

                                            <div className="space-y-1">
                                                <label className="text-[11px] font-medium text-neutral-500 dark:text-neutral-400">
                                                    Toko / Merchant
                                                </label>
                                                <Input
                                                    type="text"
                                                    value={current.merchant ?? ''}
                                                    onChange={(e) => updateField(expense.id, 'merchant', e.target.value)}
                                                    placeholder="Nama toko"
                                                    className="h-9 text-xs"
                                                />
                                            </div>

                                            <div className="space-y-1">
                                                <label className="text-[11px] font-medium text-neutral-500 dark:text-neutral-400">
                                                    Kategori
                                                </label>
                                                <select
                                                    value={current.category}
                                                    onChange={(e) => updateField(expense.id, 'category', e.target.value)}
                                                    className="flex h-9 w-full rounded-md border border-neutral-200 bg-white px-2 py-1 text-xs shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-neutral-950 dark:border-neutral-800 dark:bg-neutral-900"
                                                >
                                                    {Object.entries(categories).map(([key, label]) => (
                                                        <option key={key} value={key}>
                                                            {label}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>

                                            <div className="col-span-2 space-y-1">
                                                <label className="text-[11px] font-medium text-neutral-500 dark:text-neutral-400">
                                                    Catatan / Keterangan
                                                </label>
                                                <Input
                                                    type="text"
                                                    value={current.description ?? ''}
                                                    onChange={(e) => updateField(expense.id, 'description', e.target.value)}
                                                    placeholder="Isi belanja (opsional)"
                                                    className="h-9 text-xs"
                                                />
                                            </div>
                                        </div>

                                        {/* Action buttons */}
                                        <div className="flex items-center justify-end gap-2 pt-1">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-8 text-xs text-red-600 hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-950/50"
                                                onClick={() => handleDeleteRow(expense.id)}
                                            >
                                                <Trash2 className="size-3.5" />
                                                Hapus
                                            </Button>

                                            <Button
                                                type="button"
                                                size="sm"
                                                className="h-8 gap-1 text-xs font-semibold"
                                                disabled={saving === expense.id || current.amount <= 0}
                                                onClick={() => handleSaveRow(expense.id)}
                                            >
                                                <Check className="size-3.5" />
                                                {saving === expense.id ? 'Menyimpan...' : 'Simpan'}
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}
            </div>
        </>
    );
}
