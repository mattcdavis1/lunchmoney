<?php

namespace App\Console\Commands\Transactions;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use App\Models\Transaction;
use GuzzleHttp\Exception\RequestException;
use Exception;

class SyncUp extends Command
{
    const MODE_PATCH = 'patch';
    const MODE_POST = 'post';
    const API_ENDPOINT = 'https://dev.lunchmoney.app/v1/transactions';
    protected $signature = 'transactions:sync-up';
    protected $description = 'Sync Up Transactions';
    protected $client = null;

    public function handle()
    {
        $mode = self::MODE_PATCH;

        $client = new Client([
            'timeout'  => 500.0,
        ]);

        $accountIds = [];
        $categoryIds = [];
        $untilDate = '';
        $vendorIds = [];

        $query = Transaction::where('user_id', 1)
            ->whereIn('type', ['income', 'expense'])
            ->orderBy('transactions.date_bank_processed', 'ASC');

        if ($mode == self::MODE_PATCH) {
            $query->whereNotNull('transactions.lm_id');
        } else {
            $query->whereNull('transactions.lm_id');
        }

        if ($untilDate) {
            $query->where('date_bank_processed', '<', $untilDate);
        }

        if ($categoryIds) {
            $query->whereIn('category_id', '<', $categoryIds);
        }

        if ($vendorIds) {
            $query->whereIn('vendor_id', '<', $vendorIds);
        }

        if ($accountIds) {
            $query->whereIn('account_id', '<', $accountIds);
        }

        $endpoint = self::API_ENDPOINT . '/' . env('ACCOUNT_ID') . '/transactions';
        $numRecords = 1;
        $numRequests = 0;

        $query->chunk(1000, function($transactions) use ($client, $endpoint, $mode, &$numRecords, &$numRequests) {
            $lmTransactions = [];

            foreach ($transactions as $transaction) {
                $numRecords++;
                $amount = (float) $transaction->amount;

                if ($amount != 0) {
                    $lmTransaction = $transaction->tolm();
                    if (strlen($lmTransaction['account_id']) > 5 && strlen($lmTransaction['category_id']) > 5) {

                        if ($transaction->lm_id) {
                            $lmTransaction['id'] = $transaction->lm_id;
                        }

                        $lmTransactions[] = $lmTransaction;
                        $this->comment('[' . $numRecords . '] Adding: ' . $transaction->id . '::' . $lmTransaction['account_id'] . '::' . $lmTransaction['category_id'] . ' (' . $transaction->date_bank_processed . ')');
                    }
                }
            }

            $this->info('Posting / Patching Data');

            try {
                $response = $client->request($mode, $endpoint, [
                    'json' => ['transactions' => $lmTransactions ],
                    'headers' => [
                        'Authorization' => 'Bearer ' . env('ACCESS_TOKEN'),
                    ],
                ]);
            } catch (RequestException $e) {
                $this->error($e->getMessage());
            }

            $numRequests++;
            $numRecords = 0;

            $json = $response->getBody()->getContents();
            $responseObj = json_decode($json);

            $this->info('[' . $numRequests . '] Posted ' . $numRecords . ' Transactions');


            if ($mode == self::MODE_POST) {
                foreach ($responseObj->data->transactions as $lmTransaction) {
                    $this->comment('Saving lm Transaction: ' . $lmTransaction->id);

                    $transaction->lm_id = $lmTransaction->id;

                    try {
                        $transaction->lm_json = json_encode($lmTransaction);
                    } catch (Exception $e) {
                        $this->error($e->getMessage());
                    }

                    $transaction->save();
                }
            }

            sleep(5);
        });

        return 1;
    }

    protected function request($endpoint, $data, $options = [])
    {
        $response = (object) [
            'data' => (object) [],
            'error' => '',
            'headers' => (object) [],
            'httpResponse' => null,
            'meta' => (object) [],
            'result' => (object) [],
        ];

        $method = $options['method'] ?? 'get';
        $query = $options['query'] ?? [];
        $json = null;

        try {
            $httpResponse = $this->client->request($method, $endpoint, [
                'json' => $data,
                'query' => $query,
                'headers' => [
                    'Authorization' => env('INVENTORY_PLANNER_AUTH'),
                    'Account' => env('INVENTORY_PLANNER_ACCOUNT'),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);

            $response->headers = $httpResponse->getHeaders();
            $response->httpResponse = $httpResponse;

            $json = $httpResponse->getBody()->getContents();
        } catch (Exception $e) {
            $response->error = $e->getMessage();

            if ($e instanceof ClientException) {
                $this->logger->error($e->getResponse()->getBody()->getContents());
            } else {
                $this->logger->error($e->getMessage());
            }
        }

        if ($json) {
            $key = $options['key'] ?? self::API_KEY_PLURAL;

            $responseObj = json_decode($json);
            $response->data = $responseObj->{$key};
            if (!empty($responseObj->meta)) {
                $response->meta = $responseObj->meta;
            }

            if (!empty($responseObj->result)) {
                $message = $responseObj->result->message ?? $responseObj->result->status ?? '';
                $response->meta = $message;
                $this->logger->comment('Result: ' . $message ?? '');
            }
        }

        return $response;
    }
}
