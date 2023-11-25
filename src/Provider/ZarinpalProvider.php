<?php

namespace PhpMonsters\Shaparak\Provider;

use Exception;
use Illuminate\Support\Facades\Http;

class ZarinpalProvider extends AbstractProvider
{
    protected bool $refundSupport = true;

    /**
     * {@inheritDoc}
     *
     * @throws Exception
     */
    public function getFormParameters(): array
    {
        $token = $this->requestToken();

        return [
            'gateway' => 'zarinpal',
            'method' => 'GET',
            'action' => $this->getUrlFor(self::URL_GATEWAY).'/'.$token,
            'parameters' => [],
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception
     */
    protected function requestToken(): string
    {
        if ($this->getTransaction()->isReadyForTokenRequest() === false) {
            throw new Exception('transaction is not ready for requesting token from payment gateway');
        }

        $this->checkRequiredActionParameters([
            'merchant_id',
        ]);

        $response = Http::acceptJson()->post($this->getUrlFor(self::URL_TOKEN), [
            'merchant_id' => $this->getParameters('merchant_id'),
            'callback_url' => $this->getCallbackUrl(),
            'amount' => $this->getAmount(),
            'description' => $this->getDescription(),
        ]);

        if ($response->successful()) {
            if ((int) $response->json('data.code') === 100) {
                return $response->json('data.authority');
            }

            $this->log($response->json('errors.message'), $response->json('errors'), 'error');
            throw new Exception(
                $response->json('errors.code').' '.$response->json('errors.message')
            );
        } else {
            $this->log($response->body());
        }

        throw new Exception('shaparak::shaparak.token_failed');
    }

    /**
     * {@inheritDoc}
     */
    public function getUrlFor(string $action = null): string
    {
        if ($this->environment === 'production') {
            switch ($action) {
                case self::URL_GATEWAY:

                    return 'https://www.zarinpal.com/pg/StartPay';

                case self::URL_TOKEN:

                    return 'https://api.zarinpal.com/pg/v4/payment/request.json';

                case self::URL_VERIFY:

                    return 'https://api.zarinpal.com/pg/v4/payment/verify.json';

            }
        } else {
            switch ($action) {
                case self::URL_GATEWAY:

                    return $this->bankTestBaseUrl.'/zarinpal/www.zarinpal.com/pg/StartPay';

                case self::URL_TOKEN:

                    return $this->bankTestBaseUrl.'/zarinpal/api.zarinpal.com/pg/v4/payment/request.json';

                case self::URL_VERIFY:

                    return $this->bankTestBaseUrl.'/zarinpal/api.zarinpal.com/pg/v4/payment/verify.json';

            }
        }
        throw new Exception('url destination is not valid!');
    }

    /**
     * {@inheritDoc}
     */
    public function canContinueWithCallbackParameters(): bool
    {
        try {
            $this->checkRequiredActionParameters([
                'Authority',
                'Status',
            ]);
        } catch (\Exception $e) {
            return false;
        }

        return $this->getParameters('Status') === 'OK';
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception
     */
    public function getGatewayReferenceId(): string
    {
        $this->checkRequiredActionParameters([
            'Authority',
        ]);

        return $this->getParameters('Authority');
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception
     */
    public function verifyTransaction(): bool
    {
        if ($this->getTransaction()->isReadyForVerify() === false) {
            throw new Exception('shaparak::shaparak.could_not_verify_transaction');
        }

        $this->checkRequiredActionParameters([
            'merchant_id',
            'authority',
        ]);

        if ($this->getParameters('Status') !== 'OK') {
            throw new Exception('could not verify transaction with callback status: '.$this->getParameters('Status'));
        }

        $response = Http::acceptJson()->post($this->getUrlFor(self::URL_VERIFY), [
            'merchant_id' => $this->getParameters('merchant_id'),
            'authority' => $this->getParameters('authority'),
            'amount' => $this->getTransaction()->getPayableAmount(),
        ]);

        if ($response->successful()) {
            if ((int) $response->json('data.code') === 100 || (int) $response->json('data.code') === 101) {
                $this->getTransaction()->setVerified(true); // save()

                return true;
            }

            $this->log($response->json('errors.message'), $response->json('errors'), 'error');
            throw new Exception(
                $response->json('errors.code').' '.$response->json('errors.message')
            );
        }

        throw new Exception('shaparak::shaparak.could_not_verify_transaction');
    }

    public function refundTransaction(): bool
    {
        return false;
    }

    private function getDescription(): string
    {
        $description = $this->getTransaction()->description;
        if (empty($description)) {
            $description = sprintf('Payment for Order ID: %s', $this->getTransaction()->id);
        }

        return $description;
    }
}
