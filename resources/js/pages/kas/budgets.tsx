import { Head, useForm } from '@inertiajs/react';
import { Loader2, Save, Wallet } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatRupiah } from '@/lib/format';

interface BudgetRow {
    key: string;
    label: string;
    monthly_limit: number | null;
}

interface PageProps {
    rows: BudgetRow[];
}

export default function Budgets({ rows }: PageProps) {
    const form = useForm({
        rows: rows.map((row) => ({
            key: row.key,
            monthly_limit: row.monthly_limit ?? ('' as number | ''),
        })),
    });

    const updateLimit = (index: number, value: string) => {
        const numeric = value.replace(/\D/g, '');
        const parsed = numeric === '' ? '' : parseInt(numeric, 10);

        form.setData(
            'rows',
            form.data.rows.map((row, i) =>
                i === index ? { ...row, monthly_limit: parsed } : row,
            ),
        );
    };

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/budgets', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Budget Bulanan" />

            <form className="space-y-4" onSubmit={handleSubmit}>
                <div>
                    <h1 className="text-xl font-bold tracking-tight">Budget Bulanan</h1>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        Isi batas pengeluaran tiap kategori. Kosongkan kalau nggak mau dibatasi.
                    </p>
                </div>

                <Card className="border-neutral-200/80 shadow-sm dark:border-neutral-800">
                    <CardContent className="space-y-3.5 pt-5">
                        <div className="flex items-center gap-2 text-sm font-semibold text-emerald-600 dark:text-emerald-400">
                            <Wallet className="size-4" />
                            <span>Batasy per kategori</span>
                        </div>

                        <div className="space-y-3">
                            {form.data.rows.map((row, index) => (
                                <div key={row.key} className="space-y-1.5">
                                    <label
                                        htmlFor={`budget-${row.key}`}
                                        className="text-xs font-medium text-neutral-600 dark:text-neutral-300"
                                    >
                                        {rows[index]?.label ?? row.key}
                                    </label>
                                    <div className="relative">
                                        <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-neutral-400">
                                            Rp
                                        </span>
                                        <Input
                                            id={`budget-${row.key}`}
                                            type="text"
                                            inputMode="numeric"
                                            className="h-11 pl-9 text-sm font-semibold"
                                            placeholder="0"
                                            value={
                                                row.monthly_limit === '' || row.monthly_limit === null
                                                    ? ''
                                                    : formatRupiah(row.monthly_limit).replace('Rp', '')
                                            }
                                            onChange={(event) => updateLimit(index, event.target.value)}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                <Button
                    type="submit"
                    size="lg"
                    className="h-12 w-full text-base font-semibold"
                    disabled={form.processing}
                >
                    {form.processing ? (
                        <>
                            <Loader2 className="size-5 animate-spin" />
                            Menyimpan...
                        </>
                    ) : (
                        <>
                            <Save className="size-5" />
                            Simpan Budget
                        </>
                    )}
                </Button>
            </form>
        </>
    );
}
