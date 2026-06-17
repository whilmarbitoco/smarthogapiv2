<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Normalize feeding_times from 12-hour AM/PM format to 24-hour H:i
        $rows = DB::table('feeding_schedule')
            ->whereNotNull('feeding_times')
            ->select('id', 'feeding_times')
            ->get();

        foreach ($rows as $row) {
            $times = json_decode($row->feeding_times, true);

            if (! is_array($times)) {
                continue;
            }

            $normalized = [];

            foreach ($times as $time) {
                $time = trim((string) $time);

                if ($time === '') {
                    continue;
                }

                // Already H:i (24-hour)
                if (preg_match('/^\d{2}:\d{2}$/', $time)) {
                    $normalized[] = $time;
                    continue;
                }

                // Try various formats
                $parsed = false;
                foreach (['H:i:s', 'g:i A', 'g:iA', 'h:i A', 'h:iA', 'G:i'] as $format) {
                    $dt = \DateTime::createFromFormat($format, $time);
                    if ($dt !== false) {
                        $normalized[] = $dt->format('H:i');
                        $parsed = true;
                        break;
                    }
                }

                // If we couldn't parse it, keep original (will be handled by scheduler fallback)
                if (! $parsed) {
                    $normalized[] = $time;
                }
            }

            $normalized = array_values(array_unique($normalized));

            DB::table('feeding_schedule')
                ->where('id', $row->id)
                ->update(['feeding_times' => json_encode($normalized)]);
        }
    }

    public function down(): void
    {
        // Not reversible — original format unknown
    }
};
