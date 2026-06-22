# PROMPT_CLAUDE_CODE.md — AgencyMind Fase 1 (Fondasi)

Dokumen ini berisi prompt eksekusi siap-pakai untuk Claude Code. Tempel per-bagian (jangan sekaligus semua) ke sesi Claude Code di root repo, secara berurutan. Setiap bagian punya **Deliverable** dan **Verifikasi** — jangan lanjut ke bagian berikutnya sebelum verifikasi lolos.

Prasyarat: `CLAUDE.md`, `SKILL.md`, dan `docs/PRD_AgencyMind_v1_0.md` sudah ada di root repo — Claude Code akan membacanya otomatis sebagai context.

Target akhir Fase 1 (sesuai PRD Bagian 16): platform bisa menerima & menyimpan log dari minimal 1 proyek klien pilot, dengan isolasi schema antar-tenant terverifikasi bekerja.

---

## Langkah 0 — Setup Proyek

```
Inisialisasi proyek Laravel 13 baru bernama AgencyMind. Gunakan PHP 8.3+.
Setup koneksi PostgreSQL di .env (database utama `agencymind`).
Install dan konfigurasi:
- Laravel Horizon (untuk queue Redis)
- Laravel Pulse (observability internal platform)
- Laravel Sanctum (untuk auth API service-to-service nanti)

Buat struktur folder berikut di app/ sesuai pembagian modul di CLAUDE.md:
- app/Domain/Tenancy (logic resolve & switch schema tenant)
- app/Domain/LogIngest (parsing & normalisasi log)
- app/Domain/Agent (tool registry, agent classes — kosongkan dulu, isi di Fase 2)
- app/Services (LLMGatewayService kosong dulu sebagai interface, implementasi di Fase 2)

Jangan install dependency LLM provider (OpenAI/Anthropic SDK) dulu — itu scope Fase 2.
Commit dengan pesan: "chore: initial Laravel 13 setup + Horizon + Pulse"
```

**Deliverable:** `php artisan serve` jalan, Horizon dashboard (`/horizon`) bisa diakses, Pulse dashboard (`/pulse`) bisa diakses.
**Verifikasi:** `php artisan horizon:status` dan migrasi default berjalan tanpa error.

---

## Langkah 1 — Skema Database Inti (Central Schema)

```
Buat migration untuk schema central (public) sesuai Bagian 9.1 PRD:

1. users: id, name, email, password, role (enum: owner, lead_dev, developer, admin),
   discord_user_id (nullable), timestamps
2. clients: id, name, slug (unique), contact_email, schema_name (unique),
   status (enum: active, paused, archived), sop_validation_profile_id (nullable, FK),
   timestamps
3. user_client_access: id, user_id (FK), client_id (FK), access_level (enum: read, write, admin)
4. validation_profiles: id, client_id (nullable FK, null = profil default agensi),
   name, rules_json (json), timestamps
5. audit_logs: id, user_id (FK), client_id (FK nullable), action_type, command_text (nullable),
   payload_json (json nullable), result_summary (text nullable), created_at (TIDAK ada updated_at,
   ini append-only)

PENTING — audit_logs harus append-only di level aplikasi:
- Buat AuditLog model dengan $timestamps = false dan created_at di-set manual
- JANGAN buat method update() atau delete() di AuditLog model
- Tambahkan test yang memverifikasi tidak ada route/controller yang bisa update/delete audit_logs

Buat factory + seeder minimal untuk testing (1 klien pilot, 1 user tiap role).
Commit: "feat: skema database central sesuai PRD bagian 9.1"
```

**Deliverable:** migration jalan bersih (`php artisan migrate:fresh --seed`), model + factory ada.
**Verifikasi:** tulis test PHPUnit yang mencoba update/delete record `audit_logs` lewat Eloquent dan pastikan ditolak/tidak ada jalurnya.

---

## Langkah 2 — Arsitektur Multi-Tenant (Schema-per-Client)

```
Implementasikan isolasi schema-per-tenant sesuai SKILL.md Bagian 1:

1. Buat TenantManager service: createSchemaForClient(Client $client),
   switchToTenant(Client $client), resetToCentral()
2. Saat client baru dibuat (observer/event CREATED pada model Client):
   - generate schema_name (mis. tenant_{id})
   - jalankan CREATE SCHEMA
   - jalankan migration khusus tenant (lihat langkah berikut) ke schema baru
3. Buat middleware SetTenantSchema yang resolve client dari route parameter
   dan SET search_path sebelum request masuk controller
4. PENTING: buat juga helper untuk job/queue, karena job Horizon TIDAK otomatis
   mewarisi search_path dari request. Setiap job yang menyentuh data tenant harus
   terima client_id di payload dan set search_path sendiri di awal handle().

Buat migration untuk tenant schema sesuai Bagian 9.2 PRD:
- log_sources: id, type (enum: push_api, ssh_pull, webhook), config_encrypted (text),
  polling_interval_seconds, last_polled_at (nullable), status, timestamps
- log_entries: id, log_source_id, timestamp, level, source_type, raw_message (text),
  stack_trace (text nullable), http_status (nullable), context_json (json nullable),
  is_parsed (boolean), created_at
  -> index pada (timestamp, level, http_status)
- incidents: id, severity, title, rca_summary (text nullable), rca_confidence (nullable),
  related_log_entry_ids (jsonb), status, resolved_by_user_id (nullable), created_at, resolved_at (nullable)
- validation_results: id, validation_profile_id, triggered_by_user_id, overall_status,
  results_json (json), created_at
- weekly_reports: id, period_start, period_end, draft_content (text), status,
  reviewed_by_user_id (nullable), sent_at (nullable), created_at

Commit: "feat: multi-tenant schema-per-client + migration tenant"
```

**Deliverable:** membuat 2 client dummy menghasilkan 2 schema PostgreSQL terpisah dengan tabel tenant lengkap di masing-masing.

**Verifikasi (wajib, ini paling kritis di seluruh Fase 1):**
```
Tulis test PHPUnit/Pest:
1. Buat client A dan client B.
2. Insert log_entries ke schema tenant A.
3. Switch context ke tenant B, query log_entries.
4. Assert: hasil query dari tenant B KOSONG (tidak melihat data tenant A).
5. Test juga lewat job (bukan cuma lewat HTTP request) untuk pastikan
   search_path job ter-set benar dan tidak bocor antar job yang dispatch berurutan
   untuk tenant berbeda.

Jangan tandai Langkah 2 selesai sebelum test ini hijau.
```

---

## Langkah 3 — Autentikasi & RBAC

```
Implementasikan autentikasi (Laravel Breeze/Sanctum untuk session web) dengan
4 role: owner, lead_dev, developer, admin.

Buat Policy/Gate per modul sesuai matriks akses PRD:
- Hanya lead_dev ke atas yang boleh override hasil validasi gagal
- Hanya lead_dev ke atas yang boleh ubah threshold (akan dipakai di Fase 2,
  tapi siapkan Gate-nya sekarang: 'manage-thresholds')
- owner & admin punya akses lintas-klien (tunduk ke user_client_access),
  developer hanya akses klien yang ditugaskan ke dia

Buat middleware tambahan yang cek user_client_access sebelum middleware
SetTenantSchema dijalankan (urutan: auth -> cek akses klien -> baru switch schema).

Commit: "feat: RBAC 4-role + policy akses per klien"
```

**Deliverable:** user dengan role `developer` yang tidak terdaftar di `user_client_access` untuk klien X mendapat 403 saat akses data klien X.
**Verifikasi:** test untuk tiap kombinasi role × access_level memastikan otorisasi sesuai matriks.

---

## Langkah 4 — API Ingest Log (Push API + Worker Pull Dasar)

```
Implementasikan ingest log sesuai Modul A.1-A.2 PRD, scope MINIMAL untuk Fase 1
(parsing penuh & deteksi anomali itu Fase 2 — di sini cukup terima & simpan):

1. POST /api/v1/clients/{slug}/logs (push API)
   - auth via Sanctum token khusus log_source (bukan token user biasa)
   - terima payload log, normalisasi minimal (timestamp, level, raw_message)
   - simpan ke log_entries di tenant schema yang benar
   - log yang tidak terparsing tetap disimpan dengan is_parsed = false
     (JANGAN dibuang diam-diam, sesuai PRD Bagian 6.A.2)

2. Worker dasar (Job) untuk SSH pull:
   - terima log_source_id, ambil file log via SSH (gunakan package SSH2 Laravel),
     parse baris sederhana, simpan ke log_entries
   - scheduling: jalankan tiap 5 menit (default) via Laravel Scheduler, interval
     dibaca dari log_sources.polling_interval_seconds
   - dispatch job ini dengan client_id di payload (lihat catatan search_path di Langkah 2)

3. Encryption kredensial: config SSH (host, user, private key path/content) di
   log_sources.config_encrypted WAJIB dienkripsi pakai Laravel Crypt, tidak pernah
   disimpan/dilog plaintext. Cek juga log driver aplikasi tidak ikut nge-log payload
   yang mengandung config_encrypted mentah.

Commit: "feat: ingest log via push API + SSH pull worker dasar"
```

**Deliverable:** kirim log dummy lewat `POST /api/v1/clients/{slug}/logs` tersimpan di schema tenant yang benar; SSH pull job berhasil ambil file log dari server pilot dummy (bisa pakai container test SSH lokal).

**Verifikasi:**
```
- Test push API: log tersimpan, isolasi tenant tetap terjaga (ulangi test
  cross-tenant dari Langkah 2 tapi lewat endpoint ini).
- Test kredensial: assert config_encrypted di database TIDAK readable sebagai
  plaintext (langsung query raw, bukan lewat accessor Eloquent).
- Test log tidak terparsing tetap tersimpan dengan is_parsed = false, tidak hilang.
```

---

## Langkah 5 — Pulse Observability + Checkpoint Akhir Fase 1

```
Pastikan Laravel Pulse mencatat:
- Antrean job Horizon (termasuk job SSH pull yang baru dibuat)
- Slow query database
- Exception rate aplikasi

Buat 1 dashboard/Card Pulse kustom sederhana yang menampilkan jumlah log_entries
masuk per client dalam 24 jam terakhir — ini fondasi untuk dashboard Owner di
Fase 3/4, belum perlu lengkap sekarang.

Tulis README singkat docs/fase1-checkpoint.md berisi:
- Apa yang sudah selesai vs PRD Bagian 16 Fase 1
- Hasil verifikasi isolasi tenant (ringkasan, bukan full test output)
- Known limitation yang sengaja ditunda ke Fase 2

Commit: "chore: pulse observability + checkpoint dokumentasi fase 1"
```

**Deliverable:** Pulse dashboard menampilkan metrik di atas; `docs/fase1-checkpoint.md` ada.
**Verifikasi:** baca ulang Deliverable Fase 1 di PRD Bagian 16 — checklist manual: "platform dapat menerima dan menyimpan log dari minimal 1 proyek klien pilot, terverifikasi isolasi schema bekerja." Kalau ya untuk keduanya, Fase 1 selesai dan siap lanjut Fase 2 (Otak Agentic).

---

## Catatan Pemakaian

- Jangan minta Claude Code mengerjakan Langkah 2 dan seterusnya dalam satu prompt panjang — schema-per-tenant ini bagian paling rawan bug diam-diam (data bocor lintas klien tanpa error eksplisit), jadi wajib checkpoint manual per langkah.
- Kalau Claude Code mengusulkan pendekatan tenancy berbeda (mis. pakai package `stancl/tenancy`) dari yang ditulis di sini, itu boleh didiskusikan — tapi prinsip isolasi & verifikasi di Langkah 2 tetap wajib dipenuhi apa pun implementasinya.
- Setelah Fase 1 checkpoint lolos, lanjut ke prompt Fase 2 (LLM Gateway + Log Analyst Agent) — akan dibuatkan terpisah saat saatnya tiba.
