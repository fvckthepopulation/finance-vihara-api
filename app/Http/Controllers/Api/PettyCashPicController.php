<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PettyCashPic;
use App\Models\Vihara;
use App\Services\ViharaScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Master PIC (pemegang) kas kecil per vihara. */
class PettyCashPicController extends Controller
{
    /** Dropdown master — PIC aktif, scoped per vihara user. */
    public function index(Request $request): JsonResponse
    {
        $q = PettyCashPic::query()
            ->where('active', true)
            ->orderBy('name');

        if ($viharaId = ViharaScopeService::readFilter($request)) {
            $q->where('vihara_id', $viharaId);
        }

        return response()->json([
            'data' => $q->get(['id', 'vihara_id', 'name', 'active']),
        ]);
    }

    public function store(Request $request, Vihara $vihara): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('petty_cash_pics', 'name')->where('vihara_id', $vihara->id),
            ],
            'active' => ['sometimes', 'boolean'],
        ]);

        $pic = $vihara->pettyCashPics()->create($data);

        return response()->json($pic, 201);
    }

    public function update(Request $request, PettyCashPic $pettyCashPic): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('petty_cash_pics', 'name')
                    ->where('vihara_id', $pettyCashPic->vihara_id)
                    ->ignore($pettyCashPic->id),
            ],
            'active' => ['sometimes', 'boolean'],
        ]);

        $pettyCashPic->update($data);

        return response()->json($pettyCashPic->fresh());
    }

    public function destroy(PettyCashPic $pettyCashPic): JsonResponse
    {
        if ($pettyCashPic->movements()->exists()) {
            return response()->json([
                'message' => 'PIC sudah punya movement kas kecil — tidak bisa dihapus. Nonaktifkan saja (active=false).',
            ], 422);
        }

        $pettyCashPic->delete();

        return response()->json(['message' => 'deleted']);
    }
}
