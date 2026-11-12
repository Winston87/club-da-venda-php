<?php
declare(strict_types=1);

namespace Club\Views;

final class Layout
{
    public static function render(string $title, callable $content, callable $escape): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $escape($title) . '</title><link rel="stylesheet" href="/assets/css/app.css?v=18"><script src="/assets/js/store.js?v=12" defer></script><script src="/assets/js/campaign-actions.js?v=2" defer></script><script src="/assets/js/dashboard-filter.js?v=1" defer></script><script src="/assets/js/product-editor.js?v=1" defer></script><script src="/assets/js/settings-preview.js?v=1" defer></script><script src="/assets/js/coupon-form.js?v=1" defer></script><script src="/assets/js/coupon-modal.js?v=1" defer></script><script src="/assets/js/admin-live-refresh.js?v=1" defer></script><script src="/assets/js/customer-history-modal.js?v=2" defer></script></head><body>';
        $content();
        echo '</body></html>';
    }
}
