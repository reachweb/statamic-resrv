<?php

namespace Reach\StatamicResrv\Tests\Browser;

use Laravel\Dusk\Browser;

/**
 * CheckoutForm — only the field types with a real JS surface. The 328 headless
 * Livewire tests own the form's validation/customer-save logic; here we drive the
 * Alpine behaviour they cannot:
 *   - `dictionary_phone`: the `phonebox` combobox — open, type-to-filter, arrow/enter
 *     keyboard navigation, and selecting a country writing its dial code into the input.
 *   - `toggle`: the Alpine switch (an sr-only checkbox flipped via its wrapping label).
 *   - inline required-field validation rendering on submit.
 *
 * The seed adds an optional `dialing_code` (dictionary → country_phone_codes) and an
 * optional `newsletter` (toggle) to the shared checkout form, so the standard funnel
 * (T14) still submits with them untouched. Plain text/textarea/select/etc. stay headless.
 */
class CheckoutFormFieldsTest extends BrowserTestCase
{
    public function test_phone_combobox_opens_filters_and_keyboard_selects(): void
    {
        $this->browse(function (Browser $browser) {
            $this->reachCustomerForm($browser);

            // The phonebox starts collapsed on the "Select" placeholder.
            $browser->assertSeeIn('[role=combobox]', 'Select')
                ->assertMissing('input[name=searchField]');

            // Open it with the keyboard (arrow-down on the combobox sets openedWithKeyboard,
            // which activates x-trap so focus is scoped to the dropdown).
            $browser->keys('[role=combobox]', '{ARROW_DOWN}')
                ->waitFor('input[name=searchField]')
                ->assertVisible('#statesList');

            // Type-to-filter: narrowing to "Greece" leaves a single option.
            $browser->type('input[name=searchField]', 'Greece')
                ->waitUsing(5, 100, fn () => count($browser->elements('[role=option]')) === 1);
            $browser->assertSeeIn('#statesList', 'Greece')
                ->assertDontSeeIn('#statesList', 'Afghanistan');

            // Keyboard NAVIGATION: arrow-down moves focus off the search field onto the option.
            $browser->keys('input[name=searchField]', '{ARROW_DOWN}')
                ->waitUsing(5, 100, fn () => $browser->script(
                    "return document.activeElement && document.activeElement.getAttribute('role') === 'option'"
                )[0] === true);

            // Keyboard SELECTION: enter on the focused option writes Greece's dial code (+30)
            // into the phone input.
            $browser->keys('[role=option]', '{ENTER}')
                ->waitUsing(5, 100, fn () => $browser->value('#phoneNumber') === '+30');
            $this->assertSame('+30', $browser->value('#phoneNumber'));
        });
    }

    public function test_toggle_switch_flips_and_required_validation_renders(): void
    {
        $this->browse(function (Browser $browser) {
            $this->reachCustomerForm($browser);

            // The toggle's real input is an sr-only checkbox; a native click on it is "not
            // interactable", so flip it through its wrapping <label> (same pattern T14 uses
            // for the sr-only extra checkbox). It starts unchecked.
            $this->assertFalse($this->newsletterChecked($browser));

            $browser->script("document.querySelector('[dusk=toggle-newsletter]').closest('label').click();");
            $browser->waitUsing(5, 100, fn () => $this->newsletterChecked($browser));
            $this->assertTrue($this->newsletterChecked($browser));

            // Submit with the required text fields still empty → inline validation renders.
            // The rule logic is headless-tested; here we only confirm it surfaces in the DOM.
            $browser->click('[wire\\:click="submit()"]')
                ->waitFor('.text-red-600')
                ->assertVisible('.text-red-600');
        });
    }

    /**
     * Drive the standard funnel to checkout step 2 (the customer form): search → Book Now
     * → step 1 → handleFirstStep. The seeded option is not required, so step 1 advances
     * without touching extras/options.
     */
    private function reachCustomerForm(Browser $browser): void
    {
        $browser->visit('/bookable')->waitFor('[name=datepicker]')
            ->click('[name=datepicker]')->waitFor('.rc-day__label')
            ->click('.rc-day--available:not(.rc-day--hidden)')->waitFor('[wire\\:click="checkout()"]')
            ->click('[wire\\:click="checkout()"]')
            ->waitForLocation('/checkout')
            ->waitFor('[wire\\:click="handleFirstStep()"]')
            ->click('[wire\\:click="handleFirstStep()"]')
            ->waitFor('#first_name', 10);
    }

    private function newsletterChecked(Browser $browser): bool
    {
        return (bool) $browser->script(
            "return document.querySelector('[dusk=toggle-newsletter]').checked"
        )[0];
    }
}
