<?php

return [
    /*
     * Plafon dana kas kecil (imprest fund). Sementara konstanta global —
     * pindahkan ke settings per akun kas kecil bila sudah dibutuhkan.
     */
    'imprest' => (float) env('PETTY_CASH_IMPREST', 2000000),
];
