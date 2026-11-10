<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AnggaranController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LaporanController;
use App\Http\Controllers\Api\CashController;
use App\Http\Controllers\Api\DanaUmatController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EntityController;
use App\Http\Controllers\Api\KmReportController;
use App\Http\Controllers\Api\KasBankController;
use App\Http\Controllers\Api\ManualJournalController;
use App\Http\Controllers\Api\MasterAccountController;
use App\Http\Controllers\Api\MasterController;
use App\Http\Controllers\Api\PettyCashPicController;
use App\Http\Controllers\Api\ViharaBankAccountController;
use App\Http\Controllers\Api\PembayaranController;
use App\Http\Controllers\Api\PenerimaanController;
use App\Http\Controllers\Api\PettyCashController;
use App\Http\Controllers\Api\ReconciliationController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\ViharaController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

// --- Webhook M2M KM Finance (KalyanaMitta) ---
// Di luar auth:sanctum — auth pakai shared secret KM_WEBHOOK_TOKEN
// (Authorization: Bearer). Kontrak: docs/km-webhook-spec.md.
Route::prefix('webhooks/km')
    ->middleware(\App\Http\Middleware\VerifyKmWebhookToken::class)
    ->group(function () {
        Route::post('/donations/paid', [\App\Http\Controllers\Api\KmWebhookController::class, 'donationPaid']);
        Route::post('/settlements', [\App\Http\Controllers\Api\KmWebhookController::class, 'settlement']);
    });

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Dashboard summary — semua role terautentikasi boleh lihat ringkasan.
    Route::get('/dashboard', [DashboardController::class, 'summary']);

    // --- Kas/Bank + Petty Cash + Rekonsiliasi ---

    // Master dropdowns dipakai semua halaman transaksi.
    Route::middleware('can:masters.view')->group(function () {
        Route::get('/masters/entities', [MasterController::class, 'entities']);
        Route::get('/masters/accounts', [MasterController::class, 'accounts']);
        Route::get('/masters/bank-accounts', [MasterController::class, 'bankAccounts']);
        Route::get('/masters/payees', [MasterController::class, 'payees']);
        Route::get('/masters/transaction-names', [MasterController::class, 'transactionNames']);
        Route::get('/masters/vihara-bank-accounts', [ViharaBankAccountController::class, 'index']);
        Route::get('/masters/petty-cash-pics', [PettyCashPicController::class, 'index']);
    });

    // Master rekening bank + PIC kas kecil per vihara (CRUD admin).
    Route::middleware('can:masters.manage')->group(function () {
        Route::post('/viharas/{vihara}/bank-accounts', [ViharaBankAccountController::class, 'store']);
        Route::patch('/vihara-bank-accounts/{viharaBankAccount}', [ViharaBankAccountController::class, 'update']);
        Route::delete('/vihara-bank-accounts/{viharaBankAccount}', [ViharaBankAccountController::class, 'destroy']);
        Route::post('/viharas/{vihara}/petty-cash-pics', [PettyCashPicController::class, 'store']);
        Route::patch('/petty-cash-pics/{pettyCashPic}', [PettyCashPicController::class, 'update']);
        Route::delete('/petty-cash-pics/{pettyCashPic}', [PettyCashPicController::class, 'destroy']);
    });

    // Semua Transaksi — riwayat gabungan penerimaan + pembayaran.
    // Gate (penerimaan.view OR pembayaran.view) dicek di controller.
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/export', [TransactionController::class, 'export']);

    // Penerimaan Dana Umat — rekap penerimaan posted per kategori dana.
    Route::get('/dana-umat', [DanaUmatController::class, 'index'])->middleware('can:penerimaan.view');

    // Kas & Bank — saldo per rekening vihara + kas kecil per PIC + mutasi.
    Route::get('/kas-bank', [KasBankController::class, 'index'])->middleware('can:cash.view');

    // Cash & Bank
    Route::get('/cash/dashboard', [CashController::class, 'dashboard'])->middleware('can:cash.view');
    Route::get('/cash/accounts/{accountCode}/transactions', [CashController::class, 'transactions'])->middleware('can:cash.view');
    Route::patch('/cash/journal-lines/{journalLine}/reconcile', [CashController::class, 'reconcile'])->middleware('can:reconcile.match');

    // Petty Cash
    Route::get('/petty-cash/categories', [PettyCashController::class, 'categories'])->middleware('can:petty_cash.view');
    Route::get('/petty-cash', [PettyCashController::class, 'index'])->middleware('can:petty_cash.view');
    Route::get('/petty-cash/summary', [PettyCashController::class, 'summary'])->middleware('can:petty_cash.view');
    Route::post('/petty-cash', [PettyCashController::class, 'store'])->middleware('can:petty_cash.declare');
    Route::get('/petty-cash/{pettyCashMovement}', [PettyCashController::class, 'show'])->middleware('can:petty_cash.view');
    Route::post('/petty-cash/{pettyCashMovement}/post', [PettyCashController::class, 'post'])->middleware('can:petty_cash.approve');
    Route::post('/petty-cash/{pettyCashMovement}/attachment', [PettyCashController::class, 'uploadAttachment'])->middleware('can:petty_cash.declare');
    Route::get('/petty-cash/{pettyCashMovement}/attachment', [PettyCashController::class, 'downloadAttachment'])->middleware('can:petty_cash.view');
    Route::delete('/petty-cash/{pettyCashMovement}', [PettyCashController::class, 'destroy'])->middleware('can:petty_cash.declare');

    // Rekonsiliasi — 3 area: PG Clearing (1160), Bank per rekening fisik,
    // Suspense (2310). Run per (rekening, bulan): lines manual/import,
    // auto-matcher preview + apply, complete.
    // NB: pg-clearing & suspense HARUS sebelum {reconciliation} agar tidak
    // tertangkap route-model-binding.
    Route::get('/reconciliation/pg-clearing', [ReconciliationController::class, 'pgClearing'])->middleware('can:reconcile.view');
    Route::get('/reconciliation/suspense', [ReconciliationController::class, 'suspense'])->middleware('can:reconcile.view');
    Route::get('/reconciliation', [ReconciliationController::class, 'index'])->middleware('can:reconcile.view');
    Route::post('/reconciliation', [ReconciliationController::class, 'store'])->middleware('can:reconcile.import');
    Route::get('/reconciliation/{reconciliation}', [ReconciliationController::class, 'show'])->middleware('can:reconcile.view');
    Route::post('/reconciliation/{reconciliation}/lines', [ReconciliationController::class, 'storeLine'])->middleware('can:reconcile.import');
    Route::delete('/reconciliation/{reconciliation}/lines/{line}', [ReconciliationController::class, 'destroyLine'])->middleware('can:reconcile.import');
    Route::post('/reconciliation/{reconciliation}/import', [ReconciliationController::class, 'import'])->middleware('can:reconcile.import');
    Route::post('/reconciliation/{reconciliation}/match', [ReconciliationController::class, 'match'])->middleware('can:reconcile.match');
    Route::post('/reconciliation/{reconciliation}/unmatch', [ReconciliationController::class, 'unmatch'])->middleware('can:reconcile.match');
    Route::post('/reconciliation/{reconciliation}/apply-matches', [ReconciliationController::class, 'applyMatches'])->middleware('can:reconcile.match');
    Route::get('/reconciliation/{reconciliation}/suggestions', [ReconciliationController::class, 'suggestions'])->middleware('can:reconcile.view');
    Route::post('/reconciliation/{reconciliation}/complete', [ReconciliationController::class, 'complete'])->middleware('can:reconcile.finalize');
    Route::delete('/reconciliation/{reconciliation}', [ReconciliationController::class, 'destroy'])->middleware('can:reconcile.import');

    // --- COA management (admin) ---
    Route::get('/coa', [MasterAccountController::class, 'index'])->middleware('can:coa.view');
    Route::get('/coa/{account:code}', [MasterAccountController::class, 'show'])->middleware('can:coa.view');
    Route::post('/coa', [MasterAccountController::class, 'store'])->middleware('can:coa.manage');
    Route::patch('/coa/{account:code}', [MasterAccountController::class, 'update'])->middleware('can:coa.manage');
    Route::delete('/coa/{account:code}', [MasterAccountController::class, 'destroy'])->middleware('can:coa.manage');

    // --- Admin (users + roles) ---
    Route::middleware('can:users.manage')->group(function () {
        Route::get('/admin/users', [AdminController::class, 'users']);
        Route::post('/admin/users', [AdminController::class, 'storeUser']);
        Route::patch('/admin/users/{user}', [AdminController::class, 'updateUser']);
        Route::delete('/admin/users/{user}', [AdminController::class, 'destroyUser']);
    });
    // Membaca katalog role/permission dibutuhkan siapa pun yang boleh
    // mengelola pengguna (form "User baru" butuh daftar role). Yang
    // benar-benar mengubah DEFINISI role tetap butuh roles.manage.
    Route::get('/admin/roles', [AdminController::class, 'roles']);
    Route::get('/admin/permissions', [AdminController::class, 'permissions']);
    Route::middleware('can:roles.manage')->group(function () {
        Route::post('/admin/roles', [AdminController::class, 'storeRole']);
        Route::patch('/admin/roles/{role}', [AdminController::class, 'updateRole']);
    });

    // --- Pembayaran + Penerimaan + Jurnal Manual ---

    // Pembayaran operasional (draft → posted, auto-create JE on post)
    Route::get('/pembayaran/categories', [PembayaranController::class, 'categories'])->middleware('can:pembayaran.view');
    Route::get('/pembayaran', [PembayaranController::class, 'index'])->middleware('can:pembayaran.view');
    Route::post('/pembayaran', [PembayaranController::class, 'store'])->middleware('can:pembayaran.create');
    Route::get('/pembayaran/{pembayaran}', [PembayaranController::class, 'show'])->middleware('can:pembayaran.view');
    Route::post('/pembayaran/{pembayaran}/post', [PembayaranController::class, 'post'])->middleware('can:pembayaran.approve');
    Route::post('/pembayaran/{pembayaran}/attachment', [PembayaranController::class, 'uploadAttachment'])->middleware('can:pembayaran.create');
    Route::get('/pembayaran/{pembayaran}/attachment', [PembayaranController::class, 'downloadAttachment'])->middleware('can:pembayaran.view');
    Route::delete('/pembayaran/{pembayaran}', [PembayaranController::class, 'destroy'])->middleware('can:pembayaran.create');

    // Penerimaan operasional
    Route::get('/penerimaan/categories', [PenerimaanController::class, 'categories'])->middleware('can:penerimaan.view');
    Route::get('/penerimaan', [PenerimaanController::class, 'index'])->middleware('can:penerimaan.view');
    Route::post('/penerimaan', [PenerimaanController::class, 'store'])->middleware('can:penerimaan.create');
    Route::get('/penerimaan/{penerimaan}', [PenerimaanController::class, 'show'])->middleware('can:penerimaan.view');
    Route::post('/penerimaan/{penerimaan}/post', [PenerimaanController::class, 'post'])->middleware('can:penerimaan.approve');
    // Reklasifikasi suspense 2310 → kategori dana (bagian alur Rekonsiliasi).
    Route::post('/penerimaan/{penerimaan}/reklasifikasi', [PenerimaanController::class, 'reklasifikasi'])->middleware('can:reconcile.match');
    Route::post('/penerimaan/{penerimaan}/attachment', [PenerimaanController::class, 'uploadAttachment'])->middleware('can:penerimaan.create');
    Route::get('/penerimaan/{penerimaan}/attachment', [PenerimaanController::class, 'downloadAttachment'])->middleware('can:penerimaan.view');
    Route::delete('/penerimaan/{penerimaan}', [PenerimaanController::class, 'destroy'])->middleware('can:penerimaan.create');

    // Manual Journal Entry
    Route::get('/journal-entries', [ManualJournalController::class, 'index'])->middleware('can:journal.view');
    Route::post('/journal-entries', [ManualJournalController::class, 'store'])->middleware('can:journal.create');
    Route::get('/journal-entries/{journalEntry}', [ManualJournalController::class, 'show'])->middleware('can:journal.view');
    Route::delete('/journal-entries/{journalEntry}', [ManualJournalController::class, 'destroy'])->middleware('can:journal.create');

    // --- Yayasan (entity) CRUD — puncak hirarki tenant ---
    Route::get('/entities', [EntityController::class, 'index'])->middleware('can:entities.view');
    Route::get('/entities/{entity}', [EntityController::class, 'show'])->middleware('can:entities.view');
    Route::post('/entities', [EntityController::class, 'store'])->middleware('can:entities.manage');
    Route::patch('/entities/{entity}', [EntityController::class, 'update'])->middleware('can:entities.manage');
    Route::delete('/entities/{entity}', [EntityController::class, 'destroy'])->middleware('can:entities.manage');

    // --- Vihara master CRUD (uses masters.* permission) ---
    Route::get('/viharas', [ViharaController::class, 'index'])->middleware('can:masters.view');
    Route::get('/viharas/{vihara}', [ViharaController::class, 'show'])->middleware('can:masters.view');
    Route::post('/viharas', [ViharaController::class, 'store'])->middleware('can:masters.manage');
    Route::patch('/viharas/{vihara}', [ViharaController::class, 'update'])->middleware('can:masters.manage');
    Route::post('/viharas/{vihara}/verify', [ViharaController::class, 'verify'])->middleware('can:masters.manage');
    Route::delete('/viharas/{vihara}', [ViharaController::class, 'destroy'])->middleware('can:masters.manage');

    // --- Laporan ISAK 35 + Anggaran ---
    Route::get('/laporan/konsolidasi', [LaporanController::class, 'konsolidasi'])->middleware('can:reports.pl.view');
    Route::get('/laporan/isak35', [LaporanController::class, 'isak35'])->middleware('can:reports.pl.view');
    Route::get('/anggaran', [AnggaranController::class, 'index'])->middleware('can:anggaran.view');
    Route::put('/anggaran', [AnggaranController::class, 'upsert'])->middleware('can:anggaran.manage');

    // --- Reports + Pajak ---
    Route::get('/reports/pl', [KmReportController::class, 'profitLoss'])->middleware('can:reports.pl.view');
    Route::get('/reports/balance-sheet', [KmReportController::class, 'balanceSheet'])->middleware('can:reports.balance_sheet.view');
    Route::get('/reports/trial-balance', [KmReportController::class, 'trialBalance'])->middleware('can:reports.pl.view');
    Route::get('/reports/pajak', [KmReportController::class, 'pajakRekap'])->middleware('can:tax.view');
});
