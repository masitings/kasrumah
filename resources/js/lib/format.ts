export function formatRupiah(amount: number | string | null | undefined): string {
    const numeric = typeof amount === 'number'
        ? Math.round(amount)
        : Number(String(amount ?? 0).replace(/\D/g, ''));

    if (isNaN(numeric)) {
        return 'Rp0';
    }

    return 'Rp' + numeric.toLocaleString('id-ID');
}
