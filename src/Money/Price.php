<?php

namespace Reach\StatamicResrv\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Parser\DecimalMoneyParser;

class Price implements CastsAttributes
{
    public $money;

    public function __toString(): string
    {
        return $this->format();
    }

    public function create($price): Price
    {
        $class = new self;

        $value = (string) $price;

        // The decimal parser silently trims surrounding whitespace, but the previous
        // BCMath-based implementation rejected it. Keep create() a strict gate, since
        // callers (e.g. PaymentGatewayManager) rely on it throwing for malformed values.
        if (trim($value) !== $value) {
            throw new \InvalidArgumentException(sprintf('Cannot parse "%s" to a Price.', $value));
        }

        // Parse the decimal value using the currency's real subunit count (the inverse of
        // the DecimalMoneyFormatter used in format()), so create()/format() round-trip for
        // every currency — including non-2-decimal ones like JPY (0) and BHD (3).
        $parser = new DecimalMoneyParser(new ISOCurrencies);
        $class->money = $parser->parse($value, new Currency(config('resrv-config.currency_isoCode')));

        return $class;
    }

    public function add(Price ...$toAdd): Price
    {
        foreach ($toAdd as $addition) {
            $this->money = $this->money->add($addition->money);
        }

        return $this;
    }

    public function subtract(Price ...$toSubtract): Price
    {
        foreach ($toSubtract as $subtraction) {
            $this->money = $this->money->subtract($subtraction->money);
        }

        return $this;
    }

    public function multiply(string $by)
    {
        $this->money = $this->money->multiply($by);

        return $this;
    }

    public function divide(string $by)
    {
        $this->money = $this->money->divide($by);

        return $this;
    }

    public function percent($percent)
    {
        $by = bcmul($percent, 0.01, 4);
        $this->multiply($by);

        return $this;
    }

    public function decreasePercent($percent)
    {
        $by = bcmul(100 - $percent, 0.01, 4);
        $this->multiply($by);

        return $this;
    }

    public function increasePercent($percent)
    {
        $by = bcmul(100 + $percent, 0.01, 4);
        $this->multiply($by);

        return $this;
    }

    public function equals(Price $toCompare): bool
    {
        return $this->money->equals($toCompare->money);
    }

    public function greaterThan(Price $toCompare): bool
    {
        return $this->money->greaterThan($toCompare->money);
    }

    public function lessThan(Price $toCompare): bool
    {
        return $this->money->lessThan($toCompare->money);
    }

    public function format()
    {
        $currencies = new ISOCurrencies;
        $moneyFormatter = new DecimalMoneyFormatter($currencies);

        return $moneyFormatter->format($this->money);
    }

    public function get($model, $key, $value, $attributes)
    {
        $price = $this->create($value);

        return $price->format();
    }

    public function set($model, $key, $value, $attributes)
    {
        $price = $this->create($value);

        return $price->format();
    }

    public function raw()
    {
        return $this->money->getAmount();
    }

    public function isZero()
    {
        return $this->money->isZero();
    }
}
