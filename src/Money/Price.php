<?php

namespace Reach\StatamicResrv\Money;

use Money\Currency;
use Money\Money;
use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;

class Price 
{
    public $money;

    public function create($price): Price
    {   
        $this->money = new Money(bcmul($price, 100), new Currency(config('resrv-config.currency_isoCode')));
        return $this;
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

    public function multiply(string $by) {
        $this->money = $this->money->multiply($by);
        return $this;
    }
    
    public function divide(string $by) {
        $this->money = $this->money->divide($by);
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

    public function get()
    {
        $currencies = new ISOCurrencies();
        $moneyFormatter = new DecimalMoneyFormatter($currencies);
        return $moneyFormatter->format($this->money);
    }
}
