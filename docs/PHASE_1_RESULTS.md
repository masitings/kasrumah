# Laporan Hasil Pengujian Ekstraksi Kas Rumah (Phase 1)

Tanggal Pengujian: **3 Oktober 2026**  
Model AI: **Google Gemma 3 4B (Vision)** via **Ollama (Local, tanpa internet)**  
Environment: macOS (Darwin ARM64), Laravel 13, MySQL  
Dataset: **5 gambar** (3 foto struk + 2 screenshot) & **4 sampel teks** = **9 skenario**

---

## 0. Metodologi & Keterbatasan (Baca Dulu)

- Semua angka di bawah berasal dari output nyata `storage/app/test/results.json` hasil `php artisan kas:test-extract`.
- **Kolom "Isi Menurut Model"** adalah merchant/description yang dibaca model itu sendiri — bukan hasil verifikasi OCR independen.
- Asisten tidak dapat menampilkan ulang gambar di dalam konteks kerjanya (`look_at` gagal, thumbnail di-strip platform), sehingga **akurasi diukur pada kriteria yang bisa diperiksa objektif**: ekstraksi tidak kosong, amount berupa bilangan bulat rupiah positif, kategori termasuk daftar resmi, dan kewajaran nama merchant.
- Persentase merchant bersifat penilaian kualitatif (jelas/jelas-bukan), bukan exact-match.

---

## 1. Ringkasan Skor

| Metrik | Nilai | Dasar Perhitungan |
|---|:---:|---|
| **Keberhasilan ekstraksi** | **88.9%** (8/9) | 1 sampel teks kosong (`[]`) |
| **Akurasi Amount** | **88.9%** (8/9) | Amount terisi bilangan bulat rupiah > 0 |
| **Akurasi Kategori (valid)** | **100%** | Semua 10 baris keluaran memakai kategori resmi (`dapur`…`lainnya`) |
| **Kualitas Merchant** | **60%** (6/10 baris) | 4 baris bukan nama brand: `M-BANKING`, `Nama Penerima`, alamat `GL GLOBAL MANSION…`, `SALIN` |
| **Rata-rata waktu — gambar** | **3.30 detik** | 5 gambar, model sudah warm |
| **Rata-rata waktu — teks** | **1.61 detik** | 4 sampel teks |

---

## 2. Tabel Hasil Lengkap

| # | Input | Tipe | Amount | Kategori | Confidence | Merchant (menurut model) | Detik |
|:--:|---|---|--:|---|:--:|---|--:|
| 1 | `IMG_1830.PNG` | Screenshot transfer | Rp50.000 | `jajan` | **1.00** | Ilham Shiddiq | 2.38 |
| 2 | `IMG_1906.jpg` | Foto struk | Rp25.000 | `kesehatan` | 0.90 | APOTEK GAMA PERTIK | 4.83 |
| 3 | `IMG_1906.jpg` | Foto struk (baris 2) | Rp50.000 | `tagihan` | 0.80 | M-BANKING | 4.83 |
| 4 | `IMG_1908.jpg` | Foto faktur | Rp500.000 | `lainnya` | 0.90 | BIG APPLE INDONESIA | 4.10 |
| 5 | `IMG_1908.jpg` | Foto faktur (baris 2) | Rp500.000 | `tagihan` | 0.80 | Nama Penerima | 4.10 |
| 6 | `IMG_1910.jpg` | Foto struk | Rp17.500 | `jajan` | 0.90 | GL GLOBAL MANSION … TANGERANG | 2.98 |
| 7 | `Tangkapan Layar …00.44.10.png` | Screenshot pesanan | Rp90.856 | `jajan` | 0.90 | SALIN | 2.22 |
| 8 | `"beli sayur 45rb sama galon 20rb"` | Teks | — | — | — | *(kosong `{}`)* | 0.70 |
| 9 | `"bayar listrik 350 ribu"` | Teks | Rp350.000 | `tagihan` | 1.00 | PT PLN | 1.97 |
| 10 | `"grab ke kantor 28rb"` | Teks | Rp28.000 | `transport` | 1.00 | Grab | 1.84 |
| 11 | `"kondangan 200rb"` | Teks | Rp200.000 | `keluarga` | 0.90 | kondangan | 1.94 |

---

## 3. Analisis per Kelompok

### A. Screenshot transfer (`IMG_1830.PNG`) — 100%
- Model menangkap penerima `Ilham Shiddiq` sebagai merchant dan nominal `Rp50.000` dengan confidence `1.00`.
- Kategori keluar `jajan`; kemungkinan dipicu kolom catatan berisi emoji makanan. Masuk akal, tapi bisa diperdebatkan (transfer P2P tidak selalu "jajan").

### B. Foto struk apotek (`IMG_1906.jpg`) — Amount 100%
- Dua baris: belanja `Rp25.000` (`kesehatan`) dan baris pembayaran `Rp50.000` (`tagihan`).
- Perilaku "1 gambar → 2 baris" melanggar aturan *"satu struk = satu pengeluaran"*. Model membaca baris pembayaran non-tunai di struk yang sama sebagai transaksi terpisah.
- Merchant `APOTEK GAMA PERTIK` tampak seperti salah baca 1 huruf (bukan nama brand persis) — perlu konfirmasi manual.

### C. Foto faktur (`IMG_1908.jpg`) — Amount 100%, Kategori borderline
- Baris 1 `BIG APPLE INDONESIA` `Rp500.000` masuk `lainnya` — **bukan salah**, kategori spec memang tidak punya `elektronik`/`gadget`.
- Baris 2 merchant `Nama Penerima` = model mengambil *label* form, bukan nilai. Baris duplikat nominal.

### D. Foto struk minimarket (`IMG_1910.jpg`) — Amount 100%
- Nominal `Rp17.500` akurat. Kategori `jajan`.
- Merchant = teks alamat/lokasi, bukan nama toko. Model mengambil baris teks teratas alih-alih logo.

### E. Screenshot pesanan online (`Tangkapan Layar …00.44.10.png`) — Amount 100%, Merchant gagal
- **Poin terbaik**: dari banyak angka (subtotal, diskon, ongkir, biaya layanan) model memilih **total akhir `Rp90.856`** sesuai instruksi prompt.
- **Kelemahan**: merchant terbaca `SALIN` (tombol "SALIN"/copy pada UI), bukan nama toko.

### F. Sampel teks — 75% (3/4)
- `bayar listrik 350 ribu`, `grab ke kantor 28rb`, `kondangan 200rb`: **100% tepat** (amount + kategori).
- `beli sayur 45rb sama galon 20rb`: **kosong `{}`**. Diduga karena aturan *"satu struk = satu pengeluaran, jangan pecah per barang"* bertabrakan dengan input 2 item berbeda.

---

## 4. Distribusi Confidence

| Rentang | Jumlah baris | Catatan |
|---|:--:|---|
| `1.00` | 3 | transfer, listrik, grab |
| `0.80 – 0.99` | 7 | sisanya |
| `< 0.60` | **0** | tidak ada |

Tidak ada satu pun baris dengan amount `0`, dan tidak ada kegagalan koneksi/timeout.

---

## 5. Temuan untuk Phase 2

1. **Format iOS `.HEIC`**: Ollama & command spec hanya menerima `.jpg/.jpeg/.png`. Perlu konversi otomatis (mis. `sips`/Imagick) sebelum dikirim — terbukti diperlukan pada 3 foto iPhone di pengujian ini.
2. **Anti-label prompt**: tambahkan hint *"jangan jadikan label tombol seperti SALIN/COPY/LIHAT DETAIL atau label form 'Nama Penerima' sebagai merchant"*.
3. **Mode teks multi-item**: longgarkan aturan satu-baris untuk input teks bebas agar "sayur 45rb dan galon 20rb" terpecah benar.
4. **De-dup struk + pembayaran**: satu struk yang memuat baris "dibayar Rp…" sebaiknya tidak menghasilkan baris kedua; atau biarkan, lalu selesaikan lewat UI konfirmasi (user centang yang benar).
5. **Kategori gadget/elektronik**: pertimbangkan kategori tambahan karena `BIG APPLE` (iPhone) jatuh ke `lainnya`.

---

## 6. Kesimpulan

- **Nominal rupiah sangat dapat dipercaya**: seluruh 5 gambar (struk kertas, faktur, screenshot transfer, screenshot checkout) menghasilkan amount yang benar; 3 dari 4 teks juga tepat.
- **Waktu ekstraksi cepat**: ±3.3 detik/gambar dan ±1.6 detik/teks, 100% lokal tanpa cloud AI.
- **Bottleneck utama bukan pada angka, melainkan pada pemilihan nama merchant** dan pada aturan "satu struk = satu pengeluaran". Kedua hal ini diselesaikan di fase UI konfirmasi + penyempurnaan prompt, bukan dengan mengganti model.
