# MidnightPDF 🌙

**MidnightPDF** adalah document & media viewer berbasis **PHP + JavaScript** dengan konsep **Single-File Application** (`MidnightPDF.php`). Proyek ini merupakan versi ringan dan berfokus pada pratinjau dokumen dari ekosistem *Midnight*, dirancang dengan estetika gelap modern (*Midnight Obsidian*), responsif di perangkat desktop maupun mobile, dan tanpa memerlukan konfigurasi database atau build step yang rumit.

---

## ✨ Fitur Utama

- **Single-File Architecture (`MidnightPDF.php`)**:
  Seluruh kode backend (PHP) dan frontend (HTML5, Vanilla CSS, JS) berada dalam 1 file tunggal yang mandiri.
- **Collapsible Tree View Side Pane**:
  Mengindeks secara rekursif seluruh file dan folder yang tersimpan di dalam direktori `MidnightPDF-data/`.
  - Folder dapat dibuka/tutup (*collapsible*), status ekspansi tersimpan di `localStorage`.
  - Dilengkapi ikon berkode warna sesuai kategori file.
  - Fitur pencarian instan (*real-time filter*) untuk menemukan file dengan cepat.
  - Tombol *Expand All*, *Collapse All*, dan *Refresh*.
- **Native PDF Viewer (Powered by Mozilla PDF.js)**:
  - Pratinjau dokumen PDF berkualitas tinggi tanpa ketergantungan plugin bawaan browser.
  - Navigasi halaman (Next, Prev, Jump to page `[ 1 ] / N`).
  - Kontrol Zoom (50% – 200%, *Sesuaikan Lebar*, *Sesuaikan Halaman*).
  - Rotasi halaman 90° searah jarum jam.
  - Mode Layar Penuh (*Fullscreen*).
  - *Smart mobile scaling*: otomatis *fit-width* pada perangkat layar sempit dan mendukung scroll horizontal penuh saat di-zoom tanpa distorsi rasio aspek.
- **Dukungan Multi-Media & Kode Sumber**:
  - 🖼️ **Gambar** (`.jpg`, `.jpeg`, `.png`, `.webp`, `.gif`, `.bmp`, `.svg`): Zoom in/out, reset, layar penuh, serta info resolusi dimensi gambar.
  - 🎥 **Video** (`.mp4`, `.3gp`, `.webm`, `.mkv`): Pemutar video HTML5 responsif.
  - 🎵 **Audio** (`.mp3`, `.m4a`, `.aac`, `.wav`, `.opus`): Audio player card dengan visualisasi piringan vinil berputar saat diputar.
  - 💻 **Kode & Teks** (`.md`, `.txt`, `.html`, `.php`, `.css`, `.js`, `.py`, `.java`, `.json`, `.yaml`, `.cpp`, `.c`, dsb.): Syntax highlighting menggunakan **Highlight.js** (`atom-one-dark`), kolom nomor baris vertikal, informasi jumlah baris, tombol *Salin Kode*, dan toggle *Word Wrap*.
  - 📦 **File Format Lain**: Tampilan fallback yang informatif dengan pesan: *"Preview tidak/belum didukung, klik tombol di bawah ini untuk mengunduh"* beserta tombol unduh instan.
- **Header & Navigasi**:
  - Tombol **Burger Menu** untuk menyembunyikan/menampilkan side pane (aktif di desktop maupun mobile).
  - **Path Breadcrumbs** di bagian tengah header (contoh: `Catatan Kuliah / Sistem Operasi / Pertemuan 1.pdf`).
  - Tombol **Download** file aktif dan tombol **Tutup ("X")** untuk kembali ke tampilan awal (*empty state*).
- **HTTP Range Requests (206 Partial Content)**:
  Backend PHP mendukung streaming *byte-range*, esensial untuk *seeking / scrubbing* audio & video secara instan serta pembacaan byte dokumen PDF berukuran besar.
- **Keamanan Ketat**:
  Proteksi *directory traversal* (`../`, null bytes) memastikan akses file dibatasi hanya di dalam folder `MidnightPDF-data/`.
- **Deep Linking**:
  Menggunakan URL Hash (`#file=path/ke/file.pdf`), memungkinkan file yang sedang aktif dapat di-bookmark atau di-share.

---

## 📁 Struktur Direktori

```text
JSMidnight/
├── MidnightPDF.php         # Single-file source code (PHP, HTML, CSS, JS)
├── README.md               # Dokumentasi proyek
└── MidnightPDF-data/       # Direktori penyimpanan file & folder yang diindeks
    ├── Catatan Kuliah/
    │   └── Sistem Operasi Komputer/
    │       └── Pertemuan 1.pdf
    ├── Galeri Foto/
    ├── Koleksi Audio/
    ├── Source Code/
    └── Video Rekaman/
```

> **Catatan**: Jika folder `MidnightPDF-data/` belum ada saat script pertama kali dijalankan, `MidnightPDF.php` akan membuatnya secara otomatis dengan izin `0755`.

---

## 🚀 Cara Menjalankan

### Persyaratan Sistem
- **PHP 7.4+** atau **PHP 8.x** (disarankan PHP 8.0 ke atas).
- Ekstensi PHP standar: `fileinfo`, `json`, `iconv` / `mbstring`.
- Koneksi internet (untuk memuat library CDN publik: PDF.js, Highlight.js, Google Fonts).

### Menjalankan Server Lokal (PHP Built-in Server)

1. Buka terminal di direktori proyek:
   ```bash
   cd /path/to/JSMidnight
   ```

2. Jalankan PHP built-in development server:
   ```bash
   php -S 127.0.0.1:8090
   ```

3. Buka browser dan akses:
   ```text
   http://127.0.0.1:8090/MidnightPDF.php
   ```

### Menjalankan di Web Server (Apache / Nginx)
- Letakkan file `MidnightPDF.php` dan folder `MidnightPDF-data/` ke dalam direktori publik web server Anda (misalnya `htdocs` pada XAMPP atau `/var/www/html/`).
- Pastikan web server memiliki hak akses baca (*read*) pada folder `MidnightPDF-data/`.

---

## ⌨️ Pintasan Keyboard (Shortcuts)

| Shortcut | Aksi |
| :--- | :--- |
| `Ctrl` + `B` / `Cmd` + `B` | Buka / tutup side pane |
| `Escape` | Tutup file yang sedang dibuka atau tutup drawer mobile |
| `[` | Halaman PDF sebelumnya |
| `]` | Halaman PDF selanjutnya |

---

## 🛠️ Library Eksternal (CDN)

Aplikasi ini memanfaatkan library yang dimuat via CDN:
- [Mozilla PDF.js](https://cdnjs.com/libraries/pdf.js) - PDF Canvas Renderer
- [Highlight.js](https://highlightjs.org/) - Syntax highlighter kode dan teks
- [Google Fonts](https://fonts.google.com/) - Font *Plus Jakarta Sans* & *JetBrains Mono*

---

## 📄 Lisensi

Proyek ini berada di bawah lisensi terbuka. Silakan dikembangkan dan dimodifikasi sesuai kebutuhan Anda.
