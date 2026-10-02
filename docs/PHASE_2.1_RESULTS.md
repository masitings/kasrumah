# Laporan Hasil Kas Rumah — Phase 2.1 (anti-overfit, pengukuran jujur)

Tanggal: **3 Oktober 2026**  
Model diukur: **`gemma3:4b`** via Ollama (3 runs per kasus, `temperature 0`, structured outputs)  
`gemma3:12b` **tidak dijalankan** (dibatalkan oleh owner).  
Fixture: `tests/fixtures/extraction/{tuning,holdout}/`  
Laptop: **Apple M5, RAM 16 GB** (inference 100% GPU)

---

## 1. Apa yang diubah di Phase 2.1

- **Hapus kebocoran test-set dari prompt gambar**: semua angka (`25000`, `500000`, `17500`), semua nama toko/barang (`Apotek Gama`, `Big Apple`, `Indomaret`, `Kinder`, `Global Mansion`), dan semua aturan per-toko dihapus. Contoh di prompt teks (`sayur 45rb + galon 20rb`) juga diganti contoh karangan (`rokok 15rb + sabun 12rb`).
- **Tinggal aturan umum**: amount = baris TOTAL/GRAND TOTAL (bukan cara bayar); merchant = nama toko dari logo/wordmark, bukan alamat/cabang/nama barang; kategori generik per JENIS toko; aturan desimal rupiah (`,00` dibuang); abaikan baris TUNAI/KEMBALI/QRIS/dll.
- **Fixture dipisah**: `tuning/` (3 struk Phase 1 + 4 sampel teks) dan `holdout/` (transfer Jago + screenshot ShopeeFood). `IMG_1906.jpg` (mengandung nama obat) dan `IMG_1830.PNG` (mengandung info rekening/saldo) **tidak di-commit** — didaftarkan di `tests/fixtures/extraction/.gitignore`; command memberi catatan saat file tidak ada.
- **Command** `kas:test-extract` kini punya `--set=tuning|holdout|all`, `--model=`, dan `--runs=N` (sebuah kasus PASS hanya jika **semua** N run lulus).

---

## 2. Hasil Pengukuran — `gemma3:4b` (`--set=all --runs=3`)

| Metrik | Hasil |
|---|---|
| **Tuning pass rate (gambar)** | **1/3 (33.3%)** |
| **Tuning pass rate (teks)** | **4/4 (100%)** |
| **Holdout pass rate** | **0/2 (0.0%)** |
| **Rata-rata detik per gambar** | **4.14 s** |
| **Rata-rata detik per teks** | **3.50 s** |

Detail per kasus:

| Set | Input | Merchant | Amount | Category | Status |
|---|---|---|---|--:|---|
| tuning | IMG_1906.jpg | APOTEK GAMA PERTUK | 23.000 | kesehatan | **FAIL (0/3)** |
| tuning | IMG_1908.jpg | BIG APPLE INDONESIA | 500.000 | lainnya | PASS (3/3) |
| tuning | IMG_1910.jpg | – (null) | 17.500 | jajan | **FAIL (0/3)** |
| text | beli sayur 45rb sama galon 20rb | – | 45.000 + 20.000 | dapur + rumah | PASS (3/3) |
| text | bayar listrik 350 ribu | – | 350.000 | tagihan | PASS (3/3) |
| text | grab ke kantor 28rb | – | 28.000 | transport | PASS (3/3) |
| text | kondangan 200rb | – | 200.000 | keluarga | PASS (3/3) |
| holdout | IMG_1830.PNG | GoPay | 50.000 | jajan | **FAIL (0/3)** |
| holdout | shopee-order.png | ES KOPI SUSU IMPIAN | 90.856 | jajan | **FAIL (0/3)** |

---

## 3. Setiap Kasus Gagal (nilai salah)

| Kasus | Field salah | Expected | Didapat (ketiga run sama) |
|---|---|---|---|
| tuning `IMG_1906.jpg` | amount | `25000` | **`23000`** (konsisten 3×) |
| tuning `IMG_1910.jpg` | merchant | mengadung `indomaret` | **`null`** (logo Indomaret tak terbaca; model hanya membaca nama barang/alamat) |
| holdout `IMG_1830.PNG` | merchant | mengandung `ilham` | **`GoPay`** (penerima "Ilham Shiddiq" malah masuk ke `description`) |
| holdout `shopee-order.png` | merchant | mengandung `shopee` | **`ES KOPI SUSU IMPIAN`** (nama kedai, bukan platform) |

Catatan pembacaan gagal (bukan alasan untuk melonggarkan tes):
- Pada **kedua kasus holdout**, `amount` **dan** `category` justru **benar** (50000/jajan dan 90856/jajan); yang gagal hanya substring `merchant`.
- Semua teks **100% lulus** setelah aturan konversi `ribu/x1000` dan `juta/x1000000` ditegaskan.
- Semua kegagalan **stabil** di 3 run (bukan acak).

---

## 4. Kesimpulan pengukuran (tanpa rekomendasi)

Dengan prompt yang sudah bebas kebocoran test-set, `gemma3:4b` tidak lagi dapat mengerjakan 3 gambar uji sebaik versi Phase 2 yang "menghafal jawaban":

- **Akurasi yang bergantung pada teks terang** (nominal total, kategori per jenis toko, input teks) tetap tinggi: nominal 5 dari 5 gambar benar (kecuali IMG_1906 yang memang ambigu), kategori 4 dari 5 benar, teks 4/4.
- **Kegagalan terkonsentrasi pada merchant** untuk logo yang ditulis miring/stylized (Indomaret) dan pada pemilihan entitas untuk screenshot (penerima transfer vs nama aplikasi, kedai vs platform).
- **Angka amount `IMG_1906.jpg`**: model konsisten membaca `23000` (transkripsi bebas juga sempat membaca `23.000`), sedangkan ground truth fixture tetap `25000`.

Angka Phase 2 (7/7 karena prompt memuat jawaban) **tidak sebanding** dengan angka Phase 2.1 ini (tuning gambar 1/3); ini adalah angka yang jujur setelah kebocoran dihapus.
