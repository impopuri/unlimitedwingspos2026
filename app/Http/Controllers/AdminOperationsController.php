<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminOperationsController extends Controller
{
    public function promotions(): JsonResponse
    {
        $promotions = DB::table('promotions')->orderByDesc('active')->orderBy('name')->get()
            ->map(function ($promotion): object {
                $usage = DB::table('orders')
                    ->where('promotion_id', $promotion->id)
                    ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
                    ->selectRaw('COUNT(*) as transactions, COALESCE(SUM(promotion_free_customers), 0) as customers, COALESCE(SUM(promotion_savings_cents), 0) as savings_cents')
                    ->first();
                $promotion->used_transactions = (int) $usage->transactions;
                $promotion->used_customers = (int) $usage->customers;
                $promotion->savings_cents = (int) $usage->savings_cents;

                return $promotion;
            });
        $history = DB::table('orders')
            ->whereNotNull('promotion_name')
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->groupBy('promotion_name')
            ->orderBy('promotion_name')
            ->get([
                'promotion_name as name',
                DB::raw('COUNT(*) as transactions'),
                DB::raw('SUM(promotion_free_customers) as customers'),
                DB::raw('SUM(promotion_savings_cents) as savings_cents'),
            ]);

        return response()->json(['promotions' => $promotions, 'history' => $history]);
    }

    public function createPromotion(Request $request): JsonResponse
    {
        $data = $this->validatePromotion($request);
        $id = DB::table('promotions')->insertGetId([
            'name' => trim($data['name']),
            'description' => trim($data['description']),
            'companions_required' => $data['companions_required'],
            'free_customers_per_group' => $data['free_customers_per_group'],
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($request, 'promotion.created', 'promotion', $id, [
            'name' => trim($data['name']),
            'companions_required' => $data['companions_required'],
            'free_customers_per_group' => $data['free_customers_per_group'],
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function updatePromotion(Request $request, int $promotion): JsonResponse
    {
        abort_unless(DB::table('promotions')->where('id', $promotion)->exists(), 404);
        $data = $this->validatePromotion($request, $promotion);
        DB::table('promotions')->where('id', $promotion)->update([
            'name' => trim($data['name']),
            'description' => trim($data['description']),
            'companions_required' => $data['companions_required'],
            'free_customers_per_group' => $data['free_customers_per_group'],
            'active' => $data['active'],
            'updated_at' => now(),
        ]);
        $this->audit($request, 'promotion.updated', 'promotion', $promotion, [
            'name' => trim($data['name']),
            'companions_required' => $data['companions_required'],
            'free_customers_per_group' => $data['free_customers_per_group'],
            'active' => $data['active'],
        ]);

        return response()->json(['id' => $promotion]);
    }

    public function deletePromotion(Request $request, int $promotion): JsonResponse
    {
        $entry = DB::table('promotions')->where('id', $promotion)->first();
        abort_unless($entry, 404);
        DB::table('promotions')->where('id', $promotion)->delete();
        $this->audit($request, 'promotion.deleted', 'promotion', $promotion, ['name' => $entry->name]);

        return response()->json(['id' => $promotion, 'deleted' => true]);
    }

    private function validatePromotion(Request $request, ?int $promotion = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('promotions', 'name')->ignore($promotion)],
            'description' => ['required', 'string', 'max:1000'],
            'companions_required' => ['required', 'integer', 'min:1', 'max:50'],
            'free_customers_per_group' => ['required', 'integer', 'min:1', 'max:20'],
            'active' => [$promotion === null ? 'sometimes' : 'required', 'boolean'],
        ]);
    }

    public function cashflow(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $data['from'] ?? now()->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $sales = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.cashier_id')
            ->whereIn('orders.status', ['paid', 'partially_refunded', 'refunded'])
            ->whereDate('orders.created_at', '>=', $from)
            ->whereDate('orders.created_at', '<=', $to)
            ->orderByDesc('orders.created_at')
            ->get([
                'orders.id', 'orders.order_number', 'orders.type', 'orders.payment_method',
                DB::raw('(orders.total_cents - orders.delivery_fee_cents) as amount_cents'),
                'orders.delivery_fee_cents', 'orders.total_cents as collected_total_cents',
                'orders.created_at as occurred_at',
                'users.username as recorded_by',
            ]);
        $expenses = DB::table('expenses')
            ->join('users', 'users.id', '=', 'expenses.user_id')
            ->where('expenses.voided', false)
            ->whereDate('expenses.spent_at', '>=', $from)
            ->whereDate('expenses.spent_at', '<=', $to)
            ->orderByDesc('expenses.spent_at')
            ->get([
                'expenses.id', 'expenses.category', 'expenses.description',
                'expenses.amount_cents', 'expenses.spent_at as occurred_at',
                'expenses.reference', 'expenses.paid_with', 'users.username as recorded_by',
            ]);

        $salesTotal = (int) $sales->sum('amount_cents');
        $deliveryFees = (int) $sales->sum('delivery_fee_cents');
        $expensesTotal = (int) $expenses->sum('amount_cents');
        $cashOrders = DB::table('orders')->whereIn('status', ['paid', 'partially_refunded', 'refunded'])->where('payment_method', 'cash')
            ->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);
        $cashSales = (int) (clone $cashOrders)->sum(DB::raw('total_cents - delivery_fee_cents'));
        $cashDeliveryFees = (int) (clone $cashOrders)->sum('delivery_fee_cents');
        $cashExpenses = (int) DB::table('expenses')->where('voided', false)->where('paid_with', 'cash')
            ->whereDate('spent_at', '>=', $from)->whereDate('spent_at', '<=', $to)->sum('amount_cents');
        $cashRefunds = (int) DB::table('refunds')->where('status', 'approved')->where('payment_method', 'cash')
            ->whereDate('reviewed_at', '>=', $from)->whereDate('reviewed_at', '<=', $to)->sum('amount_cents');
        $refundsTotal = (int) DB::table('refunds')->where('status', 'approved')
            ->whereDate('reviewed_at', '>=', $from)->whereDate('reviewed_at', '<=', $to)->sum('amount_cents');
        $otherIncome = (int) DB::table('cash_flow_entries')->where('user_id', $request->user()->id)->where('type', 'other_income')
            ->whereDate('occurred_at', '>=', $from)->whereDate('occurred_at', '<=', $to)->sum('amount_cents');
        $cashOut = (int) DB::table('cash_flow_entries')->where('user_id', $request->user()->id)->whereIn('type', ['cash_refund', 'other_expense'])
            ->whereDate('occurred_at', '>=', $from)->whereDate('occurred_at', '<=', $to)->sum('amount_cents');
        $drawer = DB::table('cash_drawer_sessions')->where('business_date', $from)
            ->where('user_id', $request->user()->id)->orderByDesc('id')->first();
        $expectedClosing = null;
        if ($drawer) {
            $drawerSales = (int) DB::table('orders')->where('cashier_id', $drawer->user_id)->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
                ->where('payment_method', 'cash')->whereDate('created_at', $drawer->business_date)->sum('total_cents');
            $drawerExpenses = (int) DB::table('expenses')->where('user_id', $drawer->user_id)->where('voided', false)
                ->where('paid_with', 'cash')->whereDate('spent_at', $drawer->business_date)->sum('amount_cents');
            $drawerEntries = DB::table('cash_flow_entries')->where('user_id', $drawer->user_id)
                ->whereIn('type', ['other_income', 'other_expense', 'cash_refund'])
                ->whereDate('occurred_at', $drawer->business_date)->get(['type', 'amount_cents']);
            $drawerIncome = (int) $drawerEntries->where('type', 'other_income')->sum('amount_cents');
            $drawerOut = (int) $drawerEntries->whereIn('type', ['other_expense', 'cash_refund'])->sum('amount_cents');
            $expectedClosing = (int) $drawer->opening_cash_cents + $drawerSales + $drawerIncome - $drawerExpenses - $drawerOut;
        }

        return response()->json([
            'from' => $from,
            'to' => $to,
            'sales' => $sales,
            'expenses' => $expenses,
            'sales_total_cents' => $salesTotal,
            'expenses_total_cents' => $expensesTotal,
            'net_cents' => $salesTotal + $deliveryFees - $expensesTotal - $refundsTotal,
            'cash_sales_cents' => $cashSales,
            'non_cash_sales_cents' => $salesTotal - $cashSales,
            'delivery_fees_cents' => $deliveryFees,
            'cash_delivery_fees_cents' => $cashDeliveryFees,
            'collected_total_cents' => $salesTotal + $deliveryFees,
            'cash_expenses_cents' => $cashExpenses,
            'cash_refunds_cents' => $cashRefunds,
            'refunds_total_cents' => $refundsTotal,
            'other_income_cents' => $otherIncome,
            'other_cash_out_cents' => $cashOut,
            'drawer' => $drawer,
            'expected_closing_cash_cents' => $expectedClosing,
        ]);
    }

    public function saveReceiptSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'header' => ['nullable', 'string', 'max:500'],
            'footer' => ['nullable', 'string', 'max:500'],
        ]);
        foreach (['header' => 'receipt_header', 'footer' => 'receipt_footer'] as $field => $key) {
            DB::table('application_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => trim($data[$field] ?? ''), 'updated_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]
            );
        }
        $this->audit($request, 'receipt.settings_updated', 'receipt_settings', null);

        return response()->json(['saved' => true]);
    }

    public function uploadReceiptQr(Request $request): JsonResponse
    {
        $data = $request->validate([
            'qr_image' => [
                'required',
                'file',
                'max:2048',
                function (string $attribute, mixed $file, \Closure $fail): void {
                    $image = @getimagesize($file->getRealPath());
                    if (! $image || ! in_array($image[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                        $fail('The QR image must be a valid PNG or JPEG file.');
                    }
                },
            ],
        ]);
        $image = getimagesize($data['qr_image']->getRealPath());
        $extension = $image[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
        $directory = public_path('receipt-assets');
        File::ensureDirectoryExists($directory);
        $filename = Str::uuid().'.'.$extension;
        $data['qr_image']->move($directory, $filename);
        $path = 'receipt-assets/'.$filename;
        $oldPath = DB::table('application_settings')->where('key', 'receipt_qr_path')->value('value');
        DB::table('application_settings')->updateOrInsert(
            ['key' => 'receipt_qr_path'],
            ['value' => $path, 'updated_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]
        );
        if ($oldPath && $oldPath !== $path && str_starts_with($oldPath, 'receipt-assets/')) {
            File::delete(public_path($oldPath));
        }
        $this->audit($request, 'receipt.qr_uploaded', 'receipt_settings', null, ['path' => $path]);

        return response()->json(['qr_url' => asset($path)]);
    }

    public function resetSalesData(Request $request): JsonResponse
    {
        $request->validate(['confirmation' => ['required', 'in:RESET SALES']]);
        $counts = DB::transaction(function () use ($request): array {
            $counts = [
                'orders' => DB::table('orders')->count(),
                'unli_sessions' => DB::table('unli_sessions')->count(),
            ];
            DB::table('void_requests')->delete();
            DB::table('refunds')->delete();
            DB::table('kitchen_tickets')->delete();
            DB::table('orders')->delete();
            DB::table('unli_rounds')->delete();
            DB::table('unli_sessions')->delete();
            $this->audit($request, 'sales.reset', 'sales', null, $counts);

            return $counts;
        });

        return response()->json(['reset' => true, 'deleted' => $counts]);
    }

    public function openCashDrawer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opening_cash_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'business_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $businessDate = $data['business_date'] ?? now()->toDateString();
        if (DB::table('cash_drawer_sessions')->where('user_id', $request->user()->id)->where('business_date', $businessDate)->exists()) {
            throw ValidationException::withMessages(['drawer' => 'A cash drawer session has already been opened for this business date.']);
        }
        $id = DB::table('cash_drawer_sessions')->insertGetId([
            'business_date' => $businessDate,
            'user_id' => $request->user()->id,
            'opening_cash_cents' => $data['opening_cash_cents'],
            'status' => 'open',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($request, 'cash_drawer.opened', 'cash_drawer_session', $id, ['opening_cash_cents' => $data['opening_cash_cents']]);

        return response()->json(['id' => $id], 201);
    }

    public function cashDrawerStatus(Request $request): JsonResponse
    {
        $businessDate = now()->toDateString();
        $drawer = DB::table('cash_drawer_sessions')
            ->where('user_id', $request->user()->id)
            ->where('business_date', $businessDate)
            ->orderByDesc('id')
            ->first();
        if (! $drawer) {
            return response()->json(['business_date' => $businessDate, 'drawer' => null, 'expected_closing_cash_cents' => null]);
        }
        $sales = (int) DB::table('orders')->where('cashier_id', $drawer->user_id)->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->where('payment_method', 'cash')->whereDate('created_at', $businessDate)->sum('total_cents');
        $expenses = (int) DB::table('expenses')->where('user_id', $drawer->user_id)->where('voided', false)
            ->where('paid_with', 'cash')->whereDate('spent_at', $businessDate)->sum('amount_cents');
        $entries = DB::table('cash_flow_entries')->where('user_id', $drawer->user_id)
            ->whereIn('type', ['other_income', 'other_expense', 'cash_refund'])
            ->whereDate('occurred_at', $businessDate)->get(['type', 'amount_cents']);
        $cashIn = (int) $entries->where('type', 'other_income')->sum('amount_cents');
        $cashOut = (int) $entries->whereIn('type', ['other_expense', 'cash_refund'])->sum('amount_cents');
        $expected = (int) $drawer->opening_cash_cents + $sales + $cashIn - $expenses - $cashOut;

        return response()->json(['business_date' => $businessDate, 'drawer' => $drawer, 'expected_closing_cash_cents' => $expected]);
    }

    public function closeCashDrawer(Request $request, int $drawer): JsonResponse
    {
        $data = $request->validate(['actual_counted_cash_cents' => ['required', 'integer', 'min:0', 'max:100000000']]);
        $result = DB::transaction(function () use ($request, $drawer, $data): array {
            $session = DB::table('cash_drawer_sessions')->where('id', $drawer)->lockForUpdate()->first();
            if (! $session || $session->status !== 'open') {
                throw ValidationException::withMessages(['drawer' => 'This drawer is not open.']);
            }
            if ($request->user()->role !== 'superadmin' && (int) $session->user_id !== (int) $request->user()->id) {
                abort(403);
            }
            $sales = (int) DB::table('orders')->where('cashier_id', $session->user_id)->whereIn('status', ['paid', 'partially_refunded', 'refunded'])->where('payment_method', 'cash')
                ->whereDate('created_at', $session->business_date)->sum('total_cents');
            $expenses = (int) DB::table('expenses')->where('user_id', $session->user_id)->where('voided', false)->where('paid_with', 'cash')
                ->whereDate('spent_at', $session->business_date)->sum('amount_cents');
            $entries = DB::table('cash_flow_entries')->where('user_id', $session->user_id)->whereIn('type', ['other_income', 'other_expense', 'cash_refund'])
                ->whereDate('occurred_at', $session->business_date)->get(['type', 'amount_cents']);
            $cashIn = (int) $entries->where('type', 'other_income')->sum('amount_cents');
            $cashOut = (int) $entries->whereIn('type', ['other_expense', 'cash_refund'])->sum('amount_cents');
            $expected = (int) $session->opening_cash_cents + $sales + $cashIn - $expenses - $cashOut;
            $variance = $data['actual_counted_cash_cents'] - $expected;
            DB::table('cash_drawer_sessions')->where('id', $drawer)->update([
                'expected_closing_cash_cents' => $expected,
                'actual_counted_cash_cents' => $data['actual_counted_cash_cents'],
                'variance_cents' => $variance,
                'status' => 'closed',
                'closed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($request, 'cash_drawer.closed', 'cash_drawer_session', $drawer, [
                'expected_cents' => $expected,
                'counted_cents' => $data['actual_counted_cash_cents'],
                'variance_cents' => $variance,
            ]);

            return ['expected_closing_cash_cents' => $expected, 'variance_cents' => $variance];
        });

        return response()->json($result);
    }

    public function cashEntry(Request $request): JsonResponse
    {
        if (! DB::table('cash_drawer_sessions')->where('user_id', $request->user()->id)
            ->where('business_date', now()->toDateString())->where('status', 'open')->exists()) {
            throw ValidationException::withMessages(['drawer' => 'Open a cash drawer before recording a cash movement.']);
        }
        $data = $request->validate([
            'type' => ['required', Rule::in(['other_income', 'other_expense'])],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'occurred_at' => ['nullable', 'date'],
        ]);
        $id = DB::table('cash_flow_entries')->insertGetId([
            'user_id' => $request->user()->id,
            'type' => $data['type'],
            'amount_cents' => $data['amount_cents'],
            'description' => trim($data['description']),
            'reference' => $data['reference'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($request, 'cash_flow_entry.created', 'cash_flow_entry', $id, ['type' => $data['type']]);

        return response()->json(['id' => $id], 201);
    }

    public function refunds(): JsonResponse
    {
        return response()->json([
            'refunds' => DB::table('refunds')
                ->join('orders', 'orders.id', '=', 'refunds.order_id')
                ->join('users as requester', 'requester.id', '=', 'refunds.requested_by')
                ->leftJoin('users as reviewer', 'reviewer.id', '=', 'refunds.reviewed_by')
                ->orderByDesc('refunds.id')
                ->limit(200)
                ->get([
                    'refunds.id', 'refunds.order_id', 'orders.order_number',
                    'refunds.amount_cents', 'refunds.payment_method', 'refunds.reason',
                    'refunds.status', 'refunds.created_at', 'requester.username as requested_by',
                    'reviewer.username as reviewed_by',
                ]),
        ]);
    }

    public function catalog(): JsonResponse
    {
        return response()->json([
            'categories' => DB::table('categories')->orderBy('id')->get(),
            'flavors' => DB::table('flavors')->orderBy('id')->get(),
            'inventory' => DB::table('inventory_items')->orderBy('name')->get(),
            'products' => DB::table('products')->orderBy('id')->get()->map(function ($product): object {
                $product->inclusions = json_decode($product->inclusions, true) ?? [];
                $product->recipes = DB::table('product_recipes')
                    ->where('product_id', $product->id)
                    ->get(['inventory_item_id', 'quantity_per_product']);

                return $product;
            }),
        ]);
    }

    public function saveCategory(Request $request, ?int $category = null): JsonResponse
    {
        $request->merge(['slug' => Str::slug((string) ($request->input('slug') ?: $request->input('name')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('categories', 'name')->ignore($category)],
            'slug' => ['required', 'alpha_dash', 'max:60', Rule::unique('categories', 'slug')->ignore($category)],
            'active' => ['sometimes', 'boolean'],
        ]);
        $attributes = [
            'name' => trim($data['name']),
            'slug' => strtolower($data['slug']),
            'active' => $data['active'] ?? true,
            'updated_at' => now(),
        ];
        if ($category) {
            abort_unless(DB::table('categories')->where('id', $category)->exists(), 404);
            DB::table('categories')->where('id', $category)->update($attributes);
            $id = $category;
        } else {
            $id = DB::table('categories')->insertGetId($attributes + ['created_at' => now()]);
        }
        $this->audit($request, $category ? 'menu.category_updated' : 'menu.category_created', 'category', $id);

        return response()->json(['id' => $id], $category ? 200 : 201);
    }

    public function saveFlavor(Request $request, ?int $flavor = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('flavors', 'name')->ignore($flavor)],
            'active' => ['sometimes', 'boolean'],
        ]);
        $attributes = ['name' => trim($data['name']), 'active' => $data['active'] ?? true, 'updated_at' => now()];
        if ($flavor) {
            abort_unless(DB::table('flavors')->where('id', $flavor)->exists(), 404);
            DB::table('flavors')->where('id', $flavor)->update($attributes);
            $id = $flavor;
        } else {
            $id = DB::table('flavors')->insertGetId($attributes + ['created_at' => now()]);
        }
        $this->audit($request, $flavor ? 'menu.flavor_updated' : 'menu.flavor_created', 'flavor', $id);

        return response()->json(['id' => $id], $flavor ? 200 : 201);
    }

    public function createInventoryItem(Request $request): JsonResponse
    {
        $request->merge(['sku' => strtoupper(trim((string) $request->input('sku')))]);
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:60', 'alpha_dash', 'unique:inventory_items,sku'],
            'name' => ['required', 'string', 'max:120'],
            'unit' => ['required', 'string', 'max:16'],
            'quantity' => ['required', 'integer', 'min:0', 'max:100000000'],
            'minimum_quantity' => ['required', 'integer', 'min:0', 'max:100000000'],
            'cost_per_unit_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'supplier' => ['nullable', 'string', 'max:120'],
        ]);
        $id = DB::table('inventory_items')->insertGetId([
            'sku' => strtoupper($data['sku']),
            'name' => trim($data['name']),
            'unit' => $data['unit'],
            'quantity' => $data['quantity'],
            'minimum_quantity' => $data['minimum_quantity'],
            'cost_per_unit_cents' => $data['cost_per_unit_cents'] ?? 0,
            'supplier' => $data['supplier'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($data['quantity'] > 0) {
            DB::table('inventory_movements')->insert([
                'inventory_item_id' => $id,
                'user_id' => $request->user()->id,
                'movement_key' => 'inventory-opening-'.Str::uuid(),
                'quantity_change' => $data['quantity'],
                'reason' => 'Initial stock entry',
                'reference' => 'inventory item creation',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->audit($request, 'inventory.item_created', 'inventory_item', $id);

        return response()->json(['id' => $id], 201);
    }

    public function salesCsv(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'flavor_id' => ['nullable', 'integer', 'exists:flavors,id'],
            'cashier_id' => ['nullable', 'integer', 'exists:users,id'],
            'type' => ['nullable', Rule::in(['dine_in', 'takeaway', 'delivery', 'unliwings'])],
        ]);
        $from = $data['from'] ?? now()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        $sales = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.cashier_id')
            ->whereIn('orders.status', ['paid', 'partially_refunded', 'refunded'])
            ->whereDate('orders.created_at', '>=', $from)
            ->whereDate('orders.created_at', '<=', $to)
            ->when(isset($data['cashier_id']), fn ($query) => $query->where('orders.cashier_id', $data['cashier_id']))
            ->when(isset($data['type']), fn ($query) => $query->where('orders.type', $data['type']))
            ->when(isset($data['category_id']), function ($query) use ($data): void {
                $query->whereExists(fn ($subquery) => $subquery->selectRaw('1')->from('order_items')
                    ->join('products', 'products.id', '=', 'order_items.product_id')
                    ->whereColumn('order_items.order_id', 'orders.id')->where('products.category_id', $data['category_id']));
            })
            ->when(isset($data['product_id']), function ($query) use ($data): void {
                $query->whereExists(fn ($subquery) => $subquery->selectRaw('1')->from('order_items')
                    ->whereColumn('order_items.order_id', 'orders.id')->where('order_items.product_id', $data['product_id']));
            })
            ->when(isset($data['flavor_id']), function ($query) use ($data): void {
                $query->whereExists(fn ($subquery) => $subquery->selectRaw('1')->from('order_items')
                    ->join('order_item_flavors', 'order_item_flavors.order_item_id', '=', 'order_items.id')
                    ->whereColumn('order_items.order_id', 'orders.id')->where('order_item_flavors.flavor_id', $data['flavor_id']));
            })
            ->orderBy('orders.created_at')
            ->get([
                'orders.order_number', 'orders.type', 'orders.status', 'orders.payment_method',
                'orders.total_cents', 'orders.delivery_fee_cents', 'orders.created_at', 'users.username',
            ]);

        return response()->streamDownload(function () use ($sales): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to create the sales CSV stream.');
            }

            fwrite($output, "Order number,Type,Status,Payment method,Sales PHP,Delivery fee PHP,Total collected PHP,Date,Cashier\n");
            foreach ($sales as $sale) {
                fwrite($output, implode(',', [
                    $sale->order_number,
                    $sale->type,
                    $sale->status,
                    $sale->payment_method,
                    number_format(($sale->total_cents - $sale->delivery_fee_cents) / 100, 2, '.', ''),
                    number_format($sale->delivery_fee_cents / 100, 2, '.', ''),
                    number_format($sale->total_cents / 100, 2, '.', ''),
                    $sale->created_at,
                    $sale->username,
                ]) . "\n");
            }
            fclose($output);
        }, "sales-{$from}-to-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function salesReport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'flavor_id' => ['nullable', 'integer', 'exists:flavors,id'],
            'cashier_id' => ['nullable', 'integer', 'exists:users,id'],
            'type' => ['nullable', Rule::in(['dine_in', 'takeaway', 'delivery', 'unliwings'])],
            'status' => ['nullable', Rule::in(['paid', 'voided', 'refunded', 'partially_refunded'])],
        ]);
        $from = $data['from'] ?? now()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        $orders = DB::table('orders')
            ->whereDate('orders.created_at', '>=', $from)
            ->whereDate('orders.created_at', '<=', $to)
            ->when(isset($data['cashier_id']), fn ($query) => $query->where('cashier_id', $data['cashier_id']))
            ->when(isset($data['type']), fn ($query) => $query->where('type', $data['type']))
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(isset($data['category_id']), function ($query) use ($data): void {
                $query->whereExists(function ($subquery) use ($data): void {
                    $subquery->selectRaw('1')->from('order_items')
                        ->join('products', 'products.id', '=', 'order_items.product_id')
                        ->whereColumn('order_items.order_id', 'orders.id')
                        ->where('products.category_id', $data['category_id']);
                });
            })
            ->when(isset($data['product_id']), function ($query) use ($data): void {
                $query->whereExists(fn ($subquery) => $subquery->selectRaw('1')->from('order_items')
                    ->whereColumn('order_items.order_id', 'orders.id')->where('order_items.product_id', $data['product_id']));
            })
            ->when(isset($data['flavor_id']), function ($query) use ($data): void {
                $query->whereExists(fn ($subquery) => $subquery->selectRaw('1')->from('order_items')
                    ->join('order_item_flavors', 'order_item_flavors.order_item_id', '=', 'order_items.id')
                    ->whereColumn('order_items.order_id', 'orders.id')->where('order_item_flavors.flavor_id', $data['flavor_id']));
            });
        $orderIds = (clone $orders)->pluck('id');
        $transactions = (clone $orders)
            ->join('users', 'users.id', '=', 'orders.cashier_id')
            ->orderByDesc('orders.created_at')
            ->limit(300)
            ->get([
                'orders.id', 'orders.order_number', 'orders.type', 'orders.status',
                'orders.subtotal_cents', 'orders.discount_cents', 'orders.delivery_fee_cents', 'orders.promotion_name',
                'orders.promotion_free_customers', 'orders.promotion_savings_cents', 'orders.total_cents',
                'orders.payment_method', 'orders.created_at', 'users.username as cashier',
            ]);
        $gross = (int) (clone $orders)->whereIn('status', ['paid', 'refunded', 'partially_refunded'])->sum('subtotal_cents');
        $voided = (int) (clone $orders)->where('status', 'voided')->sum('total_cents');
        $refunds = (int) DB::table('refunds')->whereIn('status', ['approved', 'completed'])
            ->whereIn('order_id', $orderIds)->sum('amount_cents');
        $discounts = (int) (clone $orders)->whereIn('status', ['paid', 'refunded', 'partially_refunded'])->sum('discount_cents');
        $promotionSavings = (int) (clone $orders)->whereIn('status', ['paid', 'refunded', 'partially_refunded'])->sum('promotion_savings_cents');
        $deliveryFees = (int) (clone $orders)->whereIn('status', ['paid', 'refunded', 'partially_refunded'])->sum('delivery_fee_cents');
        $net = $gross - $promotionSavings - $discounts - $refunds;
        $completedCount = (int) (clone $orders)->whereIn('status', ['paid', 'refunded', 'partially_refunded'])->count();
        $promotionUsage = (clone $orders)
            ->whereNotNull('promotion_name')
            ->whereIn('status', ['paid', 'refunded', 'partially_refunded'])
            ->groupBy('promotion_name')
            ->orderBy('promotion_name')
            ->get([
                'promotion_name as name',
                DB::raw('COUNT(*) as transactions'),
                DB::raw('SUM(promotion_free_customers) as customers'),
                DB::raw('SUM(promotion_savings_cents) as savings_cents'),
            ]);
        $productSales = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.id', $orderIds)->whereIn('orders.status', ['paid', 'partially_refunded', 'refunded'])
            ->groupBy('order_items.product_name')
            ->orderByDesc(DB::raw('SUM(order_items.quantity)'))
            ->limit(20)
            ->get([
                'order_items.product_name',
                DB::raw('SUM(order_items.quantity) as quantity_sold'),
                DB::raw('SUM(order_items.line_total_cents) as sales_cents'),
            ]);
        $flavorSales = DB::table('order_item_flavors')
            ->join('order_items', 'order_items.id', '=', 'order_item_flavors.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.id', $orderIds)->whereIn('orders.status', ['paid', 'partially_refunded', 'refunded'])
            ->groupBy('order_item_flavors.flavor_name')
            ->orderByDesc(DB::raw('SUM(order_item_flavors.quantity)'))
            ->limit(20)
            ->get([
                'order_item_flavors.flavor_name',
                DB::raw('SUM(order_item_flavors.quantity) as quantity_sold'),
            ]);
        $byCategory = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('orders.id', $orderIds)->whereIn('orders.status', ['paid', 'partially_refunded', 'refunded'])
            ->groupBy('categories.name')
            ->orderByDesc(DB::raw('SUM(order_items.line_total_cents)'))
            ->get([
                DB::raw("COALESCE(categories.name, 'Uncategorized') as category"),
                DB::raw('SUM(order_items.line_total_cents) as sales_cents'),
            ]);
        $byCashier = DB::table('orders')->join('users', 'users.id', '=', 'orders.cashier_id')
            ->whereIn('orders.id', $orderIds)->whereIn('orders.status', ['paid', 'partially_refunded', 'refunded'])
            ->groupBy('users.username')->orderByDesc(DB::raw('SUM(orders.total_cents - orders.delivery_fee_cents)'))
            ->get(['users.username', DB::raw('SUM(orders.total_cents - orders.delivery_fee_cents) as sales_cents')]);
        $byType = DB::table('orders')->whereIn('id', $orderIds)->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->groupBy('type')->orderByDesc(DB::raw('SUM(total_cents - delivery_fee_cents)'))
            ->get(['type', DB::raw('SUM(total_cents - delivery_fee_cents) as sales_cents')]);
        $byHour = DB::table('orders')->whereIn('id', $orderIds)->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->selectRaw("strftime('%H', created_at) as hour, SUM(total_cents - delivery_fee_cents) as sales_cents")
            ->groupBy('hour')->orderBy('hour')->get();
        $byDay = DB::table('orders')->whereIn('id', $orderIds)->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->selectRaw('date(created_at) as day, SUM(total_cents - delivery_fee_cents) as sales_cents')
            ->groupBy('day')->orderBy('day')->get();

        return response()->json([
            'from' => $from,
            'to' => $to,
            'transactions' => $transactions,
            'gross_sales_cents' => $gross,
            'discounts_cents' => $discounts,
            'promotion_savings_cents' => $promotionSavings,
            'delivery_fees_cents' => $deliveryFees,
            'promotion_usage' => $promotionUsage,
            'voids_cents' => $voided,
            'refunds_cents' => $refunds,
            'net_sales_cents' => $net,
            'completed_transactions' => $completedCount,
            'average_transaction_cents' => $completedCount ? (int) round($net / $completedCount) : 0,
            'products' => $productSales,
            'flavors' => $flavorSales,
            'categories' => $byCategory,
            'cashiers' => $byCashier,
            'order_types' => $byType,
            'sales_by_hour' => $byHour,
            'sales_by_day' => $byDay,
        ]);
    }

    public function addExpense(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:255'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
            'spent_at' => ['nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_with' => ['nullable', Rule::in(['cash', 'ewallet', 'card'])],
        ]);
        $id = DB::table('expenses')->insertGetId([
            'user_id' => $request->user()->id,
            'category' => trim($data['category']),
            'description' => trim($data['description']),
            'amount_cents' => $data['amount_cents'],
            'spent_at' => ($data['spent_at'] ?? now()->toDateString()).' '.now()->format('H:i:s'),
            'reference' => $data['reference'] ?? null,
            'paid_with' => $data['paid_with'] ?? 'cash',
            'voided' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($request, 'expense.created', 'expense', $id, [
            'amount_cents' => $data['amount_cents'],
            'category' => $data['category'],
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function updateExpense(Request $request, int $expense): JsonResponse
    {
        $existing = DB::table('expenses')->where('id', $expense)->first();
        abort_unless($existing && ! $existing->voided, 404);
        $data = $request->validate([
            'category' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:255'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
            'spent_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_with' => ['required', Rule::in(['cash', 'ewallet', 'card'])],
        ]);
        DB::table('expenses')->where('id', $expense)->update([
            'category' => trim($data['category']),
            'description' => trim($data['description']),
            'amount_cents' => $data['amount_cents'],
            'spent_at' => $data['spent_at'],
            'reference' => $data['reference'] ?? null,
            'paid_with' => $data['paid_with'],
            'updated_by' => $request->user()->id,
            'updated_at' => now(),
        ]);
        $this->audit($request, 'expense.updated', 'expense', $expense);

        return response()->json(['id' => $expense]);
    }

    public function voidExpense(Request $request, int $expense): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $updated = DB::table('expenses')->where('id', $expense)->where('voided', false)->update([
            'voided' => true,
            'updated_by' => $request->user()->id,
            'updated_at' => now(),
        ]);
        abort_unless($updated, 404);
        $this->audit($request, 'expense.voided', 'expense', $expense, ['reason' => $data['reason']]);

        return response()->json(['id' => $expense, 'voided' => true]);
    }

    public function requestRefund(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', Rule::in(['cash', 'ewallet', 'card'])],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $id = DB::transaction(function () use ($request, $order, $data): int {
            $sale = DB::table('orders')->where('id', $order)->lockForUpdate()->first();
            if (! $sale || ! in_array($sale->status, ['paid', 'partially_refunded'], true)) {
                throw ValidationException::withMessages(['order' => 'Only paid, not-fully-refunded orders can be refunded.']);
            }
            if ($request->user()->role === 'cashier' && (int) $sale->cashier_id !== (int) $request->user()->id) {
                abort(403);
            }
            $alreadyRefunded = (int) DB::table('refunds')->where('order_id', $order)->whereIn('status', ['approved', 'completed'])->sum('amount_cents');
            if ($alreadyRefunded + $data['amount_cents'] > $sale->total_cents) {
                throw ValidationException::withMessages(['amount_cents' => 'Total approved refunds cannot exceed the transaction total.']);
            }
            $refundId = DB::table('refunds')->insertGetId([
                'order_id' => $order,
                'requested_by' => $request->user()->id,
                'amount_cents' => $data['amount_cents'],
                'payment_method' => $data['payment_method'],
                'reason' => trim($data['reason']),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($request, 'refund.requested', 'order', $order, ['refund_id' => $refundId]);

            return $refundId;
        });

        return response()->json(['id' => $id], 201);
    }

    public function reviewRefund(Request $request, int $refund): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);
        DB::transaction(function () use ($request, $refund, $data): void {
            $entry = DB::table('refunds')->where('id', $refund)->lockForUpdate()->first();
            if (! $entry || $entry->status !== 'pending') {
                throw ValidationException::withMessages(['refund' => 'This refund has already been reviewed.']);
            }
            if ($data['decision'] === 'approved') {
                $order = DB::table('orders')->where('id', $entry->order_id)->lockForUpdate()->first();
                $paidRefunds = (int) DB::table('refunds')->where('order_id', $entry->order_id)->whereIn('status', ['approved', 'completed'])->sum('amount_cents');
                if ($paidRefunds + $entry->amount_cents > $order->total_cents) {
                    throw ValidationException::withMessages(['refund' => 'The refund total exceeds the remaining paid balance.']);
                }
                $newStatus = $paidRefunds + $entry->amount_cents === $order->total_cents ? 'refunded' : 'partially_refunded';
                DB::table('orders')->where('id', $order->id)->update(['status' => $newStatus, 'updated_at' => now()]);
                if ($entry->payment_method === 'cash') {
                    DB::table('cash_flow_entries')->insert([
                        'user_id' => $entry->requested_by,
                        'type' => 'cash_refund',
                        'amount_cents' => $entry->amount_cents,
                        'description' => 'Refund for '.$order->order_number,
                        'reference' => 'refund:'.$entry->id,
                        'occurred_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            DB::table('refunds')->where('id', $entry->id)->update([
                'status' => $data['decision'] === 'approved' ? 'approved' : 'rejected',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($request, 'refund.'.$data['decision'], 'refund', $entry->id);
        });

        return response()->json(['status' => $data['decision']]);
    }

    public function inventory(): JsonResponse
    {
        return response()->json([
            'items' => DB::table('inventory_items')->orderBy('name')->get(),
            'movements' => DB::table('inventory_movements')
                ->join('inventory_items', 'inventory_items.id', '=', 'inventory_movements.inventory_item_id')
                ->join('users', 'users.id', '=', 'inventory_movements.user_id')
                ->orderByDesc('inventory_movements.id')
                ->limit(100)
                ->get([
                    'inventory_movements.id', 'inventory_items.name as item_name',
                    'inventory_movements.quantity_change', 'inventory_movements.reason',
                    'inventory_movements.created_at', 'users.username',
                ]),
        ]);
    }

    public function createProduct(Request $request): JsonResponse
    {
        $request->merge(['sku' => strtoupper(trim((string) $request->input('sku')))]);
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'sku' => ['required', 'string', 'max:60', 'alpha_dash', 'unique:products,sku'],
            'name' => ['required', 'string', 'max:120'],
            'price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'wing_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'flavor_limit' => ['required', 'integer', 'min:0', 'max:11'],
            'inclusions' => ['sometimes', 'array', 'max:20'],
            'inclusions.*' => ['string', 'max:100'],
            'recipes' => ['sometimes', 'array', 'max:20'],
            'recipes.*.inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'recipes.*.quantity_per_product' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        $this->validateRecipeCategory($data['category_id'], $data['recipes'] ?? []);
        $id = DB::transaction(function () use ($data): int {
            $id = DB::table('products')->insertGetId([
                'category_id' => $data['category_id'],
                'sku' => strtoupper($data['sku']),
                'name' => trim($data['name']),
                'price_cents' => $data['price_cents'],
                'wing_count' => $data['wing_count'] ?? null,
                'flavor_limit' => $data['flavor_limit'],
                'inclusions' => json_encode($data['inclusions'] ?? [], JSON_THROW_ON_ERROR),
                'available' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->syncRecipes($id, $data['recipes'] ?? []);

            return $id;
        });
        $this->audit($request, 'menu.product_created', 'product', $id, ['sku' => strtoupper($data['sku'])]);

        return response()->json(['id' => $id], 201);
    }

    public function updateProduct(Request $request, int $product): JsonResponse
    {
        abort_unless(DB::table('products')->where('id', $product)->exists(), 404);
        $request->merge(['sku' => strtoupper(trim((string) $request->input('sku')))]);
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'sku' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('products', 'sku')->ignore($product)],
            'name' => ['required', 'string', 'max:120'],
            'price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'wing_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'flavor_limit' => ['required', 'integer', 'min:0', 'max:11'],
            'inclusions' => ['sometimes', 'array', 'max:20'],
            'inclusions.*' => ['string', 'max:100'],
            'available' => ['required', 'boolean'],
            'recipes' => ['sometimes', 'array', 'max:20'],
            'recipes.*.inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'recipes.*.quantity_per_product' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        if (($data['recipes'] ?? []) === [] && ! array_key_exists('recipes', $data)) {
            $existingRecipes = DB::table('product_recipes')->where('product_id', $product)->exists();
            if ($existingRecipes && DB::table('categories')->where('id', $data['category_id'])->where('slug', 'unliwings')->exists()) {
                throw ValidationException::withMessages(['recipes' => 'Clear the existing recipe before moving this product to Unliwings.']);
            }
        }
        $this->validateRecipeCategory($data['category_id'], $data['recipes'] ?? []);
        DB::transaction(function () use ($product, $data): void {
            DB::table('products')->where('id', $product)->update([
                'category_id' => $data['category_id'],
                'sku' => strtoupper($data['sku']),
                'name' => trim($data['name']),
                'price_cents' => $data['price_cents'],
                'wing_count' => $data['wing_count'] ?? null,
                'flavor_limit' => $data['flavor_limit'],
                'inclusions' => json_encode($data['inclusions'] ?? [], JSON_THROW_ON_ERROR),
                'available' => $data['available'],
                'updated_at' => now(),
            ]);
            if (array_key_exists('recipes', $data)) {
                $this->syncRecipes($product, $data['recipes']);
            }
        });
        $this->audit($request, 'menu.product_updated', 'product', $product, [
            'sku' => strtoupper($data['sku']),
            'available' => $data['available'],
        ]);

        return response()->json(['id' => $product]);
    }

    private function syncRecipes(int $product, array $recipes): void
    {
        $mapped = [];
        foreach ($recipes as $recipe) {
            $itemId = (int) $recipe['inventory_item_id'];
            if (isset($mapped[$itemId])) {
                throw ValidationException::withMessages(['recipes' => 'Each inventory item may only appear once in a recipe.']);
            }

            $mapped[$itemId] = (int) $recipe['quantity_per_product'];
        }
        DB::table('product_recipes')->where('product_id', $product)->delete();
        foreach ($mapped as $itemId => $quantity) {
            DB::table('product_recipes')->insert([
                'product_id' => $product,
                'inventory_item_id' => $itemId,
                'quantity_per_product' => $quantity,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function validateRecipeCategory(int $categoryId, array $recipes): void
    {
        if ($recipes !== [] && DB::table('categories')->where('id', $categoryId)->where('slug', 'unliwings')->exists()) {
            throw ValidationException::withMessages([
                'recipes' => 'Unliwings stock is deducted from confirmed rounds; recipes are only supported for regular menu products.',
            ]);
        }
    }

    public function adjustInventory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'direction' => ['required', Rule::in(['add', 'remove'])],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $movementId = DB::transaction(function () use ($request, $data): int {
            $item = DB::table('inventory_items')->where('id', $data['inventory_item_id'])->lockForUpdate()->first();
            if (! $item) {
                throw ValidationException::withMessages(['inventory_item_id' => 'The inventory item no longer exists.']);
            }
            $quantityChange = $data['direction'] === 'add' ? $data['quantity'] : -$data['quantity'];
            if ($item->quantity + $quantityChange < 0) {
                throw ValidationException::withMessages(['quantity' => 'The adjustment cannot reduce stock below zero.']);
            }
            DB::table('inventory_items')->where('id', $item->id)->update([
                'quantity' => $item->quantity + $quantityChange,
                'updated_at' => now(),
            ]);
            $movementId = DB::table('inventory_movements')->insertGetId([
                'inventory_item_id' => $item->id,
                'user_id' => $request->user()->id,
                'movement_key' => 'adjustment-'.Str::uuid(),
                'quantity_change' => $quantityChange,
                'reason' => trim($data['reason']),
                'reference' => 'manual adjustment',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($request, 'inventory.adjusted', 'inventory_item', $item->id, [
                'quantity_change' => $quantityChange,
                'reason' => $data['reason'],
            ]);

            return $movementId;
        });

        return response()->json(['movement_id' => $movementId], 201);
    }

    public function requestVoid(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $requestId = DB::transaction(function () use ($request, $order, $data): int {
            $sale = DB::table('orders')->where('id', $order)->lockForUpdate()->first();
            if (! $sale || $sale->status !== 'paid') {
                throw ValidationException::withMessages(['order' => 'Only paid orders can be submitted for void review.']);
            }
            $pending = DB::table('void_requests')->where('order_id', $order)->where('status', 'pending')->exists();
            if ($pending) {
                throw ValidationException::withMessages(['order' => 'This order already has a pending void request.']);
            }
            $id = DB::table('void_requests')->insertGetId([
                'order_id' => $order,
                'requested_by' => $request->user()->id,
                'reason' => trim($data['reason']),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($request, 'void.requested', 'order', $order, ['void_request_id' => $id]);

            return $id;
        });

        return response()->json(['id' => $requestId], 201);
    }

    public function voidRequests(): JsonResponse
    {
        return response()->json([
            'requests' => DB::table('void_requests')
                ->join('orders', 'orders.id', '=', 'void_requests.order_id')
                ->join('users as requester', 'requester.id', '=', 'void_requests.requested_by')
                ->leftJoin('users as reviewer', 'reviewer.id', '=', 'void_requests.reviewed_by')
                ->orderByRaw("CASE WHEN void_requests.status = 'pending' THEN 0 ELSE 1 END")
                ->orderByDesc('void_requests.created_at')
                ->limit(200)
                ->get([
                    'void_requests.id', 'void_requests.order_id', 'void_requests.reason',
                    'void_requests.status', 'void_requests.created_at', 'void_requests.reviewed_at',
                    'orders.order_number', 'orders.total_cents', 'requester.username as requested_by',
                    'reviewer.username as reviewed_by',
                ]),
        ]);
    }

    public function recentOrders(Request $request): JsonResponse
    {
        $orders = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.cashier_id')
            ->when($request->user()->role !== 'superadmin', fn ($query) => $query->where('orders.cashier_id', $request->user()->id))
            ->orderByDesc('orders.created_at')
            ->limit(100)
            ->get([
                'orders.id', 'orders.order_number', 'orders.type', 'orders.status',
                'orders.total_cents', 'orders.delivery_fee_cents', 'orders.created_at', 'users.username as cashier',
            ]);

        return response()->json(['orders' => $orders]);
    }

    public function reviewVoid(Request $request, int $voidRequest): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);
        DB::transaction(function () use ($request, $voidRequest, $data): void {
            $void = DB::table('void_requests')->where('id', $voidRequest)->lockForUpdate()->first();
            if (! $void || $void->status !== 'pending') {
                throw ValidationException::withMessages(['void_request' => 'This void request has already been reviewed.']);
            }
            $order = DB::table('orders')->where('id', $void->order_id)->lockForUpdate()->first();
            if (! $order || $order->status !== 'paid') {
                throw ValidationException::withMessages(['order' => 'The paid order is not available for voiding.']);
            }
            if ($data['decision'] === 'approved') {
                $movements = DB::table('inventory_movements')
                    ->where('reference', 'order:'.$order->id)
                    ->when($order->unli_session_id, function ($query) use ($order): void {
                        $roundIds = DB::table('unli_rounds')->where('unli_session_id', $order->unli_session_id)->select('id');
                        $query->orWhereIn('reference', $roundIds->get()->map(fn ($round): string => 'round:'.$round->id)->all());
                    })
                    ->get();
                foreach ($movements as $movement) {
                    $reversal = -$movement->quantity_change;
                    if ($reversal === 0) {
                        continue;
                    }
                    DB::table('inventory_items')->where('id', $movement->inventory_item_id)->increment('quantity', $reversal, ['updated_at' => now()]);
                    DB::table('inventory_movements')->insert([
                        'inventory_item_id' => $movement->inventory_item_id,
                        'user_id' => $request->user()->id,
                        'movement_key' => 'void-'.$void->id.'-'.$movement->id,
                        'quantity_change' => $reversal,
                        'reason' => 'Approved order void',
                        'reference' => 'void:'.$void->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                DB::table('orders')->where('id', $order->id)->update(['status' => 'voided', 'updated_at' => now()]);
                if ($order->unli_session_id) {
                    $roundIds = DB::table('unli_rounds')->where('unli_session_id', $order->unli_session_id)->pluck('id');
                    DB::table('kitchen_tickets')->whereIn('unli_round_id', $roundIds)->update(['archived' => true, 'updated_at' => now()]);
                } else {
                    DB::table('kitchen_tickets')->where('order_id', $order->id)->update(['archived' => true, 'updated_at' => now()]);
                }
                if ($order->unli_session_id) {
                    DB::table('unli_sessions')->where('id', $order->unli_session_id)->update([
                        'status' => 'ongoing',
                        'closed_at' => null,
                        'updated_at' => now(),
                    ]);
                }
            }
            DB::table('void_requests')->where('id', $void->id)->update([
                'status' => $data['decision'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($request, 'void.'.$data['decision'], 'order', $order->id, ['void_request_id' => $void->id]);
        });

        return response()->json(['status' => $data['decision']]);
    }

    public function auditLogs(): JsonResponse
    {
        return response()->json([
            'logs' => DB::table('audit_logs')
                ->join('users', 'users.id', '=', 'audit_logs.user_id')
                ->orderByDesc('audit_logs.id')
                ->limit(200)
                ->get([
                    'audit_logs.id', 'audit_logs.action', 'audit_logs.target_type',
                    'audit_logs.target_id', 'audit_logs.details', 'audit_logs.created_at',
                    'users.username',
                ]),
        ]);
    }

    public function accounts(): JsonResponse
    {
        return response()->json([
            'accounts' => DB::table('users')
                ->orderBy('name')
                ->get(['id', 'name', 'username', 'role', 'active', 'created_at']),
        ]);
    }

    public function createAccount(Request $request): JsonResponse
    {
        $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:60', 'alpha_dash', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'role' => ['required', Rule::in(['superadmin', 'cashier', 'kitchen'])],
        ]);
        $id = DB::table('users')->insertGetId([
            'name' => trim($data['name']),
            'username' => strtolower($data['username']),
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($request, 'account.created', 'user', $id, ['role' => $data['role']]);

        return response()->json(['id' => $id], 201);
    }

    public function updateAccount(Request $request, int $user): JsonResponse
    {
        abort_unless(DB::table('users')->where('id', $user)->exists(), 404);
        $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:60', 'alpha_dash', Rule::unique('users', 'username')->ignore($user)],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'role' => ['required', Rule::in(['superadmin', 'cashier', 'kitchen'])],
            'active' => ['required', 'boolean'],
        ]);

        if ((int) $request->user()->id === $user && ! $data['active']) {
            throw ValidationException::withMessages(['active' => 'You cannot deactivate your own account.']);
        }
        if ((int) $request->user()->id === $user && $data['role'] !== 'superadmin') {
            throw ValidationException::withMessages(['role' => 'You cannot remove your own superadmin access.']);
        }
        $updates = [
            'name' => trim($data['name']),
            'username' => strtolower($data['username']),
            'role' => $data['role'],
            'active' => $data['active'],
            'updated_at' => now(),
        ];
        if (! empty($data['password'])) {
            $updates['password'] = Hash::make($data['password']);
        }
        DB::table('users')->where('id', $user)->update($updates);
        $this->audit($request, 'account.updated', 'user', $user, [
            'role' => $data['role'],
            'active' => $data['active'],
            'password_changed' => ! empty($data['password']),
        ]);

        return response()->json(['id' => $user]);
    }

    private function audit(Request $request, string $action, string $targetType, ?int $targetId, array $details = []): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
