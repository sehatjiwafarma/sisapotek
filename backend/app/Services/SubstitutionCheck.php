<?php

namespace App\Services;

use App\Models\Product;

class SubstitutionCheck
{
    /** This checks master data for a calculation, not clinical equivalence. */
    public static function assess(?Product $prescribed, ?Product $selected): array
    {
        $unknown = ['ok' => false, 'message' => 'Data kandungan, kekuatan, satuan, atau bentuk sediaan belum lengkap untuk diperiksa.'];
        if (! $prescribed || ! $selected || ! $prescribed->dosage_form || ! $selected->dosage_form || ! $prescribed->base_unit || ! $selected->base_unit) return $unknown;
        if ($prescribed->dosage_form !== $selected->dosage_form || $prescribed->base_unit !== $selected->base_unit) return ['ok' => false, 'message' => 'Bentuk sediaan atau satuan dasar produk berbeda.'];
        $a = $prescribed->ingredients;
        $b = $selected->ingredients;
        if ($a->count() !== 1 || $b->count() !== 1) return $unknown;
        $a = $a->first(); $b = $b->first();
        if (mb_strtolower(trim($a->name)) !== mb_strtolower(trim($b->name))) return ['ok' => false, 'message' => 'Zat aktif produk berbeda.'];
        $units = ['mcg' => 1, 'mg' => 1000, 'g' => 1000000, 'IU' => 1];
        if (! isset($units[$a->unit], $units[$b->unit]) || (($a->unit === 'IU') !== ($b->unit === 'IU')) || ! $a->amount || ! $b->amount || ! $a->basis_quantity || ! $b->basis_quantity || $a->basis_unit !== $prescribed->base_unit || $b->basis_unit !== $selected->base_unit) return $unknown;
        $ratio = ((float) $b->amount * $units[$b->unit] / (float) $b->basis_quantity) / ((float) $a->amount * $units[$a->unit] / (float) $a->basis_quantity);
        if (! is_finite($ratio) || $ratio <= 0 || $ratio > 100000) return $unknown;
        return ['ok' => true, 'ratio' => $ratio, 'message' => 'Data zat aktif dan bentuk sediaan cocok untuk perhitungan. Apoteker wajib memeriksa kesesuaian klinis, aturan pakai, dan dosis.'];
    }
}
