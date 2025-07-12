<?php

namespace Reach\StatamicResrv\Tests\Unit;

use Illuminate\Support\Collection;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Tests\TestCase;

class CustomerTest extends TestCase
{
    public function test_it_prevents_exposing_all_emails_when_using_legacy_get_method()
    {
        // Create multiple customers
        $customer1 = Customer::factory()->create([
            'email' => 'customer1@example.com',
            'data' => ['name' => 'Customer 1'],
        ]);

        Customer::factory()->create([
            'email' => 'customer2@example.com',
            'data' => ['name' => 'Customer 2'],
        ]);

        Customer::factory()->create([
            'email' => 'customer3@example.com',
            'data' => ['name' => 'Customer 3'],
        ]);

        // When using the legacy get('email') method on a specific customer
        $result = $customer1->get('email');

        // It should only return that customer's email, not all emails
        $this->assertEquals('customer1@example.com', $result);
        $this->assertIsString($result);

        // Verify it doesn't return a collection or array of emails
        $this->assertNotInstanceOf(Collection::class, $result);
        $this->assertFalse(is_array($result));
    }

    public function test_it_can_access_data_fields_via_get_method()
    {
        $customer = Customer::factory()->create([
            'email' => 'test@example.com',
            'data' => [
                'name' => 'John Doe',
                'phone' => '123-456-7890',
            ],
        ]);

        // Should be able to access data fields
        $this->assertEquals('John Doe', $customer->get('name'));
        $this->assertEquals('123-456-7890', $customer->get('phone'));

        // Should return default if field doesn't exist
        $this->assertEquals('default', $customer->get('nonexistent', 'default'));
        $this->assertNull($customer->get('nonexistent'));
    }

    public function test_it_can_access_model_attributes_via_get_method()
    {
        $customer = Customer::factory()->create([
            'email' => 'test@example.com',
            'data' => ['name' => 'John Doe'],
        ]);

        // Should be able to access model attributes
        $this->assertEquals($customer->id, $customer->get('id'));
        $this->assertEquals('test@example.com', $customer->get('email'));
    }

    public function test_it_handles_get_method_safely_when_data_is_null()
    {
        $customer = Customer::factory()->create([
            'email' => 'test@example.com',
            'data' => null,
        ]);

        // Should not find a key that doesn't exist
        $this->assertNull($customer->get('non_existent_key'));

        // Should return the provided default value if the key doesn't exist
        $this->assertEquals('default_value', $customer->get('non_existent_key', 'default_value'));

        // The get() method should still work for the 'email' attribute
        $this->assertEquals('test@example.com', $customer->get('email'));
    }

    public function test_it_is_countable_for_backward_compatibility()
    {
        $customer = Customer::factory()->create([
            'data' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'phone' => '123-456-7890',
            ],
        ]);

        // The count should be the number of data fields + 1 (for the email attribute)
        $this->assertEquals(4, $customer->count());
    }

    public function test_it_is_countable_when_data_is_empty_or_null()
    {
        $customerWithEmptyData = Customer::factory()->create(['data' => []]);
        $customerWithNullData = Customer::factory()->create(['data' => null]);

        // The count should be 1 (for the email attribute)
        $this->assertEquals(1, $customerWithEmptyData->count());
        $this->assertEquals(1, $customerWithNullData->count());
    }

    public function test_it_is_iterable_for_backward_compatibility()
    {
        $customerData = [
            'first_name' => 'John',
            'last_name' => 'Doe',
        ];
        $customer = Customer::factory()->create([
            'email' => 'test@example.com',
            'data' => $customerData,
        ]);

        // Expected data when iterating combines email and the data collection
        $expectedData = collect(['email' => 'test@example.com'])->merge($customerData);

        // Manually get the iterator to test it directly
        $iteratedData = new Collection($customer->getIterator());

        // It should contain the email and the data fields in the correct order
        $this->assertEquals($expectedData->all(), $iteratedData->all());
    }

    public function test_it_is_iterable_when_data_is_empty_or_null()
    {
        $customerWithEmptyData = Customer::factory()->create([
            'email' => 'test1@example.com',
            'data' => [],
        ]);

        $customerWithNullData = Customer::factory()->create([
            'email' => 'test2@example.com',
            'data' => null,
        ]);

        $expectedDataForEmpty = ['email' => 'test1@example.com'];
        $iteratedDataEmpty = new Collection($customerWithEmptyData->getIterator());
        $this->assertEquals($expectedDataForEmpty, $iteratedDataEmpty->all());

        $expectedDataForNull = ['email' => 'test2@example.com'];
        $iteratedDataNull = new Collection($customerWithNullData->getIterator());
        $this->assertEquals($expectedDataForNull, $iteratedDataNull->all());
    }
}
