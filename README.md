# Kas Rumah

Pencatat pengeluaran rumah tangga buat istri di rumah. Foto struk atau ketik aja — **Gemma 3 4B dijalankan 100% lokal lewat Ollama**, tidak ada satu pun data yang keluar dari laptop.

## Yang Dipakai

- Laravel 13 + Inertia + React (starter kit resmi, auth bawaan)
- SQLite / MySQL (lihat `.env`)
- Ollama `gemma3:4b` (vision) di `http://127.0.0.1:11434`
- Uang disimpan sebagai **integer rupiah**, tanpa desimal

## Persiapan Sekali Jalan

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Pastikan Ollama jalan dan model sudah didownload:

```bash
ollama pull gemma3:4b
ollama serve
```

## Development

```bash
composer run dev
```

## Regresi Ekstraksi

```bash
php artisan kas:test-extract
```

Menjalankan Gemma terhadap foto di `storage/app/test/` plus sampel teks, lalu membandingkan hasil dengan `storage/app/test/expected.json` (PASS/FAIL per kasus).

## Run on your phone

Server dan Ollama jalan di laptop; iPhone mengaksesnya lewat Wi-Fi yang sama.

1. Build aset sekali (iPhone tidak butuh Vite dev server):

   ```bash
   npm run build
   ```

2. Nyalakan Ollama kalau belum jalan:

   ```bash
   ollama serve
   ```

3. Buka server untuk semua antarmuka jaringan:

   ```bash
   php artisan serve --host=0.0.0.0 --port=8000
   ```

4. Cari IP laptop di LAN:

   ```bash
   ipconfig getifaddr en0   # macOS; lihat di Pengaturan > Jaringan kalau beda
   ```

5. Di Safari iPhone, buka:

   ```
   http://192.168.0.186:8000
   ```

   (ganti `192.168.0.186` dengan IP dari langkah 4)

6. Login, lalu **Tambahkan ke Layar Utama** supaya tampil full-screen seperti aplikasi biasa.

Catatan:
- Kamera langsung jalan: tombol "Ambil Foto Struk" butuh `http://` (bukan `https://`) di LAN untuk mengaktifkan kamera, dan `accept="image/jpeg,image/png"` membuat iOS Safari mengonversi HEIC ke JPEG otomatis.
- Kalau struk tidak terbaca, pastikan `ollama ps` menunjukkan `gemma3:4b` sudah ter-load.
- Kalau koneksi ditolak di port 11434, jalankan `ollama serve`.

## Panduan Singkat

| Halaman | Fungsi |
|---|---|
| **Catat** (`/`) | Foto struk / ketik catatan pengeluaran |
| **Review** (`/review`) | Koreksi hasil baca lalu **Simpan**, atau **Hapus** |
| **Pengeluaran** (`/expenses`) | Riwayat per bulan + rincian per kategori |
| **Ringkasan** (`/summary`) | Narasi mingguan + pemakaian budget |
| **Budget** (`/budgets`) | Batas pengeluaran tiap kategori per bulan |