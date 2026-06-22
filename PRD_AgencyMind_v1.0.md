# PRODUCT REQUIREMENTS DOCUMENT (PRD)

**Nama Produk:** AgencyMind (Nama Sementara) — *Platform Otomatisasi Agentic AI untuk Agensi Digital*
**Versi Dokumen:** 1.0 (Lengkap)
**Tanggal:** 20 Juni 2026
**Penanggung Jawab / Author:** Eka Prasetyo
**Status:** Draft untuk Review Internal

---

## DAFTAR ISI

1. Ringkasan Eksekutif
2. Latar Belakang & Pernyataan Masalah
3. Tujuan, Sasaran & Non-Tujuan
4. Target Pengguna (User Personas)
5. User Stories & Use Case
6. Spesifikasi Fitur Fungsional (MVP)
7. Arsitektur Sistem
8. Arsitektur Multi-Tenant
9. Skema Database
10. Spesifikasi Agentic AI
11. Spesifikasi Integrasi (API & Discord)
12. Kebutuhan Non-Fungsional
13. Keamanan & Kepatuhan
14. Observability & Monitoring
15. Metrik Keberhasilan (KPI)
16. Roadmap Implementasi
17. Kebutuhan Tim & Sumber Daya
18. Risiko & Mitigasi
19. Model Bisnis (Ringkas)
20. Lampiran

---

## 1. RINGKASAN EKSEKUTIF

AgencyMind adalah platform SaaS B2B *multi-tenant* yang dirancang untuk agensi pengembangan web dan digital, khususnya yang mengelola banyak proyek klien secara paralel. Platform ini memanfaatkan *Agentic AI* — agen cerdas yang tidak sekadar menjawab pertanyaan, tetapi secara otonom mengeksekusi serangkaian tindakan operasional (membaca log, menjalankan skrip validasi, mengirim notifikasi, menyusun laporan) tanpa intervensi manual di setiap langkah.

Fokus utama produk adalah tiga hal: (1) memantau kesehatan infrastruktur klien secara proaktif, (2) menstandarkan proses validasi proyek sebelum serah terima, dan (3) menyederhanakan pelaporan operasional ke klien. Dengan ini, agensi dapat menekan biaya operasional tim developer, mempercepat waktu deteksi-resolusi masalah, dan meningkatkan kualitas SLA tanpa menambah headcount secara linear terhadap jumlah klien.

Produk dibangun di atas Laravel 13 sebagai backend utama, dengan lapisan orkestrasi agentic terpisah, antrian job berbasis Redis/Horizon untuk proses panjang, dan integrasi command-center via Discord sebagai antarmuka kerja harian tim.

## 2. LATAR BELAKANG & PERNYATAAN MASALAH

### 2.1 Konteks
Agensi digital skala kecil-menengah (5–30 developer) biasanya mengelola 10–50+ proyek klien aktif secara bersamaan, dengan stack teknologi yang bervariasi (Laravel, Next.js, WordPress, dsb). Pemantauan kesehatan aplikasi klien umumnya dilakukan secara reaktif — developer baru menyadari ada masalah setelah klien melapor, padahal log error sudah mengindikasikan masalah sejak beberapa jam atau hari sebelumnya.

### 2.2 Masalah yang Diselesaikan

| # | Masalah | Dampak Saat Ini |
|---|---|---|
| 1 | Log server menumpuk dan jarang dibaca manual karena volume besar dan format tidak konsisten antar proyek | Bug kritikal baru ketahuan saat klien komplain, menurunkan kepercayaan klien |
| 2 | Tidak ada standar validasi seragam sebelum proyek diserahkan ke klien (tiap developer punya checklist sendiri-sendiri, sering informal) | Kualitas output proyek tidak konsisten, risiko bug lolos ke production |
| 3 | Penyusunan laporan progres mingguan ke klien dilakukan manual, memakan waktu Project Manager | Waktu PM tersita untuk hal administratif, bukan strategis |
| 4 | Tidak ada dasbor terpusat untuk melihat status kesehatan semua proyek klien sekaligus | Owner/Lead Dev tidak punya visibilitas real-time, keputusan reaktif |

### 2.3 Asumsi Kunci
- Agensi memiliki akses SSH/API terhadap server klien (VPS, shared hosting dengan akses log, atau platform cloud seperti DigitalOcean/AWS) yang diizinkan secara kontraktual untuk dipantau.
- Tim teknis agensi sudah menggunakan Discord sebagai kanal komunikasi internal harian.
- Penyedia LLM (OpenAI/Anthropic) dapat diakses dari server produksi tanpa pembatasan regional yang menghambat.

## 3. TUJUAN, SASARAN & NON-TUJUAN

### 3.1 Tujuan Utama
- **Efisiensi Waktu:** Mengurangi waktu yang dihabiskan developer untuk mencari bug atau menganalisis server log klien hingga 60% dalam 6 bulan pasca-adopsi penuh.
- **Proaktif, bukan Reaktif:** Mendeteksi anomali server atau error aplikasi klien sebelum klien menyadari dan melaporkannya sendiri, dengan target waktu deteksi-ke-notifikasi di bawah 5 menit.
- **Standardisasi:** Memastikan 100% proyek agensi melewati validasi otomatis terstandar sebelum deployment atau serah terima ke klien.

### 3.2 Sasaran Terukur (Quarter 1 Pasca-Launch)
- 80% proyek aktif agensi terhubung ke modul Analis Log dalam 3 bulan.
- Waktu rata-rata penyusunan laporan mingguan klien turun dari ~2 jam manual menjadi di bawah 15 menit (review + kirim draf AI).
- Tingkat halusinasi/false-positive analisis log di bawah 2%, divalidasi melalui sampling manual mingguan oleh Lead Developer.

### 3.3 Non-Tujuan (Out of Scope untuk MVP)
- AgencyMind **tidak** menggantikan APM (Application Performance Monitoring) penuh seperti New Relic/Datadog — fokus pada log parsing dan analisis kontekstual, bukan tracing distribusi mendalam.
- **Tidak** menangani auto-remediation (perbaikan kode otomatis tanpa review manusia) pada fase MVP — agen hanya merekomendasikan, manusia tetap mengeksekusi perubahan kode.
- **Tidak** mendukung integrasi command center selain Discord pada fase MVP (Slack/Telegram masuk roadmap fase berikutnya).
- **Tidak** menyediakan billing/invoicing otomatis ke klien agensi (di luar cakupan operasional teknis).

## 4. TARGET PENGGUNA (USER PERSONAS)

### Persona 1: Agency Owner / Project Manager — "Pak Budi"
- **Peran:** Mengelola hubungan klien, alokasi sumber daya tim, dan keputusan bisnis.
- **Kebutuhan:** Visibilitas status kesehatan seluruh proyek klien dalam satu dasbor; laporan progres siap kirim tanpa harus menyusun manual; notifikasi eskalasi jika ada masalah signifikan.
- **Frustrasi Saat Ini:** Harus menanyakan status proyek satu per satu ke developer; laporan klien sering terlambat karena menunggu data dari tim teknis.
- **Ukuran Sukses Baginya:** Bisa membuka satu dasbor di pagi hari dan tahu persis proyek mana yang butuh perhatian hari itu.

### Persona 2: Lead Developer / Tech Lead — "Mas Andi"
- **Peran:** Memvalidasi kualitas kode, menjaga standar teknis, menjadi eskalasi pertama saat ada insiden produksi.
- **Kebutuhan:** Asisten yang merangkum log error teknis (bukan sekadar dump mentah), validasi otomatis sebelum approve deployment, kemampuan query cepat lewat chat ("cek anomali log klien X hari ini").
- **Frustrasi Saat Ini:** Menghabiskan waktu menyaring ribuan baris log untuk menemukan akar masalah; checklist validasi manual sering terlewat saat deadline mepet.
- **Ukuran Sukses Baginya:** Root cause analysis tersedia dalam hitungan menit, bukan jam, dan akurat secara teknis.

### Persona 3: Developer Pelaksana — "Sari"
- **Peran:** Mengerjakan implementasi fitur dan perbaikan bug harian di berbagai proyek klien.
- **Kebutuhan:** Notifikasi langsung di Discord saat ada error baru di proyek yang ia pegang, tanpa harus login ke server klien satu per satu.
- **Frustrasi Saat Ini:** Konteks proyek klien (kredensial server, struktur log) tersebar dan tidak terdokumentasi rapi antar developer.

## 5. USER STORIES & USE CASE

| ID | Sebagai | Saya ingin | Sehingga | Persona |
|---|---|---|---|---|
| US-01 | Lead Developer | menerima notifikasi otomatis saat error 500 melebihi ambang batas di proyek klien tertentu | saya bisa menangani sebelum klien komplain | Mas Andi |
| US-02 | Lead Developer | mengetik perintah di Discord untuk menanyakan ringkasan anomali log klien tertentu | saya tidak perlu membuka dasbor atau SSH manual | Mas Andi |
| US-03 | Agency Owner | melihat dasbor status kesehatan semua proyek klien dalam satu tampilan | saya bisa memprioritaskan perhatian tim | Pak Budi |
| US-04 | Agency Owner | menerima draf laporan mingguan otomatis per klien yang siap edit dan kirim | saya hemat waktu administratif | Pak Budi |
| US-05 | Lead Developer | menjalankan validasi otomatis sebelum proyek diserahkan ke klien | kualitas proyek konsisten sesuai SOP agensi | Mas Andi |
| US-06 | Developer Pelaksana | melihat root cause analysis dalam bahasa yang mudah dipahami, bukan log mentah | saya bisa langsung fokus memperbaiki | Sari |
| US-07 | Admin Platform | mengisolasi data log dan kredensial setiap klien agar tidak bocor lintas tenant | keamanan data klien terjaga | (Internal) |

## 6. SPESIFIKASI FITUR FUNGSIONAL (MVP)

### MODUL A — Agen Analis Server Log (Proactive Maintenance)

**A.1 Koneksi Sumber Log**
- Mendukung 3 metode ingest log per proyek klien:
  1. **Push API** — aplikasi klien mengirim log via HTTP POST ke endpoint AgencyMind (cocok untuk aplikasi Laravel klien yang ditambahkan log driver kustom).
  2. **Pull via SSH/SFTP** — worker terjadwal menarik file log dari server klien (cocok untuk shared hosting/VPS tanpa akses API).
  3. **Webhook Forwarder** — integrasi dengan layanan logging klien yang sudah ada (mis. Laravel Telescope export, Papertrail forward) bila tersedia.
- Setiap proyek klien dikonfigurasi dengan **Log Source Profile**: tipe sumber, kredensial terenkripsi, path/pattern file log, interval polling (default 5 menit untuk pull, real-time untuk push).

**A.2 Parsing & Normalisasi**
- Parser mendukung format log umum: Laravel log (`storage/logs/laravel.log`), Nginx/Apache access & error log, format JSON terstruktur generik.
- Setiap baris log dinormalisasi ke struktur internal seragam: `timestamp, level, source_type, raw_message, stack_trace (jika ada), http_status (jika relevan), context_json`.
- Log yang tidak terparsing (format tidak dikenali) tetap disimpan sebagai `raw_unparsed` dengan flag untuk audit, tidak dibuang diam-diam.

**A.3 Deteksi Anomali**
- Threshold dikonfigurasi per proyek (dapat di-override dari default agensi), contoh:
  - Error 500 ≥ 10 kejadian dalam 5 menit → trigger alert tingkat "Warning".
  - Error 500 ≥ 30 kejadian dalam 5 menit, atau pola error yang sama berulang 3x dalam 1 jam → trigger alert tingkat "Critical".
  - Query lambat (>2 detik, dapat dikonfigurasi) terdeteksi berulang ≥5x dalam 10 menit → trigger alert "Performance".
- Deteksi anomali memakai kombinasi rule-based threshold (cepat, deterministik, tanpa biaya LLM) sebagai filter awal, **baru kemudian** kejadian yang melewati threshold dikirim ke agen LLM untuk root cause analysis — ini penting agar biaya API LLM terkendali dan tidak memproses seluruh volume log mentah.

**A.4 Root Cause Analysis (RCA) oleh Agen**
- Saat anomali terdeteksi, agen menerima konteks: kumpulan baris log terkait, stack trace, metadata proyek (framework, versi, dependency relevan jika tersedia dari `composer.lock`/`package.json` yang di-cache).
- Agen LLM menghasilkan ringkasan terstruktur:
  - **Apa yang terjadi** (1–2 kalimat)
  - **Kemungkinan akar penyebab** (berdasarkan bukti dari log, bukan tebakan generik)
  - **Tingkat keyakinan** (High/Medium/Low — agen wajib menyatakan ini secara eksplisit)
  - **Rekomendasi langkah investigasi/perbaikan**
- **Guardrail anti-halusinasi:** Agen diinstruksikan secara eksplisit untuk menyatakan "tidak cukup data untuk menentukan akar penyebab" jika bukti log tidak memadai, alih-alih mengarang penyebab. Setiap klaim teknis dalam RCA harus tertaut ke baris log spesifik yang menjadi rujukan (citation internal).

**A.5 Notifikasi**
- Alert "Critical" dikirim segera ke channel Discord proyek terkait + mention role Lead Developer.
- Alert "Warning"/"Performance" masuk ke digest yang dikirim setiap 1 jam (dapat dikonfigurasi) untuk menghindari notification fatigue.

### MODUL B — Validasi Proyek Otonom

**B.1 Definisi Checklist SOP**
- Agensi mendefinisikan **Validation Profile** berbasis YAML/JSON yang berisi daftar pengecekan, dikelompokkan dalam kategori:
  - **Keamanan:** `.env` tidak ter-commit, `APP_DEBUG=false` di production, header keamanan dasar (CSP, X-Frame-Options) ada, tidak ada credential hardcoded terdeteksi via regex scan.
  - **Performa:** asset ter-minify/bundle, query N+1 terdeteksi via static analysis sederhana pada kode yang di-submit, index database pada kolom foreign key utama.
  - **Struktur Database:** naming convention tabel/kolom konsisten, foreign key constraint terdefinisi, migration history bersih (tidak ada migration yang di-skip).
  - **Kelengkapan Dokumentasi:** README ada, environment variable terdokumentasi di `.env.example`.
- Setiap item checklist punya bobot (severity: Blocker/Major/Minor) yang memengaruhi status akhir validasi (Lulus/Lulus dengan Catatan/Gagal).

**B.2 Eksekusi Validasi**
- Trigger manual (developer menjalankan command "validasi proyek X" di Discord) atau otomatis (terhubung ke webhook CI/CD, mis. saat branch `main` di-push atau sebelum tag rilis).
- Validasi otomatis (rule-based, non-LLM) dijalankan lebih dulu — hasilnya pasti dan dapat direproduksi.
- Hasil validasi rule-based + struktur proyek (file tree ringkas, daftar dependency) dikirim ke agen LLM untuk **penilaian kualitatif tambahan**: apakah arsitektur kode secara umum mengikuti praktik baik sesuai konteks SOP agensi (mis. pemisahan layer Controller-Service-Repository jika itu standar agensi).
- LLM **tidak** menggantikan rule-based check untuk hal yang bisa dipastikan secara deterministik (keamanan, struktur DB) — LLM hanya menambahkan lapisan penilaian kualitatif/arsitektural yang sulit diaturkan lewat regex.

**B.3 Output Validasi**
- Laporan validasi terstruktur per kategori, dengan status tiap item (Pass/Fail/Warning), referensi file:baris untuk item yang gagal, dan rekomendasi perbaikan.
- Status akhir proyek: **Blocker** apa pun yang gagal → status keseluruhan "Gagal", tidak boleh diserahkan ke klien tanpa override manual oleh Lead Developer (dengan catatan alasan override tersimpan sebagai audit log).

### MODUL C — Command Center & Integrasi Komunikasi

**C.1 Discord Bot — Perintah Inti**
| Command | Fungsi | Contoh |
|---|---|---|
| `/log-check [klien] [rentang]` | Memicu agen meringkas anomali log proyek tertentu | `/log-check klien:toko-abc rentang:24h` |
| `/validate [proyek]` | Memicu validasi proyek otonom | `/validate proyek:toko-abc-v2` |
| `/status [klien]` | Menampilkan status kesehatan terkini proyek klien | `/status klien:toko-abc` |
| `/report-weekly [klien]` | Memicu pembuatan draf laporan mingguan | `/report-weekly klien:toko-abc` |
| `/threshold set [klien] [parameter] [nilai]` | Mengubah ambang batas alert per klien | `/threshold set klien:toko-abc error500:15` |

**C.2 Alur Eksekusi Command**
1. User mengetik command di channel Discord proyek/klien terkait.
2. Bot memvalidasi otorisasi user (role Discord dipetakan ke role internal AgencyMind: Owner/Lead Dev/Developer) — command sensitif (mis. `/threshold set`) dibatasi untuk role tertentu.
3. Bot membalas dengan acknowledgment instan ("Sedang memproses, mohon tunggu...") agar tidak terkesan macet, lalu **mendispatch job ke queue** (bukan diproses sinkron di request Discord, karena analisis log/LLM call bisa memakan waktu 5–30 detik).
4. Worker memproses job, memanggil agen LLM jika diperlukan, menyusun hasil.
5. Bot mengirim hasil sebagai pesan terstruktur (embed Discord) ke channel asal, dengan thread reply untuk detail tambahan jika hasil panjang.
6. Seluruh interaksi command tercatat di **Audit Log** (siapa, kapan, command apa, hasil apa) untuk keperluan akuntabilitas dan analisis pemakaian.

**C.3 Arsitektur Integrasi**
- Bot Discord berjalan sebagai service terpisah (Node.js/Python ringan atau Laravel dengan listener gateway) yang berkomunikasi dengan backend Laravel via internal REST API yang diautentikasi token, **bukan** logic agentic ditulis langsung di dalam bot — bot hanya lapisan presentasi/command-parsing.
- Kerangka eksekusi agentic (orkestrasi tool-calling, manajemen konteks, chaining langkah) berjalan di sisi backend Laravel, memudahkan audit, testing, dan penggantian channel komunikasi (Discord → Slack di masa depan) tanpa menulis ulang logic agent.

### MODUL D — Manajemen Multi-Tenant & Pelaporan

**D.1 Isolasi Data Klien**
- Lihat Bagian 8 (Arsitektur Multi-Tenant) untuk detail teknis.

**D.2 Laporan Progres Otomatis**
- Setiap akhir minggu (terjadwal, dapat dipicu manual), agen menyusun draf laporan per klien berisi:
  - Ringkasan insiden/anomali yang terdeteksi dan status penyelesaiannya.
  - Ringkasan hasil validasi proyek (jika ada aktivitas deployment minggu itu).
  - Metrik dasar: uptime perkiraan, jumlah error tertangani, waktu respons rata-rata terhadap insiden.
- Draf laporan tersedia dalam 2 format: teks siap-tempel untuk email, dan dokumen terformat (PDF/Word) untuk klien yang membutuhkan laporan formal.
- **Wajib direview manusia** sebelum dikirim ke klien — sistem tidak mengirim laporan ke klien secara otomatis tanpa approval PM (mencegah risiko reputasi dari potensi kesalahan AI yang tidak terdeteksi).

## 7. ARSITEKTUR SISTEM

### 7.1 Komponen Utama
```
┌─────────────────┐      ┌──────────────────┐      ┌────────────────────┐
│   Discord Bot    │◄────►│   Laravel API     │◄────►│  Agent Orchestrator │
│  (Command Layer) │      │  (Core Backend)   │      │  (Agentic Logic)    │
└─────────────────┘      └──────────────────┘      └──────────┬─────────┘
                                   │                            │
                          ┌────────┴────────┐         ┌─────────┴─────────┐
                          │  Redis + Horizon │         │   LLM Gateway      │
                          │  (Queue/Jobs)     │         │ (OpenAI/Claude API)│
                          └────────┬────────┘         └────────────────────┘
                                   │
                          ┌────────┴────────┐
                          │   PostgreSQL     │
                          │ (Multi-tenant DB)│
                          └────────┬────────┘
                                   │
                          ┌────────┴────────┐
                          │  Log Ingestion   │
                          │  Workers (SSH/   │
                          │  Push API)       │
                          └────────┬────────┘
                                   │
                          ┌────────┴────────┐
                          │  Server Klien    │
                          │ (Sumber Log)     │
                          └─────────────────┘

         ┌─────────────────────────────┐
         │   Laravel Pulse (Internal)  │
         │  Monitoring beban job AI,   │
         │  antrean, query lambat      │
         └─────────────────────────────┘
```

### 7.2 Tech Stack & Rasionalisasi

| Komponen | Pilihan | Alasan |
|---|---|---|
| Backend Framework | Laravel 13 | Ekosistem matang, routing & API resource yang kuat, tim sudah familiar |
| Bahasa | PHP 8.3+ | Sesuai requirement Laravel 13, performa JIT lebih baik |
| Database Utama | PostgreSQL 16 | Dukungan JSONB kuat untuk menyimpan context_json log, skema relasional matang untuk multi-tenant, dukungan row-level security native |
| Queue/Job | Redis + Laravel Horizon | Visibilitas real-time terhadap job AI yang long-running, kemampuan prioritization queue (Critical alert > laporan mingguan) |
| Monitoring Internal | Laravel Pulse | Memantau beban job AI, antrean mana yang bottleneck, query lambat internal platform sendiri |
| LLM Gateway | OpenAI GPT-4o / Anthropic Claude (dual-provider, dapat dikonfigurasi) | Redundansi provider, dapat memilih model sesuai trade-off biaya vs akurasi per jenis task |
| Agentic Orchestration | Custom layer di Laravel (tool-calling pattern) | Kontrol penuh atas audit log, guardrail, dan biaya — dibanding bergantung penuh pada framework pihak ketiga yang kurang transparan untuk kebutuhan compliance |
| Command Interface | Discord Bot (discord.js atau setara) | Tim sudah menggunakan Discord harian, latency rendah untuk command-response |
| Object/File Storage | S3-compatible (DigitalOcean Spaces/AWS S3) | Penyimpanan arsip log mentah jangka panjang di luar database utama |
| Search/Log Indexing | PostgreSQL full-text search (MVP) → Meilisearch (jika volume tumbuh) | Mulai sederhana, upgrade saat volume log menuntut pencarian lebih cepat |

### 7.3 Alur Data Log (End-to-End)
1. Worker pull/push menerima log mentah dari server klien.
2. Log mentah disimpan di S3-compatible storage (arsip, retention 90 hari default) **dan** dinormalisasi ke tabel `log_entries` di PostgreSQL (retention 30 hari untuk query cepat, lebih lama diarsipkan).
3. Rule-engine memeriksa threshold anomali terhadap entri baru secara streaming (job queue terpisah, prioritas tinggi).
4. Jika threshold terlampaui → job RCA didispatch ke queue agentic (prioritas sesuai severity).
5. Agent Orchestrator menyusun prompt dengan konteks log terkait + metadata proyek, memanggil LLM Gateway.
6. Hasil RCA disimpan ke tabel `incidents`, ditautkan ke `log_entries` yang menjadi bukti.
7. Notifikasi didispatch ke Discord Bot via internal API.

## 8. ARSITEKTUR MULTI-TENANT

### 8.1 Strategi Isolasi
Mengingat data log klien bisa berisi informasi sensitif (path internal, kadang data pengguna yang ter-log tidak sengaja), AgencyMind menggunakan pendekatan **schema-per-tenant** di PostgreSQL (bukan shared-table dengan kolom `tenant_id` saja), dengan pertimbangan:
- Isolasi lebih kuat dibanding shared-schema — kebocoran data lintas klien akibat bug query lebih sulit terjadi karena secara struktural terpisah.
- PostgreSQL mendukung banyak schema dalam satu database secara native tanpa overhead besar, cocok untuk skala agensi (puluhan-ratusan klien, bukan jutaan tenant SaaS publik).
- Tabel **global** (akun pengguna internal agensi, daftar klien, konfigurasi platform, billing internal) tetap di schema `public`, sementara tabel **per-klien** (log_entries, incidents, validation_results) berada di schema `tenant_{client_id}`.

### 8.2 Trade-off yang Dipertimbangkan
| Pendekatan | Kelebihan | Kekurangan | Keputusan |
|---|---|---|---|
| Shared schema + tenant_id | Paling sederhana, migrasi mudah | Risiko kebocoran data jika ada bug filter query | Tidak dipilih untuk data log sensitif |
| Schema-per-tenant | Isolasi kuat, masih satu database (operasional mudah) | Migration harus dijalankan per schema, sedikit lebih kompleks | **Dipilih untuk MVP** |
| Database-per-tenant | Isolasi maksimal | Overhead operasional tinggi (backup, koneksi pool) untuk skala agensi kecil-menengah | Disiapkan sebagai opsi enterprise di roadmap lanjutan, bukan MVP |

### 8.3 Resolusi Tenant
- Setiap request API internal menyertakan `client_id` yang divalidasi terhadap akses user (role-based: user hanya bisa mengakses klien yang ditugaskan padanya, kecuali role Owner/Admin yang punya akses penuh).
- Middleware Laravel kustom (`ResolveTenantSchema`) mengatur koneksi database secara dinamis ke schema yang sesuai sebelum query dieksekusi, dan mengembalikan ke schema default setelah request selesai untuk mencegah kebocoran context antar request.

## 9. SKEMA DATABASE (RINGKASAN ENTITAS UTAMA)

### 9.1 Schema `public` (Global)

**`users`**
`id, name, email, password, role (owner|lead_dev|developer|admin), discord_user_id, created_at, updated_at`

**`clients`**
`id, name, slug, contact_email, schema_name, status (active|paused|archived), sop_validation_profile_id, created_at, updated_at`

**`user_client_access`** (pivot — siapa boleh akses klien mana)
`id, user_id, client_id, access_level (read|write|admin)`

**`validation_profiles`**
`id, client_id (nullable, null = profil default agensi), name, rules_json, created_at, updated_at`

**`audit_logs`**
`id, user_id, client_id, action_type, command_text, payload_json, result_summary, created_at`

### 9.2 Schema `tenant_{client_id}` (Per Klien)

**`log_sources`**
`id, type (push_api|ssh_pull|webhook), config_encrypted, polling_interval_seconds, last_polled_at, status, created_at`

**`log_entries`**
`id, log_source_id, timestamp, level, source_type, raw_message, stack_trace, http_status, context_json, is_parsed, created_at`
*(index pada `timestamp`, `level`, `http_status` untuk query cepat threshold)*

**`incidents`**
`id, severity (warning|critical|performance), title, rca_summary, rca_confidence (high|medium|low), related_log_entry_ids (jsonb array), status (open|acknowledged|resolved), resolved_by_user_id, created_at, resolved_at`

**`validation_results`**
`id, validation_profile_id, triggered_by_user_id, overall_status (pass|pass_with_notes|fail), results_json (detail per item checklist), created_at`

**`weekly_reports`**
`id, period_start, period_end, draft_content, status (draft|reviewed|sent), reviewed_by_user_id, sent_at, created_at`

### 9.3 Catatan Retensi Data
- `log_entries`: retensi aktif 30 hari di PostgreSQL, arsip mentah di S3 selama 90 hari, dapat dikonfigurasi per klien sesuai kontrak (beberapa klien mungkin punya kebutuhan retensi lebih panjang untuk audit kepatuhan).

## 10. SPESIFIKASI AGENTIC AI

### 10.1 Prinsip Desain Agent
- **Tool-calling terbatas dan terdaftar eksplisit** — agen tidak memiliki akses bebas, melainkan hanya dapat memanggil tool yang didefinisikan: `search_log_entries`, `get_project_metadata`, `run_validation_check`, `fetch_incident_history`. Ini mencegah agen melakukan aksi di luar cakupan yang diizinkan.
- **Wajib menyertakan rujukan bukti** — setiap klaim teknis dalam output agen (terutama RCA) harus menyertakan referensi ke `log_entry_id` spesifik yang mendasarinya, disimpan terstruktur agar dapat diverifikasi manusia.
- **Confidence scoring eksplisit** — agen wajib menyatakan tingkat keyakinan (High/Medium/Low) dan dilarang menyajikan dugaan sebagai fakta pasti.
- **Tidak ada aksi destruktif otonom** — agen tidak diberi kemampuan untuk mengubah/menghapus data di server klien, restart service, atau deploy kode. Kemampuannya terbatas pada baca, analisis, dan rekomendasi.

### 10.2 Jenis Agen pada MVP
| Agen | Trigger | Tools yang Diizinkan | Output |
|---|---|---|---|
| Log Analyst Agent | Threshold anomali terlampaui / command manual | `search_log_entries`, `fetch_incident_history`, `get_project_metadata` | RCA terstruktur |
| Validation Agent | Command `/validate` atau webhook CI/CD | `run_validation_check`, `get_project_metadata` | Laporan validasi kualitatif |
| Reporting Agent | Terjadwal mingguan / command manual | `fetch_incident_history`, hasil validasi minggu berjalan | Draf laporan klien |

### 10.3 Manajemen Biaya & Performa LLM
- Filter rule-based dijalankan **sebelum** memanggil LLM untuk meminimalkan biaya — LLM hanya dipanggil pada kejadian yang sudah lolos threshold, bukan untuk setiap baris log.
- Context window dibatasi: maksimum N baris log relevan (default 50, dapat dikonfigurasi) dikirim per pemanggilan RCA, dipilih berdasarkan kedekatan waktu dan relevansi (sama stack trace/error type).
- Caching hasil — jika pola error identik terdeteksi berulang dalam rentang waktu pendek (mis. 1 jam), agen tidak memanggil LLM ulang, melainkan menautkan ke RCA yang sudah ada sebelumnya dan hanya memperbarui jumlah kejadian.

### 10.4 Evaluasi Kualitas Agent (Anti-Halusinasi)
- Sampling mingguan: Lead Developer mereview minimal 10 RCA acak per minggu, menandai sebagai akurat/tidak akurat — hasil ini menjadi dasar perhitungan KPI tingkat halusinasi (target di bawah 2%).
- RCA yang ditandai tidak akurat dicatat besertaan dengan alasan, digunakan untuk menyempurnakan prompt template secara berkala (bukan fine-tuning model, cukup prompt engineering iteratif pada fase MVP).

## 11. SPESIFIKASI INTEGRASI

### 11.1 Internal REST API (Backend ↔ Discord Bot)

**`POST /api/v1/commands/log-check`**
Request: `{ "client_slug": "toko-abc", "range": "24h", "requested_by_discord_id": "..." }`
Response (202 Accepted): `{ "job_id": "uuid", "status": "queued" }`
Hasil dikirim async via webhook callback ke Discord Bot saat job selesai.

**`POST /api/v1/commands/validate`**
Request: `{ "project_slug": "toko-abc-v2", "requested_by_discord_id": "..." }`
Response: `{ "job_id": "uuid", "status": "queued" }`

**`GET /api/v1/clients/{slug}/status`**
Response: `{ "client": "toko-abc", "health": "warning", "open_incidents": 2, "last_validation": {...} }`

**`POST /api/v1/webhooks/job-result`** (callback dari worker ke Discord Bot)
Request: `{ "job_id": "uuid", "type": "log_check", "result": {...}, "discord_channel_id": "..." }`

Seluruh endpoint internal diautentikasi menggunakan token Bearer khusus service-to-service (bukan token user), dengan rate limit untuk mencegah penyalahgunaan.

### 11.2 Format Pesan Discord (Embed)
Setiap hasil dikembalikan sebagai Discord embed terstruktur dengan: judul ringkas, warna sesuai severity (merah=critical, kuning=warning, hijau=resolved/pass), field ringkasan, dan tautan ke detail lengkap di dashboard web (untuk RCA panjang yang tidak muat di embed).

### 11.3 LLM Gateway Abstraction
Lapisan abstraksi internal (`LLMGatewayService`) memungkinkan penggantian provider (OpenAI ↔ Anthropic) tanpa mengubah logic agen, dengan konfigurasi model per jenis task (mis. task RCA teknis menggunakan model dengan reasoning lebih kuat, task ringkasan laporan menggunakan model lebih ringan/murah).

## 12. KEBUTUHAN NON-FUNGSIONAL

| Kategori | Requirement |
|---|---|
| **Performa** | Command Discord harus mendapat acknowledgment di bawah 2 detik; hasil job RCA selesai diproses dalam rata-rata di bawah 30 detik |
| **Skalabilitas** | Mendukung minimal 50 proyek klien aktif dengan volume log gabungan hingga 1 juta baris/hari pada MVP, arsitektur queue memungkinkan scaling horizontal worker |
| **Ketersediaan** | Target uptime platform 99.5% (di luar maintenance terjadwal) |
| **Reliabilitas Job** | Job gagal (mis. LLM API timeout) di-retry otomatis maksimal 3x dengan exponential backoff sebelum ditandai gagal dan dieskalasi ke notifikasi manual |
| **Auditability** | Seluruh aksi agen dan command user tercatat lengkap di `audit_logs`, dapat ditelusuri minimal 1 tahun |
| **Kompatibilitas Browser** | Dashboard web mendukung 2 versi terbaru Chrome, Firefox, Edge, Safari |
| **Lokalitas** | Antarmuka mendukung Bahasa Indonesia dan Inggris (i18n di tingkat label statis, output AI mengikuti bahasa konfigurasi proyek) |

## 13. KEAMANAN & KEPATUHAN

- **Enkripsi kredensial:** Seluruh kredensial server klien (SSH key, API token) disimpan terenkripsi menggunakan Laravel Encryption (AES-256) dengan key terpisah dari kode aplikasi, dikelola via environment/secret manager — tidak pernah disimpan plaintext di database.
- **Role-Based Access Control (RBAC):** Empat peran utama (Owner, Lead Dev, Developer, Admin Platform) dengan matriks izin eksplisit per modul; command sensitif (ubah threshold, override validasi gagal) dibatasi ke Lead Dev ke atas.
- **Row/Schema-level isolation:** Sesuai Bagian 8, mencegah satu klien mengakses data klien lain bahkan jika terjadi bug pada level query aplikasi.
- **Audit trail tidak dapat diubah:** Tabel `audit_logs` bersifat append-only di level aplikasi (tidak ada endpoint update/delete untuk record audit).
- **Data minimisasi pada prompt LLM:** Sebelum data log dikirim ke LLM eksternal, dilakukan scrubbing dasar untuk pola yang menyerupai data sensitif (email, nomor telepon, token API) menggunakan regex filter, untuk meminimalkan risiko kebocoran data klien ke penyedia LLM pihak ketiga.
- **Kepatuhan kontraktual:** Setiap klien yang dipantau harus memiliki klausul persetujuan eksplisit dalam kontrak kerja sama dengan agensi terkait pemantauan log oleh pihak ketiga (AgencyMind sebagai sub-processor) — ini menjadi syarat onboarding, bukan opsional.

## 14. OBSERVABILITY & MONITORING

- **Laravel Pulse** digunakan untuk memantau kesehatan platform itu sendiri (bukan kesehatan klien):
  - Antrean job mana yang paling padat/lambat (mis. job RCA Klien X vs Y).
  - Slow query internal database platform.
  - Penggunaan resource server (CPU/memori) saat beban job AI tinggi.
  - Exception rate aplikasi AgencyMind sendiri (dogfooding produk pada platform sendiri menjadi sinyal kualitas tambahan).
- **Dashboard khusus Owner/Lead Dev:** ringkasan biaya pemanggilan LLM per klien per bulan (untuk transparansi biaya operasional internal agensi), jumlah job diproses, dan rata-rata waktu resolusi insiden.
- **Alerting internal platform:** jika antrean Horizon menumpuk melebihi ambang batas (mis. backlog >100 job tertunda lebih dari 10 menit), tim platform internal menerima alert terpisah dari alert klien.

## 15. METRIK KEBERHASILAN (KPI)

| KPI | Definisi | Target |
|---|---|---|
| System Uptime | Persentase waktu platform dapat menangani request antar-agen tanpa timeout | ≥ 99.5% |
| Time-to-Notify | Waktu dari error terjadi di server klien hingga notifikasi sampai ke Discord internal | < 5 menit |
| Tingkat Halusinasi Agen | Persentase RCA yang ditandai tidak akurat oleh Lead Developer pada sampling mingguan | < 2% |
| Pengurangan Waktu Investigasi Manual | Penurunan waktu rata-rata developer mencari akar masalah log secara manual | ≥ 60% dalam 6 bulan |
| Adopsi Internal | Persentase proyek aktif yang terhubung ke modul Analis Log | ≥ 80% dalam 3 bulan |
| Waktu Penyusunan Laporan Mingguan | Waktu PM dari mulai hingga laporan siap kirim (termasuk review draf AI) | < 15 menit per klien |

## 16. ROADMAP IMPLEMENTASI

### Fase 1 — Fondasi (Bulan 1)
- Setup proyek Laravel 13, struktur multi-tenant (schema-per-tenant) dan autentikasi RBAC.
- Integrasi awal Laravel Pulse untuk observability internal sejak hari pertama.
- API dasar untuk menerima file/stream log server (push API + worker pull SSH dasar).
- Skema database inti (`clients`, `users`, `log_sources`, `log_entries`).
- **Deliverable:** platform dapat menerima dan menyimpan log dari minimal 1 proyek klien pilot, terverifikasi isolasi schema bekerja.

### Fase 2 — Otak Agentic (Bulan 2)
- Integrasi LLM Gateway (dual-provider abstraction).
- Implementasi rule-engine threshold deteksi anomali.
- Pembuatan Log Analyst Agent dengan tool-calling terbatas dan guardrail anti-halusinasi.
- Implementasi antrean dengan Horizon, termasuk prioritization (Critical > Warning > Reporting).
- **Deliverable:** agen dapat menghasilkan RCA otomatis dari data log nyata proyek pilot, dengan rujukan bukti yang dapat diverifikasi.

### Fase 3 — Integrasi Antarmuka & Notifikasi (Bulan 3)
- Pengembangan Discord Bot dan internal REST API service-to-service.
- Implementasi command inti (`/log-check`, `/validate`, `/status`, `/report-weekly`, `/threshold set`).
- Modul Validasi Proyek Otonom (rule-based checklist + lapisan penilaian kualitatif LLM).
- **Deliverable:** tim dapat menjalankan seluruh command inti dari Discord dan menerima hasil terstruktur di channel proyek.

### Fase 4 — Beta Testing (Bulan 4)
- Uji coba internal menggunakan seluruh proyek klien aktif agensi (bukan hanya pilot).
- Evaluasi kecepatan dan akurasi pelaporan bug otonom terhadap KPI Bagian 15.
- Modul Reporting Agent (draf laporan mingguan) dan workflow review-sebelum-kirim.
- Perbaikan iteratif prompt template berdasarkan hasil sampling akurasi RCA.
- **Deliverable:** keputusan go/no-go untuk rollout penuh berdasarkan pencapaian KPI minimum (Time-to-Notify, tingkat halusinasi).

## 17. KEBUTUHAN TIM & SUMBER DAYA

| Peran | Alokasi Estimasi | Catatan |
|---|---|---|
| Backend Developer (Laravel) | 1 full-time, 4 bulan | Inti pengembangan API, queue, multi-tenant |
| AI/Prompt Engineer | 1 part-time → full-time di Fase 2-3 | Desain agent, tool-calling, guardrail, evaluasi akurasi |
| Bot/Integration Developer | 1 part-time, fokus Fase 3 | Discord bot, internal API contract |
| QA/Validator | 1 part-time, fokus Fase 4 | Sampling akurasi RCA, uji validasi end-to-end |
| Project Owner (Eka Prasetyo) | Oversight keseluruhan | Keputusan produk, prioritas fitur, review SOP validasi |

## 18. RISIKO & MITIGASI

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Biaya API LLM membengkak seiring volume klien bertambah | Margin operasional tertekan | Filter rule-based sebelum LLM, caching pola error berulang, dashboard biaya per klien untuk monitoring dini |
| Akses SSH/kredensial server klien menjadi titik kerentanan keamanan | Risiko kebocoran data klien lintas tenant atau ke pihak luar | Enkripsi kredensial, audit akses, prinsip least-privilege pada akun service yang digunakan untuk pull log |
| Agen menghasilkan RCA yang salah dan tim mempercayainya tanpa verifikasi | Keputusan teknis salah, waktu terbuang | Wajib tampilkan confidence level, rujukan bukti eksplisit, sampling akurasi mingguan sebagai budaya kerja wajib, bukan opsional |
| Klien keberatan data log mereka diproses oleh AI pihak ketiga (LLM provider) | Risiko kontraktual/legal | Klausul persetujuan eksplisit di kontrak, data scrubbing sebelum dikirim ke LLM, opsi self-hosted LLM untuk klien sensitif di roadmap lanjutan |
| Ketergantungan tunggal pada Discord sebagai command center | Gangguan operasional jika Discord down | Dashboard web tetap menjadi sumber kebenaran utama, Discord sebagai kenyamanan tambahan bukan satu-satunya akses |

## 19. MODEL BISNIS (RINGKAS)

Pada tahap MVP, AgencyMind digunakan secara internal oleh agensi sendiri (dogfooding) sebagai alat operasional, bukan dijual sebagai produk SaaS publik. Evaluasi kelayakan untuk dikomersialkan ke agensi digital lain dilakukan setelah Fase 4 (Beta Testing) menunjukkan pencapaian KPI yang konsisten, dengan pertimbangan model harga berbasis jumlah proyek klien yang dipantau per bulan (per-seat atau per-project tier).

## 20. LAMPIRAN

### 20.1 Glosarium
- **Agentic AI:** Sistem AI yang dapat merencanakan dan mengeksekusi serangkaian langkah/tool secara otonom untuk mencapai tujuan, bukan sekadar menjawab satu pertanyaan.
- **RCA (Root Cause Analysis):** Analisis akar penyebab suatu masalah teknis berdasarkan bukti log.
- **Tenant:** Satu unit klien terisolasi dalam sistem multi-tenant.
- **Threshold:** Ambang batas kuantitatif yang memicu alert/aksi otomatis.
- **Guardrail:** Batasan eksplisit yang mencegah agen AI melakukan aksi di luar cakupan yang diizinkan atau menghasilkan output yang tidak dapat diverifikasi.

### 20.2 Pertanyaan Terbuka untuk Pendalaman Lanjutan
- Detail flow pengolahan antrean log klien secara real-time (prioritization algorithm di Horizon) — direkomendasikan dibuat sebagai dokumen teknis terpisah (Technical Design Doc) sebelum Fase 2 dimulai.
- Strategi spesifik data scrubbing sebelum data dikirim ke LLM eksternal (daftar pola regex lengkap) — perlu kolaborasi dengan tim hukum/compliance jika tersedia.
- Desain detail UI dashboard web (di luar cakupan PRD ini, akan dituangkan dalam dokumen UX/wireframe terpisah).

---

*Dokumen ini adalah versi 1.0 dan akan diperbarui seiring temuan dari Fase 1 implementasi. Setiap perubahan signifikan pada cakupan modul perlu dicatat di changelog terpisah.*
