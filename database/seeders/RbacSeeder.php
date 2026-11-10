<?php

namespace Database\Seeders;

use App\Models\Entity;
use App\Models\User;
use App\Models\Vihara;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * RBAC Vihara Finance.
 *
 * Dua sumbu yang sengaja dipisah:
 *
 *   ROLE  = APA yang boleh dilakukan (permission).
 *   SCOPE = DI MANA boleh dilakukan (users.scope_type + pivot user_vihara).
 *
 * Role yang sama bisa dipakai di tingkat yayasan maupun satu vihara; yang
 * membedakan jangkauannya adalah scope. Ini membuat "bendahara 2 vihara"
 * atau "pengawas seluruh yayasan" cukup diatur lewat data, tanpa role baru.
 */
class RbacSeeder extends Seeder
{
    /**
     * Canonical permission keys untuk Vihara Finance.
     * Format: <module>.<resource>.<action>. Mirrors the sidebar SECTIONS
     * layout in the frontend.
     */
    public const PERMISSIONS = [
        // Kas & Bank — multi-rekening
        'cash.view',
        'cash.account.manage',              // CRUD master rekening

        // Rekonsiliasi
        'reconcile.view',
        'reconcile.import',                 // upload rek koran
        'reconcile.match',                  // match / mark cocok
        'reconcile.finalize',               // selesaikan periode

        // Petty Cash
        'petty_cash.view',
        'petty_cash.declare',               // submit deklarasi pengeluaran
        'petty_cash.approve',
        'petty_cash.replenish',             // isi kembali imprest

        // Pembayaran (operasional out)
        'pembayaran.view',
        'pembayaran.create',
        'pembayaran.approve',

        // Penerimaan (dana in)
        'penerimaan.view',
        'penerimaan.create',
        'penerimaan.approve',

        // Laporan keuangan
        'reports.pl.view',
        'reports.balance_sheet.view',
        'reports.export',                   // unduh Excel

        // Anggaran (budget per vihara per tahun)
        'anggaran.view',
        'anggaran.manage',

        // Pajak & komisi
        'tax.view',
        'tax.compliance.manage',

        // Jurnal & buku besar
        'journal.view',
        'journal.create',
        'journal.approve',

        // Accounting period (close / reopen)
        'period.view',
        'period.close',
        'period.reopen',

        // Yayasan (entity) — puncak hirarki tenant
        'entities.view',
        'entities.manage',

        // Admin
        'users.manage',
        'roles.manage',
        'coa.view',
        'coa.manage',
        'masters.view',
        'masters.manage',
    ];

    /**
     * `default_scope` hanya anjuran untuk form tambah pengguna — bukan
     * penegakan. Penegakan sesungguhnya ada di users.scope_type.
     */
    public const ROLES = [
        'superadmin' => [
            'description' => 'Admin tertinggi — lintas yayasan, seluruh vihara, termasuk pengaturan RBAC.',
            'default_scope' => User::SCOPE_GLOBAL,
            'permissions' => '*',
        ],
        'admin_yayasan' => [
            'description' => 'Admin satu yayasan — seluruh vihara di yayasannya, termasuk kelola pengguna. Tidak bisa menyentuh yayasan lain atau mengubah definisi role.',
            'default_scope' => User::SCOPE_ENTITY,
            // Semua kecuali roles.manage (definisi role bersifat lintas
            // tenant, jadi tetap hak superadmin).
            'permissions' => [
                'cash.view', 'cash.account.manage',
                'reconcile.view', 'reconcile.import', 'reconcile.match', 'reconcile.finalize',
                'petty_cash.view', 'petty_cash.declare', 'petty_cash.approve', 'petty_cash.replenish',
                'pembayaran.view', 'pembayaran.create', 'pembayaran.approve',
                'penerimaan.view', 'penerimaan.create', 'penerimaan.approve',
                'reports.pl.view', 'reports.balance_sheet.view', 'reports.export',
                'anggaran.view', 'anggaran.manage',
                'tax.view', 'tax.compliance.manage',
                'journal.view', 'journal.create', 'journal.approve',
                'period.view', 'period.close', 'period.reopen',
                'entities.view', 'entities.manage',
                'users.manage',
                'coa.view', 'coa.manage',
                'masters.view', 'masters.manage',
            ],
        ],
        'pengawas_yayasan' => [
            'description' => 'Pengawas/auditor tingkat yayasan — baca saja, tapi melihat SELURUH vihara di yayasannya termasuk konsolidasi.',
            'default_scope' => User::SCOPE_ENTITY,
            'permissions' => [
                'cash.view',
                'reconcile.view',
                'petty_cash.view',
                'pembayaran.view',
                'penerimaan.view',
                'journal.view',
                'reports.pl.view', 'reports.balance_sheet.view', 'reports.export',
                'anggaran.view',
                'tax.view',
                'period.view',
                'entities.view',
                'coa.view', 'masters.view',
            ],
        ],
        'bendahara' => [
            'description' => 'Day-to-day operasional — input pembayaran/penerimaan, deklarasi petty cash. Biasanya dibatasi ke satu atau beberapa vihara.',
            'default_scope' => User::SCOPE_VIHARA,
            'permissions' => [
                'cash.view',
                'reconcile.view', 'reconcile.import',
                'petty_cash.view', 'petty_cash.declare',
                'pembayaran.view', 'pembayaran.create',
                'penerimaan.view', 'penerimaan.create',
                'journal.view',
                'reports.pl.view', 'reports.balance_sheet.view',
                'anggaran.view', 'anggaran.manage',
                'period.view',
                'entities.view',
                'coa.view', 'masters.view',
            ],
        ],
        'approver' => [
            'description' => 'Approval & reconciliation — approve pembayaran/petty cash, finalize rekonsiliasi.',
            'default_scope' => User::SCOPE_VIHARA,
            'permissions' => [
                'cash.view',
                'reconcile.view', 'reconcile.match', 'reconcile.finalize',
                'petty_cash.view', 'petty_cash.approve', 'petty_cash.replenish',
                'pembayaran.view', 'pembayaran.approve',
                'penerimaan.view', 'penerimaan.approve',
                'journal.view', 'journal.approve',
                'reports.pl.view', 'reports.balance_sheet.view', 'reports.export',
                'anggaran.view',
                'tax.view',
                'period.view', 'period.close',
                'entities.view',
                'coa.view', 'masters.view',
            ],
        ],
        'viewer' => [
            'description' => 'Read-only — dashboards + reports untuk stakeholder / auditor vihara.',
            'default_scope' => User::SCOPE_VIHARA,
            'permissions' => [
                'cash.view',
                'reconcile.view',
                'petty_cash.view',
                'pembayaran.view',
                'penerimaan.view',
                'journal.view',
                'reports.pl.view', 'reports.balance_sheet.view',
                'anggaran.view',
                'tax.view',
                'period.view',
                'entities.view',
                'coa.view', 'masters.view',
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $key) {
            Permission::firstOrCreate(['name' => $key, 'guard_name' => 'web']);
        }

        foreach (self::ROLES as $name => $config) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->syncPermissions($config['permissions'] === '*' ? self::PERMISSIONS : $config['permissions']);
        }

        $entityId = Entity::where('code', 'YDI')->value('id');
        $vvg = Vihara::where('code', 'VVG')->value('id');
        $vbm = Vihara::where('code', 'VBM')->value('id');
        $vad = Vihara::where('code', 'VAD')->value('id');

        // Demo users — ganti password setelah deploy. Sengaja mencakup
        // setiap tingkat hirarki supaya isolasi bisa dibuktikan.
        $demo = [
            // Admin tertinggi: lintas yayasan.
            ['email' => 'admin@vihara.test', 'name' => 'Admin Sistem', 'role' => 'superadmin',
                'scope' => User::SCOPE_GLOBAL, 'viharas' => []],

            // Tingkat yayasan: seluruh vihara YDI.
            ['email' => 'yayasan@ydi.test', 'name' => 'Admin Yayasan YDI', 'role' => 'admin_yayasan',
                'scope' => User::SCOPE_ENTITY, 'viharas' => []],
            ['email' => 'pengawas@ydi.test', 'name' => 'Pengawas YDI', 'role' => 'pengawas_yayasan',
                'scope' => User::SCOPE_ENTITY, 'viharas' => []],

            // Tingkat vihara tunggal.
            ['email' => 'bendahara@vvg.test', 'name' => 'Bendahara VVG', 'role' => 'bendahara',
                'scope' => User::SCOPE_VIHARA, 'viharas' => [$vvg]],
            ['email' => 'approver@vvg.test', 'name' => 'Approver VVG', 'role' => 'approver',
                'scope' => User::SCOPE_VIHARA, 'viharas' => [$vvg]],
            ['email' => 'viewer@vvg.test', 'name' => 'Viewer VVG', 'role' => 'viewer',
                'scope' => User::SCOPE_VIHARA, 'viharas' => [$vvg]],

            // Vihara lain — pembanding untuk membuktikan isolasi.
            ['email' => 'bendahara@vbm.test', 'name' => 'Bendahara VBM', 'role' => 'bendahara',
                'scope' => User::SCOPE_VIHARA, 'viharas' => [$vbm]],

            // Beberapa vihara sekaligus (VVG + VAD, tanpa VBM).
            ['email' => 'bendahara.multi@ydi.test', 'name' => 'Bendahara VVG+VAD', 'role' => 'bendahara',
                'scope' => User::SCOPE_VIHARA, 'viharas' => [$vvg, $vad]],
        ];

        foreach ($demo as $u) {
            $user = User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'entity_id' => $entityId,
                    'scope_type' => $u['scope'],
                ],
            );
            $user->syncRoles([$u['role']]);
            $user->viharas()->sync(array_values(array_filter($u['viharas'])));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
