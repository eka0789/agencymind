# Checkpoint Fase 3 - Integrasi Antarmuka & Notifikasi AgencyMind

Fase 3 (Integrasi Antarmuka & Notifikasi) dari platform AgencyMind telah berhasil diimplementasikan sepenuhnya. Seluruh unit, integration, dan feature tests terkait Service-to-Service Authentication, Project Validation Engine, Discord command endpoints, dan Node.js bot integration lolos dengan sukses. Dokumentasi di bawah ini merangkum arsitektur teknis dan cara verifikasi sistem integrasi ini.

---

## 1. Service-to-Service REST API Authentication

Untuk menjamin keamanan komunikasi antara bot Discord (presentation layer) dan backend Laravel (core logic), platform menggunakan token Bearer kustom.

### Detail Keamanan
- **Middleware `ServiceToServiceAuth`**: Memotong request ke endpoint `/api/v1/commands/*` dan memverifikasi header `Authorization: Bearer <token>` terhadap parameter `DISCORD_BOT_API_TOKEN` yang disimpan di berkas `.env`.
- **Alias Middleware**: Didaftarkan sebagai `'auth.service'` di `bootstrap/app.php` untuk memfasilitasi penggunaan deklaratif pada grup rute.
- **Pengecekan Kredensial**: Memastikan bot tidak memiliki akses langsung ke database publik atau tenant di luar otorisasi API terstruktur.

---

## 2. Modul Validasi Proyek Otonom (Validation Engine)

Membangun modul kepatuhan format/SOP yang dapat mengecek proyek secara rule-based maupun memberikan analisis kualitatif arsitektural berbasis LLM.

### A. Komponen Utama
- **Model `ValidationResult`**: Model Eloquent dalam skema tenant yang merekam `overall_status` (`pass`, `pass_with_notes`, `fail`) dan JSON detail pemeriksaan.
- **`ValidationEngine` Service**:
  - **Pemeriksaan Keamanan**: Mendeteksi berkas `.env` yang ter-commit ke git, status `APP_DEBUG=true` di produksi, absennya security headers (CSP, X-Frame-Options), dan scanning regex rekursif terhadap password, api keys, atau secrets di berkas PHP/JS.
  - **Pemeriksaan Performa**: Mendeteksi setup build assets minifikasi (file `.min.js` / `.min.css` atau skrip build di `package.json`).
  - **Pemeriksaan Dokumentasi**: Memverifikasi adanya berkas `README.md` dan `.env.example`.
- **Validation Agent (LLM)**: Mengirimkan metadata struktur folder dan temuan rule-based ke LLM Gateway untuk memberikan umpan balik arsitektur kualitatif dan catatan saran pembenahan.
- **Status Keputusan**: Jika ada pengecekan berbobot `Blocker` (seperti `.env` terekspos atau `APP_DEBUG=true`) yang gagal, status proyek otomatis dipaksa menjadi `fail`.

---

## 3. Discord Bot Command REST API

Rute-rute khusus disediakan di `routes/api.php` untuk melayani perintah Discord bot secara asinkron dengan integrasi audit logging global.

### Endpoint Command
- **`POST /api/v1/commands/log-check`**:
  - Request: `{ "client_slug": "toko-abc", "range": "24h", "discord_channel_id": "...", "requested_by_discord_id": "..." }`
  - Aksi: Membuat audit log global, mendispatch job `ProcessDiscordLogCheck` ke antrean prioritas `critical`, dan langsung mengembalikan status `202 Accepted` ke bot.
- **`POST /api/v1/commands/validate`**:
  - Request: `{ "project_slug": "toko-abc", "discord_channel_id": "...", "requested_by_discord_id": "...", "project_path": "..." }`
  - Aksi: Mengantrekan job `ProcessDiscordProjectValidation` ke antrean prioritas `critical` untuk memproses validasi proyek.
- **`POST /api/v1/commands/threshold-set`**:
  - Request: `{ "client_slug": "toko-abc", "parameter": "error_spike", "value": 20, "requested_by_discord_id": "..." }`
  - Aksi: Memperbarui batas anomali log klien di `ValidationProfile` secara langsung.
- **`GET /api/v1/clients/{client}/status-detailed`**:
  - Request: Header bearer token + parameter client.
  - Aksi: Mengembalikan data real-time: status kesehatan klien (healthy/warning/critical), jumlah insiden terbuka, dan data laporan validasi proyek terakhir.

---

## 4. Discord Bot Presentation Layer (Node.js)

Bot Discord dikembangkan sebagai layanan Node.js ringan yang terletak di folder `/discord-bot`.

### Struktur File Bot
- **`discord-bot/package.json`**: Menyediakan dependensi `discord.js` untuk interaksi gateway Discord, `express` untuk server webhook callback, dan `axios` untuk HTTP requests ke Laravel.
- **`discord-bot/bot.js`**:
  - Mendaftarkan application slash commands (`/log-check`, `/validate`, `/status`, `/threshold`) menggunakan Discord REST API.
  - Mendengarkan trigger interaksi slash command, memicu reply penantian (*defer reply*), dan mengirim data perintah ke API Laravel.
- **`discord-bot/server.js`**:
  - Server Express yang mendengarkan callback POST `/api/v1/webhooks/job-result` dari antrean worker Laravel.
  - Memetakan hasil pekerjaan menjadi format Embed Discord yang estetik (merah untuk kegagalan, hijau untuk kelulusan, dan biru untuk analisis ringkasan logs).
  - Mengirimkan pesan notifikasi beserta mention ping ke developer peminta.

---

## 5. Cara Menjalankan & Memverifikasi

### A. Menjalankan Tes Backend Lengkap (Fase 1, 2 & 3)
Jalankan seluruh test suite untuk memastikan semua unit, integration, dan feature tests Fase 1 hingga Fase 3 lolos:
```bash
php artisan test
```
Seluruh **29 tes** terverifikasi lolos 100% dengan total 124 assertions.

### B. Konfigurasi Lingkungan Bot
Untuk mengaktifkan bot Discord, pastikan variabel berikut diatur pada berkas `.env` di root workspace:
```env
DISCORD_BOT_TOKEN=your-discord-bot-token
DISCORD_CLIENT_ID=your-discord-application-client-id
DISCORD_BOT_API_TOKEN=your-secret-service-token
DISCORD_BOT_PORT=3000
AGENCYMIND_API_URL=http://localhost:8000/api
```
Untuk menginstal dependensi bot Node.js dan menjalankannya:
```bash
cd discord-bot
npm install
node server.js
```
