<?php

namespace App\Libraries;

use App\Models\InventoryLogModel;
use App\Models\ProductModel;
use App\Models\ReorderAlertModel;
use App\Models\ReorderNoticeModel;
use App\Models\SalesItemModel;
use App\Models\SalesTransactionModel;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * POS "Checkout & Print Receipt", shared by the Owner's and the Staff's SalesController.
 *
 * Saved at most once per form: the form's one-time key (OnceToken) is claimed inside the sale's
 * transaction, so a repeated or simultaneous submit of the same form creates no second sale and
 * gets the first sale's receipt back instead.
 *
 * Stock is checked and decreased inside that transaction: the product rows are locked
 * (SELECT ... FOR UPDATE), each decrease is relative (current_stock = current_stock - qty, only
 * where at least qty is left), and if any line can't be taken everything is rolled back, so two
 * cashiers can never both sell the last unit and nothing is ever half-saved.
 */
final class PosCheckout
{
    public const ACTION = 'pos_checkout';

    /** 'code' of a refusal because an item doesn't have enough stock (the cart is kept: SalesController). */
    public const NOT_ENOUGH_STOCK = 'not_enough_stock';

    /** The most cash one sale may record as received (pesos). */
    public const MAX_CASH = 1000000;

    public const OR_ERROR        = 'Use numbers only (up to 20 digits).';
    public const MONEY_ERROR     = 'Use numbers only, with up to 2 decimals.';
    public const MAX_CASH_ERROR  = 'Cash received can be up to ₱1,000,000.';
    public const GCASH_REF_ERROR = 'The GCash reference no. is exactly 13 digits.';

    /**
     * A typed peso amount: commas and spaces dropped, then digits with up to 2 decimals (no sign).
     * '' is 0. Null when it is not an amount (letters, a minus, 3 decimals...).
     */
    public static function money($typed): ?float
    {
        $value = str_replace([',', ' '], '', trim((string) $typed));
        if ($value === '') {
            return 0.0;
        }

        return preg_match('/^\d+(\.\d{1,2})?$/', $value) === 1 ? round((float) $value, 2) : null;
    }

    /**
     * A refusal names the payment or OR field it is about ('field': cash_received, gcash_amount,
     * gcash_reference_no, or_number) so the form can show it under that box (SalesController keeps the cart).
     *
     * @return array{error?: string, code?: string, field?: string, receipt?: ?array, repeat?: bool, receipt_no?: ?string}
     */
    public static function run(IncomingRequest $request, ?int $userId, string $staffName): array
    {
        $key = OnceToken::key($request);
        if ($earlier = OnceToken::used($key)) {
            return self::repeat($earlier, $request, $staffName);
        }

        $itemIds    = (array) ($request->getPost('item_id') ?? []);
        $quantities = (array) ($request->getPost('quantity') ?? []);
        $method     = $request->getPost('payment_method') ?: 'Cash';

        if ($itemIds === []) {
            return ['error' => 'Cart is empty.'];
        }
        if (! in_array($method, ['Cash', 'GCash', 'Split'], true)) {
            $method = 'Cash';
        }

        // Prices and a first stock check (re-checked under lock below).
        $productModel = new ProductModel();
        $products     = [];
        $cartLines    = [];
        foreach ($itemIds as $i => $itemId) {
            $product = $productModel->find($itemId);
            if (! $product) {
                continue;
            }
            $qty = max(1, (int) ($quantities[$i] ?? 1));
            if ($qty > (int) $product['current_stock']) {
                return ['error' => self::notEnough($product), 'code' => self::NOT_ENOUGH_STOCK];
            }
            $products[]  = $product;
            $cartLines[] = [
                'item_id'    => $product['item_id'],
                'item_name'  => $product['item_name'],
                'unit_price' => (float) $product['selling_price'],
                'qty'        => $qty,
            ];
        }
        if ($cartLines === []) {
            return ['error' => 'Cart is empty.'];
        }

        $pricing  = PosPricing::priceCart($cartLines);
        $subtotal = $pricing['subtotal'];
        $discount = $pricing['discount'];
        $total    = $pricing['total'];

        $payment = self::payment($request, $method, $total);
        if (isset($payment['error'])) {
            return $payment;
        }

        // The official receipt / invoice no. from the store's BIR booklet: optional. A number used
        // before is only warned about in the form (SalesController::checkOr), never refused here.
        // Digits only, 1-20, kept as text (leading zeros stay).
        $orNumber = trim((string) $request->getPost('or_number'));
        if ($orNumber !== '' && ! SalesTransactionModel::validOrNumber($orNumber)) {
            return ['error' => self::OR_ERROR, 'field' => 'or_number'];
        }
        $orNumber = $orNumber !== '' ? $orNumber : null;

        $db           = db_connect();
        $sales        = new SalesTransactionModel();
        $salesItems   = new SalesItemModel();
        $inventoryLog = new InventoryLogModel();

        $db->transStart();

        if ($earlier = OnceToken::claim($key, self::ACTION, $userId)) {
            return self::repeat($earlier, $request, $staffName);
        }

        // Lock the rows, then check what is really there now.
        $locked = $productModel->lockRows(array_column($cartLines, 'item_id'));
        $wanted = [];
        foreach ($pricing['lines'] as $line) {
            $wanted[$line['item_id']] = ($wanted[$line['item_id']] ?? 0) + $line['qty'];
        }
        $byId = array_column($products, null, 'item_id');
        foreach ($wanted as $itemId => $qty) {
            $row = $locked[$itemId] ?? null;
            if (! $row || (int) $row['current_stock'] < $qty) {
                $db->transRollback();

                return ['error' => self::notEnough($row ?? ['current_stock' => 0] + $byId[$itemId]), 'code' => self::NOT_ENOUGH_STOCK];
            }
        }

        // Generated inside the transaction (it locks the highest receipt number), so two
        // cashiers checking out at the same moment can never be given the same number.
        $receiptNo = $sales->generateReceiptNo();

        $salesId = $sales->insert([
            'receipt_no'         => $receiptNo,
            'or_number'          => $orNumber,
            'user_id'            => $userId,
            'sale_date'          => date('Y-m-d H:i:s'),
            'payment_method'     => $method,
            'cash_amount'        => $payment['cash_amount'],
            'gcash_amount'       => $payment['gcash_amount'],
            'gcash_reference_no' => $payment['gcash_ref'],
            'subtotal'           => $subtotal,
            'discount'           => $discount,
            'total_amount'       => $total,
        ], true);

        foreach ($pricing['lines'] as $line) {
            $salesItems->insert([
                'sales_id'      => $salesId,
                'item_id'       => $line['item_id'],
                'quantity_sold' => $line['qty'],
                'selling_price' => $line['unit_price'],
            ]);

            $newStock = $productModel->changeStock($line['item_id'], -$line['qty']);
            if ($newStock === null) {
                $db->transRollback();

                return ['error' => self::notEnough($productModel->find($line['item_id']) ?? $line), 'code' => self::NOT_ENOUGH_STOCK];
            }

            $inventoryLog->record($line['item_id'], $userId, 'SALE', -$line['qty'], $newStock, null, 'Ref: sales_id=' . $salesId);
        }

        OnceToken::complete($key, 'sale', $salesId);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return ['error' => 'Checkout failed and was rolled back. Please try again.'];
        }

        $soldIds = array_keys($wanted);
        (new ReorderNoticeModel())->clearResolved($soldIds);
        // Raise any reorder alert this sale caused right away.
        (new ReorderAlertModel())->syncFromLiveInventory();

        return ['receipt' => [
            'receipt_no'         => $receiptNo,
            'or_number'          => $orNumber,
            'sale_date'          => date('Y-m-d H:i:s'),
            'lines'              => self::receiptLines($pricing['lines']),
            'subtotal'           => $subtotal,
            'discount'           => $discount,
            'total'              => $total,
            'cash_received'      => $payment['cash_received'],
            'change'             => max(0, $payment['change']),
            'payment_method'     => $method,
            'cash_amount'        => $payment['cash_amount'],
            'gcash_amount'       => $payment['gcash_amount'],
            'gcash_reference_no' => $payment['gcash_ref'],
            'staff_name'         => $staffName,
        ]];
    }

    /** "Not enough stock for Tactical Pants · Size S (only 2 left). Nothing was saved." */
    private static function notEnough(array $product): string
    {
        $left = max(0, (int) ($product['current_stock'] ?? 0));

        return 'Not enough stock for ' . ProductModel::nameWithSize($product) . " (only {$left} left). Nothing was saved.";
    }

    /**
     * Checks the payment fields; returns the amounts, or ['error' => ..., 'field' => ...].
     * Amounts: commas dropped, digits with up to 2 decimals, never negative (money()); cash received
     * up to MAX_CASH. GCash reference no.: exactly 13 digits. Cash: at least the total. Cash+GCash:
     * GCash more than 0 and less than the total, cash at least the rest (so cash + GCash cover the
     * total exactly; anything over is change).
     */
    private static function payment(IncomingRequest $request, string $method, float $total): array
    {
        $gcashRef = trim((string) ($request->getPost('gcash_reference_no') ?: ''));
        $refError = static function () use ($gcashRef): ?array {
            if ($gcashRef === '') {
                return ['error' => 'GCash reference number is required.', 'field' => 'gcash_reference_no'];
            }

            return preg_match('/^\d{13}$/', $gcashRef) === 1 ? null : ['error' => self::GCASH_REF_ERROR, 'field' => 'gcash_reference_no'];
        };
        $cashReceived = self::money($request->getPost('cash_received'));
        $cashError    = static function () use ($cashReceived): ?array {
            if ($cashReceived === null) {
                return ['error' => self::MONEY_ERROR, 'field' => 'cash_received'];
            }

            return $cashReceived > self::MAX_CASH ? ['error' => self::MAX_CASH_ERROR, 'field' => 'cash_received'] : null;
        };

        if ($method === 'Split') {
            $gcashAmount = self::money($request->getPost('gcash_amount'));
            if ($gcashAmount === null) {
                return ['error' => self::MONEY_ERROR, 'field' => 'gcash_amount'];
            }
            if ($gcashAmount <= 0 || $gcashAmount >= $total) {
                return ['error' => "GCash amount can't be more than the total.", 'field' => 'gcash_amount'];
            }
            if ($error = $refError()) {
                return $error;
            }
            if ($error = $cashError()) {
                return $error;
            }
            $cashDue = round($total - $gcashAmount, 2);
            if ($cashReceived < $cashDue) {
                return ['error' => 'Insufficient cash received for the remaining balance.', 'field' => 'cash_received'];
            }

            return ['cash_amount' => $cashDue, 'gcash_amount' => $gcashAmount, 'gcash_ref' => $gcashRef, 'cash_received' => $cashReceived, 'change' => round($cashReceived - $cashDue, 2)];
        }

        if ($method === 'GCash') {
            if ($error = $refError()) {
                return $error;
            }

            return ['cash_amount' => 0.0, 'gcash_amount' => $total, 'gcash_ref' => $gcashRef, 'cash_received' => $total, 'change' => 0.0];
        }

        if ($error = $cashError()) {
            return $error;
        }
        if ($cashReceived < $total) {
            return ['error' => 'Insufficient cash received.', 'field' => 'cash_received'];
        }

        return ['cash_amount' => $total, 'gcash_amount' => 0.0, 'gcash_ref' => null, 'cash_received' => $cashReceived, 'change' => round($cashReceived - $total, 2)];
    }

    private static function receiptLines(array $lines): array
    {
        return array_map(static fn ($l) => [
            'item_name'  => $l['item_name'],
            'qty'        => $l['qty'],
            'unit_price' => $l['unit_price'],
            'total'      => $l['line_total'],
            'discount'   => $l['discount'],
            'badge'      => $l['badge'],
        ], $lines);
    }

    /** The same form again: no new sale, the FIRST sale's receipt (rebuilt from what was saved). */
    private static function repeat(array $earlier, IncomingRequest $request, string $staffName): array
    {
        $sale = ($earlier['result_type'] ?? null) === 'sale'
            ? (new SalesTransactionModel())->find((int) $earlier['result_id'])
            : null;
        if (! $sale) {
            return ['repeat' => true, 'receipt' => null, 'receipt_no' => null];
        }

        $productModel = new ProductModel();
        $cartLines    = [];
        foreach ((new SalesItemModel())->where('sales_id', $sale['sales_id'])->findAll() as $item) {
            $product     = $productModel->find($item['item_id']);
            $cartLines[] = [
                'item_id'    => $item['item_id'],
                'item_name'  => $product['item_name'] ?? $item['item_id'],
                'unit_price' => (float) $item['selling_price'],
                'qty'        => (int) $item['quantity_sold'],
            ];
        }
        $pricing = PosPricing::priceCart($cartLines);

        $cashAmount   = (float) $sale['cash_amount'];
        $total        = (float) $sale['total_amount'];
        $cashReceived = $sale['payment_method'] === 'GCash'
            ? $total
            : max($cashAmount, round((float) ($request->getPost('cash_received') ?: 0), 2));

        return [
            'repeat'     => true,
            'receipt_no' => $sale['receipt_no'],
            'receipt'    => [
                'receipt_no'         => $sale['receipt_no'],
                'or_number'          => $sale['or_number'] ?? null,
                'sale_date'          => $sale['sale_date'],
                'lines'              => self::receiptLines($pricing['lines']),
                'subtotal'           => (float) $sale['subtotal'],
                'discount'           => (float) $sale['discount'],
                'total'              => $total,
                'cash_received'      => $cashReceived,
                'change'             => $sale['payment_method'] === 'GCash' ? 0.0 : max(0, round($cashReceived - $cashAmount, 2)),
                'payment_method'     => $sale['payment_method'],
                'cash_amount'        => $cashAmount,
                'gcash_amount'       => (float) $sale['gcash_amount'],
                'gcash_reference_no' => $sale['gcash_reference_no'],
                'staff_name'         => $staffName,
            ],
        ];
    }
}
