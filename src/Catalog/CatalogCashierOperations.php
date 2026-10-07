<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Parameter names and arities from Cashier Stripe 16.8.0; argument values are omitted. */
final class CatalogCashierOperations
{
    public const MODEL = [
        'newsubscription' => [['type', 'prices'], 1], 'subscription' => [['type'], 0],
        'subscribed' => [['type', 'price'], 0], 'ontrial' => [['type', 'price'], 0],
        'checkout' => [['items', 'sessionOptions', 'customerOptions'], 1],
        'charge' => [['amount', 'paymentMethod', 'options'], 2], 'pay' => [['amount', 'options'], 1],
        'paywith' => [['amount', 'paymentMethods', 'options'], 2], 'createpayment' => [['amount', 'options'], 1],
        'findpayment' => [['id'], 1], 'refund' => [['paymentIntent', 'options'], 1],
        'checkoutcharge' => [['amount', 'name', 'quantity', 'sessionOptions', 'customerOptions', 'productData'], 2],
    ];

    public const BUILDER = [
        'create' => [['paymentMethod', 'customerOptions', 'subscriptionOptions'], 0],
        'add' => [['customerOptions', 'subscriptionOptions'], 0], 'createandsendinvoice' => [['customerOptions', 'subscriptionOptions'], 0],
        'checkout' => [['sessionOptions', 'customerOptions'], 0],
    ];

    public const SUBSCRIPTION = [
        'cancel' => [[], 0], 'cancelnow' => [[], 0], 'cancelnowandinvoice' => [[], 0], 'resume' => [[], 0],
        'cancelat' => [['endsAt'], 1], 'swap' => [['prices', 'options'], 1], 'swapandinvoice' => [['prices', 'options'], 1],
        'updatequantity' => [['quantity', 'price'], 1], 'incrementquantity' => [['count', 'price'], 0], 'decrementquantity' => [['count', 'price'], 0],
    ];

    public const FLUENT = ['price', 'meteredprice', 'quantity', 'trialdays', 'trialuntil', 'skiptrial', 'anchorbillingcycleon', 'withbillingthresholds', 'withmetadata'];
}
