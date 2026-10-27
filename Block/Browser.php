<?php
declare(strict_types=1);
namespace Perspective\Observability\Block;

class Browser extends \Magento\Framework\View\Element\Template
{
    public function getPageTypes(): array
    {
        return [
            'cms_index_index' => 'home', 'cms_page_view' => 'content',
            'catalog_product_view' => 'product', 'catalog_category_view' => 'category',
            'catalogsearch_result_index' => 'search', 'checkout_cart_index' => 'cart',
            'checkout_index_index' => 'checkout', 'customer_account_login' => 'login',
        ];
    }

    public function getBrowserConfig(): string
    {
        try {
            $pages = $this->getPageTypes();
            return json_encode([
                'page' => $pages[$this->getRequest()->getFullActionName()] ?? 'other',
                'sampleRate' => max(0, min(1, (float) $this->_scopeConfig->getValue('observability/browser/sample_rate', 'store'))),
                'requireConsent' => $this->_scopeConfig->isSetFlag('observability/browser/require_consent', 'store'),
            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            // Empty configuration suppresses the optional script, not the storefront.
            return '';
        }
    }
}
