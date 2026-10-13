<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function index()
    {
        return auth()->check() ? redirect()->route('pos.app') : view('pos.login');
    }

    public function app()
    {
        return view('pos.app');
    }

    public function login(Request $request)
    {
        $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);
        $credentials['username'] = strtolower($credentials['username']);

        if (! Auth::attempt($credentials + ['active' => true], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['username' => 'The username or password is incorrect, or the account is inactive.']);
        }

        $request->session()->regenerate();

        return redirect()->route('pos.app');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function bootstrap(): JsonResponse
    {
        $products = DB::table('products')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.available', true)
            ->where('categories.active', true)
            ->orderBy('products.id')
            ->get([
                'products.id', 'products.category_id', 'products.sku', 'products.name', 'products.price_cents',
                'products.wing_count', 'products.flavor_limit', 'products.inclusions', 'products.available',
                'categories.name as category', 'categories.slug as category_slug',
            ])
            ->map(function ($product): object {
                $product->inclusions = $product->inclusions ? json_decode($product->inclusions, true) : [];

                return $product;
            });

        $tables = DB::table('dining_tables')
            ->where('active', true)
            ->orderBy('id')
            ->get()
            ->map(function ($table): object {
                $table->session = DB::table('unli_sessions')
                    ->where('dining_table_id', $table->id)
                    ->where('status', 'ongoing')
                    ->latest('id')
                    ->first();

                return $table;
            });

        $sessions = DB::table('unli_sessions')
            ->join('dining_tables', 'dining_tables.id', '=', 'unli_sessions.dining_table_id')
            ->where('unli_sessions.status', 'ongoing')
            ->orderBy('dining_tables.id')
            ->get([
                'unli_sessions.*',
                'dining_tables.label as table_label',
            ])
            ->map(function ($session): object {
                $session->inclusions = json_decode($session->inclusions, true) ?? [];
                $session->rounds = $this->roundsForSession($session->id);

                return $session;
            });

        return response()->json([
            'user' => auth()->user()->only('id', 'name', 'username', 'role'),
            'categories' => DB::table('categories')->where('active', true)->where('slug', '!=', 'unliwings')->orderBy('id')->get(),
            'products' => $products,
            'flavors' => DB::table('flavors')->where('active', true)->orderBy('id')->get(),
            'tables' => $tables,
            'sessions' => $sessions,
            'promotions' => DB::table('promotions')->where('active', true)->orderBy('name')->get([
                'id', 'name', 'description', 'companions_required', 'free_customers_per_group',
            ]),
            'receipt' => $this->receiptSettings(),
            'inventory' => auth()->user()->role === 'superadmin'
                ? DB::table('inventory_items')->orderBy('name')->get()
                : [],
        ]);
    }

    public function addTable(Request $request): JsonResponse
    {
        $request->merge(['label' => trim((string) $request->input('label'))]);
        $validated = $request->validate(['label' => ['required', 'string', 'max:40', 'unique:dining_tables,label']]);
        $id = DB::table('dining_tables')->insertGetId([
            'label' => $validated['label'],
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'label' => $validated['label']], 201);
    }

    public function checkout(Request $request): JsonResponse
    {
        $request->merge(['discount_type' => $request->input('discount_type', 'none')]);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'type' => ['required', 'in:dine_in,takeaway,delivery'],
            'payment_method' => ['required', 'in:cash,gcash,maribank,maya,others'],
            'amount_paid_cents' => ['required', 'integer', 'min:0'],
            'delivery_fee_cents' => ['required_if:type,delivery', 'nullable', 'integer', 'min:0', 'max:999999999'],
            'delivery_location' => ['required_if:type,delivery', 'nullable', 'string', 'max:255'],
            'discount_type' => ['required', 'in:none,pwd,senior,other'],
            'discount_name' => ['nullable', 'required_if:discount_type,other', 'string', 'max:60'],
            'discount_rate' => ['nullable', 'required_if:discount_type,other', 'numeric', 'min:0', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.flavors' => ['sometimes', 'array'],
            'items.*.flavors.*.flavor_id' => ['required', 'integer', 'exists:flavors,id'],
            'items.*.flavors.*.quantity' => ['required', 'integer', 'min:1'],
        ]);
        if (($data['type'] ?? null) !== 'delivery' && ($data['delivery_fee_cents'] ?? 0) > 0) {
            throw ValidationException::withMessages(['delivery_fee_cents' => 'A delivery fee can only be added to a delivery order.']);
        }

        if ($existing = DB::table('orders')->where('idempotency_key', $data['idempotency_key'])->first()) {
            return $this->receiptResponse($existing->id);
        }

        $products = [];
        $totalWings = 0;
        $subtotal = 0;
        foreach ($data['items'] as $index => $item) {
            $product = DB::table('products')
                ->join('categories', 'categories.id', '=', 'products.category_id')
                ->where('products.id', $item['product_id'])
                ->where('products.available', true)
                ->first(['products.*', 'categories.slug as category_slug']);
            if (! $product) {
                throw ValidationException::withMessages(["items.$index.product_id" => 'This product is unavailable.']);
            }
            if ($product->category_slug === 'unliwings') {
                throw ValidationException::withMessages(["items.$index.product_id" => 'Unliwings is dine-in only. Use the Unliwings table workflow.']);
            }

            $allocations = $item['flavors'] ?? [];
            if ($product->wing_count) {
                $flavorIds = array_unique(array_column($allocations, 'flavor_id'));
                $flavorOrderCountMismatch = count(array_filter($allocations, fn (array $allocation): bool => $allocation['quantity'] !== $item['quantity'])) > 0;
                if (! $allocations || count($flavorIds) !== count($allocations) || count($flavorIds) > $product->flavor_limit || $flavorOrderCountMismatch) {
                    throw ValidationException::withMessages(["items.$index.flavors" => 'Choose up to '.$product->flavor_limit.' flavors; each flavor selection must match the number of orders.']);
                }
                $totalWings += $product->wing_count * $item['quantity'];
            } elseif ($allocations) {
                throw ValidationException::withMessages(["items.$index.flavors" => 'This product does not take wing flavors.']);
            }

            foreach ($allocations as $allocation) {
                if (! DB::table('flavors')->where('id', $allocation['flavor_id'])->where('active', true)->exists()) {
                    throw ValidationException::withMessages(["items.$index.flavors" => 'One of the selected flavors is unavailable.']);
                }
            }

            $products[] = [$product, $item['quantity'], $allocations];
            $subtotal += $product->price_cents * $item['quantity'];
        }

        [$discountName, $discountRateBasisPoints] = $this->discountValues($data);
        $discountCents = (int) round($subtotal * $discountRateBasisPoints / 10000);
        $deliveryFeeCents = $data['type'] === 'delivery' ? (int) ($data['delivery_fee_cents'] ?? 0) : 0;
        $total = $subtotal - $discountCents + $deliveryFeeCents;
        if ($data['amount_paid_cents'] < $total) {
            throw ValidationException::withMessages(['amount_paid_cents' => 'The amount paid cannot be less than the order total.']);
        }

        $orderId = DB::transaction(function () use ($data, $products, $totalWings, $subtotal, $total, $discountName, $discountRateBasisPoints, $discountCents, $deliveryFeeCents): int {
            $orderId = DB::table('orders')->insertGetId([
                'order_number' => 'EW-'.now()->format('ymdHis').'-'.Str::upper(Str::random(4)),
                'idempotency_key' => $data['idempotency_key'],
                'type' => $data['type'],
                'status' => 'paid',
                'cashier_id' => auth()->id(),
                'subtotal_cents' => $subtotal,
                'discount_cents' => $discountCents,
                'discount_name' => $discountName,
                'discount_rate_basis_points' => $discountRateBasisPoints,
                'delivery_fee_cents' => $deliveryFeeCents,
                'delivery_location' => $data['type'] === 'delivery' ? trim($data['delivery_location']) : null,
                'total_cents' => $total,
                'payment_method' => $data['payment_method'],
                'amount_paid_cents' => $data['amount_paid_cents'],
                'change_cents' => $data['amount_paid_cents'] - $total,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($products as [$product, $quantity, $allocations]) {
                $itemId = DB::table('order_items')->insertGetId([
                    'order_id' => $orderId,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price_cents' => $product->price_cents,
                    'quantity' => $quantity,
                    'line_total_cents' => $product->price_cents * $quantity,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                foreach ($allocations as $allocation) {
                    $flavor = DB::table('flavors')->find($allocation['flavor_id']);
                    DB::table('order_item_flavors')->insert([
                        'order_item_id' => $itemId,
                        'flavor_id' => $flavor->id,
                        'flavor_name' => $flavor->name,
                        'quantity' => $allocation['quantity'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $this->deductWings($totalWings, 'order-'.$orderId, 'Regular order', 'order:'.$orderId);
            $this->deductRecipes(
                array_map(fn (array $line): array => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $products),
                $orderId
            );
            DB::table('kitchen_tickets')->insert([
                'order_id' => $orderId,
                'status' => 'preparing',
                'archived' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit('order.created', 'order', $orderId, ['total_cents' => $total, 'type' => $data['type'], 'discount_cents' => $discountCents, 'delivery_fee_cents' => $deliveryFeeCents]);

            return $orderId;
        });

        return $this->receiptResponse($orderId, 201);
    }

    public function startSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'table_id' => ['required', 'integer', 'exists:dining_tables,id'],
            'package_id' => ['required', 'integer', 'exists:products,id'],
            'paid_sets' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $sessionId = DB::transaction(function () use ($data): int {
            $table = DB::table('dining_tables')->where('id', $data['table_id'])->where('active', true)->lockForUpdate()->first();
            $existing = DB::table('unli_sessions')->where('dining_table_id', $data['table_id'])->where('status', 'ongoing')->exists();
            $package = DB::table('products')
                ->join('categories', 'categories.id', '=', 'products.category_id')
                ->where('products.id', $data['package_id'])
                ->where('categories.slug', 'unliwings')
                ->where('products.available', true)
                ->first(['products.*']);
            if (! $table || $existing || ! $package) {
                throw ValidationException::withMessages(['table_id' => 'The table is unavailable or already has an active session.']);
            }

            return DB::table('unli_sessions')->insertGetId([
                'dining_table_id' => $table->id,
                'package_product_id' => $package->id,
                'package_snapshot' => $package->name,
                'package_price_cents' => $package->price_cents,
                'inclusions' => $package->inclusions,
                'paid_sets' => $data['paid_sets'],
                'status' => 'ongoing',
                'cashier_id' => auth()->id(),
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['session' => $this->sessionDetails($sessionId)], 201);
    }

    public function confirmRound(Request $request, int $session): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'flavors' => ['sometimes', 'array', 'max:11'],
            'flavors.*.flavor_id' => ['required', 'integer', 'exists:flavors,id'],
            'flavors.*.quantity' => ['required', 'integer', 'min:1', 'max:500'],
            'included_items' => ['sometimes', 'array', 'max:10'],
            'included_items.*' => ['required', 'string', 'max:100', 'distinct'],
            'included_quantities' => ['sometimes', 'array', 'max:10'],
            'included_quantities.*' => ['required', 'integer', 'min:1', 'max:99'],
            'instructions' => ['nullable', 'string', 'max:500'],
        ]);
        $flavors = $data['flavors'] ?? [];
        $includedItems = $data['included_items'] ?? [];
        $includedQuantities = $data['included_quantities'] ?? [];
        if (count(array_unique(array_column($flavors, 'flavor_id'))) !== count($flavors)) {
            throw ValidationException::withMessages(['flavors' => 'Select each flavor once and enter its combined quantity.']);
        }
        foreach ($flavors as $flavor) {
            if ($flavor['quantity'] < 4 || $flavor['quantity'] % 4 !== 0) {
                throw ValidationException::withMessages(['flavors' => 'Each flavor quantity must be a multiple of 4 wings (one order).']);
            }
        }
        $total = array_sum(array_column($flavors, 'quantity'));
        $roundId = DB::transaction(function () use ($session, $data, $flavors, $includedItems, $includedQuantities, $total): int {
            if ($existing = DB::table('unli_rounds')->where('idempotency_key', $data['idempotency_key'])->first()) {
                if ($existing->unli_session_id !== $session) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key belongs to a different round.']);
                }

                return $existing->id;
            }
            $activeSession = DB::table('unli_sessions')->where('id', $session)->where('status', 'ongoing')->lockForUpdate()->first();
            if (! $activeSession) {
                throw ValidationException::withMessages(['session' => 'This table session is no longer active.']);
            }
            $availableItems = $this->includedSideOptions($activeSession);
            if (array_diff($includedItems, $availableItems) !== []) {
                throw ValidationException::withMessages(['included_items' => 'One or more requested items are not included in this Unliwings set.']);
            }
            if (array_diff(array_keys($includedQuantities), $includedItems) !== []) {
                throw ValidationException::withMessages(['included_quantities' => 'Quantities can only be set for selected included items.']);
            }
            foreach ($includedItems as $item) {
                $includedQuantities[$item] = $includedQuantities[$item] ?? 1;
            }

            $maxRound = (int) (DB::table('unli_rounds')->where('unli_session_id', $session)->max('round_number') ?? 0);
            if ($maxRound === 0) {
                $includedRice = collect($availableItems)->first(static fn (string $item): bool => preg_match('/\brice\b/i', $item) === 1);
                if ($includedRice !== null) {
                    if (! in_array($includedRice, $includedItems, true)) {
                        $includedItems[] = $includedRice;
                    }
                    $includedQuantities[$includedRice] = $activeSession->paid_sets;
                }
            }
            if ($flavors === [] && $includedItems === []) {
                throw ValidationException::withMessages(['flavors' => 'Select at least one flavor or included item to request from the kitchen.']);
            }
            $roundId = DB::table('unli_rounds')->insertGetId([
                'unli_session_id' => $session,
                'round_number' => $maxRound + 1,
                'idempotency_key' => $data['idempotency_key'],
                'total_wings' => $total,
                'instructions' => $data['instructions'] ?? null,
                'included_items' => json_encode($includedItems, JSON_THROW_ON_ERROR),
                'included_quantities' => json_encode($includedQuantities, JSON_THROW_ON_ERROR),
                'submitted_by' => auth()->id(),
                'confirmed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($flavors as $allocation) {
                $flavor = DB::table('flavors')->where('id', $allocation['flavor_id'])->where('active', true)->first();
                if (! $flavor) {
                    throw ValidationException::withMessages(['flavors' => 'One of the selected flavors is unavailable.']);
                }
                DB::table('unli_round_flavors')->insert([
                    'unli_round_id' => $roundId,
                    'flavor_id' => $flavor->id,
                    'flavor_name' => $flavor->name,
                    'quantity' => $allocation['quantity'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->deductWings($total, 'round-'.$roundId, 'Unliwings round', 'round:'.$roundId);
            DB::table('kitchen_tickets')->insert([
                'unli_round_id' => $roundId,
                'status' => 'preparing',
                'archived' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit('unli.round_confirmed', 'unli_round', $roundId, [
                'total_wings' => $total,
                'included_items' => $includedItems,
                'included_quantities' => $includedQuantities,
            ]);

            return $roundId;
        });

        return response()->json(['round' => $this->roundDetails($roundId)]);
    }

    public function billout(Request $request, int $session): JsonResponse
    {
        $request->merge(['discount_type' => $request->input('discount_type', 'none')]);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'payment_method' => ['required', 'in:cash,gcash,maribank,maya,others'],
            'amount_paid_cents' => ['required', 'integer', 'min:0'],
            'discount_type' => ['required', 'in:none,pwd,senior,other'],
            'discount_name' => ['nullable', 'required_if:discount_type,other', 'string', 'max:60'],
            'discount_rate' => ['nullable', 'required_if:discount_type,other', 'numeric', 'min:0', 'max:100'],
            'promotion_id' => ['nullable', 'integer', 'exists:promotions,id'],
            'promotion_free_customers' => ['nullable', 'integer', 'min:0', 'max:50'],
            'extras' => ['sometimes', 'array'],
            'extras.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'extras.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        if ($existing = DB::table('orders')->where('idempotency_key', $data['idempotency_key'])->first()) {
            return $this->receiptResponse($existing->id);
        }

        [$discountName, $discountRateBasisPoints] = $this->discountValues($data);
        $orderId = DB::transaction(function () use ($session, $data, $discountName, $discountRateBasisPoints): int {
            $active = DB::table('unli_sessions')->where('id', $session)->where('status', 'ongoing')->lockForUpdate()->first();
            if (! $active) {
                throw ValidationException::withMessages(['session' => 'This table session is already closed.']);
            }
            $table = DB::table('dining_tables')->find($active->dining_table_id);
            $products = [];
            $subtotal = $active->package_price_cents * $active->paid_sets;
            foreach ($data['extras'] ?? [] as $index => $extra) {
                $product = DB::table('products')
                    ->join('categories', 'categories.id', '=', 'products.category_id')
                    ->where('products.id', $extra['product_id'])
                    ->where('categories.slug', 'extras')
                    ->where('products.available', true)
                    ->first(['products.*']);
                if (! $product) {
                    throw ValidationException::withMessages(["extras.$index.product_id" => 'This extra is unavailable.']);
                }
                $products[] = [$product, $extra['quantity']];
                $subtotal += $product->price_cents * $extra['quantity'];
            }
            $promotion = null;
            $promotionFreeCustomers = (int) ($data['promotion_free_customers'] ?? 0);
            $promotionSavingsCents = 0;
            if (! empty($data['promotion_id'])) {
                $promotion = DB::table('promotions')->where('id', $data['promotion_id'])->where('active', true)->lockForUpdate()->first();
                if (! $promotion) {
                    throw ValidationException::withMessages(['promotion_id' => 'This promotion is no longer available.']);
                }
                $eligibleGroups = intdiv(
                    $active->paid_sets,
                    $promotion->companions_required + $promotion->free_customers_per_group
                );
                $maximumFreeCustomers = $eligibleGroups * $promotion->free_customers_per_group;
                if ($promotionFreeCustomers < 1 || $promotionFreeCustomers > $maximumFreeCustomers) {
                    throw ValidationException::withMessages(['promotion_free_customers' => 'The customer count does not meet this promotion requirement.']);
                }
                $promotionSavingsCents = $active->package_price_cents * $promotionFreeCustomers;
            } elseif ($promotionFreeCustomers !== 0) {
                throw ValidationException::withMessages(['promotion_free_customers' => 'Select an active promotion before entering free customers.']);
            }
            $discountableSubtotal = $subtotal - $promotionSavingsCents;
            $discountCents = (int) round($discountableSubtotal * $discountRateBasisPoints / 10000);
            $total = $discountableSubtotal - $discountCents;
            if ($data['amount_paid_cents'] < $total) {
                throw ValidationException::withMessages(['amount_paid_cents' => 'The amount paid cannot be less than the bill total.']);
            }

            $orderId = DB::table('orders')->insertGetId([
                'order_number' => 'EW-'.now()->format('ymdHis').'-'.Str::upper(Str::random(4)),
                'idempotency_key' => $data['idempotency_key'],
                'type' => 'unliwings',
                'status' => 'paid',
                'cashier_id' => auth()->id(),
                'dining_table_id' => $active->dining_table_id,
                'unli_session_id' => $active->id,
                'package_snapshot' => $active->package_snapshot,
                'paid_sets' => $active->paid_sets,
                'subtotal_cents' => $subtotal,
                'discount_cents' => $discountCents,
                'discount_name' => $discountName,
                'discount_rate_basis_points' => $discountRateBasisPoints,
                'promotion_id' => $promotion?->id,
                'promotion_name' => $promotion?->name,
                'promotion_companions_required' => $promotion?->companions_required,
                'promotion_free_customers_per_group' => $promotion?->free_customers_per_group,
                'promotion_free_customers' => $promotionFreeCustomers,
                'promotion_savings_cents' => $promotionSavingsCents,
                'total_cents' => $total,
                'payment_method' => $data['payment_method'],
                'amount_paid_cents' => $data['amount_paid_cents'],
                'change_cents' => $data['amount_paid_cents'] - $total,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('order_items')->insert([
                'order_id' => $orderId,
                'product_id' => $active->package_product_id,
                'product_name' => $active->package_snapshot,
                'unit_price_cents' => $active->package_price_cents,
                'quantity' => $active->paid_sets,
                'line_total_cents' => $active->package_price_cents * $active->paid_sets,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($products as [$product, $quantity]) {
                DB::table('order_items')->insert([
                    'order_id' => $orderId,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price_cents' => $product->price_cents,
                    'quantity' => $quantity,
                    'line_total_cents' => $product->price_cents * $quantity,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->deductRecipes(
                array_map(fn (array $line): array => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $products),
                $orderId
            );

            DB::table('unli_sessions')->where('id', $active->id)->update([
                'status' => 'closed',
                'closed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit('unli.billout', 'order', $orderId, ['total_cents' => $total, 'discount_cents' => $discountCents, 'session_id' => $active->id]);

            return $orderId;
        });

        return $this->receiptResponse($orderId, 201);
    }

    public function kitchen(): JsonResponse
    {
        $tickets = DB::table('kitchen_tickets')->where('archived', false)->orderBy('created_at')->get();
        $result = $tickets->map(function ($ticket): array {
            if ($ticket->order_id) {
                $order = DB::table('orders')->where('id', $ticket->order_id)->first();
                $table = $order?->dining_table_id ? DB::table('dining_tables')->find($order->dining_table_id) : null;
                $items = DB::table('order_items')->where('order_id', $ticket->order_id)->get()->map(function ($item): object {
                    $item->flavors = DB::table('order_item_flavors')->where('order_item_id', $item->id)->get();

                    return $item;
                });

                return ['id' => $ticket->id, 'status' => $ticket->status, 'created_at' => $ticket->created_at, 'order_number' => $order?->order_number, 'table' => $table?->label, 'type' => $order?->type, 'items' => $items];
            }

            $round = DB::table('unli_rounds')->find($ticket->unli_round_id);
            $session = $round ? DB::table('unli_sessions')->find($round->unli_session_id) : null;
            $table = $session ? DB::table('dining_tables')->find($session->dining_table_id) : null;
            $flavors = DB::table('unli_round_flavors')->where('unli_round_id', $round?->id)->get();
            $includedItems = $round ? $this->roundIncludedItems($round) : [];

            return ['id' => $ticket->id, 'status' => $ticket->status, 'created_at' => $ticket->created_at, 'order_number' => 'Round '.$round?->round_number, 'table' => $table?->label, 'type' => 'unliwings', 'total_wings' => $round?->total_wings, 'instructions' => $round?->instructions, 'included_items' => $includedItems['items'], 'included_quantities' => $includedItems['quantities'], 'items' => $flavors];
        });

        return response()->json(['tickets' => $result]);
    }

    public function updateKitchen(Request $request, int $ticket): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:preparing,cooking,served']]);
        DB::transaction(function () use ($request, $ticket, $data): void {
            $active = DB::table('kitchen_tickets')->where('id', $ticket)->where('archived', false)->lockForUpdate()->first();
            abort_unless($active, 404);
            DB::table('kitchen_tickets')->where('id', $ticket)->update(['status' => $data['status'], 'updated_at' => now()]);
            DB::table('kitchen_status_history')->insert([
                'kitchen_ticket_id' => $ticket,
                'user_id' => $request->user()->id,
                'from_status' => $active->status,
                'to_status' => $data['status'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit('kitchen.status_changed', 'kitchen_ticket', $ticket, [
                'from_status' => $active->status,
                'to_status' => $data['status'],
            ]);
        });

        return response()->json(['id' => $ticket, 'status' => $data['status']]);
    }

    public function archiveKitchen(Request $request, int $ticket): JsonResponse
    {
        DB::transaction(function () use ($ticket): void {
            $active = DB::table('kitchen_tickets')->where('id', $ticket)->where('archived', false)->lockForUpdate()->first();
            abort_unless($active, 404);
            DB::table('kitchen_tickets')->where('id', $ticket)->update(['archived' => true, 'updated_at' => now()]);
            $this->audit('kitchen.ticket_archived', 'kitchen_ticket', $ticket);
        });

        return response()->json(['id' => $ticket, 'archived' => true]);
    }

    public function kitchenHistory(): JsonResponse
    {
        $tickets = DB::table('kitchen_tickets')
            ->where('archived', true)
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        return response()->json([
            'tickets' => $tickets->map(function ($ticket): array {
                if ($ticket->order_id) {
                    $order = DB::table('orders')->where('id', $ticket->order_id)->first();

                    return [
                        'id' => $ticket->id,
                        'status' => $ticket->status,
                        'created_at' => $ticket->created_at,
                        'archived_at' => $ticket->updated_at,
                        'order_number' => $order?->order_number,
                        'type' => $order?->type,
                        'history' => DB::table('kitchen_status_history')->where('kitchen_ticket_id', $ticket->id)->orderBy('created_at')->get(),
                    ];
                }
                $round = DB::table('unli_rounds')->find($ticket->unli_round_id);

                return [
                    'id' => $ticket->id,
                    'status' => $ticket->status,
                    'created_at' => $ticket->created_at,
                    'archived_at' => $ticket->updated_at,
                    'order_number' => 'Round '.$round?->round_number,
                    'type' => 'unliwings',
                    'history' => DB::table('kitchen_status_history')->where('kitchen_ticket_id', $ticket->id)->orderBy('created_at')->get(),
                ];
            }),
        ]);
    }

    public function receipt(int $order): JsonResponse
    {
        abort_unless(DB::table('orders')->where('id', $order)->exists(), 404);

        return $this->receiptResponse($order);
    }

    private function receiptResponse(int $orderId, int $status = 200): JsonResponse
    {
        $order = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.cashier_id')
            ->where('orders.id', $orderId)
            ->first(['orders.*', 'users.name as cashier_name']);
        $order->items = DB::table('order_items')->where('order_id', $orderId)->get()->map(function ($item): object {
            $item->flavors = DB::table('order_item_flavors')->where('order_item_id', $item->id)->get();

            return $item;
        });
        $order->rounds = [];
        if ($order->type === 'unliwings') {
            $session = $order->unli_session_id ? DB::table('unli_sessions')->find($order->unli_session_id) : null;
            $order->rounds = $session ? $this->roundsForSession($session->id) : [];
        }

        $order->table = $order->dining_table_id ? DB::table('dining_tables')->find($order->dining_table_id)?->label : null;

        return response()->json(['receipt' => $order], $status);
    }

    private function discountValues(array $data): array
    {
        $name = match ($data['discount_type']) {
            'pwd' => 'PWD',
            'senior' => 'Senior Citizen',
            'other' => trim($data['discount_name']),
            default => null,
        };
        $rateBasisPoints = match ($data['discount_type']) {
            'pwd', 'senior' => 2000,
            'other' => (int) round((float) $data['discount_rate'] * 100),
            default => 0,
        };

        return [$name, $rateBasisPoints];
    }

    private function receiptSettings(): array
    {
        $settings = DB::table('application_settings')
            ->whereIn('key', ['receipt_header', 'receipt_footer', 'receipt_qr_path'])
            ->pluck('value', 'key');

        return [
            'header' => $settings['receipt_header'] ?? '',
            'footer' => $settings['receipt_footer'] ?? 'Thank you for dining with us!',
            'qr_url' => ! empty($settings['receipt_qr_path']) ? asset($settings['receipt_qr_path']) : null,
        ];
    }

    private function sessionDetails(int $id): array
    {
        $session = DB::table('unli_sessions')
            ->join('dining_tables', 'dining_tables.id', '=', 'unli_sessions.dining_table_id')
            ->where('unli_sessions.id', $id)
            ->first(['unli_sessions.*', 'dining_tables.label as table_label']);
        $session->inclusions = json_decode($session->inclusions, true) ?? [];
        $session->rounds = $this->roundsForSession($id);

        return (array) $session;
    }

    private function roundsForSession(int $id): array
    {
        return DB::table('unli_rounds')->where('unli_session_id', $id)->orderBy('round_number')->get()->map(function ($round): object {
            $round->flavors = DB::table('unli_round_flavors')->where('unli_round_id', $round->id)->get();
            $included = $this->roundIncludedItems($round);
            $round->included_items = $included['items'];
            $round->included_quantities = $included['quantities'];

            return $round;
        })->all();
    }

    private function roundDetails(int $id): object
    {
        $round = DB::table('unli_rounds')->find($id);
        $round->flavors = DB::table('unli_round_flavors')->where('unli_round_id', $id)->get();
        $included = $this->roundIncludedItems($round);
        $round->included_items = $included['items'];
        $round->included_quantities = $included['quantities'];

        return $round;
    }

    private function roundIncludedItems(object $round): array
    {
        $items = json_decode($round->included_items ?? '[]', true) ?? [];
        $quantities = json_decode($round->included_quantities ?? '[]', true) ?? [];
        foreach ($items as $item) {
            $quantities[$item] = $quantities[$item] ?? 1;
        }

        return ['items' => $items, 'quantities' => $quantities];
    }

    private function includedSideOptions(object $session): array
    {
        $inclusions = json_decode($session->inclusions, true) ?? [];

        return array_values(array_filter($inclusions, static fn ($item): bool => is_string($item) && preg_match('/\b(rice|fries|drinks?)\b/i', $item) === 1));
    }

    private function deductWings(int $quantity, string $key, string $reason, string $reference): void
    {
        if ($quantity === 0) {
            return;
        }

        $stock = DB::table('inventory_items')->where('sku', 'WINGS')->lockForUpdate()->first();
        if (! $stock || $stock->quantity < $quantity) {
            throw ValidationException::withMessages(['inventory' => 'Not enough chicken wings in stock for this order.']);
        }

        DB::table('inventory_items')->where('id', $stock->id)->decrement('quantity', $quantity, ['updated_at' => now()]);
        DB::table('inventory_movements')->insert([
            'inventory_item_id' => $stock->id,
            'user_id' => auth()->id(),
            'movement_key' => $key,
            'quantity_change' => -$quantity,
            'reason' => $reason,
            'reference' => $reference,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deductRecipes(array $lines, int $orderId): void
    {
        $required = [];
        foreach ($lines as $line) {
            $recipes = DB::table('product_recipes')
                ->join('inventory_items', 'inventory_items.id', '=', 'product_recipes.inventory_item_id')
                ->where('product_recipes.product_id', $line['product_id'])
                ->where('inventory_items.sku', '!=', 'WINGS')
                ->get(['product_recipes.inventory_item_id', 'product_recipes.quantity_per_product']);
            foreach ($recipes as $recipe) {
                $required[$recipe->inventory_item_id] = ($required[$recipe->inventory_item_id] ?? 0)
                    + $recipe->quantity_per_product * $line['quantity'];
            }
        }
        foreach ($required as $inventoryItemId => $quantity) {
            $item = DB::table('inventory_items')->where('id', $inventoryItemId)->lockForUpdate()->first();
            if (! $item || $item->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'items' => 'Insufficient stock for '.($item->name ?? 'a configured recipe ingredient').'.',
                ]);
            }
            DB::table('inventory_items')->where('id', $item->id)->decrement('quantity', $quantity, ['updated_at' => now()]);
            DB::table('inventory_movements')->insert([
                'inventory_item_id' => $item->id,
                'user_id' => auth()->id(),
                'movement_key' => 'order-'.$orderId.'-recipe-'.$item->id,
                'quantity_change' => -$quantity,
                'reason' => 'Configured recipe usage',
                'reference' => 'order:'.$orderId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function audit(string $action, string $targetType, ?int $targetId, array $details = []): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => auth()->id(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
