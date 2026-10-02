import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Camera, Image as ImageIcon, Loader2, Mic, Sparkles } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface PageProps {
    errors?: Record<string, string>;
    [key: string]: unknown;
}

export default function Catat() {
    const { props } = usePage<PageProps>();
    const errors = props.errors ?? {};

    const cameraRef = useRef<HTMLInputElement>(null);
    const galleryRef = useRef<HTMLInputElement>(null);
    const [loading, setLoading] = useState(false);

    const textForm = useForm({ text: '' });

    const submitImage = (file: File) => {
        const data = new FormData();
        data.append('image', file);

        setLoading(true);

        router.post('/catat/image', data, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                setLoading(false);

                if (cameraRef.current) {
                    cameraRef.current.value = '';
                }

                if (galleryRef.current) {
                    galleryRef.current.value = '';
                }
            },
        });
    };

    return (
        <>
            <Head title="Catat" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-xl font-bold tracking-tight">Catat Pengeluaran</h1>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        Foto struk atau ketik aja, nanti Gemma yang baca.
                    </p>
                </div>

                {/* Foto struk */}
                <Card className="border-neutral-200/80 shadow-sm dark:border-neutral-800">
                    <CardContent className="space-y-3 pt-5">
                        <div className="flex items-center gap-2 text-sm font-semibold text-emerald-600 dark:text-emerald-400">
                            <Camera className="size-4" />
                            <span>Foto struk</span>
                        </div>

                        <input
                            ref={cameraRef}
                            type="file"
                            accept="image/jpeg,image/png"
                            capture="environment"
                            className="hidden"
                            onChange={(event) => {
                                const file = event.target.files?.[0];

                                if (file) {
                                    submitImage(file);
                                }
                            }}
                        />

                        <Button
                            type="button"
                            size="lg"
                            className="h-14 w-full text-base font-semibold"
                            disabled={loading}
                            onClick={() => cameraRef.current?.click()}
                        >
                            {loading ? (
                                <>
                                    <Loader2 className="size-5 animate-spin" />
                                    Lagi dibaca...
                                </>
                            ) : (
                                <>
                                    <Camera className="size-5" />
                                    Ambil Foto Struk
                                </>
                            )}
                        </Button>

                        <input
                            ref={galleryRef}
                            type="file"
                            accept="image/jpeg,image/png"
                            className="hidden"
                            onChange={(event) => {
                                const file = event.target.files?.[0];

                                if (file) {
                                    submitImage(file);
                                }
                            }}
                        />

                        <Button
                            type="button"
                            variant="outline"
                            size="lg"
                            className="h-12 w-full"
                            disabled={loading}
                            onClick={() => galleryRef.current?.click()}
                        >
                            <ImageIcon className="size-5" />
                            Pilih dari Galeri
                        </Button>

                        {errors.image && (
                            <p className="text-sm font-medium text-red-600 dark:text-red-400">{errors.image}</p>
                        )}
                    </CardContent>
                </Card>

                {/* Teks */}
                <Card className="border-neutral-200/80 shadow-sm dark:border-neutral-800">
                    <CardContent className="space-y-3 pt-5">
                        <div className="flex items-center gap-2 text-sm font-semibold text-emerald-600 dark:text-emerald-400">
                            <Sparkles className="size-4" />
                            <span>Atau ketik aja</span>
                        </div>

                        <form
                            className="space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                textForm.post('/catat/text', {
                                    preserveScroll: true,
                                    onSuccess: () => textForm.reset(),
                                });
                            }}
                        >
                            <div className="space-y-2">
                                <Label htmlFor="text" className="text-sm">
                                    Catatan pengeluaran
                                </Label>
                                <Input
                                    id="text"
                                    value={textForm.data.text}
                                    onChange={(event) => textForm.setData('text', event.target.value)}
                                    placeholder="misal: beli sayur 45rb sama galon 20rb"
                                    className="h-12 text-base"
                                    autoComplete="off"
                                />
                                <p className="flex items-center gap-1.5 text-xs text-neutral-500 dark:text-neutral-400">
                                    <Mic className="size-3.5" />
                                    Bisa juga pencet tombol mic di keyboard iPhone buat ngomong.
                                </p>
                                {errors.text && (
                                    <p className="text-sm font-medium text-red-600 dark:text-red-400">{errors.text}</p>
                                )}
                            </div>

                            <Button
                                type="submit"
                                size="lg"
                                className="h-12 w-full text-base font-semibold"
                                disabled={textForm.processing || textForm.data.text.trim() === ''}
                            >
                                {textForm.processing ? (
                                    <>
                                        <Loader2 className="size-5 animate-spin" />
                                        Lagi dibaca...
                                    </>
                                ) : (
                                    'Baca Catatan'
                                )}
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
