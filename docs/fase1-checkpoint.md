# Checkpoint Fase 1 - Fondasi AgencyMind

Fase 1 (Fondasi) untuk AgencyMind telah berhasil diimplementasikan sepenuhnya. Seluruh unit dan feature tests lolos dengan sukses. Dokumentasi di bawah ini merangkum arsitektur, implementasi teknis, dan verifikasi sistem.

---

## 1. Arsitektur Database & Multi-Tenancy

AgencyMind menggunakan arsitektur **PostgreSQL Single Database dengan Tenancy Berbasis Schema-per-Client** untuk memadukan isolasi data maksimal dengan kesederhanaan infrastruktur.

### A. Central Schema (`public`)
Menampung data global dan koordinasi multi-tenant:
- `users`: Pengguna platform global dengan role (`owner`, `lead_dev`, `developer`, `admin`).
- `clients`: Daftar klien (digital agencies) penyewa platform. Kolom `schema_name` (misalnya `tenant_push_client`) digunakan untuk menghubungkan klien dengan skema PostgreSQL mereka.
- `user_client_access`: Pivot tabel yang mendefinisikan hubungan antara user dan client beserta level akses mereka.
- `validation_profiles`: Aturan validasi log yang terdefinisi secara global.
- `audit_logs`: Log kepatuhan hukum (*compliance*) platform. Dikonfigurasi agar strictly **append-only** menggunakan Eloquent observer yang mencegah update dan delete.

### B. Dynamic Tenant Schemas (`tenant_*`)
Setiap kali klien baru dibuat, observer `ClientObserver` akan memicu pembentukan skema PostgreSQL baru (`CREATE SCHEMA tenant_...`) secara dinamis, diikuti dengan eksekusi migrasi tabel tenant:
- `log_sources`: Sumber penarikan log (API push, SSH pull, webhook). Kredensial sensitif disimpan terenkripsi menggunakan AES-256 (`Crypt::encryptString`).
- `log_entries`: Log terperinci yang berhasil di-ingest dari client server.
- `incidents`: Insiden anomali log terdeteksi.
- `validation_results`: Hasil validasi kepatuhan format log.
- `weekly_reports`: Laporan analitik berkala klien.

---

## 2. Autentikasi & RBAC (Role-Based Access Control)

### Role & Permission Gates
Ada 4 role global dengan gate otonom:
- **Owner**: Kontrol penuh atas seluruh sistem dan penyewa klien.
- **Admin**: Operasional administratif global, setup klien, dan profiling.
- **Lead Dev**: Mengelola konfigurasi teknis klien, ambang batas error, dan penanganan log.
- **Developer**: Melihat log klien yang terasosiasi dengannya, membuat laporan.

Akses ke klien dibatasi secara ketat menggunakan middleware `UserAccessMiddleware` yang mengecek tabel `user_client_access`. Pengguna dengan role `owner` dan `admin` mendapatkan akses *bypass* otomatis ke seluruh klien.

---

## 3. Engine Ingest Log (Push & Pull)

### A. API Push Ingest (`POST /api/v1/clients/{client}/logs`)
- Menggunakan token autentikasi **Sanctum** yang diterbitkan khusus untuk `LogSource`.
- Untuk mengatasi urutan eksekusi middleware Laravel, middleware `SetTenantSchema` diposisikan mendahului `AuthenticatesRequests` dalam urutan prioritas middleware (`bootstrap/app.php`). Ini memastikan koneksi database sudah berganti skema ke penyewa yang tepat sebelum Sanctum mengidentifikasi kepemilikan token `LogSource`.
- Menerima batching payload log dan menyimpannya. Data log yang tidak lengkap atau rusak disimpan dengan bendera `is_parsed = false`.

### B. SSH/SFTP Pull Job (`PullLogsFromSSH`)
- Job antrean asinkron (`TenantAwareJob`) yang terintegrasi dengan middleware `SwitchTenantSchema` untuk memastikan context `search_path` diswitch otomatis ke tenant target sebelum job berjalan, dan dikembalikan ke `public` setelah selesai.
- Membuka koneksi SFTP aman menggunakan kredensial terenkripsi (SSH key / kata sandi) melalui pustaka `phpseclib3`.
- Mendukung pemisahan multi-line stack traces (dikelompokkan berdasarkan baris baru yang diawali spasi atau tanda pagar `#`).

---

## 4. Observability & Laravel Pulse Customization

Kami mengimplementasikan sebuah custom card Livewire pada Laravel Pulse (`livewire:pulse.client-logs-card`) untuk memonitor status kesehatan penarikan log masing-masing penyewa klien secara real-time.

### Fitur Custom Card:
- **Dynamic Period**: Menghitung volume log masuk menyesuaikan periode waktu yang dipilih pada dashboard Pulse (misal 1 jam, 24 jam, atau 7 hari).
- **Client Health Indicator**: Lingkaran hijau (*all good*), lingkaran merah berkedip (*error logs detected*), atau abu-abu (*inactive/error context*).
- **Stats breakdown**: Menampilkan detail jumlah total log, log level error, log level warning, dan timestamp log terakhir masuk.
- **Lazy Loading & Performance**: Menggunakan attribute `#[Lazy]` bawaan Livewire 3 untuk menjaga performa rendering awal dashboard Pulse tetap cepat.

---

## 5. Cara Menjalankan & Memverifikasi

### A. Menjalankan Tes Backend (PHPUnit)
Jalankan seluruh test suite dengan perintah berikut:
```bash
php artisan test
```
Seluruh 11 tes penanganan tenancy isolasi skema, push API, SFTP pull worker, autentikasi, append-only audit logs, dan rendering Livewire Pulse card akan dieksekusi dan lolos secara otomatis.

### B. Menjalankan Server Lokal & Queue
Untuk menjalankan server lokal, antrean Horizon/Queue worker, dan visualizer asset secara bersamaan:
```bash
npm run dev
```
Dashboard Laravel Pulse akan dapat diakses secara lokal di alamat: `http://localhost:8000/pulse`.
Dashboard Laravel Horizon dapat diakses di alamat: `http://localhost:8000/horizon`.
