<?php

namespace Waxis\Cashier;

use DOMPDF;
use Carbon\Carbon;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;
use Braintree\Transaction as BraintreeTransaction;

class Invoice
{
    /**
     * The user instance.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $user;

    /**
     * The Braintree transaction instance.
     *
     * @var \Braintree\Transaction
     */
    protected $transaction;

    /**
     * Create a new invoice instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $user
     * @param  \Braintree\Transaction  $transaction
     * @return void
     */
    public function __construct($user, BraintreeTransaction $transaction)
    {
        $this->user = $user;
        $this->transaction = $transaction;
    }

    /**
     * Get a Carbon date for the invoice.
     *
     * @param  \DateTimeZone|string  $timezone
     * @return \Carbon\Carbon
     */
    public function date($timezone = null)
    {
        $carbon = Carbon::instance($this->transaction->createdAt);

        return $timezone ? $carbon->setTimezone($timezone) : $carbon;
    }

    /**
     * Get the total amount that was paid (or will be paid).
     *
     * @return string
     */
    public function total()
    {
        return $this->formatAmount($this->rawTotal());
    }

    /**
     * Get the raw total amount that was paid (or will be paid).
     *
     * @return float
     */
    public function rawTotal()
    {
        return max(0, $this->transaction->amount);
    }

    /**
     * Get the total of the invoice (before discounts).
     *
     * @return string
     */
    public function subtotal()
    {
        return $this->formatAmount(
            max(0, $this->transaction->amount + $this->discountAmount())
        );
    }

    /**
     * Determine if the invoice has any add-ons.
     *
     * @return bool
     */
    public function hasAddon()
    {
        return count($this->transaction->addOns) > 0;
    }

    /**
     * Get the discount amount.
     *
     * @return string
     */
    public function addOn()
    {
        return $this->formatAmount($this->addOnAmount());
    }

    /**
     * Get the raw add-on amount.
     *
     * @return float
     */
    public function addOnAmount()
    {
        $totalAddOn = 0;

        foreach ($this->transaction->addOns as $addOn) {
            $totalAddOn += $addOn->amount;
        }

        return (float) $totalAddOn;
    }

    /**
     * Get the add-on codes applied to the invoice.
     *
     * @return array
     */
    public function addOns()
    {
        $addOns = [];

        foreach ($this->transaction->addOns as $addOn) {
            $addOns[] = $addOn->id;
        }

        return $addOns;
    }

    /**
     * Determine if the invoice has a discount.
     *
     * @return bool
     */
    public function hasDiscount()
    {
        return count($this->transaction->discounts) > 0;
    }

    /**
     * Get the discount amount.
     *
     * @return string
     */
    public function discount()
    {
        return $this->formatAmount($this->discountAmount());
    }

    /**
     * Get the raw discount amount.
     *
     * @return float
     */
    public function discountAmount()
    {
        $totalDiscount = 0;

        foreach ($this->transaction->discounts as $discount) {
            $totalDiscount += $discount->amount;
        }

        return (float) $totalDiscount;
    }

    /**
     * Get the coupon codes applied to the invoice.
     *
     * @return array
     */
    public function coupons()
    {
        $coupons = [];

        foreach ($this->transaction->discounts as $discount) {
            $coupons[] = $discount->id;
        }

        return $coupons;
    }

    /**
     * Get the discount amount for the invoice.
     *
     * @return string
     */
    public function amountOff()
    {
        return $this->discount();
    }

    /**
     * Format the given amount into a string based on the user's preferences.
     *
     * @param  int  $amount
     * @return string
     */
    protected function formatAmount($amount)
    {
        return Cashier::formatAmount($amount);
    }

    /**
     * Get the View instance for the invoice.
     *
     * @param  array  $data
     * @return \Illuminate\View\View
     */
    public function view(array $data, $view = null)
    {
        if (is_null($view)) {
            $view = 'cashier::receipt';
        }
        
        return View::make($view, array_merge(
            $data, ['invoice' => $this, 'user' => $this->user]
        ));
    }

    /**
     * Capture the invoice as a PDF and return the raw bytes.
     *
     * @param  array  $data
     * @return string
     */
    public function pdf(array $data, $view = null)
    {
        if (! defined('DOMPDF_ENABLE_AUTOLOAD')) {
            define('DOMPDF_ENABLE_AUTOLOAD', false);
        }

        if (file_exists($configPath = base_path().'/vendor/dompdf/dompdf/dompdf_config.inc.php')) {
            require_once $configPath;
        }

        $dompdf = new DOMPDF;
        
        $dompdf->load_html($this->view($data, $view)->render());

        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Create an invoice download response.
     *
     * @param  array   $data
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function download(array $data, $view = null)
    {
        $filename = $data['product'].'_'.$this->date()->month.'_'.$this->date()->year.'.pdf';

        return new Response($this->pdf($data, $view), 200, [
            'Content-Description' => 'File Transfer',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Transfer-Encoding' => 'binary',
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Returns user.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function user()
    {
        return $this->user;
    }

    /**
     * Returns VAT percentage.
     *
     * @return int
     */
    public function vatPercentage()
    {
        return $this->user->taxPercentage();
    }

    /**
     * Returns VAT.
     *
     * @return int
     */
    public function vat()
    {
        return $this->net() * 0.27;
    }

    /**
     * Returns net amount.
     *
     * @return int
     */
    public function net()
    {
        $total = $this->rawTotal();

        return $total / (1 + ($this->user->taxPercentage() / 100));
    }

    /**
     * Get the Braintree transaction instance.
     *
     * @return \Braintree\Transaction
     */
    public function asBraintreeTransaction()
    {
        return $this->transaction;
    }

    /**
     * Dynamically get values from the Braintree transaction.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->transaction->{$key};
    }
}
