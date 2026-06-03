<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests run against the real sbn.db (same pattern as AuthTest).
 * Created products are cleaned up by slug in setUp + tearDown.
 */
class AdminProductCrudTest extends TestCase
{
    private const TEST_SLUG   = 'test-admin-product-crud-12c';
    private const TEST_EMAIL  = 'admin-product-crud-test@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        DB::reconnect('sqlite');
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        Product::where('slug', self::TEST_SLUG)->delete();
        User::where('email', self::TEST_EMAIL)->delete();
    }

    private function makeInstructor(): User
    {
        $user = User::create([
            'name'     => 'Test Instructor',
            'email'    => self::TEST_EMAIL,
            'password' => bcrypt('password'),
        ]);
        $user->forceFill(['is_instructor' => true])->save();
        return $user;
    }

    public function test_index_loads_for_instructor(): void
    {
        $user = $this->makeInstructor();

        $response = $this->actingAs($user)->get(route('admin.products.index'));

        $response->assertOk();
        $response->assertSee('Products');
    }

    public function test_store_creates_product(): void
    {
        $user = $this->makeInstructor();

        $response = $this->actingAs($user)->post(route('admin.products.store'), [
            'title'       => 'Test Admin Product CRUD 12c',
            'slug'        => self::TEST_SLUG,
            'price'       => '19.99',
            'status'      => 'draft',
            'payment_ref' => 'price_test123',
            'tax_code'    => 'txcd_10000001',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('sbn_products', [
            'slug'        => self::TEST_SLUG,
            'price_cents' => 1999,
            'payment_ref' => 'price_test123',
        ]);
    }

    public function test_update_persists_payment_ref_and_tax_code(): void
    {
        $user = $this->makeInstructor();

        $product = Product::create([
            'slug'        => self::TEST_SLUG,
            'title'       => 'Test Admin Product CRUD 12c',
            'price_cents' => 1000,
            'status'      => 'draft',
        ]);

        $response = $this->actingAs($user)->put(route('admin.products.update', $product), [
            'title'       => 'Test Admin Product CRUD 12c',
            'slug'        => self::TEST_SLUG,
            'price'       => '25.00',
            'status'      => 'published',
            'payment_ref' => 'price_updated456',
            'tax_code'    => 'txcd_99999999',
        ]);

        $response->assertRedirect();
        $product->refresh();
        $this->assertEquals('price_updated456', $product->payment_ref);
        $this->assertEquals('txcd_99999999', $product->tax_code);
        $this->assertEquals(2500, $product->price_cents);
    }

    public function test_destroy_deletes_product(): void
    {
        $user = $this->makeInstructor();

        $product = Product::create([
            'slug'        => self::TEST_SLUG,
            'title'       => 'Test Admin Product CRUD 12c',
            'price_cents' => 500,
            'status'      => 'draft',
        ]);

        $response = $this->actingAs($user)->delete(route('admin.products.destroy', $product));

        $response->assertRedirect(route('admin.products.index'));
        $this->assertDatabaseMissing('sbn_products', ['slug' => self::TEST_SLUG]);
    }
}
