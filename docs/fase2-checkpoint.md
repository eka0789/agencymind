# Checkpoint Fase 2 - Otak Agentic AgencyMind

Fase 2 (Otak Agentic) dari platform AgencyMind telah berhasil diimplementasikan sepenuhnya. Seluruh unit, integration, dan feature tests terkait LLM Gateway, Rule-Engine, dan Log Analyst Agent lolos dengan sukses. Dokumentasi di bawah ini merangkum arsitektur teknis dan cara verifikasi sistem kecerdasan ini.

---

## 1. Abstraksi LLM Gateway (Dual-Provider & Auto-Fallback)

Untuk menjamin keandalan platform tanpa bergantung pada satu penyedia LLM tunggal, AgencyMind menerapkan lapisan abstraksi gateway terpadu.

### A. Kontrak & Implementasi Layanan
- **`LLMGatewayService` Interface**: Mendefinisikan kontrak dasar `analyze(string $prompt, string $systemPrompt = '', array $options = []): string` untuk interaksi LLM.
- **`OpenAIGatewayService`**: Implementasi API Chat Completion OpenAI (menggunakan model default `gpt-4o` dari `config/services.php`).
- **`AnthropicGatewayService`**: Implementasi API Messages Anthropic (menggunakan model default `claude-3-5-sonnet-20240620` dari `config/services.php`).

### B. Otomatisasi Fallback (`LLMGatewayManager`)
- **`LLMGatewayManager`** mengelola instansiasi driver default (ditentukan lewat `LLM_DEFAULT_PROVIDER` di berkas `.env`).
- Menyediakan metode `analyzeWithFallback` yang memanggil provider utama. Jika terjadi kegagalan (misalnya karena limitasi jaringan, timeout, atau limit rate HTTP `429`), gateway secara dinamis akan menangkap exception dan mengalihkan request ke provider cadangan (Anthropic), sehingga menjamin uptime pemantauan insiden kritis tetap terjaga.

---

## 2. Rule-Engine Threshold Anomaly Detection

Untuk menyaring miliaran baris logs mentah menjadi informasi bernilai, AgencyMind mengimplementasikan mesin deteksi anomali mandiri.

### A. Konfigurasi Dinamis per Klien
Aturan batas (*threshold rules*) disimpan di tabel global `validation_profiles` dalam format JSON (`rules_json`). Contoh struktur aturan:
```json
{
  "thresholds": [
    {
      "name": "error_spike",
      "severity": "critical",
      "metric": "error_count",
      "window_minutes": 5,
      "limit": 3
    }
  ]
}
```

### B. Evaluasi & Deduplikasi (`RuleEngine`)
- Kelas `RuleEngine` mengevaluasi total kecocokan metrik log entries (misal: `error_count`, `warning_count`, `http_500_count`) dalam jendela waktu berjalan (*sliding window*) tertentu.
- **Deduplikasi**: Mesin deteksi memverifikasi apakah sudah ada insiden aktif (`status = 'open'`) untuk aturan tersebut. Jika ada, mesin akan membatalkan pembuatan insiden duplikat demi menghindari spam notifikasi.
- **Incident Creation**: Jika melanggar batas, insiden baru dibuat di skema database tenant, dan job analisis akar masalah (`AnalyzeIncidentRCA`) akan otomatis dikirimkan ke antrean.

---

## 3. Log Analyst Agent & RCA Job

Ketika anomali terdeteksi, Log Analyst Agent dipicu secara asinkron untuk melakukan analisis mendalam tanpa campur tangan manusia.

### A. Proteksi Data Sensitif (Log Scrubbing)
Sebelum log dikirim ke LLM eksternal, job `AnalyzeIncidentRCA` menerapkan metode sensor (*scrubbing*) tingkat lanjut menggunakan ekspresi reguler (regex) untuk menggantikan data pribadi:
- **Email**: Disensor menjadi `[EMAIL_REDACTED]`.
- **IP Address (IPv4)**: Disensor menjadi `[IP_REDACTED]`.
- **Secrets/Credentials**: Format penugasan seperti `password=...`, `"token": "..."` disensor menjadi `password=[REDACTED]`.

### B. Pembuatan Laporan Akar Masalah (RCA)
- Agen merakit prompt instruktif yang membawa log context bersih dari sensor data sensitif.
- Mengirimkan instruksi sistem agar model memberikan output berupa objek JSON dengan struktur terdefinisi (berisi markdown summary akar masalah, bukti teknis yang merujuk log ID, rekomendasi langkah pemecahan, dan tingkat kepercayaan analisis).
- Hasil analisis markdown terstruktur disimpan kembali ke kolom `rca_summary` dan `rca_confidence` di tabel `incidents` tenant.

---

## 4. Prioritas Antrean Worker (Horizon)

Tugas-tugas platform diatur dalam antrean bertingkat pada berkas `config/horizon.php` untuk memastikan keandalan eksekusi tugas sensitif:
- **`critical`**: Menangani job analisis agen insiden (`AnalyzeIncidentRCA`).
- **`warning`**: Untuk insiden/anomali non-kritis.
- **`reporting`**: Untuk pembuatan draf laporan berkala.
- **`default`**: Untuk sinkronisasi log harian via SSH/SFTP (`PullLogsFromSSH`).

---

## 5. Cara Menjalankan & Memverifikasi

### A. Menjalankan Tes Backend Lengkap (Fase 1 & 2)
Jalankan tes backend untuk memverifikasi fungsionalitas LLM Gateway (fallback & mock), Rule-Engine (deduplikasi & threshold), dan Log Analyst Agent (scrubbing & RCA saving):
```bash
php artisan test
```
Seluruh **20 tes** (Fase 1 & Fase 2) terverifikasi lolos 100% dengan total 89 assertions.

### B. Menjalankan Antrean Prioritas Horizon
Untuk mengaktifkan Horizon worker di lokal agar memproses antrean prioritas sesuai urutan:
```bash
php artisan horizon
```
Dashboard antrean dapat dipantau langsung di browser melalui alamat: `http://localhost:8000/horizon`.
