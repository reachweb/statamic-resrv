<?php

namespace Reach\StatamicResrv\Models;

use ArrayIterator;
use Countable;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use IteratorAggregate;
use Reach\StatamicResrv\Database\Factories\CustomerFactory;
use Traversable;

class Customer extends Model implements Countable, IteratorAggregate
{
    use HasFactory;

    protected $table = 'resrv_customers';

    protected $guarded = [];

    protected $casts = [
        'data' => AsCollection::class,
    ];

    protected static function newFactory()
    {
        return CustomerFactory::new();
    }

    /**
     * Override get method to prevent security vulnerability from old template syntax.
     * This prevents $reservation->customer->get('email') from exposing all customer emails.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        // First, try to get a direct model attribute (like 'id' or 'email')
        if (property_exists($this, $key) || array_key_exists($key, $this->attributes)) {
            return $this->getAttribute($key);
        }

        // If data is not null, get the value from the data collection.
        // Otherwise, return the provided default value.
        return $this->data ? $this->data->get($key, $default) : $default;
    }

    /**
     * Get the number of attributes.
     *
     * This provides backward compatibility for templates that
     * used to call `->count()` on the customer data.
     */
    public function count(): int
    {
        return 1 + ($this->data?->count() ?? 0);
    }

    /**
     * Get an iterator for the attributes.
     *
     * This provides backward compatibility for templates that
     * used to iterate over the customer data.
     */
    public function getIterator(): Traversable
    {
        $attributes = collect(['email' => $this->email])
            ->merge($this->data ?? []);

        return new ArrayIterator($attributes->all());
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
