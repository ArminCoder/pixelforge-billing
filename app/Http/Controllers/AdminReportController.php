<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * What users paid, what Pixelforge charged, and the profit.
 *
 * Days are UTC days of completion, because that is how Pixelforge's own
 * usage report counts. Grouping by created_at would not match their numbers.
 */
class AdminReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless(in_array($request->user()->email, config('pixelforge.admin_emails'), true), 403);

        $totals = 'COUNT(*) as images, SUM(price_nanos) as paid, SUM(cost_nanos) as cost';

        $byDay = DB::table('generations')
            ->where('status', GenerationStatus::Succeeded->value)
            ->selectRaw("DATE(completed_at) as label, {$totals}")
            ->groupBy('label')
            ->orderByDesc('label')
            ->get();

        $byUser = DB::table('generations')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('status', GenerationStatus::Succeeded->value)
            ->selectRaw("users.email as label, {$totals}")
            ->groupBy('users.email')
            ->orderByDesc('paid')
            ->get();

        return Inertia::render('admin/Report', [
            'byDay' => $this->rows($byDay),
            'byUser' => $this->rows($byUser),
        ]);
    }

    /**
     * Databases return SUM() as a string, so turn the numbers back into integers.
     *
     * @param  Collection<int, stdClass>  $rows  plain rows from DB::table()
     * @return list<array{label: string, images: int, paid: int, cost: int, profit: int}>
     */
    private function rows(Collection $rows): array
    {
        return array_values($rows->map(fn (stdClass $row) => [
            'label' => (string) $row->label,
            'images' => (int) $row->images,
            'paid' => (int) $row->paid,
            'cost' => (int) $row->cost,
            'profit' => (int) $row->paid - (int) $row->cost,
        ])->all());
    }
}
