<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PosWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_regular_order_tracks_flavor_orders_and_is_idempotent(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $flavors = DB::table('flavors')->pluck('id', 'name');
        $key = '3d42ed38-2782-4ad2-b484-d7b0409a1101';
        $payload = [
            'idempotency_key' => $key,
            'type' => 'dine_in',
            'payment_method' => 'cash',
            'amount_paid_cents' => 34900,
            'items' => [[
                'product_id' => DB::table('products')->where('sku', 'PLATTER-12')->value('id'),
                'quantity' => 1,
                'flavors' => [
                    ['flavor_id' => $flavors['Buffalo'], 'quantity' => 1],
                    ['flavor_id' => $flavors['Cheese'], 'quantity' => 1],
                    ['flavor_id' => $flavors['Ranch'], 'quantity' => 1],
                ],
            ]],
        ];

        $first = $this->actingAs($cashier)->postJson('/posetivacwingspos/api/orders', $payload);
        $first->assertCreated()->assertJsonPath('receipt.total_cents', 34900);
        $this->getJson('/posetivacwingspos/api/receipts/'.$first->json('receipt.id'))
            ->assertOk()
            ->assertJsonPath('receipt.order_number', $first->json('receipt.order_number'));
        $this->assertSame(988, DB::table('inventory_items')->where('sku', 'WINGS')->value('quantity'));

        $retry = $this->postJson('/posetivacwingspos/api/orders', $payload);
        $retry->assertOk()->assertJsonPath('receipt.order_number', $first->json('receipt.order_number'));
        $this->assertSame(988, DB::table('inventory_items')->where('sku', 'WINGS')->value('quantity'));
        $this->assertSame(1, DB::table('inventory_movements')->count());
    }

    public function test_meal_platter_and_bilao_flavor_quantities_are_per_order(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $buffaloId = DB::table('flavors')->where('name', 'Buffalo')->value('id');

        $cases = [
            ['MEAL-4', 4, 16000, '891b7bef-4b90-43a6-8236-5b2d0810e41a'],
            ['PLATTER-12', 12, 34900, 'f7d4a416-558a-48e9-9f1e-53606af403d5'],
            ['BILAO-36', 36, 94900, 'b5ad64ea-6624-4b2c-9b68-07ea6e23bb6c'],
        ];
        foreach ($cases as [$sku, $wingCount, $price, $key]) {
            $this->actingAs($cashier)->postJson('/posetivacwingspos/api/orders', [
                'idempotency_key' => $key,
                'type' => 'takeaway',
                'payment_method' => 'cash',
                'amount_paid_cents' => $price,
                'items' => [[
                    'product_id' => DB::table('products')->where('sku', $sku)->value('id'),
                    'quantity' => 1,
                    'flavors' => [
                        ['flavor_id' => $buffaloId, 'quantity' => 1],
                    ],
                ]],
            ])->assertCreated()
                ->assertJsonPath('receipt.items.0.flavors.0.flavor_name', 'Buffalo')
                ->assertJsonPath('receipt.items.0.flavors.0.quantity', 1);
        }
    }

    public function test_two_wing_meal_orders_keep_separate_flavors_and_accept_gcash(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $flavors = DB::table('flavors')->pluck('id', 'name');
        $productId = DB::table('products')->where('sku', 'MEAL-2')->value('id');

        $response = $this->actingAs($cashier)->postJson('/posetivacwingspos/api/orders', [
            'idempotency_key' => 'c57c5d42-0c89-4b4f-87ee-0027f01bfabc',
            'type' => 'takeaway',
            'payment_method' => 'gcash',
            'amount_paid_cents' => 19800,
            'items' => [
                ['product_id' => $productId, 'quantity' => 1, 'flavors' => [['flavor_id' => $flavors['Buffalo'], 'quantity' => 1]]],
                ['product_id' => $productId, 'quantity' => 1, 'flavors' => [['flavor_id' => $flavors['Ranch'], 'quantity' => 1]]],
            ],
        ])->assertCreated()
            ->assertJsonPath('receipt.payment_method', 'gcash')
            ->assertJsonPath('receipt.items.0.flavors.0.quantity', 1)
            ->assertJsonPath('receipt.items.1.flavors.0.flavor_name', 'Ranch');

        $this->assertSame(996, DB::table('inventory_items')->where('sku', 'WINGS')->value('quantity'));

        $this->postJson('/posetivacwingspos/api/orders', [
            'idempotency_key' => '2cb22750-2ac3-4d76-a19d-badd4b61513a',
            'type' => 'takeaway',
            'payment_method' => 'cash',
            'amount_paid_cents' => 9900,
            'items' => [[
                'product_id' => $productId,
                'quantity' => 1,
                'flavors' => [['flavor_id' => $flavors['Buffalo'], 'quantity' => 2]],
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.flavors']);
    }

    public function test_pwd_and_custom_discounts_are_applied_to_sale_total(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $flavorId = DB::table('flavors')->where('name', 'Buffalo')->value('id');
        $productId = DB::table('products')->where('sku', 'MEAL-2')->value('id');
        $basePayload = [
            'type' => 'takeaway',
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $productId,
                'quantity' => 1,
                'flavors' => [['flavor_id' => $flavorId, 'quantity' => 1]],
            ]],
        ];

        $this->actingAs($cashier)->postJson('/posetivacwingspos/api/orders', $basePayload + [
            'idempotency_key' => '89a79eaa-0b4c-4880-9755-502841fcbcb2',
            'amount_paid_cents' => 8000,
            'discount_type' => 'pwd',
        ])->assertCreated()
            ->assertJsonPath('receipt.subtotal_cents', 9900)
            ->assertJsonPath('receipt.discount_cents', 1980)
            ->assertJsonPath('receipt.discount_name', 'PWD')
            ->assertJsonPath('receipt.discount_rate_basis_points', 2000)
            ->assertJsonPath('receipt.total_cents', 7920)
            ->assertJsonPath('receipt.change_cents', 80);

        $this->postJson('/posetivacwingspos/api/orders', $basePayload + [
            'idempotency_key' => 'cb6f52c7-1a17-42c1-8fb2-88ac5984a1e4',
            'amount_paid_cents' => 8860,
            'discount_type' => 'other',
            'discount_name' => 'Opening promo',
            'discount_rate' => 10.5,
        ])->assertCreated()
            ->assertJsonPath('receipt.discount_name', 'Opening promo')
            ->assertJsonPath('receipt.discount_rate_basis_points', 1050)
            ->assertJsonPath('receipt.discount_cents', 1040)
            ->assertJsonPath('receipt.total_cents', 8860);
    }

    public function test_delivery_fee_is_collected_and_reported_separately_from_sales(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $admin = User::where('role', 'superadmin')->firstOrFail();
        $productId = DB::table('products')->where('sku', 'MEAL-2')->value('id');
        $flavorId = DB::table('flavors')->where('name', 'Buffalo')->value('id');

        $response = $this->actingAs($cashier)->postJson('/posetivacwingspos/api/orders', [
            'idempotency_key' => 'dd667c28-c663-4dbb-88f4-a5187d254129',
            'type' => 'delivery',
            'payment_method' => 'cash',
            'amount_paid_cents' => 9920,
            'delivery_fee_cents' => 2000,
            'delivery_location' => '123 Mabini Street, Barangay San Jose',
            'discount_type' => 'pwd',
            'items' => [[
                'product_id' => $productId,
                'quantity' => 1,
                'flavors' => [['flavor_id' => $flavorId, 'quantity' => 1]],
            ]],
        ])->assertCreated()
            ->assertJsonPath('receipt.subtotal_cents', 9900)
            ->assertJsonPath('receipt.discount_cents', 1980)
            ->assertJsonPath('receipt.delivery_fee_cents', 2000)
            ->assertJsonPath('receipt.delivery_location', '123 Mabini Street, Barangay San Jose')
            ->assertJsonPath('receipt.total_cents', 9920)
            ->assertJsonPath('receipt.change_cents', 0);

        $this->getJson('/posetivacwingspos/api/receipts/'.$response->json('receipt.id'))
            ->assertOk()
            ->assertJsonPath('receipt.delivery_location', '123 Mabini Street, Barangay San Jose');
        $this->get('/posetivacwingspos/app')
            ->assertOk()
            ->assertSee('Delivery fee')
            ->assertSee('Delivery location / address')
            ->assertSee('.receipt-qr{display:block;width:38mm;height:38mm', false)
            ->assertSee('@media(max-width:560px)', false)
            ->assertSee('qrSize=state.receiptSettings?.qr_url?260:0', false)
            ->assertSee('value="delivery"', false);
        $this->actingAs($admin)->getJson('/posetivacwingspos/api/admin/sales-report?type=delivery')
            ->assertOk()
            ->assertJsonPath('gross_sales_cents', 9900)
            ->assertJsonPath('discounts_cents', 1980)
            ->assertJsonPath('net_sales_cents', 7920)
            ->assertJsonPath('delivery_fees_cents', 2000)
            ->assertJsonPath('transactions.0.delivery_fee_cents', 2000)
            ->assertJsonPath('transactions.0.total_cents', 9920);
        $this->getJson('/posetivacwingspos/api/admin/cashflow')
            ->assertOk()
            ->assertJsonPath('sales_total_cents', 7920)
            ->assertJsonPath('delivery_fees_cents', 2000)
            ->assertJsonPath('cash_sales_cents', 7920)
            ->assertJsonPath('cash_delivery_fees_cents', 2000)
            ->assertJsonPath('collected_total_cents', 9920)
            ->assertJsonPath('net_cents', 9920);
        $csv = $this->get('/posetivacwingspos/api/admin/sales.csv?type=delivery')->assertOk()->streamedContent();
        $this->assertStringContainsString('Sales PHP,Delivery fee PHP,Total collected PHP', $csv);
        $this->assertStringContainsString('79.20,20.00,99.20', $csv);
    }

    public function test_delivery_location_is_required_for_delivery_orders(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $productId = DB::table('products')->where('sku', 'MEAL-2')->value('id');
        $flavorId = DB::table('flavors')->where('name', 'Buffalo')->value('id');

        $this->actingAs($cashier)->postJson('/posetivacwingspos/api/orders', [
            'idempotency_key' => 'e39cd8e1-f8ae-4382-94c1-c836483af97a',
            'type' => 'delivery',
            'payment_method' => 'cash',
            'amount_paid_cents' => 11900,
            'delivery_fee_cents' => 2000,
            'items' => [[
                'product_id' => $productId,
                'quantity' => 1,
                'flavors' => [['flavor_id' => $flavorId, 'quantity' => 1]],
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_location']);
    }

    public function test_unli_round_uses_four_wings_per_selected_flavor(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $admin = User::where('role', 'superadmin')->firstOrFail();
        $tableId = DB::table('dining_tables')->where('label', 'Table 1')->value('id');
        $packageId = DB::table('products')->where('sku', 'UNLI-D')->value('id');
        $session = $this->actingAs($cashier)->postJson('/posetivacwingspos/api/unli-sessions', [
            'table_id' => $tableId,
            'package_id' => $packageId,
            'paid_sets' => 2,
        ])->assertCreated()
            ->assertJsonPath('session.inclusions.1', 'Unlimited rice')
            ->assertJsonPath('session.inclusions.2', 'Unlimited fries')
            ->assertJsonPath('session.inclusions.3', 'Unlimited drinks')
            ->json('session');
        $this->actingAs($cashier)->get('/posetivacwingspos/app')
            ->assertOk()
            ->assertSee('data-round-qty')
            ->assertSee('type="number" min="0" max="125" step="1" value="0"', false)
            ->assertSee('auto for 2 person(s) in round 1')
            ->assertSee('Included sides available depend on this set.')
            ->assertSee('not included in this set');

        $flavors = DB::table('flavors')->pluck('id', 'name');
        $payload = [
            'idempotency_key' => '48e6e6f6-93c8-4d2d-baff-bc6b3c48b820',
            'flavors' => [
                ['flavor_id' => $flavors['Buffalo'], 'quantity' => 4],
                ['flavor_id' => $flavors['Cheese'], 'quantity' => 8],
            ],
            'included_items' => ['Unlimited fries', 'Unlimited drinks'],
            'included_quantities' => ['Unlimited fries' => 2, 'Unlimited drinks' => 3],
        ];
        $invalidPayload = $payload;
        $invalidPayload['flavors'][0]['quantity'] = 5;
        $this->postJson("/posetivacwingspos/api/unli-sessions/{$session['id']}/rounds", $invalidPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['flavors']);
        $invalidPayload['flavors'][0]['quantity'] = 6;
        $this->postJson("/posetivacwingspos/api/unli-sessions/{$session['id']}/rounds", $invalidPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['flavors']);
        $this->assertSame(1000, DB::table('inventory_items')->where('sku', 'WINGS')->value('quantity'));

        $response = $this->postJson("/posetivacwingspos/api/unli-sessions/{$session['id']}/rounds", $payload);
        $response->assertOk()
            ->assertJsonPath('round.total_wings', 12)
            ->assertJsonPath('round.flavors.1.quantity', 8)
            ->assertJsonPath('round.included_items.0', 'Unlimited fries')
            ->assertJsonPath('round.included_items.1', 'Unlimited drinks')
            ->assertJsonPath('round.included_items.2', 'Unlimited rice')
            ->assertJsonPath('round.included_quantities.Unlimited fries', 2)
            ->assertJsonPath('round.included_quantities.Unlimited drinks', 3)
            ->assertJsonPath('round.included_quantities.Unlimited rice', 2);
        $this->assertSame(988, DB::table('inventory_items')->where('sku', 'WINGS')->value('quantity'));

        $this->postJson("/posetivacwingspos/api/unli-sessions/{$session['id']}/rounds", $payload)->assertOk();
        $this->assertSame(988, DB::table('inventory_items')->where('sku', 'WINGS')->value('quantity'));
        $this->assertSame(1, DB::table('unli_rounds')->count());
        $this->actingAs($admin)->getJson('/posetivacwingspos/api/kitchen')
            ->assertOk()
            ->assertJsonPath('tickets.0.table', 'Table 1')
            ->assertJsonPath('tickets.0.items.1.flavor_name', 'Cheese')
            ->assertJsonPath('tickets.0.items.1.quantity', 8)
            ->assertJsonPath('tickets.0.included_quantities.Unlimited fries', 2)
            ->assertJsonPath('tickets.0.included_quantities.Unlimited drinks', 3)
            ->assertJsonPath('tickets.0.included_quantities.Unlimited rice', 2);

        $receipt = $this->postJson("/posetivacwingspos/api/unli-sessions/{$session['id']}/billout", [
            'idempotency_key' => '65ce4a3e-3f28-4299-88aa-d3a71015857a',
            'payment_method' => 'cash',
            'amount_paid_cents' => 49800,
        ])->assertCreated()
            ->assertJsonPath('receipt.rounds.0.flavors.0.flavor_name', 'Buffalo')
            ->assertJsonPath('receipt.rounds.0.flavors.1.flavor_name', 'Cheese')
            ->assertJsonPath('receipt.rounds.0.flavors.1.quantity', 8)
            ->assertJsonPath('receipt.rounds.0.included_items.0', 'Unlimited fries')
            ->assertJsonPath('receipt.rounds.0.included_quantities.Unlimited fries', 2)
            ->assertJsonPath('receipt.rounds.0.included_quantities.Unlimited rice', 2);
        $this->assertSame('Unlimited drinks', $receipt->json('receipt.rounds.0.included_items.1'));
        $this->assertSame(3, $receipt->json('receipt.rounds.0.included_quantities.Unlimited drinks'));
        $this->actingAs($cashier)->get('/posetivacwingspos/app')->assertOk()->assertSee('EWLOGO.png');
    }

    public function test_unli_side_requests_follow_set_inclusions_and_show_on_kitchen_and_receipt(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $admin = User::where('role', 'superadmin')->firstOrFail();
        $tableId = DB::table('dining_tables')->where('label', 'Table 1')->value('id');
        $packageId = DB::table('products')->where('sku', 'UNLI-C')->value('id');
        $session = $this->actingAs($cashier)->postJson('/posetivacwingspos/api/unli-sessions', [
            'table_id' => $tableId,
            'package_id' => $packageId,
            'paid_sets' => 1,
        ])->assertCreated()->json('session');

        $roundUrl = "/posetivacwingspos/api/unli-sessions/{$session['id']}/rounds";
        $this->postJson($roundUrl, [
            'idempotency_key' => 'e3eaf611-4e9e-44f1-b32a-dce5230bb111',
            'included_items' => ['Unlimited rice'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['included_items']);

        $this->postJson($roundUrl, [
            'idempotency_key' => '19eb1e71-9867-4c32-9f7a-872f203ce212',
            'included_items' => ['Unlimited fries', 'Unlimited drinks'],
            'included_quantities' => ['Unlimited fries' => 2, 'Unlimited drinks' => 3],
        ])->assertOk()
            ->assertJsonPath('round.total_wings', 0)
            ->assertJsonPath('round.included_items.0', 'Unlimited fries')
            ->assertJsonPath('round.included_quantities.Unlimited fries', 2);

        $this->actingAs($admin)->getJson('/posetivacwingspos/api/kitchen')
            ->assertOk()
            ->assertJsonPath('tickets.0.included_items.0', 'Unlimited fries')
            ->assertJsonPath('tickets.0.included_items.1', 'Unlimited drinks')
            ->assertJsonPath('tickets.0.included_quantities.Unlimited fries', 2)
            ->assertJsonPath('tickets.0.included_quantities.Unlimited drinks', 3);

        $this->actingAs($cashier)->postJson("/posetivacwingspos/api/unli-sessions/{$session['id']}/billout", [
            'idempotency_key' => '53f9b6c4-2519-42d1-b921-f1a2c8ee1bd9',
            'payment_method' => 'cash',
            'amount_paid_cents' => 45900,
        ])->assertCreated()
            ->assertJsonPath('receipt.rounds.0.total_wings', 0)
            ->assertJsonPath('receipt.rounds.0.included_items.0', 'Unlimited fries')
            ->assertJsonPath('receipt.rounds.0.included_items.1', 'Unlimited drinks')
            ->assertJsonPath('receipt.rounds.0.included_quantities.Unlimited fries', 2)
            ->assertJsonPath('receipt.rounds.0.included_quantities.Unlimited drinks', 3);
    }

    public function test_unlimited_rice_is_automatically_requested_per_paid_customer_in_round_one(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $tableId = DB::table('dining_tables')->where('label', 'Table 1')->value('id');
        $packageId = DB::table('products')->where('sku', 'UNLI-D')->value('id');
        $session = $this->actingAs($cashier)->postJson('/posetivacwingspos/api/unli-sessions', [
            'table_id' => $tableId,
            'package_id' => $packageId,
            'paid_sets' => 3,
        ])->assertCreated()->json('session');

        $roundUrl = "/posetivacwingspos/api/unli-sessions/{$session['id']}/rounds";
        $firstRound = $this->postJson($roundUrl, [
            'idempotency_key' => 'd534a850-316f-4a51-b268-3cf477a1e42d',
        ])->assertOk()
            ->assertJsonPath('round.round_number', 1)
            ->assertJsonPath('round.included_items.0', 'Unlimited rice')
            ->assertJsonPath('round.included_quantities.Unlimited rice', 3);

        $this->postJson($roundUrl, [
            'idempotency_key' => 'dcc05211-36cb-42a4-bec0-8ae0f1d311c1',
            'included_items' => ['Unlimited fries'],
            'included_quantities' => ['Unlimited fries' => 2],
        ])->assertOk()
            ->assertJsonPath('round.round_number', 2)
            ->assertJsonMissingPath('round.included_quantities.Unlimited rice');

        $this->actingAs(User::where('role', 'superadmin')->firstOrFail())
            ->getJson('/posetivacwingspos/api/kitchen')
            ->assertOk()
            ->assertJsonPath('tickets.0.included_quantities.Unlimited rice', 3);
        $this->assertSame('Unlimited rice', $firstRound->json('round.included_items.0'));
    }

    public function test_set_d_billing_uses_paid_sets_and_cashier_cannot_open_kitchen_board(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $tableId = DB::table('dining_tables')->where('label', 'Table 1')->value('id');
        $packageId = DB::table('products')->where('sku', 'UNLI-D')->value('id');
        $session = $this->actingAs($cashier)->postJson('/posetivacwingspos/api/unli-sessions', [
            'table_id' => $tableId,
            'package_id' => $packageId,
            'paid_sets' => 3,
        ])->assertCreated()->json('session');

        $this->postJson("/posetivacwingspos/api/unli-sessions/{$session['id']}/billout", [
            'idempotency_key' => 'ab031102-cee4-4a99-9854-f1cb9582308a',
            'payment_method' => 'cash',
            'amount_paid_cents' => 59760,
            'discount_type' => 'senior',
        ])->assertCreated()
            ->assertJsonPath('receipt.subtotal_cents', 74700)
            ->assertJsonPath('receipt.discount_name', 'Senior Citizen')
            ->assertJsonPath('receipt.discount_cents', 14940)
            ->assertJsonPath('receipt.total_cents', 59760)
            ->assertJsonPath('receipt.items.0.product_name', 'Set D')
            ->assertJsonPath('receipt.items.0.quantity', 3);
        $this->assertSame(1000, DB::table('inventory_items')->where('sku', 'WINGS')->value('quantity'));
        $this->getJson('/posetivacwingspos/api/kitchen')->assertForbidden();
    }

    public function test_admin_can_manage_promotions_and_unli_bill_tracks_comped_customers(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $admin = User::where('role', 'superadmin')->firstOrFail();
        $this->actingAs($cashier)->getJson('/posetivacwingspos/api/admin/promotions')->assertForbidden();

        $promoId = $this->actingAs($admin)->postJson('/posetivacwingspos/api/admin/promotions', [
            'name' => 'Birthday Promo',
            'description' => 'Free one customer when there are 3 full-paying companions.',
            'companions_required' => 3,
            'free_customers_per_group' => 1,
        ])->assertCreated()->json('id');

        $this->patchJson("/posetivacwingspos/api/admin/promotions/{$promoId}", [
            'name' => 'Birthday Promo',
            'description' => 'Birthday guest eats free with three full-paying companions.',
            'companions_required' => 3,
            'free_customers_per_group' => 1,
            'active' => true,
        ])->assertOk();

        $cashier->refresh();
        $this->actingAs($cashier)->getJson('/posetivacwingspos/api/bootstrap')
            ->assertOk()
            ->assertJsonPath('promotions.0.id', $promoId)
            ->assertJsonPath('promotions.0.name', 'Birthday Promo');
        $this->actingAs($cashier)->get('/posetivacwingspos/app')
            ->assertOk()
            ->assertSee('PROMOTIONS')
            ->assertSee('Promotion')
            ->assertSee('Free customers using promo');

        $tableId = DB::table('dining_tables')->where('label', 'Table 1')->value('id');
        $packageId = DB::table('products')->where('sku', 'UNLI-D')->value('id');
        $threeCustomers = $this->postJson('/posetivacwingspos/api/unli-sessions', [
            'table_id' => $tableId,
            'package_id' => $packageId,
            'paid_sets' => 3,
        ])->assertCreated()->json('session');
        $this->postJson("/posetivacwingspos/api/unli-sessions/{$threeCustomers['id']}/billout", [
            'idempotency_key' => 'de47bc1c-23c0-4e1d-a7d4-d4ca5b1f4cf3',
            'payment_method' => 'cash',
            'amount_paid_cents' => 74700,
            'promotion_id' => $promoId,
            'promotion_free_customers' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['promotion_free_customers']);

        $tableTwoId = DB::table('dining_tables')->where('label', 'Table 2')->value('id');
        $fourCustomers = $this->postJson('/posetivacwingspos/api/unli-sessions', [
            'table_id' => $tableTwoId,
            'package_id' => $packageId,
            'paid_sets' => 4,
        ])->assertCreated()->json('session');
        $billout = $this->postJson("/posetivacwingspos/api/unli-sessions/{$fourCustomers['id']}/billout", [
            'idempotency_key' => '7be8927b-c5d8-4c7a-b87e-1b21c2fd5201',
            'payment_method' => 'cash',
            'amount_paid_cents' => 74700,
            'promotion_id' => $promoId,
            'promotion_free_customers' => 1,
        ])->assertCreated()
            ->assertJsonPath('receipt.subtotal_cents', 99600)
            ->assertJsonPath('receipt.promotion_name', 'Birthday Promo')
            ->assertJsonPath('receipt.promotion_companions_required', 3)
            ->assertJsonPath('receipt.promotion_free_customers_per_group', 1)
            ->assertJsonPath('receipt.promotion_free_customers', 1)
            ->assertJsonPath('receipt.promotion_savings_cents', 24900)
            ->assertJsonPath('receipt.total_cents', 74700);

        $this->actingAs($admin)->getJson('/posetivacwingspos/api/admin/promotions')
            ->assertOk()
            ->assertJsonPath('promotions.0.used_transactions', 1)
            ->assertJsonPath('promotions.0.used_customers', 1)
            ->assertJsonPath('history.0.name', 'Birthday Promo')
            ->assertJsonPath('history.0.customers', 1);
        $this->getJson('/posetivacwingspos/api/admin/sales-report')
            ->assertOk()
            ->assertJsonPath('promotion_savings_cents', 24900)
            ->assertJsonPath('promotion_usage.0.customers', 1)
            ->assertJsonPath('net_sales_cents', 74700);

        $this->deleteJson("/posetivacwingspos/api/admin/promotions/{$promoId}")
            ->assertOk()
            ->assertJsonPath('deleted', true);
        $this->getJson('/posetivacwingspos/api/receipts/'.$billout->json('receipt.id'))
            ->assertOk()
            ->assertJsonPath('receipt.promotion_name', 'Birthday Promo')
            ->assertJsonPath('receipt.promotion_free_customers', 1);
        $this->actingAs($admin)->getJson('/posetivacwingspos/api/admin/promotions')
            ->assertOk()
            ->assertJsonPath('promotions', [])
            ->assertJsonPath('history.0.customers', 1);
    }

    public function test_superadmin_can_reset_sales_without_changing_stock_cash_or_expenses(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $admin = User::where('role', 'superadmin')->firstOrFail();
        $flavorId = DB::table('flavors')->where('name', 'Buffalo')->value('id');
        $order = $this->actingAs($cashier)->postJson('/posetivacwingspos/api/orders', [
            'idempotency_key' => '9a13531c-3110-41d0-9bdb-37c13ed60a8b',
            'type' => 'takeaway',
            'payment_method' => 'cash',
            'amount_paid_cents' => 9900,
            'items' => [[
                'product_id' => DB::table('products')->where('sku', 'MEAL-2')->value('id'),
                'quantity' => 1,
                'flavors' => [['flavor_id' => $flavorId, 'quantity' => 1]],
            ]],
        ])->assertCreated()->json('receipt');
        $this->postJson("/posetivacwingspos/api/orders/{$order['id']}/void-requests", [
            'reason' => 'Reset workflow test',
        ])->assertCreated();
        $this->postJson("/posetivacwingspos/api/admin/orders/{$order['id']}/refunds", [
            'amount_cents' => 100,
            'payment_method' => 'cash',
            'reason' => 'Reset workflow test',
        ])->assertCreated();

        $tableId = DB::table('dining_tables')->where('label', 'Table 1')->value('id');
        $session = $this->postJson('/posetivacwingspos/api/unli-sessions', [
            'table_id' => $tableId,
            'package_id' => DB::table('products')->where('sku', 'UNLI-D')->value('id'),
            'paid_sets' => 1,
        ])->assertCreated()->json('session');
        $this->postJson("/posetivacwingspos/api/unli-sessions/{$session['id']}/rounds", [
            'idempotency_key' => '39f23067-5d68-4722-bca2-3fae944e74d1',
            'flavors' => [['flavor_id' => $flavorId, 'quantity' => 4]],
        ])->assertOk();

        $this->postJson('/posetivacwingspos/api/admin/cash-drawers', [
            'opening_cash_cents' => 50000,
        ])->assertCreated();
        DB::table('expenses')->insert([
            'user_id' => $admin->id,
            'category' => 'Supplies',
            'description' => 'Kept after reset',
            'amount_cents' => 500,
            'spent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stockBefore = DB::table('inventory_items')->where('sku', 'WINGS')->value('quantity');
        $movementsBefore = DB::table('inventory_movements')->count();
        $auditBefore = DB::table('audit_logs')->count();
        $drawerBefore = DB::table('cash_drawer_sessions')->count();

        $this->actingAs($cashier)->postJson('/posetivacwingspos/api/admin/reset-sales', [
            'confirmation' => 'RESET SALES',
        ])->assertForbidden();
        $this->actingAs($admin)->postJson('/posetivacwingspos/api/admin/reset-sales', [
            'confirmation' => 'RESET SALES',
        ])->assertOk()
            ->assertJsonPath('deleted.orders', 1)
            ->assertJsonPath('deleted.unli_sessions', 1);

        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('order_items')->count());
        $this->assertSame(0, DB::table('void_requests')->count());
        $this->assertSame(0, DB::table('refunds')->count());
        $this->assertSame(0, DB::table('unli_sessions')->count());
        $this->assertSame(0, DB::table('unli_rounds')->count());
        $this->assertSame(0, DB::table('kitchen_tickets')->count());
        $this->assertSame($stockBefore, DB::table('inventory_items')->where('sku', 'WINGS')->value('quantity'));
        $this->assertSame($movementsBefore, DB::table('inventory_movements')->count());
        $this->assertSame($drawerBefore, DB::table('cash_drawer_sessions')->count());
        $this->assertSame(1, DB::table('expenses')->where('description', 'Kept after reset')->count());
        $this->assertSame($auditBefore + 1, DB::table('audit_logs')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'sales.reset')->count());
    }

    public function test_superadmin_can_edit_receipt_text_and_upload_qr_image(): void
    {
        $admin = User::where('role', 'superadmin')->firstOrFail();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $this->actingAs($cashier)->putJson('/posetivacwingspos/api/admin/receipt-settings', [
            'header' => 'Welcome',
            'footer' => 'See you again',
        ])->assertForbidden();

        $this->actingAs($admin)->putJson('/posetivacwingspos/api/admin/receipt-settings', [
            'header' => 'Welcome',
            'footer' => 'Scan our survey',
        ])->assertOk();

        $temporaryImage = tempnam(sys_get_temp_dir(), 'survey-qr-');
        copy(public_path('images/EWlogo.jpg'), $temporaryImage);
        $upload = $this->postJson('/posetivacwingspos/api/admin/receipt-qr', [
            'qr_image' => new UploadedFile($temporaryImage, 'survey.jpg', 'image/jpeg', null, true),
        ])->assertOk()->assertJsonStructure(['qr_url']);
        if (is_file($temporaryImage)) {
            unlink($temporaryImage);
        }
        $qrPath = parse_url($upload->json('qr_url'), PHP_URL_PATH);

        $bootstrap = $this->actingAs($cashier)->getJson('/posetivacwingspos/api/bootstrap')
            ->assertOk()
            ->assertJsonPath('receipt.header', 'Welcome')
            ->assertJsonPath('receipt.footer', 'Scan our survey');
        $this->assertStringContainsString('receipt-assets/', $bootstrap->json('receipt.qr_url'));
        File::delete(public_path(ltrim($qrPath, '/')));
    }

    public function test_unli_products_are_hidden_from_pos_and_rejected_by_regular_checkout(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();

        $this->actingAs($cashier)->getJson('/posetivacwingspos/api/bootstrap')
            ->assertOk()
            ->assertJsonMissing(['slug' => 'unliwings'])
            ->assertJsonPath('products.0.available', 1);

        $this->postJson('/posetivacwingspos/api/orders', [
            'idempotency_key' => '603ff04e-d93e-4cf8-b91f-cebaf3a103a3',
            'type' => 'takeaway',
            'payment_method' => 'cash',
            'amount_paid_cents' => 38900,
            'items' => [[
                'product_id' => DB::table('products')->where('sku', 'UNLI-A')->value('id'),
                'quantity' => 1,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_id']);
    }

    public function test_superadmin_can_review_cashflow_record_expenses_and_manage_username_accounts(): void
    {
        $admin = User::where('role', 'superadmin')->firstOrFail();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $this->actingAs($cashier)->getJson('/posetivacwingspos/api/admin/cashflow')->assertForbidden();
        $this->actingAs($cashier)->getJson('/posetivacwingspos/api/admin/accounts')->assertForbidden();

        $this->actingAs($admin);
        DB::table('orders')->insert([
            'order_number' => 'EW-TEST-SALE-1',
            'idempotency_key' => 'test-sale-key',
            'type' => 'takeaway',
            'status' => 'paid',
            'cashier_id' => $cashier->id,
            'subtotal_cents' => 12000,
            'total_cents' => 12000,
            'payment_method' => 'cash',
            'amount_paid_cents' => 15000,
            'change_cents' => 3000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->getJson('/posetivacwingspos/api/admin/cashflow')
            ->assertOk()
            ->assertJsonPath('sales_total_cents', 12000)
            ->assertJsonPath('expenses_total_cents', 0)
            ->assertJsonPath('net_cents', 12000);

        $this->postJson('/posetivacwingspos/api/admin/expenses', [
            'category' => 'Supplies',
            'description' => 'Takeout packaging',
            'amount_cents' => 2500,
            'spent_at' => now()->toDateString(),
        ])->assertCreated();

        $this->getJson('/posetivacwingspos/api/admin/cashflow')
            ->assertOk()
            ->assertJsonPath('expenses_total_cents', 2500)
            ->assertJsonPath('net_cents', 9500);

        $account = $this->postJson('/posetivacwingspos/api/admin/accounts', [
            'name' => 'New Cashier',
            'username' => 'NEW_Cashier',
            'password' => 'change-this-password',
            'role' => 'cashier',
        ])->assertCreated()->json('id');
        $this->assertDatabaseHas('users', ['id' => $account, 'username' => 'new_cashier']);

        $this->patchJson("/posetivacwingspos/api/admin/accounts/{$account}", [
            'name' => 'Senior Cashier',
            'username' => 'senior-cashier',
            'password' => null,
            'role' => 'cashier',
            'active' => false,
        ])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $account, 'username' => 'senior-cashier', 'active' => false]);
    }

    public function test_login_accepts_username_instead_of_email(): void
    {
        $this->post('/posetivacwingspos/login', [
            'username' => 'ADMIN',
            'password' => 'password',
        ])->assertRedirect('/posetivacwingspos/app');

        $this->post('/posetivacwingspos/login', [
            'username' => 'admin@etivacwings.local',
            'password' => 'password',
        ])->assertSessionHasErrors('username');
    }

    public function test_inventory_adjustments_menu_management_csv_and_audit_are_superadmin_only(): void
    {
        $admin = User::where('role', 'superadmin')->firstOrFail();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $wings = DB::table('inventory_items')->where('sku', 'WINGS')->first();

        $this->actingAs($cashier)->postJson('/posetivacwingspos/api/admin/inventory/adjustments', [
            'inventory_item_id' => $wings->id,
            'direction' => 'remove',
            'quantity' => 1001,
            'reason' => 'invalid test adjustment',
        ])->assertForbidden();

        $this->actingAs($admin)->postJson('/posetivacwingspos/api/admin/inventory/adjustments', [
            'inventory_item_id' => $wings->id,
            'direction' => 'remove',
            'quantity' => 1001,
            'reason' => 'invalid test adjustment',
        ])->assertUnprocessable();

        $this->postJson('/posetivacwingspos/api/admin/inventory/adjustments', [
            'inventory_item_id' => $wings->id,
            'direction' => 'add',
            'quantity' => 25,
            'reason' => 'Opening delivery',
        ])->assertCreated();
        $this->assertSame(1025, DB::table('inventory_items')->where('id', $wings->id)->value('quantity'));

        $mealCategory = DB::table('categories')->where('slug', 'meals')->value('id');
        $product = $this->postJson('/posetivacwingspos/api/admin/products', [
            'category_id' => $mealCategory,
            'sku' => 'SPECIAL-MEAL',
            'name' => 'Special Meal',
            'price_cents' => 15000,
            'wing_count' => 3,
            'flavor_limit' => 1,
            'inclusions' => [],
        ])->assertCreated()->json('id');
        $this->patchJson("/posetivacwingspos/api/admin/products/{$product}", [
            'category_id' => $mealCategory,
            'sku' => 'SPECIAL-MEAL',
            'name' => 'Special Meal',
            'price_cents' => 16500,
            'wing_count' => 3,
            'flavor_limit' => 1,
            'inclusions' => [],
            'available' => false,
        ])->assertOk();

        $this->get('/posetivacwingspos/api/admin/sales.csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->getJson('/posetivacwingspos/api/admin/audit-logs')
            ->assertOk()
            ->assertJsonFragment(['action' => 'inventory.adjusted'])
            ->assertJsonFragment(['action' => 'menu.product_created']);
    }

    public function test_superadmin_void_approval_reverses_stock_and_removes_sale_from_cashflow(): void
    {
        $admin = User::where('role', 'superadmin')->firstOrFail();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $buffaloId = DB::table('flavors')->where('name', 'Buffalo')->value('id');
        $order = $this->actingAs($cashier)->postJson('/posetivacwingspos/api/orders', [
            'idempotency_key' => '0df99eb7-cda0-4ac3-85d1-67ff816bf3be',
            'type' => 'takeaway',
            'payment_method' => 'cash',
            'amount_paid_cents' => 9900,
            'items' => [[
                'product_id' => DB::table('products')->where('sku', 'MEAL-2')->value('id'),
                'quantity' => 1,
                'flavors' => [['flavor_id' => $buffaloId, 'quantity' => 1]],
            ]],
        ])->assertCreated()->json('receipt');
        $this->assertSame(998, DB::table('inventory_items')->where('sku', 'WINGS')->value('quantity'));

        $voidRequest = $this->postJson("/posetivacwingspos/api/orders/{$order['id']}/void-requests", [
            'reason' => 'Duplicate transaction',
        ])->assertCreated()->json('id');
        $this->actingAs($admin)->patchJson("/posetivacwingspos/api/admin/void-requests/{$voidRequest}", [
            'decision' => 'approved',
        ])->assertOk();

        $this->assertSame(1000, DB::table('inventory_items')->where('sku', 'WINGS')->value('quantity'));
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'status' => 'voided']);
        $this->assertDatabaseHas('kitchen_tickets', ['order_id' => $order['id'], 'archived' => true]);
        $this->getJson('/posetivacwingspos/api/admin/cashflow')
            ->assertOk()
            ->assertJsonPath('sales_total_cents', 0);
        $this->patchJson("/posetivacwingspos/api/admin/void-requests/{$voidRequest}", [
            'decision' => 'approved',
        ])->assertUnprocessable();
    }

    public function test_kitchen_can_archive_served_ticket_and_history_is_preserved(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $kitchen = User::where('role', 'kitchen')->firstOrFail();
        $buffaloId = DB::table('flavors')->where('name', 'Buffalo')->value('id');
        $order = $this->actingAs($cashier)->postJson('/posetivacwingspos/api/orders', [
            'idempotency_key' => '7a2fd249-892e-47d4-81d0-150fed301a84',
            'type' => 'takeaway',
            'payment_method' => 'cash',
            'amount_paid_cents' => 9900,
            'items' => [[
                'product_id' => DB::table('products')->where('sku', 'MEAL-2')->value('id'),
                'quantity' => 1,
                'flavors' => [['flavor_id' => $buffaloId, 'quantity' => 1]],
            ]],
        ])->assertCreated()->json('receipt');
        $ticketId = DB::table('kitchen_tickets')->where('order_id', $order['id'])->value('id');

        $this->actingAs($kitchen)->patchJson("/posetivacwingspos/api/kitchen/{$ticketId}", ['status' => 'cooking'])->assertOk();
        $this->patchJson("/posetivacwingspos/api/kitchen/{$ticketId}", ['status' => 'served'])->assertOk();
        $this->postJson("/posetivacwingspos/api/kitchen/{$ticketId}/archive", [])->assertOk();
        $this->getJson('/posetivacwingspos/api/kitchen')->assertOk()->assertJsonMissing(['id' => $ticketId]);
        $this->getJson('/posetivacwingspos/api/kitchen/history')
            ->assertOk()
            ->assertJsonPath('tickets.0.id', $ticketId)
            ->assertJsonPath('tickets.0.history.0.to_status', 'cooking')
            ->assertJsonPath('tickets.0.history.1.to_status', 'served');
    }

    public function test_configured_recipe_stock_is_deducted_on_sale_and_restored_on_void(): void
    {
        $admin = User::where('role', 'superadmin')->firstOrFail();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $mealCategory = DB::table('categories')->where('slug', 'meals')->value('id');
        $sauceId = DB::table('inventory_items')->where('sku', 'SAUCE')->value('id');
        $product = $this->actingAs($admin)->postJson('/posetivacwingspos/api/admin/products', [
            'category_id' => $mealCategory,
            'sku' => 'RECIPE-MEAL',
            'name' => 'Recipe Meal',
            'price_cents' => 9900,
            'wing_count' => 2,
            'flavor_limit' => 1,
            'inclusions' => [],
            'recipes' => [['inventory_item_id' => $sauceId, 'quantity_per_product' => 3]],
        ])->assertCreated()->json('id');

        $flavorId = DB::table('flavors')->where('name', 'Buffalo')->value('id');
        $order = $this->actingAs($cashier)->postJson('/posetivacwingspos/api/orders', [
            'idempotency_key' => '6235d871-251e-4eac-9878-a7eb53d58d6b',
            'type' => 'takeaway',
            'payment_method' => 'cash',
            'amount_paid_cents' => 19800,
            'items' => [[
                'product_id' => $product,
                'quantity' => 2,
                'flavors' => [['flavor_id' => $flavorId, 'quantity' => 2]],
            ]],
        ])->assertCreated()->json('receipt');

        $this->assertSame(294, DB::table('inventory_items')->where('id', $sauceId)->value('quantity'));
        $this->assertSame(996, DB::table('inventory_items')->where('sku', 'WINGS')->value('quantity'));
        $voidId = $this->postJson("/posetivacwingspos/api/orders/{$order['id']}/void-requests", [
            'reason' => 'Recipe stock reversal test',
        ])->assertCreated()->json('id');
        $this->actingAs($admin)->patchJson("/posetivacwingspos/api/admin/void-requests/{$voidId}", [
            'decision' => 'approved',
        ])->assertOk();

        $this->assertSame(300, DB::table('inventory_items')->where('id', $sauceId)->value('quantity'));
        $this->assertSame(1000, DB::table('inventory_items')->where('sku', 'WINGS')->value('quantity'));
    }

    public function test_cash_drawer_calculates_expected_close_and_variance(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $this->actingAs($cashier)->postJson('/posetivacwingspos/api/admin/cash-drawers', [
            'opening_cash_cents' => 10000,
        ])->assertCreated();
        $flavorId = DB::table('flavors')->where('name', 'Buffalo')->value('id');
        $this->postJson('/posetivacwingspos/api/orders', [
            'idempotency_key' => 'd6dd4a17-9366-42f9-8662-08afc7cf2e3f',
            'type' => 'takeaway',
            'payment_method' => 'cash',
            'amount_paid_cents' => 9900,
            'items' => [[
                'product_id' => DB::table('products')->where('sku', 'MEAL-2')->value('id'),
                'quantity' => 1,
                'flavors' => [['flavor_id' => $flavorId, 'quantity' => 1]],
            ]],
        ])->assertCreated();
        $this->postJson('/posetivacwingspos/api/admin/cash-entries', [
            'type' => 'other_expense',
            'amount_cents' => 3000,
            'description' => 'Cash drawer test outflow',
        ])->assertCreated();
        $drawer = $this->getJson('/posetivacwingspos/api/cash-drawer')
            ->assertOk()
            ->assertJsonPath('expected_closing_cash_cents', 16900)
            ->json('drawer');

        $this->postJson("/posetivacwingspos/api/admin/cash-drawers/{$drawer['id']}/close", [
            'actual_counted_cash_cents' => 17000,
        ])->assertOk()
            ->assertJsonPath('expected_closing_cash_cents', 16900)
            ->assertJsonPath('variance_cents', 100);
    }

    public function test_sales_report_returns_summary_and_catalog_configuration_is_authorized(): void
    {
        $admin = User::where('role', 'superadmin')->firstOrFail();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $this->actingAs($cashier)->getJson('/posetivacwingspos/api/admin/catalog')->assertForbidden();

        $this->actingAs($admin)->postJson('/posetivacwingspos/api/admin/categories', [
            'name' => 'Seasonal Menu',
            'slug' => '',
        ])->assertCreated();
        $this->postJson('/posetivacwingspos/api/admin/flavors', ['name' => 'Smoky BBQ'])->assertCreated();

        DB::table('orders')->insert([
            'order_number' => 'EW-REPORT-TEST',
            'idempotency_key' => 'report-test-key',
            'type' => 'takeaway',
            'status' => 'paid',
            'cashier_id' => $cashier->id,
            'subtotal_cents' => 12000,
            'total_cents' => 12000,
            'payment_method' => 'cash',
            'amount_paid_cents' => 12000,
            'change_cents' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->getJson('/posetivacwingspos/api/admin/sales-report?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('gross_sales_cents', 12000)
            ->assertJsonPath('net_sales_cents', 12000)
            ->assertJsonPath('completed_transactions', 1);
    }

    public function test_cash_refund_is_reviewed_and_reconciled_against_the_cashier_drawer(): void
    {
        $admin = User::where('role', 'superadmin')->firstOrFail();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $this->actingAs($cashier)->postJson('/posetivacwingspos/api/admin/cash-drawers', [
            'opening_cash_cents' => 10000,
        ])->assertCreated();
        $flavorId = DB::table('flavors')->where('name', 'Buffalo')->value('id');
        $order = $this->postJson('/posetivacwingspos/api/orders', [
            'idempotency_key' => '4d4793d4-9172-44c2-bbd5-eb702ee7bd40',
            'type' => 'takeaway',
            'payment_method' => 'cash',
            'amount_paid_cents' => 9900,
            'items' => [[
                'product_id' => DB::table('products')->where('sku', 'MEAL-2')->value('id'),
                'quantity' => 1,
                'flavors' => [['flavor_id' => $flavorId, 'quantity' => 1]],
            ]],
        ])->assertCreated()->json('receipt');
        $refundId = $this->postJson("/posetivacwingspos/api/admin/orders/{$order['id']}/refunds", [
            'amount_cents' => 3000,
            'payment_method' => 'cash',
            'reason' => 'Partial order correction',
        ])->assertCreated()->json('id');
        $this->actingAs($admin)->patchJson("/posetivacwingspos/api/admin/refunds/{$refundId}", [
            'decision' => 'approved',
        ])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'status' => 'partially_refunded']);
        $this->assertDatabaseHas('cash_flow_entries', [
            'user_id' => $cashier->id,
            'type' => 'cash_refund',
            'amount_cents' => 3000,
        ]);
        $this->getJson('/posetivacwingspos/api/admin/sales-report?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('gross_sales_cents', 9900)
            ->assertJsonPath('refunds_cents', 3000)
            ->assertJsonPath('net_sales_cents', 6900);
        $this->actingAs($cashier)->getJson('/posetivacwingspos/api/cash-drawer')
            ->assertOk()
            ->assertJsonPath('expected_closing_cash_cents', 16900);
    }

    public function test_expenses_can_be_updated_and_voided_with_audit_trail(): void
    {
        $admin = User::where('role', 'superadmin')->firstOrFail();
        $this->actingAs($admin);
        $expense = $this->postJson('/posetivacwingspos/api/admin/expenses', [
            'category' => 'Supplies',
            'description' => 'Original entry',
            'amount_cents' => 2500,
            'spent_at' => now()->toDateString(),
            'paid_with' => 'ewallet',
            'reference' => 'REF-001',
        ])->assertCreated()->json('id');
        $this->patchJson("/posetivacwingspos/api/admin/expenses/{$expense}", [
            'category' => 'Utilities',
            'description' => 'Corrected entry',
            'amount_cents' => 3500,
            'spent_at' => now()->toDateString(),
            'paid_with' => 'card',
            'reference' => 'REF-002',
        ])->assertOk();
        $this->deleteJson("/posetivacwingspos/api/admin/expenses/{$expense}", [
            'reason' => 'Duplicate expense record',
        ])->assertOk();

        $this->assertDatabaseHas('expenses', ['id' => $expense, 'voided' => true, 'amount_cents' => 3500]);
        $this->getJson('/posetivacwingspos/api/admin/cashflow')
            ->assertOk()
            ->assertJsonPath('expenses_total_cents', 0);
        $this->getJson('/posetivacwingspos/api/admin/audit-logs')
            ->assertOk()
            ->assertJsonFragment(['action' => 'expense.updated'])
            ->assertJsonFragment(['action' => 'expense.voided']);
    }
}
