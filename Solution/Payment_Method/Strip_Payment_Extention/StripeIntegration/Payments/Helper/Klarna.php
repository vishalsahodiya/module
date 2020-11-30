<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\CouldNotSaveException;

class Klarna
{
    public $supported = [
        "AT" => ["country" => "Austria", "locales" => ["de-AT", "en-AT"], "currencies" => ["EUR"]],
        "DK" => ["country" => "Denmark", "locales" => ["da-DK", "en-DK"], "currencies" => ["DKK"]],
        "FI" => ["country" => "Finland", "locales" => ["fi-FI", "sv-FI", "en-FI"], "currencies" => ["EUR"]],
        "DE" => ["country" => "Germany", "locales" => ["de-DE", "en-DE"], "currencies" => ["EUR"]],
        "NL" => ["country" => "Netherlands", "locales" => ["nl-NL", "en-NL"], "currencies" => ["EUR"]],
        "NO" => ["country" => "Norway", "locales" => ["nb-NO", "en-NO"], "currencies" => ["NOK"]],
        "SE" => ["country" => "Sweden", "locales" => ["sv-SE", "en-SE"], "currencies" => ["SEK"]],
        "CH" => ["country" => "Switzerland", "locales" => ["de-CH", "fr-CH", "it-CH", "en-CH"], "currencies" => ["CHF"]],
        "GB" => ["country" => "United Kingdom", "locales" => ["en-GB"], "currencies" => ["GBP"]],
        "US" => ["country" => "United States", "locales" => ["en-US"], "currencies" => ["USD"]]
    ];

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \Magento\Framework\Session\Generic $session,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Framework\Locale\Resolver $localeResolver
    ) {
        $this->helper = $helper;
        $this->session = $session;
        $this->config = $config;
        $this->cart = $cart;
        $this->localeResolver = $localeResolver;
    }

    public function getSourceParams($billingAddress, $shippingAddress = null, $shippingMethod = null, $guestEmail = null)
    {
        $quote = $this->helper->getQuote();
        $fields = $this->config->getAmountCurrencyFromQuote($quote);

        $country = $billingAddress["countryId"];
        $street = $billingAddress["street"];
        $supported = $this->supported;
        $email = $quote->getCustomerEmail();

        // Guest customers do not have a quote email
        if (empty($email))
            $email = $guestEmail;

        $testEmail = $this->getTestEmail();
        if (!empty($testEmail))
            $email = $testEmail;

        if (!in_array($country, array_keys($supported)))
            throw new \Exception((string)__("The selected billing country %1 is not supported by Klarna", $country));

        if (!in_array($fields["currency"], $supported[$country]["currencies"]))
            throw new \Exception((string)__("The selected currency %1 is not supported for the billing country %2", $fields["currency"], $supported[$country]["country"]));

        $params = [
            "type" => "klarna",
            "flow" => "redirect",
            "amount" => $fields["amount"],
            "currency" => $fields["currency"],
            "klarna" => [
                "product" => "payment",
                "purchase_country" => $country,
                "first_name" => $billingAddress["firstname"],
                "last_name" => $billingAddress["lastname"],
                "locale" => strtolower(str_replace("_", "-", $this->localeResolver->getLocale()))
            ],
            "source_order" => [
                "items" => $this->getOrderItems($quote)
            ],
            "owner" => [
                "name" => $billingAddress["firstname"] . " " . $billingAddress["lastname"],
                "email" => $email,
                "phone" => $billingAddress["telephone"],
                "address" => [
                    "city" => $billingAddress["city"],
                    "country" => $billingAddress["countryId"],
                    "postal_code" => $billingAddress["postcode"],
                    "state" => $billingAddress["region"],
                    "line1" => $street[0],
                    "line2" => (empty($street[1]) ? "" : $street[1])
                ]
            ],
            "redirect" => [
                "return_url" => $this->helper->getUrl('stripe/payment/index')
            ]
        ];

        if ($country == "US")
        {
            $options = $this->config->getConfigData("custom_payment_methods", "klarna");
            if (!empty($options))
                $params["klarna"]["custom_payment_methods"] = $options;
        }

        $attachment = $this->getKlarnaAttachment();
        if (!empty($attachment))
            $params["klarna"]["attachment"] = $attachment;

        if (!$quote->getIsVirtual() && $shippingAddress)
        {
            $street = $shippingAddress["street"];
            $params["source_order"]["shipping"] = [
                "address" => [
                    "city" => $shippingAddress["city"],
                    "country" => $shippingAddress["countryId"],
                    "postal_code" => $shippingAddress["postcode"],
                    "state" => $shippingAddress["region"],
                    "line1" => $street[0],
                    "line2" => (empty($street[1]) ? "" : $street[1])
                ],
                "phone" => $shippingAddress["telephone"],
                "carrier" => $shippingMethod
            ];
            $params["klarna"]["shipping_first_name"] = $shippingAddress["firstname"];
            $params["klarna"]["shipping_last_name"] = $shippingAddress["lastname"];

            if (!in_array($shippingAddress["countryId"], array_keys($supported)))
                throw new \Exception((string)__("The selected shipping country %1 is not supported by Klarna", $shippingAddress["countryId"]));
        }

        return $params;
    }

    public function createSource($billingAddress, $shippingAddress = null, $shippingMethod = null, $guestEmail = null)
    {
        $params = $this->getSourceParams($billingAddress, $shippingAddress, $shippingMethod, $guestEmail);
        return \Stripe\Source::create($params);
    }

    public function updateSource($sourceId, $billingAddress, $shippingAddress = null, $shippingMethod = null, $guestEmail = null)
    {
        $source = \Stripe\Source::retrieve($sourceId);
        $params = $this->getSourceParams($billingAddress, $shippingAddress, $shippingMethod, $guestEmail);
        unset($params["type"]);
        unset($params["currency"]);
        unset($params["flow"]);
        unset($params["redirect"]);
        unset($params["klarna"]["product"]);
        unset($params["klarna"]["purchase_country"]);
        unset($params["klarna"]["custom_payment_methods"]);
        unset($params["source_order"]["items"]);
        return \Stripe\Source::update($sourceId, $params);
    }

    public function getKlarnaAttachment()
    {
        // Overwrite this method to provide additional purchase information for your business to increase approval rates
        // Values provided vary based on business type and can be found at https://developers.klarna.com/api/#checkout-api__create-a-new-order__attachment
        return null;
    }

    public function getOrderItems($quote)
    {
        $items = [];
        $useStoreCurrency = $this->config->getConfigData('use_store_currency');
        $tax = 0;
        $discount = 0;
        $shipping = 0;

        if ($useStoreCurrency)
        {
            $currency = $quote->getQuoteCurrencyCode();
            if (!$quote->getIsVirtual())
                $shipping = $quote->getShippingAddress()->getShippingAmount();
        }
        else
        {
            $currency = $quote->getBaseCurrencyCode();
            if (!$quote->getIsVirtual())
                $shipping = $quote->getShippingAddress()->getBaseShippingAmount();
        }

        $cents = $this->helper->isZeroDecimal($currency) ? 1 : 100;

        $quoteItems = $quote->getAllVisibleItems();
        foreach ($quoteItems as $item)
        {
            if ($useStoreCurrency)
            {
                $amount = $item->getRowTotal() - $item->getDiscountAmount();
                $tax += $item->getTaxAmount();
                // $discount += $item->getDiscountAmount();
            }
            else
            {
                $amount = $item->getBaseRowTotal() - $item->getBaseDiscountAmount();
                $tax += $item->getBaseTaxAmount();
                // $discount += $item->getBaseDiscountAmount();
            }

            $items[] = [
                "type" => "sku",
                "parent" => $item->getSku(),
                "description" => $item->getName(),
                "quantity" => $item->getQty(),
                "currency" => $currency,
                "amount" => round($amount * $cents)
            ];
        }

        if ($tax > 0)
        {
            $items[] = [
                "type" => "tax",
                "description" => "Tax",
                "currency" => $currency,
                "amount" => round($tax * $cents)
            ];
        }

        // Not supported by the Stripe API
        // if ($discount > 0)
        // {
        //     $items[] = [
        //         "type" => "discount",
        //         "description" => "Discount",
        //         "currency" => $currency,
        //         "amount" => round($discount * $cents)
        //     ];
        // }

        if ($shipping > 0)
        {
            $items[] = [
                "type" => "shipping",
                "description" => "Shipping",
                "currency" => $currency,
                "amount" => round($shipping * $cents)
            ];
        }

        return $items;
    }

    public function getPaymentOptions($source)
    {
        $paymentOptions = [];

        if (empty($source->klarna->payment_method_categories))
            throw new LocalizedException(__("Sorry, there are no available payment options"));

        $categories = explode(",", $source->klarna->payment_method_categories);
        foreach ($categories as $category)
        {
            $keyName = $category . "_name";
            $keyRedirectUrl = $category . "_redirect_url";
            $paymentOptions[$category] = [
                "key" => $category,
                "name" => $source->klarna->$keyName,
                "redirect_url" => $source->klarna->$keyRedirectUrl
            ];
        }

        return $paymentOptions;
    }

    // Use this for testing based on https://stripe.com/docs/sources/klarna#testing-klarna-payments
    public function getTestEmail()
    {
        // return "test+red@example.com"; // (US) The selected payment option becomes unavailable
        // return "test+denied@stripe.com"; // (US) Authorization fails with "Your purchase can not be accepted"
        return null;
    }
}