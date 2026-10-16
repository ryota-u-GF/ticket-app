<x-mail::message>
# チケットの購入が完了しました

ご購入ありがとうございます。内容は次のとおりです。

**{{ $order->event->title }}**

- 日時: {{ $order->event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}
- 会場: {{ $order->event->venue }}

<x-mail::table>
| 券種 | 枚数 | 小計 |
|:-----|-----:|-----:|
@foreach ($order->items as $item)
| {{ str_replace('|', '｜', $item->ticketType->name) }} | {{ $item->quantity }}枚 | {{ number_format($item->unit_price * $item->quantity) }}円 |
@endforeach
</x-mail::table>

**合計: {{ number_format($order->total_amount) }}円**

<x-mail::button :url="$orderUrl">
注文詳細を見る
</x-mail::button>

チケットのコードは注文詳細ページに表示されます。
</x-mail::message>
