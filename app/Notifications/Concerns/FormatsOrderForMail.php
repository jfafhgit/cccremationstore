<?php

namespace App\Notifications\Concerns;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Str;

/**
 * Shared helpers for the order emails, which are Markdown mail templates.
 */
trait FormatsOrderForMail
{
    /**
     * Families type most of what these emails show (their names, the
     * deceased's name), and Markdown would turn "[text](url)" or "**" into
     * links and formatting. Backslash-escaping Markdown's punctuation keeps
     * it plain text; Blade's {{ }} still escapes any HTML on top of that.
     */
    protected function escapeMarkdown(?string $text): string
    {
        return preg_replace('/([\\\\`*_{}\[\]()#+\-.!|<>~])/', '\\\\$1', (string) $text);
    }

    /**
     * Multi-line text a family typed (an obituary, say), escaped like
     * escapeMarkdown() but keeping their line breaks and paragraphs. Leading
     * spaces are dropped so an indented line can't become a code block.
     */
    protected function escapeMarkdownBlock(?string $text): string
    {
        $lines = array_map(
            fn (string $line): string => $this->escapeMarkdown(trim($line)),
            preg_split('/\R/', trim((string) $text)) ?: [],
        );

        // A trailing backslash is a Markdown hard line break.
        return preg_replace('/(?<!\n)\n(?!\n)/', "\\\\\n", implode("\n", $lines));
    }

    /**
     * The order's line items and totals, ready for the shared summary partial.
     *
     * @return array{
     *     orderNumber: string,
     *     paidAt: string|null,
     *     items: list<array{name: string, variant: string|null, quantity: int, total: string}>,
     *     subtotal: string,
     *     tax: string|null,
     *     processingFee: string|null,
     *     total: string,
     * }
     */
    protected function orderSummary(Order $order): array
    {
        return [
            'orderNumber' => $order->order_number,
            'paidAt' => $order->paid_at?->setTimezone($order->store->timezone)->format('F j, Y \a\t g:i A T'),
            'items' => array_values($order->items->map(fn (OrderItem $item): array => [
                'name' => $this->escapeMarkdown($item->name_snapshot),
                'variant' => $item->variant_snapshot ? $this->escapeMarkdown($item->variant_snapshot) : null,
                'quantity' => $item->quantity,
                'total' => '$'.number_format($item->total_price_cents / 100, 2),
            ])->all()),
            'subtotal' => '$'.$order->subtotalInDollars(),
            'tax' => $order->tax_cents > 0 ? '$'.$order->taxInDollars() : null,
            'processingFee' => $order->processing_fee_cents > 0 ? '$'.$order->processingFeeInDollars() : null,
            'total' => '$'.$order->totalInDollars(),
        ];
    }

    /**
     * What the <x-mail::store-message> frame needs: the funeral home heads
     * the email, and the platform is only mentioned in the footer.
     *
     * @return array{storeName: string, storeUrl: string, logoUrl: string|null, footer: string}
     */
    protected function storeFrame(Order $order, string $storeUrl, string $footer): array
    {
        return [
            'storeName' => $order->store->name,
            'storeUrl' => $storeUrl,
            'logoUrl' => $order->store->brandLogoUrl(),
            'footer' => $footer,
        ];
    }

    /**
     * The footer line naming the platform, already escaped for Markdown.
     */
    protected function poweredByFooter(string $prefix): string
    {
        return $this->escapeMarkdown($prefix.' '.config('app.name').'.');
    }

    /**
     * Mail goes out from the platform's own verified address, but under the
     * funeral home's name so families recognize who it is from.
     */
    protected function senderNameFor(Order $order): string
    {
        return Str::limit($order->store->name, 70, '');
    }
}
