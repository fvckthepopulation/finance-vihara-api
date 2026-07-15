# KM Finance → Vihara Finance Webhook Spec

Kontrak integrasi machine-to-machine antara **KM Finance (KalyanaMitta)** sebagai
pengirim dan **Vihara Finance API** sebagai penerima. Tim KM Finance
mengimplementasikan sender berdasarkan dokumen ini.

Base URL (dev): `http://localhost:8500`

## Autentikasi

Semua request memakai shared secret di header:

```
Authorization: Bearer <KM_WEBHOOK_TOKEN>
Content-Type: application/json
Accept: application/json
```

- Token disimpan di env `KM_WEBHOOK_TOKEN` di kedua sisi (generate:
  `openssl rand -hex 32`).
- Token salah/absen → `401 {"message":"Unauthenticated."}`.
- Endpoint webhook berada **di luar** auth Sanctum — tidak butuh user login.

## Prinsip umum

- **Idempotent** — KM boleh retry request yang timeout/gagal. Event yang sudah
  pernah diproses dijawab `200` dengan `"already_processed": true` (bukan error),
  tanpa efek samping ganda.
- **Validasi gagal** → `422` dengan bentuk standar Laravel:
  `{"message": "...", "errors": {"field": ["..."]}}`.
- Semua timestamp dikirim ISO-8601 dengan offset (mis. `2026-07-15T08:00:00+07:00`).
  Tanggal buku (jurnal) diambil dari tanggal timestamp tsb dalam **Asia/Jakarta**.
- `vihara_code` adalah kode master vihara di Vihara Finance: `VVG`, `VBM`, `VAD`.

## Mapping kategori (WAJIB)

KM Finance harus mengirim **key kategori milik Vihara Finance** di field
`category`. Hanya 4 kategori dana yang diizinkan (suspense
`donasi_belum_teridentifikasi` **tidak** boleh dikirim via webhook):

| Key                | Label                                 | Akun kredit JE                        |
| ------------------ | ------------------------------------- | ------------------------------------- |
| `dana_operasional` | Dana Operasional (Tanpa Pembatasan)   | 4110 · Donasi KM — Tanpa Pembatasan   |
| `dana_paramita`    | Dana Paramita (Tanpa Pembatasan)      | 4110 · Donasi KM — Tanpa Pembatasan   |
| `dana_bhikkhu`     | Dana Bhikkhu (Dengan Pembatasan)      | 4120 · Donasi KM — Dengan Pembatasan  |
| `dana_perayaan`    | Dana Perayaan (Dengan Pembatasan)     | 4120 · Donasi KM — Dengan Pembatasan  |

> COA ISAK 35 v2: akun pendapatan donasi = **kanal × pembatasan** (41x0 via
> KM, 42x0 transfer bank, 43x0 tunai). Semua donasi via webhook memakai kanal
> KM (41x0); kategori dana menentukan tanpa/dengan pembatasan.

---

## 1. `POST /api/webhooks/km/donations/paid`

Dikirim saat sebuah donasi **berhasil dibayar** di payment gateway KM.
Vihara Finance otomatis membuat Penerimaan posted:
**Dr 1160 PG Clearing / Kr 4110 (TP) atau 4120 (DP)** sesuai kategori.

### Payload

```json
{
  "donation": {
    "km_donation_id": 123,
    "ref": "KM-DON-26071501",
    "vihara_code": "VVG",
    "category": "dana_bhikkhu",
    "name": "Dana Catupacaya",
    "donor_name": "Bpk. Surya",
    "amount": 500000,
    "paid_at": "2026-07-15T08:00:00+07:00"
  }
}
```

| Field            | Tipe            | Aturan                                                        |
| ---------------- | --------------- | ------------------------------------------------------------- |
| `km_donation_id` | integer         | wajib, PK donasi di KM Finance, unik                          |
| `ref`            | string ≤100     | wajib, referensi unik donasi                                  |
| `vihara_code`    | string          | wajib, harus kode vihara terdaftar → selain itu `422`         |
| `category`       | string          | wajib, salah satu 4 key `dana_*` di atas → selain itu `422`   |
| `name`           | string\|null    | opsional — nama transaksi (lihat catatan di bawah)            |
| `donor_name`     | string\|null    | opsional                                                       |
| `amount`         | number > 0      | wajib, rupiah                                                  |
| `paid_at`        | ISO-8601        | wajib                                                          |

**Catatan `name`:** dicocokkan ke master `transaction_names` (type `penerimaan`,
kategori sama, case-sensitive). Kalau **tidak** terdaftar, donasi **tetap
diterima** — nama raw disimpan di `km_donations.name`, tapi `penerimaan.name`
dikosongkan dan respons memuat `"name_matched": false`.

### Responses

`201` — diproses:

```json
{
  "km_donation_id": 123,
  "penerimaan_id": 45,
  "journal_entry_id": 90,
  "name_matched": true
}
```

`200` — sudah pernah diproses (`km_donation_id` ATAU `ref` sudah ada):

```json
{ "already_processed": true, "km_donation_id": 123, "penerimaan_id": 45 }
```

`401` token salah · `422` validasi gagal (vihara tak dikenal, kategori bukan
`dana_*`, amount ≤ 0, periode akuntansi sudah tutup buku).

### Efek di Vihara Finance

- Baris `km_donations` status `paid`.
- Penerimaan posted `RCV-YYMMNNN`, method `auto_credit`, tujuan 1160,
  `reference` = `ref`, description `KalyanaMitta | {name} | {donor} | Paid`,
  dibuat oleh sistem (`created_by`/`posted_by` null).
- Journal entry posted: Dr 1160 / Kr 4110 (TP) atau 4120 (DP) sebesar `amount`.
- Muncul di Rekonsiliasi PG Clearing sebagai baris **Escrow**.

### Contoh curl

```bash
curl -s -X POST http://localhost:8500/api/webhooks/km/donations/paid \
  -H "Authorization: Bearer $KM_WEBHOOK_TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "donation": {
      "km_donation_id": 123,
      "ref": "KM-DON-26071501",
      "vihara_code": "VVG",
      "category": "dana_bhikkhu",
      "name": "Dana Catupacaya",
      "donor_name": "Bpk. Surya",
      "amount": 500000,
      "paid_at": "2026-07-15T08:00:00+07:00"
    }
  }'
```

---

## 2. `POST /api/webhooks/km/settlements`

Dikirim saat payment gateway **mencairkan (payout)** sekumpulan donasi paid ke
rekening bank vihara. Vihara Finance otomatis:

1. Membuat Pembayaran komisi posted (kategori `komisi_platform`):
   **Dr 7140 Beban Komisi / Kr 1160** sebesar `komisi` (dilewati kalau komisi 0).
2. Membuat JE transfer net: **Dr 1130 Bank Donasi / Kr 1160** sebesar `net`
   (akun debit mengikuti `coa_account_code` rekening yang match).
3. Menandai donasi-donasi tsb `settled`.

### Payload

```json
{
  "settlement": {
    "ref": "KM-STL-260715-VVG-01",
    "vihara_code": "VVG",
    "bank_account_number": "6280-3488-88",
    "gross": 1500000,
    "komisi": 15000,
    "net": 1485000,
    "settled_at": "2026-07-15T17:00:00+07:00",
    "km_donation_ids": [123, 124, 125]
  }
}
```

| Field                 | Tipe          | Aturan                                                                 |
| --------------------- | ------------- | ---------------------------------------------------------------------- |
| `ref`                 | string ≤100   | wajib, unik — kunci idempotensi                                        |
| `vihara_code`         | string        | wajib, kode vihara terdaftar                                            |
| `bank_account_number` | string\|null  | dicocokkan digit-only ke master rekening vihara (lihat catatan)         |
| `gross`               | number > 0    | wajib, **harus** = Σ amount donasi di `km_donation_ids` → selain itu `422` |
| `komisi`              | number ≥ 0    | wajib                                                                   |
| `net`                 | number ≥ 0    | wajib, **harus** = `gross − komisi` → selain itu `422`                  |
| `settled_at`          | ISO-8601      | wajib                                                                   |
| `km_donation_ids`     | int[] min 1   | wajib — `km_donation_id` KM (bukan id internal Vihara Finance)          |

Validasi donasi (semua → `422`): setiap id harus dikenal (sudah pernah dikirim
via `donations/paid`), milik vihara yang sama, dan berstatus `paid` (belum
pernah di-settle).

**Catatan rekening:** `bank_account_number` dibandingkan digit-only
(`6280-3488-88` == `628034888 8`) terhadap master rekening vihara. Tidak
ketemu → **fallback** rekening aktif pertama vihara + respons
`"bank_matched": false` (request tetap sukses).

### Responses

`201` — diproses:

```json
{
  "settlement_id": 7,
  "journal_entry_id": 95,
  "komisi_pembayaran_id": 31,
  "donations_settled": 3,
  "bank_matched": true
}
```

(`komisi_pembayaran_id` null kalau komisi 0; `journal_entry_id` null kalau net 0.)

`200` — `ref` sudah pernah diproses:

```json
{
  "already_processed": true,
  "settlement_id": 7,
  "journal_entry_id": 95,
  "komisi_pembayaran_id": 31
}
```

`401` token salah · `422` validasi gagal.

### Contoh curl

```bash
curl -s -X POST http://localhost:8500/api/webhooks/km/settlements \
  -H "Authorization: Bearer $KM_WEBHOOK_TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "settlement": {
      "ref": "KM-STL-260715-VVG-01",
      "vihara_code": "VVG",
      "bank_account_number": "6280-3488-88",
      "gross": 1500000,
      "komisi": 15000,
      "net": 1485000,
      "settled_at": "2026-07-15T17:00:00+07:00",
      "km_donation_ids": [123, 124, 125]
    }
  }'
```

---

## Alur akuntansi lengkap (contoh 1 donasi 500rb, komisi 5rb)

| Event               | Jurnal                                        |
| ------------------- | --------------------------------------------- |
| donations/paid      | Dr 1160 PG Clearing 500.000 / Kr 4120 500.000 |
| settlements: komisi | Dr 7140 Komisi 5.000 / Kr 1160 5.000          |
| settlements: net    | Dr 1130 Bank Donasi 495.000 / Kr 1160 495.000 |

Setelah settle penuh, saldo 1160 kembali 0 untuk donasi tsb. Status di layar
Rekonsiliasi PG Clearing (`GET /api/reconciliation/pg-clearing`): donasi paid
tampil **Escrow**, setelah settlement hilang dari pending dan terhitung di
`settled` + daftar `settlements`.

## Urutan pengiriman & retry (untuk implementor KM)

1. Kirim `donations/paid` **sesaat setelah** payment gateway mengonfirmasi paid.
2. Kirim `settlements` setelah payout PG cair — semua `km_donation_ids` di
   dalamnya harus sudah pernah dikirim paid (kalau belum → `422`, kirim ulang
   donasinya dulu).
3. Timeout/5xx → retry dengan payload identik (idempotent). Jangan mengubah
   `ref` saat retry.
4. `422` = bug data di sisi pengirim — jangan retry buta; perbaiki payload.
