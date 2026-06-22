# CLAUDE.md — AgencyMind

Dokumen ini dibaca otomatis oleh Claude Code di awal setiap sesi kerja pada repo ini. Tujuannya: memastikan setiap perubahan kode konsisten dengan PRD v1.0 dan tidak melanggar guardrail keamanan/agentic yang sudah disepakati.

Referensi utama: `docs/PRD_AgencyMind_v1_0.md` — jika ada keraguan soal scope atau keputusan desain, PRD itu sumber kebenaran, bukan asumsi.

## 1. Apa Itu AgencyMind

Platform SaaS B2B multi-tenant untuk agensi digital: memantau kesehatan server log klien, menjalankan validasi proyek otonom sebelum serah terima, dan menyusun laporan progres mingguan — semuanya lewat agen AI dengan guardrail ketat (tidak ada aksi destruktif otonom).

Fase saat ini: **cek `ROADMAP.md` atau tanyakan ke user** sebelum mengasumsikan modul mana yang sedang dikerjakan. Jangan bangun fitur Fase 3/4 (Discord bot, reporting agent) kalau fondasi Fase 1 (multi-tenant + auth) belum selesai.

## 2. Stack

| Layer | Teknologi |
|---|---|
| Backend | Laravel 13, PHP 8.3+ |
| Database | PostgreSQL — central schema + `tenant_{client_id}` per klien |
| Queue | Redis + Laravel Horizon |
| Command center | Discord Bot — service terpisah (Node.js ringan), komunikasi via internal REST API |
| LLM | Dual-provider via `LLMGatewayService` (OpenAI ↔ Anthropic), bukan hardcoded ke satu provider |
| Observability | Laravel Pulse (kesehatan platform sendiri, bukan kesehatan klien) |

## 3. Aturan yang TIDAK BOLEH Dilanggar

Ini guardrail dari Bagian 10.1 dan 13 PRD. Kalau task yang diminta user bertentangan dengan poin ini, **stop dan tanyakan dulu**, jangan diam-diam dikerjakan dengan cara yang melanggar.

1. **Agen tidak pernah punya aksi destruktif.** Tidak boleh ada tool yang memberi agen kemampuan deploy, restart service, ubah/hapus data di server klien. Agen hanya: baca, analisis, rekomendasikan.
2. **Setiap klaim teknis RCA wajib bersitasi** ke `log_entry_id` spesifik. Kalau bukti tidak cukup, output agen harus eksplisit bilang "tidak cukup data", bukan mengarang.
3. **Confidence level wajib eksplisit** (High/Medium/Low) di setiap output RCA — tidak boleh ada dugaan disajikan sebagai fakta pasti.
4. **Tool-calling agen pakai allow-list eksplisit**, bukan akses bebas. Tools yang diizinkan: `search_log_entries`, `get_project_metadata`, `run_validation_check`, `fetch_incident_history`. Menambah tool baru = perubahan desain yang perlu didiskusikan dulu, bukan ditambah on-the-fly oleh AI saat coding.
5. **Rule-based filter jalan sebelum LLM dipanggil.** Jangan pernah kirim seluruh volume log mentah ke LLM — itu melanggar prinsip kontrol biaya di Bagian 10.3.
6. **Kredensial klien (SSH key, API token) tidak pernah plaintext.** Selalu lewat Laravel Encryption (AES-256), key terpisah dari kode, lewat secret manager/env.
7. **Isolasi tenant ketat.** Setiap query ke data klien harus melalui koneksi/schema tenant yang benar. Tidak ada raw query lintas schema tanpa alasan eksplisit yang didokumentasikan.
8. **`audit_logs` append-only.** Tidak boleh ada endpoint atau Eloquent action yang update/delete record audit.
9. **Data scrubbing wajib jalan** sebelum konten log dikirim ke LLM eksternal — filter pola email, nomor telepon, token API.
10. **Command Discord selalu async.** Bot membalas acknowledgment instan lalu dispatch job ke Horizon queue — jangan proses sinkron di request handler bot.

## 4. Struktur Modul

- **Modul A — Log Analyst Agent**: ingest log (push API / SSH pull / webhook) → parsing & normalisasi → deteksi anomali rule-based → RCA via LLM jika lolos threshold → notifikasi Discord.
- **Modul B — Validation Agent**: checklist SOP (YAML/JSON Validation Profile) → eksekusi rule-based dulu → penilaian kualitatif LLM sebagai lapisan tambahan, bukan pengganti.
- **Modul C — Command Center**: Discord Bot (presentasi/parsing command saja) ↔ internal REST API ↔ backend Laravel (logic agentic ada di backend, bukan di bot).
- **Modul D — Multi-Tenant & Reporting**: isolasi data per klien, Reporting Agent untuk draf laporan mingguan dengan workflow review-sebelum-kirim.

## 5. Konvensi Kode

- Nama tabel/kolom: snake_case, konsisten dengan skema di Bagian 9 PRD.
- Setiap Job baru untuk proses LLM harus masuk antrean dengan prioritas eksplisit (Critical > Warning > Reporting) — lihat pattern di `SKILL.md`.
- Setiap interaksi command Discord wajib tercatat ke `audit_logs` (siapa, kapan, command, hasil).
- Penamaan service: `{Domain}Service` (mis. `LLMGatewayService`, `LogParsingService`), agent logic dipisah dari controller/job — job hanya orkestrasi, logic agent ada di service/agent class tersendiri agar mudah ditest dan diaudit.

## 6. Testing yang Wajib Ada

Saat menambah fitur baru, jangan lupa test untuk:
- Isolasi tenant (klien A tidak bisa lihat data klien B, termasuk lewat bug query).
- Guardrail RCA (output tanpa citation log_entry_id harus ditolak/flag, bukan diloloskan).
- Audit log tidak bisa diubah/dihapus lewat endpoint manapun.
- Validasi rule-based menghasilkan status deterministik yang sama untuk input yang sama (reproducible).

## 7. Saat Ragu

Kalau instruksi user di sesi coding tampak bertentangan dengan PRD atau guardrail di atas (contoh: "kasih saja agen akses buat auto-fix kode-nya langsung"), tanyakan konfirmasi eksplisit dulu sebelum implementasi — itu Non-Tujuan MVP yang sudah disepakati di Bagian 3.3 PRD.
