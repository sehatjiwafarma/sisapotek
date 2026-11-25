@if($sale->discount_rule_id || $sale->tax_rule_id)
<section class="card pos-section" style="margin-bottom:20px"><h2>Rincian diskon & PPN</h2><div class="pos-totals">
@if($sale->discount_rule_id)<div><span>{{ $sale->discount_name }} ({{ $sale->discount_type === 'percentage' ? number_format($sale->discount_value,2,',','.').'%' : 'nominal' }})</span><strong>Rp {{ number_format($sale->discount,2,',','.') }}</strong></div>@endif
@if($sale->tax_rule_id)<div><span>Nilai sebelum PPN</span><strong>Rp {{ number_format($sale->taxable_base,2,',','.') }}</strong></div><div><span>{{ $sale->tax_name }} {{ number_format($sale->tax_rate,2,',','.') }}% ({{ $sale->tax_mode === 'inclusive' ? 'termasuk harga' : 'ditambahkan' }})</span><strong>Rp {{ number_format($sale->tax_amount,2,',','.') }}</strong></div>@endif
</div></section>
@endif
