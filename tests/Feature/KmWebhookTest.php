<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\KmDonation;
use App\Models\KmSettlement;
use App\Models\Pembayaran;
use App\Models\Penerimaan;
use Database\Seeders\BaselineEntityCoaSeeder;
use Database\Seeders\TransactionNameSeeder;
use Database\Seeders\ViharaBankAccountSeeder;
use Database\Seeders\ViharaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Webhook KM Finance (Phase 7). Jalan di sqlite :memory: (phpunit.xml) —
 * tidak menyentuh DB pgsql dev.
 */
class KmWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-km-webhook-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.km.webhook_token' => self::TOKEN]);
        $this->seed([
            BaselineEntityCoaSeeder::class,
            ViharaSeeder::class,
            TransactionNameSeeder::class,
            ViharaBankAccountSeeder::class,
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . self::TOKEN, 'Accept' => 'application/json'];
    }

    private function donationPayload(array $overrides = []): array
    {
        return ['donation' => array_merge([
            'km_donation_id' => 123,
            'ref' => 'KM-DON-26071501',
            'vihara_code' => 'VVG',
            'category' => 'dana_bhikkhu',
            'name' => 'Dana Catupacaya',
            'donor_name' => 'Bpk. Surya',
            'amount' => 500000,
            'paid_at' => '2026-07-15T08:00:00+07:00',
        ], $overrides)];
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_webhook_requires_valid_bearer_token(): void
    {
        $this->postJson('/api/webhooks/km/donations/paid', $this->donationPayload())
            ->assertStatus(401);

        $this->postJson('/api/webhooks/km/donations/paid', $this->donationPayload(), [
            'Authorization' => 'Bearer wrong-token',
        ])->assertStatus(401);

        $this->postJson('/api/webhooks/km/settlements', [], [
            'Authorization' => 'Bearer wrong-token',
        ])->assertStatus(401);

        $this->assertSame(0, KmDonation::count());
    }

    // ------------------------------------------------------------------
    // Happy path: paid → settled lifecycle
    // ------------------------------------------------------------------

    public function test_paid_to_settled_lifecycle_posts_correct_journals(): void
    {
        // 1. Donasi paid — dana_bhikkhu, nama terdaftar di master.
        $res = $this->postJson('/api/webhooks/km/donations/paid', $this->donationPayload(), $this->headers())
            ->assertStatus(201)
            ->assertJson(['km_donation_id' => 123, 'name_matched' => true]);

        $penerimaan = Penerimaan::findOrFail($res->json('penerimaan_id'));
        $this->assertSame('posted', $penerimaan->status);
        $this->assertSame('auto_credit', $penerimaan->method);
        $this->assertSame('1160', $penerimaan->destination_account_code);
        $this->assertSame('4210', $penerimaan->credit_account_code);
        $this->assertSame('Dana Catupacaya', $penerimaan->name);
        $this->assertSame('KM-DON-26071501', $penerimaan->reference);
        $this->assertNull($penerimaan->created_by_user_id);
        $this->assertNull($penerimaan->posted_by_user_id);

        // JE donasi: Dr 1160 / Kr 4210 sebesar 500.000.
        $je = JournalEntry::with('lines')->findOrFail($res->json('journal_entry_id'));
        $this->assertSame('posted', $je->status);
        $this->assertNotNull($je->vihara_id);
        $lines = $je->lines->keyBy('account_code');
        $this->assertEqualsWithDelta(500000, (float) $lines['1160']->debit, 0.001);
        $this->assertEqualsWithDelta(500000, (float) $lines['4210']->credit, 0.001);

        // 2. Donasi kedua — nama TIDAK terdaftar → tetap diterima, name_matched false.
        $res2 = $this->postJson('/api/webhooks/km/donations/paid', $this->donationPayload([
            'km_donation_id' => 124,
            'ref' => 'KM-DON-26071502',
            'category' => 'dana_operasional',
            'name' => 'Donasi QRIS Random',
            'amount' => 250000,
        ]), $this->headers())
            ->assertStatus(201)
            ->assertJson(['name_matched' => false]);

        $p2 = Penerimaan::findOrFail($res2->json('penerimaan_id'));
        $this->assertNull($p2->name, 'Nama tak terdaftar tidak boleh masuk penerimaan.name');
        $this->assertSame('Donasi QRIS Random', KmDonation::where('km_donation_id', 124)->value('name'));

        // 3. Settlement kedua donasi: gross 750.000, komisi 10.000, net 740.000.
        $res3 = $this->postJson('/api/webhooks/km/settlements', ['settlement' => [
            'ref' => 'KM-STL-260715-VVG-01',
            'vihara_code' => 'VVG',
            'bank_account_number' => '6280-3488-88',
            'gross' => 750000,
            'komisi' => 10000,
            'net' => 740000,
            'settled_at' => '2026-07-15T17:00:00+07:00',
            'km_donation_ids' => [123, 124],
        ]], $this->headers())
            ->assertStatus(201)
            ->assertJson(['donations_settled' => 2, 'bank_matched' => true]);

        // JE komisi: Dr 7140 / Kr 1160 (10.000) via pembayaran komisi_platform.
        $pembayaran = Pembayaran::findOrFail($res3->json('komisi_pembayaran_id'));
        $this->assertSame('komisi_platform', $pembayaran->category);
        $this->assertSame('posted', $pembayaran->status);
        $this->assertSame('KalyanaMitta', $pembayaran->payee_name);
        $komisiLines = $pembayaran->journalEntry->lines->keyBy('account_code');
        $this->assertEqualsWithDelta(10000, (float) $komisiLines['7140']->debit, 0.001);
        $this->assertEqualsWithDelta(10000, (float) $komisiLines['1160']->credit, 0.001);

        // JE net transfer: Dr 1130 / Kr 1160 (740.000), reference km_settlement.
        $netJe = JournalEntry::with('lines')->findOrFail($res3->json('journal_entry_id'));
        $this->assertSame('km_settlement', $netJe->reference_type);
        $netLines = $netJe->lines->keyBy('account_code');
        $this->assertEqualsWithDelta(740000, (float) $netLines['1130']->debit, 0.001);
        $this->assertEqualsWithDelta(740000, (float) $netLines['1160']->credit, 0.001);

        // Donasi jadi settled + terhubung settlement.
        $settlement = KmSettlement::findOrFail($res3->json('settlement_id'));
        $this->assertSame(2, $settlement->donations()->count());
        $this->assertSame(2, KmDonation::where('status', 'settled')->count());
        $this->assertNotNull(KmDonation::where('km_donation_id', 123)->value('settled_at'));

        // Saldo 1160 dari jurnal: 750.000 masuk − 10.000 komisi − 740.000 net = 0.
        $saldo1160 = \DB::table('journal_lines')->where('account_code', '1160')
            ->selectRaw('COALESCE(SUM(debit - credit),0) s')->value('s');
        $this->assertEqualsWithDelta(0, (float) $saldo1160, 0.001);
    }

    // ------------------------------------------------------------------
    // Idempotensi
    // ------------------------------------------------------------------

    public function test_repeated_webhooks_are_idempotent(): void
    {
        $this->postJson('/api/webhooks/km/donations/paid', $this->donationPayload(), $this->headers())
            ->assertStatus(201);
        $this->postJson('/api/webhooks/km/donations/paid', $this->donationPayload(), $this->headers())
            ->assertStatus(200)
            ->assertJson(['already_processed' => true, 'km_donation_id' => 123]);

        $this->assertSame(1, KmDonation::count());
        $this->assertSame(1, Penerimaan::count());

        $settlementPayload = ['settlement' => [
            'ref' => 'KM-STL-260715-VVG-01',
            'vihara_code' => 'VVG',
            'bank_account_number' => '6280-3488-88',
            'gross' => 500000,
            'komisi' => 5000,
            'net' => 495000,
            'settled_at' => '2026-07-15T17:00:00+07:00',
            'km_donation_ids' => [123],
        ]];
        $first = $this->postJson('/api/webhooks/km/settlements', $settlementPayload, $this->headers())
            ->assertStatus(201);
        $this->postJson('/api/webhooks/km/settlements', $settlementPayload, $this->headers())
            ->assertStatus(200)
            ->assertJson([
                'already_processed' => true,
                'settlement_id' => $first->json('settlement_id'),
            ]);

        $this->assertSame(1, KmSettlement::count());
        $this->assertSame(1, Pembayaran::count());
        // Hanya 3 JE: donasi + komisi + net (retry tidak menggandakan).
        $this->assertSame(3, JournalEntry::count());
    }

    // ------------------------------------------------------------------
    // Validasi 422
    // ------------------------------------------------------------------

    public function test_donation_with_unknown_vihara_is_rejected(): void
    {
        $this->postJson('/api/webhooks/km/donations/paid', $this->donationPayload([
            'vihara_code' => 'XXX',
        ]), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['donation.vihara_code']);

        $this->assertSame(0, KmDonation::count());
    }

    public function test_donation_with_invalid_category_is_rejected(): void
    {
        // Suspense tidak boleh dikirim via webhook.
        $this->postJson('/api/webhooks/km/donations/paid', $this->donationPayload([
            'category' => 'donasi_belum_teridentifikasi',
        ]), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['donation.category']);

        $this->postJson('/api/webhooks/km/donations/paid', $this->donationPayload([
            'category' => 'bukan_kategori',
        ]), $this->headers())->assertStatus(422);

        $this->assertSame(0, KmDonation::count());
    }

    public function test_settlement_with_gross_mismatch_is_rejected(): void
    {
        $this->postJson('/api/webhooks/km/donations/paid', $this->donationPayload(), $this->headers())
            ->assertStatus(201);

        $this->postJson('/api/webhooks/km/settlements', ['settlement' => [
            'ref' => 'KM-STL-260715-VVG-99',
            'vihara_code' => 'VVG',
            'gross' => 999999, // donasi 123 = 500.000
            'komisi' => 5000,
            'net' => 994999,
            'settled_at' => '2026-07-15T17:00:00+07:00',
            'km_donation_ids' => [123],
        ]], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['settlement.gross']);

        $this->assertSame(0, KmSettlement::count());
        $this->assertSame('paid', KmDonation::where('km_donation_id', 123)->value('status'));
    }

    public function test_settling_an_already_settled_donation_is_rejected(): void
    {
        $this->postJson('/api/webhooks/km/donations/paid', $this->donationPayload(), $this->headers())
            ->assertStatus(201);
        $this->postJson('/api/webhooks/km/settlements', ['settlement' => [
            'ref' => 'KM-STL-260715-VVG-01',
            'vihara_code' => 'VVG',
            'gross' => 500000,
            'komisi' => 0,
            'net' => 500000,
            'settled_at' => '2026-07-15T17:00:00+07:00',
            'km_donation_ids' => [123],
        ]], $this->headers())
            ->assertStatus(201)
            // Komisi 0 → tidak ada pembayaran komisi.
            ->assertJson(['komisi_pembayaran_id' => null]);

        // Ref BARU yang mencoba settle donasi yang sama → 422 (bukan idempotent hit).
        $this->postJson('/api/webhooks/km/settlements', ['settlement' => [
            'ref' => 'KM-STL-260715-VVG-02',
            'vihara_code' => 'VVG',
            'gross' => 500000,
            'komisi' => 0,
            'net' => 500000,
            'settled_at' => '2026-07-16T17:00:00+07:00',
            'km_donation_ids' => [123],
        ]], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['settlement.km_donation_ids']);

        $this->assertSame(1, KmSettlement::count());
    }

    public function test_settlement_with_unknown_bank_account_falls_back(): void
    {
        $this->postJson('/api/webhooks/km/donations/paid', $this->donationPayload(), $this->headers())
            ->assertStatus(201);

        $res = $this->postJson('/api/webhooks/km/settlements', ['settlement' => [
            'ref' => 'KM-STL-260715-VVG-03',
            'vihara_code' => 'VVG',
            'bank_account_number' => '1111-2222-33', // tak ada di master VVG
            'gross' => 500000,
            'komisi' => 5000,
            'net' => 495000,
            'settled_at' => '2026-07-15T17:00:00+07:00',
            'km_donation_ids' => [123],
        ]], $this->headers())
            ->assertStatus(201)
            ->assertJson(['bank_matched' => false]);

        // Fallback rekening aktif pertama VVG — tetap tercatat.
        $this->assertNotNull(KmSettlement::findOrFail($res->json('settlement_id'))->bank_account_id);
    }
}
