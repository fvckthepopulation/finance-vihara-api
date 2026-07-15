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

        // Admin
        'users.manage',
        'roles.manage',
        'coa.view',
        'coa.manage',
        'masters.view',
        'masters.manage',
    ];

    public const ROLES = [
        'superadmin' => [
            'description' => 'Full access — managing RBAC, all transactions, period close/reopen.',
            'permissions' => '*',
        ],
        'bendahara' => [
            'description' => 'Day-to-day operasional — input pembayaran/penerimaan, deklarasi petty cash.',
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
                'coa.view', 'masters.view',
            ],
        ],
        'approver' => [
            'description' => 'Approval & reconciliation — approve pembayaran/petty cash, finalize rekonsiliasi.',
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
                'coa.view', 'masters.view',
            ],
        ],
        'viewer' => [
            'description' => 'Read-only — dashboards + reports for stakeholders / auditors.',
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

        // Demo users — change passwords post-deploy. Used by frontend dev.
        // vihara_id null = akses semua vihara (admin yayasan).
        $entityId = Entity::where('code', 'YDI')->value('id');
        $vvgId = Vihara::where('code', 'VVG')->value('id');

        $demo = [
            ['email' => 'admin@vihara.test',     'name' => 'Admin Yayasan',  'role' => 'superadmin', 'vihara_id' => null],
            ['email' => 'bendahara@vvg.test',    'name' => 'Bendahara VVG',  'role' => 'bendahara',  'vihara_id' => $vvgId],
            ['email' => 'approver@vvg.test',     'name' => 'Approver VVG',   'role' => 'approver',   'vihara_id' => $vvgId],
            ['email' => 'viewer@vvg.test',       'name' => 'Viewer VVG',     'role' => 'viewer',     'vihara_id' => $vvgId],
        ];
        foreach ($demo as $u) {
            $user = User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'entity_id' => $entityId,
                    'vihara_id' => $u['vihara_id'],
                ],
            );
            $user->syncRoles([$u['role']]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
